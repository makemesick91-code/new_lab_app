<?php

namespace Database\Factories;

use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicRoom\Models\ClinicRoom;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ClinicRoom>
 */
class ClinicRoomFactory extends Factory
{
    protected $model = ClinicRoom::class;

    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'code' => 'RM-'.strtoupper(Str::random(6)),
            // The random suffix already differentiates names; `unique()` is not
            // used here because a tiny base-name pool would exhaust once many
            // visits each auto-create a room (Hotfix Sprint 60.8 factory default).
            'name' => fake()->randomElement([
                'Ruang Perawatan',
                'Ruang Konsultasi',
                'Ruang Rontgen',
                'Ruang Sterilisasi',
                'Ruang Lab',
            ]).' '.strtoupper(Str::random(3)),
            'type' => fake()->randomElement(ClinicRoom::TYPES),
            'status' => ClinicRoom::STATUS_ACTIVE,
            'description' => fake()->optional()->sentence(),
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['status' => ClinicRoom::STATUS_INACTIVE]);
    }

    public function maintenance(): static
    {
        return $this->state(fn () => ['status' => ClinicRoom::STATUS_MAINTENANCE]);
    }
}
