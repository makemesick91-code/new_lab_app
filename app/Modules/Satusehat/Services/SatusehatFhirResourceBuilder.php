<?php

namespace App\Modules\Satusehat\Services;

use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Satusehat\Exceptions\SatusehatBuildException;
use App\Modules\Satusehat\Interfaces\SatusehatMappingRepositoryInterface;
use App\Modules\Satusehat\Models\SatusehatCodeMapping;
use App\Modules\Satusehat\Models\SatusehatSubmissionItem;
use App\Modules\Satusehat\Support\SatusehatFhirValidator;
use App\Modules\Satusehat\Support\SatusehatPeriodResolver;

/**
 * Builds the REAL FHIR resources actually submitted to the SATUSEHAT sandbox
 * (SATUSEHAT-2), using the resolved remote IHS identifiers and the versioned
 * ACTIVE code mappings. It NEVER invents a terminology code: Procedure/Condition
 * codes come only from an active {@see SatusehatCodeMapping}.
 * A resource whose mandatory data is missing throws {@see SatusehatBuildException}
 * and stays BLOCKED — it is never sent with fabricated data.
 *
 * Out-of-scope content (odontogram, handwriting, scans, attachments, raw NIK) is
 * never included and is asserted absent by {@see SatusehatFhirValidator}.
 */
class SatusehatFhirResourceBuilder
{
    public function __construct(
        private readonly SatusehatMappingRepositoryInterface $mappings,
        private readonly SatusehatPeriodResolver $period,
        private readonly SatusehatFhirValidator $validator,
    ) {}

    /**
     * @param  array<string, mixed>  $facts
     * @return array<string, mixed>
     */
    public function buildEncounter(ClinicVisit $visit, array $facts): array
    {
        $ids = $facts['identifiers'] ?? [];
        $patientIhs = $ids['patient'] ?? null;
        $organizationIhs = $ids['organization'] ?? null;
        $practitionerIhs = $ids['practitioner'] ?? null;
        $locationIhs = $ids['location'] ?? null;

        $period = $this->period->resolve($visit);

        if (blank($patientIhs)) {
            throw new SatusehatBuildException('Encounter membutuhkan IHS Patient.');
        }
        if (blank($organizationIhs)) {
            throw new SatusehatBuildException('Encounter membutuhkan IHS Organization.');
        }
        if (blank($period['start'])) {
            throw new SatusehatBuildException('Encounter membutuhkan periode kunjungan yang dapat dinormalisasi ke UTC.');
        }

        $encounter = [
            'resourceType' => 'Encounter',
            'status' => 'finished',
            'class' => [
                'system' => 'http://terminology.hl7.org/CodeSystem/v3-ActCode',
                'code' => 'AMB',
                'display' => 'ambulatory',
            ],
            'subject' => $this->reference('Patient', (string) $patientIhs, $facts['patient']['name'] ?? null),
            'serviceProvider' => $this->reference('Organization', (string) $organizationIhs),
            'period' => array_filter([
                'start' => $period['start'],
                'end' => $period['end'],
            ], fn ($v) => $v !== null),
        ];

        if (filled($practitionerIhs)) {
            $encounter['participant'] = [[
                'individual' => $this->reference('Practitioner', (string) $practitionerIhs),
            ]];
        }

        if (filled($locationIhs)) {
            $encounter['location'] = [[
                'location' => $this->reference('Location', (string) $locationIhs),
            ]];
        }

        $this->assertValid($encounter);

        return $encounter;
    }

    /**
     * Finalization/update of an already-created Encounter (idempotent PUT by
     * remote id). Attaches diagnosis references when Conditions exist. Never
     * creates a second Encounter.
     *
     * @param  array<string, mixed>  $facts
     * @param  list<string>  $conditionRemoteIds
     * @return array<string, mixed>
     */
    public function buildEncounterFinalization(ClinicVisit $visit, array $facts, string $encounterRemoteId, array $conditionRemoteIds = []): array
    {
        $encounter = $this->buildEncounter($visit, $facts);
        $encounter['id'] = $encounterRemoteId;

        if ($conditionRemoteIds !== []) {
            $encounter['diagnosis'] = array_map(
                fn (string $cid) => ['condition' => $this->reference('Condition', $cid)],
                $conditionRemoteIds,
            );
        }

        $this->assertValid($encounter);

        return $encounter;
    }

    /**
     * Build Condition resources from a STRUCTURED diagnosis source. The current
     * data model stores no structured diagnosis (handwriting RM is primary), so
     * `facts['diagnoses']` is absent and this returns []. It NEVER fabricates a
     * diagnosis. When a structured source is added later, each diagnosis maps
     * through an active mapping (system+code from the mapping only).
     *
     * @param  array<string, mixed>  $facts
     * @return list<array<string, mixed>>
     */
    public function buildConditions(array $facts, string $encounterRemoteId): array
    {
        $ids = $facts['identifiers'] ?? [];
        $patientIhs = $ids['patient'] ?? null;
        $diagnoses = $facts['diagnoses'] ?? [];

        if (blank($patientIhs) || ! is_array($diagnoses) || $diagnoses === []) {
            return [];
        }

        $env = (string) ($facts['environment'] ?? config('satusehat.environment'));
        $conditions = [];

        foreach ($diagnoses as $diagnosis) {
            $diagnosisId = $diagnosis['diagnosis_id'] ?? null;
            $mapping = $diagnosisId !== null
                ? $this->mappings->findActive($env, 'diagnosis', (int) $diagnosisId, $diagnosis['code'] ?? null, SatusehatSubmissionItem::RESOURCE_CONDITION)
                : null;

            if ($mapping === null) {
                // No active mapping → do not fabricate; skip (stays blocked upstream).
                continue;
            }

            $condition = [
                'resourceType' => 'Condition',
                'clinicalStatus' => [
                    'coding' => [[
                        'system' => 'http://terminology.hl7.org/CodeSystem/condition-clinical',
                        'code' => 'active',
                    ]],
                ],
                'category' => [[
                    'coding' => [[
                        'system' => 'http://terminology.hl7.org/CodeSystem/condition-category',
                        'code' => 'encounter-diagnosis',
                    ]],
                ]],
                'code' => [
                    'coding' => [array_filter([
                        'system' => $mapping->terminology_system,
                        'code' => $mapping->target_code,
                        'display' => $mapping->target_display,
                    ], fn ($v) => $v !== null && $v !== '')],
                ],
                'subject' => $this->reference('Patient', (string) $patientIhs),
                'encounter' => $this->reference('Encounter', $encounterRemoteId),
            ];

            $this->assertValid($condition);
            $conditions[] = $condition;
        }

        return $conditions;
    }

    /**
     * Build a Procedure for a single treatment row. Terminology (system + code +
     * display) comes ONLY from the active Procedure mapping — never invented.
     * Throws when the treatment has no active mapping or the patient IHS is
     * missing (stays blocked).
     *
     * @param  array<string, mixed>  $treatment
     * @param  array<string, mixed>  $facts
     * @return array<string, mixed>
     */
    public function buildProcedure(ClinicVisit $visit, array $treatment, array $facts, string $encounterRemoteId): array
    {
        $ids = $facts['identifiers'] ?? [];
        $patientIhs = $ids['patient'] ?? null;
        if (blank($patientIhs)) {
            throw new SatusehatBuildException('Procedure membutuhkan IHS Patient.');
        }

        $treatmentId = $treatment['treatment_id'] ?? null;
        if ($treatmentId === null) {
            throw new SatusehatBuildException('Procedure membutuhkan treatment yang valid.');
        }

        $env = (string) ($facts['environment'] ?? config('satusehat.environment'));
        $mapping = $this->mappings->findActive($env, 'treatment', (int) $treatmentId, null, SatusehatSubmissionItem::RESOURCE_PROCEDURE);

        if ($mapping === null || blank($mapping->terminology_system) || blank($mapping->target_code)) {
            throw new SatusehatBuildException('Tindakan belum memiliki mapping Procedure aktif — tidak dikirim.');
        }

        $period = $this->period->resolve($visit);
        $performed = $period['end'] ?? $period['start'] ?? null;

        $procedure = array_filter([
            'resourceType' => 'Procedure',
            'status' => 'completed',
            'code' => [
                'coding' => [array_filter([
                    'system' => $mapping->terminology_system,
                    'code' => $mapping->target_code,
                    'display' => $mapping->target_display,
                ], fn ($v) => $v !== null && $v !== '')],
            ],
            'subject' => $this->reference('Patient', (string) $patientIhs),
            'encounter' => $this->reference('Encounter', $encounterRemoteId),
            'performedDateTime' => $performed,
        ], fn ($v) => $v !== null);

        $this->assertValid($procedure);

        return $procedure;
    }

    /**
     * @param  array<string, mixed>  $resource
     */
    private function assertValid(array $resource): void
    {
        $issues = $this->validator->validate($resource);
        if ($issues !== []) {
            throw new SatusehatBuildException('Resource FHIR tidak valid secara lokal: '.implode(' ', $issues));
        }
    }

    /**
     * @return array<string, string>
     */
    private function reference(string $type, string $remoteId, ?string $display = null): array
    {
        $ref = ['reference' => $type.'/'.$remoteId];
        if (filled($display)) {
            $ref['display'] = $display;
        }

        return $ref;
    }
}
