<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorMicrosoftMail\Mail;

use DOMDocument;
use DOMElement;
use DOMXPath;
use League\HTMLToMarkdown\HtmlConverter;
use UnexpectedValueException;

/** Readable full-body rendering, adapted from the Apache-2.0 IMAP renderer. */
final class EmailRenderer
{
    /** @param array<string, mixed> $message @param array<string, mixed> $settings */
    public function render(array $message, array $settings = []): string
    {
        $config = array_merge($settings, (array) ($settings['body'] ?? []));
        $stripQuoted = (bool) ($config['strip_quoted_history'] ?? $config['strip_quoted'] ?? $config['strip_quoted_text'] ?? false);
        $body = $this->body($message, (string) ($config['format'] ?? $config['body_format'] ?? 'prefer_text'), $stripQuoted);
        if ($stripQuoted) {
            $body = implode("\n", array_filter(preg_split('/\r\n|\r|\n/', $body) ?: [], static fn (string $line): bool => ! str_starts_with(ltrim($line), '>')));
        }
        $subject = MessageData::decodeHeader((string) ($message['subject'] ?? ''));
        $headers = MessageData::headers($message);
        $fields = [
            'From' => $this->address(MessageData::address($message['from'] ?? $message['sender'] ?? null)),
            'To' => $this->addresses(MessageData::addresses($message['toRecipients'] ?? [])),
            'Cc' => $this->addresses(MessageData::addresses($message['ccRecipients'] ?? [])),
            'Bcc' => $this->addresses(MessageData::addresses($message['bccRecipients'] ?? [])),
            'Date' => MessageData::date($message['receivedDateTime'] ?? $message['sentDateTime'] ?? null) ?? '',
            'Message-ID' => (string) ($message['internetMessageId'] ?? $headers['message-id'] ?? ''),
            'In-Reply-To' => $headers['in-reply-to'] ?? '',
            'References' => $headers['references'] ?? '',
        ];
        $lines = ['# '.$this->cell($subject !== '' ? $subject : '(no subject)'), '', '| Field | Value |', '|------|--------|'];
        foreach ($fields as $label => $value) {
            if ($value !== '' || in_array($label, ['From', 'To', 'Date'], true)) {
                $lines[] = '| '.$label.' | '.$this->cell($value).' |';
            }
        }
        $lines[] = '';
        $lines[] = '---';
        $lines[] = '';
        $lines[] = trim($body);

        return implode("\n", $lines)."\n";
    }

    /** @param array<string, mixed> $message */
    private function body(array $message, string $format, bool $stripQuoted): string
    {
        $body = is_array($message['body'] ?? null) ? $message['body'] : [];
        if (! array_key_exists('content', $body) && ! array_key_exists('textBody', $message) && ! array_key_exists('htmlBody', $message)) {
            throw new UnexpectedValueException('Graph full message body was not acquired.');
        }
        $text = (string) ($message['textBody'] ?? (strtolower((string) ($body['contentType'] ?? '')) === 'text' ? ($body['content'] ?? '') : ''));
        $html = (string) ($message['htmlBody'] ?? (strtolower((string) ($body['contentType'] ?? '')) === 'html' ? ($body['content'] ?? '') : ''));
        $charset = (string) ($body['charset'] ?? $message['charset'] ?? 'UTF-8');
        $text = $this->utf8($text, $charset);
        $html = $this->utf8($html, $charset);
        if (! in_array($format, ['html', 'prefer_html'], true) && $text !== '') {
            return str_replace(["\r\n", "\r"], "\n", $text);
        }
        if ($html !== '') {
            return $this->html($html, $stripQuoted);
        }

        return $text;
    }

    private function utf8(string $text, string $charset): string
    {
        if ($text === '' || (strcasecmp($charset, 'UTF-8') === 0 && mb_check_encoding($text, 'UTF-8'))) {
            return $text;
        }
        try {
            return mb_convert_encoding($text, 'UTF-8', $charset);
        } catch (\ValueError) {
            return mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        }
    }

    private function html(string $html, bool $stripQuoted): string
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            $document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
            $xpath = new DOMXPath($document);
            $query = '//script|//style|//head|//iframe|//object|//embed|//form|//input|//button|//meta|//link';
            if ($stripQuoted) {
                $query .= '|//blockquote|//*[contains(concat(" ",normalize-space(@class)," ")," gmail_quote ")]|//*[@id="divRplyFwdMsg"]';
            }
            foreach ($xpath->query($query) ?: [] as $node) {
                $node->parentNode?->removeChild($node);
            }
            foreach ($xpath->query('//*') ?: [] as $node) {
                if (! $node instanceof DOMElement) {
                    continue;
                }
                foreach (iterator_to_array($node->attributes) as $attribute) {
                    if (str_starts_with(strtolower($attribute->name), 'on') || in_array(strtolower($attribute->name), ['style', 'src', 'srcset', 'background'], true)) {
                        $node->removeAttribute($attribute->name);
                    }
                }
                if ($node->hasAttribute('href') && ! preg_match('/^(https?:\/\/|mailto:|#)/i', trim($node->getAttribute('href')))) {
                    $node->removeAttribute('href');
                }
            }
            $sanitized = $document->saveHTML() ?: '';
            $converter = new HtmlConverter(['strip_tags' => true, 'remove_nodes' => 'script style head img', 'hard_break' => true, 'use_autolinks' => false]);

            return trim($converter->convert($sanitized));
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private function cell(string $value): string
    {
        return str_replace(["\r", "\n", '|', '<', '>'], [' ', ' ', '\\|', '&lt;', '&gt;'], $value);
    }

    /** @param array{name: string, email: string} $address */
    private function address(array $address): string
    {
        return $address['name'] !== '' ? $address['name'].' <'.$address['email'].'>' : $address['email'];
    }

    /** @param list<array{name: string, email: string}> $addresses */
    private function addresses(array $addresses): string
    {
        return implode(', ', array_map($this->address(...), $addresses));
    }
}
