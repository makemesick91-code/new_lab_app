<?php

namespace App\Modules\Reporting\Services;

use App\Modules\Reporting\Interfaces\ReportingRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class DeliveryReportService
{
    public function __construct(
        private readonly ReportingRepositoryInterface $reporting,
    ) {}

    public function paginate(array $filters, int $perPage = 15): LengthAwarePaginator
    {
        return $this->reporting->deliveryQuery($filters)->paginate($perPage)->withQueryString();
    }

    /**
     * @return array{by_status: Collection, courier_performance: Collection, total: int}
     */
    public function summary(array $filters): array
    {
        $byStatus = $this->reporting->deliveryStatusSummary($filters);

        return [
            'by_status' => $byStatus,
            'courier_performance' => $this->reporting->courierPerformance($filters),
            'total' => (int) $byStatus->sum('total'),
        ];
    }

    /**
     * @return array{filename: string, header: array<int, string>, rows: Collection}
     */
    public function export(array $filters): array
    {
        $rows = $this->reporting->deliveryQuery($filters)->get()->map(fn ($r) => [
            $r->delivery_number, $r->order_number, $r->clinic_name, $r->courier_name, $r->status,
            $r->receiver_name, delivery_report_has_signature($r) ? 'YES' : 'NO', $r->completed_at,
        ]);

        return [
            'filename' => 'delivery-report.csv',
            'header' => ['Delivery Number', 'Order Number', 'Clinic', 'Courier', 'Status', 'Receiver', 'POD Signed', 'Completed At'],
            'rows' => $rows,
        ];
    }
}
