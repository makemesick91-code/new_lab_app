<?php

namespace App\Modules\ClinicVisit\Interfaces;

use App\Modules\ClinicVisit\Models\ClinicVisit;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

interface ClinicVisitRepositoryInterface
{
    /** @param array{search?: string|null, status?: string|null, visit_date?: string|null} $filters */
    public function paginate(int $branchId, array $filters = [], int $perPage = 15): LengthAwarePaginator;

    /**
     * Paginate visits scoped to a set of branches (the active RME-enabled
     * "Cabang RME" set), rather than a single BranchContext fallback branch.
     *
     * @param  array<int, int>  $branchIds
     * @param  array{search?: string|null, status?: string|null, visit_date?: string|null}  $filters
     */
    public function paginateForBranches(array $branchIds, array $filters = [], int $perPage = 15): LengthAwarePaginator;

    /**
     * Paginate room-assigned, non-terminal visits for the doctor/nurse worklist,
     * scoped to the active RME-enabled branch set.
     *
     * @param  array<int, int>  $branchIds
     * @param  array{search?: string|null, status?: string|null, clinic_room_id?: int|null}  $filters
     */
    public function worklistForBranches(array $branchIds, array $filters = [], int $perPage = 20): LengthAwarePaginator;

    /**
     * Paginate active (non-terminal) registered-patient queue visits scoped to
     * the active RME-enabled branch set. Includes visits with and without an
     * assigned room. Sprint 58.7 — Antrian Pasien.
     *
     * @param  array<int, int>  $branchIds
     * @param  array{search?: string|null, status?: string|null, room_status?: string|null, visit_date?: string|null}  $filters
     */
    public function queueForBranches(array $branchIds, array $filters = [], int $perPage = 20): LengthAwarePaginator;

    public function findInBranch(int $branchId, int $id): ?ClinicVisit;

    public function nextQueueNumber(int $branchId, Carbon $visitDate): int;

    public function countTodayByBranch(int $branchId, string $date): int;

    public function countByBranchStatus(int $branchId, string $status): int;

    /** @param array<int, int> $branchIds */
    public function countTodayByBranches(array $branchIds, string $date): int;

    /** @param array<int, int> $branchIds */
    public function countByBranchesStatus(array $branchIds, string $status): int;

    /** @param array<string, mixed> $data */
    public function create(array $data): ClinicVisit;

    /** @param array<string, mixed> $data */
    public function update(ClinicVisit $visit, array $data): ClinicVisit;

    /**
     * List visits for a patient scoped to RME-enabled branches.
     *
     * @param  array<int, int>  $branchIds
     */
    public function listForPatient(array $branchIds, int $patientId, ?int $excludeVisitId = null): Collection;

    /**
     * Find a visit by ID when it belongs to one of the given RME branches.
     *
     * @param  array<int, int>  $branchIds
     */
    public function findByIdInBranches(array $branchIds, int $id): ?ClinicVisit;
}
