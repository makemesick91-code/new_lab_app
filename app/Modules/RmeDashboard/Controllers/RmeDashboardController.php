<?php

namespace App\Modules\RmeDashboard\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\ClinicOversight\Support\ClinicalDayWindow;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\ClinicVisit\Services\ClinicVisitService;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\MedicalRecord\Services\MedicalRecordService;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use App\Modules\RmeInvoice\Models\RmePayment;
use App\Modules\RmeInvoice\Support\RmeInvoiceLedger;
use App\Support\Clinical\ClinicalClock;
use Illuminate\Http\Request;
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
 *
 * DMS-RME-COMPREHENSIVE-CLOSURE-1 PR8 (internal memo 2026-10-17, M2.5; owner
 * decision 2026-11-17, rule 238 §1) — the two payment cards ("Piutang RME
 * Aktif", "Pembayaran Hari Ini") are payment information. They are computed
 * only for an account that holds `manage_rme_billing` or
 * `view_rme_payment_reports` (effective permissions, CG-06); for any other
 * account the figures are never queried, so they cannot leak through the view
 * data either.
 */
class RmeDashboardController extends Controller
{
    public function __construct(
        private readonly ClinicVisitService $visits,
        private readonly MedicalRecordService $medicalRecords,
        private readonly ClinicalClock $clock,
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

        // SECURITY-F4A: the clinic day and month (Asia/Makassar), never the UTC
        // ones — between 00:00 and 08:00 WITA those name the previous day.
        // DATE columns compare against clinic dates; TIMESTAMP columns against
        // the UTC instants that bound the clinic period (half-open).
        $today = ClinicalDayWindow::today($this->clock);
        $monthStart = $this->clock->today()->startOfMonth();
        $monthEnd = $monthStart->endOfMonth();
        $monthStartUtc = $monthStart->utc()->format('Y-m-d H:i:s');
        $nextMonthStartUtc = $monthStart->addMonth()->utc()->format('Y-m-d H:i:s');

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
                ->where('finalized_at', '>=', $monthStartUtc)
                ->where('finalized_at', '<', $nextMonthStartUtc)
                ->count(),
            'cashier_pending' => ClinicVisit::query()
                ->whereIn('branch_id', $branchIds)
                ->where('status', ClinicVisit::STATUS_CASHIER_PENDING)
                ->count(),
        ];

        $showPaymentTotals = self::mayViewPaymentTotals($actor);

        if ($showPaymentTotals) {
            $metrics['active_receivables'] = RmeInvoice::query()
                ->whereIn('branch_id', $branchIds)
                ->whereIn('status', [RmeInvoice::STATUS_UNPAID, RmeInvoice::STATUS_PARTIAL])
                ->where('grand_total', '>', 0)
                ->whereRaw('trx_rme_invoices.grand_total > '.RmeInvoiceLedger::netReceivedSql('trx_rme_invoices.id'))
                ->count();
            $metrics['payments_today_total'] = (float) RmePayment::query()
                ->whereIn('branch_id', $branchIds)
                ->where('paid_at', '>=', $today->startUtcString())
                ->where('paid_at', '<', $today->endUtcString())
                ->sum('amount');
        }

        return view('rme.dashboard.index', [
            'metrics' => $metrics,
            'showPaymentTotals' => $showPaymentTotals,
            // Never offer a branch the actor cannot read (same source as the visit list).
            'rmeBranches' => $this->visits->selectableRmeBranches($actor),
            'selectedBranchId' => $selectedBranchId,
        ]);
    }

    /**
     * Rule 238 §1 — payment totals need payment authority: cashier work
     * (`manage_rme_billing`) or the payment report (`view_rme_payment_reports`).
     * Decided on effective permissions, never on a role name.
     */
    public static function mayViewPaymentTotals(?User $actor): bool
    {
        return $actor !== null
            && ($actor->can('manage_rme_billing') || $actor->can('view_rme_payment_reports'));
    }
}
