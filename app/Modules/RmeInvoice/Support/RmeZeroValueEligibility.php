<?php

declare(strict_types=1);

namespace App\Modules\RmeInvoice\Support;

use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use App\Modules\RmeInvoice\Models\RmeInvoiceItem;
use App\Modules\Treatment\Models\Treatment;

/**
 * FEATURE-RME-RP0-CONTROL-CONSULTATION-1 (rule 217) — the single answer to "is
 * this Rp0 invoice LEGITIMATELY free, and why?". Pure: it reads only the rows it
 * is handed and writes nothing. The closure service asks it on the LOCKED
 * invoice and visit; the cashier page asks it for display only.
 *
 * Owner decisions of 2026-11-01:
 *  - S6-D1 — only a Konsultasi Dokter or a Kontrol. A server-derived Kontrol
 *    visit (visit_type control AND a parent visit) may carry ANY free line
 *    (sub-decision); every other visit may only carry consultation lines.
 *    Eligibility comes from treatment_id + category, never from a description.
 *  - S6-D6 — vouchers, discounts, corrections and write-offs are out of scope:
 *    a line is free only at unit price 0 WITH no discount, TRT-FREESC is never
 *    eligible, a corrected invoice is never eligible.
 *  - D28 / S6-D4 — this is not a correction; it never changes an amount.
 *  - #36 (S6-D2) — a visit that is no longer cashier_pending is never closed.
 *
 * Narrowed after the independent security review, both APPROVED by the Owner on
 * 2026-11-01 as S6-Q1 and S6-Q2 (originally recorded as pending confirmation):
 *  - a Kontrol must follow a REAL examined visit of the same patient — the parent
 *    exists, is not soft-deleted, and reached cashier_pending or completed. A link
 *    to a cancelled or never-examined visit is a label, not a follow-up;
 *  - a line that requires lab work is never closed free: NO_CHARGE generates no
 *    lab candidate, so closing it would silently drop the lab work.
 *
 * HARDENING (owner S6-Q3 revised, 2026-11-01) — supersedes the S6-D1 sub-decision
 * "every free line of a Kontrol is eligible". A Rp0 line must be an APPROVED FREE
 * SERVICE on every visit, Kontrol included: a `consultation` category treatment
 * anywhere, a `control` (Kontrol service) category treatment only on a VALID
 * Kontrol. A normally paid treatment at Rp0, a free-text line, or a renamed
 * description is never free — whoever closes it. {@see isApprovedFreeTreatment()}
 * is the single answer, used here and by the issuance guard.
 */
final class RmeZeroValueEligibility
{
    public const NOT_ENABLED = 'ZERO_VALUE_NOT_ENABLED';

    public const INVOICE_NOT_OPEN = 'ZERO_VALUE_INVOICE_NOT_OPEN';

    public const TOTAL_NOT_ZERO = 'ZERO_VALUE_TOTAL_NOT_ZERO';

    public const INVOICE_CORRECTED = 'ZERO_VALUE_INVOICE_CORRECTED';

    public const HAS_PAYMENTS = 'ZERO_VALUE_HAS_PAYMENTS';

    public const VISIT_NOT_PENDING = 'ZERO_VALUE_VISIT_NOT_PENDING';

    public const NO_LINES = 'ZERO_VALUE_NO_LINES';

    public const LINE_NOT_FREE = 'ZERO_VALUE_LINE_NOT_FREE';

    public const VOUCHER_EXCLUDED = 'ZERO_VALUE_VOUCHER_EXCLUDED';

    public const CATEGORY_NOT_ELIGIBLE = 'ZERO_VALUE_CATEGORY_NOT_ELIGIBLE';

    public const CONTROL_PARENT_INVALID = 'ZERO_VALUE_CONTROL_PARENT_INVALID';

    public const LAB_LINE_EXCLUDED = 'ZERO_VALUE_LAB_LINE_EXCLUDED';

    /** The parent statuses that prove the earlier visit was actually examined. */
    public const EXAMINED_PARENT_STATUSES = [
        ClinicVisit::STATUS_CASHIER_PENDING,
        ClinicVisit::STATUS_COMPLETED,
    ];

    public static function notEnabled(): RmeZeroValueEligibilityResult
    {
        return RmeZeroValueEligibilityResult::refuse(self::NOT_ENABLED, 'Fitur Selesai Tanpa Biaya belum diaktifkan.');
    }

    /** A server-derived Kontrol: the type AND a parent visit, never a client field. */
    public static function isControlVisit(ClinicVisit $visit): bool
    {
        return $visit->visit_type === ClinicVisit::VISIT_TYPE_CONTROL
            && $visit->follow_up_of_visit_id !== null;
    }

    /**
     * The single answer to "is this treatment an approved FREE service?" (owner
     * S6-Q3a). Decided from the treatment's identity and master-data category, never
     * from a description: a free-text line (no treatment) is never free. The
     * `control` category counts only when $validControl — a Kontrol whose parent was
     * really examined — so the Kontrol link alone cannot make anything free.
     */
    public static function isApprovedFreeTreatment(?Treatment $treatment, bool $validControl): bool
    {
        $categoryCode = $treatment?->category?->code;
        if ($categoryCode === null) {
            return false;
        }

        $approved = array_map('strval', (array) config('rme_zero_value_closure.consultation_category_codes', []));
        if ($validControl) {
            $approved = [...$approved, ...array_map('strval', (array) config('rme_zero_value_closure.control_service_category_codes', []))];
        }

        return in_array((string) $categoryCode, $approved, true);
    }

    /**
     * HARDENING S6-Q3b — the ISSUANCE guard. On a server-derived Kontrol, every line
     * whose NET value is Rp0 (priced at Rp0 or discounted down to it) must be a line
     * the closure would accept — decided by the SAME per-line predicate
     * (lineRefusalCode) — and the Kontrol itself must be valid (S6-Q1). A normally
     * paid treatment is billed at a positive net price instead. Returns the operator
     * message (with its stable code) of the first offending line, or null when every
     * line may be issued. Pure: the caller hands in the treatments it already loaded.
     *
     * @param  list<array{treatment_id: int|null, unit_price_cents: int, discount_cents: int, subtotal_cents: int}>  $lines
     * @param  array<int, Treatment>  $treatments  keyed by id, soft-deleted included
     */
    public static function issuanceRefusal(ClinicVisit $visit, array $lines, array $treatments): ?string
    {
        if (! self::isControlVisit($visit)) {
            return null;
        }

        $validControl = self::followsExaminedVisit($visit);

        foreach ($lines as $line) {
            // A line that still carries a positive NET value is billed and paid
            // normally. Only a line that comes out at Rp0 — priced at Rp0 OR
            // discounted down to Rp0 — must be one the closure would accept.
            if ((int) $line['subtotal_cents'] !== 0) {
                continue;
            }

            // S6-Q1: on a Kontrol whose parent is not valid, NO line may be Rp0 —
            // the closure refuses such an invoice whole, so issuing it at Rp0
            // would leave an invoice nobody can pay or close.
            if (! $validControl) {
                return self::issuanceMessage(self::CONTROL_PARENT_INVALID);
            }

            $treatment = $line['treatment_id'] !== null ? ($treatments[(int) $line['treatment_id']] ?? null) : null;
            $code = self::lineRefusalCode($treatment, (int) $line['unit_price_cents'], (int) $line['discount_cents'], true);

            if ($code !== null) {
                return self::issuanceMessage($code);
            }
        }

        return null;
    }

    /**
     * HARDENING (independent review LOW-3) — ONE per-line answer, shared by the
     * closure (evaluate) and the issuance guard, so the two can never disagree about
     * a Rp0 line. Returns the stable refusal code, or null when the line is an
     * approved free service priced at Rp0 without a discount.
     */
    public static function lineRefusalCode(?Treatment $treatment, int $unitPriceCents, int $discountCents, bool $validControl): ?string
    {
        if ($unitPriceCents !== 0 || $discountCents !== 0) {
            return self::LINE_NOT_FREE;
        }

        $excluded = array_map('strval', (array) config('rme_zero_value_closure.excluded_treatment_codes', []));

        if ($treatment !== null && in_array((string) $treatment->code, $excluded, true)) {
            return self::VOUCHER_EXCLUDED;
        }

        if ($treatment !== null && (bool) $treatment->requires_lab) {
            return self::LAB_LINE_EXCLUDED;
        }

        if (! self::isApprovedFreeTreatment($treatment, $validControl)) {
            return self::CATEGORY_NOT_ELIGIBLE;
        }

        return null;
    }

    /** Operator message of an ISSUANCE refusal on a Kontrol, carrying its stable code. */
    private static function issuanceMessage(string $code): string
    {
        $text = match ($code) {
            self::CONTROL_PARENT_INVALID => 'Kunjungan Kontrol ini tidak terhubung ke kunjungan sebelumnya yang sudah diperiksa, sehingga tidak ada item yang boleh Rp0. Beri harga lebih dari Rp0, atau laporkan ke penanggung jawab cabang.',
            self::LINE_NOT_FREE => 'Pada kunjungan Kontrol, item tidak boleh menjadi Rp0 karena diskon. Layanan Kontrol dan Konsultasi Dokter diberi harga Rp0 langsung; tindakan lain wajib diberi harga lebih dari Rp0.',
            self::VOUCHER_EXCLUDED => 'Pada kunjungan Kontrol, voucher tidak boleh dipakai untuk membuat item Rp0.',
            self::LAB_LINE_EXCLUDED => 'Pada kunjungan Kontrol, tindakan yang memerlukan pekerjaan lab tidak boleh Rp0. Tagih dengan harga lebih dari Rp0 agar dibayar dan permintaan lab dibuat seperti biasa.',
            default => 'Pada kunjungan Kontrol, hanya layanan Kontrol dan Konsultasi Dokter yang boleh Rp0. Tindakan lain yang normalnya berbayar wajib diberi harga lebih dari Rp0.',
        };

        return '['.$code.'] '.$text;
    }

    /**
     * The parent is read through the default relation, so a soft-deleted parent
     * reads as missing — fail closed.
     */
    public static function followsExaminedVisit(ClinicVisit $visit): bool
    {
        $visit->loadMissing('followUpOf');
        $parent = $visit->followUpOf;

        return $parent !== null
            && (int) $parent->patient_id === (int) $visit->patient_id
            && in_array($parent->status, self::EXAMINED_PARENT_STATUSES, true);
    }

    /**
     * @param  int  $paymentCount  the number of payment rows of THIS invoice, read by the caller
     */
    public function evaluate(RmeInvoice $invoice, ClinicVisit $visit, int $paymentCount): RmeZeroValueEligibilityResult
    {
        if ($invoice->status !== RmeInvoice::STATUS_UNPAID) {
            return RmeZeroValueEligibilityResult::refuse(self::INVOICE_NOT_OPEN, 'Hanya tagihan berstatus Belum Dibayar yang dapat diselesaikan tanpa biaya.');
        }

        if (RmeInvoiceDocument::toCents($invoice->grand_total) !== 0) {
            return RmeZeroValueEligibilityResult::refuse(self::TOTAL_NOT_ZERO, 'Total tagihan bukan Rp0, sehingga harus dibayar melalui kasir.');
        }

        if ((int) ($invoice->correction_version ?? 0) !== 0) {
            return RmeZeroValueEligibilityResult::refuse(self::INVOICE_CORRECTED, 'Tagihan yang pernah dikoreksi tidak dapat diselesaikan tanpa biaya.');
        }

        if ($paymentCount > 0) {
            return RmeZeroValueEligibilityResult::refuse(self::HAS_PAYMENTS, 'Tagihan ini sudah memiliki pembayaran.');
        }

        if ($visit->status !== ClinicVisit::STATUS_CASHIER_PENDING) {
            return RmeZeroValueEligibilityResult::refuse(self::VISIT_NOT_PENDING, 'Kunjungan tidak sedang menunggu kasir, sehingga tagihan ini tidak dapat diselesaikan tanpa biaya.');
        }

        $invoice->loadMissing(['items.treatment' => fn ($query) => $query->withTrashed()->with(['category' => fn ($category) => $category->withTrashed()])]);
        $items = $invoice->items;

        if ($items->isEmpty()) {
            return RmeZeroValueEligibilityResult::refuse(self::NO_LINES, 'Tagihan tidak memiliki item tindakan.');
        }

        $control = self::isControlVisit($visit);

        if ($control && ! self::followsExaminedVisit($visit)) {
            return RmeZeroValueEligibilityResult::refuse(self::CONTROL_PARENT_INVALID, 'Kunjungan Kontrol ini tidak terhubung ke kunjungan sebelumnya yang sudah diperiksa, sehingga tidak dapat diselesaikan tanpa biaya.');
        }

        // From here $control means a VALID Kontrol: an invalid parent was refused above.
        foreach ($items as $item) {
            /** @var RmeInvoiceItem $item */
            $code = self::lineRefusalCode(
                $item->treatment,
                RmeInvoiceDocument::toCents($item->unit_price),
                RmeInvoiceDocument::toCents($item->discount),
                $control,
            );

            if ($code !== null) {
                return RmeZeroValueEligibilityResult::refuse($code, self::closureMessage($code, $control));
            }
        }

        return RmeZeroValueEligibilityResult::forCategory($control ? RmeZeroValueCategory::CONTROL : RmeZeroValueCategory::CONSULTATION);
    }

    /** Operator message of a CLOSURE refusal for a per-line code (unchanged wording). */
    private static function closureMessage(string $code, bool $control): string
    {
        return match ($code) {
            self::LINE_NOT_FREE => 'Ada item yang menjadi Rp0 karena diskon. Diskon bukan layanan gratis dan tidak dapat diselesaikan tanpa biaya.',
            self::VOUCHER_EXCLUDED => 'Voucher tidak termasuk layanan yang dapat diselesaikan tanpa biaya.',
            self::LAB_LINE_EXCLUDED => 'Ada item yang memerlukan pekerjaan lab. Selesai Tanpa Biaya tidak membuat permintaan lab, sehingga item ini harus diproses melalui pembayaran.',
            default => $control
                ? 'Pada kunjungan Kontrol, hanya layanan Kontrol dan Konsultasi Dokter yang boleh Rp0. Tindakan lain yang normalnya berbayar wajib ditagih dengan harga lebih dari Rp0 dan dibayar seperti biasa.'
                : 'Di luar kunjungan Kontrol, hanya Konsultasi Dokter yang dapat diselesaikan tanpa biaya.',
        };
    }
}
