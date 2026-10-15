<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorMicrosoftMail\Auth;

use GuzzleHttp\Exception\TransferException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Padosoft\AskMyDocsConnectorBase\Exceptions\ConnectorApiException;
use Padosoft\AskMyDocsConnectorBase\Exceptions\ConnectorAuthException;

/** Validate Microsoft v2 OIDC tokens; unsigned claims never define mailbox identity. */
final class IdTokenValidator
{
    /** @return array<string,mixed> */
    public function validate(string $token, string $clientId, string $nonce, string $authority): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3 || strlen($token) > 32768) {
            throw new ConnectorAuthException('Microsoft identity token is malformed.');
        }
        try {
            $header = json_decode($this->decode($parts[0]), true, 16, JSON_THROW_ON_ERROR);
            $claims = json_decode($this->decode($parts[1]), true, 32, JSON_THROW_ON_ERROR);
            $signature = $this->decode($parts[2]);
        } catch (\JsonException $exception) {
            throw new ConnectorAuthException('Microsoft identity token is malformed.');
        }
        if (! is_array($header) || ! is_array($claims) || ($header['alg'] ?? null) !== 'RS256' || ! is_string($header['kid'] ?? null)) {
            throw new ConnectorAuthException('Microsoft identity token has an unsupported signature.');
        }
        $keys = $this->keys($authority);
        $key = $this->findKey($keys, $header['kid']);
        if ($key === null) {
            Cache::forget($this->cacheKey($authority));
            $key = $this->findKey($this->keys($authority), $header['kid']);
        }
        if ($key === null || ($key['kty'] ?? null) !== 'RSA' || ($key['use'] ?? 'sig') !== 'sig' || ! is_string($key['n'] ?? null) || ! is_string($key['e'] ?? null)) {
            throw new ConnectorAuthException('Microsoft identity signing key is unavailable.');
        }
        $rsa = $this->sequence($this->integer($this->decode($key['n'])).$this->integer($this->decode($key['e'])));
        $der = $this->sequence(hex2bin('300d06092a864886f70d0101010500')."\x03".$this->length(strlen($rsa) + 1)."\x00".$rsa);
        $pem = "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($der), 64, "\n")."-----END PUBLIC KEY-----\n";
        if (openssl_verify($parts[0].'.'.$parts[1], $signature, $pem, OPENSSL_ALGO_SHA256) !== 1) {
            throw new ConnectorAuthException('Microsoft identity token signature is invalid.');
        }
        $tid = $claims['tid'] ?? null;
        if (! is_string($tid) || ! preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/iD', $tid)) {
            throw new ConnectorAuthException('Microsoft identity token has no valid directory identity.');
        }
        $issuer = 'https://login.microsoftonline.com/'.$tid.'/v2.0';
        $keyIssuer = $key['issuer'] ?? 'https://login.microsoftonline.com/{tenantid}/v2.0';
        if (($claims['iss'] ?? null) !== $issuer || ! is_string($keyIssuer) || str_replace('{tenantid}', $tid, $keyIssuer) !== $issuer || ($claims['aud'] ?? null) !== $clientId || ! is_string($claims['nonce'] ?? null) || ! hash_equals($nonce, $claims['nonce'])) {
            throw new ConnectorAuthException('Microsoft identity token issuer, audience or nonce is invalid.');
        }
        $now = now()->getTimestamp();
        if (! is_int($claims['exp'] ?? null) || $claims['exp'] <= $now - 60 || (isset($claims['nbf']) && (! is_int($claims['nbf']) || $claims['nbf'] > $now + 60))) {
            throw new ConnectorAuthException('Microsoft identity token is expired or not active.');
        }
        $consumerTid = '9188040d-6c67-4c5b-b112-36a304b66dad';
        if (($authority === 'consumers' && strtolower($tid) !== $consumerTid) || ($authority === 'organizations' && strtolower($tid) === $consumerTid) || (preg_match('/^[a-f0-9-]{36}$/iD', $authority) && strtolower($authority) !== strtolower($tid))) {
            throw new ConnectorAuthException('Microsoft identity directory does not match the configured authority.');
        }

        return $claims;
    }

    /** @return list<array<string,mixed>> */
    private function keys(string $authority): array
    {
        $key = $this->cacheKey($authority);
        $cached = Cache::get($key);
        if (is_array($cached)) {
            return $cached;
        }
        try {
            $response = Http::acceptJson()->connectTimeout(10)->timeout(20)->withOptions(OAuthResponseLimit::options())->get('https://login.microsoftonline.com/'.$authority.'/discovery/v2.0/keys');
        } catch (ConnectionException|TransferException $exception) {
            throw new ConnectorApiException('Microsoft signing key service is unavailable.');
        }
        if (! $response->successful() || strlen($response->body()) > 1024 * 1024) {
            throw new ConnectorApiException('Microsoft signing key service returned an invalid response.');
        }
        $json = $response->json();
        if (! is_array($json) || ! is_array($json['keys'] ?? null) || ! array_is_list($json['keys'])) {
            throw new ConnectorApiException('Microsoft signing key service returned malformed keys.');
        }
        foreach ($json['keys'] as $item) {
            if (! is_array($item)) {
                throw new ConnectorApiException('Microsoft signing key service returned malformed keys.');
            }
        }
        Cache::put($key, $json['keys'], now()->addHour());

        return $json['keys'];
    }

    private function cacheKey(string $authority): string
    {
        return 'microsoft-mail:oidc-keys:'.hash('sha256', $authority);
    }

    /** @param list<array<string,mixed>> $keys
     * @return array<string,mixed>|null */
    private function findKey(array $keys, string $kid): ?array
    {
        foreach ($keys as $key) {
            if (($key['kid'] ?? null) === $kid) {
                return $key;
            }
        }

        return null;
    }

    private function decode(string $encoded): string
    {
        if (! preg_match('/^[A-Za-z0-9_-]+$/D', $encoded)) {
            throw new ConnectorAuthException('Microsoft identity token encoding is invalid.');
        }
        $value = base64_decode(strtr($encoded, '-_', '+/'), true);
        if ($value === false) {
            throw new ConnectorAuthException('Microsoft identity token encoding is invalid.');
        }

        return $value;
    }

    private function integer(string $bytes): string
    {
        if ($bytes === '') {
            throw new ConnectorAuthException('Microsoft identity signing key is malformed.');
        }
        $bytes = ltrim($bytes, "\x00");
        if ($bytes === '' || (ord($bytes[0]) & 0x80) !== 0) {
            $bytes = "\x00".$bytes;
        }

        return "\x02".$this->length(strlen($bytes)).$bytes;
    }

    private function sequence(string $bytes): string
    {
        return "\x30".$this->length(strlen($bytes)).$bytes;
    }

    private function length(int $length): string
    {
        if ($length < 128) {
            return chr($length);
        }
        $bytes = '';
        while ($length > 0) {
            $bytes = chr($length & 255).$bytes;
            $length >>= 8;
        }

        return chr(128 | strlen($bytes)).$bytes;
    }
}
