<?php

namespace App\Modules\ClinicOversight\Interfaces;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * FEATURE-FINANCE-KLINIK-DATA-ANALYST-ROLES-1 — read-only operational
 * monitoring queries (dental rooms, patients per room, billed treatments per
 * room, visit queues).
 *
 * Every method takes the SERVER-resolved branch set first and returns nothing
 * for an empty set. Nothing here calls a service: in particular nothing calls
 * UserOnlineContextService (resolveDoctorIdForRoom / currentContextFor can
 * WRITE presence rows), so rendering a monitoring page never mutates state.
 */
interface OversightMonitoringRepositoryInterface
{
    /**
     * Active dental rooms (treatment + consultation) in the branches.
     *
     * @param  list<int>  $branchIds
     * @param  array{room_id?: int|null}  $filters
     */
    public function activeDentalRooms(array $branchIds, array $filters = []): Collection;

    /**
     * Visit counts per room for a clinic date: [room_id => [status => count]].
     *
     * @param  list<int>  $branchIds
     * @return array<int, array<string, int>>
     */
    public function roomVisitStatusCounts(array $branchIds, string $date): array;

    /**
     * Visits on the date assigned to an active dental room.
     *
     * @param  list<int>  $branchIds
     * @param  array<string, mixed>  $filters
     */
    public function roomPatients(array $branchIds, string $date, array $filters, ?int $perPage): LengthAwarePaginator|Collection;

    /**
     * Billed invoice items of visits on the date in an active dental room.
     *
     * @param  list<int>  $branchIds
     * @param  array<string, mixed>  $filters
     */
    public function roomTreatments(array $branchIds, string $date, array $filters, ?int $perPage): LengthAwarePaginator|Collection;

    /**
     * Aggregated billed treatments: one row per (room, treatment-or-free-text bucket).
     *
     * @param  list<int>  $branchIds
     * @param  array<string, mixed>  $filters
     */
    public function roomTreatmentSummary(array $branchIds, string $date, array $filters): Collection;

    /**
     * Visits of the clinic date (the queue), any room or none.
     *
     * @param  list<int>  $branchIds
     * @param  array<string, mixed>  $filters
     */
    public function queue(array $branchIds, string $date, array $filters, ?int $perPage): LengthAwarePaginator|Collection;

    /**
     * Queue status counts per branch for the date: [branch_id => [status => count]].
     *
     * @param  list<int>  $branchIds
     * @return array<int, array<string, int>>
     */
    public function queueStatusCountsByBranch(array $branchIds, string $date): array;

    /**
     * Unfinished visits from BEFORE the date (stale backlog), per branch.
     *
     * @param  list<int>  $branchIds
     * @return array<int, int>
     */
    public function staleUnfinishedByBranch(array $branchIds, string $date): array;
}
