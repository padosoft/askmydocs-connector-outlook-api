<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorMicrosoftMail\Auth;

use Carbon\Carbon;
use GuzzleHttp\Exception\TransferException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Padosoft\AskMyDocsConnectorBase\Auth\OAuthCredentialVault;
use Padosoft\AskMyDocsConnectorBase\Exceptions\ConnectorApiException;
use Padosoft\AskMyDocsConnectorBase\Exceptions\ConnectorAuthException;
use Padosoft\AskMyDocsConnectorBase\Models\ConnectorInstallation;
use Padosoft\AskMyDocsConnectorBase\Support\TenantContext;
use Padosoft\AskMyDocsConnectorMicrosoftMail\Graph\GraphClient;
use Padosoft\AskMyDocsConnectorMicrosoftMail\Graph\GraphPermissionException;

final class MicrosoftOAuth
{
    public function __construct(private readonly OAuthCredentialVault $vault, private readonly TenantContext $tenants) {}

    /** @return list<string> */
    public function scopes(?ConnectorInstallation $installation = null): array
    {
        $scopes = ['offline_access', 'Mail.Read', 'User.Read', 'openid'];
        if ($installation !== null && $this->target($installation) !== null) {
            $scopes[] = 'Mail.Read.Shared';
        }

        return $scopes;
    }

    public function initiate(ConnectorInstallation $installation): string
    {
        $this->assertInstallation($installation);
        $provider = $this->provider();
        $state = bin2hex(random_bytes(32));
        $verifier = $this->base64Url(random_bytes(64));
        $nonce = bin2hex(random_bytes(32));
        Cache::lock($this->installationStateKey($installation).':lock', 30)->block(5, function () use ($state, $installation, $provider, $verifier, $nonce): void {
            $previous = Cache::get($this->installationStateKey($installation));
            if (is_string($previous)) {
                Cache::forget($previous);
            }
            Cache::put($this->stateKey($state), [
                'tenant_id' => $installation->tenant_id,
                'installation_id' => $installation->id,
                'fingerprint' => $this->fingerprint($installation),
                'verifier' => $verifier,
                'nonce' => $nonce,
                'authority' => $provider['authority'],
            ], now()->addMinutes(10));
            Cache::put($this->installationStateKey($installation), $this->stateKey($state), now()->addMinutes(10));
        });

        return 'https://login.microsoftonline.com/'.$provider['authority'].'/oauth2/v2.0/authorize?'.http_build_query([
            'client_id' => $provider['client_id'],
            'redirect_uri' => $provider['redirect_uri'],
            'response_type' => 'code',
            'response_mode' => 'query',
            'scope' => implode(' ', $this->scopes($installation)),
            'state' => $state,
            'nonce' => $nonce,
            'code_challenge' => $this->base64Url(hash('sha256', $verifier, true)),
            'code_challenge_method' => 'S256',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public function callback(ConnectorInstallation $installation, Request $request): void
    {
        $this->assertInstallation($installation);
        $state = $request->input('state');
        if (! is_string($state) || ! preg_match('/^[a-f0-9]{64}$/D', $state)) {
            throw new ConnectorAuthException('Microsoft OAuth state is missing or invalid.');
        }
        $stored = Cache::lock($this->stateKey($state).':lock', 30)->block(5, function () use ($state, $installation): array {
            $stored = Cache::get($this->stateKey($state));
            if (! is_array($stored) || ($stored['tenant_id'] ?? null) !== $installation->tenant_id || ($stored['installation_id'] ?? null) !== $installation->id || ($stored['fingerprint'] ?? null) !== $this->fingerprint($installation)) {
                throw new ConnectorAuthException('Microsoft OAuth state expired, replayed, or belongs to another installation.');
            }
            Cache::forget($this->stateKey($state));

            return $stored;
        });
        if ($request->filled('error')) {
            throw new ConnectorAuthException('Microsoft OAuth consent was denied or requires user interaction.');
        }
        $code = $request->input('code');
        if (! is_string($code) || $code === '') {
            throw new ConnectorAuthException('Microsoft OAuth authorization code is missing.');
        }
        $provider = $this->provider();
        $payload = $this->exchange($provider, [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $provider['redirect_uri'],
            'code_verifier' => $stored['verifier'],
            'scope' => implode(' ', $this->scopes($installation)),
        ]);
        $this->validateGrant($payload, $installation, true);
        if (! is_string($payload['id_token'] ?? null)) {
            throw new ConnectorAuthException('Microsoft OAuth returned no verifiable identity token.');
        }
        $claims = (new IdTokenValidator)->validate($payload['id_token'], $provider['client_id'], $stored['nonce'], $provider['authority']);
        $account = (new GraphClient($payload['access_token']))->get('/me?$select=id');
        if (! is_string($account['id'] ?? null) || $account['id'] === '') {
            throw new ConnectorAuthException('Microsoft Graph returned no stable account identity.');
        }
        $target = $this->target($installation);
        $directory = $claims['tid'];
        if ($target !== null && strtolower($directory) === '9188040d-6c67-4c5b-b112-36a304b66dad') {
            throw new GraphPermissionException('Shared mailbox access is available only for Microsoft work or school accounts.');
        }
        $mailboxPath = $target === null ? '/me' : '/users/'.rawurlencode($target);
        // An explicit target GUID is verified by mail access, which does not require directory-wide user scopes.
        (new GraphClient($payload['access_token'], $mailboxPath))->get($mailboxPath.'/mailFolders?$top=1&$select=id');
        $mailboxId = strtolower($directory).':'.($target ?? $account['id']);
        Cache::lock($this->syncLock($installation), 900)->block(5, function () use ($installation, $payload, $mailboxId, $mailboxPath, $directory, $account, $target, $state): void {
            Cache::lock($this->credentialLock($installation), 120)->block(5, function () use ($installation, $payload, $mailboxId, $mailboxPath, $directory, $account, $target, $state): void {
                if (Cache::get($this->installationStateKey($installation)) !== $this->stateKey($state)) {
                    throw new ConnectorAuthException('Microsoft OAuth flow was cancelled or replaced before completion.');
                }
                $existing = $this->vault->getExtra($installation->id);
                // Preserve state only for the same authenticated mailbox; account changes require a fresh baseline.
                $extra = ($existing['mailbox_id'] ?? null) === $mailboxId ? $existing : [];
                $extra = array_replace($extra, [
                    'mailbox_id' => $mailboxId,
                    'mailbox_path' => $mailboxPath,
                    'mailbox_target_id' => $target,
                    'microsoft_directory_id' => strtolower($directory),
                    'microsoft_account_id' => $account['id'],
                    'scope' => $payload['scope'],
                    'token_type' => 'Bearer',
                ]);
                $this->persist($installation, $payload, $payload['refresh_token'], $extra);
                $installation->forceFill(['status' => ConnectorInstallation::STATUS_ACTIVE, 'error_json' => null])->save();
                Cache::forget($this->installationStateKey($installation));
            });
        });
    }

    public function token(ConnectorInstallation $installation): string
    {
        $this->assertInstallation($installation);
        $this->mailbox($installation);
        $access = $this->vault->getAccessToken($installation->id);
        if ($access !== null) {
            return $access;
        }

        return Cache::lock($this->credentialLock($installation), 120)->block(5, function () use ($installation): string {
            $access = $this->vault->getAccessToken($installation->id);
            if ($access !== null) {
                return $access;
            }
            $refresh = $this->vault->getRefreshToken($installation->id);
            if ($refresh === null) {
                throw new ConnectorAuthException('Microsoft Mail is disconnected; reconnect the installation.');
            }
            $provider = $this->provider();
            $payload = $this->exchange($provider, ['grant_type' => 'refresh_token', 'refresh_token' => $refresh, 'scope' => implode(' ', $this->scopes($installation))]);
            $this->validateGrant($payload, $installation, false);
            $extra = $this->vault->getExtra($installation->id);
            $extra['scope'] = $payload['scope'];
            $this->persist($installation, $payload, $payload['refresh_token'] ?? $refresh, $extra);

            return $payload['access_token'];
        });
    }

    /** @return array{id:string,path:string} */
    public function mailbox(ConnectorInstallation $installation): array
    {
        $this->assertInstallation($installation);
        $extra = $this->vault->getExtra($installation->id);
        if (! is_string($extra['mailbox_id'] ?? null) || ! is_string($extra['mailbox_path'] ?? null) || ! isset($extra['microsoft_directory_id'], $extra['microsoft_account_id'])) {
            throw new ConnectorAuthException('Microsoft Mail has no verified mailbox identity; reconnect the installation.');
        }
        if (! is_string($extra['scope'] ?? null)) {
            throw new ConnectorAuthException('Microsoft Mail stored grant has no verified scopes; reconnect.');
        }
        $this->validateScopes($extra['scope'], $installation);
        $target = $this->target($installation);
        if (($extra['mailbox_target_id'] ?? null) !== $target || $extra['mailbox_path'] !== ($target === null ? '/me' : '/users/'.rawurlencode($target))) {
            throw new ConnectorAuthException('Microsoft Mail target changed; reconnect before using mailbox state.');
        }

        return ['id' => $extra['mailbox_id'], 'path' => $extra['mailbox_path']];
    }

    public function disconnect(ConnectorInstallation $installation): void
    {
        $this->assertInstallation($installation);
        Cache::lock($this->credentialLock($installation), 120)->block(5, function () use ($installation): void {
            $this->vault->clearCredentials($installation->id);
            $pending = Cache::pull($this->installationStateKey($installation));
            if (is_string($pending)) {
                Cache::forget($pending);
            }
        });
    }

    /** @return array{client_id:string,client_secret:string,redirect_uri:string,authority:string} */
    private function provider(): array
    {
        $provider = [];
        foreach (['client_id', 'client_secret', 'redirect_uri', 'authority'] as $key) {
            $value = config('connector-microsoft-mail.'.$key, $key === 'authority' ? 'common' : '');
            if (! is_string($value) || $value === '') {
                throw new ConnectorAuthException('Microsoft OAuth server configuration is incomplete.');
            }
            $provider[$key] = $value;
        }
        if (! in_array($provider['authority'], ['common', 'organizations', 'consumers'], true) && ! preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/iD', $provider['authority'])) {
            throw new ConnectorAuthException('Microsoft OAuth authority must be common, organizations, consumers, an organizational tenant GUID.');
        }
        $redirect = parse_url($provider['redirect_uri']);
        if ($redirect === false || ! in_array($redirect['scheme'] ?? '', ['http', 'https'], true) || ! isset($redirect['host']) || isset($redirect['user']) || isset($redirect['fragment'])) {
            throw new ConnectorAuthException('Microsoft OAuth redirect URI is invalid.');
        }

        return $provider;
    }

    /** @param array<string,string> $provider
     * @param array<string,mixed> $parameters
     * @return array<string,mixed> */
    private function exchange(array $provider, array $parameters): array
    {
        try {
            $response = Http::asForm()->acceptJson()->connectTimeout(10)->timeout(30)->withOptions(OAuthResponseLimit::options())->post('https://login.microsoftonline.com/'.$provider['authority'].'/oauth2/v2.0/token', array_merge($parameters, ['client_id' => $provider['client_id'], 'client_secret' => $provider['client_secret']]));
        } catch (ConnectionException|TransferException $exception) {
            throw new ConnectorApiException('Microsoft OAuth service is temporarily unavailable.');
        }
        if (in_array($response->status(), [429, 500, 502, 503, 504], true)) {
            throw new ConnectorApiException('Microsoft OAuth service is temporarily unavailable (HTTP '.$response->status().').');
        }
        if (! $response->successful()) {
            throw new ConnectorAuthException('Microsoft OAuth grant failed; reconnect or verify the application registration.');
        }
        if (strlen($response->body()) > 1024 * 1024 || ! is_array($response->json())) {
            throw new ConnectorApiException('Microsoft OAuth returned an invalid token response.');
        }

        return $response->json();
    }

    /** @param array<string,mixed> $payload */
    private function validateGrant(array $payload, ConnectorInstallation $installation, bool $initial): void
    {
        if (! is_string($payload['access_token'] ?? null) || $payload['access_token'] === '' || strcasecmp((string) ($payload['token_type'] ?? ''), 'Bearer') !== 0 || ! is_numeric($payload['expires_in'] ?? null) || (int) $payload['expires_in'] <= 0) {
            throw new ConnectorAuthException('Microsoft OAuth returned an incomplete bearer grant.');
        }
        if ($initial && (! is_string($payload['refresh_token'] ?? null) || $payload['refresh_token'] === '')) {
            throw new ConnectorAuthException('Microsoft OAuth did not grant offline access; reconnect and consent.');
        }
        if (isset($payload['refresh_token']) && (! is_string($payload['refresh_token']) || $payload['refresh_token'] === '')) {
            throw new ConnectorAuthException('Microsoft OAuth returned an invalid refresh token.');
        }
        if (! is_string($payload['scope'] ?? null)) {
            throw new ConnectorAuthException('Microsoft OAuth did not confirm granted scopes.');
        }
        $this->validateScopes($payload['scope'], $installation);
    }

    private function validateScopes(string $scope, ConnectorInstallation $installation): void
    {
        $granted = preg_split('/\s+/', strtolower($scope)) ?: [];
        $granted = array_map(static fn (string $scope): string => str_replace('https://graph.microsoft.com/', '', $scope), $granted);
        // Microsoft does not consistently echo OIDC/offline scopes; verify Graph capabilities and offline via refresh token.
        $required = ['mail.read', 'user.read'];
        if ($this->target($installation) !== null) {
            $required[] = 'mail.read.shared';
        }
        foreach ($required as $scope) {
            if (! in_array($scope, $granted, true)) {
                throw new ConnectorAuthException('Microsoft OAuth granted insufficient read-only scopes; reconnect and consent.');
            }
        }
    }

    /** @param array<string,mixed> $payload
     * @param array<string,mixed> $extra */
    private function persist(ConnectorInstallation $installation, array $payload, string $refresh, array $extra): void
    {
        $this->vault->setCredentials($installation->id, $payload['access_token'], $refresh, Carbon::now()->addSeconds(max(1, (int) $payload['expires_in'] - 60)), $extra);
    }

    private function target(ConnectorInstallation $installation): ?string
    {
        $mailbox = ($installation->config_json ?? [])['mailbox'] ?? [];
        if (! is_array($mailbox)) {
            throw new ConnectorAuthException('Microsoft Mail mailbox configuration is invalid.');
        }
        $target = $mailbox['target_id'] ?? $mailbox['id'] ?? null;
        if ($target === null || $target === '') {
            if (($mailbox['type'] ?? 'personal') === 'shared' || ! empty($mailbox['address'])) {
                throw new GraphPermissionException('Shared mailbox resolution requires a persistent Microsoft user GUID in mailbox.target_id. User.Read cannot resolve another user address; obtain the target GUID from the administrator.');
            }

            return null;
        }
        if (! is_string($target) || ! preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/iD', $target)) {
            throw new GraphPermissionException('Shared mailbox target must be a persistent Microsoft user GUID. User.Read cannot resolve another user address; obtain the target GUID from the administrator.');
        }

        return strtolower($target);
    }

    private function assertInstallation(ConnectorInstallation $installation): void
    {
        if ($installation->id < 1 || $installation->tenant_id !== $this->tenants->current() || $installation->connector_name !== 'microsoft-mail') {
            throw new ConnectorAuthException('Microsoft Mail installation is outside the active tenant.');
        }
    }

    private function fingerprint(ConnectorInstallation $installation): string
    {
        return hash('sha256', json_encode([$this->provider(), $this->target($installation)], JSON_THROW_ON_ERROR));
    }

    private function installationStateKey(ConnectorInstallation $installation): string
    {
        return 'microsoft-mail:oauth-installation:'.$installation->tenant_id.':'.$installation->id;
    }

    private function syncLock(ConnectorInstallation $installation): string
    {
        return 'microsoft-mail:sync-installation:'.hash('sha256', json_encode([$installation->tenant_id, $installation->id], JSON_THROW_ON_ERROR));
    }

    private function stateKey(string $state): string
    {
        return 'microsoft-mail:oauth-state:'.hash('sha256', $state);
    }

    private function credentialLock(ConnectorInstallation $installation): string
    {
        return 'microsoft-mail:credential:'.$installation->tenant_id.':'.$installation->id;
    }

    private function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
