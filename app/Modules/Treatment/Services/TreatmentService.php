<?php

namespace App\Modules\Treatment\Services;

use App\Modules\Treatment\Interfaces\TreatmentRepositoryInterface;
use App\Modules\Treatment\Models\Treatment;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class TreatmentService
{
    public function __construct(
        private readonly TreatmentRepositoryInterface $treatments,
    ) {}

    public function paginate(array $filters = [], int $perPage = 10): LengthAwarePaginator
    {
        return $this->treatments->paginate($filters, $perPage);
    }

    public function listActive(): Collection
    {
        return $this->treatments->listActive();
    }

    public function create(array $data): Treatment
    {
        return DB::transaction(fn () => $this->treatments->create($data));
    }

    public function update(Treatment $treatment, array $data): Treatment
    {
        return DB::transaction(fn () => $this->treatments->update($treatment, $data));
    }

    public function delete(Treatment $treatment): bool
    {
        return DB::transaction(fn () => $this->treatments->delete($treatment));
    }
}
