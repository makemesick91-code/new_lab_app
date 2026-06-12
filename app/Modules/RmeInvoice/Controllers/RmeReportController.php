<?php

namespace App\Modules\RmeInvoice\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\RmeInvoice\Models\RmePayment;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Sprint 23 Phase 23.5 — Separated RME report pages.
 *
 * RME is a multi-branch module, so both reports honour an optional branch
 * filter (RME-enabled branches only). Access is split by permission:
 *   - patients()  → view_rme_patient_reports
 *   - payments()  → view_rme_payment_reports
 * The route layer enforces these; a viewer with only one permission can never
 * reach the other page.
 */
class RmeReportController extends Controller
{
    public function patients(Request $request): View
    {
        $branchId = $this->resolveBranchId($request);

        $visits = ClinicVisit::query()
            ->with(['patient:id,name', 'branch:id,name'])
            ->when($branchId !== null, fn (Builder $q) => $q->where('branch_id', $branchId))
            ->where('status', '!=', ClinicVisit::STATUS_CANCELLED)
            ->latest('visit_date')
            ->limit(100)
            ->get();

        return view('rme.reports.patients', [
            'branches' => $this->rmeBranches(),
            'selectedBranchId' => $branchId,
            'visits' => $visits,
            'totalVisits' => $visits->count(),
        ]);
    }

    public function payments(Request $request): View
    {
        $branchId = $this->resolveBranchId($request);

        $payments = RmePayment::query()
            ->with(['patient:id,name', 'branch:id,name', 'rmeInvoice:id,invoice_number'])
            ->when($branchId !== null, fn (Builder $q) => $q->where('branch_id', $branchId))
            ->latest('paid_at')
            ->limit(100)
            ->get();

        return view('rme.reports.payments', [
            'branches' => $this->rmeBranches(),
            'selectedBranchId' => $branchId,
            'payments' => $payments,
            'totalAmount' => (float) $payments->sum('amount'),
        ]);
    }

    private function resolveBranchId(Request $request): ?int
    {
        if (! $request->filled('branch_id')) {
            return null;
        }

        $requested = (int) $request->input('branch_id');

        return Branch::query()
            ->where('id', $requested)
            ->where('is_active', true)
            ->rmeEnabled()
            ->value('id');
    }

    private function rmeBranches()
    {
        return Branch::query()
            ->where('is_active', true)
            ->rmeEnabled()
            ->orderBy('name')
            ->get(['id', 'name', 'code']);
    }
}
