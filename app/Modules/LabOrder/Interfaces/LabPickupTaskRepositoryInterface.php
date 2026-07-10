<?php

namespace App\Modules\LabOrder\Interfaces;

use App\Models\User;
use App\Modules\LabOrder\Models\LabOrder;
use App\Modules\LabOrder\Models\LabPickupTask;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface LabPickupTaskRepositoryInterface
{
    /** Idempotent: one pickup task per lab order (UNIQUE lab_order_id). */
    public function firstOrCreateForOrder(LabOrder $order, int $branchId, User $creator): LabPickupTask;

    public function findDetailById(int $id): ?LabPickupTask;

    /** Row-locked fetch for claim/transition concurrency safety. */
    public function lockById(int $id): ?LabPickupTask;

    /**
     * Courier/lab pickup queue with order + branch eager-loaded.
     *
     * @param  array<string, mixed>  $filters  status?, courier_id?, branch_id?
     */
    public function queue(array $filters = [], int $perPage = 15): LengthAwarePaginator;

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(LabPickupTask $task, array $data): LabPickupTask;
}
