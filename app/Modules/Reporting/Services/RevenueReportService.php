<?php

namespace App\Modules\Reporting\Services;

use App\Modules\Reporting\Interfaces\ReportingRepositoryInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Revenue reporting. VOID invoices are excluded (sprint_8_technical_design.md §14).
 */
class RevenueReportService
{
    public function __construct(
        private readonly ReportingRepositoryInterface $reporting,
    ) {}

    public function byMonth(array $filters): Collection
    {
        return $this->reporting->revenueRows($filters)
            ->groupBy(fn ($row) => Carbon::parse($row->invoice_date)->format('Y-m'))
            ->map(fn ($rows, $month) => (object) ['month' => $month, 'amount' => (float) $rows->sum('total_amount')])
            ->values()
            ->sortBy('month')
            ->values();
    }

    public function byClinic(array $filters): Collection
    {
        return $this->reporting->revenueByClinic($filters);
    }

    /**
     * @return array{invoice_revenue: float, payment_received: float, by_month: Collection, by_clinic: Collection}
     */
    public function summary(array $filters): array
    {
        return [
            'invoice_revenue' => $this->reporting->invoiceRevenueTotal($filters),
            'payment_received' => $this->reporting->paymentsTotal($filters),
            'by_month' => $this->byMonth($filters),
            'by_clinic' => $this->byClinic($filters),
        ];
    }

    /**
     * @return array{filename: string, header: array<int, string>, rows: Collection}
     */
    public function export(array $filters): array
    {
        $rows = $this->byMonth($filters)->map(fn ($r) => [$r->month, $r->amount]);

        return [
            'filename' => 'revenue-report.csv',
            'header' => ['Month', 'Revenue'],
            'rows' => $rows,
        ];
    }
}
