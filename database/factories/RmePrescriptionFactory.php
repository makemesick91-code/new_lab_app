<?php

namespace Database\Factories;

use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Prescription\Models\RmePrescription;
use App\Support\Storage\ClinicalEvidenceStorage;
use Illuminate\Database\Eloquent\Factories\Factory;

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

            /*
             * FINAL-STABILIZATION-RESIDUAL-AUDIT-1 — the fixture writes through the
             * SAME authority as production (RmePrescriptionService::storeCanvas).
             *
             * It previously wrote to the 'public' disk. Production reads these
             * columns back through ClinicalEvidenceStorage::dataUri(), which
             * resolves against the private clinical disk, so a fixture on the
             * public disk produced a row whose canvases silently read back as
             * null. A print test then asserted assertSee(null) — which passes
             * against any response — so "renders the canvas" was never proven.
             *
             * A fixture must not be able to reintroduce the storage layout the
             * public-disk incident removed; the governance scan in
             * tests/Feature/Storage/ClinicalEvidencePrivacyTest.php now covers
             * database/ for exactly that reason.
             */
            ClinicalEvidenceStorage::disk()->put($rxPath, $png);
            ClinicalEvidenceStorage::disk()->put($sigPath, $png);

            $prescription->update([
                'prescription_canvas_path' => $rxPath,
                'doctor_signature_canvas_path' => $sigPath,
            ]);
        });
    }
}
