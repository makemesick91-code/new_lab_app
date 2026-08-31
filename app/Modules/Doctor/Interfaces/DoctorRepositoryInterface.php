<?php

namespace App\Modules\Doctor\Interfaces;

use App\Models\User;
use App\Modules\Doctor\Models\Doctor;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

interface DoctorRepositoryInterface
{
    /**
     * @param  array{search?: string|null, branch_id?: int|null}  $filters
     */
    public function paginate(array $filters = [], int $perPage = 10): LengthAwarePaginator;

    public function listAll(?int $branchId = null): Collection;

    public function findById(int $id): ?Doctor;

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Doctor;

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Doctor $doctor, array $data): Doctor;

    public function delete(Doctor $doctor): bool;

    public function setActiveStatus(Doctor $doctor, bool $isActive): Doctor;

    /**
     * @param  array<int, int>  $branchIds
     */
    public function syncAllowedBranches(Doctor $doctor, array $branchIds): void;

    /**
     * FEATURE-DOCTOR-ACCOUNT-PERFORMANCE-INCOME-LINKAGE-1 — account link reads/writes.
     *
     * @param  array{search?: string|null, link_status?: string|null}  $filters
     */
    public function paginateWithLinkedAccount(array $filters = [], int $perPage = 15): LengthAwarePaginator;

    /**
     * Re-read a doctor row under a write lock, so link preconditions and the
     * write itself observe the same state.
     */
    public function findForUpdate(int $id): ?Doctor;

    /**
     * The doctor currently linked to this account, optionally ignoring one
     * doctor id (the one being edited). Includes soft-deleted doctors: the
     * unique index on `user_id` still counts them.
     */
    public function findLinkedByUserId(int $userId, ?int $excludeDoctorId = null): ?Doctor;

    public function setLinkedUser(Doctor $doctor, ?int $userId): Doctor;

    /**
     * Accounts eligible to be linked: active, holding $role, and not already
     * linked to a doctor record.
     *
     * @return Collection<int, User>
     */
    public function linkableUserCandidates(string $role): Collection;
}
