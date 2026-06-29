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
        return DB::transaction(function () use ($data) {
            $branchIds = $this->extractBranchIds($data);
            $doctor = $this->doctors->create($this->canonicalWritePayload($data));
            $this->doctors->syncAllowedBranches($doctor, $branchIds);

            return $doctor->load('branches');
        });
    }

    public function update(Doctor $doctor, array $data): Doctor
    {
        return DB::transaction(function () use ($doctor, $data) {
            $branchIds = $this->extractBranchIds($data);
            $doctor = $this->doctors->update($doctor, $this->canonicalWritePayload($data));
            $this->doctors->syncAllowedBranches($doctor, $branchIds);

            return $doctor->load('branches');
        });
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
     * Sprint 66.1.1 — clinic_id stays null; branch_id is not the write source of truth.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function canonicalWritePayload(array $data): array
    {
        unset($data['branch_ids'], $data['branches'], $data['branch_id']);
        $data['clinic_id'] = null;

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<int, int>
     */
    private function extractBranchIds(array $data): array
    {
        $branchIds = $data['branch_ids'] ?? $data['branches'] ?? [];

        return collect($branchIds)
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }
}
