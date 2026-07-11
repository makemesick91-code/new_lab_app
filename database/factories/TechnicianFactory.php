<?php

namespace Database\Factories;

use App\Models\User;
use App\Modules\Technician\Models\Technician;
use App\Modules\Technician\Services\TechnicianAssignmentEligibility;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

/**
 * @extends Factory<Technician>
 */
class TechnicianFactory extends Factory
{
    protected $model = Technician::class;

    public function definition(): array
    {
        return [
            'user_id' => null,
            'code' => 'TECH-'.strtoupper(Str::random(6)),
            'name' => fake()->name(),
            'phone' => fake()->numerify('08##########'),
            'email' => fake()->unique()->safeEmail(),
            'specialization' => fake()->randomElement(['Crown & Bridge', 'Denture', 'Orthodontic', 'Ceramic', 'Implant']),
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }

    /**
     * An ELIGIBLE assignment target: linked to an active user account holding
     * the canonical Technician role (TechnicianAssignmentEligibility rule).
     */
    public function assignable(): static
    {
        return $this->afterCreating(function (Technician $technician) {
            if ($technician->user_id === null) {
                $technician->forceFill([
                    'user_id' => User::factory()->create(['is_active' => true])->id,
                ])->save();
            }

            Role::findOrCreate(TechnicianAssignmentEligibility::ROLE, 'web');
            $technician->user()->first()?->assignRole(TechnicianAssignmentEligibility::ROLE);
        });
    }
}
