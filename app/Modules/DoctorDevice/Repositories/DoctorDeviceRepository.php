<?php

namespace App\Modules\DoctorDevice\Repositories;

use App\Modules\DoctorDevice\Interfaces\DoctorDeviceRepositoryInterface;
use App\Modules\DoctorDevice\Models\DoctorDevice;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class DoctorDeviceRepository implements DoctorDeviceRepositoryInterface
{
    public function paginate(array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        return DoctorDevice::query()
            ->with(['branch', 'registeredBy'])
            ->when($filters['branch_id'] ?? null, fn ($q, $v) => $q->where('branch_id', $v))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['search'] ?? null, function ($query, $search) {
                $term = '%'.mb_strtolower($search).'%';
                $query->where(function ($q) use ($term) {
                    $q->whereRaw('LOWER(device_name) LIKE ?', [$term])
                        ->orWhereRaw('LOWER(COALESCE(device_model, \'\')) LIKE ?', [$term])
                        ->orWhereRaw('LOWER(COALESCE(platform, \'\')) LIKE ?', [$term]);
                });
            })
            ->orderBy('branch_id')
            ->orderBy('device_name')
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * `forceFill` on purpose. The model's narrow `$fillable` is a guard against
     * mass assignment from a REQUEST; the allow-list that matters here is the
     * explicit payload DoctorDeviceService builds, and it needs to write
     * non-fillable columns such as `uuid` and the registration stamps.
     */
    public function create(array $data): DoctorDevice
    {
        $device = new DoctorDevice;
        $device->forceFill($data)->save();

        return $device->refresh();
    }

    public function update(DoctorDevice $device, array $data): DoctorDevice
    {
        $device->forceFill($data)->save();

        return $device->refresh();
    }

    /**
     * Row-locked read used by every lifecycle transition, so two concurrent
     * administrators cannot both act on the same stale status.
     */
    public function findForUpdate(int $id): ?DoctorDevice
    {
        return DoctorDevice::query()->lockForUpdate()->find($id);
    }

    public function existsWithNameInBranch(int $branchId, string $deviceName, ?int $exceptId = null): bool
    {
        return DoctorDevice::query()
            ->where('branch_id', $branchId)
            ->whereRaw('LOWER(device_name) = ?', [mb_strtolower($deviceName)])
            ->when($exceptId !== null, fn ($q) => $q->whereKeyNot($exceptId))
            ->exists();
    }
}
