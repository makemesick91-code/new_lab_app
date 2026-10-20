<?php

declare(strict_types=1);

namespace App\Modules\RmeInvoice\Services;

use App\Models\User;
use App\Modules\RmeInvoice\Support\RmeInvoiceDocument;
use App\Modules\RmeInvoice\Support\RmeInvoicePriceSource;
use App\Modules\RmeInvoice\Support\RmeInvoicePricingRefusal;
use App\Modules\RmeInvoice\Support\RmeTariffExceptionPermission;
use App\Modules\Tariff\Services\BranchTariffAuthorityService;
use App\Modules\Tariff\Support\TariffAuthorityConfig;
use App\Modules\Tariff\Support\TariffPricingMode;
use App\Modules\Tariff\Support\TariffResolution;
use App\Modules\Tariff\Support\TariffResolutionSet;
use App\Modules\Treatment\Interfaces\TreatmentRepositoryInterface;
use Illuminate\Validation\ValidationException;

/**
 * The server-side price of every line of an ORIGINAL RME invoice.
 *
 * REVISION-RME-MANUAL-PRICING-AUTHORITY-1 (owner D21, revised 2026-10-20):
 * the authorized cashier types the price. There is no mandatory tariff, no
 * tariff equality, no automatic tariff fallback and no tariff lookup on this
 * path. Manual pricing means the CASHIER chooses the amount — never that the
 * browser decides the accounting. The server still owns:
 *
 *   - money: integer cents parsed from the submitted value, never floats; a
 *     unit price is REQUIRED (empty / null / whitespace / exponent / separators
 *     / currency text / 3+ decimals / non-finite are refused with
 *     INVOICE_AMOUNT_INVALID — malformed money is never silently zero);
 *   - qty >= 1, unit price >= 0, discount >= 0 (unchanged messages);
 *   - a discount never above its line (INVOICE_LINE_NEGATIVE), so neither a
 *     line nor the invoice total can be negative;
 *   - overflow of decimal(15,2) refused before multiplying;
 *   - the totals, recomputed here — a submitted total is never read;
 *   - a named treatment must exist, not be soft-deleted and be active
 *     (INVOICE_TREATMENT_INVALID, audited) — a free-text line naming no
 *     treatment stays permitted, as it is a live cashier workflow.
 *
 * Every line records MANUAL provenance (MANUAL_CASHIER_ENTRY /
 * MANUAL_FREE_TEXT); a manual price is never labelled as tariff.
 *
 * Rp0 on an ORIGINAL invoice keeps its pre-revision behaviour (accepted,
 * counted as `zero_value_lines` in the audit). The Rp0 policy (D21g / D11 / D22)
 * is still undecided, so no new Rp0 privilege and no new Rp0 block is invented
 * here; invoice CORRECTION keeps refusing every new Rp0 line and total.
 *
 * STRICT_ENFORCED is LEGACY_SUPERSEDED: its code is kept below, but no branch
 * can reach it (source-controlled empty list, rule 194).
 */
class RmeInvoicePricingService
{
    /** decimal(15,2) holds at most 9,999,999,999,999.99. */
    public const MAX_CENTS = 999_999_999_999_999;

    /**
     * The largest quantity `trx_rme_invoice_items.qty` holds on PostgreSQL
     * (`integer`). A STORAGE bound — no business ceiling on quantity was decided.
     */
    public const MAX_QTY = 2_147_483_647;

    public function __construct(
        private readonly BranchTariffAuthorityService $authority,
        private readonly TreatmentRepositoryInterface $treatments,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $items  validated request lines
     * @return array{
     *     mode: string,
     *     lines: list<array<string, mixed>>,
     *     subtotal_cents: int,
     *     discount_cents: int,
     *     grand_total_cents: int,
     *     summary: array<string, int|string>
     * }
     */
    public function price(int $branchId, array $items, User $actor, string $clinicalDate): array
    {
        $mode = $this->authority->modeFor($branchId);
        $strict = $mode === TariffPricingMode::STRICT_ENFORCED;
        $treatmentIds = array_map(fn ($item) => self::optionalId(is_array($item) ? ($item['treatment_id'] ?? null) : null), $items);

        // MANUAL: the tariff master is never read. Only treatment VALIDITY is
        // checked, in one query for the whole invoice.
        $resolutions = $strict
            ? $this->authority->resolveMany($branchId, $treatmentIds, $clinicalDate)
            : null;
        $activeTreatmentIds = $strict
            ? []
            : $this->treatments->activeIds(array_values(array_filter($treatmentIds, fn (?int $id) => $id !== null)));

        $lines = [];
        $subtotal = 0;
        $discountTotal = 0;
        $lineNo = 0;

        foreach ($items as $item) {
            $lineNo++;
            $line = $strict
                ? $this->priceLine(is_array($item) ? $item : [], $lineNo, $resolutions, $actor)
                : $this->priceManualLine(is_array($item) ? $item : [], $lineNo, $activeTreatmentIds);

            $subtotal += $line['gross_cents'];
            $discountTotal += $line['discount_cents'];

            if ($subtotal > self::MAX_CENTS) {
                throw ValidationException::withMessages([
                    'items' => '[INVOICE_TOTAL_OVERFLOW] Total tagihan melebihi batas yang dapat disimpan.',
                ]);
            }

            $line['pricing_mode'] = $mode;
            $lines[] = $line;
        }

        $grandTotal = $subtotal - $discountTotal;

        if ($strict && $grandTotal <= 0) {
            throw new RmeInvoicePricingRefusal(
                RmeInvoicePricingRefusal::RP0_NOT_PERMITTED,
                'Total tagihan Rp0 tidak diizinkan pada mode tarif ketat. Kebijakan Rp0 belum diputuskan owner.',
            );
        }

        return [
            'mode' => $mode,
            'lines' => $lines,
            'subtotal_cents' => $subtotal,
            'discount_cents' => $discountTotal,
            'grand_total_cents' => $grandTotal,
            'summary' => $this->summarize($mode, $lines),
        ];
    }

    /**
     * MANUAL pricing of one line: the cashier's price, validated by the server.
     * No tariff is read, compared or recorded.
     *
     * @param  array<string, mixed>  $item
     * @param  list<int>  $activeTreatmentIds
     * @return array<string, mixed>
     */
    private function priceManualLine(array $item, int $lineNo, array $activeTreatmentIds): array
    {
        $line = $this->arithmetic($item);

        if ($line['treatment_id'] !== null && ! in_array($line['treatment_id'], $activeTreatmentIds, true)) {
            $this->refuse(
                RmeInvoicePricingRefusal::TREATMENT_INVALID,
                'Tindakan pada item "'.self::clip($line['description']).'" tidak ditemukan atau tidak aktif.',
                $lineNo,
                $line['treatment_id'],
            );
        }

        return array_merge($line, [
            'price_source' => $line['treatment_id'] !== null
                ? RmeInvoicePriceSource::MANUAL_CASHIER_ENTRY
                : RmeInvoicePriceSource::MANUAL_FREE_TEXT,
            'tariff_id' => null,
            'tariff_unit_price_cents' => null,
            'price_exception_reason' => null,
            'price_exception_by' => null,
        ]);
    }

    /**
     * The server arithmetic every line goes through, in every mode: parsed
     * money, unchanged legacy messages for ordinary input errors, overflow
     * refused before multiplying, and a discount never above its line.
     *
     * @param  array<string, mixed>  $item
     * @return array{treatment_id: int|null, description: string, qty: int, unit_price_cents: int, discount_cents: int, gross_cents: int, subtotal_cents: int, doctor_id: int|null}
     */
    private function arithmetic(array $item): array
    {
        $qty = (int) ($item['qty'] ?? 0);
        $unit = self::amountToCents($item['unit_price'] ?? null, 'Harga satuan', required: true);
        $discount = self::amountToCents($item['discount'] ?? null, 'Diskon', required: false);
        $treatmentId = self::optionalId($item['treatment_id'] ?? null);
        $description = (string) ($item['description'] ?? '');

        if ($qty < 1) {
            throw ValidationException::withMessages(['items' => 'Qty item harus minimal 1.']);
        }
        // Security review F2: a quantity beyond the integer column reached the
        // database and answered 500. This is the STORAGE bound, not a business cap.
        if ($qty > self::MAX_QTY) {
            throw ValidationException::withMessages([
                'items' => '[INVOICE_QTY_OVERFLOW] Qty item "'.self::clip($description).'" melebihi batas yang dapat disimpan.',
            ]);
        }
        if ($unit < 0) {
            throw ValidationException::withMessages(['items' => 'Harga satuan tidak boleh negatif.']);
        }
        if ($discount < 0) {
            throw ValidationException::withMessages(['items' => 'Diskon tidak boleh negatif.']);
        }

        // Refuse BEFORE multiplying: past PHP_INT_MAX the product becomes a float.
        if ($unit > intdiv(self::MAX_CENTS, $qty)) {
            throw ValidationException::withMessages([
                'items' => '[INVOICE_TOTAL_OVERFLOW] Nilai item "'.self::clip($description).'" melebihi batas yang dapat disimpan.',
            ]);
        }

        $gross = $qty * $unit;
        $net = $gross - $discount;

        if ($net < 0) {
            throw ValidationException::withMessages([
                'items' => '[INVOICE_LINE_NEGATIVE] Diskon pada item "'.self::clip($description).'" melebihi nilai item.',
            ]);
        }

        return [
            'treatment_id' => $treatmentId,
            'description' => $description,
            'qty' => $qty,
            'unit_price_cents' => $unit,
            'discount_cents' => $discount,
            'gross_cents' => $gross,
            'subtotal_cents' => $net,
            'doctor_id' => self::optionalId($item['doctor_id'] ?? null),
        ];
    }

    /**
     * LEGACY_SUPERSEDED strict tariff pricing of one line (unreachable while the
     * source-controlled strict list is empty — rule 194).
     *
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function priceLine(array $item, int $lineNo, TariffResolutionSet $resolutions, User $actor): array
    {
        $line = $this->arithmetic($item);
        $resolution = $resolutions->for($line['treatment_id']);
        $exceptionReason = null;
        $exceptionBy = null;

        $source = $this->strictSource($resolution, $line['unit_price_cents'], $line['discount_cents'], $line['subtotal_cents'], $lineNo, $item, $actor);
        if ($source === RmeInvoicePriceSource::TARIFF_EXCEPTION_AUTHORIZED) {
            $exceptionReason = trim((string) ($item['price_exception_reason'] ?? ''));
            $exceptionBy = (int) $actor->id;
        }

        return array_merge($line, [
            'price_source' => $source,
            'tariff_id' => $resolution->tariffId !== null && $resolution->isResolved() ? $resolution->tariffId : null,
            'tariff_unit_price_cents' => $resolution->isResolved() ? $resolution->priceCents : null,
            'price_exception_reason' => $exceptionReason,
            'price_exception_by' => $exceptionBy,
        ]);
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function strictSource(TariffResolution $resolution, int $unit, int $discount, int $net, int $lineNo, array $item, User $actor): string
    {
        $label = '"'.self::clip((string) ($item['description'] ?? '')).'"';

        match ($resolution->outcome) {
            TariffResolution::RESOLVED => null,
            TariffResolution::NO_TREATMENT => $this->refuse(RmeInvoicePricingRefusal::FREE_TEXT_NOT_PERMITTED,
                "Item {$label} tidak memilih tindakan master. Mode tarif ketat hanya menerima tindakan yang memiliki tarif cabang.", $lineNo, null),
            TariffResolution::TREATMENT_INACTIVE => $this->refuse(RmeInvoicePricingRefusal::TREATMENT_INACTIVE,
                "Tindakan pada item {$label} tidak aktif.", $lineNo, $resolution->treatmentId),
            TariffResolution::ZERO_PRICE => $this->refuse(RmeInvoicePricingRefusal::TARIFF_ZERO_PRICE,
                "Tarif tindakan pada item {$label} bernilai Rp0 dan tidak dapat menjadi dasar harga.", $lineNo, $resolution->treatmentId),
            default => $this->refuse(RmeInvoicePricingRefusal::TARIFF_MISSING,
                "Tindakan pada item {$label} belum memiliki tarif master yang berlaku di cabang ini.", $lineNo, $resolution->treatmentId),
        };

        if ($net === 0) {
            $this->refuse(RmeInvoicePricingRefusal::RP0_NOT_PERMITTED,
                "Item {$label} bernilai Rp0. Kebijakan Rp0 belum diputuskan owner.", $lineNo, $resolution->treatmentId);
        }

        $priceDeviates = $unit !== $resolution->priceCents;
        $discounted = $discount > 0;

        if (! $priceDeviates && ! $discounted) {
            return RmeInvoicePriceSource::TARIFF_MATCHED;
        }

        if ($priceDeviates && ! $this->mayAuthorize(RmeTariffExceptionPermission::OVERRIDE_PRICE, $actor)) {
            $this->refuse(RmeInvoicePricingRefusal::PRICE_MISMATCH,
                "Harga item {$label} berbeda dari tarif master cabang. Harga harus sama dengan tarif.", $lineNo, $resolution->treatmentId);
        }

        if ($discounted && ! $this->mayAuthorize(RmeTariffExceptionPermission::AUTHORIZE_DISCOUNT, $actor)) {
            $this->refuse(RmeInvoicePricingRefusal::DISCOUNT_NOT_AUTHORIZED,
                "Diskon pada item {$label} tidak diizinkan tanpa otorisasi.", $lineNo, $resolution->treatmentId);
        }

        $reason = trim((string) ($item['price_exception_reason'] ?? ''));
        $min = (int) config(TariffAuthorityConfig::KEY_REASON_MIN, 10);
        $max = (int) config(TariffAuthorityConfig::KEY_REASON_MAX, 500);
        $length = mb_strlen($reason);

        if ($length < $min || $length > $max) {
            $this->refuse(RmeInvoicePricingRefusal::EXCEPTION_REASON_REQUIRED,
                "Pengecualian harga/diskon pada item {$label} wajib disertai alasan {$min}–{$max} karakter.", $lineNo, $resolution->treatmentId);
        }

        return RmeInvoicePriceSource::TARIFF_EXCEPTION_AUTHORIZED;
    }

    /**
     * BOTH the switch and the specific permission. The switch is checked first
     * and is a plain config value, so Super Admin's wildcard cannot open it.
     */
    private function mayAuthorize(string $permission, User $actor): bool
    {
        return $this->authority->exceptionsEnabled() && $actor->can($permission);
    }

    /**
     * Money in, integer cents out — for EVERY caller, not only the HTTP form.
     * A plain decimal string (at most 2 places), an int, or a finite float that
     * is exact to the cent and within the column. Anything else — exponent
     * notation, 3+ decimals, NaN/INF, a value past decimal(15,2) — is refused
     * rather than converted, because the float fallback of a cents conversion
     * wraps silently on such input (security review, this sprint).
     */
    private static function amountToCents(mixed $value, string $label, bool $required): int
    {
        // An optional amount (the discount) left blank is zero. A REQUIRED one
        // (the unit price) left blank is refused — never silently zero.
        if ($value === null || (is_string($value) && trim($value) === '')) {
            if (! $required) {
                return 0;
            }

            throw ValidationException::withMessages([
                'items' => "[INVOICE_AMOUNT_INVALID] {$label} wajib diisi dengan angka.",
            ]);
        }

        $valid = match (true) {
            is_int($value) => abs($value) <= intdiv(self::MAX_CENTS, 100),
            is_float($value) => is_finite($value) && abs($value) <= self::MAX_CENTS / 100
                && round($value, 2) === $value,
            is_string($value) => preg_match('/^-?\d{1,13}(?:\.\d{1,2})?$/D', trim($value)) === 1,
            default => false,
        };

        if (! $valid) {
            throw ValidationException::withMessages([
                'items' => "[INVOICE_AMOUNT_INVALID] {$label} harus berupa angka dengan maksimal 2 desimal dan dalam batas yang dapat disimpan.",
            ]);
        }

        return RmeInvoiceDocument::toCents($value);
    }

    private function refuse(string $code, string $message, ?int $lineNo, ?int $treatmentId): never
    {
        throw new RmeInvoicePricingRefusal($code, $message, $lineNo, $treatmentId);
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @return array<string, int|string>
     */
    private function summarize(string $mode, array $lines): array
    {
        $summary = ['pricing_mode' => $mode];
        foreach (RmeInvoicePriceSource::ALL as $source) {
            $summary['lines_'.strtolower($source)] = 0;
        }
        $summary['zero_value_lines'] = 0;

        foreach ($lines as $line) {
            $summary['lines_'.strtolower((string) $line['price_source'])]++;
            if ($line['subtotal_cents'] === 0) {
                $summary['zero_value_lines']++;
            }
        }

        return $summary;
    }

    private static function optionalId(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $id = (int) $value;

        return $id > 0 ? $id : null;
    }

    private static function clip(string $text): string
    {
        return mb_strlen($text) > 60 ? mb_substr($text, 0, 57).'…' : $text;
    }
}
