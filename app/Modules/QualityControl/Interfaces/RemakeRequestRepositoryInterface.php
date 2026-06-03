<?php

namespace App\Modules\QualityControl\Interfaces;

use App\Modules\QualityControl\Models\RemakeRequest;
use Illuminate\Support\Collection;

interface RemakeRequestRepositoryInterface
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): RemakeRequest;

    public function findById(int $id): ?RemakeRequest;

    public function forLabOrder(int $labOrderId): Collection;

    public function forQualityControl(int $qualityControlId): Collection;

    public function latestForLabOrder(int $labOrderId): ?RemakeRequest;

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(RemakeRequest $remakeRequest, array $data): RemakeRequest;
}
