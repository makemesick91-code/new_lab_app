<?php

namespace Database\Factories;

use App\Modules\Branch\Models\Branch;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Branch>
 */
class BranchFactory extends Factory
{
    protected $model = Branch::class;

    public function definition(): array
    {
        return [
            'code' => 'BR-'.strtoupper(Str::random(6)),
            'name' => fake()->city().' Branch',
            'address' => fake()->streetAddress(),
            'phone' => fake()->numerify('08##########'),
            'is_active' => true,
        ];
    }

    /**
     * The default head-office branch (code MAIN) used as the multi-branch anchor.
     */
    public function main(): static
    {
        return $this->state(fn () => [
            'code' => 'MAIN',
            'name' => 'Klinik Gigi Daengtisia Pusat',
            'address' => 'Makassar',
            'phone' => null,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
