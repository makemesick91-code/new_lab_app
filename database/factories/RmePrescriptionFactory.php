<?php

namespace Database\Factories;

use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Prescription\Models\RmePrescription;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Storage;

/**
 * @extends Factory<RmePrescription>
 */
class RmePrescriptionFactory extends Factory
{
    protected $model = RmePrescription::class;

    public function definition(): array
    {
        return [
            'branch_id' => fn (array $attrs) => ClinicVisit::find($attrs['clinic_visit_id'])?->branch_id ?? 1,
            'clinic_visit_id' => ClinicVisit::factory(),
            'medical_record_id' => null,
            'patient_id' => fn (array $attrs) => ClinicVisit::find($attrs['clinic_visit_id'])?->patient_id ?? 1,
            'doctor_id' => fn (array $attrs) => ClinicVisit::find($attrs['clinic_visit_id'])?->doctor_id,
            'prescribed_by_name' => fake()->name(),
            'prescription_date' => now()->toDateString(),
            'patient_name_snapshot' => fake()->name(),
            'patient_age_snapshot' => (string) fake()->numberBetween(18, 70),
            'allergy_note' => null,
            'pregnant_or_breastfeeding' => null,
            'renal_function_issue' => null,
            'prescription_canvas_path' => 'prescriptions/test/prescription.png',
            'doctor_signature_canvas_path' => 'prescriptions/test/signature.png',
            'notes' => null,
            'status' => RmePrescription::STATUS_DRAFT,
            'printed_at' => null,
            'created_by' => null,
            'updated_by' => null,
        ];
    }

    public function withStoredCanvases(): static
    {
        return $this->afterCreating(function (RmePrescription $prescription) {
            $png = base64_decode(
                'iVBORw0KGgoAAAANSUhEUgAAAAoAAAAKCAYAAACNMs+9AAAAFUlEQVR42mNk+M9Qz0AEYBxVSF+FABJADveWkH6oAAAAAElFTkSuQmCC',
                true,
            );

            $rxPath = sprintf(
                'prescriptions/%d/%d/prescription_test.png',
                $prescription->branch_id,
                $prescription->clinic_visit_id,
            );
            $sigPath = sprintf(
                'prescriptions/%d/%d/signature_test.png',
                $prescription->branch_id,
                $prescription->clinic_visit_id,
            );

            Storage::disk('public')->put($rxPath, $png);
            Storage::disk('public')->put($sigPath, $png);

            $prescription->update([
                'prescription_canvas_path' => $rxPath,
                'doctor_signature_canvas_path' => $sigPath,
            ]);
        });
    }
}
