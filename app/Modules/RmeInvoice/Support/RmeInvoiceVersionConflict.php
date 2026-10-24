<?php

declare(strict_types=1);

namespace App\Modules\RmeInvoice\Support;

use App\Modules\RmeInvoice\Services\RmePaymentService;
use RuntimeException;

/**
 * ACTIVATION-RME-INVOICE-CORRECTION-PREPAYMENT-1 (rule 191) — a payment was
 * submitted against an invoice version the cashier did not see.
 *
 * Raised by {@see RmePaymentService} UNDER the
 * invoice row lock and caught OUTSIDE the transaction, so the payment path can
 * roll the whole batch back, write the
 * {@see RmeFinancialAuditEvent::ACTION_PAYMENT_REFUSED_INVOICE_VERSION} audit row
 * after the rollback (an attempt is evidence even though it changed nothing), and
 * only then surface the refusal as a validation error on `invoice_versions`.
 *
 * It never reaches a user as an exception: it is always converted.
 */
final class RmeInvoiceVersionConflict extends RuntimeException
{
    /** The form carried no version for an invoice the payment would settle. */
    public const MISSING = 'INVOICE_VERSION_MISSING';

    /** The invoice was corrected after the form was rendered. */
    public const CHANGED = 'INVOICE_VERSION_CHANGED';

    public const PATH_SINGLE = 'single';

    public const PATH_CONTROL = 'control';

    public const PATH_VISIT = 'visit';

    /** Rule 204 (OPS-OQ-1b) — a Kontrol invoice paid on its own while its origin is disputed. */
    public const PATH_CONTROL_ONLY = 'control_only';

    public function __construct(
        public readonly string $reasonCode,
        public readonly int $invoiceId,
        public readonly ?int $expectedVersion,
        public readonly int $currentVersion,
    ) {
        parent::__construct($reasonCode);
    }

    /** The operator-facing message, prefixed with the stable code. */
    public function operatorMessage(): string
    {
        return self::messageFor($this->reasonCode);
    }

    /**
     * Shared with the payment FormRequest, so the early (pre-lock) check and the
     * authoritative (under-lock) check can never word the same refusal twice.
     */
    public static function messageFor(string $reasonCode): string
    {
        return match ($reasonCode) {
            self::MISSING => '['.self::MISSING.'] Formulir pembayaran tidak memuat versi tagihan. '
                .'Muat ulang halaman pembayaran dan periksa total tagihan sebelum mencatat pembayaran.',
            default => '['.self::CHANGED.'] Tagihan ini telah dikoreksi sejak halaman pembayaran dibuka. '
                .'Muat ulang halaman, periksa total terbaru, lalu catat pembayaran kembali.',
        };
    }
}
