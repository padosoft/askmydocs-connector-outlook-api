<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorMicrosoftMail\Mail;

use InvalidArgumentException;
use Padosoft\AskMyDocsConnectorBase\Support\Metadata\SourceAwareMetadataBuilder;

final class EmailMetadata
{
    public const JSON_FLAGS = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    public static function messageKey(string $tenant, int $installation, string $mailbox, string $messageId): string
    {
        if ($tenant === '' || $installation < 1 || $mailbox === '' || $messageId === '') {
            throw new InvalidArgumentException('Email identity requires tenant, installation, mailbox and native message ID.');
        }

        return 'microsoft-mail:'.hash('sha256', json_encode([$tenant, $installation, 'microsoft-mail', $mailbox, $messageId], self::JSON_FLAGS));
    }

    /**
     * @param  array<string, mixed>  $message
     * @param  array<array-key, mixed>  $folders  Folder ID => name mapping or descriptors with id/name/displayName.
     * @param  list<array<string, mixed>>  $manifest
     * @return array<string, mixed>
     */
    public function build(string $tenant, int $installation, string $mailbox, array $message, array $folders, string $markdown, array $manifest): array
    {
        $nativeId = (string) ($message['id'] ?? '');
        $key = self::messageKey($tenant, $installation, $mailbox, $nativeId);
        $folderIds = isset($message['parentFolderId']) && $message['parentFolderId'] !== '' ? [(string) $message['parentFolderId']] : [];
        $folderNames = [];
        foreach ($folders as $folderId => $folder) {
            if (is_array($folder)) {
                $id = (string) ($folder['id'] ?? $folderId);
                $name = (string) ($folder['name'] ?? $folder['path'] ?? $folder['displayName'] ?? '');
            } else {
                $id = (string) $folderId;
                $name = (string) $folder;
            }
            if (in_array($id, $folderIds, true) && $name !== '') {
                $folderNames[] = $name;
            }
        }
        $headers = MessageData::headers($message);
        $from = MessageData::address($message['from'] ?? $message['sender'] ?? null);
        $bodyHash = hash('sha256', $markdown);
        $manifestIdentity = array_map(static fn (array $file): array => [
            $file['slot'], $file['id'], $file['name'], $file['mime'], $file['hash'],
            $file['status'] === 'excluded' ? $file['reason'] : null,
        ], $manifest);
        $revision = hash('sha256', json_encode([$bodyHash, $manifestIdentity], self::JSON_FLAGS));
        $date = MessageData::date($message['receivedDateTime'] ?? null);
        $sourceFields = [
            'id' => $nativeId,
            'mailbox_id' => $mailbox,
            'parent_folder_id' => $message['parentFolderId'] ?? null,
            'folder_names' => $folderNames,
            'subject' => MessageData::decodeHeader((string) ($message['subject'] ?? '')),
            'message_id' => $message['internetMessageId'] ?? $headers['message-id'] ?? null,
            'in_reply_to' => $headers['in-reply-to'] ?? null,
            'references' => $headers['references'] ?? null,
            'internet_message_headers' => $headers,
            'conversation_id' => $message['conversationId'] ?? null,
            'conversation_index' => $message['conversationIndex'] ?? null,
            'from' => $from,
            'sender' => MessageData::address($message['sender'] ?? $message['from'] ?? null),
            'from_name' => $from['name'],
            'from_email' => $from['email'],
            'to' => MessageData::addresses($message['toRecipients'] ?? []),
            'cc' => MessageData::addresses($message['ccRecipients'] ?? []),
            'bcc' => MessageData::addresses($message['bccRecipients'] ?? []),
            'received_date_time' => $date,
            'sent_date_time' => MessageData::date($message['sentDateTime'] ?? null),
            'last_modified_date_time' => MessageData::date($message['lastModifiedDateTime'] ?? null),
            'categories' => array_values((array) ($message['categories'] ?? [])),
            'importance' => $message['importance'] ?? null,
            'flag' => $message['flag'] ?? null,
            'is_read' => $message['isRead'] ?? null,
            'is_draft' => $message['isDraft'] ?? null,
            'has_attachments' => $manifest !== [],
            'attachment_count' => count($manifest),
        ];
        $tags = array_values(array_filter(array_merge($folderNames, (array) ($message['categories'] ?? [])), 'is_string'));

        return (new SourceAwareMetadataBuilder)->build(
            base: [
                'connector' => 'microsoft-mail',
                'installation_id' => $installation,
                'email_schema_version' => 1,
                'email_provider' => 'microsoft-mail',
                'email_mailbox_id' => $mailbox,
                'email_message_id' => $nativeId,
                'email_message_key' => $key,
                'microsoft_mail_doc_key' => $key,
                'email_folder_ids' => $folderIds,
                'email_folder_names' => $folderNames,
                'email_date' => $date,
                'email_attachment_count' => count($manifest),
                'email_body_hash' => $bodyHash,
                'email_revision_hash' => $revision,
            ],
            sourceKey: 'microsoft_mail',
            sourceFields: $sourceFields,
            tags: $tags,
            statusActive: true,
            lastModified: $sourceFields['last_modified_date_time'] ?? $date,
            owner: $from['email'] !== '' ? $from['email'] : null,
        );
    }

    /** @param array<string, mixed> $parent @param array<string, mixed> $file @return array<string, mixed> */
    public function attachment(array $parent, array $file): array
    {
        return array_merge($parent, [
            'email_attachment_id' => $file['id'],
            'email_attachment_slot' => $file['slot'],
            'attachment_filename' => $file['name'],
            'attachment_of_message_id' => $parent['email_message_id'],
            'email_attachment_hash' => hash('sha256', (string) $file['bytes']),
        ]);
    }
}
