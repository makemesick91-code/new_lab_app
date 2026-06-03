<?php

namespace App\Modules\Reporting\Services;

use App\Modules\Reporting\Interfaces\ReportingRepositoryInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Read-only dashboard KPI cards and chart datasets. Empty-state safe.
 */
class DashboardService
{
    public function __construct(
        private readonly ReportingRepositoryInterface $reporting,
    ) {}

    /**
     * @return array<string, int|float>
     */
    public function cards(array $filters = []): array
    {
        return [
            'total_orders' => $this->reporting->orderStatusCount(null),
            'in_progress' => $this->reporting->orderStatusCount('IN_PRODUCTION'),
            'completed' => $this->reporting->orderStatusCount('COMPLETED'),
            'delivered' => $this->reporting->orderStatusCount('DELIVERED'),
            'pending_qc' => $this->reporting->orderStatusCount('QC_PENDING'),
            'revenue' => $this->reporting->invoiceRevenueTotal($filters),
            'outstanding' => $this->reporting->outstandingTotal($filters),
            'overdue_invoices' => $this->reporting->overdueInvoiceCount(),
            'remake_count' => $this->reporting->remakeCount($filters),
        ];
    }

    /**
     * @return array<string, Collection>
     */
    public function charts(array $filters = []): array
    {
        return [
            'orders_by_status' => $this->reporting->ordersStatusSummary($filters),
            'orders_by_clinic' => $this->reporting->ordersByClinic($filters),
            'revenue_by_month' => $this->revenueByMonth($filters),
            'payments_by_method' => $this->reporting->paymentsByMethod($filters),
            'qc_summary' => $this->reporting->qcResultSummary($filters),
            'delivery_summary' => $this->reporting->deliveryStatusSummary($filters),
        ];
    }

    private function revenueByMonth(array $filters): Collection
    {
        return $this->reporting->revenueRows($filters)
            ->groupBy(fn ($row) => Carbon::parse($row->invoice_date)->format('Y-m'))
            ->map(fn ($rows, $month) => (object) ['month' => $month, 'amount' => (float) $rows->sum('total_amount')])
            ->values()
            ->sortBy('month')
            ->values();
    }
}
