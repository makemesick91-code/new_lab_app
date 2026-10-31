<?php

declare(strict_types=1);

namespace App\Modules\RmeInvoice\Support;

/**
 * FEATURE-RME-BILLING-REFUND-REQUEST-APPROVAL-1 (rule 215) — the Sprint 4 state
 * machine of a refund request (design §3). APPROVED is the waiting state Sprint 5
 * settlement consumes; it never means money was paid out.
 *
 * FEATURE-RME-REFUND-SETTLEMENT-1 (rule 216) adds SETTLED: written only by the
 * settlement transaction, together with the settlement row and the obligation, once
 * the payout was verified. A SETTLED request is terminal and can no longer be revoked.
 */
final class RmeRefundRequestStatus
{
    public const REQUESTED = 'REQUESTED';

    public const APPROVED = 'APPROVED';

    public const REJECTED = 'REJECTED';

    public const CANCELLED = 'CANCELLED';

    public const STALE = 'STALE';

    /** FEATURE-RME-REFUND-SETTLEMENT-1: the approved refund was verified as paid out. */
    public const SETTLED = 'SETTLED';

    public const ALL = [self::REQUESTED, self::APPROVED, self::REJECTED, self::CANCELLED, self::STALE, self::SETTLED];

    /** Holds the obligation: at most one per obligation (partial UNIQUE backstop). */
    public const ACTIVE = [self::REQUESTED, self::APPROVED];

    public const TERMINAL = [self::REJECTED, self::CANCELLED, self::STALE, self::SETTLED];

    public const TRANSITIONS = [
        self::REQUESTED => [self::APPROVED, self::REJECTED, self::CANCELLED, self::STALE],
        self::APPROVED => [self::CANCELLED, self::STALE, self::SETTLED],
        self::REJECTED => [],
        self::CANCELLED => [],
        self::STALE => [],
        self::SETTLED => [],
    ];

    public const LABELS = [
        self::REQUESTED => 'Menunggu Persetujuan',
        self::APPROVED => 'Disetujui — Belum Dibayarkan',
        self::REJECTED => 'Ditolak',
        self::CANCELLED => 'Dibatalkan',
        self::STALE => 'Kedaluwarsa',
        self::SETTLED => 'Refund Telah Dibayarkan',
    ];

    public const TONES = [
        self::REQUESTED => 'warning',
        self::APPROVED => 'info',
        self::REJECTED => 'danger',
        self::CANCELLED => 'neutral',
        self::STALE => 'neutral',
        self::SETTLED => 'success',
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
