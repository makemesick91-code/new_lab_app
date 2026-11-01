<?php

declare(strict_types=1);

namespace App\Modules\RmeInvoice\Support;

use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Odontogram\Models\Odontogram;
use App\Modules\PerformedTreatment\Support\RmePerformedTreatmentEvidence;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use App\Modules\RmeInvoice\Services\RmeClinicalBillingConsistencyService;
use App\Modules\Treatment\Models\Treatment;

/**
 * HARDENING-RME-RP0-CLINICAL-BILLING-CONSISTENCY-1 (rule 218) — the clinical vs
 * billing cross-check of a Rp0 invoice. Pure: it reads only the rows it is handed
 * and writes nothing. {@see RmeClinicalBillingConsistencyService}
 * gathers those rows; the closure records the answer in its audit row.
 *
 * THE FINDING (clinical source authority audit, 2026-11-01). DaengtisiaMS holds no
 * structured record of a treatment the doctor PERFORMED. The RME is handwriting (an
 * image), the odontogram is a tooth-CONDITION chart whose only "perawatan" column is
 * free text, a structured diagnosis is a diagnosis, and the registered treatment
 * ("Tindakan Awal") is a triage choice made at registration — in production it is
 * absent from the bill on 6 of 10 legitimately paid invoices. The only structured
 * procedure identity in the system is the invoice line the cashier chose (D21).
 *
 * OWNER DECISIONS (2026-11-01). CB-Q1: the authoritative evidence will be a
 * structured doctor input, built in a separate sprint (CB-Q3). CB-Q2: until then
 * every signal here is DETECTIVE — recorded and surfaced, never a refusal.
 *
 * So this class can only ever answer:
 *   - REVIEW_REQUIRED — a structured signal fired;
 *   - INSUFFICIENT_STRUCTURED_EVIDENCE — none fired. This is NOT "consistent": the
 *     handwritten record is never machine-verified, so silence proves nothing;
 *   - NOT_APPLICABLE — not a Rp0 invoice (a positive total is paid normally).
 * It never answers CONSISTENT or CONFIRMED_DISCREPANCY: there is no authoritative
 * source to confirm either. Those belong to the structured-doctor-input sprint.
 *
 * Signals (deterministic, from structured server rows only — a description, a note
 * or any free text is never read):
 *   - CBC_REGISTERED_TREATMENT_NOT_BILLED — the registered treatment is not an
 *     approved free service (decided by the SAME predicate as the closure,
 *     RmeZeroValueEligibility::lineRefusalCode) and no line of the invoice carries it;
 *   - CBC_ODONTOGRAM_PROCEDURE_CHANGE — on this visit's chart a tooth now reads
 *     filling, crown, root_treated or missing, and the patient's most recent EARLIER
 *     chart recorded the SAME tooth with a different status. A tooth the earlier
 *     chart did not record is unknown, not normal, and is never counted; a first
 *     chart has no baseline; a newly found caries is a finding, not a procedure.
 *     It says the status CHANGED since the previous chart — not that the procedure
 *     happened at this visit (on a Kontrol the previous chart is often the parent's,
 *     recorded in `baseline_is_control_parent`).
 */
final class RmeClinicalBillingConsistency
{
    public const VERSION = 1;

    /**
     * FEATURE-RME-DOCTOR-PERFORMED-TREATMENTS-1 (rule 219) — the snapshot shape
     * when the doctor's structured record was consulted (capability ON): version 1
     * plus `doctor_evidence`. With the capability OFF the snapshot stays version 1,
     * byte for byte (rule 218 CBC_FLAG_OFF_UNCHANGED).
     */
    public const VERSION_WITH_DOCTOR_EVIDENCE = 2;

    public const STATUS_REVIEW_REQUIRED = 'REVIEW_REQUIRED';

    public const STATUS_INSUFFICIENT_STRUCTURED_EVIDENCE = 'INSUFFICIENT_STRUCTURED_EVIDENCE';

    public const STATUS_NOT_APPLICABLE = 'NOT_APPLICABLE';

    /**
     * An oversight label, never an evaluator output: a NO_CHARGE closure whose audit
     * row carries no cross-check (recorded before this rule existed).
     */
    public const STATUS_NOT_EVALUATED = 'NOT_EVALUATED';

    /** What a closure can record. A NOT_APPLICABLE invoice is never closed free. */
    public const RECORDED_STATUSES = [
        self::STATUS_REVIEW_REQUIRED,
        self::STATUS_INSUFFICIENT_STRUCTURED_EVIDENCE,
    ];

    public const SIGNAL_ODONTOGRAM_PROCEDURE_CHANGE = 'CBC_ODONTOGRAM_PROCEDURE_CHANGE';

    public const SIGNAL_REGISTERED_TREATMENT_NOT_BILLED = 'CBC_REGISTERED_TREATMENT_NOT_BILLED';

    /**
     * Rule 219 — the treating doctor recorded a performed treatment that is not an
     * approved free service on this visit, and no invoice line carries its identity.
     * The strongest signal (authoritative clinical source, CB-Q1) — and still
     * DETECTIVE: no owner decision has approved a blocking contract (CB-Q2).
     */
    public const SIGNAL_PERFORMED_TREATMENT_NOT_BILLED = 'CBC_PERFORMED_TREATMENT_NOT_BILLED';

    public const SIGNALS = [
        self::SIGNAL_ODONTOGRAM_PROCEDURE_CHANGE,
        self::SIGNAL_REGISTERED_TREATMENT_NOT_BILLED,
        self::SIGNAL_PERFORMED_TREATMENT_NOT_BILLED,
    ];

    /** Tooth statuses whose appearance on a tooth suggests a procedure was performed. */
    public const PROCEDURE_IMPLYING_TOOTH_STATUSES = ['filling', 'crown', 'root_treated', 'missing'];

    /**
     * @param  Treatment|null  $registered  the visit's registered treatment, soft-deleted master data included
     * @param  Odontogram|null  $current  this visit's chart, when it records teeth
     * @param  Odontogram|null  $baseline  the patient's most recent EARLIER chart that records teeth
     */
    public static function evaluate(
        RmeInvoice $invoice,
        ClinicVisit $visit,
        ?Treatment $registered,
        ?Odontogram $current,
        ?Odontogram $baseline,
        ?RmePerformedTreatmentEvidence $doctor = null,
    ): RmeClinicalBillingConsistencyResult {
        if (RmeInvoiceDocument::toCents($invoice->grand_total) !== 0) {
            return RmeClinicalBillingConsistencyResult::notApplicable();
        }

        $signals = [];

        // ─── Signal 1: the registered treatment ──────────────────────────────
        $validControl = RmeZeroValueEligibility::isControlVisit($visit)
            && RmeZeroValueEligibility::followsExaminedVisit($visit);

        $billedTreatmentIds = $invoice->items
            ->pluck('treatment_id')
            ->filter(fn ($id) => $id !== null)
            ->map(fn ($id) => (int) $id)
            ->all();

        $registeredFree = $registered === null ? null
            : RmeZeroValueEligibility::lineRefusalCode($registered, 0, 0, $validControl) === null;
        $registeredBilled = $registered === null ? null
            : in_array((int) $registered->id, $billedTreatmentIds, true);

        if ($registered !== null && $registeredFree === false && $registeredBilled === false) {
            $signals[] = self::SIGNAL_REGISTERED_TREATMENT_NOT_BILLED;
        }

        // ─── Signal 2: the odontogram against the previous chart ─────────────
        $changed = ($current !== null && $baseline !== null)
            ? self::procedureChangeCount(self::teeth($current), self::teeth($baseline))
            : 0;

        if ($changed > 0) {
            $signals[] = self::SIGNAL_ODONTOGRAM_PROCEDURE_CHANGE;
        }

        // ─── Signal 3 (rule 219): the doctor's structured record ─────────────
        // Null while the capability is OFF: nothing below runs and the snapshot
        // keeps its version 1 shape. The other two signals stay independent — a
        // "no treatment" declaration never silences a chart change.
        $doctorEvidence = $doctor?->snapshot($billedTreatmentIds, $validControl);

        if ($doctorEvidence !== null && $doctorEvidence['unbilled_performed_count'] > 0) {
            $signals[] = self::SIGNAL_PERFORMED_TREATMENT_NOT_BILLED;
        }

        return RmeClinicalBillingConsistencyResult::evaluated(
            $signals,
            [
                'registered_treatment_id' => $registered !== null ? (int) $registered->id : null,
                'registered_treatment_code' => $registered?->code !== null ? (string) $registered->code : null,
                'registered_treatment_free' => $registeredFree,
                'registered_treatment_billed' => $registeredBilled,
                'odontogram_id' => $current !== null ? (int) $current->id : null,
                'baseline_odontogram_id' => ($current !== null && $baseline !== null) ? (int) $baseline->id : null,
                // On a Kontrol the previous chart is usually the parent visit's: a filling done
                // AND billed there, charted as caries before treatment, reads as a change here.
                // Recorded so a reviewer can tell that likely false positive apart.
                'baseline_is_control_parent' => ($current !== null && $baseline !== null && $visit->follow_up_of_visit_id !== null)
                    ? (int) $baseline->clinic_visit_id === (int) $visit->follow_up_of_visit_id
                    : null,
                'procedure_change_tooth_count' => $changed,
                ...($doctorEvidence !== null ? ['doctor_evidence' => $doctorEvidence] : []),
            ],
            [
                'registered_treatment_name' => in_array(self::SIGNAL_REGISTERED_TREATMENT_NOT_BILLED, $signals, true)
                    ? (string) $registered?->name
                    : null,
            ],
            $doctorEvidence !== null ? self::VERSION_WITH_DOCTOR_EVIDENCE : self::VERSION,
        );
    }

    /**
     * @param  array<string, string>  $current  FDI => status of this visit's chart
     * @param  array<string, string>  $baseline  FDI => status of the earlier chart
     */
    public static function procedureChangeCount(array $current, array $baseline): int
    {
        $count = 0;
        foreach ($current as $fdi => $status) {
            $before = $baseline[$fdi] ?? null;

            if ($before !== null
                && $before !== $status
                && in_array($status, self::PROCEDURE_IMPLYING_TOOTH_STATUSES, true)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * FDI => status of every CHARTED tooth. The per-tooth `status` is the chart's
     * single source of truth (Odontogram::DMF_MAP); notes are never read.
     *
     * @return array<string, string>
     */
    public static function teeth(Odontogram $chart): array
    {
        $teeth = $chart->tooth_map_payload['teeth'] ?? null;
        if (! is_array($teeth)) {
            return [];
        }

        $statuses = [];
        foreach ($teeth as $fdi => $tooth) {
            $status = is_array($tooth) ? ($tooth['status'] ?? null) : null;
            if (is_string($status) && $status !== '') {
                $statuses[(string) $fdi] = $status;
            }
        }

        return $statuses;
    }

    public static function statusLabel(?string $status): string
    {
        return match ($status) {
            self::STATUS_REVIEW_REQUIRED => 'Perlu ditinjau',
            self::STATUS_INSUFFICIENT_STRUCTURED_EVIDENCE => 'Tanpa sinyal (bukti terstruktur belum ada)',
            self::STATUS_NOT_APPLICABLE => 'Tidak berlaku',
            default => 'Belum dievaluasi',
        };
    }

    public static function signalLabel(string $signal): string
    {
        return match ($signal) {
            // Worded to claim no more than the signal proves (independent review F4/F5).
            self::SIGNAL_REGISTERED_TREATMENT_NOT_BILLED => 'Tindakan Awal bukan layanan gratis dan tidak ditagih',
            self::SIGNAL_ODONTOGRAM_PROCEDURE_CHANGE => 'Status gigi berubah sejak odontogram sebelumnya',
            self::SIGNAL_PERFORMED_TREATMENT_NOT_BILLED => 'Tindakan yang dicatat dokter tidak ada di tagihan',
            default => $signal,
        };
    }
}
