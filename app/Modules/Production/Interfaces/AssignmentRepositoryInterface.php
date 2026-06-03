<?php

namespace App\Modules\Production\Interfaces;

use App\Modules\Production\Models\LabOrderAssignment;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

interface AssignmentRepositoryInterface
{
    /**
     * Paginate Lab Orders for the Production Board (with active assignment).
     *
     * @param  array<string, mixed>  $filters
     */
    public function paginateBoard(array $filters = [], int $perPage = 10): LengthAwarePaginator;

    /**
     * Paginate assignment records.
     *
     * @param  array<string, mixed>  $filters
     */
    public function paginate(array $filters = [], int $perPage = 10): LengthAwarePaginator;

    public function findById(int $id): ?LabOrderAssignment;

    public function findActiveByLabOrder(int $labOrderId): ?LabOrderAssignment;

    public function forLabOrder(int $labOrderId): Collection;

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): LabOrderAssignment;

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(LabOrderAssignment $assignment, array $data): LabOrderAssignment;
}
