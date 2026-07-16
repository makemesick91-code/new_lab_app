<?php

namespace App\Modules\Satusehat\Gateways;

use App\Modules\Satusehat\Exceptions\SatusehatTokenException;
use App\Modules\Satusehat\Support\SatusehatHostGuard;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * OAuth2 `client_credentials` token provider for the SATUSEHAT sandbox.
 *
 * Security posture (SATUSEHAT-2 §11):
 *   - HTTPS + host allowlist enforced via SatusehatHostGuard before any request.
 *   - TLS verification is ALWAYS on (Http::withoutVerifying is never called).
 *   - The token is cached (Illuminate cache, NOT the DB) keyed by environment +
 *     a non-reversible credential fingerprint, refreshed a buffer before expiry.
 *   - A distributed lock ensures concurrent workers acquire at most one token.
 *   - The token/secret are never logged, never put in an exception message,
 *     never audited, never returned by status().
 */
final class OAuthClientCredentialsSatusehatTokenProvider implements SatusehatTokenProviderInterface
{
    public function __construct(
        private readonly HttpFactory $http,
        private readonly string $environment,
    ) {}

    public function token(): string
    {
        $cached = Cache::get($this->cacheKey());
        if (is_array($cached) && $this->stillValid($cached)) {
            return (string) $cached['access_token'];
        }

        $lock = Cache::lock($this->lockKey(), max(1, (int) config('satusehat.token_lock_seconds', 10)));

        try {
            // block() waits briefly for a peer's in-flight acquisition, then we
            // re-check the cache so only one network call happens per refresh.
            return $lock->block(max(1, (int) config('satusehat.token_lock_seconds', 10)), function () {
                $cached = Cache::get($this->cacheKey());
                if (is_array($cached) && $this->stillValid($cached)) {
                    return (string) $cached['access_token'];
                }

                return $this->acquire();
            });
        } catch (SatusehatTokenException $e) {
            throw $e;
        } catch (Throwable $e) {
            // Lock contention / unexpected error — never leak the underlying message.
            throw new SatusehatTokenException('Gagal memperoleh token SATUSEHAT (lock/timeout).');
        }
    }

    public function forget(): void
    {
        Cache::forget($this->cacheKey());
    }

    public function status(): array
    {
        $cached = Cache::get($this->cacheKey());

        if (! is_array($cached) || ! isset($cached['expires_at'])) {
            return [
                'token_status' => 'unavailable',
                'expires_in_bucket' => 'none',
                'environment' => $this->environment,
            ];
        }

        $remaining = (int) $cached['expires_at'] - now()->getTimestamp();

        return [
            'token_status' => $this->stillValid($cached) ? 'available' : 'expired',
            'expires_in_bucket' => $this->bucket($remaining),
            'environment' => $this->environment,
        ];
    }

    private function acquire(): string
    {
        $baseUrl = (string) config('satusehat.oauth_base_url');
        SatusehatHostGuard::assertAllowedBaseUrl($baseUrl);

        $clientId = (string) config('satusehat.client_id');
        $clientSecret = (string) config('satusehat.client_secret');

        if ($clientId === '' || $clientSecret === '') {
            throw new SatusehatTokenException('Kredensial SATUSEHAT tidak lengkap.');
        }

        try {
            $response = $this->http
                ->asForm()
                ->connectTimeout(max(1, (int) config('satusehat.connect_timeout_seconds', 5)))
                ->timeout(max(1, (int) config('satusehat.timeout_seconds', 15)))
                ->withoutRedirecting()
                ->post(rtrim($baseUrl, '/').'/accesstoken?grant_type=client_credentials', [
                    'client_id' => $clientId,
                    'client_secret' => $clientSecret,
                ]);
        } catch (Throwable $e) {
            // Do NOT include $e->getMessage() — it can echo the request incl. creds.
            Log::warning('satusehat.token.transport_failure', ['environment' => $this->environment]);
            throw new SatusehatTokenException('Permintaan token SATUSEHAT gagal (transport).');
        }

        if (! $response->successful()) {
            Log::warning('satusehat.token.http_error', [
                'environment' => $this->environment,
                'status' => $response->status(),
            ]);
            throw new SatusehatTokenException('Permintaan token SATUSEHAT ditolak (HTTP '.$response->status().').');
        }

        $body = $response->json();
        $accessToken = is_array($body) ? ($body['access_token'] ?? null) : null;
        if (! is_string($accessToken) || $accessToken === '') {
            throw new SatusehatTokenException('Respons token SATUSEHAT tidak valid.');
        }

        // SATUSEHAT returns expires_in as a string of seconds.
        $expiresIn = (int) ($body['expires_in'] ?? 3600);
        $expiresIn = $expiresIn > 0 ? $expiresIn : 3600;

        $buffer = max(0, (int) config('satusehat.token_refresh_buffer_seconds', 60));
        $expiresAt = now()->getTimestamp() + $expiresIn;

        Cache::put($this->cacheKey(), [
            'access_token' => $accessToken,
            'expires_at' => $expiresAt,
        ], now()->addSeconds(max(1, $expiresIn)));

        Log::info('satusehat.token.acquired', [
            'environment' => $this->environment,
            'expires_in_bucket' => $this->bucket($expiresIn - $buffer),
        ]);

        return $accessToken;
    }

    /**
     * @param  array<string, mixed>  $cached
     */
    private function stillValid(array $cached): bool
    {
        if (! isset($cached['access_token'], $cached['expires_at'])) {
            return false;
        }

        $buffer = max(0, (int) config('satusehat.token_refresh_buffer_seconds', 60));

        return ((int) $cached['expires_at'] - $buffer) > now()->getTimestamp();
    }

    private function bucket(int $seconds): string
    {
        return match (true) {
            $seconds <= 0 => 'expired',
            $seconds < 60 => 'lt_1m',
            $seconds < 300 => 'lt_5m',
            $seconds < 1800 => 'lt_30m',
            default => 'gte_30m',
        };
    }

    private function cacheKey(): string
    {
        return "satusehat:token:{$this->environment}:{$this->credentialFingerprint()}";
    }

    private function lockKey(): string
    {
        return "satusehat:token-lock:{$this->environment}:{$this->credentialFingerprint()}";
    }

    /**
     * Non-reversible fingerprint so a credential rotation invalidates the cache
     * without ever storing the secret in the cache key.
     */
    private function credentialFingerprint(): string
    {
        return substr(hash('sha256', (string) config('satusehat.client_id').'|'.(string) config('satusehat.client_secret')), 0, 16);
    }
}
