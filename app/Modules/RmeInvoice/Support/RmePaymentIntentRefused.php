<?php

declare(strict_types=1);

namespace App\Modules\RmeInvoice\Support;

use RuntimeException;

/**
 * FIX-RME-PAYMENT-SUBMISSION-IDEMPOTENCY-1 (rule 205) — a payment submission
 * whose intent cannot be honoured. Always converted to a validation error on
 * `payment_intent`; nothing is recorded.
 *
 * The message never reveals another account's payment, amount or receipt: a
 * REUSED refusal tells the cashier only that this form was already used, which
 * is true for the account it was issued to and reveals nothing to anyone else.
 */
final class RmePaymentIntentRefused extends RuntimeException
{
    /** The form carries no intent (rendered before rule 205, or crafted). */
    public const MISSING = 'PAYMENT_INTENT_MISSING';

    /** The intent was not issued by this server for this invoice. */
    public const INVALID = 'PAYMENT_INTENT_INVALID';

    /**
     * The intent already recorded a DIFFERENT submission (another payload or
     * another account). An exact repetition is a replay, never this.
     */
    public const REUSED = 'PAYMENT_INTENT_REUSED';

    public function __construct(
        public readonly string $reasonCode,
        public readonly ?int $submissionId = null,
        public readonly ?int $invoiceId = null,
    ) {
        parent::__construct($reasonCode);
    }

    public function operatorMessage(): string
    {
        return self::messageFor($this->reasonCode);
    }

    public static function messageFor(string $reasonCode): string
    {
        return match ($reasonCode) {
            self::MISSING => '['.self::MISSING.'] Formulir pembayaran ini tidak memuat penanda pembayaran (dibuka sebelum pembaruan aplikasi). '
                .'Tidak ada pembayaran yang dicatat. Muat ulang halaman pembayaran, lalu catat pembayaran kembali.',
            self::REUSED => '['.self::REUSED.'] Formulir pembayaran ini sudah pernah dipakai untuk mencatat pembayaran. '
                .'Tidak ada pembayaran baru yang dicatat. Periksa riwayat pembayaran tagihan ini sebelum membuka formulir pembayaran yang baru.',
            default => '['.self::INVALID.'] Formulir pembayaran tidak dikenali untuk tagihan ini. '
                .'Tidak ada pembayaran yang dicatat. Muat ulang halaman pembayaran, lalu catat pembayaran kembali.',
        };
    }
}
