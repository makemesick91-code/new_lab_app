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
use Illuminate\Validation\ValidationException;

/**
 * FEATURE-RME-BRANCH-TARIFF-AUTHORITY-1 — the server-side price of every RME
 * invoice line (owner decision D21).
 *
 * ALL MODES (tightening only, never widening):
 *   - money is integer cents, parsed from the submitted value; never floats;
 *   - qty >= 1, unit price >= 0, discount >= 0 (unchanged messages);
 *   - a discount above the line's gross amount is refused (a negative line was
 *     accepted before; production held none);
 *   - overflow of decimal(15,2) is refused before multiplying;
 *   - the totals are recomputed here; a submitted total is never read.
 *
 * TRANSITIONAL (default): the legacy cashier price is kept, and the line
 * records its PROVENANCE against the branch tariff — matched, deviating, no
 * tariff, or free text — so it is never mistaken for master pricing.
 *
 * STRICT_ENFORCED (explicit per-branch opt-in): the tariff in force IS the
 * price. Refused with a stable code: a free-text line, an inactive treatment, a
 * missing tariff, a zero tariff, a price that differs from the tariff, any
 * discount, and any Rp0 line or total. A deviation or a discount is accepted
 * only as an AUTHORIZED EXCEPTION: exceptions switched on AND the actor holds
 * the specific permission AND gives a written reason — and even then the
 * all-modes rules and the Rp0 refusal still hold. Exceptions are OFF until the
 * owner decides D21b–D21f.
 */
class RmeInvoicePricingService
{
    /** decimal(15,2) holds at most 9,999,999,999,999.99. */
    public const MAX_CENTS = 999_999_999_999_999;

    public function __construct(
        private readonly BranchTariffAuthorityService $authority,
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

        $resolutions = $this->authority->resolveMany(
            $branchId,
            array_map(fn ($item) => self::optionalId($item['treatment_id'] ?? null), $items),
            $clinicalDate,
        );

        $lines = [];
        $subtotal = 0;
        $discountTotal = 0;
        $lineNo = 0;

        foreach ($items as $item) {
            $lineNo++;
            $line = $this->priceLine($item, $lineNo, $resolutions, $strict, $actor);

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
     * What the cashier form displays for a branch: its mode, the tariff price in
     * force (cents) for each given treatment, and whether exceptions are open.
     * Display only — the server re-prices every submitted line.
     *
     * @param  list<int>  $treatmentIds
     * @return array{mode: string, tariffs: array<int, int>, exceptions_enabled: bool}
     */
    public function contextFor(int $branchId, array $treatmentIds, string $clinicalDate): array
    {
        $tariffs = [];
        foreach ($this->authority->resolveMany($branchId, $treatmentIds, $clinicalDate)->all() as $id => $resolution) {
            if ($resolution->isResolved()) {
                $tariffs[$id] = (int) $resolution->priceCents;
            }
        }

        return [
            'mode' => $this->authority->modeFor($branchId),
            'tariffs' => $tariffs,
            'exceptions_enabled' => $this->authority->exceptionsEnabled(),
        ];
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function priceLine(array $item, int $lineNo, TariffResolutionSet $resolutions, bool $strict, User $actor): array
    {
        $qty = (int) ($item['qty'] ?? 0);
        $unit = self::amountToCents($item['unit_price'] ?? 0, 'Harga satuan');
        $discount = self::amountToCents($item['discount'] ?? 0, 'Diskon');
        $treatmentId = self::optionalId($item['treatment_id'] ?? null);
        $description = (string) ($item['description'] ?? '');

        // Unchanged legacy messages for ordinary input errors.
        if ($qty < 1) {
            throw ValidationException::withMessages(['items' => 'Qty item harus minimal 1.']);
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

        $resolution = $resolutions->for($treatmentId);
        $exceptionReason = null;
        $exceptionBy = null;

        if ($strict) {
            $source = $this->strictSource($resolution, $unit, $discount, $net, $lineNo, $item, $actor);
            if ($source === RmeInvoicePriceSource::TARIFF_EXCEPTION_AUTHORIZED) {
                $exceptionReason = trim((string) ($item['price_exception_reason'] ?? ''));
                $exceptionBy = (int) $actor->id;
            }
        } else {
            $source = $this->transitionalSource($resolution, $unit, $discount);
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
            'price_source' => $source,
            'tariff_id' => $resolution->tariffId !== null && $resolution->isResolved() ? $resolution->tariffId : null,
            'tariff_unit_price_cents' => $resolution->isResolved() ? $resolution->priceCents : null,
            'price_exception_reason' => $exceptionReason,
            'price_exception_by' => $exceptionBy,
        ];
    }

    private function transitionalSource(TariffResolution $resolution, int $unit, int $discount): string
    {
        return match ($resolution->outcome) {
            TariffResolution::RESOLVED => ($unit === $resolution->priceCents && $discount === 0)
                ? RmeInvoicePriceSource::TARIFF_MATCHED
                : RmeInvoicePriceSource::TRANSITIONAL_TARIFF_DEVIATION,
            TariffResolution::NO_TREATMENT => RmeInvoicePriceSource::TRANSITIONAL_FREE_TEXT,
            default => RmeInvoicePriceSource::TRANSITIONAL_NO_TARIFF,
        };
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
    private static function amountToCents(mixed $value, string $label): int
    {
        if ($value === null || $value === '') {
            return 0;
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
