<?php

declare(strict_types=1);

namespace App\Modules\LegacyImport\MassUpload\Support;

/**
 * Per-item outcome for FEATURE-LEGACY-RME-ODONTOGRAM-MASS-UPLOAD-1.
 *
 * The distinction that matters most here is BLOCKED vs FAILED.
 *
 *   BLOCKED — the domain refused this row, correctly. The patient already has
 *             a published document, or an import is already in flight, or the
 *             date is outside the native-RME boundary. Nothing is wrong with
 *             the system; the archive simply contains a row that must not
 *             become a second lifecycle. A blocked item NEVER enqueues (§18).
 *
 *   FAILED  — we tried and something broke technically. Retry is meaningful.
 *
 * Collapsing them would make a healthy migration look broken and, worse, would
 * invite a "retry all failures" button that re-attempts rows the domain already
 * refused on clinical grounds.
 *
 * ELIGIBLE is a PREFLIGHT verdict, not a promise. It means "the server would
 * accept this right now". Between preflight and creation a single upload may
 * publish for the same patient, so the canonical service re-decides under a
 * lock at creation time and an ELIGIBLE item can still end up BLOCKED (§15).
 * That is the design working, not a bug.
 */
final class LegacyMassUploadItemStatus
{
    /** Parsed from the manifest; not yet evaluated. */
    public const PENDING = 'PENDING';

    /** Preflight says the server would accept this row now. */
    public const ELIGIBLE = 'ELIGIBLE';

    /**
     * Acceptable, but with something the operator should see first — for
     * example a manifest RM that needed normalizing to match. Warnings are
     * dispatchable; they exist so "look at this" does not have to mean "refuse
     * this".
     */
    public const WARNING = 'WARNING';

    /** Domain refused it. Terminal for this batch. Never enqueued. */
    public const BLOCKED = 'BLOCKED';

    /** A canonical import was created and its render job dispatched. */
    public const DISPATCHED = 'DISPATCHED';

    /** Technical failure during creation. No canonical import exists. */
    public const FAILED = 'FAILED';

    /**
     * Not attempted because the batch stopped first (cancelled, or a bounded
     * pass ended). Distinct from BLOCKED: nothing was decided about this row.
     */
    public const SKIPPED = 'SKIPPED';

    /** @return list<string> */
    public static function all(): array
    {
        return [
            self::PENDING,
            self::ELIGIBLE,
            self::WARNING,
            self::BLOCKED,
            self::DISPATCHED,
            self::FAILED,
            self::SKIPPED,
        ];
    }

    public static function isValid(string $status): bool
    {
        return in_array($status, self::all(), true);
    }

    /**
     * The only statuses a confirm may act on. Note BLOCKED is absent: an
     * operator confirmation can never override a domain refusal (§19).
     *
     * @return list<string>
     */
    public static function dispatchable(): array
    {
        return [self::ELIGIBLE, self::WARNING];
    }

    public static function isDispatchable(string $status): bool
    {
        return in_array($status, self::dispatchable(), true);
    }

    /**
     * Statuses shown under the operator's "needs attention" filter.
     *
     * @return list<string>
     */
    public static function attentionStatuses(): array
    {
        return [self::BLOCKED, self::FAILED];
    }

    public static function label(string $status): string
    {
        return match ($status) {
            self::PENDING => 'Belum dievaluasi',
            self::ELIGIBLE => 'Layak',
            self::WARNING => 'Layak dengan catatan',
            self::BLOCKED => 'Ditolak',
            self::DISPATCHED => 'Diproses',
            self::FAILED => 'Gagal teknis',
            self::SKIPPED => 'Tidak diproses',
            default => $status,
        };
    }
}
