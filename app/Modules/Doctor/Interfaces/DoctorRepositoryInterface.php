<?php

namespace App\Modules\Doctor\Interfaces;

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
}
