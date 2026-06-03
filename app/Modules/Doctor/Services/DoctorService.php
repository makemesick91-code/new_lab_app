<?php

namespace App\Modules\Doctor\Services;

use App\Modules\Doctor\Interfaces\DoctorRepositoryInterface;
use App\Modules\Doctor\Models\Doctor;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class DoctorService
{
    public function __construct(
        private readonly DoctorRepositoryInterface $doctors,
    ) {}

    public function list(array $filters = [], int $perPage = 10): LengthAwarePaginator
    {
        return $this->doctors->paginate($filters, $perPage);
    }

    public function listAll(?int $clinicId = null): Collection
    {
        return $this->doctors->listAll($clinicId);
    }

    public function find(int $id): ?Doctor
    {
        return $this->doctors->findById($id);
    }

    public function create(array $data): Doctor
    {
        return DB::transaction(fn () => $this->doctors->create($data));
    }

    public function update(Doctor $doctor, array $data): Doctor
    {
        return DB::transaction(fn () => $this->doctors->update($doctor, $data));
    }

    public function delete(Doctor $doctor): bool
    {
        return DB::transaction(fn () => $this->doctors->delete($doctor));
    }

    public function activate(Doctor $doctor): Doctor
    {
        return $this->doctors->setActiveStatus($doctor, true);
    }

    public function deactivate(Doctor $doctor): Doctor
    {
        return $this->doctors->setActiveStatus($doctor, false);
    }
}
