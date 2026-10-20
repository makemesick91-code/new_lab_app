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
     * REVISION-RME-MANUAL-PRICING-AUTHORITY-1 — which of the given ids name a
     * treatment that exists, is not soft-deleted and is active. One query.
     *
     * @param  list<int>  $ids
     * @return list<int>
     */
    public function activeIds(array $ids): array;

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
