<?php

namespace Database\Seeders;

use App\Models\User;
use App\Modules\Clinic\Models\Clinic;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\LabOrder\Models\LabOrder;
use App\Modules\LabOrder\Services\LabOrderService;
use App\Modules\LabService\Models\LabService;
use App\Modules\Patient\Models\Patient;
use Illuminate\Database\Seeder;

/**
 * Sprint 3 — optional sample lab orders. Idempotent and defensive so it never
 * breaks tests (tests seed only permissions/roles, not this seeder).
 */
class LabOrderSeeder extends Seeder
{
    public function run(): void
    {
        if (LabOrder::count() > 0) {
            return;
        }

        $admin = User::where('email', 'admin@asiadentallab.com')->first();
        $clinics = Clinic::with('doctors')->get();
        $services = LabService::all();

        if (! $admin || $clinics->isEmpty() || $services->isEmpty()) {
            return;
        }

        $service = app(LabOrderService::class);

        for ($i = 0; $i < 3; $i++) {
            $clinic = $clinics->random();
            $doctor = Doctor::where('clinic_id', $clinic->id)->first();
            $patient = Patient::where('clinic_id', $clinic->id)->first() ?? Patient::first();

            if (! $doctor || ! $patient) {
                continue;
            }

            $picked = $services->random(min(2, $services->count()));

            $service->create([
                'clinic_id' => $clinic->id,
                'doctor_id' => $doctor->id,
                'patient_id' => $patient->id,
                'order_date' => now()->toDateString(),
                'due_date' => now()->addDays(7)->toDateString(),
                'priority' => 'NORMAL',
                'notes' => 'Sample seeded order',
                'items' => $picked->map(fn ($svc) => [
                    'lab_service_id' => $svc->id,
                    'tooth_number' => (string) fake()->numberBetween(11, 48),
                    'shade_color_text' => 'A2',
                    'material_text' => 'Zirconia',
                    'quantity' => fake()->numberBetween(1, 3),
                    'unit_price' => (float) $svc->price,
                ])->all(),
            ], $admin);
        }
    }
}
