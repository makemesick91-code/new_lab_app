<?php

namespace App\Modules\Reporting\Services;

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Inventory\Interfaces\InventoryAnalyticsRepositoryInterface;
use App\Modules\LabOrder\Models\LabOrder;
use App\Modules\Patient\Models\Patient;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use App\Modules\RmeInvoice\Models\RmePayment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;

/**
 * Sprint 62.0 — Executive, period-based Owner KPI Dashboard metrics.
 *
 * Read-only aggregate KPIs for the Owner. Complements the existing
 * "today snapshot" OwnerDashboardRmeLabKpiService by supporting a
 * configurable date range and branch filter. Never exposes KTP/NIK,
 * scanned documents, or raw medical notes. Lab and inventory cross-branch
 * aggregates are computed without weakening BranchContext for operators.
 */
class OwnerDashboardKpiService
{
    /** Lab order statuses considered "closed" (excluded from active count). */
    private const LAB_CLOSED_STATUSES = [
        LabOrder::STATUS_DELIVERED,
        LabOrder::STATUS_COMPLETED,
        LabOrder::STATUS_CANCELLED,
    ];

    public function __construct(
        private readonly InventoryAnalyticsRepositoryInterface $inventoryAnalytics,
    ) {}

    /**
     * Resolve the selected reporting period from the request inputs.
     *
     * @return array{range: string, from: Carbon, to: Carbon, label: string}
     */
    public function resolvePeriod(?string $range, ?string $from, ?string $to, ?Carbon $now = null): array
    {
        $now = $now ?? now();
        $range = in_array($range, ['today', '7d', 'month', '30d', 'custom'], true) ? $range : 'month';

        [$start, $end, $label] = match ($range) {
            'today' => [$now->copy()->startOfDay(), $now->copy()->endOfDay(), 'Hari ini'],
            '7d' => [$now->copy()->subDays(6)->startOfDay(), $now->copy()->endOfDay(), '7 hari terakhir'],
            '30d' => [$now->copy()->subDays(29)->startOfDay(), $now->copy()->endOfDay(), '30 hari terakhir'],
            'custom' => $this->resolveCustomRange($from, $to, $now),
            default => [$now->copy()->startOfMonth(), $now->copy()->endOfMonth(), 'Bulan ini'],
        };

        return ['range' => $range, 'from' => $start, 'to' => $end, 'label' => $label];
    }

    /**
     * @return array{0: Carbon, 1: Carbon, 2: string}
     */
    private function resolveCustomRange(?string $from, ?string $to, Carbon $now): array
    {
        try {
            $start = $from ? Carbon::parse($from)->startOfDay() : $now->copy()->startOfMonth();
            $end = $to ? Carbon::parse($to)->endOfDay() : $now->copy()->endOfDay();
        } catch (\Throwable) {
            $start = $now->copy()->startOfMonth();
            $end = $now->copy()->endOfDay();
        }

        if ($end->lessThan($start)) {
            [$start, $end] = [$end->copy()->startOfDay(), $start->copy()->endOfDay()];
        }

        return [$start, $end, $start->translatedFormat('d M Y').' – '.$end->translatedFormat('d M Y')];
    }

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
     * The 10 executive KPI cards for the selected period and branch.
     *
     * @return array<string, mixed>
     */
    public function metrics(?int $branchId, Carbon $from, Carbon $to): array
    {
        $branchIds = $this->resolveBranchIds($branchId);

        $totalVisits = $this->visitQuery($branchIds)
            ->whereBetween('visit_date', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->where('status', '!=', ClinicVisit::STATUS_CANCELLED)
            ->count();

        $newPatients = Patient::query()
            ->when($branchIds !== null, fn (Builder $q) => $q->whereIn('branch_id', $branchIds))
            ->whereBetween('created_at', [$from, $to])
            ->count();

        $totalRevenue = (float) $this->paymentQuery($branchIds)
            ->whereBetween('paid_at', [$from, $to])
            ->sum('amount');

        // Active receivables snapshot (UNPAID + PARTIAL). Remaining =
        // grand_total - sum(payments). Exclude zero grand_total and zero
        // remaining per spec.
        $receivableInvoices = $this->invoiceQuery($branchIds)
            ->whereIn('status', [RmeInvoice::STATUS_UNPAID, RmeInvoice::STATUS_PARTIAL])
            ->where('grand_total', '>', 0)
            ->withSum('payments', 'amount')
            ->get(['id', 'status', 'grand_total']);

        $activeReceivable = 0.0;
        $unpaidInvoiceCount = 0;
        foreach ($receivableInvoices as $invoice) {
            $remaining = max(0, round((float) $invoice->grand_total - (float) ($invoice->payments_sum_amount ?? 0), 2));
            if ($remaining > 0) {
                $activeReceivable += $remaining;
                $unpaidInvoiceCount++;
            }
        }

        $today = $to->copy()->endOfDay()->toDateString();
        $followUpDue = $this->invoiceQuery($branchIds)
            ->whereIn('status', [RmeInvoice::STATUS_UNPAID, RmeInvoice::STATUS_PARTIAL])
            ->whereHas('latestFollowUp', fn (Builder $q) => $q->whereDate('next_follow_up_date', '<=', now()->toDateString()))
            ->count();

        // Lab is a single global laboratory; not branch-grouped (Sprint 23.5).
        $labOrdersActive = LabOrder::query()
            ->whereNotIn('status', self::LAB_CLOSED_STATUSES)
            ->count();

        $inventory = $this->inventorySnapshot($branchIds);

        // Payment collection rate: collected in period / billable in period.
        $billable = (float) $this->invoiceQuery($branchIds)
            ->whereNotIn('status', [RmeInvoice::STATUS_VOID, RmeInvoice::STATUS_DRAFT])
            ->whereBetween('created_at', [$from, $to])
            ->sum('grand_total');

        $collectionRate = $billable > 0 ? min(100.0, round(($totalRevenue / $billable) * 100, 1)) : null;

        return [
            'total_visits' => $totalVisits,
            'new_patients' => $newPatients,
            'total_revenue' => $totalRevenue,
            'active_receivable' => $activeReceivable,
            'unpaid_invoices' => $unpaidInvoiceCount,
            'follow_up_due' => $followUpDue,
            'lab_orders_active' => $labOrdersActive,
            'low_stock_items' => $inventory['low_stock_items'],
            'stock_value' => $inventory['stock_value'],
            'stock_available' => $inventory['available'],
            'collection_rate' => $collectionRate,
            'today' => $today,
        ];
    }

    /**
     * Per-branch performance table for the selected period.
     *
     * @return array<int, array<string, mixed>>
     */
    public function branchPerformance(?int $branchId, Carbon $from, Carbon $to): array
    {
        $branches = Branch::query()
            ->when($branchId !== null, fn (Builder $q) => $q->where('id', $branchId))
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'code']);

        return $branches->map(function (Branch $branch) use ($from, $to): array {
            $visits = $this->visitQuery([$branch->id])
                ->whereBetween('visit_date', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
                ->where('status', '!=', ClinicVisit::STATUS_CANCELLED)
                ->count();

            $newPatients = Patient::query()
                ->where('branch_id', $branch->id)
                ->whereBetween('created_at', [$from, $to])
                ->count();

            $revenue = (float) $this->paymentQuery([$branch->id])
                ->whereBetween('paid_at', [$from, $to])
                ->sum('amount');

            $receivableInvoices = $this->invoiceQuery([$branch->id])
                ->whereIn('status', [RmeInvoice::STATUS_UNPAID, RmeInvoice::STATUS_PARTIAL])
                ->where('grand_total', '>', 0)
                ->withSum('payments', 'amount')
                ->get(['id', 'grand_total']);

            $receivable = (float) $receivableInvoices->sum(
                fn (RmeInvoice $i): float => max(0, round((float) $i->grand_total - (float) ($i->payments_sum_amount ?? 0), 2)),
            );

            $followUpDue = $this->invoiceQuery([$branch->id])
                ->whereIn('status', [RmeInvoice::STATUS_UNPAID, RmeInvoice::STATUS_PARTIAL])
                ->whereHas('latestFollowUp', fn (Builder $q) => $q->whereDate('next_follow_up_date', '<=', now()->toDateString()))
                ->count();

            return [
                'branch_id' => $branch->id,
                'branch_name' => $branch->name,
                'visits' => $visits,
                'new_patients' => $newPatients,
                'revenue' => $revenue,
                'receivable' => $receivable,
                'follow_up_due' => $followUpDue,
            ];
        })->all();
    }

    /**
     * Daily visit counts across the period (capped for display safety).
     *
     * @return array<int, array{label: string, count: int}>
     */
    public function dailyVisitTrend(?int $branchId, Carbon $from, Carbon $to): array
    {
        $branchIds = $this->resolveBranchIds($branchId);
        $from = $this->cappedFrom($from, $to);

        // Group by calendar day in PHP to stay portable: visit_date may carry a
        // time component under SQLite while being a pure DATE under PostgreSQL.
        $rows = $this->visitQuery($branchIds)
            ->whereBetween('visit_date', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->where('status', '!=', ClinicVisit::STATUS_CANCELLED)
            ->get(['visit_date'])
            ->groupBy(fn (ClinicVisit $v): string => Carbon::parse($v->visit_date)->toDateString())
            ->map(fn ($group): int => $group->count());

        return $this->fillDailySeries($from, $to, fn (string $date) => (int) ($rows[$date] ?? 0));
    }

    /**
     * Daily paid revenue across the period (capped for display safety).
     *
     * @return array<int, array{label: string, count: int}>
     */
    public function dailyPaymentTrend(?int $branchId, Carbon $from, Carbon $to): array
    {
        $branchIds = $this->resolveBranchIds($branchId);
        $from = $this->cappedFrom($from, $to);

        // Group by calendar day in PHP to stay portable (paid_at is a timestamp).
        // Window is capped to 31 days so the row set stays small.
        $rows = $this->paymentQuery($branchIds)
            ->whereBetween('paid_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->get(['paid_at', 'amount'])
            ->groupBy(fn (RmePayment $p): string => Carbon::parse($p->paid_at)->toDateString())
            ->map(fn ($group): float => (float) $group->sum('amount'));

        return $this->fillDailySeries($from, $to, fn (string $date) => (int) round((float) ($rows[$date] ?? 0)));
    }

    /**
     * Latest active receivables — privacy safe (NO KTP/NIK, NO medical notes).
     *
     * @return array<int, array<string, mixed>>
     */
    public function topUnpaidReceivables(?int $branchId, int $limit = 8): array
    {
        $branchIds = $this->resolveBranchIds($branchId);

        $invoices = $this->invoiceQuery($branchIds)
            ->whereIn('status', [RmeInvoice::STATUS_UNPAID, RmeInvoice::STATUS_PARTIAL])
            ->where('grand_total', '>', 0)
            ->withSum('payments', 'amount')
            ->with(['patient:id,name', 'branch:id,name', 'clinicVisit:id,visit_date'])
            ->latest('id')
            ->limit(50)
            ->get();

        return $invoices
            ->map(function (RmeInvoice $invoice): array {
                $remaining = max(0, round((float) $invoice->grand_total - (float) ($invoice->payments_sum_amount ?? 0), 2));

                return [
                    'patient_name' => $invoice->patient?->name ?? 'Pasien',
                    'branch_name' => $invoice->branch?->name ?? '-',
                    'visit_date' => $invoice->clinicVisit?->visit_date,
                    'remaining' => $remaining,
                    'status' => $invoice->status,
                ];
            })
            ->filter(fn (array $row): bool => $row['remaining'] > 0)
            ->take($limit)
            ->values()
            ->all();
    }

    /**
     * Permission- and route-existence-aware drilldown links.
     *
     * @return array<string, string>
     */
    public function drilldownLinks(User $user, Carbon $from, Carbon $to): array
    {
        $links = [];

        if ($user->canAny(['view_clinic_visits', 'manage_clinic_visits']) && Route::has('rme.visits.index')) {
            $links['total_visits'] = route('rme.visits.index');
        }

        if ($user->can('manage patients') && Route::has('settings.patients.index')) {
            $links['new_patients'] = route('settings.patients.index');
        }

        if ($user->can('manage_rme_billing')) {
            if (Route::has('rme.cashier.index')) {
                $links['total_revenue'] = route('rme.cashier.index');
                $links['unpaid_invoices'] = route('rme.cashier.index');
            }
            if (Route::has('rme.cashier.receivables')) {
                $links['active_receivable'] = route('rme.cashier.receivables');
                $links['follow_up_due'] = route('rme.cashier.receivables', ['follow_up_filter' => 'overdue']);
            }
        }

        if ($user->canAny(['view_lab_orders', 'manage_lab_orders']) && Route::has('lab-orders.index')) {
            $links['lab_orders_active'] = route('lab-orders.index');
        }

        if ($user->canAny(['view_inventory', 'manage_inventory'])) {
            if (Route::has('inventory.alerts.index')) {
                $links['low_stock_items'] = route('inventory.alerts.index');
            }
            if (Route::has('inventory.analytics.index')) {
                $links['stock_value'] = route('inventory.analytics.index');
            }
        }

        return $links;
    }

    /**
     * FIX-PRE-68-45 Scope B — module/report-category shortcuts shown at the top of
     * the Owner dashboard. Each shortcut is permission-gated and route-existence
     * checked, so a user only ever sees the reports they are already authorised to
     * open. This never widens visibility: clicking a shortcut lands on the target
     * report, whose own branch scope + policy still apply (no cross-branch leak).
     *
     * @return array<int, array{key: string, label: string, description: string, href: string}>
     */
    public function moduleShortcuts(User $user): array
    {
        $shortcuts = [];

        // Owner summary — the current dashboard context. Always available to any
        // user who can reach this dashboard.
        if (Route::has('dashboard')) {
            $shortcuts[] = [
                'key' => 'owner_summary',
                'label' => 'Ringkasan Owner',
                'description' => 'Dashboard KPI eksekutif',
                'href' => route('dashboard'),
            ];
        }

        if ($user->can('view_rme_patient_reports') && Route::has('rme.reports.patients')) {
            $shortcuts[] = [
                'key' => 'rme_patients',
                'label' => 'Laporan Pasien RME',
                'description' => 'Kunjungan & pasien RME',
                'href' => route('rme.reports.patients'),
            ];
        }

        if ($user->can('view_rme_payment_reports') && Route::has('rme.reports.payments')) {
            $shortcuts[] = [
                'key' => 'rme_payments',
                'label' => 'Laporan Pembayaran RME',
                'description' => 'Pembayaran & pendapatan',
                'href' => route('rme.reports.payments'),
            ];
        }

        if ($user->can('manage_rme_billing') && Route::has('rme.cashier.receivables')) {
            $shortcuts[] = [
                'key' => 'receivables',
                'label' => 'Piutang Pasien',
                'description' => 'Tagihan belum lunas',
                'href' => route('rme.cashier.receivables'),
            ];
        }

        if ($user->canAny(['view_inventory', 'manage_inventory']) && Route::has('inventory.reports.index')) {
            $shortcuts[] = [
                'key' => 'inventory',
                'label' => 'Laporan Inventory',
                'description' => 'Stok & nilai persediaan',
                'href' => route('inventory.reports.index'),
            ];
        }

        return $shortcuts;
    }

    /**
     * Cross-branch inventory aggregate. Degrades to a safe empty state on any
     * failure so the dashboard never breaks on inventory wiring issues.
     *
     * @param  array<int, int>|null  $branchIds
     * @return array{low_stock_items: int|null, stock_value: float|null, available: bool}
     */
    private function inventorySnapshot(?array $branchIds): array
    {
        $ids = $branchIds ?? $this->activeBranchIds();

        try {
            $lowStock = 0;
            $value = 0.0;
            foreach ($ids as $id) {
                $lowStock += $this->inventoryAnalytics->getLowStockCount($id);
                $value += $this->inventoryAnalytics->getInventoryValue($id);
            }

            return ['low_stock_items' => $lowStock, 'stock_value' => $value, 'available' => true];
        } catch (\Throwable) {
            return ['low_stock_items' => null, 'stock_value' => null, 'available' => false];
        }
    }

    /**
     * @return array<int, int>|null null = all active branches (owner aggregate)
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
        return Branch::query()->where('is_active', true)->pluck('id')->all();
    }

    /**
     * Cap trend window to 31 days to keep the mini-chart bounded.
     */
    private function cappedFrom(Carbon $from, Carbon $to): Carbon
    {
        $earliest = $to->copy()->subDays(30)->startOfDay();

        return $from->lessThan($earliest) ? $earliest : $from->copy()->startOfDay();
    }

    /**
     * @param  callable(string): int  $resolver
     * @return array<int, array{label: string, count: int}>
     */
    private function fillDailySeries(Carbon $from, Carbon $to, callable $resolver): array
    {
        $series = [];
        $cursor = $from->copy()->startOfDay();
        $end = $to->copy()->startOfDay();

        while ($cursor->lessThanOrEqualTo($end)) {
            $date = $cursor->toDateString();
            $series[] = ['label' => $cursor->translatedFormat('d/m'), 'count' => $resolver($date)];
            $cursor->addDay();
        }

        return $series;
    }

    /**
     * @param  array<int, int>|null  $branchIds
     */
    private function visitQuery(?array $branchIds): Builder
    {
        return ClinicVisit::query()
            ->when($branchIds !== null, fn (Builder $q) => $q->whereIn('branch_id', $branchIds));
    }

    /**
     * @param  array<int, int>|null  $branchIds
     */
    private function invoiceQuery(?array $branchIds): Builder
    {
        return RmeInvoice::query()
            ->when($branchIds !== null, fn (Builder $q) => $q->whereIn('branch_id', $branchIds));
    }

    /**
     * @param  array<int, int>|null  $branchIds
     */
    private function paymentQuery(?array $branchIds): Builder
    {
        return RmePayment::query()
            ->when($branchIds !== null, fn (Builder $q) => $q->whereIn('branch_id', $branchIds));
    }
}
