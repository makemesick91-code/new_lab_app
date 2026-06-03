<?php

namespace App\Modules\Clinic\Interfaces;

use App\Modules\Clinic\Models\Clinic;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

interface ClinicRepositoryInterface
{
    /**
     * @param  array{search?: string|null}  $filters
     */
    public function paginate(array $filters = [], int $perPage = 10): LengthAwarePaginator;

    public function listAll(): Collection;

    public function findById(int $id): ?Clinic;

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Clinic;

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Clinic $clinic, array $data): Clinic;

    public function delete(Clinic $clinic): bool;

    public function setActiveStatus(Clinic $clinic, bool $isActive): Clinic;
}
