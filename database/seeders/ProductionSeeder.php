<?php

namespace Database\Seeders;

use App\Models\User;
use App\Modules\LabOrder\Models\LabOrder;
use App\Modules\Production\Models\LabOrderAssignment;
use App\Modules\Production\Services\AssignmentService;
use App\Modules\Technician\Models\Technician;
use Illuminate\Database\Seeder;

/**
 * Sprint 4 — optional demo production data. Idempotent and defensive
 * (tests seed only permissions/roles, never this seeder).
 */
class ProductionSeeder extends Seeder
{
    public function run(): void
    {
        if (LabOrderAssignment::count() > 0) {
            return;
        }

        $admin = User::where('email', 'admin@asiadentallab.com')->first();
        $technician = Technician::first();
        $order = LabOrder::where('status', LabOrder::STATUS_RECEIVED)->first();

        if (! $admin || ! $technician || ! $order) {
            return;
        }

        // Assign the first RECEIVED order to demonstrate the production workflow.
        app(AssignmentService::class)->assign($order, $technician->id, 'Seeded production assignment', $admin);
    }
}
