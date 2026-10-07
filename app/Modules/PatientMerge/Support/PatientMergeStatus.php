<?php

namespace App\Modules\PatientMerge\Support;

/**
 * FEATURE-PATIENT-DUPLICATE-RESOLUTION-MERGE-1 — merge case lifecycle.
 *
 *   draft ──submit──▶ pending_review ──approve──▶ approved ─▶ merging ─▶ completed
 *     │                    │                                               │
 *     └──cancel──▶ cancelled   └──reject──▶ rejected        request reversal
 *                                                                        ▼
 *                                         reversed ◀──safe── reversal_required
 *
 * `approved` and `merging` exist only INSIDE the merge transaction: the
 * approval and the merge commit together or not at all, so no case can sit
 * approved-but-unmerged while the identities it was approved against drift.
 */
final class PatientMergeStatus
{
    public const DRAFT = 'draft';

    public const PENDING_REVIEW = 'pending_review';

    public const APPROVED = 'approved';

    public const MERGING = 'merging';

    public const COMPLETED = 'completed';

    public const REJECTED = 'rejected';

    public const CANCELLED = 'cancelled';

    public const REVERSAL_REQUIRED = 'reversal_required';

    public const REVERSED = 'reversed';

    public const ALL = [
        self::DRAFT, self::PENDING_REVIEW, self::APPROVED, self::MERGING, self::COMPLETED,
        self::REJECTED, self::CANCELLED, self::REVERSAL_REQUIRED, self::REVERSED,
    ];

    /** A case in one of these states still claims its two patients. */
    public const OPEN = [self::DRAFT, self::PENDING_REVIEW, self::APPROVED, self::MERGING];

    public const HISTORY = [self::COMPLETED, self::REJECTED, self::CANCELLED, self::REVERSAL_REQUIRED, self::REVERSED];

    public const TRANSITIONS = [
        self::DRAFT => [self::PENDING_REVIEW, self::CANCELLED],
        self::PENDING_REVIEW => [self::APPROVED, self::REJECTED, self::CANCELLED, self::DRAFT],
        self::APPROVED => [self::MERGING],
        self::MERGING => [self::COMPLETED],
        self::COMPLETED => [self::REVERSAL_REQUIRED],
        self::REVERSAL_REQUIRED => [self::REVERSED, self::COMPLETED],
        self::REJECTED => [],
        self::CANCELLED => [],
        self::REVERSED => [],
    ];

    public const LABELS = [
        self::DRAFT => 'Draf',
        self::PENDING_REVIEW => 'Menunggu Review',
        self::APPROVED => 'Disetujui',
        self::MERGING => 'Sedang Digabungkan',
        self::COMPLETED => 'Selesai Digabungkan',
        self::REJECTED => 'Ditolak',
        self::CANCELLED => 'Dibatalkan',
        self::REVERSAL_REQUIRED => 'Review Reversal',
        self::REVERSED => 'Dibatalkan (Reversal)',
    ];

    public static function canTransition(string $from, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }

    public static function label(string $status): string
    {
        return self::LABELS[$status] ?? $status;
    }

    public static function tone(string $status): string
    {
        return match ($status) {
            self::COMPLETED => 'success',
            self::PENDING_REVIEW, self::REVERSAL_REQUIRED => 'warning',
            self::REJECTED => 'danger',
            self::DRAFT, self::APPROVED, self::MERGING => 'info',
            default => 'neutral',
        };
    }
}
