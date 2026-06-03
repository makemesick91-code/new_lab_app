<?php

namespace App\Modules\Reporting\Services;

use App\Modules\Reporting\Interfaces\ReportingRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class OrderReportService
{
    public function __construct(
        private readonly ReportingRepositoryInterface $reporting,
    ) {}

    public function paginate(array $filters, int $perPage = 15): LengthAwarePaginator
    {
        return $this->reporting->ordersQuery($filters)->paginate($perPage)->withQueryString();
    }

    /**
     * @return array{by_status: Collection, total: int}
     */
    public function summary(array $filters): array
    {
        return [
            'by_status' => $this->reporting->ordersStatusSummary($filters),
            'total' => $this->reporting->ordersQuery($filters)->reorder()->count(),
        ];
    }

    /**
     * @return array{filename: string, header: array<int, string>, rows: Collection}
     */
    public function export(array $filters): array
    {
        $rows = $this->reporting->ordersQuery($filters)->get()->map(fn ($r) => [
            $r->order_number, $r->clinic_name, $r->doctor_name, $r->patient_name,
            $r->order_date, $r->due_date, $r->priority, $r->status,
        ]);

        return [
            'filename' => 'order-report.csv',
            'header' => ['Order Number', 'Clinic', 'Doctor', 'Patient', 'Order Date', 'Due Date', 'Priority', 'Status'],
            'rows' => $rows,
        ];
    }
}
