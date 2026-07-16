<?php

namespace App\Modules\Satusehat\Support;

/**
 * Canonical classification of a single outbound SATUSEHAT attempt. This is the
 * safety spine of SATUSEHAT-2 retry handling.
 *
 *   SUCCESS   — a confirmed 2xx with a parseable resource.
 *   RETRYABLE — a transient failure that is SAFE to retry (server clearly did
 *               not process the request, or an idempotent-safe rate limit).
 *   PERMANENT — a definitive rejection; retrying will never succeed.
 *   UNKNOWN   — the request MAY have been processed but the outcome is
 *               unverifiable (ambiguous timeout/reset after the body was sent).
 *               A blind POST retry is FORBIDDEN — reconciliation is required.
 */
final class SatusehatOutcome
{
    public const SUCCESS = 'success';

    public const RETRYABLE = 'retryable';

    public const PERMANENT = 'permanent';

    public const UNKNOWN = 'unknown';
}
