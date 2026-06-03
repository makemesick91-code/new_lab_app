<?php

namespace App\Modules\Reporting\Services;

use App\Modules\Reporting\Interfaces\ReportingRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class QcReportService
{
    public function __construct(
        private readonly ReportingRepositoryInterface $reporting,
    ) {}

    public function paginate(array $filters, int $perPage = 15): LengthAwarePaginator
    {
        return $this->reporting->qcQuery($filters)->paginate($perPage)->withQueryString();
    }

    /**
     * @return array{by_result: Collection, remake_count: int, total: int}
     */
    public function summary(array $filters): array
    {
        $byResult = $this->reporting->qcResultSummary($filters);

        return [
            'by_result' => $byResult,
            'remake_count' => $this->reporting->remakeCount($filters),
            'total' => (int) $byResult->sum('total'),
        ];
    }

    /**
     * @return array{filename: string, header: array<int, string>, rows: Collection}
     */
    public function export(array $filters): array
    {
        $rows = $this->reporting->qcQuery($filters)->get()->map(fn ($r) => [
            $r->order_number, $r->clinic_name, $r->inspector_name, $r->result ?? 'IN_REVIEW', $r->completed_at, $r->created_at,
        ]);

        return [
            'filename' => 'qc-report.csv',
            'header' => ['Order Number', 'Clinic', 'Inspector', 'Result', 'Completed At', 'Created At'],
            'rows' => $rows,
        ];
    }
}
