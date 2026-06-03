<?php

namespace App\Modules\Technician\Services;

use App\Modules\Technician\Interfaces\TechnicianRepositoryInterface;
use App\Modules\Technician\Models\Technician;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class TechnicianService
{
    public function __construct(
        private readonly TechnicianRepositoryInterface $technicians,
    ) {}

    public function list(array $filters = [], int $perPage = 10): LengthAwarePaginator
    {
        return $this->technicians->paginate($filters, $perPage);
    }

    public function find(int $id): ?Technician
    {
        return $this->technicians->findById($id);
    }

    public function create(array $data): Technician
    {
        return DB::transaction(fn () => $this->technicians->create($data));
    }

    public function update(Technician $technician, array $data): Technician
    {
        return DB::transaction(fn () => $this->technicians->update($technician, $data));
    }

    public function delete(Technician $technician): bool
    {
        return DB::transaction(fn () => $this->technicians->delete($technician));
    }

    public function activate(Technician $technician): Technician
    {
        return $this->technicians->setActiveStatus($technician, true);
    }

    public function deactivate(Technician $technician): Technician
    {
        return $this->technicians->setActiveStatus($technician, false);
    }
}
