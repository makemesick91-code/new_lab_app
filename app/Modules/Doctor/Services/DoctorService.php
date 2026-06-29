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

    public function listAll(?int $branchId = null): Collection
    {
        return $this->doctors->listAll($branchId);
    }

    public function find(int $id): ?Doctor
    {
        return $this->doctors->findById($id);
    }

    public function create(array $data): Doctor
    {
        return DB::transaction(fn () => $this->doctors->create($this->canonicalWritePayload($data)));
    }

    public function update(Doctor $doctor, array $data): Doctor
    {
        return DB::transaction(fn () => $this->doctors->update($doctor, $this->canonicalWritePayload($data)));
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

    /**
     * Sprint 66.1 — new doctor writes use branch_id only; legacy clinic_id is not set.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function canonicalWritePayload(array $data): array
    {
        unset($data['clinic_id']);
        $data['clinic_id'] = null;

        return $data;
    }
}
