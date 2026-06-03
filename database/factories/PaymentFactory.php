<?php

namespace Database\Factories;

use App\Models\User;
use App\Modules\Invoice\Models\Invoice;
use App\Modules\Invoice\Models\Payment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    protected $model = Payment::class;

    public function definition(): array
    {
        $month = now()->format('Ym');

        return [
            'payment_number' => 'PAY-'.$month.'-'.str_pad((string) fake()->unique()->numberBetween(1, 999999), 6, '0', STR_PAD_LEFT),
            'invoice_id' => Invoice::factory()->issued(),
            'payment_date' => now()->toDateString(),
            'payment_method' => fake()->randomElement(Payment::METHODS),
            'amount' => fake()->randomFloat(2, 100000, 1000000),
            'reference_number' => fake()->optional()->bothify('REF-####'),
            'notes' => fake()->optional()->sentence(),
            'received_by' => User::factory(),
            'created_by' => User::factory(),
        ];
    }
}
