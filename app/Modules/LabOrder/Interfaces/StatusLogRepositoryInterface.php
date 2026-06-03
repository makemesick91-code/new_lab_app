<?php

namespace App\Modules\LabOrder\Interfaces;

use App\Modules\LabOrder\Models\LabOrderStatusLog;
use Illuminate\Support\Collection;

interface StatusLogRepositoryInterface
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): LabOrderStatusLog;

    public function forLabOrder(int $labOrderId): Collection;

    public function latestForLabOrder(int $labOrderId): ?LabOrderStatusLog;
}
