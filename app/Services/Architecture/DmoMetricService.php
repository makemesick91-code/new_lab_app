<?php

namespace App\Services\Architecture;

use App\Modules\Branch\Models\Branch;
use App\Modules\Delivery\Models\Delivery;
use App\Modules\Invoice\Models\Invoice;
use App\Modules\Invoice\Models\Payment;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use App\Modules\RmeInvoice\Models\RmePayment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * DMO-3 read-only canonical metric computations (privacy-safe, branch-scoped).
 */
class DmoMetricService
{
    public const AGING_BUCKETS = ['0-7', '8-14', '15-30', '31-60', '61+'];

    /**
     * @return array{
     *     rme_collected_revenue: float,
     *     lab_collected_revenue: float,
     *     combined_collected_revenue: float,
     *     net_revenue: float,
     *     limitation: string,
     * }
     */
    public function netRevenue(?int $branchId, Carbon $from, Carbon $to): array
    {
        $branchIds = $this->resolveBranchIds($branchId);
        $rme = (float) $this->rmePaymentQuery($branchIds)
            ->whereBetween('paid_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->sum('amount');
        $lab = (float) $this->labPaymentQuery($branchIds)
            ->whereBetween('payment_date', [$from->copy()->startOfDay()->toDateString(), $to->copy()->endOfDay()->toDateString()])
            ->sum('amount');

        return [
            'rme_collected_revenue' => round($rme, 2),
            'lab_collected_revenue' => round($lab, 2),
            'combined_collected_revenue' => round($rme + $lab, 2),
            'net_revenue' => round($rme + $lab, 2),
            'limitation' => 'net_revenue equals collected payments; no separate refund/discount reversal fields at payment level',
        ];
    }

    /**
     * Invoice-remaining based receivable aging (computed at read time).
     *
     * @return array<string, array{count: int, remaining: float}>
     */
    public function receivableAgingBuckets(?int $branchId): array
    {
        $branchIds = $this->resolveBranchIds($branchId);
        $summary = [];
        foreach (self::AGING_BUCKETS as $bucket) {
            $summary[$bucket] = ['count' => 0, 'remaining' => 0.0];
        }

        $today = Carbon::today();
        $ageColumn = $this->receivableAgeColumn();
        $bucketExpression = <<<'SQL'
CASE
    WHEN DATE(receivables.age_date) >= ? THEN '0-7'
    WHEN DATE(receivables.age_date) >= ? THEN '8-14'
    WHEN DATE(receivables.age_date) >= ? THEN '15-30'
    WHEN DATE(receivables.age_date) >= ? THEN '31-60'
    ELSE '61+'
END
SQL;

        $base = $this->receivableAggregateBaseQuery($branchIds);
        $bucketed = DB::query()
            ->fromSub($base, 'receivables')
            ->leftJoinSub($this->receivablePaymentTotalsQuery(), 'payment_totals', function ($join): void {
                $join->on('payment_totals.rme_invoice_id', '=', 'receivables.id');
            })
            ->selectRaw($bucketExpression.' AS bucket', [
                $today->copy()->subDays(7)->toDateString(),
                $today->copy()->subDays(14)->toDateString(),
                $today->copy()->subDays(30)->toDateString(),
                $today->copy()->subDays(60)->toDateString(),
            ])
            ->selectRaw('receivables.grand_total - COALESCE(payment_totals.paid_total, 0) AS remaining_total')
            ->whereRaw('receivables.grand_total - COALESCE(payment_totals.paid_total, 0) > 0');

        DB::query()
            ->fromSub($bucketed, 'bucketed_receivables')
            ->select('bucket')
            ->selectRaw('COUNT(*) AS invoice_count')
            ->selectRaw('COALESCE(SUM(remaining_total), 0) AS remaining_total')
            ->groupBy('bucket')
            ->get()
            ->each(function ($row) use (&$summary): void {
                $bucket = (string) $row->bucket;
                if (! array_key_exists($bucket, $summary)) {
                    return;
                }
                $summary[$bucket] = [
                    'count' => (int) $row->invoice_count,
                    'remaining' => round((float) $row->remaining_total, 2),
                ];
            });

        return $summary;
    }

    public function podCount(?int $branchId, Carbon $from, Carbon $to): int
    {
        $branchIds = $this->resolveBranchIds($branchId);

        return Delivery::query()
            ->when($branchIds !== null, fn (Builder $q) => $q->whereIn('branch_id', $branchIds))
            ->whereIn('status', [Delivery::STATUS_DELIVERED, Delivery::STATUS_COMPLETED])
            ->where(function (Builder $q): void {
                $q->whereNotNull('receiver_signature_path')
                    ->orWhereNotNull('receiver_signature_data');
            })
            ->whereNotNull('received_at')
            ->whereBetween('received_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->count();
    }

    /**
     * @return array<int, int>|null
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
     * @param  array<int, int>|null  $branchIds
     */
    private function rmePaymentQuery(?array $branchIds): Builder
    {
        return RmePayment::query()
            ->when($branchIds !== null, fn (Builder $q) => $q->whereIn('branch_id', $branchIds))
            ->whereHas('rmeInvoice', fn (Builder $q) => $q->where('status', '!=', RmeInvoice::STATUS_VOID));
    }

    /**
     * @param  array<int, int>|null  $branchIds
     */
    private function labPaymentQuery(?array $branchIds): Builder
    {
        return Payment::query()
            ->when($branchIds !== null, fn (Builder $q) => $q->whereIn('branch_id', $branchIds))
            ->whereHas('invoice', fn (Builder $q) => $q->where('status', '!=', Invoice::STATUS_VOID));
    }

    private function receivableAgeColumn(): string
    {
        if (Schema::hasColumn('trx_rme_invoices', 'due_date')) {
            return 'COALESCE(trx_rme_invoices.due_date, trx_rme_invoices.created_at)';
        }

        return 'trx_rme_invoices.created_at';
    }

    /**
     * @param  array<int, int>|null  $branchIds
     */
    private function receivableAggregateBaseQuery(?array $branchIds)
    {
        $ageColumn = $this->receivableAgeColumn();

        return RmeInvoice::query()
            ->when($branchIds !== null, fn (Builder $q) => $q->whereIn('branch_id', $branchIds))
            ->whereIn('status', [RmeInvoice::STATUS_UNPAID, RmeInvoice::STATUS_PARTIAL])
            ->where('grand_total', '>', 0)
            ->select([
                'id',
                'grand_total',
                DB::raw("{$ageColumn} AS age_date"),
            ])
            ->toBase();
    }

    private function receivablePaymentTotalsQuery()
    {
        return DB::table('trx_rme_payments')
            ->select('rme_invoice_id')
            ->selectRaw('SUM(amount) AS paid_total')
            ->whereNull('deleted_at')
            ->groupBy('rme_invoice_id');
    }
}
