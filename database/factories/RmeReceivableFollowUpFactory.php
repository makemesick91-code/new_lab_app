<?php

namespace Database\Factories;

use App\Models\User;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use App\Modules\RmeInvoice\Models\RmeReceivableFollowUp;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RmeReceivableFollowUp>
 */
class RmeReceivableFollowUpFactory extends Factory
{
    protected $model = RmeReceivableFollowUp::class;

    public function definition(): array
    {
        $invoice = RmeInvoice::factory()->unpaid()->create();

        return [
            'rme_invoice_id' => $invoice->id,
            'branch_id' => $invoice->branch_id,
            'user_id' => User::factory(),
            'status' => RmeReceivableFollowUp::STATUS_NEW,
            'channel' => RmeReceivableFollowUp::CHANNEL_WHATSAPP,
            'contacted_at' => now(),
            'next_follow_up_date' => now()->addDays(3)->toDateString(),
            'note' => fake()->sentence(),
        ];
    }
}
