<?php

namespace App\Modules\Satusehat\Gateways;

use App\Modules\Satusehat\Exceptions\SatusehatGatewayException;
use App\Modules\Satusehat\Exceptions\SatusehatTokenException;
use App\Modules\Satusehat\Support\SatusehatCircuitBreaker;
use App\Modules\Satusehat\Support\SatusehatHostGuard;
use App\Modules\Satusehat\Support\SatusehatOutcome;
use App\Modules\Satusehat\Support\SatusehatRetryClassifier;
use App\Support\DeveloperConsole\SensitiveValueMasker;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * SATUSEHAT-2 real sandbox HTTP adapter. Every request is:
 *   - Guarded by {@see SatusehatHostGuard} (HTTPS + host allowlist + sandbox lock).
 *   - Gated by the send kill switch and the circuit breaker.
 *   - Authenticated with a bearer token from the token provider (never logged).
 *   - Executed with explicit connect/response timeouts and NO redirect following.
 *   - Returned as a sanitized {@see SatusehatApiResponse} — outcome-classified,
 *     never carrying the token, raw body, patient payload, or NIK.
 *
 * TLS verification is ALWAYS on. Http::withoutVerifying() is never called.
 */
final class HttpSatusehatGateway implements SatusehatGatewayInterface
{
    private readonly SatusehatCircuitBreaker $breaker;

    public function __construct(
        private readonly string $environment = 'sandbox',
        private readonly ?SatusehatTokenProviderInterface $tokenProvider = null,
        private readonly ?HttpFactory $http = null,
        private readonly ?SensitiveValueMasker $masker = null,
    ) {
        $this->breaker = new SatusehatCircuitBreaker($this->environment);
    }

    public function isEnabled(): bool
    {
        return (bool) config('satusehat.enabled')
            && (bool) config('satusehat.send_enabled')
            && $this->tokenProvider !== null;
    }

    public function environment(): string
    {
        return $this->environment;
    }

    public function assertReadyForSubmission(): void
    {
        $this->assertSendAllowed();
    }

    public function assertSendAllowed(): void
    {
        if (! (bool) config('satusehat.enabled')) {
            throw new SatusehatGatewayException('Integrasi SATUSEHAT nonaktif.', SatusehatOutcome::PERMANENT);
        }

        if (! (bool) config('satusehat.send_enabled')) {
            throw new SatusehatGatewayException('Kill switch pengiriman SATUSEHAT aktif (send_enabled=false).', SatusehatOutcome::PERMANENT);
        }

        // Sandbox lock + host allowlist — throws on any production/SSRF attempt.
        SatusehatHostGuard::assertAllowedBaseUrl((string) config('satusehat.fhir_base_url'));

        if (! $this->breaker->allowsRequest()) {
            throw new SatusehatGatewayException('Circuit breaker SATUSEHAT terbuka — pengiriman ditunda.', SatusehatOutcome::RETRYABLE);
        }
    }

    public function createResource(string $resourceType, array $payload, string $correlationId): SatusehatApiResponse
    {
        return $this->perform('POST', '/'.$resourceType, $payload, $resourceType, $correlationId);
    }

    public function updateResource(string $resourceType, string $remoteId, array $payload, string $correlationId): SatusehatApiResponse
    {
        return $this->perform('PUT', '/'.$resourceType.'/'.rawurlencode($remoteId), $payload, $resourceType, $correlationId);
    }

    public function getResource(string $resourceType, string $remoteId, string $correlationId): SatusehatApiResponse
    {
        return $this->perform('GET', '/'.$resourceType.'/'.rawurlencode($remoteId), null, $resourceType, $correlationId);
    }

    public function verifyIdentifier(string $entityType, array $searchParams, string $correlationId): SatusehatApiResponse
    {
        // Defence-in-depth: never let a NIK-like value (13+ digit run) reach a
        // request URL/log. The real verification path uses getResource() by IHS
        // id; this guard stops a future caller from putting a NIK in a query.
        foreach ($searchParams as $value) {
            if (is_string($value) && preg_match('/\d{13,}/', $value) === 1) {
                return SatusehatApiResponse::permanent('Parameter pencarian tidak boleh memuat NIK.', null, $correlationId);
            }
        }

        $query = http_build_query($searchParams);

        return $this->perform('GET', '/'.$entityType.($query !== '' ? '?'.$query : ''), null, $entityType, $correlationId);
    }

    public function connectionStatus(): array
    {
        $status = [
            'gateway' => 'http',
            'environment' => $this->environment,
            'send_enabled' => config('satusehat.send_enabled') ? 'true' : 'false',
            'circuit_breaker' => $this->breaker->state(),
        ];

        try {
            SatusehatHostGuard::assertAllowedBaseUrl((string) config('satusehat.fhir_base_url'));
            $status['host_allowed'] = 'true';
        } catch (Throwable) {
            $status['host_allowed'] = 'false';
        }

        if ($this->tokenProvider !== null) {
            $status = array_merge($status, $this->tokenProvider->status());
        }

        return $status;
    }

    // ------------------------------------------------------------------
    // Legacy SATUSEHAT-1 methods — kept for back-compat; route through the new
    // typed path or fail closed.
    // ------------------------------------------------------------------

    public function submitResource(string $resourceType, array $payload, string $idempotencyKey): SatusehatGatewayResult
    {
        $response = $this->createResource($resourceType, $payload, $idempotencyKey);

        return $response->isSuccess()
            ? SatusehatGatewayResult::ok('submitted', ['remote_resource_id' => $response->remoteResourceId])
            : SatusehatGatewayResult::blocked($response->message);
    }

    public function lookupPatientIdentifier(string $localIdentifier): SatusehatGatewayResult
    {
        $response = $this->verifyIdentifier('Patient', ['identifier' => $localIdentifier], 'legacy-lookup');

        return $response->isSuccess()
            ? SatusehatGatewayResult::ok('verified', ['remote_resource_id' => $response->remoteResourceId])
            : SatusehatGatewayResult::blocked($response->message);
    }

    // ------------------------------------------------------------------

    /**
     * @param  array<string, mixed>|null  $body
     */
    private function perform(string $method, string $path, ?array $body, string $resourceType, string $correlationId): SatusehatApiResponse
    {
        $this->assertSendAllowed();

        $baseUrl = (string) config('satusehat.fhir_base_url');
        SatusehatHostGuard::assertAllowedBaseUrl($baseUrl);
        $url = rtrim($baseUrl, '/').$path;

        $http = $this->http ?? app(HttpFactory::class);
        $isWrite = in_array($method, ['POST', 'PUT'], true);

        // At most two attempts: the retry exists solely to recover from a single
        // 401 (expired token). It is NEVER a blind retry of an ambiguous write.
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            try {
                $token = $this->tokenProvider?->token() ?? throw new SatusehatTokenException('Token provider tidak tersedia.');
            } catch (SatusehatTokenException) {
                $this->breaker->recordFailure();

                return SatusehatApiResponse::retryable('Gagal memperoleh token SATUSEHAT.', 401, $correlationId);
            }

            try {
                $request = $http
                    ->withToken($token)
                    ->acceptJson()
                    ->connectTimeout(max(1, (int) config('satusehat.connect_timeout_seconds', 5)))
                    ->timeout(max(1, (int) config('satusehat.timeout_seconds', 15)))
                    ->withoutRedirecting()
                    ->withHeaders(['X-Correlation-ID' => $correlationId]);

                /** @var Response $response */
                $response = match ($method) {
                    'GET' => $request->get($url),
                    'PUT' => $request->asJson()->put($url, $body ?? []),
                    default => $request->asJson()->post($url, $body ?? []),
                };
            } catch (ConnectionException) {
                $this->breaker->recordFailure();
                Log::warning('satusehat.gateway.transport_failure', $this->logContext($resourceType, $method, $correlationId, null));

                // Conservative: a write whose fate is unverifiable → UNKNOWN
                // (never a blind POST retry). An idempotent GET is safe to retry.
                return $isWrite
                    ? SatusehatApiResponse::unknown('Kegagalan transport setelah kemungkinan terkirim — perlu rekonsiliasi.', null, $correlationId)
                    : SatusehatApiResponse::retryable('Kegagalan transport sebelum terkirim.', null, $correlationId);
            } catch (Throwable) {
                $this->breaker->recordFailure();
                Log::warning('satusehat.gateway.unexpected_failure', $this->logContext($resourceType, $method, $correlationId, null));

                return $isWrite
                    ? SatusehatApiResponse::unknown('Kegagalan tak terduga — perlu rekonsiliasi.', null, $correlationId)
                    : SatusehatApiResponse::retryable('Kegagalan tak terduga.', null, $correlationId);
            }

            // Oversized/hostile response guard — reject before decoding.
            $max = max(1024, (int) config('satusehat.max_response_bytes', 1048576));
            if (strlen((string) $response->body()) > $max) {
                $this->breaker->recordSuccess();

                return SatusehatApiResponse::permanent('Respons SATUSEHAT melebihi batas ukuran.', $response->status(), $correlationId);
            }

            // 401 handling: evict + retry exactly once; a second 401 after the
            // refresh is a hard auth/config failure (not an endless retry loop).
            if ($response->status() === 401) {
                if ($attempt === 1) {
                    $this->tokenProvider?->forget();

                    continue;
                }

                $this->breaker->recordFailure();

                return SatusehatApiResponse::permanent('Autentikasi SATUSEHAT gagal setelah refresh token.', 401, $correlationId);
            }

            return $this->interpret($response, $resourceType, $method, $correlationId);
        }

        // Unreachable in practice (the loop returns on every path); a defensive
        // permanent fallback so the method always returns.
        return SatusehatApiResponse::permanent('Autentikasi SATUSEHAT gagal.', 401, $correlationId);
    }

    private function interpret(Response $response, string $resourceType, string $method, string $correlationId): SatusehatApiResponse
    {
        $status = $response->status();
        $outcome = SatusehatRetryClassifier::classifyStatus($status);

        // Circuit-breaker health: 5xx / unknown = server unhealthy; a clean 2xx
        // or a client-side 4xx means the server is responding normally.
        if ($status >= 500 || $outcome === SatusehatOutcome::UNKNOWN) {
            $this->breaker->recordFailure();
        } else {
            $this->breaker->recordSuccess();
        }

        if ($outcome === SatusehatOutcome::SUCCESS) {
            $body = $response->json();
            $remoteId = is_array($body) ? ($body['id'] ?? null) : null;
            $versionId = is_array($body) ? ($body['meta']['versionId'] ?? null) : null;

            if (! is_string($remoteId) || $remoteId === '') {
                Log::warning('satusehat.gateway.success_without_id', $this->logContext($resourceType, $method, $correlationId, $status));

                return SatusehatApiResponse::unknown('Respons sukses tanpa id resource — perlu rekonsiliasi.', $status, $correlationId);
            }

            Log::info('satusehat.gateway.success', $this->logContext($resourceType, $method, $correlationId, $status));

            return SatusehatApiResponse::success($resourceType, $remoteId, is_string($versionId) ? $versionId : null, $status, $correlationId);
        }

        $issues = $this->parseOperationOutcome($response);
        $retryAfter = $status === 429 ? $this->retryAfterSeconds($response) : null;

        Log::warning('satusehat.gateway.error', $this->logContext($resourceType, $method, $correlationId, $status) + ['outcome' => $outcome]);

        return match ($outcome) {
            SatusehatOutcome::RETRYABLE => SatusehatApiResponse::retryable('Kegagalan sementara SATUSEHAT.', $status, $correlationId, $retryAfter, $issues),
            SatusehatOutcome::PERMANENT => SatusehatApiResponse::permanent('Ditolak SATUSEHAT (permanen).', $status, $correlationId, $issues),
            default => SatusehatApiResponse::unknown('Hasil SATUSEHAT tidak dapat dipastikan — perlu rekonsiliasi.', $status, $correlationId),
        };
    }

    /**
     * @return list<array{severity: string, code: string, diagnostics: string}>
     */
    private function parseOperationOutcome(Response $response): array
    {
        $body = $response->json();
        if (! is_array($body) || ($body['resourceType'] ?? null) !== 'OperationOutcome') {
            return [];
        }

        $masker = $this->masker ?? app(SensitiveValueMasker::class);
        $issues = [];

        foreach (array_slice((array) ($body['issue'] ?? []), 0, 10) as $issue) {
            if (! is_array($issue)) {
                continue;
            }
            $diagnostics = (string) ($issue['diagnostics'] ?? '');
            $issues[] = [
                'severity' => substr((string) ($issue['severity'] ?? 'error'), 0, 40),
                'code' => substr((string) ($issue['code'] ?? 'unknown'), 0, 60),
                'diagnostics' => substr($masker->mask($diagnostics), 0, 300),
            ];
        }

        return $issues;
    }

    private function retryAfterSeconds(Response $response): ?int
    {
        $header = $response->header('Retry-After');
        if ($header === '') {
            return null;
        }

        $cap = max(1, (int) config('satusehat.retry.retry_after_cap_seconds', 600));

        if (is_numeric($header)) {
            return min($cap, max(1, (int) $header));
        }

        $timestamp = strtotime($header);
        if ($timestamp === false) {
            return null;
        }

        return min($cap, max(1, $timestamp - now()->getTimestamp()));
    }

    /**
     * @return array<string, mixed>
     */
    private function logContext(string $resourceType, string $method, string $correlationId, ?int $status): array
    {
        // Deliberately excludes URL query, body, token, and any patient data.
        return [
            'environment' => $this->environment,
            'resource_type' => $resourceType,
            'method' => $method,
            'correlation_id' => $correlationId,
            'status' => $status,
        ];
    }
}
