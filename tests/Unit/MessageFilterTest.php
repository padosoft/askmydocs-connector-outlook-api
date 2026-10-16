<?php

declare(strict_types=1);

namespace Tests\Unit;

use DateTimeImmutable;
use Padosoft\AskMyDocsConnectorMicrosoftMail\Mail\MessageFilter;
use PHPUnit\Framework\TestCase;

final class MessageFilterTest extends TestCase
{
    /** @return array<string, mixed> */
    private function message(): array
    {
        return [
            'internetMessageHeaders' => [],
            'subject' => 'Project invoice report',
            'from' => ['emailAddress' => ['address' => 'alice@EXAMPLE.test']],
            'toRecipients' => [['emailAddress' => ['address' => 'bob@receiver.test']]],
            'bccRecipients' => [['emailAddress' => ['address' => 'private@hidden.test']]],
            'receivedDateTime' => '2026-10-10T12:00:00Z',
            'isRead' => false,
            'flag' => ['flagStatus' => 'flagged'],
        ];
    }

    public function test_address_domain_and_subject_filter_exclude_wins(): void
    {
        $filter = new MessageFilter;
        self::assertTrue($filter->accepts($this->message(), [
            'senders' => ['include' => ['example.test']], 'recipients' => ['include' => ['hidden.test']],
            'subject' => ['include_keywords' => ['INVOICE']],
        ]));
        self::assertFalse($filter->accepts($this->message(), ['senders' => ['include' => ['example.test'], 'exclude' => ['ALICE@example.test']]]));
        self::assertFalse($filter->accepts($this->message(), ['subject' => ['include_keywords' => ['invoice'], 'exclude_keywords' => ['REPORT']]]));
        self::assertFalse($filter->accepts($this->message(), ['recipients' => ['exclude' => ['receiver.test']]]));
    }

    public function test_active_filters_fail_closed_when_required_data_is_missing(): void
    {
        $filter = new MessageFilter;
        foreach ([
            ['senders' => ['exclude' => ['example.test']]],
            ['recipients' => ['exclude' => ['example.test']]],
            ['subject' => ['exclude_keywords' => ['spam']]],
            ['only_unread' => true], ['only_flagged' => true], ['date_window_days' => 10],
        ] as $settings) {
            self::assertFalse($filter->accepts(['internetMessageHeaders' => []], $settings));
        }
        self::assertFalse($filter->accepts([], []));
        self::assertTrue($filter->accepts([], ['skip_auto_generated' => false]));
    }

    public function test_unread_flagged_and_auto_generated_headers(): void
    {
        $filter = new MessageFilter;
        self::assertTrue($filter->accepts($this->message(), ['only_unread' => true, 'only_flagged' => true]));
        $read = array_replace($this->message(), ['isRead' => true]);
        self::assertFalse($filter->accepts($read, ['only_unread' => true]));
        foreach ([['name' => 'Precedence', 'value' => 'bulk'], ['name' => 'Auto-Submitted', 'value' => 'auto-replied'], ['name' => 'List-Unsubscribe', 'value' => '<https://example.test>']] as $header) {
            self::assertFalse($filter->accepts(array_replace($this->message(), ['internetMessageHeaders' => [$header]]), []));
        }
    }

    public function test_windows_have_explicit_inclusive_boundaries(): void
    {
        $filter = new MessageFilter(new DateTimeImmutable('2026-10-14T12:00:00Z'));
        self::assertTrue($filter->accepts($this->message(), ['date_window_days' => 4]));
        self::assertFalse($filter->accepts($this->message(), ['date_window_days' => 3]));
        self::assertTrue($filter->accepts($this->message(), ['since' => '2026-10-10T12:00:00Z', 'until' => '2026-10-10T12:00:00Z']));
        self::assertFalse($filter->accepts($this->message(), ['since' => '2026-10-11T00:00:00Z']));
        self::assertFalse($filter->accepts($this->message(), ['until' => 'invalid']));
    }
}
