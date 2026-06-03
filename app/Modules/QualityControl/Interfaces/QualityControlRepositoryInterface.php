<?php

namespace App\Modules\QualityControl\Interfaces;

use App\Modules\QualityControl\Models\QualityControl;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

interface QualityControlRepositoryInterface
{
    /**
     * Paginate QC_PENDING Lab Orders for the QC queue.
     *
     * @param  array<string, mixed>  $filters
     */
    public function paginateQueue(array $filters = [], int $perPage = 10): LengthAwarePaginator;

    public function findReviewById(int $id): ?QualityControl;

    public function findActiveByLabOrder(int $labOrderId): ?QualityControl;

    public function latestForLabOrder(int $labOrderId): ?QualityControl;

    public function historyForLabOrder(int $labOrderId): Collection;

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): QualityControl;

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(QualityControl $review, array $data): QualityControl;
}
