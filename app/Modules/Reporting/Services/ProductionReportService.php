<?php

namespace App\Modules\Reporting\Services;

use App\Modules\Reporting\Interfaces\ReportingRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class ProductionReportService
{
    public function __construct(
        private readonly ReportingRepositoryInterface $reporting,
    ) {}

    public function paginate(array $filters, int $perPage = 15): LengthAwarePaginator
    {
        return $this->reporting->productionQuery($filters)->paginate($perPage)->withQueryString();
    }

    /**
     * @return array{workload: Collection, total: int}
     */
    public function summary(array $filters): array
    {
        return [
            'workload' => $this->reporting->technicianWorkload($filters),
            'total' => $this->reporting->productionQuery($filters)->reorder()->count(),
        ];
    }

    /**
     * @return array{filename: string, header: array<int, string>, rows: Collection}
     */
    public function export(array $filters): array
    {
        $rows = $this->reporting->productionQuery($filters)->get()->map(fn ($r) => [
            $r->order_number, $r->technician_name, $r->clinic_name, $r->status, $r->assigned_at, $r->completed_at,
        ]);

        return [
            'filename' => 'production-report.csv',
            'header' => ['Order Number', 'Technician', 'Clinic', 'Assignment Status', 'Assigned At', 'Completed At'],
            'rows' => $rows,
        ];
    }
}
