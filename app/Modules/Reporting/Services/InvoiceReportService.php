<?php

namespace App\Modules\Reporting\Services;

use App\Modules\Reporting\Interfaces\ReportingRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class InvoiceReportService
{
    public function __construct(
        private readonly ReportingRepositoryInterface $reporting,
    ) {}

    public function paginate(array $filters, int $perPage = 15): LengthAwarePaginator
    {
        return $this->reporting->invoicesQuery($filters)->paginate($perPage)->withQueryString();
    }

    /**
     * @return array{by_status: Collection, total_amount: float, count: int}
     */
    public function summary(array $filters): array
    {
        $byStatus = $this->reporting->invoiceStatusSummary($filters);

        return [
            'by_status' => $byStatus,
            'total_amount' => (float) $byStatus->sum('amount'),
            'count' => (int) $byStatus->sum('total'),
        ];
    }

    /**
     * @return array{filename: string, header: array<int, string>, rows: Collection}
     */
    public function export(array $filters): array
    {
        $rows = $this->reporting->invoicesQuery($filters)->get()->map(fn ($r) => [
            $r->invoice_number, $r->clinic_name, $r->invoice_date, $r->due_date, $r->status,
            $r->total_amount, $r->paid_amount, $r->outstanding_amount,
        ]);

        return [
            'filename' => 'invoice-report.csv',
            'header' => ['Invoice Number', 'Clinic', 'Invoice Date', 'Due Date', 'Status', 'Total', 'Paid', 'Outstanding'],
            'rows' => $rows,
        ];
    }

    // ----- Outstanding (invoice-based) ------------------------------------

    public function outstandingPaginate(array $filters, int $perPage = 15): LengthAwarePaginator
    {
        return $this->reporting->outstandingQuery($filters)->paginate($perPage)->withQueryString();
    }

    /**
     * @return array{total_outstanding: float, aging: array<string, float>}
     */
    public function outstandingSummary(array $filters): array
    {
        $rows = $this->reporting->outstandingQuery($filters)->get();
        $aging = ['current' => 0.0, '1_30' => 0.0, '31_60' => 0.0, '61_90' => 0.0, 'over_90' => 0.0];
        $today = now()->startOfDay();

        foreach ($rows as $row) {
            $amount = (float) $row->outstanding_amount;
            $days = $row->due_date ? $today->diffInDays(Carbon::parse($row->due_date)->startOfDay(), false) : 0;
            $overdue = $days < 0 ? abs($days) : 0;

            $bucket = match (true) {
                $overdue === 0 => 'current',
                $overdue <= 30 => '1_30',
                $overdue <= 60 => '31_60',
                $overdue <= 90 => '61_90',
                default => 'over_90',
            };
            $aging[$bucket] += $amount;
        }

        return ['total_outstanding' => (float) $rows->sum('outstanding_amount'), 'aging' => $aging];
    }

    /**
     * @return array{filename: string, header: array<int, string>, rows: Collection}
     */
    public function outstandingExport(array $filters): array
    {
        $rows = $this->reporting->outstandingQuery($filters)->get()->map(fn ($r) => [
            $r->invoice_number, $r->clinic_name, $r->invoice_date, $r->due_date, $r->status, $r->total_amount, $r->outstanding_amount,
        ]);

        return [
            'filename' => 'outstanding-report.csv',
            'header' => ['Invoice Number', 'Clinic', 'Invoice Date', 'Due Date', 'Status', 'Total', 'Outstanding'],
            'rows' => $rows,
        ];
    }
}
