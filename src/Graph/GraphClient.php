<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorMicrosoftMail\Graph;

use GuzzleHttp\Exception\TransferException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Padosoft\AskMyDocsConnectorBase\Exceptions\ConnectorAuthException;
use Psr\Http\Message\ResponseInterface;

/** Read-only, per-mailbox Graph transport. Never follow redirects or foreign cursors. */
final class GraphClient
{
    private const ORIGIN = 'https://graph.microsoft.com/v1.0';

    private int $requests = 0;

    private int $responseBytes = 0;

    private ?float $deadline = null;

    public function __construct(private readonly string $token, private readonly string $mailboxPath = '/me', private readonly bool $preferPlain = true)
    {
        if ($token === '' || ! preg_match('~^/(?:me|users/[^/?#]+)$~D', $mailboxPath)) {
            throw new ConnectorAuthException('Microsoft Mail requires a valid token and an explicit mailbox path.');
        }
    }

    public function withDeadline(float $deadline): void
    {
        if (! is_finite($deadline)) {
            throw new GraphException('Microsoft Graph time budget is invalid.');
        }
        $this->deadline = $deadline;
    }

    public function root(): string
    {
        return $this->mailboxPath;
    }

    /** @return array{requests:int,bytes:int} */
    public function metrics(): array
    {
        return ['requests' => $this->requests, 'bytes' => $this->responseBytes];
    }

    /** @return array<string,mixed> */
    public function get(string $pathOrAbsoluteUrl): array
    {
        $url = str_starts_with($pathOrAbsoluteUrl, '/') ? self::ORIGIN.$pathOrAbsoluteUrl : $pathOrAbsoluteUrl;
        $this->validateUrl($url);
        $maximum = $this->setting('max_response_bytes', 50 * 1024 * 1024);
        $retries = min(5, max(0, (int) config('connector-microsoft-mail.http.retries', 2)));
        for ($attempt = 0; $attempt <= $retries; $attempt++) {
            $remaining = $this->remaining();
            $timeout = min($this->setting('timeout', 30), $remaining);
            $connectTimeout = min($this->setting('connect_timeout', 10), $remaining);
            try {
                $this->requests++;
                $response = Http::withToken($this->token)->acceptJson()
                    ->withHeaders(['Prefer' => 'IdType="ImmutableId", outlook.body-content-type="'.($this->preferPlain ? 'text' : 'html').'"'])
                    ->connectTimeout($connectTimeout)
                    ->timeout($timeout)
                    ->withOptions([
                        'allow_redirects' => false,
                        'on_headers' => static function (ResponseInterface $response) use ($maximum): void {
                            $length = $response->getHeaderLine('Content-Length');
                            if (ctype_digit($length) && (int) $length > $maximum) {
                                throw new GraphException('Microsoft Graph response exceeds the configured byte limit.');
                            }
                        },
                        'progress' => static function ($downloadTotal, $downloaded) use ($maximum): void {
                            if ($downloadTotal > $maximum || $downloaded > $maximum) {
                                throw new GraphException('Microsoft Graph response exceeds the configured byte limit.');
                            }
                        },
                    ])->get($url);
            } catch (ConnectionException $exception) {
                if ($attempt === $retries) {
                    throw new GraphException('Microsoft Graph connection failed; retry the bounded batch.');
                }
                $this->pause(null, $attempt);

                continue;
            } catch (TransferException $exception) {
                throw new GraphException('Microsoft Graph transfer was aborted or exceeded the byte limit.');
            }
            $this->remaining();
            $bytes = strlen($response->body());
            $this->responseBytes += $bytes;
            if ($bytes > $maximum) {
                throw new GraphException('Microsoft Graph response exceeds the configured byte limit.');
            }
            if (in_array($response->status(), [429, 500, 502, 503, 504], true) && $attempt < $retries) {
                $this->pause($response, $attempt);

                continue;
            }
            if (! $response->successful()) {
                $this->fail($response);
            }
            try {
                $json = json_decode($response->body(), true, 64, JSON_THROW_ON_ERROR);
            } catch (\JsonException $exception) {
                throw new GraphException('Microsoft Graph returned malformed JSON.');
            }
            if (! is_array($json) || array_is_list($json)) {
                throw new GraphException('Microsoft Graph returned an invalid JSON object.');
            }

            return $json;
        }
        throw new GraphException('Microsoft Graph retry budget exhausted.');
    }

    /** @return \Generator<int,array<string,mixed>> */
    public function pages(string $path): \Generator
    {
        $next = $path;
        $seen = [];
        $maximum = $this->setting('max_pages', 1000);
        for ($page = 0; $page < $maximum; $page++) {
            $fingerprint = hash('sha256', $next);
            if (isset($seen[$fingerprint])) {
                throw new GraphException('Microsoft Graph returned a repeated continuation.');
            }
            $seen[$fingerprint] = true;
            $payload = $this->get($next);
            $this->collection($payload);
            $continuation = $payload['@odata.nextLink'] ?? null;
            if ($continuation !== null) {
                if (! is_string($continuation) || $continuation === '') {
                    throw new GraphException('Microsoft Graph returned an invalid continuation.');
                }
                $this->validateContinuation($continuation, $path);
            }
            $delta = $payload['@odata.deltaLink'] ?? null;
            if ($delta !== null) {
                if (! is_string($delta) || $delta === '') {
                    throw new GraphException('Microsoft Graph returned an invalid delta checkpoint.');
                }
                $this->validateContinuation($delta, $path);
            }
            yield $payload;
            if ($continuation === null) {
                return;
            }
            $next = $continuation;
        }
        throw new GraphException('Microsoft Graph pagination budget exhausted before completion.');
    }

    public function validateContinuation(string $url, string $expectedPath): void
    {
        $this->validateUrl($url);
        $expectedUrl = str_starts_with($expectedPath, '/') ? self::ORIGIN.$expectedPath : $expectedPath;
        $this->validateUrl($expectedUrl);
        $expected = $this->segments((string) parse_url($expectedUrl, PHP_URL_PATH));
        if ($this->segments((string) parse_url($url, PHP_URL_PATH)) !== $expected) {
            throw new GraphException('Microsoft Graph continuation is outside the requested collection.');
        }
    }

    /** @return array<string,mixed> */
    public function message(string $id): array
    {
        if ($id === '') {
            throw new GraphException('Microsoft Graph message identity is missing.');
        }
        $fields = 'id,subject,body,from,sender,toRecipients,ccRecipients,bccRecipients,receivedDateTime,sentDateTime,lastModifiedDateTime,internetMessageId,internetMessageHeaders,conversationId,parentFolderId,categories,importance,flag,isRead,hasAttachments';

        $message = $this->get($this->root().'/messages/'.rawurlencode($id).'?'.http_build_query(['$select' => $fields], '', '&', PHP_QUERY_RFC3986));
        if (($message['id'] ?? null) !== $id) {
            throw new GraphException('Microsoft Graph returned a message identity different from the requested immutable ID.');
        }

        return $message;
    }

    /** @param array<string,mixed> $settings
     * @return list<array<string,mixed>> */
    public function attachments(string $messageId, array $settings = []): array
    {
        $policy = $settings['attachments'] ?? $settings;
        $policy = array_replace((array) config('connector-microsoft-mail.defaults.attachments', []), $policy);
        $base = $this->root().'/messages/'.rawurlencode($messageId).'/attachments';
        $path = $base.'?'.http_build_query(['$select' => 'id,name,contentType,size,isInline'], '', '&', PHP_QUERY_RFC3986);
        $attachments = [];
        $maximum = $this->setting('max_attachment_items', 1000);
        $initialBytes = $this->responseBytes;
        $transportBudget = $this->setting('max_attachment_response_bytes', 100 * 1024 * 1024);
        foreach ($this->pages($path) as $page) {
            if ($this->responseBytes - $initialBytes > $transportBudget) {
                throw new GraphException('Microsoft Graph attachment response budget exhausted.');
            }
            foreach ($this->collection($page) as $attachment) {
                if (count($attachments) >= $maximum) {
                    throw new GraphException('Microsoft Graph attachment discovery budget exhausted.');
                }
                $attachments[] = $attachment;
            }
        }
        usort($attachments, static fn (array $a, array $b): int => strcmp((string) ($a['id'] ?? ''), (string) ($b['id'] ?? '')));
        $accepted = 0;
        $decodedBytes = 0;
        $maxBytes = max(0, (int) ($policy['max_bytes'] ?? $policy['max_file_bytes'] ?? ((float) ($policy['max_size_mb'] ?? 25) * 1024 * 1024)));
        $maxTotal = max(0, (int) ($policy['max_total_bytes'] ?? 50 * 1024 * 1024));
        $maxCount = max(0, (int) ($policy['max_count'] ?? $policy['max_per_email'] ?? 20));
        $extensions = array_map('strtolower', (array) ($policy['allowed_extensions'] ?? []));
        foreach ($attachments as &$attachment) {
            $type = $attachment['@odata.type'] ?? '';
            $id = $attachment['id'] ?? null;
            $size = (int) ($attachment['size'] ?? 0);
            $extension = strtolower(pathinfo((string) ($attachment['name'] ?? ''), PATHINFO_EXTENSION));
            $reason = null;
            if (! ($policy['enabled'] ?? true)) {
                $reason = 'disabled';
            } elseif (($policy['skip_inline'] ?? true) && ($attachment['isInline'] ?? false)) {
                $reason = 'inline';
            } elseif ($type === '#microsoft.graph.referenceAttachment') {
                $reason = 'reference_attachment';
            } elseif (! in_array($type, ['#microsoft.graph.fileAttachment', '#microsoft.graph.itemAttachment'], true)) {
                $reason = 'unsupported_attachment_type';
            } elseif ($size < 0 || $size > $maxBytes) {
                $reason = 'file_byte_limit';
            } elseif ($type === '#microsoft.graph.fileAttachment' && ! in_array($extension, $extensions, true)) {
                $reason = 'extension_not_allowed';
            } elseif ($type === '#microsoft.graph.itemAttachment' && ! in_array('md', $extensions, true)) {
                $reason = 'extension_not_allowed';
            } elseif ($accepted >= $maxCount) {
                $reason = 'count_limit';
            } elseif ($decodedBytes + $size > $maxTotal) {
                $reason = 'message_byte_budget';
            }
            if ($reason !== null) {
                $attachment['__excluded_reason'] = $reason;
                unset($attachment['contentBytes'], $attachment['bytes'], $attachment['item']);

                continue;
            }
            if (is_string($id) && $id !== '' && (
                ($type === '#microsoft.graph.fileAttachment' && ! array_key_exists('contentBytes', $attachment)) ||
                ($type === '#microsoft.graph.itemAttachment' && ! isset($attachment['item']))
            )) {
                $detailPath = $base.'/'.rawurlencode($id);
                if ($type === '#microsoft.graph.itemAttachment') {
                    $detailPath .= '?'.http_build_query(['$expand' => 'microsoft.graph.itemAttachment/item'], '', '&', PHP_QUERY_RFC3986);
                }
                $detail = $this->get($detailPath);
                if (isset($detail['id']) && $detail['id'] !== $id) {
                    throw new GraphException('Microsoft Graph returned an attachment identity different from the requested ID.');
                }
                $attachment = array_replace($attachment, $detail);
            }
            if ($this->responseBytes - $initialBytes > $transportBudget) {
                throw new GraphException('Microsoft Graph attachment response budget exhausted.');
            }
            if ($type === '#microsoft.graph.fileAttachment' && ! is_string($attachment['contentBytes'] ?? null) && ! is_string($attachment['bytes'] ?? null)) {
                throw new GraphException('Microsoft Graph returned no bytes for an accepted file attachment.');
            }
            if ($type === '#microsoft.graph.itemAttachment' && ! is_array($attachment['item'] ?? null)) {
                throw new GraphException('Microsoft Graph returned no content for an accepted Outlook item attachment.');
            }
            $actualBytes = $this->attachmentBytes($attachment);
            if ($actualBytes > $maxBytes || $decodedBytes + $actualBytes > $maxTotal) {
                $attachment['__excluded_reason'] = $actualBytes > $maxBytes ? 'file_byte_limit' : 'message_byte_budget';
                unset($attachment['contentBytes'], $attachment['bytes'], $attachment['item']);

                continue;
            }
            $decodedBytes += $actualBytes;
            $accepted++;
        }
        unset($attachment);

        return $attachments;
    }

    /** @param array<string,mixed> $attachment */
    private function attachmentBytes(array $attachment): int
    {
        if (is_string($attachment['bytes'] ?? null)) {
            return strlen($attachment['bytes']);
        }
        if (is_string($attachment['contentBytes'] ?? null)) {
            $value = $attachment['contentBytes'];

            return (int) floor(strlen($value) * 3 / 4) - (str_ends_with($value, '==') ? 2 : (str_ends_with($value, '=') ? 1 : 0));
        }
        if (is_array($attachment['item'] ?? null)) {
            return strlen(json_encode($attachment['item'], JSON_THROW_ON_ERROR));
        }

        return 0;
    }

    /** @return array<string,array{id:string,name:string,path:string,hidden:bool,default_selected:bool,well_known:?string}> */
    public function folders(bool $includeHidden = false): array
    {
        $known = [];
        foreach (['deleteditems', 'junkemail'] as $name) {
            $folder = $this->get($this->root().'/mailFolders/'.$name.'?$select=id');
            if (! is_string($folder['id'] ?? null) || $folder['id'] === '') {
                throw new GraphException('Microsoft Graph returned no identity for a well-known folder.');
            }
            $known[$folder['id']] = $name;
        }
        $query = '?'.http_build_query(['includeHiddenFolders' => $includeHidden ? 'true' : 'false', '$select' => 'id,displayName,childFolderCount,isHidden'], '', '&', PHP_QUERY_RFC3986);
        $stack = [[$this->root().'/mailFolders'.$query, '', 0, false]];
        $folders = [];
        $maximum = $this->setting('max_folders', 10000);
        $maxDepth = $this->setting('max_folder_depth', 100);
        while ($stack !== []) {
            [$path, $parentName, $depth, $parentExcluded] = array_pop($stack);
            if ($depth > $maxDepth) {
                throw new GraphException('Microsoft Graph folder depth budget exhausted.');
            }
            foreach ($this->pages($path) as $page) {
                foreach ($this->collection($page) as $folder) {
                    $id = $folder['id'] ?? null;
                    $name = $folder['displayName'] ?? null;
                    if (! is_string($id) || $id === '' || ! is_string($name)) {
                        throw new GraphException('Microsoft Graph returned a folder without identity or name.');
                    }
                    if (isset($folders[$id])) {
                        throw new GraphException('Microsoft Graph returned a cyclic or duplicate folder identity.');
                    }
                    if (count($folders) >= $maximum) {
                        throw new GraphException('Microsoft Graph folder discovery budget exhausted.');
                    }
                    $hidden = ($folder['isHidden'] ?? false) === true;
                    if ($hidden && ! $includeHidden) {
                        continue;
                    }
                    $displayPath = $parentName === '' ? $name : $parentName.'/'.$name;
                    $excluded = $parentExcluded || isset($known[$id]);
                    $folders[$id] = ['id' => $id, 'name' => $name, 'path' => $displayPath, 'hidden' => $hidden, 'default_selected' => ! $excluded, 'well_known' => $known[$id] ?? null];
                    if ((int) ($folder['childFolderCount'] ?? 0) > 0) {
                        $stack[] = [$this->root().'/mailFolders/'.rawurlencode($id).'/childFolders'.$query, $displayPath, $depth + 1, $excluded];
                    }
                }
            }
        }

        return $folders;
    }

    /** @param array<string,mixed> $payload
     * @return list<array<string,mixed>> */
    private function collection(array $payload): array
    {
        if (! isset($payload['value']) || ! is_array($payload['value']) || ! array_is_list($payload['value'])) {
            throw new GraphException('Microsoft Graph returned an invalid collection.');
        }
        foreach ($payload['value'] as $item) {
            if (! is_array($item)) {
                throw new GraphException('Microsoft Graph returned an invalid collection item.');
            }
        }

        return $payload['value'];
    }

    private function validateUrl(string $url): void
    {
        $parts = parse_url($url);
        if ($parts === false || ($parts['scheme'] ?? '') !== 'https' || ($parts['host'] ?? '') !== 'graph.microsoft.com' || isset($parts['user'], $parts['pass']) || isset($parts['user']) || isset($parts['port']) || isset($parts['fragment']) || preg_match('/[\x00-\x20\\\\]/', $url)) {
            throw new GraphException('Microsoft Graph URL is outside the trusted HTTPS origin.');
        }
        $segments = $this->segments($parts['path'] ?? '');
        $root = $this->segments('/v1.0'.$this->root());
        if (array_slice($segments, 0, count($root)) !== $root || count($segments) < count($root)) {
            throw new GraphException('Microsoft Graph URL is outside the configured mailbox.');
        }
        $remaining = array_slice($segments, count($root));
        if ($remaining !== [] && ! in_array($remaining[0], ['mailfolders', 'messages'], true)) {
            throw new GraphException('Microsoft Graph URL is outside the read-only mail resources.');
        }
    }

    /** @return list<string> */
    private function segments(string $path): array
    {
        $path = preg_replace_callback("~/(users|mailFolders|messages|attachments)\\('((?:[^']|'')*)'\\)~i", static fn (array $match): string => '/'.$match[1].'/'.rawurlencode(str_replace("''", "'", rawurldecode($match[2]))), $path);
        $segments = explode('/', trim((string) $path, '/'));
        $resources = ['v1.0', 'me', 'users', 'mailfolders', 'messages', 'attachments', 'childfolders', 'delta'];
        foreach ($segments as $index => $segment) {
            $decoded = rawurldecode($segment);
            if ($decoded === '.' || $decoded === '..' || $decoded === '' || str_contains($decoded, '\\') || preg_match('/[\x00-\x1f]/', $decoded)) {
                throw new GraphException('Microsoft Graph URL contains an unsafe path.');
            }
            // Only resource positions are case-insensitive; native IDs remain case-sensitive.
            $isIdentity = $index > 0 && in_array(strtolower(rawurldecode($segments[$index - 1])), ['users', 'mailfolders', 'messages', 'attachments'], true);
            $segments[$index] = ! $isIdentity && in_array(strtolower($decoded), $resources, true) ? strtolower($decoded) : $decoded;
        }

        return $segments;
    }

    private function fail(Response $response): never
    {
        $error = $response->json('error.code');
        $code = is_string($error) ? $error : '';
        if ($response->status() === 401) {
            throw new ConnectorAuthException('Microsoft Graph authentication failed; reconnect Microsoft Mail.');
        }
        if ($response->status() === 403) {
            throw new GraphPermissionException('Microsoft Graph denied access to the configured mailbox; verify delegated permissions and mailbox access.');
        }
        if ($response->status() === 410 || in_array(strtolower($code), ['syncstatenotfound', 'invaliddeltatoken', 'resyncrequired', 'errorinvalidsyncstatedata'], true)) {
            throw new InvalidDeltaCursorException('Microsoft Graph delta cursor expired; rebuild only the affected folder baseline.', $response->status(), $code);
        }
        if ($response->status() === 404) {
            throw new GraphResourceNotFoundException('Microsoft Graph resource was not found.', 404, $code);
        }
        throw new GraphException('Microsoft Graph request failed (HTTP '.$response->status().').', $response->status(), $code);
    }

    private function pause(?Response $response, int $attempt): void
    {
        $maximum = max(0, (int) config('connector-microsoft-mail.http.max_retry_after', 5));
        $retryAfter = $response?->header('Retry-After');
        if (is_string($retryAfter) && $retryAfter !== '') {
            $seconds = ctype_digit($retryAfter) ? (int) $retryAfter : max(0, (strtotime($retryAfter) ?: time()) - time());
            if ($seconds > $maximum) {
                throw new GraphException('Microsoft Graph Retry-After exceeds the worker wait budget; retry the batch later.', $response->status());
            }
            $milliseconds = $seconds * 1000;
        } else {
            $milliseconds = min($maximum * 1000, 100 * (2 ** $attempt) + random_int(0, 100));
        }
        if ($milliseconds / 1000 >= $this->remaining()) {
            throw new GraphException('Microsoft Graph retry delay exceeds the remaining time budget; resume the batch later.');
        }
        if ($milliseconds > 0) {
            usleep($milliseconds * 1000);
        }
    }

    private function remaining(): float
    {
        if ($this->deadline === null) {
            return PHP_FLOAT_MAX;
        }
        $remaining = $this->deadline - microtime(true);
        if ($remaining <= 0) {
            throw new GraphException('Microsoft Graph time budget exhausted; resume the batch later.');
        }

        return $remaining;
    }

    private function setting(string $key, int $default): int
    {
        return max(1, (int) config('connector-microsoft-mail.http.'.$key, $default));
    }
}
