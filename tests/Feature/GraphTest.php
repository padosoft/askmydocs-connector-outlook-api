<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorMicrosoftMail\Tests\Feature;

use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Padosoft\AskMyDocsConnectorBase\Exceptions\ConnectorAuthException;
use Padosoft\AskMyDocsConnectorMicrosoftMail\Graph\GraphClient;
use Padosoft\AskMyDocsConnectorMicrosoftMail\Graph\GraphException;
use Padosoft\AskMyDocsConnectorMicrosoftMail\Graph\GraphPermissionException;
use Padosoft\AskMyDocsConnectorMicrosoftMail\Graph\GraphResourceNotFoundException;
use Padosoft\AskMyDocsConnectorMicrosoftMail\Graph\InvalidDeltaCursorException;
use Padosoft\AskMyDocsConnectorMicrosoftMail\Tests\TestCase;

final class GraphTest extends TestCase
{
    private function resetHttp(): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
    }

    public function test_all_gets_and_continuations_preserve_immutable_ids(): void
    {
        $next = "https://graph.microsoft.com/v1.0/me/mailFolders('Case%2B%2Fid')/messages/delta?\$skiptoken=opaque";
        Http::fakeSequence()->push(['value' => [['id' => 'a']], '@odata.nextLink' => $next])->push(['value' => [], '@odata.deltaLink' => str_replace('skiptoken', 'deltatoken', $next)]);
        $pages = iterator_to_array((new GraphClient('synthetic'))->pages('/me/mailFolders/Case%2B%2Fid/messages/delta'));
        $this->assertCount(2, $pages);
        Http::assertSentCount(2);
        Http::assertSent(fn ($request): bool => $request->hasHeader('Prefer', 'IdType="ImmutableId", outlook.body-content-type="text"'));
        Http::assertNotSent(fn ($request): bool => $request->method() !== 'GET');
    }

    public function test_continuations_reject_foreign_hosts_accounts_folders_and_case_changed_ids(): void
    {
        $client = new GraphClient('synthetic', '/users/Account-A');
        $expected = '/users/Account-A/mailFolders/Folder-A/messages/delta';
        $urls = [
            'http://graph.microsoft.com/v1.0/users/Account-A/mailFolders/Folder-A/messages/delta',
            'https://evil.test/v1.0/users/Account-A/mailFolders/Folder-A/messages/delta',
            'https://graph.microsoft.com:443/v1.0/users/Account-A/mailFolders/Folder-A/messages/delta',
            'https://user@graph.microsoft.com/v1.0/users/Account-A/mailFolders/Folder-A/messages/delta',
            'https://graph.microsoft.com/beta/users/Account-A/mailFolders/Folder-A/messages/delta',
            'https://graph.microsoft.com/v1.0/me/mailFolders/Folder-A/messages/delta',
            'https://graph.microsoft.com/v1.0/users/account-a/mailFolders/Folder-A/messages/delta',
            'https://graph.microsoft.com/v1.0/users/Account-A/mailFolders/folder-a/messages/delta',
            'https://graph.microsoft.com/v1.0/users/Account-A/mailFolders/%2e%2e/messages/delta',
        ];
        foreach ($urls as $url) {
            try {
                $client->validateContinuation($url, $expected);
                $this->fail('Unsafe continuation accepted.');
            } catch (GraphException $exception) {
                $this->assertStringNotContainsString('synthetic', $exception->getMessage());
            }
        }
        Http::assertNothingSent();
    }

    public function test_discovery_recurses_pages_and_excludes_localized_well_known_folder_ids(): void
    {
        Http::fake([
            '*mailFolders/deleteditems*' => Http::response(['id' => 'trash-id']),
            '*mailFolders/junkemail*' => Http::response(['id' => 'junk-id']),
            'https://graph.microsoft.com/v1.0/me/mailFolders?$skiptoken=x' => Http::response(['value' => [
                ['id' => 'two', 'displayName' => 'Duplicato', 'childFolderCount' => 0],
                ['id' => 'junk-id', 'displayName' => 'Posta indesiderata', 'childFolderCount' => 0],
                ['id' => 'hidden', 'displayName' => 'Hidden', 'childFolderCount' => 0, 'isHidden' => true],
            ]]),
            'https://graph.microsoft.com/v1.0/me/mailFolders?*' => Http::response(['value' => [
                ['id' => 'one', 'displayName' => 'Duplicato', 'childFolderCount' => 1],
                ['id' => 'trash-id', 'displayName' => 'Gelöschte Elemente', 'childFolderCount' => 0],
            ], '@odata.nextLink' => 'https://graph.microsoft.com/v1.0/me/mailFolders?$skiptoken=x']),
            '*mailFolders/one/childFolders*' => Http::response(['value' => [['id' => 'child', 'displayName' => 'Nested', 'childFolderCount' => 0]]]),
        ]);
        $folders = (new GraphClient('synthetic'))->folders();
        $this->assertCount(5, $folders);
        $this->assertSame('Duplicato/Nested', $folders['child']['path']);
        $this->assertFalse($folders['trash-id']['default_selected']);
        $this->assertFalse($folders['junk-id']['default_selected']);
        $this->assertSame('deleteditems', $folders['trash-id']['well_known']);
        $this->assertArrayNotHasKey('hidden', $folders);
    }

    public function test_discovery_hidden_choice_and_permission_error_are_explicit(): void
    {
        Http::fake([
            '*deleteditems*' => Http::response(['id' => 'trash']),
            '*junkemail*' => Http::response(['id' => 'junk']),
            '*mailFolders?*' => Http::response(['value' => [['id' => 'hidden', 'displayName' => 'Hidden', 'isHidden' => true]]]),
        ]);
        $this->assertTrue((new GraphClient('synthetic'))->folders(true)['hidden']['hidden']);
        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'includeHiddenFolders=true'));
        $this->resetHttp();
        Http::fake(['*' => Http::response(['error' => ['code' => 'ErrorAccessDenied']], 403)]);
        $this->expectException(GraphPermissionException::class);
        (new GraphClient('synthetic'))->folders();
    }

    public function test_malformed_response_cycle_redirect_and_byte_budget_fail_explicitly(): void
    {
        $cases = [
            Http::response('{malformed', 200),
            Http::response(['value' => []], 302, ['Location' => 'https://evil.test']),
            Http::response(['value' => []], 200),
        ];
        foreach ($cases as $index => $response) {
            config(['connector-microsoft-mail.http.max_response_bytes' => $index === 2 ? 2 : 1024]);
            $this->resetHttp();
            Http::fake(['*' => $response]);
            try {
                (new GraphClient('synthetic'))->get('/me/messages');
                $this->fail('Invalid response accepted.');
            } catch (GraphException $exception) {
                $this->assertNotSame('', $exception->getMessage());
            }
        }
        config(['connector-microsoft-mail.http.max_response_bytes' => 1024]);
        $this->resetHttp();
        Http::fake(['*' => Http::response(['value' => [], '@odata.nextLink' => 'https://graph.microsoft.com/v1.0/me/messages?$skiptoken=repeated'])]);
        $this->expectException(GraphException::class);
        iterator_to_array((new GraphClient('synthetic'))->pages('/me/messages'));
    }

    public function test_distinct_auth_permission_not_found_and_invalid_delta_errors(): void
    {
        foreach ([401 => ConnectorAuthException::class, 403 => GraphPermissionException::class, 404 => GraphResourceNotFoundException::class, 410 => InvalidDeltaCursorException::class] as $status => $expected) {
            $this->resetHttp();
            Http::fake(['*' => Http::response(['error' => ['code' => 'synthetic']], $status)]);
            try {
                (new GraphClient('synthetic'))->get('/me/messages/id');
                $this->fail('Error response accepted.');
            } catch (\Throwable $exception) {
                $this->assertInstanceOf($expected, $exception);
            }
        }
    }

    public function test_retry_after_over_budget_throws_without_waiting_or_infinite_retry(): void
    {
        config(['connector-microsoft-mail.http.retries' => 2]);
        Http::fake(['*' => Http::response(['error' => ['code' => 'TooManyRequests']], 429, ['Retry-After' => '3600'])]);
        try {
            (new GraphClient('synthetic'))->get('/me/messages');
            $this->fail('Excessive retry delay accepted.');
        } catch (GraphException $exception) {
            $this->assertStringContainsString('wait budget', $exception->getMessage());
            Http::assertSentCount(1);
        }
    }

    public function test_attachment_discovery_covers_inline_reference_and_nested_items_without_external_fetch(): void
    {
        Http::fake([
            '*messages/msg/attachments?*' => Http::response(['value' => [
                ['@odata.type' => '#microsoft.graph.fileAttachment', 'id' => 'a', 'name' => 'normal.txt', 'contentType' => 'text/plain', 'size' => 3],
                ['@odata.type' => '#microsoft.graph.fileAttachment', 'id' => 'b', 'name' => 'inline.txt', 'contentType' => 'text/plain', 'isInline' => true, 'size' => 3],
                ['@odata.type' => '#microsoft.graph.referenceAttachment', 'id' => 'c', 'name' => 'link.txt', 'sourceUrl' => 'https://evil.test/file'],
                ['@odata.type' => '#microsoft.graph.itemAttachment', 'id' => 'd', 'name' => 'nested.eml', 'size' => 50],
            ]]),
            '*attachments/a' => Http::response(['contentBytes' => base64_encode('abc')]),
            '*attachments/d?*' => Http::response(['item' => ['@odata.type' => '#microsoft.graph.message', 'id' => 'nested', 'body' => ['contentType' => 'text', 'content' => 'nested body']]]),
        ]);
        $attachments = (new GraphClient('synthetic'))->attachments('msg');
        $this->assertCount(4, $attachments);
        $this->assertSame(base64_encode('abc'), $attachments[0]['contentBytes']);
        $this->assertSame('inline', $attachments[1]['__excluded_reason']);
        $this->assertSame('reference_attachment', $attachments[2]['__excluded_reason']);
        $this->assertSame('nested', $attachments[3]['item']['id']);
        Http::assertSentCount(3);
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'evil.test'));
    }

    public function test_attachment_count_size_and_total_budget_skip_fetch_using_stable_id_order(): void
    {
        Http::fake([
            '*attachments?*' => Http::response(['value' => [
                ['@odata.type' => '#microsoft.graph.fileAttachment', 'id' => 'b', 'name' => 'same.txt', 'size' => 3],
                ['@odata.type' => '#microsoft.graph.fileAttachment', 'id' => 'a', 'name' => 'same.txt', 'size' => 3],
                ['@odata.type' => '#microsoft.graph.fileAttachment', 'id' => 'z', 'name' => 'huge.txt', 'size' => 100],
            ]]),
            '*attachments/a' => Http::response(['contentBytes' => base64_encode('abc')]),
        ]);
        $files = (new GraphClient('synthetic'))->attachments('msg', ['max_bytes' => 10, 'max_count' => 1, 'max_total_bytes' => 3]);
        $this->assertSame('a', $files[0]['id']);
        $this->assertSame('count_limit', $files[1]['__excluded_reason']);
        $this->assertSame('file_byte_limit', $files[2]['__excluded_reason']);
        Http::assertSentCount(2);
    }

    public function test_message_and_attachment_detail_identity_mismatch_fail_before_ingest(): void
    {
        Http::fake(['*' => Http::response(['id' => 'different-ID', 'body' => ['content' => 'unexpected']])]);
        try {
            (new GraphClient('synthetic'))->message('Case-ID');
            $this->fail('Different immutable message identity accepted.');
        } catch (GraphException $exception) {
            $this->assertStringContainsString('identity different', $exception->getMessage());
        }
        $this->resetHttp();
        Http::fake([
            '*attachments?*' => Http::response(['value' => [['@odata.type' => '#microsoft.graph.fileAttachment', 'id' => 'Case-ID', 'name' => 'file.txt', 'size' => 3]]]),
            '*attachments/Case-ID' => Http::response(['id' => 'different-ID', 'contentBytes' => base64_encode('abc')]),
        ]);
        $this->expectException(GraphException::class);
        $this->expectExceptionMessage('identity different');
        (new GraphClient('synthetic'))->attachments('msg');
    }

    public function test_aggregate_attachment_transport_budget_and_missing_accepted_bytes_are_errors(): void
    {
        Http::fake([
            '*attachments?*' => Http::response(['value' => [['@odata.type' => '#microsoft.graph.fileAttachment', 'id' => 'a', 'name' => 'file.txt', 'size' => 1]]]),
            '*attachments/a' => Http::response(['id' => 'a']),
        ]);
        try {
            (new GraphClient('synthetic'))->attachments('msg');
            $this->fail('Missing accepted file bytes treated as completion.');
        } catch (GraphException $exception) {
            $this->assertStringContainsString('no bytes', $exception->getMessage());
        }
        $this->resetHttp();
        config(['connector-microsoft-mail.http.max_attachment_response_bytes' => 2]);
        Http::fake(['*' => Http::response(['value' => []])]);
        $this->expectException(GraphException::class);
        $this->expectExceptionMessage('response budget');
        (new GraphClient('synthetic'))->attachments('msg');
    }

    public function test_expired_deadline_makes_no_request_and_retry_delay_respects_remaining_budget(): void
    {
        $client = new GraphClient('synthetic');
        $client->withDeadline(microtime(true) - 1);
        try {
            $client->get('/me/messages');
            $this->fail('Expired deadline was ignored.');
        } catch (GraphException $exception) {
            $this->assertStringContainsString('time budget exhausted', $exception->getMessage());
            Http::assertNothingSent();
        }
        config(['connector-microsoft-mail.http.retries' => 2]);
        Http::fake(['*' => Http::response(['error' => ['code' => 'throttled']], 429, ['Retry-After' => '1'])]);
        $client = new GraphClient('synthetic');
        $client->withDeadline(microtime(true) + 0.5);
        try {
            $client->get('/me/messages');
            $this->fail('Retry delay exceeded the run deadline.');
        } catch (GraphException $exception) {
            $this->assertStringContainsString('remaining time budget', $exception->getMessage());
            Http::assertSentCount(1);
        }
    }

    public function test_http_timeouts_are_clamped_to_the_run_deadline(): void
    {
        Http::fake(function ($request, $options) {
            $this->assertLessThanOrEqual(0.5, $options['timeout']);
            $this->assertLessThanOrEqual(0.5, $options['connect_timeout']);

            return Http::response(['value' => []]);
        });
        $client = new GraphClient('synthetic');
        $client->withDeadline(microtime(true) + 0.5);
        $this->assertSame(['value' => []], $client->get('/me/messages'));
    }

    public function test_message_requests_full_body_headers_and_selected_format(): void
    {
        Http::fake(['*' => Http::response(['id' => 'Case-ID', 'body' => ['contentType' => 'html', 'content' => '<p>complete</p>'], 'internetMessageHeaders' => []])]);
        $message = (new GraphClient('synthetic', '/me', false))->message('Case-ID');
        $this->assertSame('<p>complete</p>', $message['body']['content']);
        Http::assertSent(fn ($request): bool => $request->hasHeader('Prefer', 'IdType="ImmutableId", outlook.body-content-type="html"') && str_contains(urldecode($request->url()), 'internetMessageHeaders'));
    }
}
