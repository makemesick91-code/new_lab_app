<?php

declare(strict_types=1);

namespace App\Modules\RmeInvoice\Support;

/**
 * IMPLEMENTATION-RME-BILLING-DISPUTE-ESCALATION-OPS-OQ-1 (owner OPS-OQ-1, rule
 * 203) — the billing dispute state machine.
 *
 *     (report)        ──►  OPEN
 *     OPEN            ──►  UNDER_REVIEW  (branch authority took it)
 *     OPEN|UNDER_REVIEW ─► ESCALATED     (needs the Owner's case-specific decision)
 *     OPEN|UNDER_REVIEW|ESCALATED ─► RESOLVED (verified, authorized resolution)
 *
 * - OPEN, UNDER_REVIEW and ESCALATED are ACTIVE: the invoice accepts NO payment
 *   on any path while one of them exists (partial unique index: at most one per
 *   invoice).
 * - RESOLVED is terminal. Nothing leaves it. A new problem with the same invoice
 *   is a NEW dispute; the resolved one stays as history.
 * - There is deliberately NO "closed without resolution", "withdrawn" or
 *   "cancelled" state: the only way to release the hold is a RESOLUTION whose
 *   outcome the service can verify (RmeBillingDisputeOutcome). A rejected or
 *   cancelled correction is NOT a resolution — the error it tried to fix may
 *   still be there.
 */
final class RmeBillingDisputeStatus
{
    public const OPEN = 'OPEN';

    public const UNDER_REVIEW = 'UNDER_REVIEW';

    public const ESCALATED = 'ESCALATED';

    public const RESOLVED = 'RESOLVED';

    public const ALL = [self::OPEN, self::UNDER_REVIEW, self::ESCALATED, self::RESOLVED];

    /** At most one dispute per invoice may be in these (partial unique index); each one HOLDS payment. */
    public const ACTIVE = [self::OPEN, self::UNDER_REVIEW, self::ESCALATED];

    public const TERMINAL = [self::RESOLVED];

    /** @var array<string, list<string>> */
    public const TRANSITIONS = [
        self::OPEN => [self::UNDER_REVIEW, self::ESCALATED, self::RESOLVED],
        self::UNDER_REVIEW => [self::ESCALATED, self::RESOLVED],
        self::ESCALATED => [self::RESOLVED],
        self::RESOLVED => [],
    ];

    public const LABELS = [
        self::OPEN => 'Dilaporkan — Menunggu Tinjauan Cabang',
        self::UNDER_REVIEW => 'Sedang Ditinjau',
        self::ESCALATED => 'Dieskalasi ke Owner',
        self::RESOLVED => 'Selesai',
    ];

    public const TONES = [
        self::OPEN => 'warning',
        self::UNDER_REVIEW => 'info',
        self::ESCALATED => 'danger',
        self::RESOLVED => 'success',
    ];

    public static function canTransition(?string $from, string $to): bool
    {
        if ($from === null) {
            return $to === self::OPEN;
        }

        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }

    public static function isActive(string $status): bool
    {
        return in_array($status, self::ACTIVE, true);
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
