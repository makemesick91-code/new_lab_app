<?php

namespace App\Modules\ClinicVisit\Services;

use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use Illuminate\Support\Collection;

/**
 * The view data of the native RME print / PDF (`rme.visits.print`,
 * `rme.visits.print-pdf`, both rendering `rme.visits.partials.print-body`).
 *
 * Extracted verbatim from ClinicVisitController (FEATURE-FINANCE-KLINIK-DATA-
 * ANALYST-ROLES-1) so the operational print and the read-only oversight print
 * are built by ONE builder and can never drift apart. It READS only.
 *
 * Authorization is the caller's job — this class assumes the caller already
 * decided the actor may print this visit.
 */
class ClinicVisitPrintDataBuilder
{
    /**
     * @return array{visit: ClinicVisit, paidInvoice: ?RmeInvoice, payment: mixed, labCaseCandidates: Collection}
     */
    public function build(ClinicVisit $clinicVisit): array
    {
        $clinicVisit->load([
            'patient',
            'doctor',
            'branch',
            'clinic',
            'initialTreatment',
            'medicalRecord.handwriting',
            'medicalRecord.finalizedBy',
        ]);

        $paidInvoice = RmeInvoice::query()
            ->where('clinic_visit_id', $clinicVisit->id)
            ->where('status', RmeInvoice::STATUS_PAID)
            ->with([
                'items',
                'payments.paymentMethod',
                'labCaseCandidates.convertedLabOrder',
                'labCaseCandidates.treatment',
            ])
            ->first();

        $payment = $paidInvoice?->payments->first();

        // FIX-RME-CONSENT-WORKFLOW-PRINT-UX-2 / FIX-05 — the RME print bundle no
        // longer composes the odontogram, so it no longer builds the Sprint 63.1
        // structured odontogram view-model. The formatter itself is unchanged and
        // is still the single source of truth for the standalone odontogram print
        // (OdontogramController@print), which is untouched.
        return [
            'visit' => $clinicVisit,
            'paidInvoice' => $paidInvoice,
            'payment' => $payment,
            'labCaseCandidates' => $paidInvoice?->labCaseCandidates ?? collect(),
        ];
    }
}
