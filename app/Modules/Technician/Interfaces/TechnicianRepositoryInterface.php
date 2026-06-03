<?php

namespace App\Modules\Technician\Interfaces;

use App\Modules\Technician\Models\Technician;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

interface TechnicianRepositoryInterface
{
    /**
     * @param  array{search?: string|null}  $filters
     */
    public function paginate(array $filters = [], int $perPage = 10): LengthAwarePaginator;

    public function listAll(): Collection;

    public function findById(int $id): ?Technician;

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Technician;

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Technician $technician, array $data): Technician;

    public function delete(Technician $technician): bool;

    public function setActiveStatus(Technician $technician, bool $isActive): Technician;
}
