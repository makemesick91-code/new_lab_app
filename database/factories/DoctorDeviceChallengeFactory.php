<?php

namespace Database\Factories;

use App\Modules\DoctorDevice\Models\DoctorDevice;
use App\Modules\DoctorDevice\Models\DoctorDeviceChallenge;
use App\Modules\DoctorDevice\Support\DeviceProofMessage;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<DoctorDeviceChallenge>
 */
class DoctorDeviceChallengeFactory extends Factory
{
    protected $model = DoctorDeviceChallenge::class;

    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'doctor_device_id' => DoctorDevice::factory(),
            'nonce' => bin2hex(random_bytes(32)),
            'purpose' => DeviceProofMessage::PURPOSE_DEVICE_PROOF,
            'expires_at' => now()->addSeconds(120),
        ];
    }

    public function expired(): static
    {
        return $this->state(fn () => ['expires_at' => now()->subSecond()]);
    }

    public function consumed(): static
    {
        return $this->state(fn () => ['consumed_at' => now()]);
    }
}
