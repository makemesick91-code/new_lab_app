<?php

namespace App\Modules\Production\Interfaces;

use App\Modules\Production\Models\WorkLog;
use Illuminate\Support\Collection;

interface WorkLogRepositoryInterface
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): WorkLog;

    public function forAssignment(int $assignmentId): Collection;

    public function forLabOrder(int $labOrderId): Collection;

    public function latestForAssignment(int $assignmentId): ?WorkLog;
}
