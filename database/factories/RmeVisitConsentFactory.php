<?php

namespace Database\Factories;

use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Consent\Models\RmeVisitConsent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RmeVisitConsent>
 */
class RmeVisitConsentFactory extends Factory
{
    protected $model = RmeVisitConsent::class;

    public function definition(): array
    {
        $template = config('rme_consent.templates.PERSETUJUAN_TINDAKAN_MEDIS');

        return [
            'consent_number' => 'CONSENT-'.now()->format('Ym').'-'.str_pad((string) $this->faker->unique()->numberBetween(1, 999999), 6, '0', STR_PAD_LEFT),
            'clinic_visit_id' => ClinicVisit::factory(),
            'branch_id' => fn (array $attrs) => ClinicVisit::find($attrs['clinic_visit_id'])?->branch_id,
            'patient_id' => fn (array $attrs) => ClinicVisit::find($attrs['clinic_visit_id'])?->patient_id,
            'doctor_id' => fn (array $attrs) => ClinicVisit::find($attrs['clinic_visit_id'])?->doctor_id,

            'template_code' => $template['code'] ?? 'PERSETUJUAN_TINDAKAN_MEDIS',
            'template_version' => $template['version'] ?? '2026.1',
            'content_snapshot' => [
                'code' => $template['code'] ?? 'PERSETUJUAN_TINDAKAN_MEDIS',
                'version' => $template['version'] ?? '2026.1',
                'title' => $template['title'] ?? 'PERSETUJUAN TINDAKAN MEDIS',
                'clauses' => $template['clauses'] ?? [],
                'declaration' => $template['declaration'] ?? null,
            ],

            'consenter_relationship' => 'self',
            'consenter_name' => $this->faker->name(),
            'consenter_age' => (string) $this->faker->numberBetween(18, 80),
            'consenter_gender' => $this->faker->randomElement(['Male', 'Female']),
            'consenter_address' => $this->faker->address(),
            'consenter_identity_number' => (string) $this->faker->numerify('################'),

            'patient_name_snapshot' => $this->faker->name(),
            'patient_age_snapshot' => (string) $this->faker->numberBetween(1, 90),
            'patient_gender_snapshot' => $this->faker->randomElement(['Male', 'Female']),
            'patient_address_snapshot' => $this->faker->address(),
            'patient_identity_number_snapshot' => (string) $this->faker->numerify('################'),
            'medical_record_number_snapshot' => 'MRN-'.strtoupper($this->faker->bothify('??####')),

            'medical_action' => 'Pencabutan gigi',
            'treatment_summary' => 'Tindakan gigi',

            'documentation_consent' => false,

            'consenter_signature_path' => 'rme-consents/test/consenter.png',
            'doctor_signature_path' => null,
            'doctor_name_snapshot' => $this->faker->name(),
            'signed_location' => 'Makassar',
            'signed_at' => now(),
            'signed_by' => null,
        ];
    }

    public function voided(): static
    {
        return $this->state(fn () => [
            'voided_at' => now(),
            'void_reason' => 'Kesalahan input data pemberi persetujuan.',
        ]);
    }
}
