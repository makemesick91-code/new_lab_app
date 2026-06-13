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
        // Lazy defaults: when rme_invoice_id is overridden, no stray invoice is
        // created and branch_id is derived from the provided invoice.
        return [
            'rme_invoice_id' => RmeInvoice::factory()->unpaid(),
            'branch_id' => fn (array $attributes) => RmeInvoice::find($attributes['rme_invoice_id'])?->branch_id,
            'user_id' => User::factory(),
            'status' => RmeReceivableFollowUp::STATUS_NEW,
            'channel' => RmeReceivableFollowUp::CHANNEL_WHATSAPP,
            'contacted_at' => now(),
            'next_follow_up_date' => now()->addDays(3)->toDateString(),
            'note' => fake()->sentence(),
        ];
    }
}
