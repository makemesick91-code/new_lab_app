<?php

declare(strict_types=1);

namespace App\Modules\RmeInvoice\Support;

use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use App\Modules\RmeInvoice\Models\RmeInvoiceItem;

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
 * Narrowed after the independent security review (both fail closed, recorded in
 * rule 217 as pending owner confirmation, never as an owner decision):
 *  - a Kontrol must follow a REAL examined visit of the same patient — the parent
 *    exists, is not soft-deleted, and reached cashier_pending or completed. A link
 *    to a cancelled or never-examined visit is a label, not a follow-up;
 *  - a line that requires lab work is never closed free: NO_CHARGE generates no
 *    lab candidate, so closing it would silently drop the lab work.
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
     * The parent is read through the default relation, so a soft-deleted parent
     * reads as missing — fail closed.
     */
    private static function followsExaminedVisit(ClinicVisit $visit): bool
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
        $excluded = array_map('strval', (array) config('rme_zero_value_closure.excluded_treatment_codes', []));
        $consultationCategories = array_map('strval', (array) config('rme_zero_value_closure.consultation_category_codes', []));

        foreach ($items as $item) {
            /** @var RmeInvoiceItem $item */
            if (RmeInvoiceDocument::toCents($item->unit_price) !== 0 || RmeInvoiceDocument::toCents($item->discount) !== 0) {
                return RmeZeroValueEligibilityResult::refuse(self::LINE_NOT_FREE, 'Ada item yang menjadi Rp0 karena diskon. Diskon bukan layanan gratis dan tidak dapat diselesaikan tanpa biaya.');
            }

            $treatment = $item->treatment;

            if ($treatment !== null && in_array((string) $treatment->code, $excluded, true)) {
                return RmeZeroValueEligibilityResult::refuse(self::VOUCHER_EXCLUDED, 'Voucher tidak termasuk layanan yang dapat diselesaikan tanpa biaya.');
            }

            if ($treatment !== null && (bool) $treatment->requires_lab) {
                return RmeZeroValueEligibilityResult::refuse(self::LAB_LINE_EXCLUDED, 'Ada item yang memerlukan pekerjaan lab. Selesai Tanpa Biaya tidak membuat permintaan lab, sehingga item ini harus diproses melalui pembayaran.');
            }

            if ($control) {
                continue;
            }

            $categoryCode = $treatment?->category?->code;

            if ($categoryCode === null || ! in_array((string) $categoryCode, $consultationCategories, true)) {
                return RmeZeroValueEligibilityResult::refuse(self::CATEGORY_NOT_ELIGIBLE, 'Di luar kunjungan Kontrol, hanya Konsultasi Dokter yang dapat diselesaikan tanpa biaya.');
            }
        }

        return RmeZeroValueEligibilityResult::forCategory($control ? RmeZeroValueCategory::CONTROL : RmeZeroValueCategory::CONSULTATION);
    }
}
