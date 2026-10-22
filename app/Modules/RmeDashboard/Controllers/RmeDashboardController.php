<?php

namespace App\Modules\RmeDashboard\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\ClinicVisit\Services\ClinicVisitService;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\MedicalRecord\Services\MedicalRecordService;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use App\Modules\RmeInvoice\Models\RmePayment;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * Sprint 58.4 — Standalone RME operational dashboard.
 *
 * Replaces the Sprint 58.3 availability hotfix (which redirected /rme/dashboard
 * to /rme/visits) with a real "Dashboard RME" page of aggregate KPI cards and
 * shortcut links. Aggregate counts/totals only — no patient-level detail is
 * queried or rendered. Branch isolation mirrors the existing RME listing
 * pattern: default to all RME-enabled branches, optionally narrow to a single
 * branch via ?branch_id when it is within the allowed set.
 *
 * AUDIT-RME-CLINIC-VISIT-BRANCH-AUTHORITY-1 — "the allowed set" is now the
 * canonical working-branch scope of the authenticated user
 * (ClinicVisitService::scopedBranchIdsFor()), not every RME branch. Before this
 * audit only `visits_today` went through that scope; every other card —
 * including today's PAYMENT TOTAL and the receivables count — summed the whole
 * estate for a context-bound Kasir/Admin Klinik/Perawat, and `?branch_id=`
 * reached any other branch. Governance roles keep the full RME set, so their
 * dashboard is unchanged.
 */
class RmeDashboardController extends Controller
{
    public function __construct(
        private readonly ClinicVisitService $visits,
        private readonly MedicalRecordService $medicalRecords,
    ) {}

    public function index(Request $request): View
    {
        $actor = $request->user();
        $allowedBranchIds = $this->visits->scopedBranchIdsFor($actor);
        $requestedBranchId = $request->integer('branch_id') ?: null;

        // Only honor an explicit branch filter when it is inside the actor's own
        // scope; otherwise fall back to that scope. A crafted ?branch_id= can
        // only NARROW, never reach a branch the actor does not work in.
        $selectedBranchId = ($requestedBranchId !== null && in_array($requestedBranchId, $allowedBranchIds, true))
            ? $requestedBranchId
            : null;

        $branchIds = $selectedBranchId !== null ? [$selectedBranchId] : $allowedBranchIds;

        $today = Carbon::today();
        $monthStart = Carbon::now()->startOfMonth();
        $monthEnd = Carbon::now()->endOfMonth();

        $metrics = [
            'visits_today' => $this->visits->visitsTodayCount($actor, $selectedBranchId),
            'visits_month' => ClinicVisit::query()
                ->whereIn('branch_id', $branchIds)
                ->whereBetween('visit_date', [$monthStart->toDateString(), $monthEnd->toDateString()])
                ->count(),
            'new_patients_month' => ClinicVisit::query()
                ->whereIn('branch_id', $branchIds)
                ->where('visit_type', ClinicVisit::VISIT_TYPE_NEW)
                ->whereBetween('visit_date', [$monthStart->toDateString(), $monthEnd->toDateString()])
                ->count(),
            'follow_up_month' => ClinicVisit::query()
                ->whereIn('branch_id', $branchIds)
                ->whereIn('visit_type', ClinicVisit::FOLLOW_UP_VISIT_TYPES)
                ->whereBetween('visit_date', [$monthStart->toDateString(), $monthEnd->toDateString()])
                ->count(),
            'medical_records_draft' => $this->medicalRecords->draftCount($branchIds),
            'medical_records_finalized_month' => MedicalRecord::query()
                ->whereIn('branch_id', $branchIds)
                ->where('status', MedicalRecord::STATUS_FINAL)
                ->whereBetween('finalized_at', [$monthStart, $monthEnd])
                ->count(),
            'cashier_pending' => ClinicVisit::query()
                ->whereIn('branch_id', $branchIds)
                ->where('status', ClinicVisit::STATUS_CASHIER_PENDING)
                ->count(),
            'active_receivables' => RmeInvoice::query()
                ->whereIn('branch_id', $branchIds)
                ->whereIn('status', [RmeInvoice::STATUS_UNPAID, RmeInvoice::STATUS_PARTIAL])
                ->where('grand_total', '>', 0)
                ->whereRaw('trx_rme_invoices.grand_total > (SELECT COALESCE(SUM(amount), 0) FROM trx_rme_payments WHERE rme_invoice_id = trx_rme_invoices.id)')
                ->count(),
            'payments_today_total' => (float) RmePayment::query()
                ->whereIn('branch_id', $branchIds)
                ->whereDate('paid_at', $today->toDateString())
                ->sum('amount'),
        ];

        return view('rme.dashboard.index', [
            'metrics' => $metrics,
            // Never offer a branch the actor cannot read (same source as the visit list).
            'rmeBranches' => $this->visits->selectableRmeBranches($actor),
            'selectedBranchId' => $selectedBranchId,
        ]);
    }
}
