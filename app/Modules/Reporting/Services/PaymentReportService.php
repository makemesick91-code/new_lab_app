<?php

namespace App\Modules\Reporting\Services;

use App\Modules\Reporting\Interfaces\ReportingRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class PaymentReportService
{
    public function __construct(
        private readonly ReportingRepositoryInterface $reporting,
    ) {}

    public function paginate(array $filters, int $perPage = 15): LengthAwarePaginator
    {
        return $this->reporting->paymentsQuery($filters)->paginate($perPage)->withQueryString();
    }

    /**
     * @return array{by_method: Collection, by_user: Collection, total: float}
     */
    public function summary(array $filters): array
    {
        return [
            'by_method' => $this->reporting->paymentsByMethod($filters),
            'by_user' => $this->reporting->paymentsByUser($filters),
            'total' => $this->reporting->paymentsTotal($filters),
        ];
    }

    /**
     * @return array{filename: string, header: array<int, string>, rows: Collection}
     */
    public function export(array $filters): array
    {
        $rows = $this->reporting->paymentsQuery($filters)->get()->map(fn ($r) => [
            $r->payment_number, $r->invoice_number, $r->clinic_name, $r->payment_date, $r->payment_method, $r->amount, $r->received_by_name,
        ]);

        return [
            'filename' => 'payment-report.csv',
            'header' => ['Payment Number', 'Invoice Number', 'Clinic', 'Payment Date', 'Method', 'Amount', 'Received By'],
            'rows' => $rows,
        ];
    }
}
