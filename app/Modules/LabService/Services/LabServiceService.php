<?php

namespace App\Modules\LabService\Services;

use App\Modules\LabService\Interfaces\LabServiceRepositoryInterface;
use App\Modules\LabService\Models\LabService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class LabServiceService
{
    public function __construct(
        private readonly LabServiceRepositoryInterface $labServices,
    ) {}

    public function list(array $filters = [], int $perPage = 10): LengthAwarePaginator
    {
        return $this->labServices->paginate($filters, $perPage);
    }

    public function listAll(): Collection
    {
        return $this->labServices->listAll();
    }

    public function find(int $id): ?LabService
    {
        return $this->labServices->findById($id);
    }

    public function create(array $data): LabService
    {
        return DB::transaction(fn () => $this->labServices->create($data));
    }

    public function update(LabService $labService, array $data): LabService
    {
        return DB::transaction(fn () => $this->labServices->update($labService, $data));
    }

    public function delete(LabService $labService): bool
    {
        return DB::transaction(fn () => $this->labServices->delete($labService));
    }

    public function activate(LabService $labService): LabService
    {
        return $this->labServices->setActiveStatus($labService, true);
    }

    public function deactivate(LabService $labService): LabService
    {
        return $this->labServices->setActiveStatus($labService, false);
    }
}
