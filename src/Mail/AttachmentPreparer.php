<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorMicrosoftMail\Mail;

use Closure;
use finfo;
use UnexpectedValueException;

/** Prepares every observed attachment before any host persistence or dispatch. */
final class AttachmentPreparer
{
    private const DEFAULT_EXTENSIONS = ['pdf', 'doc', 'docx', 'ppt', 'pptx', 'xls', 'xlsx', 'txt', 'csv', 'md', 'rtf', 'odt'];

    public function __construct(private readonly EmailRenderer $renderer = new EmailRenderer, private readonly ?Closure $redactor = null) {}

    /**
     * @param  list<array<string, mixed>>  $attachments
     * @param  array<string, mixed>  $settings
     * @return array{manifest: list<array{slot: int, id: string, name: string, mime: string, hash: ?string, status: string, reason: ?string}>, files: list<array{bytes: string, name: string, mime: string, id: string, slot: int}>}
     */
    public function prepare(array $attachments, array $settings = []): array
    {
        $config = array_merge($settings, (array) ($settings['attachments'] ?? []));
        $maxFileBytes = max(0, (int) ($config['max_bytes'] ?? $config['max_file_bytes'] ?? ((int) ($config['max_size_mb'] ?? 25) * 1024 * 1024)));
        $maxTotalBytes = max(0, (int) ($config['max_total_bytes'] ?? (50 * 1024 * 1024)));
        $maxCount = max(0, (int) ($config['max_count'] ?? $config['max_per_email'] ?? 20));
        $maxDepth = max(0, (int) ($config['max_depth'] ?? 3));
        $allowed = array_map(static fn (mixed $value): string => strtolower(ltrim((string) $value, '.')), (array) ($config['allowed_extensions'] ?? self::DEFAULT_EXTENSIONS));
        $flat = $this->flatten($attachments, $maxDepth, max(1, (int) ($config['max_observed'] ?? 1000)));
        usort($flat, static fn (array $a, array $b): int => strcmp($a['_identity'], $b['_identity']));
        $manifest = $files = [];
        $totalBytes = 0;
        $seen = [];
        foreach ($flat as $attachment) {
            $id = $attachment['_identity'];
            if (isset($seen[$id])) {
                if ($seen[$id] !== $attachment) {
                    throw new UnexpectedValueException('Graph returned conflicting duplicate attachment identities.');
                }

                continue;
            }
            $seen[$id] = $attachment;
            $slot = count($manifest);
            $type = (string) ($attachment['@odata.type'] ?? '#microsoft.graph.fileAttachment');
            $isItem = $type === '#microsoft.graph.itemAttachment';
            $name = $this->filename((string) ($attachment['name'] ?? 'attachment'));
            if ($isItem) {
                $name = $this->filename(pathinfo($name, PATHINFO_FILENAME).'.md');
            }
            $mime = $isItem ? 'text/markdown' : strtolower(trim((string) ($attachment['contentType'] ?? 'application/octet-stream')));
            $mime = explode(';', $mime)[0];
            $reason = null;
            $bytes = null;
            if (($config['enabled'] ?? true) !== true) {
                $reason = 'attachments_disabled';
            } elseif (is_string($attachment['__excluded_reason'] ?? null) && $attachment['__excluded_reason'] !== '') {
                $reason = $attachment['__excluded_reason'];
            } elseif ($type === '#microsoft.graph.referenceAttachment') {
                $reason = 'reference_attachment_not_downloaded';
            } elseif (! in_array($type, ['#microsoft.graph.fileAttachment', '#microsoft.graph.itemAttachment'], true)) {
                $reason = 'unsupported_attachment_type';
            } elseif (($attachment['_depth_exceeded'] ?? false) === true) {
                $reason = 'attachment_depth_limit';
            } elseif (($config['skip_inline'] ?? true) === true && ($attachment['isInline'] ?? false) === true) {
                $reason = 'inline_attachment';
            } elseif (count($files) >= $maxCount) {
                $reason = 'attachment_count_limit';
            } elseif (! in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), $allowed, true)) {
                $reason = 'extension_not_allowed';
            } elseif ((int) ($attachment['size'] ?? 0) > $maxFileBytes) {
                $reason = 'attachment_size_limit';
            } else {
                if ($isItem) {
                    $item = $attachment['item'] ?? null;
                    if (! is_array($item) || ($item['@odata.type'] ?? '#microsoft.graph.message') !== '#microsoft.graph.message') {
                        $reason = 'unsupported_nested_item';
                    } else {
                        $bytes = $this->renderer->render($item, $settings);
                        if ($this->redactor !== null) {
                            $bytes = ($this->redactor)($bytes);
                        }
                    }
                } else {
                    if (isset($attachment['bytes']) && is_string($attachment['bytes'])) {
                        $bytes = $attachment['bytes'];
                    } elseif (isset($attachment['contentBytes']) && is_string($attachment['contentBytes'])) {
                        $encoded = $attachment['contentBytes'];
                        if (intdiv(strlen($encoded) + 3, 4) * 3 > $maxFileBytes + 2) {
                            $reason = 'attachment_size_limit';
                        } else {
                            $decoded = base64_decode($encoded, true);
                            if ($decoded === false) {
                                throw new UnexpectedValueException('Graph attachment contains invalid base64 content.');
                            }
                            $bytes = $decoded;
                        }
                    } else {
                        throw new UnexpectedValueException('Graph file attachment content was not acquired.');
                    }
                }
                if ($bytes !== null) {
                    if ($bytes === '') {
                        $reason = 'empty_attachment';
                    } elseif (strlen($bytes) > $maxFileBytes) {
                        $reason = 'attachment_size_limit';
                    } elseif ($totalBytes + strlen($bytes) > $maxTotalBytes) {
                        $reason = 'message_attachment_byte_limit';
                    } elseif (! $isItem) {
                        $detected = (new finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
                        $detected = is_string($detected) ? $detected : 'application/octet-stream';
                        if ($this->mismatch($mime, $detected)) {
                            $reason = 'mime_mismatch';
                        } elseif ($mime === 'application/octet-stream' && $detected !== 'application/octet-stream') {
                            $mime = $detected;
                        }
                    }
                }
            }
            $manifest[] = [
                'slot' => $slot, 'id' => $id, 'name' => $name, 'mime' => $mime,
                'hash' => $reason === null && $bytes !== null ? hash('sha256', $bytes) : null,
                'status' => $reason === null ? 'pending' : 'excluded', 'reason' => $reason,
            ];
            if ($reason === null && $bytes !== null) {
                $totalBytes += strlen($bytes);
                $files[] = ['bytes' => $bytes, 'name' => $name, 'mime' => $mime, 'id' => $id, 'slot' => $slot];
            }
        }

        return ['manifest' => $manifest, 'files' => $files];
    }

    /** @param list<array<string, mixed>> $attachments @return list<array<string, mixed>> */
    private function flatten(array $attachments, int $maxDepth, int $maxObserved): array
    {
        $flat = [];
        $stack = [];
        foreach ($attachments as $index => $attachment) {
            $stack[] = [$attachment, '', 0, 'part/'.$index];
        }
        while ($stack !== []) {
            if (count($flat) >= $maxObserved) {
                throw new UnexpectedValueException('Observed attachment coverage exceeds the configured safety bound.');
            }
            [$attachment, $prefix, $depth, $partPath] = array_pop($stack);
            $id = (string) ($attachment['id'] ?? '');
            if ($id === '') {
                $id = 'synthetic:'.hash('sha256', (string) ($attachment['part_path'] ?? $partPath));
            }
            $identity = $prefix === '' ? $id : $prefix.'/'.rawurlencode($id);
            $attachment['_identity'] = $identity;
            $attachment['_depth_exceeded'] = $depth > $maxDepth;
            $flat[] = $attachment;
            if (($attachment['@odata.type'] ?? null) === '#microsoft.graph.itemAttachment' && is_array($attachment['item']['attachments'] ?? null)) {
                foreach ($attachment['item']['attachments'] as $index => $child) {
                    if (! is_array($child)) {
                        throw new UnexpectedValueException('Nested Graph attachment is malformed.');
                    }
                    $stack[] = [$child, $identity, $depth + 1, 'part/'.$index];
                }
            }
        }

        return $flat;
    }

    private function mismatch(string $declared, string $detected): bool
    {
        if ($declared === $detected || $declared === 'application/octet-stream') {
            return false;
        }
        if (str_starts_with($declared, 'text/') && str_starts_with($detected, 'text/')) {
            return false;
        }
        if (in_array($detected, ['application/zip', 'application/x-zip', 'application/x-zip-compressed'], true)
            && (str_contains($declared, 'officedocument') || str_contains($declared, 'opendocument'))) {
            return false;
        }
        if ($detected === 'application/x-ole-storage' && in_array($declared, ['application/msword', 'application/vnd.ms-excel', 'application/vnd.ms-powerpoint'], true)) {
            return false;
        }

        return true;
    }

    private function filename(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[^\p{L}\p{N} ._-]/u', '_', mb_convert_encoding($name, 'UTF-8', 'UTF-8')) ?? 'attachment';
        $name = trim($name, " .\t\n\r\0\x0B");
        if ($name === '' || preg_match('/^(CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9])(?:\.|$)/i', $name)) {
            $name = 'file-'.$name;
        }

        return mb_strcut($name, 0, 180, 'UTF-8');
    }
}
