<?php

namespace App\Modules\Treatment\Interfaces;

use App\Modules\Treatment\Models\Treatment;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

interface TreatmentRepositoryInterface
{
    /**
     * @param  array{search?: string|null, treatment_category_id?: int|null, is_active?: bool|null, requires_lab?: bool|null}  $filters
     */
    public function paginate(array $filters = [], int $perPage = 10): LengthAwarePaginator;

    public function listActive(): Collection;

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Treatment;

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Treatment $treatment, array $data): Treatment;

    public function delete(Treatment $treatment): bool;
}
