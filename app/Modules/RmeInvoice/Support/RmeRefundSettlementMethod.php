<?php

declare(strict_types=1);

namespace App\Modules\RmeInvoice\Support;

/**
 * FEATURE-RME-REFUND-SETTLEMENT-1 (owner RF-9 = C) — the two ways a refund may be
 * paid out, each with its own proof. No other method exists (no patient credit,
 * no cross-invoice offset, no payment reversal: RF-12, RF-13).
 *
 *   BANK_TRANSFER — bank transaction reference, destination bank, the last four
 *                   digits of the destination account (never the full number),
 *                   and the transfer receipt.
 *   CASH          — handover acknowledgment number, recipient relation, and the
 *                   signed handover acknowledgment. A generated receipt alone is
 *                   never proof that cash was handed over.
 */
final class RmeRefundSettlementMethod
{
    public const BANK_TRANSFER = 'BANK_TRANSFER';

    public const CASH = 'CASH';

    public const ALL = [self::BANK_TRANSFER, self::CASH];

    public const LABELS = [
        self::BANK_TRANSFER => 'Transfer Bank',
        self::CASH => 'Tunai',
    ];

    public const RECIPIENT_PATIENT = 'PATIENT';

    public const RECIPIENT_REPRESENTATIVE = 'REPRESENTATIVE';

    public const RECIPIENTS = [self::RECIPIENT_PATIENT, self::RECIPIENT_REPRESENTATIVE];

    public const RECIPIENT_LABELS = [
        self::RECIPIENT_PATIENT => 'Pasien sendiri',
        self::RECIPIENT_REPRESENTATIVE => 'Wakil yang dikuasakan pasien',
    ];

    public static function label(?string $method): string
    {
        return self::LABELS[$method] ?? (string) $method;
    }
}
