<?php

declare(strict_types=1);

namespace App\Modules\RmeInvoice\Support;

/**
 * FEATURE-RME-INVOICE-CORRECTION-RECEIPT-FOUNDATION-1 — the correction state machine.
 *
 *   (none) ──submit──▶ SUBMITTED ──approve──▶ APPROVED ──apply──▶ APPLIED
 *                        │  │  │                 │  │
 *                        │  │  └─reject─▶ REJECTED │  └─revalidation fails─▶ STALE
 *                        │  └─cancel─▶ CANCELLED ◀─┘ cancel (maker, before apply)
 *                        └─revalidation fails─▶ STALE
 *
 * - SUBMITTED is the "pending approval" state. There is no DRAFT: a request only
 *   exists once it is submitted, so no half-written correction can linger.
 * - APPROVED never means the invoice changed. Only APPLIED does.
 * - STALE is how a correction is superseded: the invoice it was reviewed against
 *   changed (a payment arrived, another correction was applied). It is terminal;
 *   the cashier files a fresh request against the current document.
 * - REJECTED, CANCELLED, STALE and APPLIED are terminal. Nothing leaves them.
 * - Repeating a transition into the state the row is already in is an
 *   idempotent no-op in the service (replay-safe), never a second transition.
 */
final class RmeInvoiceCorrectionStatus
{
    public const SUBMITTED = 'SUBMITTED';

    public const APPROVED = 'APPROVED';

    public const REJECTED = 'REJECTED';

    public const CANCELLED = 'CANCELLED';

    public const STALE = 'STALE';

    public const APPLIED = 'APPLIED';

    public const ALL = [
        self::SUBMITTED,
        self::APPROVED,
        self::REJECTED,
        self::CANCELLED,
        self::STALE,
        self::APPLIED,
    ];

    /** At most one correction per invoice may be in these (partial unique index). */
    public const ACTIVE = [self::SUBMITTED, self::APPROVED];

    public const TERMINAL = [self::REJECTED, self::CANCELLED, self::STALE, self::APPLIED];

    /** @var array<string, list<string>> */
    public const TRANSITIONS = [
        self::SUBMITTED => [self::APPROVED, self::REJECTED, self::CANCELLED, self::STALE],
        self::APPROVED => [self::APPLIED, self::CANCELLED, self::STALE],
        self::REJECTED => [],
        self::CANCELLED => [],
        self::STALE => [],
        self::APPLIED => [],
    ];

    public const LABELS = [
        self::SUBMITTED => 'Menunggu Persetujuan',
        self::APPROVED => 'Disetujui — Belum Diterapkan',
        self::REJECTED => 'Ditolak',
        self::CANCELLED => 'Dibatalkan',
        self::STALE => 'Kedaluwarsa (Tagihan Berubah)',
        self::APPLIED => 'Diterapkan',
    ];

    public const TONES = [
        self::SUBMITTED => 'warning',
        self::APPROVED => 'info',
        self::REJECTED => 'danger',
        self::CANCELLED => 'neutral',
        self::STALE => 'neutral',
        self::APPLIED => 'success',
    ];

    public static function canTransition(?string $from, string $to): bool
    {
        if ($from === null) {
            return $to === self::SUBMITTED;
        }

        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }

    public static function isActive(string $status): bool
    {
        return in_array($status, self::ACTIVE, true);
    }

    public static function isTerminal(string $status): bool
    {
        return in_array($status, self::TERMINAL, true);
    }

    public static function label(string $status): string
    {
        return self::LABELS[$status] ?? $status;
    }

    public static function tone(string $status): string
    {
        return self::TONES[$status] ?? 'neutral';
    }
}
