<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorMicrosoftMail\Tests\Support;

use Padosoft\AskMyDocsConnectorMicrosoftMail\Mail\EmailMetadata;

/** Synthetic host registry/receipt gate for the frozen contract; no classifier or AI. */
final class PreparedListener
{
    public array $registered = [];

    public array $ready = [];

    public bool $enabled = true;

    public function __invoke(array $envelope): void
    {
        $metadata = $envelope['metadata'];
        $manifest = $envelope['manifest'];
        if ($envelope['schema_version'] !== 1 || $metadata['email_schema_version'] !== 1 || $metadata['connector'] !== 'microsoft-mail' || $metadata['installation_id'] !== $envelope['installation_id'] || $metadata['project_key'] !== $envelope['project_key']) {
            throw new \UnexpectedValueException('Invalid synthetic host envelope');
        }
        $key = EmailMetadata::messageKey($envelope['tenant_id'], $envelope['installation_id'], $metadata['email_mailbox_id'], $metadata['email_message_id']);
        if ($metadata['email_message_key'] !== $key || count($manifest) !== $metadata['email_attachment_count']) {
            throw new \UnexpectedValueException('Invalid parent identity or manifest count');
        }
        $identity = [];
        $previousId = null;
        foreach ($manifest as $slot => $file) {
            if ($slot !== $file['slot'] || ($previousId !== null && strcmp($previousId, $file['id']) >= 0) || ! in_array($file['status'], ['pending', 'excluded'], true) || ($file['status'] === 'pending' && (! is_string($file['hash']) || strlen($file['hash']) !== 64)) || ($file['status'] === 'excluded' && ($file['hash'] !== null || ! is_string($file['reason'])))) {
                throw new \UnexpectedValueException('Invalid synthetic host attachment manifest');
            }
            $identity[] = [$file['slot'], $file['id'], $file['name'], $file['mime'], $file['hash'], $file['status'] === 'excluded' ? $file['reason'] : null];
            $previousId = $file['id'];
        }
        $revision = hash('sha256', json_encode([$metadata['email_body_hash'], $identity], EmailMetadata::JSON_FLAGS));
        if ($metadata['email_revision_hash'] !== $revision) {
            throw new \UnexpectedValueException('Revision hash does not match manifest');
        }
        if (! $this->enabled) {
            return;
        }
        if (($this->registered[$key]['metadata']['email_revision_hash'] ?? null) !== $revision) {
            $this->registered[$key] = ['metadata' => $metadata, 'manifest' => $manifest, 'body' => false, 'receipts' => [], 'state' => 'pending'];
        }
    }

    public function receive(array $metadata, string $mime, string $bytes): void
    {
        if (! $this->enabled) {
            return;
        }
        $key = $metadata['email_message_key'];
        $record = &$this->registered[$key];
        if (! is_array($record) || $record['metadata']['email_revision_hash'] !== $metadata['email_revision_hash']) {
            throw new \UnexpectedValueException('Missing or obsolete prepared revision');
        }
        if (! isset($metadata['email_attachment_id'])) {
            if ($mime !== 'text/markdown' || hash('sha256', $bytes) !== $metadata['email_body_hash']) {
                throw new \UnexpectedValueException('Body receipt differs from prepared bytes');
            }
            $record['body'] = true;
        } else {
            $slot = $metadata['email_attachment_slot'];
            $part = $record['manifest'][$slot] ?? null;
            if ($part === null || $part['status'] !== 'pending' || $part['id'] !== $metadata['email_attachment_id'] || $part['hash'] !== hash('sha256', $bytes) || $part['mime'] !== $mime) {
                throw new \UnexpectedValueException('Attachment receipt differs from prepared part');
            }
            $record['receipts'][$slot] = true;
        }
        $pending = array_filter($record['manifest'], static fn (array $part): bool => $part['status'] === 'pending');
        if ($record['body'] && count($record['receipts']) === count($pending) && $record['state'] !== 'ready') {
            $record['state'] = 'ready';
            $this->ready[] = [$key, $metadata['email_revision_hash']];
        }
    }
}
