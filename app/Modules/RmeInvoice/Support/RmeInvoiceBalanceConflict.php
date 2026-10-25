<?php

declare(strict_types=1);

namespace App\Modules\RmeInvoice\Support;

use RuntimeException;

/**
 * FIX-RME-PAYMENT-SUBMISSION-IDEMPOTENCY-1 (rule 205, finding F3) — a payment
 * would settle balances other than the ones the cashier saw and confirmed.
 *
 * Raised by RmePaymentService UNDER the invoice row locks (and, as an early
 * courtesy, by the payment FormRequest) and caught OUTSIDE the transaction, so
 * the WHOLE batch rolls back — no payment on any invoice, no status change, no
 * visit completion — and the attempt is audited after the rollback. The amount is
 * never silently cut down and the money is never silently moved onto another
 * invoice: the cashier reloads the page, reads the current balances and confirms
 * again.
 *
 * It never reaches a user as an exception: it is always converted to a
 * validation error on `invoice_balances`.
 */
final class RmeInvoiceBalanceConflict extends RuntimeException
{
    /** The form did not declare the plan or the balance it displayed for an invoice. */
    public const MISSING = 'PAYMENT_BALANCE_MISSING';

    /** An invoice's remaining balance changed after the form was rendered. */
    public const CHANGED = 'PAYMENT_BALANCE_CHANGED';

    /** The set of invoices this payment would settle is no longer the one displayed. */
    public const PLAN_CHANGED = 'PAYMENT_PLAN_CHANGED';

    /** Defence in depth: the amount could not be allocated in full to the locked plan. */
    public const NOT_ALLOCATED = 'PAYMENT_AMOUNT_NOT_ALLOCATED';

    public function __construct(
        public readonly string $reasonCode,
        public readonly ?int $invoiceId = null,
        public readonly ?int $declaredCents = null,
        public readonly ?int $currentCents = null,
    ) {
        parent::__construct($reasonCode);
    }

    public function operatorMessage(): string
    {
        return self::messageFor($this->reasonCode);
    }

    /** Shared by the early (FormRequest) and authoritative (under-lock) checks. */
    public static function messageFor(string $reasonCode): string
    {
        return match ($reasonCode) {
            self::MISSING => '['.self::MISSING.'] Formulir pembayaran tidak memuat rincian dan sisa tagihan yang ditampilkan. '
                .'Tidak ada pembayaran yang dicatat. Muat ulang halaman pembayaran dan periksa sisa tagihan sebelum mencatat pembayaran.',
            self::PLAN_CHANGED => '['.self::PLAN_CHANGED.'] Rincian tagihan yang akan dibayar telah berubah sejak halaman pembayaran dibuka '
                .'(misalnya piutang sebelumnya sudah dilunasi). Tidak ada pembayaran yang dicatat. '
                .'Muat ulang halaman, periksa rincian terbaru, lalu konfirmasi ulang pembayaran.',
            self::NOT_ALLOCATED => '['.self::NOT_ALLOCATED.'] Jumlah pembayaran tidak dapat dialokasikan seluruhnya ke tagihan yang tersisa. '
                .'Tidak ada pembayaran yang dicatat. Muat ulang halaman dan periksa total yang harus dibayar.',
            default => '['.self::CHANGED.'] Sisa tagihan telah berubah sejak halaman pembayaran dibuka, misalnya karena ada pembayaran lain yang baru tercatat. '
                .'Tidak ada pembayaran yang dicatat. Muat ulang halaman, periksa sisa tagihan terbaru, lalu konfirmasi ulang pembayaran.',
        };
    }
}
