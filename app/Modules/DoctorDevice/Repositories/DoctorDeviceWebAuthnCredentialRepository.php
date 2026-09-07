<?php

namespace App\Modules\DoctorDevice\Repositories;

use App\Modules\DoctorDevice\Interfaces\DoctorDeviceWebAuthnCredentialRepositoryInterface;
use App\Modules\DoctorDevice\Models\DoctorDevice;
use App\Modules\DoctorDevice\Models\DoctorDeviceAuthorization;
use App\Modules\DoctorDevice\Models\DoctorDeviceWebAuthnCredential;
use Illuminate\Support\Collection;

class DoctorDeviceWebAuthnCredentialRepository implements DoctorDeviceWebAuthnCredentialRepositoryInterface
{
    public function findByCredentialId(string $credentialId): ?DoctorDeviceWebAuthnCredential
    {
        if ($credentialId === '') {
            return null;
        }

        return DoctorDeviceWebAuthnCredential::query()
            ->where('credential_id', $credentialId)
            ->first();
    }

    public function usableForDevice(int $doctorDeviceId): Collection
    {
        return DoctorDeviceWebAuthnCredential::query()
            ->where('doctor_device_id', $doctorDeviceId)
            ->whereNull('revoked_at')
            ->orderBy('id')
            ->get();
    }

    public function usableForDoctor(int $doctorId): Collection
    {
        /*
         * Three conditions, all server-side:
         *   - the credential itself is not revoked
         *   - the device it belongs to is ACTIVE (not pending, disabled, revoked)
         *   - an ACTIVE authorization links this doctor to that device
         *
         * Advertising a credential the doctor would then be refused for would
         * leak which tablets exist and which are approved, which is a map of
         * the estate the login page has no business drawing.
         */
        $deviceIds = DoctorDeviceAuthorization::query()
            ->where('doctor_id', $doctorId)
            ->where('status', DoctorDeviceAuthorization::STATUS_ACTIVE)
            ->pluck('doctor_device_id');

        if ($deviceIds->isEmpty()) {
            return collect();
        }

        $usableDeviceIds = DoctorDevice::query()
            ->whereIn('id', $deviceIds)
            ->where('status', DoctorDevice::STATUS_ACTIVE)
            ->pluck('id');

        if ($usableDeviceIds->isEmpty()) {
            return collect();
        }

        return DoctorDeviceWebAuthnCredential::query()
            ->whereIn('doctor_device_id', $usableDeviceIds)
            ->whereNull('revoked_at')
            ->orderBy('id')
            ->get();
    }
}
