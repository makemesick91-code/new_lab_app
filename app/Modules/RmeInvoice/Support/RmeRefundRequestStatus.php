<?php

declare(strict_types=1);

namespace App\Modules\RmeInvoice\Support;

/**
 * FEATURE-RME-BILLING-REFUND-REQUEST-APPROVAL-1 (rule 215) — the Sprint 4 state
 * machine of a refund request (design §3). APPROVED is the waiting state Sprint 5
 * settlement consumes; it never means money was paid out.
 */
final class RmeRefundRequestStatus
{
    public const REQUESTED = 'REQUESTED';

    public const APPROVED = 'APPROVED';

    public const REJECTED = 'REJECTED';

    public const CANCELLED = 'CANCELLED';

    public const STALE = 'STALE';

    public const ALL = [self::REQUESTED, self::APPROVED, self::REJECTED, self::CANCELLED, self::STALE];

    /** Holds the obligation: at most one per obligation (partial UNIQUE backstop). */
    public const ACTIVE = [self::REQUESTED, self::APPROVED];

    public const TERMINAL = [self::REJECTED, self::CANCELLED, self::STALE];

    public const TRANSITIONS = [
        self::REQUESTED => [self::APPROVED, self::REJECTED, self::CANCELLED, self::STALE],
        self::APPROVED => [self::CANCELLED, self::STALE],
        self::REJECTED => [],
        self::CANCELLED => [],
        self::STALE => [],
    ];

    public const LABELS = [
        self::REQUESTED => 'Menunggu Persetujuan',
        self::APPROVED => 'Disetujui — Belum Dibayarkan',
        self::REJECTED => 'Ditolak',
        self::CANCELLED => 'Dibatalkan',
        self::STALE => 'Kedaluwarsa',
    ];

    public const TONES = [
        self::REQUESTED => 'warning',
        self::APPROVED => 'info',
        self::REJECTED => 'danger',
        self::CANCELLED => 'neutral',
        self::STALE => 'neutral',
    ];

    public static function canTransition(string $from, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }

    public static function label(?string $status): string
    {
        return self::LABELS[$status] ?? (string) $status;
    }
}
