<?php

namespace App\Modules\Reporting\Services;

use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\LabOrder\Models\LabCaseCandidate;
use App\Modules\LabOrder\Models\LabOrder;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use App\Modules\RmeInvoice\Models\RmePayment;
use App\Modules\RmeInvoice\Models\RmeReceivableFollowUp;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Read-only RME/Lab pilot KPIs for the Owner Dashboard.
 */
class OwnerDashboardRmeLabKpiService
{
    /**
     * @return array<string, mixed>
     */
    public function resolveSelectedBranchId(?int $requestedBranchId): ?int
    {
        if ($requestedBranchId === null || $requestedBranchId <= 0) {
            return null;
        }

        return Branch::query()
            ->where('id', $requestedBranchId)
            ->where('is_active', true)
            ->value('id');
    }

    /**
     * @return Collection<int, Branch>
     */
    public function activeBranches(): Collection
    {
        return Branch::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'code']);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function branchSummary(?int $branchId = null, ?Carbon $asOf = null): array
    {
        $today = ($asOf ?? now())->toDateString();
        $branchIds = $this->resolveBranchIds($branchId);

        $branches = Branch::query()
            ->when($branchIds !== null, fn (Builder $query) => $query->whereIn('id', $branchIds))
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'code']);

        return $branches->map(function (Branch $branch) use ($today): array {
            $visitsToday = $this->visitQuery([$branch->id])
                ->whereDate('visit_date', $today)
                ->where('status', '!=', ClinicVisit::STATUS_CANCELLED)
                ->count();

            $cashierPending = $this->visitQuery([$branch->id])
                ->where('status', ClinicVisit::STATUS_CASHIER_PENDING)
                ->count();

            $unpaidInvoices = $this->rmeInvoiceQuery([$branch->id])
                ->whereIn('status', [RmeInvoice::STATUS_DRAFT, RmeInvoice::STATUS_UNPAID])
                ->count();

            $pendingCandidates = $this->labCandidateQuery([$branch->id])
                ->where('status', LabCaseCandidate::STATUS_PENDING_REVIEW)
                ->count();

            $convertedToday = $this->labCandidateQuery([$branch->id])
                ->where('status', LabCaseCandidate::STATUS_CONVERTED_TO_LAB_ORDER)
                ->whereDate('reviewed_at', $today)
                ->count();

            $draftRecords = $this->medicalRecordQuery([$branch->id])
                ->where('status', MedicalRecord::STATUS_DRAFT)
                ->count();

            return [
                'branch_id' => $branch->id,
                'branch_name' => $branch->name,
                'visits_today' => $visitsToday,
                'cashier_pending' => $cashierPending,
                'unpaid_invoices' => $unpaidInvoices,
                'pending_candidates' => $pendingCandidates,
                'converted_today' => $convertedToday,
                'attention_status' => $this->branchAttentionStatus(
                    $visitsToday,
                    $cashierPending,
                    $pendingCandidates,
                    $draftRecords,
                ),
            ];
        })->all();
    }

    /**
     * Sprint 25 Phase 25.5: Read-only per-branch RME receivable summary for the
     * Owner Dashboard. Active receivables are UNPAID + PARTIAL invoices only
     * (DRAFT/PAID/VOID excluded). Remaining balance = grand_total -
     * sum(payments.amount), floored at 0. Follow-up counts use the latest
     * follow-up per invoice and never create records. Respects the selected
     * Owner branch filter via resolveBranchIds().
     *
     * @return array<int, array<string, mixed>>
     */
    public function branchReceivableSummary(?int $branchId = null, ?Carbon $asOf = null): array
    {
        $today = ($asOf ?? now())->toDateString();
        $branchIds = $this->resolveBranchIds($branchId);

        $branches = Branch::query()
            ->when($branchIds !== null, fn (Builder $query) => $query->whereIn('id', $branchIds))
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'code']);

        return $branches->map(function (Branch $branch) use ($today): array {
            $receivableInvoices = $this->rmeReceivableQuery([$branch->id])
                ->withSum('payments', 'amount')
                ->get(['id', 'status', 'grand_total']);

            $remaining = (float) $receivableInvoices->sum(
                fn (RmeInvoice $invoice): float => max(
                    0,
                    round((float) $invoice->grand_total - (float) ($invoice->payments_sum_amount ?? 0), 2),
                ),
            );

            $partialCount = $receivableInvoices
                ->where('status', RmeInvoice::STATUS_PARTIAL)
                ->count();

            $unpaidCount = $receivableInvoices
                ->where('status', RmeInvoice::STATUS_UNPAID)
                ->count();

            $followUpOverdueCount = $this->rmeReceivableQuery([$branch->id])
                ->whereHas('latestFollowUp', fn (Builder $query) => $query->whereDate('next_follow_up_date', '<', $today))
                ->count();

            $followUpTodayCount = $this->rmeReceivableQuery([$branch->id])
                ->whereHas('latestFollowUp', fn (Builder $query) => $query->whereDate('next_follow_up_date', '=', $today))
                ->count();

            $followUpScheduledCount = $this->rmeReceivableQuery([$branch->id])
                ->whereHas('latestFollowUp', fn (Builder $query) => $query->whereDate('next_follow_up_date', '>', $today))
                ->count();

            $neverFollowedUpCount = $this->rmeReceivableQuery([$branch->id])
                ->whereDoesntHave('followUps')
                ->count();

            return [
                'branch_id' => $branch->id,
                'branch_name' => $branch->name,
                'receivable_total_remaining' => $remaining,
                'partial_count' => $partialCount,
                'unpaid_count' => $unpaidCount,
                'follow_up_overdue_count' => $followUpOverdueCount,
                'follow_up_today_count' => $followUpTodayCount,
                'follow_up_scheduled_count' => $followUpScheduledCount,
                'never_followed_up_count' => $neverFollowedUpCount,
            ];
        })->all();
    }

    public function metrics(?int $branchId = null, ?Carbon $asOf = null): array
    {
        $today = ($asOf ?? now())->toDateString();
        $branchIds = $this->resolveBranchIds($branchId);

        $visitsToday = $this->visitQuery($branchIds)
            ->whereDate('visit_date', $today)
            ->where('status', '!=', ClinicVisit::STATUS_CANCELLED)
            ->count();

        $visitsWaiting = $this->visitQuery($branchIds)
            ->where('status', ClinicVisit::STATUS_WAITING)
            ->count();

        $visitsInProgress = $this->visitQuery($branchIds)
            ->where('status', ClinicVisit::STATUS_IN_PROGRESS)
            ->count();

        $visitsCashierPending = $this->visitQuery($branchIds)
            ->where('status', ClinicVisit::STATUS_CASHIER_PENDING)
            ->count();

        $visitsCompletedToday = $this->visitQuery($branchIds)
            ->where('status', ClinicVisit::STATUS_COMPLETED)
            ->where(function (Builder $query) use ($today): void {
                $query->whereDate('completed_at', $today)
                    ->orWhere(function (Builder $inner) use ($today): void {
                        $inner->whereNull('completed_at')
                            ->whereDate('visit_date', $today);
                    });
            })
            ->count();

        $medicalRecordsDraft = $this->medicalRecordQuery($branchIds)
            ->where('status', MedicalRecord::STATUS_DRAFT)
            ->count();

        $medicalRecordsFinalToday = $this->medicalRecordQuery($branchIds)
            ->where('status', MedicalRecord::STATUS_FINAL)
            ->whereDate('finalized_at', $today)
            ->count();

        $rmeInvoicesUnpaid = $this->rmeInvoiceQuery($branchIds)
            ->whereIn('status', [RmeInvoice::STATUS_DRAFT, RmeInvoice::STATUS_UNPAID])
            ->count();

        $rmeInvoicesPaidToday = $this->rmeInvoiceQuery($branchIds)
            ->where('status', RmeInvoice::STATUS_PAID)
            ->whereHas('payments', fn (Builder $query) => $query->whereDate('paid_at', $today))
            ->count();

        $rmeRevenuePaidToday = (float) $this->rmePaymentQuery($branchIds)
            ->whereDate('paid_at', $today)
            ->sum('amount');

        // Sprint 24 Phase 24.4: RME receivable KPIs. Active receivables are
        // UNPAID + PARTIAL invoices only (DRAFT/PAID/VOID excluded). Remaining
        // balance = grand_total - sum(payments.amount), computed via a single
        // aggregate query (withSum) to avoid per-invoice N+1.
        $receivableInvoices = $this->rmeInvoiceQuery($branchIds)
            ->whereIn('status', [RmeInvoice::STATUS_UNPAID, RmeInvoice::STATUS_PARTIAL])
            ->withSum('payments', 'amount')
            ->get(['id', 'status', 'grand_total']);

        $rmeReceivableTotalRemaining = (float) $receivableInvoices->sum(
            fn (RmeInvoice $invoice): float => max(
                0,
                round((float) $invoice->grand_total - (float) ($invoice->payments_sum_amount ?? 0), 2),
            ),
        );

        $rmeReceivablePartialCount = $receivableInvoices
            ->where('status', RmeInvoice::STATUS_PARTIAL)
            ->count();

        $rmeReceivableUnpaidCount = $receivableInvoices
            ->where('status', RmeInvoice::STATUS_UNPAID)
            ->count();

        // Sprint 24 Phase 24.10: RME receivable follow-up KPIs. Counted over
        // active receivables (UNPAID + PARTIAL) only, branch-aware via $branchIds.
        // Due calculations use the latest follow-up per invoice (latestOfMany);
        // counts are of invoices, not follow-up rows.
        $rmeReceivableFollowUpOverdueCount = $this->rmeReceivableQuery($branchIds)
            ->whereHas('latestFollowUp', fn (Builder $query) => $query->whereDate('next_follow_up_date', '<', $today))
            ->count();

        $rmeReceivableFollowUpTodayCount = $this->rmeReceivableQuery($branchIds)
            ->whereHas('latestFollowUp', fn (Builder $query) => $query->whereDate('next_follow_up_date', '=', $today))
            ->count();

        $rmeReceivableFollowUpScheduledCount = $this->rmeReceivableQuery($branchIds)
            ->whereHas('latestFollowUp', fn (Builder $query) => $query->whereDate('next_follow_up_date', '>', $today))
            ->count();

        $rmeReceivableNeverFollowedUpCount = $this->rmeReceivableQuery($branchIds)
            ->whereDoesntHave('followUps')
            ->count();

        $rmeReceivableFollowUpEscalatedCount = $this->rmeReceivableQuery($branchIds)
            ->whereHas('latestFollowUp', fn (Builder $query) => $query->where('status', RmeReceivableFollowUp::STATUS_ESCALATED))
            ->count();

        // Sprint 23 Phase 23.5: Lab is a single / global laboratory. Lab KPIs are
        // NOT grouped by branch and the Owner branch filter must not touch them.
        $labCandidatesPending = $this->labCandidateQuery(null)
            ->where('status', LabCaseCandidate::STATUS_PENDING_REVIEW)
            ->count();

        $labCandidatesConvertedToday = $this->labCandidateQuery(null)
            ->where('status', LabCaseCandidate::STATUS_CONVERTED_TO_LAB_ORDER)
            ->whereDate('reviewed_at', $today)
            ->count();

        $labOrdersFromRmeToday = LabOrder::query()
            ->whereDate('created_at', $today)
            ->whereHas('rmeLabCaseCandidate')
            ->count();

        $conversionRateDisplay = $this->conversionRateDisplay(
            $labCandidatesPending,
            $labCandidatesConvertedToday,
        );

        $attentionItems = $this->attentionItems($branchIds, $today);

        return [
            'visits_today' => $visitsToday,
            'visits_waiting' => $visitsWaiting,
            'visits_in_progress' => $visitsInProgress,
            'visits_cashier_pending' => $visitsCashierPending,
            'visits_completed_today' => $visitsCompletedToday,
            'medical_records_draft' => $medicalRecordsDraft,
            'medical_records_final_today' => $medicalRecordsFinalToday,
            'rme_invoices_unpaid' => $rmeInvoicesUnpaid,
            'rme_invoices_paid_today' => $rmeInvoicesPaidToday,
            'rme_revenue_paid_today' => $rmeRevenuePaidToday,
            'rme_receivable_total_remaining' => $rmeReceivableTotalRemaining,
            'rme_receivable_partial_count' => $rmeReceivablePartialCount,
            'rme_receivable_unpaid_count' => $rmeReceivableUnpaidCount,
            'rme_receivable_follow_up_overdue_count' => $rmeReceivableFollowUpOverdueCount,
            'rme_receivable_follow_up_today_count' => $rmeReceivableFollowUpTodayCount,
            'rme_receivable_never_followed_up_count' => $rmeReceivableNeverFollowedUpCount,
            'rme_receivable_follow_up_scheduled_count' => $rmeReceivableFollowUpScheduledCount,
            'rme_receivable_follow_up_escalated_count' => $rmeReceivableFollowUpEscalatedCount,
            'lab_candidates_pending' => $labCandidatesPending,
            'lab_candidates_converted_today' => $labCandidatesConvertedToday,
            'lab_orders_from_rme_today' => $labOrdersFromRmeToday,
            'conversion_rate_display' => $conversionRateDisplay,
            'attention_items' => $attentionItems,
            'funnel_stages' => $this->funnelStages(
                $visitsToday,
                $visitsCashierPending,
                $rmeInvoicesPaidToday,
                $labCandidatesPending,
                $labOrdersFromRmeToday,
            ),
            'scope_label' => $branchId === null
                ? 'Semua cabang aktif'
                : (Branch::query()->find($branchId)?->name ?? 'Cabang aktif'),
        ];
    }

    /**
     * @return array<int, int>|null null = all active branches (Owner aggregate)
     */
    private function resolveBranchIds(?int $branchId): ?array
    {
        if ($branchId !== null) {
            $isActive = Branch::query()
                ->where('id', $branchId)
                ->where('is_active', true)
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
            ->pluck('id')
            ->all();
    }

    private function branchAttentionStatus(
        int $visitsToday,
        int $cashierPending,
        int $pendingCandidates,
        int $draftRecords,
    ): string {
        if ($cashierPending > 0) {
            return 'Perlu cek kasir';
        }

        if ($pendingCandidates > 0) {
            return 'Perlu cek kandidat lab';
        }

        if ($draftRecords > 0) {
            return 'Banyak RM draft';
        }

        if ($visitsToday === 0) {
            return 'Belum ada data hari ini';
        }

        return 'Aman';
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
     * Active RME receivable invoices (UNPAID + PARTIAL), branch-aware.
     *
     * @param  array<int, int>|null  $branchIds
     */
    private function rmeReceivableQuery(?array $branchIds): Builder
    {
        return $this->rmeInvoiceQuery($branchIds)
            ->whereIn('status', [RmeInvoice::STATUS_UNPAID, RmeInvoice::STATUS_PARTIAL]);
    }

    /**
     * @param  array<int, int>|null  $branchIds
     */
    private function rmePaymentQuery(?array $branchIds): Builder
    {
        return RmePayment::query()
            ->when($branchIds !== null, fn (Builder $query) => $query->whereIn('branch_id', $branchIds));
    }

    /**
     * @param  array<int, int>|null  $branchIds
     */
    private function labCandidateQuery(?array $branchIds): Builder
    {
        return LabCaseCandidate::query()
            ->when($branchIds !== null, fn (Builder $query) => $query->whereIn('branch_id', $branchIds));
    }

    private function conversionRateDisplay(int $pending, int $convertedToday): ?string
    {
        $denominator = $pending + $convertedToday;

        if ($denominator === 0) {
            return null;
        }

        $rate = (int) round(($convertedToday / $denominator) * 100);

        return "{$rate}% konversi hari ini ({$convertedToday}/{$denominator})";
    }

    /**
     * @param  array<int, int>|null  $branchIds
     * @return array<int, array<string, mixed>>
     */
    private function attentionItems(?array $branchIds, string $today): array
    {
        $branches = Branch::query()
            ->when($branchIds !== null, fn (Builder $query) => $query->whereIn('id', $branchIds))
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'code']);

        if ($branches->isEmpty()) {
            return [[
                'severity' => 'info',
                'title' => 'Belum ada cabang aktif',
                'description' => 'Tidak ada cabang aktif untuk monitoring pilot RME/Lab.',
                'metric' => '0',
            ]];
        }

        $items = [];

        foreach ($branches as $branch) {
            $cashierPending = $this->visitQuery([$branch->id])
                ->where('status', ClinicVisit::STATUS_CASHIER_PENDING)
                ->count();

            $unpaidInvoices = $this->rmeInvoiceQuery([$branch->id])
                ->whereIn('status', [RmeInvoice::STATUS_DRAFT, RmeInvoice::STATUS_UNPAID])
                ->count();

            $pendingCandidates = $this->labCandidateQuery([$branch->id])
                ->where('status', LabCaseCandidate::STATUS_PENDING_REVIEW)
                ->count();

            $draftRecords = $this->medicalRecordQuery([$branch->id])
                ->where('status', MedicalRecord::STATUS_DRAFT)
                ->count();

            $issues = collect([
                'cashier_pending' => $cashierPending,
                'unpaid_invoices' => $unpaidInvoices,
                'pending_candidates' => $pendingCandidates,
                'draft_records' => $draftRecords,
            ])->filter(fn (int $count) => $count > 0);

            if ($issues->isEmpty()) {
                continue;
            }

            $parts = [];
            if ($cashierPending > 0) {
                $parts[] = "{$cashierPending} kunjungan menunggu kasir";
            }
            if ($unpaidInvoices > 0) {
                $parts[] = "{$unpaidInvoices} invoice belum dibayar";
            }
            if ($pendingCandidates > 0) {
                $parts[] = "{$pendingCandidates} kandidat lab pending";
            }
            if ($draftRecords > 0) {
                $parts[] = "{$draftRecords} RM draft";
            }

            $items[] = [
                'severity' => $cashierPending > 0 || $unpaidInvoices > 0 ? 'warning' : 'info',
                'title' => $branch->name,
                'description' => implode('; ', $parts).'.',
                'metric' => (string) $issues->sum(),
            ];
        }

        if ($items === []) {
            $visitsToday = $this->visitQuery($branchIds)
                ->whereDate('visit_date', $today)
                ->where('status', '!=', ClinicVisit::STATUS_CANCELLED)
                ->count();

            if ($visitsToday === 0) {
                return [[
                    'severity' => 'neutral',
                    'title' => 'Belum ada aktivitas RME hari ini',
                    'description' => 'Tidak ada kunjungan RME terdaftar untuk hari ini di cabang yang dipantau.',
                    'metric' => '0',
                ]];
            }

            return [[
                'severity' => 'success',
                'title' => 'Tidak ada antrean kritis',
                'description' => 'Semua cabang aktif tidak memiliki kunjungan menunggu kasir, invoice tertunda, kandidat lab pending, atau RM draft.',
                'metric' => '0',
            ]];
        }

        return $items;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function funnelStages(
        int $visitsToday,
        int $cashierPending,
        int $paidToday,
        int $pendingCandidates,
        int $labOrdersToday,
    ): array {
        $baseline = max($visitsToday, 1);

        return [
            [
                'label' => 'Kunjungan',
                'count' => $visitsToday,
                'percent' => $visitsToday > 0 ? 100 : 0,
                'oldestAge' => 'Hari ini',
                'severity' => $visitsToday > 0 ? 'info' : 'neutral',
            ],
            [
                'label' => 'Menunggu Kasir',
                'count' => $cashierPending,
                'percent' => min(100, ($cashierPending / $baseline) * 100),
                'oldestAge' => 'Status saat ini',
                'severity' => $cashierPending > 0 ? 'warning' : 'success',
            ],
            [
                'label' => 'Bayar RME',
                'count' => $paidToday,
                'percent' => min(100, ($paidToday / $baseline) * 100),
                'oldestAge' => 'Dibayar hari ini',
                'severity' => $paidToday > 0 ? 'success' : 'neutral',
            ],
            [
                'label' => 'Kandidat Lab',
                'count' => $pendingCandidates,
                'percent' => min(100, ($pendingCandidates / $baseline) * 100),
                'oldestAge' => 'Pending review',
                'severity' => $pendingCandidates > 0 ? 'warning' : 'success',
            ],
            [
                'label' => 'Lab Order RME',
                'count' => $labOrdersToday,
                'percent' => min(100, ($labOrdersToday / $baseline) * 100),
                'oldestAge' => 'Dikonversi hari ini',
                'severity' => $labOrdersToday > 0 ? 'success' : 'neutral',
            ],
        ];
    }
}
