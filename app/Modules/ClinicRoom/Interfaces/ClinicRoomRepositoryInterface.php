<?php

namespace App\Modules\ClinicRoom\Interfaces;

use App\Modules\ClinicRoom\Models\ClinicRoom;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

interface ClinicRoomRepositoryInterface
{
    /**
     * @param  array{search?: string|null, type?: string|null, status?: string|null}  $filters
     */
    public function paginate(int $branchId, array $filters = [], int $perPage = 10): LengthAwarePaginator;

    public function listActive(int $branchId): Collection;

    public function findInBranch(int $branchId, int $id): ?ClinicRoom;

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): ClinicRoom;

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(ClinicRoom $room, array $data): ClinicRoom;

    public function delete(ClinicRoom $room): bool;
}
