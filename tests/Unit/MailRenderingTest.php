<?php

declare(strict_types=1);

namespace Tests\Unit;

use Padosoft\AskMyDocsConnectorMicrosoftMail\Mail\EmailRenderer;
use Padosoft\AskMyDocsConnectorMicrosoftMail\Mail\MessageData;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

final class MailRenderingTest extends TestCase
{
    public function test_full_html_is_sanitized_and_never_uses_preview(): void
    {
        $rendered = (new EmailRenderer)->render([
            'subject' => 'A | report',
            'bodyPreview' => 'only a short preview',
            'from' => ['emailAddress' => ['name' => 'Alice', 'address' => 'alice@example.test']],
            'toRecipients' => [['emailAddress' => ['address' => 'bob@example.test']]],
            'bccRecipients' => [['emailAddress' => ['address' => 'private@example.test']]],
            'receivedDateTime' => '2026-10-14T09:00:00Z',
            'internetMessageId' => '<message@example.test>',
            'internetMessageHeaders' => [['name' => 'In-Reply-To', 'value' => '<parent@example.test>']],
            'body' => ['contentType' => 'html', 'content' => '<html><head><title>SECRET HEAD</title><style>secret CSS</style></head><body><script>secret script</script><p>Complete <strong>message</strong> final paragraph.</p><img src="https://remote.invalid/tracker.png"><a href="javascript:alert(1)" onclick="run()">unsafe link</a><blockquote>quoted history</blockquote></body></html>'],
        ]);
        self::assertStringContainsString('Complete **message** final paragraph.', $rendered);
        self::assertStringContainsString('quoted history', $rendered);
        self::assertStringContainsString('private@example.test', $rendered);
        self::assertStringContainsString('&lt;parent@example.test&gt;', $rendered);
        self::assertStringContainsString('A \\| report', $rendered);
        foreach (['SECRET HEAD', 'secret CSS', 'secret script', 'tracker.png', 'javascript:', 'onclick', 'only a short preview'] as $excluded) {
            self::assertStringNotContainsString($excluded, $rendered);
        }
    }

    public function test_text_preference_charset_and_encoded_headers_are_preserved(): void
    {
        $rendered = (new EmailRenderer)->render([
            'subject' => '=?UTF-8?B?Q2Fmw6k=?=',
            'textBody' => mb_convert_encoding("Full café\r\n> old quote\r\nFinal", 'ISO-8859-1', 'UTF-8'),
            'htmlBody' => '<p>Other alternative</p>',
            'charset' => 'ISO-8859-1',
        ], ['body' => ['format' => 'prefer_text', 'strip_quoted' => true]]);
        self::assertStringContainsString('# Café', $rendered);
        self::assertStringContainsString("Full café\nFinal", $rendered);
        self::assertStringNotContainsString('old quote', $rendered);
        self::assertStringNotContainsString('Other alternative', $rendered);
    }

    public function test_html_quotes_can_be_stripped_without_discarding_body(): void
    {
        $rendered = (new EmailRenderer)->render([
            'body' => ['contentType' => 'html', 'content' => '<p>Latest message</p><blockquote>old history</blockquote><div class="gmail_quote">older history</div>'],
        ], ['strip_quoted' => true]);
        self::assertStringContainsString('Latest message', $rendered);
        self::assertStringNotContainsString('old history', $rendered);
        self::assertStringNotContainsString('older history', $rendered);
    }

    public function test_absent_body_does_not_invent_preview_text(): void
    {
        $rendered = (new EmailRenderer)->render(['body' => ['contentType' => 'text', 'content' => ''], 'bodyPreview' => 'not the full document']);
        self::assertStringNotContainsString('not the full document', $rendered);
        self::assertStringEndsWith("---\n\n\n", $rendered);
    }

    public function test_missing_full_body_is_a_fetch_error_and_html_preference_is_explicit(): void
    {
        $rendered = (new EmailRenderer)->render(['textBody' => 'plain alternative', 'htmlBody' => '<p>preferred html</p>'], ['body' => ['format' => 'prefer_html']]);
        self::assertStringContainsString('preferred html', $rendered);
        self::assertStringNotContainsString('plain alternative', $rendered);
        $this->expectException(UnexpectedValueException::class);
        (new EmailRenderer)->render(['bodyPreview' => 'partial body']);
    }

    public function test_headers_are_case_insensitive_and_repeated_values_are_preserved(): void
    {
        self::assertSame(['references' => '<a> <b>'], MessageData::headers(['internetMessageHeaders' => [
            ['name' => 'References', 'value' => '<a>'], ['name' => 'REFERENCES', 'value' => '<b>'],
        ]]));
        self::assertNull(MessageData::date('not a date'));
    }
}
