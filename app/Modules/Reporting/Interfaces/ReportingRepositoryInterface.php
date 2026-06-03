<?php

namespace App\Modules\Reporting\Interfaces;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;

/**
 * Read-only reporting query contract. All methods take a filter array:
 * date_from, date_to, clinic_id, doctor_id, status, service_id, technician_id,
 * qc_status, courier_id, delivery_status, invoice_status, payment_method,
 * received_by, include_void.
 *
 * @phpstan-type Filters array<string, mixed>
 */
interface ReportingRepositoryInterface
{
    public function ordersQuery(array $f): Builder;

    public function ordersStatusSummary(array $f): Collection;

    public function productionQuery(array $f): Builder;

    public function technicianWorkload(array $f): Collection;

    public function qcQuery(array $f): Builder;

    public function qcResultSummary(array $f): Collection;

    public function remakeCount(array $f): int;

    public function deliveryQuery(array $f): Builder;

    public function deliveryStatusSummary(array $f): Collection;

    public function courierPerformance(array $f): Collection;

    public function invoicesQuery(array $f): Builder;

    public function invoiceStatusSummary(array $f): Collection;

    public function paymentsQuery(array $f): Builder;

    public function paymentsByMethod(array $f): Collection;

    public function paymentsByUser(array $f): Collection;

    public function paymentsTotal(array $f): float;

    public function outstandingQuery(array $f): Builder;

    public function revenueRows(array $f): Collection;

    public function revenueByClinic(array $f): Collection;

    public function invoiceRevenueTotal(array $f): float;

    public function outstandingTotal(array $f): float;

    public function orderStatusCount(?string $status): int;

    public function overdueInvoiceCount(): int;

    public function ordersByClinic(array $f): Collection;
}
