<?php

namespace Database\Seeders;

use App\Modules\Clinic\Models\Clinic;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\LabService\Models\LabService;
use App\Modules\Patient\Models\Patient;
use App\Modules\Technician\Models\Technician;
use Illuminate\Database\Seeder;

/**
 * TASK-0206 — sample master data. Idempotent: skips if clinics already exist.
 */
class MasterDataSeeder extends Seeder
{
    public function run(): void
    {
        if (Clinic::count() > 0) {
            return;
        }

        $clinics = Clinic::factory()->count(5)->create();

        // 10 doctors spread across the clinics.
        $doctors = collect();
        for ($i = 0; $i < 10; $i++) {
            $doctors->push(Doctor::factory()->create([
                'clinic_id' => $clinics->random()->id,
            ]));
        }

        // 20 patients, each tied to a clinic and one of that clinic's doctors.
        for ($i = 0; $i < 20; $i++) {
            $clinic = $clinics->random();
            $clinicDoctors = $doctors->where('clinic_id', $clinic->id);
            $doctor = $clinicDoctors->isNotEmpty() ? $clinicDoctors->random() : $doctors->random();

            Patient::factory()->create([
                'clinic_id' => $doctor->clinic_id,
                'doctor_id' => $doctor->id,
            ]);
        }

        LabService::factory()->count(10)->create();
        Technician::factory()->count(10)->create();
    }
}
