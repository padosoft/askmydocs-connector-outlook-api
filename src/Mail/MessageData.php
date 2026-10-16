<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorMicrosoftMail\Mail;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/** Normalizes Graph values without replacing missing evidence with invented text. */
final class MessageData
{
    /** @param array<string, mixed> $message @return array<string, string> */
    public static function headers(array $message): array
    {
        $headers = [];
        foreach ((array) ($message['internetMessageHeaders'] ?? []) as $key => $value) {
            if (is_array($value)) {
                $name = (string) ($value['name'] ?? '');
                $content = (string) ($value['value'] ?? '');
            } else {
                $name = (string) $key;
                $content = (string) $value;
            }
            if ($name !== '') {
                $normalized = strtolower($name);
                $headers[$normalized] = isset($headers[$normalized]) ? $headers[$normalized].' '.$content : $content;
            }
        }

        return $headers;
    }

    /** @param mixed $value @return array{name: string, email: string} */
    public static function address(mixed $value): array
    {
        $value = is_array($value) ? $value : [];
        $address = is_array($value['emailAddress'] ?? null) ? $value['emailAddress'] : $value;

        return [
            'name' => self::decodeHeader((string) ($address['name'] ?? '')),
            'email' => (string) ($address['address'] ?? $address['email'] ?? ''),
        ];
    }

    /** @param mixed $values @return list<array{name: string, email: string}> */
    public static function addresses(mixed $values): array
    {
        return array_values(array_map(self::address(...), is_array($values) ? $values : []));
    }

    public static function decodeHeader(string $value): string
    {
        return mb_check_encoding($value, 'UTF-8') ? mb_decode_mimeheader($value) : mb_convert_encoding($value, 'UTF-8', 'ISO-8859-1');
    }

    public static function date(mixed $value): ?string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format(DateTimeInterface::ATOM);
        }
        if (! is_string($value) || trim($value) === '') {
            return null;
        }
        try {
            return (new DateTimeImmutable($value, new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM);
        } catch (\Exception) {
            return null;
        }
    }
}
