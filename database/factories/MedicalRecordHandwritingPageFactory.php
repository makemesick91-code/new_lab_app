<?php

namespace Database\Factories;

use App\Models\User;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\MedicalRecord\Models\MedicalRecordHandwritingPage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MedicalRecordHandwritingPage>
 */
class MedicalRecordHandwritingPageFactory extends Factory
{
    protected $model = MedicalRecordHandwritingPage::class;

    public function definition(): array
    {
        $medicalRecord = MedicalRecord::factory()->create();

        return [
            'medical_record_id' => $medicalRecord->id,
            'clinic_visit_id' => $medicalRecord->clinic_visit_id,
            'branch_id' => $medicalRecord->branch_id,
            'doctor_id' => Doctor::factory(),
            'page_number' => 2,
            'handwriting_path' => 'handwritings/test/page_'.fake()->unixTime().'.png',
            'handwriting_hash' => hash('sha256', fake()->sentence()),
            'saved_at' => now(),
            'created_by' => User::factory(),
            'updated_by' => User::factory(),
        ];
    }
}
