<?php

namespace App\Modules\Reporting\Services;

use App\Models\User;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\LabOrder\Models\LabCaseCandidate;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use Illuminate\Support\Carbon;

/**
 * Permission-aware read-only drilldown links for Owner Dashboard RME/Lab KPIs.
 */
class OwnerDashboardRmeLabDrilldownService
{
    /**
     * @return array<string, string>
     */
    public function linksFor(User $user, ?Carbon $asOf = null): array
    {
        $today = ($asOf ?? now())->toDateString();
        $links = [];

        if ($user->canAny(['view_clinic_visits', 'manage_clinic_visits'])) {
            $links['visits_today'] = route('rme.visits.index', ['visit_date' => $today]);
            $links['visits_cashier_pending'] = route('rme.visits.index', [
                'status' => ClinicVisit::STATUS_CASHIER_PENDING,
            ]);
            $links['medical_records_draft'] = route('rme.medical-records.index', [
                'status' => MedicalRecord::STATUS_DRAFT,
            ]);
            $links['medical_records_final_today'] = route('rme.medical-records.index', [
                'status' => MedicalRecord::STATUS_FINAL,
                'visit_date_from' => $today,
                'visit_date_to' => $today,
            ]);
        }

        if ($user->can('manage_rme_billing')) {
            $links['rme_invoices_unpaid'] = route('rme.cashier.index');
            $links['rme_invoices_paid_today'] = route('rme.cashier.index');
        }

        if ($user->canAny(['view_lab_orders', 'manage_lab_orders'])) {
            $links['lab_candidates_pending'] = route('lab-case-candidates.index', [
                'status' => LabCaseCandidate::STATUS_PENDING_REVIEW,
            ]);
            $links['lab_candidates_converted_today'] = route('lab-case-candidates.index', [
                'status' => LabCaseCandidate::STATUS_CONVERTED_TO_LAB_ORDER,
            ]);
            $links['lab_orders_from_rme_today'] = route('lab-orders.index');
        }

        return $links;
    }
}
