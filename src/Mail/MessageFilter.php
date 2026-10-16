<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorMicrosoftMail\Mail;

use Carbon\CarbonImmutable;
use DateTimeImmutable;

/** Adapted from the Apache-2.0 AskMyDocs IMAP filter, with missing-data rejection. */
final class MessageFilter
{
    public function __construct(private readonly ?DateTimeImmutable $now = null) {}

    /** @param array<string, mixed> $message @param array<string, mixed> $settings */
    public function accepts(array $message, array $settings): bool
    {
        $config = array_merge($settings, (array) ($settings['filters'] ?? []));
        if (($config['only_unseen'] ?? $config['only_unread'] ?? false) === true && (! array_key_exists('isRead', $message) || $message['isRead'] !== false)) {
            return false;
        }
        if (($config['only_flagged'] ?? false) === true && ($message['flag']['flagStatus'] ?? null) !== 'flagged') {
            return false;
        }
        if (($config['skip_auto_generated'] ?? true) === true) {
            if (! array_key_exists('internetMessageHeaders', $message)) {
                return false;
            }
            $headers = MessageData::headers($message);
            if (in_array(strtolower(trim($headers['precedence'] ?? '')), ['bulk', 'list', 'junk'], true)
                || (isset($headers['auto-submitted']) && strtolower(trim($headers['auto-submitted'])) !== 'no')
                || isset($headers['list-unsubscribe'])) {
                return false;
            }
        }
        $sender = MessageData::address($message['from'] ?? $message['sender'] ?? null)['email'];
        if (! $this->addressesPass([$sender], (array) ($config['senders'] ?? []))) {
            return false;
        }
        $recipients = array_column(array_merge(
            MessageData::addresses($message['toRecipients'] ?? []),
            MessageData::addresses($message['ccRecipients'] ?? []),
            MessageData::addresses($message['bccRecipients'] ?? []),
        ), 'email');
        if (! $this->addressesPass($recipients, (array) ($config['recipients'] ?? []))) {
            return false;
        }
        $subjectConfig = (array) ($config['subject'] ?? []);
        $include = $this->needles($subjectConfig['include_keywords'] ?? []);
        $exclude = $this->needles($subjectConfig['exclude_keywords'] ?? []);
        if (($include !== [] || $exclude !== []) && (! isset($message['subject']) || trim((string) $message['subject']) === '')) {
            return false;
        }
        $subject = mb_strtolower(MessageData::decodeHeader((string) ($message['subject'] ?? '')));
        if ($this->containsAny($subject, $exclude) || ($include !== [] && ! $this->containsAny($subject, $include))) {
            return false;
        }

        return $this->datePasses($message, $config);
    }

    /** @param list<string> $addresses @param array<string, mixed> $config */
    private function addressesPass(array $addresses, array $config): bool
    {
        $include = $this->needles($config['include'] ?? []);
        $exclude = $this->needles($config['exclude'] ?? []);
        if ($include === [] && $exclude === []) {
            return true;
        }
        $addresses = array_values(array_filter(array_map('strtolower', $addresses), static fn (string $address): bool => $address !== ''));
        if ($addresses === []) {
            return false;
        }
        foreach ($addresses as $address) {
            foreach ($exclude as $needle) {
                if ($this->matchesAddress($address, $needle)) {
                    return false;
                }
            }
        }
        if ($include === []) {
            return true;
        }
        foreach ($addresses as $address) {
            foreach ($include as $needle) {
                if ($this->matchesAddress($address, $needle)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function matchesAddress(string $address, string $needle): bool
    {
        return $address === $needle || str_ends_with($address, '@'.ltrim($needle, '@'));
    }

    /** @param mixed $values @return list<string> */
    private function needles(mixed $values): array
    {
        return array_values(array_filter(array_map(static fn (mixed $value): string => mb_strtolower(trim((string) $value)), (array) $values), static fn (string $value): bool => $value !== ''));
    }

    /** @param list<string> $needles */
    private function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $message @param array<string, mixed> $config */
    private function datePasses(array $message, array $config): bool
    {
        $window = max(0, (int) ($config['date_window_days'] ?? $config['sync']['date_window_days'] ?? 0));
        $since = $config['since'] ?? $config['date_from'] ?? null;
        $until = $config['until'] ?? $config['date_to'] ?? null;
        if ($window === 0 && $since === null && $until === null) {
            return true;
        }
        $date = MessageData::date($message['receivedDateTime'] ?? null);
        if ($date === null) {
            return false;
        }
        $received = new DateTimeImmutable($date);
        $now = $this->now ?? CarbonImmutable::now('UTC');
        if ($window > 0 && $received < $now->modify('-'.$window.' days')) {
            return false;
        }
        foreach ([[$since, true], [$until, false]] as [$boundary, $isStart]) {
            if ($boundary !== null) {
                $parsed = MessageData::date($boundary);
                if ($parsed === null || ($isStart ? $received < new DateTimeImmutable($parsed) : $received > new DateTimeImmutable($parsed))) {
                    return false;
                }
            }
        }

        return true;
    }
}
