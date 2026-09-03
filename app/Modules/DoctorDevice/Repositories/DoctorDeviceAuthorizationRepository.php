<?php

namespace App\Modules\DoctorDevice\Repositories;

use App\Modules\DoctorDevice\Interfaces\DoctorDeviceAuthorizationRepositoryInterface;
use App\Modules\DoctorDevice\Models\DoctorDeviceAuthorization;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class DoctorDeviceAuthorizationRepository implements DoctorDeviceAuthorizationRepositoryInterface
{
    public function paginate(array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        return DoctorDeviceAuthorization::query()
            // Eager loaded because the approval list renders doctor, device and
            // branch on every row; without this the badge screen is an N+1.
            ->with(['doctor', 'device.branch'])
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['branch_id'] ?? null, function ($query, $branchId) {
                $query->whereHas('device', fn ($q) => $q->where('branch_id', $branchId));
            })
            ->when($filters['search'] ?? null, function ($query, $search) {
                $term = '%'.mb_strtolower($search).'%';
                $query->where(function ($q) use ($term) {
                    $q->whereHas('doctor', fn ($d) => $d->whereRaw('LOWER(name) LIKE ?', [$term]))
                        ->orWhereHas('device', fn ($d) => $d->whereRaw('LOWER(device_name) LIKE ?', [$term]));
                });
            })
            // Pending first, then most recently requested: the approver's queue
            // is the point of the screen.
            ->orderByRaw("CASE WHEN status = 'pending' THEN 0 ELSE 1 END")
            ->orderByDesc('requested_at')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function findPair(int $doctorId, int $deviceId): ?DoctorDeviceAuthorization
    {
        return DoctorDeviceAuthorization::query()
            ->where('doctor_id', $doctorId)
            ->where('doctor_device_id', $deviceId)
            ->first();
    }

    public function findPairForUpdate(int $doctorId, int $deviceId): ?DoctorDeviceAuthorization
    {
        return DoctorDeviceAuthorization::query()
            ->lockForUpdate()
            ->where('doctor_id', $doctorId)
            ->where('doctor_device_id', $deviceId)
            ->first();
    }

    public function findForUpdate(int $id): ?DoctorDeviceAuthorization
    {
        return DoctorDeviceAuthorization::query()->lockForUpdate()->find($id);
    }

    /**
     * `forceFill` on purpose: the model has an empty `$fillable` so no request
     * payload can drive a lifecycle column, and the allow-list that matters is
     * the explicit array the service builds.
     */
    public function create(array $data): DoctorDeviceAuthorization
    {
        $authorization = new DoctorDeviceAuthorization;
        $authorization->forceFill($data)->save();

        return $authorization->refresh();
    }

    public function update(DoctorDeviceAuthorization $authorization, array $data): DoctorDeviceAuthorization
    {
        $authorization->forceFill($data)->save();

        return $authorization->refresh();
    }

    public function countPending(): int
    {
        return DoctorDeviceAuthorization::query()
            ->where('status', DoctorDeviceAuthorization::STATUS_PENDING)
            ->count();
    }
}
