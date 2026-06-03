<?php

namespace Database\Seeders;

use App\Models\User;
use App\Modules\Invoice\Models\Invoice;
use App\Modules\Invoice\Models\Payment;
use App\Modules\Invoice\Services\PaymentService;
use Illuminate\Database\Seeder;

class PaymentSeeder extends Seeder
{
    public function run(): void
    {
        if (Payment::count() > 0) {
            return;
        }

        $admin = User::where('email', 'admin@asiadentallab.com')->first();

        if (! $admin) {
            return;
        }

        $invoice = Invoice::query()
            ->where('status', Invoice::STATUS_ISSUED)
            ->where('outstanding_amount', '>', 0)
            ->first();

        if (! $invoice) {
            return;
        }

        $amount = min(500000, (float) $invoice->outstanding_amount);

        app(PaymentService::class)->record($invoice, [
            'payment_date' => now()->toDateString(),
            'payment_method' => Payment::METHOD_BANK_TRANSFER,
            'amount' => $amount,
            'reference_number' => 'SEED-PAYMENT',
            'notes' => 'Seeded partial payment',
        ], $admin);
    }
}
