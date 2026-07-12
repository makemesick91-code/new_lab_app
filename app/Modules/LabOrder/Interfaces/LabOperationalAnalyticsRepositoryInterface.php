<?php

namespace App\Modules\LabOrder\Interfaces;

use App\Modules\LabOrder\Models\LabOrder;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * LAB-PROD-2 — Operational Analytics data access boundary.
 *
 * All Lab Workflow V2 KPI data access lives behind this interface. Every method
 * is READ-ONLY and branch/technician scoped: a null $branchId means the caller
 * is authorised for all RME branches (management tier); a concrete int constrains
 * to that branch; a non-null $technicianId forces the technician self-scope
 * (order-level metrics restricted to orders that technician was assigned to,
 * assignment-level metrics restricted to that technician). Callers must resolve
 * scope server-side and never pass a request-supplied branch/technician id
 * without validation. No PII is selected or returned.
 */
interface LabOperationalAnalyticsRepositoryInterface
{
    /**
     * Current (now) count of V2 orders per workflow status.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, int> status => count
     */
    public function currentStatusCounts(?int $branchId, ?int $technicianId, array $filters): array;

    /**
     * Count of active (non-terminal) V2 orders whose due_date is before today.
     *
     * @param  array<string, mixed>  $filters
     */
    public function openOverdueCount(?int $branchId, ?int $technicianId, array $filters): int;

    /**
     * Count of V2 orders whose order_date falls within [$from, $to].
     *
     * @param  array<string, mixed>  $filters
     */
    public function ordersReceivedCount(?int $branchId, ?int $technicianId, string $from, string $to, array $filters): int;

    /**
     * First DELIVERED transition per order with changed_at within [$from, $to].
     *
     * @param  array<string, mixed>  $filters
     * @return Collection<int, object{lab_order_id: int, delivered_at: string}>
     */
    public function deliveredTransitions(?int $branchId, ?int $technicianId, string $from, string $to, array $filters): Collection;

    /**
     * Completed (delivered in period) orders that HAD a due_date, with the
     * delivered timestamp (first DELIVERED status log). SLA-eligible cases only.
     *
     * @param  array<string, mixed>  $filters
     * @return Collection<int, object{id: int, due_date: string, delivered_at: string}>
     */
    public function slaCompletedCases(?int $branchId, ?int $technicianId, string $from, string $to, array $filters): Collection;

    /**
     * Ordered QC transitions (QC_PASSED/QC_FAILED) per order that had a QC
     * attempt whose changed_at falls within [$from, $to].
     *
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array{order_id: int, results: list<string>}>
     */
    public function qcAttemptSequences(?int $branchId, ?int $technicianId, string $from, string $to, array $filters): Collection;

    /**
     * Per-technician assignment aggregates within [$from, $to].
     *
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array{technician_id: int, name: string, active_wip: int, assigned: int, completed: int, completion_minutes: list<float>}>
     */
    public function technicianAssignmentStats(?int $branchId, ?int $technicianId, string $from, string $to, array $filters): Collection;

    /**
     * Analysis routing decisions (INTERNAL/EXTERNAL) recorded within [$from, $to].
     *
     * @param  array<string, mixed>  $filters
     * @return array{internal: int, external: int}
     */
    public function analysisDecisionCounts(?int $branchId, ?int $technicianId, string $from, string $to, array $filters): array;

    /**
     * External dispatch turnarounds (sent_at → returned_at) within [$from, $to].
     *
     * @param  array<string, mixed>  $filters
     * @return Collection<int, object{sent_at: string, returned_at: string}>
     */
    public function externalTurnarounds(?int $branchId, ?int $technicianId, string $from, string $to, array $filters): Collection;

    /**
     * Data-quality coverage counts for V2 orders received within [$from, $to].
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, int>
     */
    public function dataQualityCounts(?int $branchId, ?int $technicianId, string $from, string $to, array $filters): array;

    /**
     * Paginated drill-down order list (privacy-safe columns only).
     *
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, LabOrder>
     */
    public function drilldownOrders(?int $branchId, ?int $technicianId, string $from, string $to, array $filters, int $perPage): LengthAwarePaginator;
}
