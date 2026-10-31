<?php

declare(strict_types=1);

namespace App\Modules\RmeInvoice\Support;

/**
 * FEATURE-RME-REFUND-SETTLEMENT-1 (Sprint 5/7, rule 216) — the state machine of a
 * refund settlement. It separates the facts the clinic must never confuse:
 *
 *   approval (the request, Sprint 4)  ≠  a claimed payout (DISBURSING)
 *   ≠  an unverified result (OUTCOME_UNKNOWN)  ≠  verified money out (SETTLED)
 *
 * Only SETTLED reduces net received. ABORTED means the settler attested that no
 * money left; it releases the request for a fresh attempt. An unknown result is
 * never retried automatically: it is reconciled, with evidence, to SETTLED or
 * ABORTED.
 */
final class RmeRefundSettlementStatus
{
    public const DISBURSING = 'DISBURSING';

    public const OUTCOME_UNKNOWN = 'OUTCOME_UNKNOWN';

    public const SETTLED = 'SETTLED';

    public const ABORTED = 'ABORTED';

    public const ALL = [self::DISBURSING, self::OUTCOME_UNKNOWN, self::SETTLED, self::ABORTED];

    /** Money may be in flight: holds the request, blocks revocation and any second attempt. */
    public const ACTIVE = [self::DISBURSING, self::OUTCOME_UNKNOWN];

    /** Holds the request's single settlement slot (partial UNIQUE backstop: status <> ABORTED). */
    public const HOLDING = [self::DISBURSING, self::OUTCOME_UNKNOWN, self::SETTLED];

    public const TERMINAL = [self::SETTLED, self::ABORTED];

    public const TRANSITIONS = [
        self::DISBURSING => [self::SETTLED, self::OUTCOME_UNKNOWN, self::ABORTED],
        self::OUTCOME_UNKNOWN => [self::SETTLED, self::ABORTED],
        self::SETTLED => [],
        self::ABORTED => [],
    ];

    public const LABELS = [
        self::DISBURSING => 'Sedang Dibayarkan — Belum Terverifikasi',
        self::OUTCOME_UNKNOWN => 'Hasil Pembayaran Tidak Pasti — Perlu Rekonsiliasi',
        self::SETTLED => 'Refund Telah Dibayarkan',
        self::ABORTED => 'Dibatalkan — Uang Tidak Keluar',
    ];

    public const TONES = [
        self::DISBURSING => 'warning',
        self::OUTCOME_UNKNOWN => 'danger',
        self::SETTLED => 'success',
        self::ABORTED => 'neutral',
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
