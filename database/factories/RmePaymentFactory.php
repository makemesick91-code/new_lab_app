<?php

namespace Database\Factories;

use App\Models\User;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use App\Modules\RmeInvoice\Models\RmePayment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RmePayment>
 */
class RmePaymentFactory extends Factory
{
    protected $model = RmePayment::class;

    public function definition(): array
    {
        $invoice = RmeInvoice::factory()->unpaid()->create();
        $month = now()->format('Ym');

        return [
            'branch_id' => $invoice->branch_id,
            'rme_invoice_id' => $invoice->id,
            'clinic_visit_id' => $invoice->clinic_visit_id,
            'patient_id' => $invoice->patient_id,
            'cashier_id' => User::factory(),
            'payment_method_id' => null,
            'payment_number' => 'RMEPAY-'.$month.'-'.str_pad((string) fake()->unique()->numberBetween(1, 999999), 6, '0', STR_PAD_LEFT),
            'amount' => 0,
            'paid_at' => now(),
            'reference_number' => null,
            'notes' => null,
        ];
    }
}
