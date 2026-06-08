<?php

namespace App\Modules\TreatmentCategory\Interfaces;

use App\Modules\TreatmentCategory\Models\TreatmentCategory;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

interface TreatmentCategoryRepositoryInterface
{
    /**
     * @param  array{search?: string|null, is_active?: bool|null}  $filters
     */
    public function paginate(array $filters = [], int $perPage = 10): LengthAwarePaginator;

    public function listActive(): Collection;

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): TreatmentCategory;

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(TreatmentCategory $category, array $data): TreatmentCategory;

    public function delete(TreatmentCategory $category): bool;
}
