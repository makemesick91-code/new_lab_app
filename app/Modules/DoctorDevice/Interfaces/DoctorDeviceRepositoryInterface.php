<?php

namespace App\Modules\DoctorDevice\Interfaces;

use App\Modules\DoctorDevice\Models\DoctorDevice;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface DoctorDeviceRepositoryInterface
{
    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, DoctorDevice>
     */
    public function paginate(array $filters = [], int $perPage = 20): LengthAwarePaginator;

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): DoctorDevice;

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(DoctorDevice $device, array $data): DoctorDevice;

    public function findForUpdate(int $id): ?DoctorDevice;

    public function existsWithNameInBranch(int $branchId, string $deviceName, ?int $exceptId = null): bool;
}
