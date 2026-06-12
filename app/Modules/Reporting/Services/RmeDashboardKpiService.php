<?php

namespace App\Modules\Reporting\Services;

use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use App\Modules\RmeInvoice\Models\RmePayment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Sprint 23 Phase 23.5 — Branch-aware RME KPI service.
 *
 * RME is a multi-branch module: every metric here honours the selected branch
 * filter (null = all active RME branches). Lab metrics are intentionally NOT
 * part of this service — see {@see LabDashboardKpiService}.
 */
class RmeDashboardKpiService
{
    public function resolveSelectedBranchId(?int $requestedBranchId): ?int
    {
        if ($requestedBranchId === null || $requestedBranchId <= 0) {
            return null;
        }

        return Branch::query()
            ->where('id', $requestedBranchId)
            ->where('is_active', true)
            ->rmeEnabled()
            ->value('id');
    }

    /**
     * @return array<string, mixed>
     */
    public function metrics(?int $branchId = null, ?Carbon $asOf = null): array
    {
        $today = ($asOf ?? now())->toDateString();
        $branchIds = $this->resolveBranchIds($branchId);

        return [
            'visits_today' => $this->visitQuery($branchIds)
                ->whereDate('visit_date', $today)
                ->where('status', '!=', ClinicVisit::STATUS_CANCELLED)
                ->count(),
            'visits_cashier_pending' => $this->visitQuery($branchIds)
                ->where('status', ClinicVisit::STATUS_CASHIER_PENDING)
                ->count(),
            'medical_records_draft' => $this->medicalRecordQuery($branchIds)
                ->where('status', MedicalRecord::STATUS_DRAFT)
                ->count(),
            'rme_invoices_unpaid' => $this->rmeInvoiceQuery($branchIds)
                ->whereIn('status', [RmeInvoice::STATUS_DRAFT, RmeInvoice::STATUS_UNPAID])
                ->count(),
            'rme_invoices_paid_today' => $this->rmeInvoiceQuery($branchIds)
                ->where('status', RmeInvoice::STATUS_PAID)
                ->whereHas('payments', fn (Builder $query) => $query->whereDate('paid_at', $today))
                ->count(),
            'rme_revenue_paid_today' => (float) $this->rmePaymentQuery($branchIds)
                ->whereDate('paid_at', $today)
                ->sum('amount'),
            'scope_label' => $branchId === null
                ? 'Semua cabang RME aktif'
                : (Branch::query()->find($branchId)?->name ?? 'Cabang aktif'),
        ];
    }

    /**
     * @return array<int, int>|null null = all active RME branches.
     */
    private function resolveBranchIds(?int $branchId): ?array
    {
        if ($branchId !== null) {
            $isActive = Branch::query()
                ->where('id', $branchId)
                ->where('is_active', true)
                ->rmeEnabled()
                ->exists();

            return $isActive ? [$branchId] : $this->activeBranchIds();
        }

        return $this->activeBranchIds();
    }

    /**
     * @return array<int, int>
     */
    private function activeBranchIds(): array
    {
        return Branch::query()
            ->where('is_active', true)
            ->rmeEnabled()
            ->pluck('id')
            ->all();
    }

    /**
     * @param  array<int, int>|null  $branchIds
     */
    private function visitQuery(?array $branchIds): Builder
    {
        return ClinicVisit::query()
            ->when($branchIds !== null, fn (Builder $query) => $query->whereIn('branch_id', $branchIds));
    }

    /**
     * @param  array<int, int>|null  $branchIds
     */
    private function medicalRecordQuery(?array $branchIds): Builder
    {
        return MedicalRecord::query()
            ->when($branchIds !== null, fn (Builder $query) => $query->whereIn('branch_id', $branchIds));
    }

    /**
     * @param  array<int, int>|null  $branchIds
     */
    private function rmeInvoiceQuery(?array $branchIds): Builder
    {
        return RmeInvoice::query()
            ->when($branchIds !== null, fn (Builder $query) => $query->whereIn('branch_id', $branchIds));
    }

    /**
     * @param  array<int, int>|null  $branchIds
     */
    private function rmePaymentQuery(?array $branchIds): Builder
    {
        return RmePayment::query()
            ->when($branchIds !== null, fn (Builder $query) => $query->whereIn('branch_id', $branchIds));
    }
}
