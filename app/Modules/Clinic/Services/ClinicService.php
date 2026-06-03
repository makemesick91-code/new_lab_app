<?php

namespace App\Modules\Clinic\Services;

use App\Modules\Clinic\Interfaces\ClinicRepositoryInterface;
use App\Modules\Clinic\Models\Clinic;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ClinicService
{
    public function __construct(
        private readonly ClinicRepositoryInterface $clinics,
    ) {}

    public function list(array $filters = [], int $perPage = 10): LengthAwarePaginator
    {
        return $this->clinics->paginate($filters, $perPage);
    }

    public function listAll(): Collection
    {
        return $this->clinics->listAll();
    }

    public function find(int $id): ?Clinic
    {
        return $this->clinics->findById($id);
    }

    public function create(array $data): Clinic
    {
        return DB::transaction(fn () => $this->clinics->create($data));
    }

    public function update(Clinic $clinic, array $data): Clinic
    {
        return DB::transaction(fn () => $this->clinics->update($clinic, $data));
    }

    public function delete(Clinic $clinic): bool
    {
        return DB::transaction(fn () => $this->clinics->delete($clinic));
    }

    public function activate(Clinic $clinic): Clinic
    {
        return $this->clinics->setActiveStatus($clinic, true);
    }

    public function deactivate(Clinic $clinic): Clinic
    {
        return $this->clinics->setActiveStatus($clinic, false);
    }
}
