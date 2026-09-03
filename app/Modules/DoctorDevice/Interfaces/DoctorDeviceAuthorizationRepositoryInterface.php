<?php

namespace App\Modules\DoctorDevice\Interfaces;

use App\Modules\DoctorDevice\Models\DoctorDeviceAuthorization;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * REVISION-DOCTOR-AUTO-DEVICE-APPROVAL-APP-ONLY-LOGIN-1.
 *
 * Persistence for the doctor/device authorization. Every lifecycle rule lives
 * in DoctorDeviceAuthorizationService; this layer only reads and writes.
 */
interface DoctorDeviceAuthorizationRepositoryInterface
{
    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, DoctorDeviceAuthorization>
     */
    public function paginate(array $filters = [], int $perPage = 20): LengthAwarePaginator;

    public function findPair(int $doctorId, int $deviceId): ?DoctorDeviceAuthorization;

    public function findPairForUpdate(int $doctorId, int $deviceId): ?DoctorDeviceAuthorization;

    public function findForUpdate(int $id): ?DoctorDeviceAuthorization;

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): DoctorDeviceAuthorization;

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(DoctorDeviceAuthorization $authorization, array $data): DoctorDeviceAuthorization;

    /**
     * Actionable requests only. Counting anything else would put a number in
     * front of an approver that no action can clear.
     */
    public function countPending(): int;
}
