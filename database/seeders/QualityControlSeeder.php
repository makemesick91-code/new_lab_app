<?php

namespace Database\Seeders;

use App\Models\User;
use App\Modules\LabOrder\Models\LabOrder;
use App\Modules\QualityControl\Models\QualityControl;
use App\Modules\QualityControl\Services\QualityControlService;
use Illuminate\Database\Seeder;

/**
 * Sprint 5 — optional demo QC data. Idempotent and defensive
 * (tests seed only permissions/roles, never this seeder).
 */
class QualityControlSeeder extends Seeder
{
    public function run(): void
    {
        if (QualityControl::count() > 0) {
            return;
        }

        $admin = User::where('email', 'admin@asiadentallab.com')->first();
        $order = LabOrder::query()->whereNotIn('status', ['CANCELLED'])->orderBy('id')->first();

        if (! $admin || ! $order) {
            return;
        }

        // Demonstrate the QC queue: move one order to QC_PENDING and start a review.
        $order->update(['status' => LabOrder::STATUS_QC_PENDING]);

        app(QualityControlService::class)->start($order->refresh(), 'Seeded QC review', $admin);
    }
}
