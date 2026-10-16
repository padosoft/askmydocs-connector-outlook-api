<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorMicrosoftMail;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Padosoft\AskMyDocsConnectorBase\BaseConnector;
use Padosoft\AskMyDocsConnectorBase\Contracts\DeclaresProvenance;
use Padosoft\AskMyDocsConnectorBase\Contracts\SupportsConnectionSettings;
use Padosoft\AskMyDocsConnectorBase\Contracts\SupportsFolderDiscovery;
use Padosoft\AskMyDocsConnectorBase\Exceptions\ConnectorAuthException;
use Padosoft\AskMyDocsConnectorBase\HealthStatus;
use Padosoft\AskMyDocsConnectorBase\Models\ConnectorInstallation;
use Padosoft\AskMyDocsConnectorBase\ProvenanceTier;
use Padosoft\AskMyDocsConnectorBase\Support\CredentialField;
use Padosoft\AskMyDocsConnectorBase\SyncResult;
use Padosoft\AskMyDocsConnectorMicrosoftMail\Auth\MicrosoftOAuth;
use Padosoft\AskMyDocsConnectorMicrosoftMail\Graph\GraphClient;
use Padosoft\AskMyDocsConnectorMicrosoftMail\Sync\SyncEngine;
use Padosoft\AskMyDocsConnectorMicrosoftMail\Sync\SyncStore;

final class MicrosoftMailConnector extends BaseConnector implements DeclaresProvenance, SupportsConnectionSettings, SupportsFolderDiscovery
{
    public function key(): string
    {
        return 'microsoft-mail';
    }

    public function displayName(): string
    {
        return 'Microsoft Mail';
    }

    private function oauth(): MicrosoftOAuth
    {
        return new MicrosoftOAuth($this->vault, $this->tenantContext);
    }

    public function oauthScopes(): array
    {
        return $this->oauth()->scopes();
    }

    public function initiateOAuth(int $installationId): string
    {
        return $this->oauth()->initiate($this->loadInstallation($installationId));
    }

    public function handleOAuthCallback(int $installationId, Request $request): void
    {
        $installation = $this->loadInstallation($installationId);
        $this->oauth()->callback($installation, $request);
        $this->emitAudit('connector_connected', $installationId);
    }

    public function refreshTokenIfExpired(int $installationId): string
    {
        return $this->oauth()->token($this->loadInstallation($installationId));
    }

    private function client(ConnectorInstallation $installation): GraphClient
    {
        $token = $this->oauth()->token($installation);
        $mailbox = $this->oauth()->mailbox($installation);
        $settings = $this->settings($installation);

        return new GraphClient($token, $mailbox['path'], ($settings['body']['format'] ?? 'prefer_text') !== 'prefer_html');
    }

    private function settings(ConnectorInstallation $installation): array
    {
        return $this->mergeSettings((array) config('connector-microsoft-mail.defaults', []), $installation->config_json ?? []);
    }

    private function mergeSettings(array $defaults, array $overrides): array
    {
        foreach ($overrides as $key => $value) {
            $defaults[$key] = is_array($value) && ! array_is_list($value) && is_array($defaults[$key] ?? null) ? $this->mergeSettings($defaults[$key], $value) : $value;
        }

        return $defaults;
    }

    public function folderMapping(int $installationId): array
    {
        $installation = $this->loadInstallation($installationId);

        return $this->client($installation)->folders((bool) ($this->settings($installation)['folders']['include_hidden'] ?? false));
    }

    public function listAvailableFolders(int $installationId): array
    {
        return array_values(array_map(static fn (array $folder): string => $folder['id'], $this->folderMapping($installationId)));
    }

    public function syncFull(int $installationId): SyncResult
    {
        return $this->sync($installationId, true);
    }

    public function syncIncremental(int $installationId, ?Carbon $since): SyncResult
    {
        // Native per-folder cursors are authoritative; $since is not a mailbox checkpoint.
        return $this->sync($installationId, false);
    }

    private function sync(int $installationId, bool $full): SyncResult
    {
        $installation = $this->loadInstallation($installationId);
        $client = $this->client($installation);
        $mailbox = $this->oauth()->mailbox($installation);

        return (new SyncEngine($this->ingestion))->run($installation, $this->resolveProjectKey($installation), $mailbox['id'], $client, $this->settings($installation), $full);
    }

    public function syncStatus(int $installationId): array
    {
        $installation = $this->loadInstallation($installationId);
        $mailbox = $this->oauth()->mailbox($installation);
        $state = (new SyncStore($installation, $mailbox['id']))->get('runs', 'run');

        // Opaque continuation URLs are credentials: never return them to diagnostics or logs.
        return ['started' => $state !== [], 'complete' => (bool) ($state['complete'] ?? false), 'active' => (bool) ($state['active'] ?? false), 'mode' => ($state['full'] ?? false) ? 'baseline' : 'incremental'];
    }

    public function disconnect(int $installationId): void
    {
        $installation = $this->loadInstallation($installationId);
        Cache::lock(SyncEngine::installationLock($installation), 900)->block(5, function () use ($installation): void {
            SyncStore::forgetInstallation($installation);
            $this->oauth()->disconnect($installation);
        });
        $this->emitAudit('connector_disconnected', $installationId);
    }

    public function health(int $installationId): HealthStatus
    {
        $installation = $this->loadInstallation($installationId);
        try {
            $client = $this->client($installation);
            $client->withDeadline(microtime(true) + 2);
            $client->get($this->oauth()->mailbox($installation)['path'].'/mailFolders/inbox?$select=id');

            return HealthStatus::healthy();
        } catch (ConnectorAuthException) {
            return HealthStatus::errored('Reconnect Microsoft Mail or verify access to the explicitly selected mailbox.');
        } catch (\Throwable) {
            return HealthStatus::degraded('Microsoft Graph is unavailable; retry the read-only connection check.');
        }
    }

    public function provenanceTier(int $installationId): ProvenanceTier
    {
        $installation = $this->loadInstallation($installationId);

        return ($installation->config_json['provenance_tier'] ?? null) === ProvenanceTier::TrustedInternal->value ? ProvenanceTier::TrustedInternal : ProvenanceTier::UntrustedExternal;
    }

    public function connectionSettingsSchema(): array
    {
        $specs = [
            ['mailbox.target_id', 'Shared mailbox target ID (empty = personal)', 'text', 'Mailbox'],
            ['folders.include', 'Folders to include', 'multiselect', 'Folders'],
            ['folders.exclude', 'Folders to exclude', 'multiselect', 'Folders'],
            ['folders.include_hidden', 'Discover hidden folders', 'checkbox', 'Folders'],
            ['folders.include_deleted', 'Include Deleted Items', 'checkbox', 'Folders'],
            ['folders.include_junk', 'Include Junk Email', 'checkbox', 'Folders'],
            ['sync.date_window_days', 'Historical window in days (0 = all)', 'number', 'Sync'],
            ['sync.max_pages', 'Pages per batch', 'number', 'Sync'],
            ['sync.max_messages', 'Messages per batch', 'number', 'Sync'],
            ['sync.max_bytes', 'Bytes per batch', 'number', 'Sync'],
            ['sync.time_budget_seconds', 'Seconds per batch', 'number', 'Sync'],
            ['filters.only_unseen', 'Only unread', 'checkbox', 'Filters'],
            ['filters.only_flagged', 'Only flagged', 'checkbox', 'Filters'],
            ['filters.skip_auto_generated', 'Skip automated messages', 'checkbox', 'Filters'],
            ['filters.senders.include', 'Sender addresses/domains to include', 'tags', 'Filters'],
            ['filters.senders.exclude', 'Sender addresses/domains to exclude', 'tags', 'Filters'],
            ['filters.recipients.include', 'Recipient addresses/domains to include', 'tags', 'Filters'],
            ['filters.recipients.exclude', 'Recipient addresses/domains to exclude', 'tags', 'Filters'],
            ['filters.subject.include_keywords', 'Subject contains any', 'tags', 'Filters'],
            ['filters.subject.exclude_keywords', 'Subject excludes all', 'tags', 'Filters'],
            ['body.format', 'Preferred body format', 'select', 'Body'],
            ['body.strip_quoted_history', 'Strip quoted replies', 'checkbox', 'Body'],
            ['attachments.enabled', 'Acquire attachments', 'checkbox', 'Attachments'],
            ['attachments.allowed_extensions', 'Allowed extensions', 'tags', 'Attachments'],
            ['attachments.max_size_mb', 'Maximum MiB per attachment', 'number', 'Attachments'],
            ['attachments.max_per_email', 'Maximum accepted attachments', 'number', 'Attachments'],
            ['attachments.max_total_bytes', 'Maximum attachment bytes per message', 'number', 'Attachments'],
            ['attachments.max_depth', 'Maximum nested item depth', 'number', 'Attachments'],
            ['attachments.skip_inline', 'Exclude inline files', 'checkbox', 'Attachments'],
            ['reconciliation.enabled', 'Reconcile proven deletion/selection exits locally', 'checkbox', 'Reconciliation'],
        ];
        $defaults = (array) config('connector-microsoft-mail.defaults', []);

        return array_map(static fn (array $spec): array => (new CredentialField(name: $spec[0], label: $spec[1], type: $spec[2], target: 'config', default: data_get($defaults, $spec[0]), options: $spec[0] === 'body.format' ? ['prefer_text' => 'Plain text', 'prefer_html' => 'HTML converted to Markdown'] : [], group: $spec[3], discovery: $spec[2] === 'multiselect' ? 'folders' : null))->toArray(), $specs);
    }
}
