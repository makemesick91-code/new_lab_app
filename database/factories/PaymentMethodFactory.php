<?php

namespace Database\Factories;

use App\Modules\PaymentMethod\Models\PaymentMethod;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PaymentMethod>
 */
class PaymentMethodFactory extends Factory
{
    protected $model = PaymentMethod::class;

    public function definition(): array
    {
        return [
            'code' => 'PAY-'.strtoupper(Str::random(6)),
            'name' => fake()->unique()->words(2, true).' '.strtoupper(Str::random(3)),
            'type' => fake()->randomElement(PaymentMethod::TYPES),
            'description' => fake()->optional()->sentence(),
            'sort_order' => fake()->numberBetween(0, 100),
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
