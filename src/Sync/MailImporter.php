<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorMicrosoftMail\Sync;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Padosoft\AskMyDocsConnectorBase\Contracts\ConnectorIngestionContract;
use Padosoft\AskMyDocsConnectorBase\Models\ConnectorInstallation;
use Padosoft\AskMyDocsConnectorMicrosoftMail\Graph\GraphClient;
use Padosoft\AskMyDocsConnectorMicrosoftMail\Mail\AttachmentPreparer;
use Padosoft\AskMyDocsConnectorMicrosoftMail\Mail\EmailMetadata;
use Padosoft\AskMyDocsConnectorMicrosoftMail\Mail\EmailRenderer;
use Padosoft\AskMyDocsConnectorMicrosoftMail\Mail\MessageFilter;

/** Receipts are persisted after the bridge has durably accepted each dispatch. */
final class MailImporter
{
    public function __construct(private readonly ConnectorIngestionContract $bridge) {}

    public function import(ConnectorInstallation $installation, string $project, string $mailbox, GraphClient $client, SyncStore $store, array $message, array $folders, array $settings, string $generation, int $remainingBytes): array
    {
        $id = $message['id'] ?? null;
        if (! is_string($id) || $id === '') {
            throw new \UnexpectedValueException('Graph message has no immutable identity');
        }
        $previous = $store->get('messages', $id);
        $folder = (string) ($message['parentFolderId'] ?? '');
        if ($folder === '') {
            throw new \UnexpectedValueException('Graph message has no current folder');
        }
        $accepted = isset($folders[$folder]) && (new MessageFilter)->accepts($message, $settings);
        if (! $accepted) {
            $removed = $this->exclude($installation, $store, $id, $previous, $settings);
            $previous['pending_tombstone'] = false;
            $store->put('messages', $id, array_merge($previous, ['id' => $id, 'folder' => $folder, 'excluded' => 'selection', 'active' => $previous['active'] ?? false, 'key' => $previous['key'] ?? null]), ['folder_key' => $store->key($folder), 'generation' => $generation, 'active' => $previous['active'] ?? false]);

            return ['added' => 0, 'updated' => 0, 'removed' => $removed, 'bytes' => 0, 'skipped' => 1];
        }
        $markdown = (new EmailRenderer)->render($message, $settings);
        // Always consult the host policy before hashing or preparing the triage snapshot.
        $markdown = $this->bridge->redactContent($markdown);
        $prepared = (new AttachmentPreparer(redactor: fn (string $content): string => $this->bridge->redactContent($content)))->prepare($client->attachments($id, $settings), $settings);
        $bytes = strlen($markdown) + array_sum(array_map(static fn (array $file): int => strlen($file['bytes']), $prepared['files']));
        if ($bytes > (int) ($settings['sync']['max_bytes'] ?? 104857600)) {
            throw new \RuntimeException('Message exceeds the configured batch byte budget');
        }
        if ($bytes > $remainingBytes) {
            return ['added' => 0, 'updated' => 0, 'removed' => 0, 'bytes' => 0, 'skipped' => 0, 'limited' => true];
        }
        $metadata = (new EmailMetadata)->build((string) $installation->tenant_id, (int) $installation->id, $mailbox, $message, $folders, $markdown, $prepared['manifest']);
        $metadata['project_key'] = $project;
        $projection = hash('sha256', json_encode($metadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $revision = $metadata['email_revision_hash'];
        $unchanged = ($previous['revision'] ?? null) === $revision && ($previous['projection'] ?? null) === $projection && ($previous['complete'] ?? false) && ($previous['active'] ?? false);
        if ($unchanged) {
            $previous['pending_tombstone'] = false;
            $store->put('messages', $id, $previous, ['folder_key' => $store->key($folder), 'generation' => $generation, 'active' => true]);

            return ['added' => 0, 'updated' => 0, 'removed' => 0, 'bytes' => $bytes, 'skipped' => 1];
        }
        Event::dispatch('askmydocs.email.prepared.v1', [[
            'schema_version' => 1,
            'tenant_id' => (string) $installation->tenant_id,
            'installation_id' => (int) $installation->id,
            'project_key' => $project,
            'metadata' => $metadata,
            'email_date' => $metadata['email_date'],
            'manifest' => $prepared['manifest'],
        ]]);
        $record = ['id' => $id, 'folder' => $folder, 'key' => $metadata['email_message_key'], 'revision' => $revision, 'projection' => $projection, 'complete' => false, 'active' => true, 'receipts' => (($previous['revision'] ?? null) === $revision && ($previous['projection'] ?? null) === $projection) ? ($previous['receipts'] ?? []) : [], 'paths' => [], 'attachment_sources' => [], 'retired_attachment_sources' => [], 'pending_tombstone' => (bool) ($previous['pending_tombstone'] ?? false)];
        $prefix = 'connectors/microsoft-mail/'.hash('sha256', (string) $installation->tenant_id).'/'.$installation->id.'/'.hash('sha256', $metadata['email_message_key']);
        $documents = [['receipt' => 'body', 'path' => $prefix.'/body.md', 'bytes' => $markdown, 'mime' => 'text/markdown', 'name' => (string) ($message['subject'] ?? ''), 'metadata' => $metadata]];
        foreach ($prepared['files'] as $file) {
            $sourcePath = $prefix.'/attachments/'.$file['slot'].'-'.hash('sha256', $file['id']).'-'.$file['name'];
            // An additive source key lets the public bridge retire one obsolete attachment,
            // while the frozen parent identity and revision remain unchanged.
            $sourceKey = 'microsoft-mail-attachment:'.hash('sha256', json_encode([$metadata['email_message_key'], $sourcePath], EmailMetadata::JSON_FLAGS));
            $attachmentMetadata = array_merge($metadata, ['email_attachment_id' => $file['id'], 'email_attachment_slot' => $file['slot'], 'email_attachment_hash' => hash('sha256', $file['bytes']), 'email_attachment_source_key' => $sourceKey, 'attachment_filename' => $file['name'], 'attachment_of_message_id' => $id]);
            $record['attachment_sources'][$sourcePath] = array_merge($previous['attachment_sources'][$sourcePath] ?? [], ['key' => $sourceKey]);
            $documents[] = ['receipt' => 'attachment:'.$file['slot'].':'.$file['id'], 'path' => $sourcePath, 'bytes' => $file['bytes'], 'mime' => $file['mime'], 'name' => $file['name'], 'metadata' => $attachmentMetadata];
        }
        if ($settings['reconciliation']['enabled'] ?? false) {
            $record['retired_attachment_sources'] = array_merge(
                $previous['retired_attachment_sources'] ?? [],
                array_diff_key($previous['attachment_sources'] ?? [], $record['attachment_sources']),
            );
        }
        $record['paths'] = array_column($documents, 'path');
        $stateColumns = ['folder_key' => $store->key($folder), 'generation' => $generation, 'active' => true];
        // Persist the cleanup backlog before any dispatch can replace prior source receipts.
        $store->put('messages', $id, $record, $stateColumns);
        foreach ($documents as $document) {
            if (! isset($record['receipts'][$document['receipt']])) {
                $path = $this->bridge->resolveKbSourcePath($document['path']);
                if (isset($record['attachment_sources'][$document['path']])) {
                    $record['attachment_sources'][$document['path']] = array_merge($record['attachment_sources'][$document['path']], ['disk' => $path['disk'], 'absolute' => $path['absolute'], 'relative' => $path['relative']]);
                    $store->put('messages', $id, $record, $stateColumns);
                }
                if (! Storage::disk($path['disk'])->put($path['absolute'], $document['bytes'])) {
                    throw new \RuntimeException('Email source write failed');
                }
                $this->bridge->dispatchIngestion($project, $path['relative'], $path['disk'], $document['name'], $document['metadata'], $document['mime'], (string) $installation->tenant_id);
                $record['receipts'][$document['receipt']] = hash('sha256', $document['bytes']);
                $store->put('messages', $id, $record, $stateColumns);
            }
        }
        foreach ($record['retired_attachment_sources'] as $sourcePath => $source) {
            if (isset($record['attachment_sources'][$sourcePath])) {
                // A pending cleanup can become current again during a provider revision.
                unset($record['retired_attachment_sources'][$sourcePath]);

                continue;
            }
            $this->bridge->softDeleteByRemoteId($installation, 'email_attachment_source_key', $source['key']);
            $path = isset($source['disk'], $source['absolute'], $source['relative']) ? $source : $this->bridge->resolveKbSourcePath($sourcePath);
            $disk = Storage::disk($path['disk']);
            if ($disk->exists($path['absolute']) && ! $disk->delete($path['absolute'])) {
                throw new \RuntimeException('Obsolete email attachment source deletion failed');
            }
            unset($record['retired_attachment_sources'][$sourcePath]);
            $store->put('messages', $id, $record, $stateColumns);
        }
        $record['complete'] = true;
        $record['pending_tombstone'] = false;
        $store->put('messages', $id, $record, ['folder_key' => $store->key($folder), 'generation' => $generation, 'active' => true]);

        return ['added' => ($previous['active'] ?? false) ? 0 : 1, 'updated' => ($previous['active'] ?? false) ? 1 : 0, 'removed' => 0, 'bytes' => $bytes, 'skipped' => 0];
    }

    public function exclude(ConnectorInstallation $installation, SyncStore $store, string $id, array &$previous, array $settings): int
    {
        if (($settings['reconciliation']['enabled'] ?? false) && ($previous['active'] ?? false)) {
            if (! is_string($previous['key'] ?? null)) {
                throw new \UnexpectedValueException('Cannot reconcile email without composed identity');
            }
            $this->bridge->softDeleteByRemoteId($installation, 'email_message_key', $previous['key']);
            $previous['active'] = false;
            $previous['pending_tombstone'] = false;
            $previous['excluded'] = 'deleted_or_out_of_scope';
            $store->put('messages', $id, $previous, ['active' => false]);

            return 1;
        }

        return 0;
    }
}
