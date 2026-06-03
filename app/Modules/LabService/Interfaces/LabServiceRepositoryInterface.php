<?php

namespace App\Modules\LabService\Interfaces;

use App\Modules\LabService\Models\LabService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

interface LabServiceRepositoryInterface
{
    /**
     * @param  array{search?: string|null}  $filters
     */
    public function paginate(array $filters = [], int $perPage = 10): LengthAwarePaginator;

    public function listAll(): Collection;

    public function findById(int $id): ?LabService;

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): LabService;

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(LabService $labService, array $data): LabService;

    public function delete(LabService $labService): bool;

    public function setActiveStatus(LabService $labService, bool $isActive): LabService;
}
