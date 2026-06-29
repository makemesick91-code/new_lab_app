<?php

namespace Database\Factories;

use App\Modules\Branch\Models\Branch;
use App\Modules\Clinic\Models\Clinic;
use App\Modules\Doctor\Models\Doctor;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Doctor>
 */
class DoctorFactory extends Factory
{
    protected $model = Doctor::class;

    public function definition(): array
    {
        return [
            'clinic_id' => null,
            'branch_id' => Branch::factory()->state([
                'is_active' => true,
                'is_rme_enabled' => true,
            ]),
            'code' => 'DOC-'.strtoupper(Str::random(6)),
            'name' => 'Dr. '.fake()->name(),
            'phone' => fake()->numerify('08##########'),
            'email' => fake()->unique()->safeEmail(),
            'is_active' => true,
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (Doctor $doctor): void {
            if ($doctor->branch_id !== null) {
                $doctor->branches()->syncWithoutDetaching([(int) $doctor->branch_id]);
            }
        });
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }

    /**
     * Legacy clinic association for historical-read tests only.
     */
    public function withLegacyClinic(): static
    {
        return $this->state(fn () => [
            'clinic_id' => Clinic::factory(),
            'branch_id' => null,
        ]);
    }

    /**
     * @param  array<int, Branch|int>  $branches
     */
    public function withAllowedBranches(array $branches): static
    {
        return $this->afterCreating(function (Doctor $doctor) use ($branches): void {
            $ids = collect($branches)->map(function ($branch) {
                return $branch instanceof Branch ? $branch->id : (int) $branch;
            })->unique()->values()->all();

            $doctor->branches()->sync($ids);
        });
    }
}
