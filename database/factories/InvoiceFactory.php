<?php

namespace Database\Factories;

use App\Models\User;
use App\Modules\Clinic\Models\Clinic;
use App\Modules\Invoice\Models\Invoice;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Invoice>
 */
class InvoiceFactory extends Factory
{
    protected $model = Invoice::class;

    public function definition(): array
    {
        $month = now()->format('Ym');
        $total = fake()->randomFloat(2, 500000, 5000000);

        return [
            'invoice_number' => 'INV-'.$month.'-'.str_pad((string) fake()->unique()->numberBetween(1, 999999), 6, '0', STR_PAD_LEFT),
            'clinic_id' => Clinic::factory(),
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(14)->toDateString(),
            'status' => Invoice::STATUS_DRAFT,
            'subtotal' => $total,
            'discount_amount' => 0,
            'tax_amount' => 0,
            'total_amount' => $total,
            'paid_amount' => 0,
            'outstanding_amount' => $total,
            'notes' => fake()->optional()->sentence(),
            'created_by' => User::factory(),
            'issued_at' => null,
            'voided_at' => null,
        ];
    }

    public function issued(): static
    {
        return $this->state(fn () => [
            'status' => Invoice::STATUS_ISSUED,
            'issued_at' => now(),
        ]);
    }

    public function paid(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Invoice::STATUS_PAID,
            'paid_amount' => $attributes['total_amount'],
            'outstanding_amount' => 0,
            'issued_at' => now(),
        ]);
    }
}
