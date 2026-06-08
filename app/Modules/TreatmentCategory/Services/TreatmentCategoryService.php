<?php

namespace App\Modules\TreatmentCategory\Services;

use App\Modules\TreatmentCategory\Interfaces\TreatmentCategoryRepositoryInterface;
use App\Modules\TreatmentCategory\Models\TreatmentCategory;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class TreatmentCategoryService
{
    public function __construct(
        private readonly TreatmentCategoryRepositoryInterface $categories,
    ) {}

    public function paginate(array $filters = [], int $perPage = 10): LengthAwarePaginator
    {
        return $this->categories->paginate($filters, $perPage);
    }

    public function listActive(): Collection
    {
        return $this->categories->listActive();
    }

    public function create(array $data): TreatmentCategory
    {
        return DB::transaction(fn () => $this->categories->create($data));
    }

    public function update(TreatmentCategory $category, array $data): TreatmentCategory
    {
        return DB::transaction(fn () => $this->categories->update($category, $data));
    }

    public function delete(TreatmentCategory $category): bool
    {
        return DB::transaction(fn () => $this->categories->delete($category));
    }
}
