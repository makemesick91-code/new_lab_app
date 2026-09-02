<?php

namespace Database\Factories;

use App\Modules\Branch\Models\Branch;
use App\Modules\DoctorDevice\Models\DoctorDevice;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<DoctorDevice>
 */
class DoctorDeviceFactory extends Factory
{
    protected $model = DoctorDevice::class;

    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'device_name' => 'Tablet '.$this->faker->unique()->numberBetween(1000, 9999),
            'branch_id' => Branch::factory()->create(['is_active' => true, 'is_rme_enabled' => true])->id,
            'status' => DoctorDevice::STATUS_ACTIVE,
            'identity_state' => DoctorDevice::IDENTITY_UNVERIFIED,
            'platform' => 'android',
            'device_model' => 'Pixel Tablet',
            'os_version' => '15',
            'app_version' => '1.0.0',
            'public_key_fingerprint' => null,
            'registered_at' => now(),
        ];
    }

    public function disabled(): static
    {
        return $this->state(fn () => [
            'status' => DoctorDevice::STATUS_DISABLED,
            'disabled_at' => now(),
            'disabled_reason' => 'Disimpan di gudang sementara.',
        ]);
    }

    public function revoked(): static
    {
        return $this->state(fn () => [
            'status' => DoctorDevice::STATUS_REVOKED,
            'revoked_at' => now(),
            'revoked_reason' => 'Perangkat hilang.',
        ]);
    }
}
