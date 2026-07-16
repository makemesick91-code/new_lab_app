<?php

namespace App\Modules\Satusehat\Support;

/**
 * Maps HTTP status codes and transport failures to a {@see SatusehatOutcome}.
 * Pure + side-effect-free so it is exhaustively unit-testable.
 *
 * The critical rule (SATUSEHAT-2 §18): a failure that occurs AFTER the request
 * body may have reached the server is UNKNOWN, never blindly RETRYABLE.
 */
final class SatusehatRetryClassifier
{
    /**
     * Classify a completed HTTP response by status code.
     */
    public static function classifyStatus(int $status): string
    {
        if ($status >= 200 && $status < 300) {
            return SatusehatOutcome::SUCCESS;
        }

        return match (true) {
            // Auth token expired mid-flight — caller evicts token and retries once.
            $status === 401 => SatusehatOutcome::RETRYABLE,
            // Rate limited — retry after honoring Retry-After.
            $status === 429 => SatusehatOutcome::RETRYABLE,
            // Transient upstream errors — server did not durably process.
            in_array($status, [502, 503, 504], true) => SatusehatOutcome::RETRYABLE,
            // 500 is ambiguous: the server may have partially processed. Treat as
            // UNKNOWN so we reconcile instead of blindly re-POSTing.
            $status === 500 => SatusehatOutcome::UNKNOWN,
            // Definitive client-side rejections.
            in_array($status, [400, 403, 404, 409, 422], true) => SatusehatOutcome::PERMANENT,
            // Other 4xx are permanent; other 5xx are unknown (may have processed).
            $status >= 400 && $status < 500 => SatusehatOutcome::PERMANENT,
            default => SatusehatOutcome::UNKNOWN,
        };
    }

    /**
     * Classify a transport-level failure (connection error / timeout).
     *
     * @param  bool  $requestPossiblySent  true when the request body may already
     *                                     have reached the server (read timeout,
     *                                     reset after send). false only when the
     *                                     failure is provably before send
     *                                     (connect timeout / DNS / TLS handshake).
     */
    public static function classifyTransportFailure(bool $requestPossiblySent): string
    {
        return $requestPossiblySent
            ? SatusehatOutcome::UNKNOWN
            : SatusehatOutcome::RETRYABLE;
    }

    public static function isRetryable(string $outcome): bool
    {
        return $outcome === SatusehatOutcome::RETRYABLE;
    }

    public static function requiresReconciliation(string $outcome): bool
    {
        return $outcome === SatusehatOutcome::UNKNOWN;
    }
}
