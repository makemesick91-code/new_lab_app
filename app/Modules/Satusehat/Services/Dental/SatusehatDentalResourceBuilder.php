<?php

namespace App\Modules\Satusehat\Services\Dental;

use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Satusehat\Interfaces\SatusehatIdentifierRepositoryInterface;
use App\Modules\Satusehat\Models\SatusehatEntityIdentifier;
use App\Modules\Satusehat\Support\SatusehatDentalSnapshot;
use App\Modules\Satusehat\Support\SatusehatPeriodResolver;

/**
 * Coordinates the domain-specific dental Observation builders into an ordered,
 * dependency-consistent local preview. Side-effect-free, network-silent, and
 * PII-free. It resolves the Encounter/Patient/Practitioner references (local id
 * + optional remote IHS id) and the UTC visit period once, then delegates each
 * dental variable to its own typed builder — no giant conditional block.
 */
class SatusehatDentalResourceBuilder
{
    public function __construct(
        private readonly SatusehatDentalSnapshotBuilder $snapshots,
        private readonly DentalOdontogramObservationBuilder $odontogram,
        private readonly DentalToothConditionObservationBuilder $toothCondition,
        private readonly DentalDmfObservationBuilder $dmf,
        private readonly DentalOtherFindingObservationBuilder $otherFinding,
        private readonly SatusehatIdentifierRepositoryInterface $identifiers,
        private readonly SatusehatPeriodResolver $period,
    ) {}

    /**
     * @return array{environment:string, note:string, resources:list<array<string,mixed>>, mapping_versions:array<string,int>}
     */
    public function build(ClinicVisit $visit, ?SatusehatDentalSnapshot $snapshot = null, ?string $environment = null): array
    {
        $env = $environment ?? (string) config('satusehat.environment');
        $snapshot ??= $this->snapshots->build($visit, $env);

        $ctx = $this->context($visit, $env);

        $resources = [];
        $order = 0;

        // 1. Parent odontogram examination.
        $resources[] = $this->odontogram->build($snapshot, $ctx, $order++);

        // 2. Per-tooth conditions (0..n).
        foreach ($this->toothCondition->build($snapshot, $ctx, $order) as $descriptor) {
            $resources[] = $descriptor;
            $order++;
        }

        // 3. DMF counts (D/M/F).
        foreach ($this->dmf->build($snapshot, $ctx, $order) as $descriptor) {
            $resources[] = $descriptor;
            $order++;
        }

        // 4. Other oral condition (presence only).
        $resources[] = $this->otherFinding->build($snapshot, $ctx, $order++);

        return [
            'environment' => $env,
            'note' => 'Preview lokal gigi — belum dikirim dan belum diverifikasi oleh API SATUSEHAT.',
            'resources' => $resources,
            'mapping_versions' => $this->mappingVersions($resources),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function context(ClinicVisit $visit, string $env): array
    {
        $visit->loadMissing(['patient', 'doctor', 'branch', 'clinicRoom']);

        $patientIhs = $visit->patient_id
            ? $this->identifiers->findActive($env, SatusehatEntityIdentifier::ENTITY_PATIENT, 'patient', (int) $visit->patient_id)
            : null;
        $practitionerIhs = $visit->doctor_id
            ? $this->identifiers->findActive($env, SatusehatEntityIdentifier::ENTITY_PRACTITIONER, 'doctor', (int) $visit->doctor_id)
            : null;

        // Patient is referenced by local id + (future) IHS id only. The patient
        // NAME is deliberately NOT placed in subject.display: FHIR Reference
        // .display is optional, SATUSEHAT resolves the Patient by IHS id, and
        // the name would be unnecessary PII egress on a future submission.
        return [
            'environment' => $env,
            'patient' => [
                'local_id' => $visit->patient_id,
                'ihs' => $patientIhs?->remote_identifier,
                'display' => null,
            ],
            'encounter' => [
                'local_id' => $visit->id,
                'ihs' => null,
                'display' => $visit->visit_number ?? ('Visit '.$visit->id),
            ],
            'practitioner' => [
                'local_id' => $visit->doctor_id,
                'ihs' => $practitionerIhs?->remote_identifier,
                'display' => null,
            ],
            'period' => $this->period->resolve($visit),
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $resources
     * @return array<string, int>
     */
    private function mappingVersions(array $resources): array
    {
        // Deterministic marker of which mapping confidences fed the build (used
        // in the dental source hash so a mapping version change is drift).
        $versions = [];
        foreach ($resources as $r) {
            if (($r['supported'] ?? false) && ($r['mapping_confidence'] ?? null) !== null) {
                $key = $r['variable'].'|'.($r['tooth_number'] ?? '');
                $versions[$key] = $versions[$key] ?? 0;
                $versions[$key]++;
            }
        }
        ksort($versions);

        return $versions;
    }
}
