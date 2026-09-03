<?php

namespace Database\Factories;

use App\Modules\Doctor\Models\Doctor;
use App\Modules\DoctorDevice\Models\DoctorDevice;
use App\Modules\DoctorDevice\Models\DoctorDeviceAuthorization;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<DoctorDeviceAuthorization>
 */
class DoctorDeviceAuthorizationFactory extends Factory
{
    protected $model = DoctorDeviceAuthorization::class;

    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'doctor_id' => Doctor::factory(),
            'doctor_device_id' => DoctorDevice::factory(),
            'status' => DoctorDeviceAuthorization::STATUS_PENDING,
            'request_source' => DoctorDeviceAuthorization::SOURCE_APP_LOGIN,
            'requested_at' => now(),
        ];
    }

    public function active(): static
    {
        return $this->state(fn () => [
            'status' => DoctorDeviceAuthorization::STATUS_ACTIVE,
            'approved_at' => now(),
        ]);
    }

    public function rejected(): static
    {
        return $this->state(fn () => [
            'status' => DoctorDeviceAuthorization::STATUS_REJECTED,
            'rejected_at' => now(),
            'rejected_reason' => 'Perangkat bukan milik klinik.',
        ]);
    }

    public function revoked(): static
    {
        return $this->state(fn () => [
            'status' => DoctorDeviceAuthorization::STATUS_REVOKED,
            'approved_at' => now()->subDay(),
            'revoked_at' => now(),
            'revoked_reason' => 'Dokter pindah cabang.',
        ]);
    }
}
