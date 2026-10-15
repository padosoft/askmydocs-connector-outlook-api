<?php

declare(strict_types=1);

namespace Tests\Unit;

use Padosoft\AskMyDocsConnectorMicrosoftMail\Mail\EmailMetadata;
use PHPUnit\Framework\TestCase;

final class EmailMetadataTest extends TestCase
{
    public function test_parent_key_and_revision_match_frozen_algorithms_and_are_move_stable(): void
    {
        $message = ['id' => 'IMMUTABLE_A', 'parentFolderId' => 'inbox-id', 'receivedDateTime' => '2026-10-14T12:00:00Z', 'subject' => 'Subject', 'categories' => ['Business'], 'internetMessageHeaders' => []];
        $manifest = [['slot' => 0, 'id' => 'part-A', 'name' => 'report.txt', 'mime' => 'text/plain', 'hash' => hash('sha256', 'file'), 'status' => 'pending', 'reason' => null]];
        $markdown = "Full body\n";
        $metadata = (new EmailMetadata)->build('tenant-1', 4, 'directory/account-id', $message, ['inbox-id' => 'Inbox'], $markdown, $manifest);
        $key = 'microsoft-mail:'.hash('sha256', json_encode(['tenant-1', 4, 'microsoft-mail', 'directory/account-id', 'IMMUTABLE_A'], EmailMetadata::JSON_FLAGS));
        self::assertSame($key, $metadata['email_message_key']);
        self::assertSame(hash('sha256', $markdown), $metadata['email_body_hash']);
        $expectedRevision = hash('sha256', json_encode([hash('sha256', $markdown), [[0, 'part-A', 'report.txt', 'text/plain', hash('sha256', 'file'), null]]], EmailMetadata::JSON_FLAGS));
        self::assertSame($expectedRevision, $metadata['email_revision_hash']);
        $moved = (new EmailMetadata)->build('tenant-1', 4, 'directory/account-id', array_replace($message, ['parentFolderId' => 'archive-id', 'isRead' => true]), ['archive-id' => 'Archive'], $markdown, $manifest);
        self::assertSame($metadata['email_message_key'], $moved['email_message_key']);
        self::assertSame($metadata['email_revision_hash'], $moved['email_revision_hash']);
        self::assertSame(['Archive'], $moved['email_folder_names']);
        self::assertSame(['Archive', 'Business'], $moved['_derived']['search_tags']);
        self::assertSame($moved['_derived'], $moved['converter_hints']['_derived']);
        self::assertArrayHasKey('microsoft_mail', $moved['converter_hints']);
        self::assertNotContains('IMMUTABLE_A', $moved['_derived']['search_tags']);
    }

    public function test_native_case_tenant_installation_and_mailbox_isolate_parent_keys(): void
    {
        $original = EmailMetadata::messageKey('tenant', 1, 'mailbox', 'Id');
        foreach ([['TENANT', 1, 'mailbox', 'Id'], ['tenant', 2, 'mailbox', 'Id'], ['tenant', 1, 'other', 'Id'], ['tenant', 1, 'mailbox', 'id']] as $identity) {
            self::assertNotSame($original, EmailMetadata::messageKey(...$identity));
        }
    }

    public function test_receipt_status_is_excluded_from_revision_and_attachment_linkage_keeps_hashes(): void
    {
        $message = ['id' => 'native'];
        $manifest = [['slot' => 0, 'id' => 'part', 'name' => 'same.txt', 'mime' => 'text/plain', 'hash' => hash('sha256', 'bytes'), 'status' => 'pending', 'reason' => null]];
        $builder = new EmailMetadata;
        $pending = $builder->build('tenant', 1, 'mailbox', $message, [], 'body', $manifest);
        $manifest[0]['status'] = 'saved';
        $saved = $builder->build('tenant', 1, 'mailbox', $message, [], 'body', $manifest);
        self::assertSame($pending['email_revision_hash'], $saved['email_revision_hash']);
        $attachment = $builder->attachment($pending, ['id' => 'part', 'slot' => 0, 'name' => 'same.txt', 'bytes' => 'bytes']);
        self::assertSame($pending['email_message_key'], $attachment['email_message_key']);
        self::assertSame($pending['email_revision_hash'], $attachment['email_revision_hash']);
        self::assertSame('native', $attachment['attachment_of_message_id']);
        self::assertSame(0, $attachment['email_attachment_slot']);
        self::assertSame(hash('sha256', 'bytes'), $attachment['email_attachment_hash']);
        $manifest[0]['status'] = 'excluded';
        $manifest[0]['reason'] = 'unsupported';
        $excluded = $builder->build('tenant', 1, 'mailbox', $message, [], 'body', $manifest);
        self::assertNotSame($pending['email_revision_hash'], $excluded['email_revision_hash']);
    }
}
