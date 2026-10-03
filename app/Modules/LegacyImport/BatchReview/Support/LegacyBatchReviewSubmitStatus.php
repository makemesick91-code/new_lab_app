<?php

declare(strict_types=1);

namespace App\Modules\LegacyImport\BatchReview\Support;

/**
 * What the SERVER did with one attested decision — PR1 §3, §7, §16.
 *
 * The distinction that matters is REFUSED vs SKIPPED, and it mirrors the
 * BLOCKED/FAILED distinction the mass-upload vocabulary already draws:
 *
 *   REFUSED — the canonical review path said no. Separation of duties, a stale
 *             transition, missing rendered pages, a drifted source binding. The
 *             domain is working; this item simply must not be reviewed by this
 *             actor right now. Carries a stable code.
 *
 *   SKIPPED — nothing was decided about it. A triage decision performs no
 *             canonical write by design, and an interrupted submit leaves
 *             untouched rows behind. Collapsing it into REFUSED would make a
 *             healthy session look broken.
 *
 * PENDING is what makes a session resumable: a browser that disconnects mid
 * submit leaves applied rows APPLIED and the rest PENDING, and re-submitting
 * re-attempts only what is not yet APPLIED (§16).
 */
final class LegacyBatchReviewSubmitStatus
{
    private function __construct() {}

    /** Attested, not yet carried to the canonical path. */
    public const PENDING = 'PENDING';

    /** The canonical review ran and the import is REVIEWED. */
    public const APPLIED = 'APPLIED';

    /** The canonical review path refused. Stable code recorded. */
    public const REFUSED = 'REFUSED';

    /** Intentionally not carried — triage decisions, or an interrupted pass. */
    public const SKIPPED = 'SKIPPED';

    /** @return list<string> */
    public static function all(): array
    {
        return [self::PENDING, self::APPLIED, self::REFUSED, self::SKIPPED];
    }

    public static function isValid(?string $status): bool
    {
        return $status !== null && in_array($status, self::all(), true);
    }

    /**
     * Statuses a resumed submit may re-attempt. APPLIED is absent: the canonical
     * review is idempotent, but re-walking a done row would still inflate the
     * session's applied count and write a second audit line for one attestation.
     *
     * @return list<string>
     */
    public static function retryable(): array
    {
        return [self::PENDING, self::REFUSED];
    }

    public static function isRetryable(?string $status): bool
    {
        return $status !== null && in_array($status, self::retryable(), true);
    }

    public static function label(?string $status): string
    {
        return match ($status) {
            self::PENDING => 'Menunggu dikirim',
            self::APPLIED => 'Berhasil diterapkan',
            self::REFUSED => 'Ditolak sistem',
            self::SKIPPED => 'Tidak diproses',
            default => (string) $status,
        };
    }
}
