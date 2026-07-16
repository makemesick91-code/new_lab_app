<?php

namespace Database\Factories;

use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Patient\Models\Patient;
use App\Modules\Satusehat\Models\SatusehatCandidate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SatusehatCandidate>
 */
class SatusehatCandidateFactory extends Factory
{
    protected $model = SatusehatCandidate::class;

    public function definition(): array
    {
        $branch = Branch::factory()->create(['is_active' => true, 'is_rme_enabled' => true]);
        $patient = Patient::factory()->create(['branch_id' => $branch->id]);
        $visit = ClinicVisit::factory()->create([
            'branch_id' => $branch->id,
            'patient_id' => $patient->id,
            'status' => ClinicVisit::STATUS_COMPLETED,
        ]);

        return [
            'environment' => 'sandbox',
            'branch_id' => $branch->id,
            'clinic_visit_id' => $visit->id,
            'patient_id' => $patient->id,
            'doctor_id' => $visit->doctor_id,
            'source_version' => 1,
            'source_hash' => hash('sha256', 'seed-'.$visit->id),
            'readiness_status' => SatusehatCandidate::READINESS_INCOMPLETE,
            'readiness_reasons' => [],
            'review_status' => SatusehatCandidate::REVIEW_PENDING,
        ];
    }

    public function ready(): static
    {
        return $this->state(fn () => ['readiness_status' => SatusehatCandidate::READINESS_READY]);
    }

    public function approved(): static
    {
        return $this->state(fn () => [
            'readiness_status' => SatusehatCandidate::READINESS_READY,
            'review_status' => SatusehatCandidate::REVIEW_APPROVED,
            'approved_source_hash' => hash('sha256', 'seed'),
            'source_hash' => hash('sha256', 'seed'),
            'approved_at' => now(),
        ]);
    }

    /** A candidate whose branch is NOT RME-enabled (out of the review scope). */
    public function nonRme(): static
    {
        return $this->afterCreating(function (SatusehatCandidate $candidate) {
            $candidate->branch?->update(['is_rme_enabled' => false]);
        });
    }
}
