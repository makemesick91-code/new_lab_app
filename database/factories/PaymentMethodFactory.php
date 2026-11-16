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
            // DMS-RME-COMPREHENSIVE-CLOSURE-1 PR8 (rule 238 §3) — deterministic
            // (CG-20): a random type made every fixture a Bank Transfer one time
            // in five, and a transfer now needs a reference. Use bankTransfer()
            // or an explicit type where the method matters.
            'type' => 'cash',
            'description' => fake()->optional()->sentence(),
            'sort_order' => fake()->numberBetween(0, 100),
            'is_active' => true,
        ];
    }

    public function bankTransfer(): static
    {
        return $this->state(fn () => ['type' => PaymentMethod::TYPE_BANK_TRANSFER]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
