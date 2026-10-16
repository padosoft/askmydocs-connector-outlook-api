<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorMicrosoftMail\Tests\Feature;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Padosoft\AskMyDocsConnectorBase\Exceptions\ConnectorApiException;
use Padosoft\AskMyDocsConnectorBase\Exceptions\ConnectorAuthException;
use Padosoft\AskMyDocsConnectorBase\Support\TenantContext;
use Padosoft\AskMyDocsConnectorMicrosoftMail\Auth\MicrosoftOAuth;
use Padosoft\AskMyDocsConnectorMicrosoftMail\Graph\GraphPermissionException;
use Padosoft\AskMyDocsConnectorMicrosoftMail\Tests\TestCase;

final class OAuthTest extends TestCase
{
    private const DIRECTORY = '11111111-2222-3333-4444-555555555555';

    private const TARGET = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';

    public function test_pkce_signed_identity_and_single_use_state(): void
    {
        $installation = $this->installation();
        $oauth = $this->oauth();
        parse_str((string) parse_url($oauth->initiate($installation), PHP_URL_QUERY), $query);
        $this->assertSame('S256', $query['code_challenge_method']);
        $this->assertStringContainsString('openid', $query['scope']);
        $this->fakeGrant($query);
        $oauth->callback($installation, Request::create('/', 'GET', ['state' => $query['state'], 'code' => 'synthetic-code']));
        $this->assertSame(['id' => self::DIRECTORY.':account-A', 'path' => '/me'], $oauth->mailbox($installation));
        $this->assertSame('fresh-access', $oauth->token($installation));
        Http::assertSent(function ($request) use ($query): bool {
            if ($request->method() !== 'POST') {
                return false;
            }
            $challenge = $this->base64Url(hash('sha256', $request['code_verifier'], true));

            return hash_equals($query['code_challenge'], $challenge);
        });
        $this->expectException(ConnectorAuthException::class);
        $oauth->callback($installation, Request::create('/', 'GET', ['state' => $query['state'], 'code' => 'replay']));
    }

    public function test_scope_denial_never_persists_credentials(): void
    {
        $installation = $this->installation();
        parse_str((string) parse_url($this->oauth()->initiate($installation), PHP_URL_QUERY), $query);
        Http::fake(['*/token' => Http::response(['access_token' => 'a', 'refresh_token' => 'r', 'expires_in' => 3600, 'token_type' => 'Bearer', 'scope' => 'Mail.ReadBasic User.Read'])]);
        try {
            $this->oauth()->callback($installation, Request::create('/', 'GET', ['state' => $query['state'], 'code' => 'c']));
            $this->fail('Insufficient scope accepted.');
        } catch (ConnectorAuthException $exception) {
            $this->assertNull($this->vault->getCredentialRow($installation->id));
        }
    }

    public function test_state_is_bound_to_installation_tenant_and_expiry(): void
    {
        Carbon::setTestNow('2026-10-14 10:00:00');
        try {
            $first = $this->installation();
            $second = $this->installation();
            parse_str((string) parse_url($this->oauth()->initiate($first), PHP_URL_QUERY), $query);
            foreach ([$second, $this->installation([], 'tenant-b')] as $wrong) {
                try {
                    $this->oauth()->callback($wrong, Request::create('/', 'GET', ['state' => $query['state'], 'code' => 'c']));
                    $this->fail('Cross-installation OAuth accepted.');
                } catch (ConnectorAuthException $exception) {
                    $this->assertNull($this->vault->getCredentialRow($first->id));
                }
            }
            Carbon::setTestNow('2026-10-14 10:11:00');
            $this->expectException(ConnectorAuthException::class);
            $this->oauth()->callback($first, Request::create('/', 'GET', ['state' => $query['state'], 'code' => 'c']));
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_provider_denial_consumes_state_and_never_calls_token_endpoint(): void
    {
        $installation = $this->installation();
        parse_str((string) parse_url($this->oauth()->initiate($installation), PHP_URL_QUERY), $query);
        try {
            $this->oauth()->callback($installation, Request::create('/', 'GET', ['state' => $query['state'], 'error' => 'access_denied']));
            $this->fail('Denied consent accepted.');
        } catch (ConnectorAuthException $exception) {
            Http::assertNothingSent();
            $this->assertNull($this->vault->getCredentialRow($installation->id));
        }
        $this->expectException(ConnectorAuthException::class);
        $this->oauth()->callback($installation, Request::create('/', 'GET', ['state' => $query['state'], 'code' => 'c']));
    }

    public function test_invalid_signature_or_nonce_cannot_set_directory_identity(): void
    {
        $installation = $this->installation();
        parse_str((string) parse_url($this->oauth()->initiate($installation), PHP_URL_QUERY), $query);
        $this->fakeGrant(array_replace($query, ['nonce' => 'wrong-nonce']));
        $this->expectException(ConnectorAuthException::class);
        $this->oauth()->callback($installation, Request::create('/', 'GET', ['state' => $query['state'], 'code' => 'c']));
    }

    public function test_refresh_preserves_extra_and_original_refresh_when_not_rotated(): void
    {
        $installation = $this->installation();
        $extra = $this->extra() + ['sync_state' => ['folders' => ['Inbox' => ['cursor' => 'opaque']]], 'unrelated' => 'preserved'];
        $this->vault->setCredentials($installation->id, 'expired', 'original-refresh', Carbon::now()->subHour(), $extra);
        Http::fake(['*/token' => Http::response(['access_token' => 'refreshed', 'token_type' => 'Bearer', 'scope' => 'Mail.Read User.Read', 'expires_in' => 3600])]);
        $this->assertSame('refreshed', $this->oauth()->token($installation));
        $this->assertSame('original-refresh', $this->vault->getRefreshToken($installation->id));
        $this->assertSame($extra['sync_state'], $this->vault->getExtra($installation->id)['sync_state']);
        $this->assertSame('preserved', $this->vault->getExtra($installation->id)['unrelated']);
        $this->oauth()->token($installation);
        Http::assertSentCount(1);
    }

    public function test_refresh_rotation_preserves_mailbox_and_state(): void
    {
        $installation = $this->installation();
        $this->vault->setCredentials($installation->id, 'expired', 'original-refresh', Carbon::now()->subHour(), $this->extra());
        Http::fake(['*/token' => Http::response(['access_token' => 'refreshed', 'refresh_token' => 'rotated-refresh', 'token_type' => 'Bearer', 'scope' => 'https://graph.microsoft.com/Mail.Read User.Read', 'expires_in' => 3600])]);
        $this->assertSame('refreshed', $this->oauth()->token($installation));
        $this->assertSame('rotated-refresh', $this->vault->getRefreshToken($installation->id));
        $this->assertSame(self::DIRECTORY.':account-A', $this->oauth()->mailbox($installation)['id']);
    }

    public function test_transient_refresh_outage_retains_credentials(): void
    {
        $installation = $this->installation();
        $this->vault->setCredentials($installation->id, 'expired', 'original-refresh', Carbon::now()->subHour(), $this->extra());
        Http::fake(['*/token' => Http::response(['error' => 'server_error'], 503)]);
        try {
            $this->oauth()->token($installation);
            $this->fail('Refresh outage accepted.');
        } catch (ConnectorApiException $exception) {
            $this->assertSame('original-refresh', $this->vault->getRefreshToken($installation->id));
            $this->assertSame($this->extra(), $this->vault->getExtra($installation->id));
        }
    }

    public function test_shared_guid_uses_shared_scope_and_never_falls_back(): void
    {
        $installation = $this->installation(['mailbox' => ['target_id' => self::TARGET]]);
        parse_str((string) parse_url($this->oauth()->initiate($installation), PHP_URL_QUERY), $query);
        $this->assertStringContainsString('Mail.Read.Shared', $query['scope']);
        $this->fakeGrant($query, self::DIRECTORY, self::TARGET);
        $this->oauth()->callback($installation, Request::create('/', 'GET', ['state' => $query['state'], 'code' => 'c']));
        $this->assertSame(['id' => self::DIRECTORY.':'.self::TARGET, 'path' => '/users/'.self::TARGET], $this->oauth()->mailbox($installation));
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/me/mailFolders'));
    }

    public function test_shared_permission_denial_keeps_installation_disconnected(): void
    {
        $installation = $this->installation(['mailbox' => ['target_id' => self::TARGET]]);
        parse_str((string) parse_url($this->oauth()->initiate($installation), PHP_URL_QUERY), $query);
        $this->fakeGrant($query, self::DIRECTORY, self::TARGET, 403);
        try {
            $this->oauth()->callback($installation, Request::create('/', 'GET', ['state' => $query['state'], 'code' => 'c']));
            $this->fail('Shared permission denial accepted.');
        } catch (GraphPermissionException $exception) {
            $this->assertNull($this->vault->getCredentialRow($installation->id));
            Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/me/mailFolders'));
        }
    }

    public function test_shared_address_without_guid_has_actionable_error(): void
    {
        $installation = $this->installation(['mailbox' => ['target_id' => 'shared@example.test']]);
        $this->expectException(GraphPermissionException::class);
        $this->expectExceptionMessage('persistent Microsoft user GUID');
        $this->oauth()->initiate($installation);
    }

    public function test_target_change_and_cross_tenant_token_access_are_rejected(): void
    {
        $installation = $this->installation();
        $this->vault->setCredentials($installation->id, 'access', 'refresh', Carbon::now()->addHour(), $this->extra());
        app(TenantContext::class)->set('tenant-b');
        try {
            $this->oauth()->token($installation);
            $this->fail('Cross-tenant token accepted.');
        } catch (ConnectorAuthException $exception) {
            Http::assertNothingSent();
        }
        app(TenantContext::class)->set('tenant-a');
        $installation->config_json = ['mailbox' => ['target_id' => self::TARGET]];
        $this->expectException(ConnectorAuthException::class);
        $this->oauth()->token($installation);
    }

    public function test_authority_cannot_inject_an_endpoint(): void
    {
        config(['connector-microsoft-mail.authority' => 'common/../../evil.test']);
        $this->expectException(ConnectorAuthException::class);
        $this->oauth()->initiate($this->installation());
    }

    public function test_disconnect_only_clears_local_installation(): void
    {
        $installation = $this->installation();
        $other = $this->installation();
        foreach ([$installation, $other] as $account) {
            $this->vault->setCredentials($account->id, 'access', 'refresh', Carbon::now()->addHour(), $this->extra());
        }
        $this->oauth()->disconnect($installation);
        $this->assertNull($this->vault->getCredentialRow($installation->id));
        $this->assertSame('access', $this->vault->getAccessToken($other->id));
        Http::assertNothingSent();
    }

    public function test_outlook_com_personal_identity_uses_verified_consumer_directory(): void
    {
        config(['connector-microsoft-mail.authority' => 'consumers']);
        $installation = $this->installation();
        parse_str((string) parse_url($this->oauth()->initiate($installation), PHP_URL_QUERY), $query);
        $directory = '9188040d-6c67-4c5b-b112-36a304b66dad';
        $this->fakeGrant($query, $directory);
        $this->oauth()->callback($installation, Request::create('/', 'GET', ['state' => $query['state'], 'code' => 'c']));
        $this->assertSame($directory.':account-A', $this->oauth()->mailbox($installation)['id']);
    }

    public function test_personal_account_cannot_use_an_explicit_shared_target(): void
    {
        $installation = $this->installation(['mailbox' => ['target_id' => self::TARGET]]);
        parse_str((string) parse_url($this->oauth()->initiate($installation), PHP_URL_QUERY), $query);
        $this->fakeGrant($query, '9188040d-6c67-4c5b-b112-36a304b66dad', self::TARGET);
        $this->expectException(GraphPermissionException::class);
        $this->expectExceptionMessage('only for Microsoft work or school');
        $this->oauth()->callback($installation, Request::create('/', 'GET', ['state' => $query['state'], 'code' => 'c']));
    }

    public function test_tampered_identity_signature_is_rejected(): void
    {
        $installation = $this->installation();
        parse_str((string) parse_url($this->oauth()->initiate($installation), PHP_URL_QUERY), $query);
        $this->fakeGrant($query, self::DIRECTORY, null, 200, true);
        $this->expectException(ConnectorAuthException::class);
        $this->expectExceptionMessage('signature is invalid');
        $this->oauth()->callback($installation, Request::create('/', 'GET', ['state' => $query['state'], 'code' => 'c']));
    }

    public function test_organizational_authority_cannot_accept_another_verified_directory(): void
    {
        config(['connector-microsoft-mail.authority' => '99999999-9999-9999-9999-999999999999']);
        $installation = $this->installation();
        parse_str((string) parse_url($this->oauth()->initiate($installation), PHP_URL_QUERY), $query);
        $this->fakeGrant($query);
        $this->expectException(ConnectorAuthException::class);
        $this->expectExceptionMessage('directory does not match');
        $this->oauth()->callback($installation, Request::create('/', 'GET', ['state' => $query['state'], 'code' => 'c']));
    }

    public function test_disconnect_cancels_pending_authorization_state(): void
    {
        $installation = $this->installation();
        parse_str((string) parse_url($this->oauth()->initiate($installation), PHP_URL_QUERY), $query);
        $this->oauth()->disconnect($installation);
        $this->expectException(ConnectorAuthException::class);
        $this->oauth()->callback($installation, Request::create('/', 'GET', ['state' => $query['state'], 'code' => 'c']));
    }

    public function test_stored_read_basic_grant_is_not_healthy(): void
    {
        $installation = $this->installation();
        $this->vault->setCredentials($installation->id, 'access', 'refresh', Carbon::now()->addHour(), array_replace($this->extra(), ['scope' => 'Mail.ReadBasic User.Read']));
        $this->expectException(ConnectorAuthException::class);
        $this->oauth()->token($installation);
    }

    private function oauth(): MicrosoftOAuth
    {
        return new MicrosoftOAuth($this->vault, app(TenantContext::class));
    }

    /** @return array<string,mixed> */
    private function extra(): array
    {
        return ['mailbox_id' => self::DIRECTORY.':account-A', 'mailbox_path' => '/me', 'mailbox_target_id' => null, 'microsoft_directory_id' => self::DIRECTORY, 'microsoft_account_id' => 'account-A', 'scope' => 'Mail.Read User.Read', 'token_type' => 'Bearer'];
    }

    /** @param array<string,string> $query */
    private function fakeGrant(array $query, string $directory = self::DIRECTORY, ?string $target = null, int $targetStatus = 200, bool $tamperedSignature = false): void
    {
        $private = openssl_pkey_new(['private_key_bits' => 2048]);
        $details = openssl_pkey_get_details($private);
        $header = $this->base64Url(json_encode(['alg' => 'RS256', 'kid' => 'synthetic-test-key'], JSON_THROW_ON_ERROR));
        $claims = $this->base64Url(json_encode(['tid' => $directory, 'aud' => 'test-client', 'iss' => 'https://login.microsoftonline.com/'.$directory.'/v2.0', 'nonce' => $query['nonce'], 'exp' => now()->addHour()->getTimestamp(), 'nbf' => now()->subMinute()->getTimestamp()], JSON_THROW_ON_ERROR));
        openssl_sign($header.'.'.$claims, $signature, $private, OPENSSL_ALGO_SHA256);
        if ($tamperedSignature) {
            $signature[0] = chr(ord($signature[0]) ^ 255);
        }
        $token = $header.'.'.$claims.'.'.$this->base64Url($signature);
        Http::fake([
            '*/discovery/v2.0/keys' => Http::response(['keys' => [['kid' => 'synthetic-test-key', 'kty' => 'RSA', 'use' => 'sig', 'n' => $this->base64Url($details['rsa']['n']), 'e' => $this->base64Url($details['rsa']['e']), 'issuer' => 'https://login.microsoftonline.com/{tenantid}/v2.0']]]),
            '*/oauth2/v2.0/token' => Http::response(['access_token' => 'fresh-access', 'refresh_token' => 'fresh-refresh', 'token_type' => 'Bearer', 'expires_in' => 3600, 'scope' => 'Mail.Read User.Read openid offline_access'.($target === null ? '' : ' Mail.Read.Shared'), 'id_token' => $token]),
            'https://graph.microsoft.com/v1.0/me?$select=id' => Http::response(['id' => 'account-A']),
            'https://graph.microsoft.com/v1.0/'.($target === null ? 'me' : 'users/'.$target).'/mailFolders*' => Http::response(['value' => []], $targetStatus),
        ]);
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
