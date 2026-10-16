<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorMicrosoftMail\Sync;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Padosoft\AskMyDocsConnectorBase\Contracts\ConnectorIngestionContract;
use Padosoft\AskMyDocsConnectorBase\Models\ConnectorInstallation;
use Padosoft\AskMyDocsConnectorBase\SyncResult;
use Padosoft\AskMyDocsConnectorMicrosoftMail\Graph\GraphClient;
use Padosoft\AskMyDocsConnectorMicrosoftMail\Graph\GraphResourceNotFoundException;
use Padosoft\AskMyDocsConnectorMicrosoftMail\Graph\InvalidDeltaCursorException;

final class SyncEngine
{
    public function __construct(private readonly ConnectorIngestionContract $bridge) {}

    public function run(ConnectorInstallation $installation, string $project, string $mailbox, GraphClient $client, array $settings, bool $full): SyncResult
    {
        $store = new SyncStore($installation, $mailbox);
        $lock = Cache::lock(self::installationLock($installation), 900);
        if (! $lock->get()) {
            throw new \RuntimeException('Microsoft Mail sync is already running for this mailbox');
        }
        $start = microtime(true);
        $client->withDeadline($start + max(1, min(300, (int) ($settings['sync']['time_budget_seconds'] ?? 60))));
        $totals = ['added' => 0, 'updated' => 0, 'removed' => 0, 'bytes' => 0, 'messages' => 0, 'pages' => 0, 'skipped' => 0];
        try {
            $mapping = $client->folders((bool) ($settings['folders']['include_hidden'] ?? false));
            $selected = self::selectFolders($mapping, $settings);
            ksort($selected, SORT_STRING);
            // Host triage/profile settings never trigger mailbox acquisition.
            // Only changes that affect selected evidence invalidate native cursors.
            $semantic = array_intersect_key($settings, array_flip(['folders', 'filters', 'body', 'attachments', 'reconciliation', 'provenance_tier']));
            $semantic['sync'] = ['date_window_days' => $settings['sync']['date_window_days'] ?? 0];
            $fingerprint = hash('sha256', json_encode([$mailbox, $project, $semantic, array_map(static fn (array $folder): array => [$folder['id'], $folder['name'], $folder['path']], $selected)], JSON_THROW_ON_ERROR));
            $run = $store->get('runs', 'run');
            $changed = ($run['fingerprint'] ?? null) !== $fingerprint;
            if ($changed || ! ($run['active'] ?? false) || ($full && ! ($run['full'] ?? false))) {
                $run = ['fingerprint' => $fingerprint, 'round' => bin2hex(random_bytes(16)), 'active' => true, 'complete' => false, 'full' => $full || $changed, 'reconcile_required' => $full || $changed, 'reconcile_after' => '', 'tombstone_after' => ''];
                $store->put('runs', 'run', $run);
            }
            $importer = new MailImporter($this->bridge);
            foreach ($selected as $folderId => $folder) {
                $folderId = $folder['id'];
                $state = $store->get('folders', $folderId);
                if (($state['round'] ?? null) !== $run['round']) {
                    $baseline = $run['full'] || ($state['fingerprint'] ?? null) !== $fingerprint || empty($state['delta']);
                    $state = ['round' => $run['round'], 'fingerprint' => $fingerprint, 'generation' => $baseline ? bin2hex(random_bytes(16)) : $state['generation'], 'delta' => $baseline ? null : $state['delta'], 'next' => $baseline ? null : $state['delta'], 'complete' => false, 'baseline' => $baseline, 'seen_links' => [], 'resets' => 0];
                    $store->put('folders', $folderId, $state);
                    if ($baseline) {
                        $run['reconcile_required'] = true;
                        $store->put('runs', 'run', $run);
                    }
                }
                if ($state['complete']) {
                    continue;
                }
                $base = $client->root().'/mailFolders/'.rawurlencode($folderId).'/messages/delta';
                while (! $state['complete']) {
                    if ($this->limited($totals, $settings, $start)) {
                        return $this->result($installation, $totals, false, $client);
                    }
                    if (! isset($state['page'])) {
                        $url = $state['next'] ?? $base.'?$select=id,parentFolderId&$top='.max(1, min(100, (int) ($settings['sync']['page_size'] ?? 100)));
                        if ($state['next'] !== null) {
                            if (! is_string($state['next']) || $state['next'] === '') {
                                throw new \UnexpectedValueException('Invalid persisted Graph delta continuation');
                            }
                            $client->validateContinuation($state['next'], $base);
                        }
                        try {
                            $page = $client->get($url);
                        } catch (InvalidDeltaCursorException $e) {
                            if ($state['resets'] >= 1) {
                                throw $e;
                            }
                            $state = array_merge($state, ['generation' => bin2hex(random_bytes(16)), 'delta' => null, 'next' => null, 'baseline' => true, 'seen_links' => [], 'resets' => $state['resets'] + 1]);
                            $run['reconcile_required'] = true;
                            $store->put('runs', 'run', $run);
                            $store->put('folders', $folderId, $state);

                            continue;
                        }
                        if (! is_array($page['value'] ?? null) || ! array_is_list($page['value']) || count($page['value']) > 1000) {
                            throw new \UnexpectedValueException('Invalid Graph delta page');
                        }
                        $next = $page['@odata.nextLink'] ?? null;
                        $delta = $page['@odata.deltaLink'] ?? null;
                        if (($next !== null && ! is_string($next)) || ($delta !== null && ! is_string($delta)) || (is_string($next) === is_string($delta)) || ($next === '') || ($delta === '')) {
                            throw new \UnexpectedValueException('Graph delta page needs exactly one continuation or checkpoint');
                        }
                        $client->validateContinuation($next ?? $delta, $base);
                        $linkHash = hash('sha256', $next ?? $delta);
                        if (isset($state['seen_links'][$linkHash]) && $next !== null) {
                            throw new \UnexpectedValueException('Cyclic Graph delta continuation');
                        }
                        // Persist only immutable IDs/removal markers, never full bodies or headers.
                        $items = [];
                        foreach ($page['value'] as $item) {
                            if (! is_array($item) || ! is_string($item['id'] ?? null) || $item['id'] === '') {
                                throw new \UnexpectedValueException('Delta item has no identity');
                            }
                            $items[] = ['id' => $item['id'], 'removed' => array_key_exists('@removed', $item)];
                        }
                        $state['page'] = ['items' => $items, 'index' => 0, 'next' => $next, 'delta' => $delta];
                        $state['seen_links'][$linkHash] = true;
                        if (count($state['seen_links']) > 10000) {
                            throw new \RuntimeException('Delta round exceeds finite continuation limit');
                        }
                        $store->put('folders', $folderId, $state);
                        $totals['pages']++;
                    }
                    while ($state['page']['index'] < count($state['page']['items'])) {
                        if ($this->limited($totals, $settings, $start, false)) {
                            return $this->result($installation, $totals, false, $client);
                        }
                        $item = $state['page']['items'][$state['page']['index']];
                        $outcome = $this->process($installation, $project, $mailbox, $client, $store, $importer, $item['id'], $selected, $settings, $state['generation'], (int) ($settings['sync']['max_bytes'] ?? 104857600) - $totals['bytes']);
                        if ($outcome['limited'] ?? false) {
                            return $this->result($installation, $totals, false, $client);
                        }
                        foreach (['added', 'updated', 'removed', 'bytes', 'skipped'] as $metric) {
                            $totals[$metric] += $outcome[$metric];
                        }
                        $totals['messages']++;
                        $state['page']['index']++;
                        $store->put('folders', $folderId, $state);
                    }
                    // Only this boundary advances the opaque continuation; delta commits at round end.
                    $state['next'] = $state['page']['next'];
                    if ($state['page']['delta'] !== null) {
                        $state['delta'] = $state['page']['delta'];
                        $state['complete'] = true;
                        $state['baseline'] = false;
                        $state['seen_links'] = [];
                    }
                    unset($state['page']);
                    $store->put('folders', $folderId, $state);
                }
            }
            // A folder removal can be a move. A transient lookup 404 is only
            // confirmed after every selected destination has durably completed.
            while (true) {
                $row = $store->query('messages')->where('pending_tombstone', true)->where('key', '>', $run['tombstone_after'] ?? '')->orderBy('key')->first();
                if ($row === null) {
                    break;
                }
                if ($this->limited($totals, $settings, $start, false)) {
                    return $this->result($installation, $totals, false, $client);
                }
                $record = json_decode($row->data, true, 512, JSON_THROW_ON_ERROR);
                $current = isset($selected[$record['folder'] ?? '']) ? $store->get('folders', $record['folder']) : [];
                $outcome = $this->process($installation, $project, $mailbox, $client, $store, $importer, $record['id'], $selected, $settings, $current['generation'] ?? $run['round'], (int) ($settings['sync']['max_bytes'] ?? 104857600) - $totals['bytes'], true);
                if ($outcome['limited'] ?? false) {
                    return $this->result($installation, $totals, false, $client);
                }
                foreach (['added', 'updated', 'removed', 'bytes', 'skipped'] as $metric) {
                    $totals[$metric] += $outcome[$metric];
                }
                $totals['messages']++;
                $run['tombstone_after'] = $row->key;
                $store->put('runs', 'run', $run);
            }
            // A full scan must finish all selected folders before absence authorizes any prune.
            if (($settings['reconciliation']['enabled'] ?? false) && $run['reconcile_required']) {
                while (true) {
                    if ($this->limited($totals, $settings, $start, false)) {
                        return $this->result($installation, $totals, false, $client);
                    }
                    $row = $store->query('messages')->where('active', true)->where('key', '>', $run['reconcile_after'])->orderBy('key')->first();
                    if ($row === null) {
                        break;
                    }
                    $record = json_decode($row->data, true, 512, JSON_THROW_ON_ERROR);
                    $current = isset($selected[$record['folder'] ?? '']) ? $store->get('folders', $record['folder']) : [];
                    if (($row->generation ?? null) !== ($current['generation'] ?? null) || $current === []) {
                        $outcome = $this->process($installation, $project, $mailbox, $client, $store, $importer, $record['id'], $selected, $settings, $current['generation'] ?? $run['round'], (int) ($settings['sync']['max_bytes'] ?? 104857600) - $totals['bytes'], true);
                        if ($outcome['limited'] ?? false) {
                            return $this->result($installation, $totals, false, $client);
                        }
                        foreach (['added', 'updated', 'removed', 'bytes', 'skipped'] as $metric) {
                            $totals[$metric] += $outcome[$metric];
                        }
                    }
                    $totals['messages']++;
                    $run['reconcile_after'] = $row->key;
                    $store->put('runs', 'run', $run);
                }
            }
            $run['active'] = false;
            $run['complete'] = true;
            $store->put('runs', 'run', $run);

            return $this->result($installation, $totals, true, $client);
        } catch (\Throwable $exception) {
            $this->bridge->emitAudit('microsoft-mail', 'sync_batch_failed', (int) $installation->id, ['error_type' => $exception::class, 'messages' => $totals['messages']] + $client->metrics());
            throw $exception;
        } finally {
            $lock->release();
        }
    }

    public static function installationLock(ConnectorInstallation $installation): string
    {
        return 'microsoft-mail:sync-installation:'.hash('sha256', json_encode([(string) $installation->tenant_id, (int) $installation->id], JSON_THROW_ON_ERROR));
    }

    private function process(ConnectorInstallation $installation, string $project, string $mailbox, GraphClient $client, SyncStore $store, MailImporter $importer, string $id, array $selected, array $settings, string $generation, int $remainingBytes, bool $confirmDeletion = false): array
    {
        try {
            $message = $client->message($id);
        } catch (GraphResourceNotFoundException) {
            $previous = $store->get('messages', $id);
            $removed = 0;
            if (($previous['active'] ?? false) && ! $confirmDeletion) {
                $previous['pending_tombstone'] = true;
                $store->put('messages', $id, $previous, ['pending_tombstone' => true]);
            } else {
                if ($confirmDeletion) {
                    $removed = $importer->exclude($installation, $store, $id, $previous, $settings);
                }
                $previous = array_merge(['id' => $id, 'active' => false, 'excluded' => 'missing'], $previous, ['pending_tombstone' => false]);
                $store->put('messages', $id, $previous, ['pending_tombstone' => false, 'active' => (bool) $previous['active']]);
            }

            return ['added' => 0, 'updated' => 0, 'removed' => $removed, 'bytes' => 0, 'skipped' => 1];
        }
        $currentFolder = (string) ($message['parentFolderId'] ?? '');
        $currentState = $store->get('folders', $currentFolder);

        $outcome = $importer->import($installation, $project, $mailbox, $client, $store, $message, $selected, $settings, $currentState['generation'] ?? $generation, $remainingBytes);
        if (! ($outcome['limited'] ?? false)) {
            $record = $store->get('messages', $id);
            $record['pending_tombstone'] = false;
            $store->put('messages', $id, $record, ['pending_tombstone' => false]);
        }

        return $outcome;
    }

    public static function selectFolders(array $mapping, array $settings): array
    {
        $config = $settings['folders'] ?? [];
        $include = $config['include'] ?? [];
        $exclude = $config['exclude'] ?? [];
        foreach ($include as $id) {
            if (! isset($mapping[$id])) {
                throw new \InvalidArgumentException('Selected Microsoft Mail folder is unavailable; rediscover folders');
            }
        }

        return array_filter($mapping, static function (array $folder) use ($config, $include, $exclude): bool {
            $id = $folder['id'];
            if (in_array($id, $exclude, true) || ($include !== [] && ! in_array($id, $include, true))) {
                return false;
            }
            if ($include !== []) {
                return true;
            }

            return match ($folder['well_known'] ?? null) {
                'deleteditems' => (bool) ($config['include_deleted'] ?? false),
                'junkemail' => (bool) ($config['include_junk'] ?? false),
                default => (bool) ($folder['default_selected'] ?? true),
            };
        });
    }

    private function limited(array $totals, array $settings, float $start, bool $pages = true): bool
    {
        $limits = $settings['sync'] ?? [];

        return ($pages && $totals['pages'] >= max(1, (int) ($limits['max_pages'] ?? 20)))
            || $totals['messages'] >= max(1, (int) ($limits['max_messages'] ?? 500))
            || $totals['bytes'] >= max(1, (int) ($limits['max_bytes'] ?? 104857600))
            || microtime(true) - $start >= max(1, min(300, (int) ($limits['time_budget_seconds'] ?? 60)));
    }

    private function result(ConnectorInstallation $installation, array $totals, bool $complete, GraphClient $client): SyncResult
    {
        $this->bridge->emitAudit('microsoft-mail', 'sync_batch_completed', (int) $installation->id, array_merge($totals, ['complete' => $complete, 'graph_requests' => $client->metrics()['requests'], 'graph_bytes' => $client->metrics()['bytes']]));

        return new SyncResult($totals['added'], $totals['updated'], $totals['removed'], [], Carbon::now());
    }
}
