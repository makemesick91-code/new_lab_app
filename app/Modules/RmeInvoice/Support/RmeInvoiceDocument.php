<?php

namespace App\Modules\RmeInvoice\Support;

use App\Modules\RmeInvoice\Models\RmeInvoice;
use App\Modules\RmeInvoice\Models\RmePayment;
use Illuminate\Support\Collection;

/**
 * REVISION-RME-VISIT-DETAIL-INVOICE-REGISTRATION-UX-1 — the money on the printed
 * RME invoice, assembled once on the server.
 *
 * The redesigned invoice shows a "Biaya" column and a summary block
 * (Jumlah / Diskon / Total Biaya / Pembayaran / Sisa Pembayaran). Every one of
 * those numbers is read from a STORED canonical field — nothing is recomputed
 * from rendered text and nothing new is invented:
 *
 *   line Biaya       = item.qty × item.unit_price (the gross line, exactly how
 *                      RmeInvoiceService::create() accumulates invoice.subtotal)
 *   line Diskon      = item.discount
 *   Jumlah           = invoice.subtotal
 *   Diskon           = invoice.discount_total
 *   Total Biaya      = invoice.grand_total
 *   Pembayaran       = RmeInvoice::paidAmount()       (every payment on THIS invoice)
 *   Sisa Pembayaran  = RmeInvoice::remainingAmount()
 *
 * Arithmetic is done in integer cents, parsed exactly from the decimal(15,2)
 * strings, so no float rounding can move a rupiah.
 *
 * The document also RECONCILES itself: the lines must add up to the stored
 * totals, and paid + remaining must equal the total. It never "fixes" a
 * mismatch — the stored values are what print — but it reports one, so the
 * template can refuse to look settled when the books disagree.
 *
 * Read-only: no query, no write, no billing behaviour. paidAmount() /
 * remainingAmount() are the invoice's own canonical accessors.
 */
final class RmeInvoiceDocument
{
    /**
     * @param  list<array{description: string, qty: int, unit_price_cents: int, gross_cents: int, discount_cents: int, net_cents: int}>  $lines
     * @param  list<string>  $paymentMethods
     * @param  list<string>  $discrepancies
     */
    private function __construct(
        public readonly array $lines,
        public readonly int $grossCents,
        public readonly int $discountCents,
        public readonly int $totalCents,
        public readonly int $paidCents,
        public readonly int $remainingCents,
        public readonly array $paymentMethods,
        public readonly array $discrepancies,
    ) {}

    /**
     * @param  Collection<int, RmePayment>  $payments  the invoice's payments (any order)
     */
    public static function fromInvoice(RmeInvoice $invoice, Collection $payments): self
    {
        $lines = [];
        $discrepancies = [];
        $lineGrossSum = 0;
        $lineDiscountSum = 0;

        foreach ($invoice->items as $item) {
            $qty = (int) $item->qty;
            $unit = self::toCents($item->unit_price);
            $gross = $qty * $unit;
            $discount = self::toCents($item->discount);
            $net = self::toCents($item->subtotal);

            if ($gross - $discount !== $net) {
                $discrepancies[] = 'line_net_mismatch';
            }

            $lineGrossSum += $gross;
            $lineDiscountSum += $discount;

            $lines[] = [
                'description' => (string) $item->description,
                'qty' => $qty,
                'unit_price_cents' => $unit,
                'gross_cents' => $gross,
                'discount_cents' => $discount,
                'net_cents' => $net,
            ];
        }

        $gross = self::toCents($invoice->subtotal);
        $discountTotal = self::toCents($invoice->discount_total);
        $total = self::toCents($invoice->grand_total);
        $paid = self::toCents($invoice->paidAmount());
        $remaining = self::toCents($invoice->remainingAmount());

        if ($lineGrossSum !== $gross) {
            $discrepancies[] = 'subtotal_mismatch';
        }
        if ($lineDiscountSum !== $discountTotal) {
            $discrepancies[] = 'discount_mismatch';
        }
        if ($gross - $discountTotal !== $total) {
            $discrepancies[] = 'grand_total_mismatch';
        }
        if ($remaining !== max(0, $total - $paid)) {
            $discrepancies[] = 'remaining_mismatch';
        }

        $methods = $payments
            ->map(fn (RmePayment $payment) => $payment->paymentMethod?->name)
            ->map(fn (?string $name) => $name !== null && trim($name) !== '' ? $name : 'Tidak tercatat')
            ->unique()
            ->values()
            ->all();

        return new self(
            lines: $lines,
            grossCents: $gross,
            discountCents: $discountTotal,
            totalCents: $total,
            paidCents: $paid,
            remainingCents: $remaining,
            paymentMethods: $methods,
            discrepancies: array_values(array_unique($discrepancies)),
        );
    }

    public function reconciles(): bool
    {
        return $this->discrepancies === [];
    }

    public function isSettled(): bool
    {
        return $this->remainingCents === 0 && $this->reconciles();
    }

    /**
     * Rupiah with Indonesian separators ("." thousands, "," decimals).
     *
     * A whole-rupiah amount prints with no decimals ("Rp 1.225.000"), exactly as
     * before. A NON-whole amount prints its cents ("Rp 175.000,50").
     *
     * ACTIVATION-RME-INVOICE-CORRECTION-PREPAYMENT-2 (rule 195 §25): this used to
     * round half-up to whole rupiah, so a stored 175000.50 printed as
     * "Rp 175.001" — a financial document showing a figure that is not on the
     * books. The stored value was always exact; only the presentation lied. It
     * never rounds now: what is printed is what is stored.
     */
    public static function rupiah(int $cents): string
    {
        $negative = $cents < 0;
        $absolute = abs($cents);
        $whole = intdiv($absolute, 100);
        $fraction = $absolute % 100;

        $text = 'Rp '.number_format($whole, 0, ',', '.');
        if ($fraction !== 0) {
            $text .= ','.str_pad((string) $fraction, 2, '0', STR_PAD_LEFT);
        }

        return ($negative ? '- ' : '').$text;
    }

    /**
     * rupiah() for a decimal(15,2) value as stored ("175000.50"), parsed exactly
     * through toCents() — never through a float.
     */
    public static function rupiahDecimal(mixed $value): string
    {
        return self::rupiah(self::toCents($value));
    }

    /**
     * Exact decimal → integer cents. decimal(15,2) columns arrive as strings
     * ("150000.00"), which are parsed digit by digit; only a non-decimal value
     * (e.g. a float from an aggregate) falls back to rounding.
     */
    public static function toCents(mixed $value): int
    {
        if ($value === null || $value === '') {
            return 0;
        }

        if (is_int($value)) {
            return $value * 100;
        }

        $string = is_string($value) ? trim($value) : null;

        if ($string !== null && preg_match('/^(-?)(\d+)(?:\.(\d{1,2}))?$/D', $string, $m) === 1) {
            $cents = ((int) $m[2]) * 100 + (int) str_pad($m[3] ?? '', 2, '0');

            return $m[1] === '-' ? -$cents : $cents;
        }

        return (int) round(((float) $value) * 100);
    }
}
