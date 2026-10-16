<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorMicrosoftMail\Tests\Support;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

final class GraphFixtures
{
    public array $folders = [['id' => 'inbox-id', 'displayName' => 'Inbox', 'childFolderCount' => 0], ['id' => 'archive-id', 'displayName' => 'Archive', 'childFolderCount' => 0]];

    public array $messages = [];

    public array $attachments = [];

    public array $pages = [];

    public array $requests = [];

    public ?string $failMessage = null;

    public function install(): void
    {
        Http::fake(function (Request $request) {
            $this->requests[] = $request;
            if ($request->method() !== 'GET') {
                throw new \RuntimeException('Mail sync performed a mutating request');
            }
            if (! str_contains($request->header('Prefer')[0] ?? '', 'IdType="ImmutableId"')) {
                throw new \RuntimeException('Missing ImmutableId preference');
            }
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            if (str_ends_with($path, '/mailFolders/deleteditems')) {
                return Http::response(['id' => 'deleted-id']);
            }
            if (str_ends_with($path, '/mailFolders/junkemail')) {
                return Http::response(['id' => 'junk-id']);
            }
            if (str_ends_with($path, '/mailFolders/inbox')) {
                return Http::response(['id' => 'inbox-id']);
            }
            if (str_ends_with($path, '/mailFolders')) {
                return Http::response(['value' => $this->folders]);
            }
            if (preg_match('~/mailFolders/([^/]+)/messages/delta$~', $path, $match)) {
                $folder = rawurldecode($match[1]);
                parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
                $token = $query['$skiptoken'] ?? $query['$deltatoken'] ?? 'baseline';
                $page = $this->pages[$folder][$token] ?? ['value' => [], '@odata.deltaLink' => self::delta($folder, 'committed')];
                if (is_int($page)) {
                    return Http::response(['error' => ['code' => $page === 410 ? 'SyncStateNotFound' : 'ErrorSynthetic']], $page);
                }

                return Http::response($page);
            }
            if (preg_match('~/messages/([^/]+)/attachments(?:/([^/]+))?$~', $path, $match)) {
                $files = $this->attachments[rawurldecode($match[1])] ?? [];
                if (isset($match[2])) {
                    foreach ($files as $file) {
                        if ($file['id'] === rawurldecode($match[2])) {
                            return Http::response($file);
                        }
                    }

                    return Http::response(['error' => ['code' => 'ErrorItemNotFound']], 404);
                }

                return Http::response(['value' => $files]);
            }
            if (preg_match('~/messages/([^/]+)$~', $path, $match)) {
                $id = rawurldecode($match[1]);
                if ($this->failMessage === $id) {
                    return Http::response(['error' => ['code' => 'ErrorSynthetic']], 503);
                }

                return isset($this->messages[$id]) ? Http::response($this->messages[$id]) : Http::response(['error' => ['code' => 'ErrorItemNotFound']], 404);
            }
            throw new \RuntimeException('Unexpected synthetic Graph route');
        });
    }

    public static function message(string $id, string $folder = 'inbox-id', string $body = 'Complete body'): array
    {
        return ['id' => $id, 'subject' => 'Synthetic email', 'parentFolderId' => $folder, 'receivedDateTime' => '2026-10-01T12:00:00Z', 'lastModifiedDateTime' => '2026-10-01T12:00:00Z', 'body' => ['contentType' => 'text', 'content' => $body], 'from' => ['emailAddress' => ['address' => 'private@example.test', 'name' => 'Synthetic sender']], 'sender' => ['emailAddress' => ['address' => 'private@example.test']], 'toRecipients' => [['emailAddress' => ['address' => 'recipient@example.test']]], 'ccRecipients' => [], 'bccRecipients' => [], 'internetMessageHeaders' => [['name' => 'Message-ID', 'value' => '<synthetic@example.test>']], 'internetMessageId' => '<synthetic@example.test>', 'isRead' => false, 'flag' => ['flagStatus' => 'notFlagged'], 'categories' => [], 'hasAttachments' => false];
    }

    public static function delta(string $folder, string $token): string
    {
        return 'https://graph.microsoft.com/v1.0/me/mailFolders/'.rawurlencode($folder).'/messages/delta?$deltatoken='.$token;
    }

    public static function next(string $folder, string $token): string
    {
        return 'https://graph.microsoft.com/v1.0/me/mailFolders/'.rawurlencode($folder).'/messages/delta?$skiptoken='.$token;
    }
}
