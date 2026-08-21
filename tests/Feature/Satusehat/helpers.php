<?php

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\MedicalRecord\Models\ClinicalDiagnosis;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\MedicalRecord\Models\MedicalRecordDiagnosis;
use App\Modules\Odontogram\Models\Odontogram;
use App\Modules\Patient\Models\Patient;
use App\Modules\Satusehat\Models\SatusehatCandidate;
use App\Modules\Satusehat\Models\SatusehatCodeMapping;
use App\Modules\Satusehat\Models\SatusehatEntityIdentifier;
use App\Modules\Satusehat\Services\DataQuality\SatusehatDataQualityIssueService;
use App\Modules\Satusehat\Services\SatusehatCandidateService;

if (! function_exists('ssMakeVisit')) {
    /**
     * @return array{branch: Branch, doctor: Doctor, patient: Patient, visit: ClinicVisit, mr: MedicalRecord}
     */
    function ssMakeVisit(array $overrides = []): array
    {
        $branch = Branch::factory()->create(['is_active' => true, 'is_rme_enabled' => true]);
        $doctor = Doctor::factory()->create();
        $patient = Patient::factory()->create([
            'branch_id' => $branch->id,
            'date_of_birth' => '1990-01-01',
            'gender' => 'male',
            'ktp_number' => fake()->unique()->numerify('327301##########'),
        ]);
        $visit = ClinicVisit::factory()->create(array_merge([
            'branch_id' => $branch->id,
            'patient_id' => $patient->id,
            'doctor_id' => $doctor->id,
            'status' => ClinicVisit::STATUS_COMPLETED,
            'completed_at' => now(),
        ], $overrides));
        $mr = MedicalRecord::factory()->create([
            'clinic_visit_id' => $visit->id,
            'branch_id' => $branch->id,
            'patient_id' => $patient->id,
            'doctor_id' => $doctor->id,
            'status' => MedicalRecord::STATUS_FINAL,
            'finalized_at' => now(),
        ]);

        return compact('branch', 'doctor', 'patient', 'visit', 'mr');
    }
}

if (! function_exists('ssOpenEncounter')) {
    /**
     * FIX-RME-EXAM-CONSENT-ODONTOGRAM-HISTORY-3 / CORRECTIVE-02 + CORRECTIVE-03 —
     * reopen a SATUSEHAT fixture's visit as a live, consented encounter so
     * clinical content can be written into it.
     *
     * `ssMakeVisit()` produces a COMPLETED visit, which is right for SATUSEHAT:
     * a candidate is created from a finished encounter. But clinical writes —
     * including a structured diagnosis — are only legal DURING the examination,
     * with a signed consent. In production the diagnosis is recorded while the
     * patient is in the chair and the visit finishes afterwards; the fixture has
     * to model that order rather than write onto an already-closed visit.
     *
     * Pair with ssCloseEncounter() when the test's later assertions depend on the
     * visit being finished again.
     */
    function ssOpenEncounter(array $ctx, ?User $actor = null): void
    {
        $visit = $ctx['visit'];

        $visit->forceFill([
            'status' => ClinicVisit::STATUS_IN_PROGRESS,
            'completed_at' => null,
        ])->save();

        rmeSignedConsentFor($visit->refresh());

        if ($actor !== null) {
            test()->actingAs($actor);
        }
    }
}

if (! function_exists('ssCloseEncounter')) {
    function ssCloseEncounter(array $ctx): void
    {
        $ctx['visit']->forceFill([
            'status' => ClinicVisit::STATUS_COMPLETED,
            'completed_at' => now(),
        ])->save();

        $ctx['visit']->refresh();
    }
}

if (! function_exists('ssAddIdentifiers')) {
    function ssAddIdentifiers(array $ctx): void
    {
        foreach ([
            [SatusehatEntityIdentifier::ENTITY_PATIENT, 'patient', $ctx['patient']->id],
            [SatusehatEntityIdentifier::ENTITY_PRACTITIONER, 'doctor', $ctx['doctor']->id],
            [SatusehatEntityIdentifier::ENTITY_ORGANIZATION, 'branch', $ctx['branch']->id],
            [SatusehatEntityIdentifier::ENTITY_LOCATION, 'clinic_room', $ctx['visit']->clinic_room_id],
        ] as [$type, $localType, $localId]) {
            SatusehatEntityIdentifier::create([
                'environment' => 'sandbox',
                'entity_type' => $type,
                'local_entity_type' => $localType,
                'local_entity_id' => $localId,
                'remote_identifier' => 'ihs-'.$localType.'-'.$localId,
                'status' => SatusehatEntityIdentifier::STATUS_ACTIVE,
            ]);
        }
    }
}

if (! function_exists('ssService')) {
    function ssService(): SatusehatCandidateService
    {
        return app(SatusehatCandidateService::class);
    }
}

if (! function_exists('ssConfigureSandbox')) {
    /**
     * Configure a fully-enabled sandbox runtime with SAFE fake credentials + the
     * real sandbox host allowlist. No real network call is ever made — tests use
     * Http::fake or the FakeSatusehatGateway.
     */
    function ssConfigureSandbox(bool $sendEnabled = true): void
    {
        config()->set('satusehat.enabled', true);
        config()->set('satusehat.send_enabled', $sendEnabled);
        config()->set('satusehat.environment', 'sandbox');
        config()->set('satusehat.sandbox_only', true);
        config()->set('satusehat.oauth_base_url', 'https://api-satusehat-stg.dto.kemkes.go.id/oauth2/v1');
        config()->set('satusehat.fhir_base_url', 'https://api-satusehat-stg.dto.kemkes.go.id/fhir-r4/v1');
        config()->set('satusehat.client_id', 'test-client-id');
        config()->set('satusehat.client_secret', 'test-client-secret');
        config()->set('satusehat.organization_id', 'ORG-TEST');
        config()->set('satusehat.location_id', 'LOC-TEST');
    }
}

if (! function_exists('ssTreatmentMapping')) {
    function ssTreatmentMapping(int $treatmentId, string $env = 'sandbox'): SatusehatCodeMapping
    {
        return SatusehatCodeMapping::create([
            'environment' => $env,
            'local_entity_type' => 'treatment',
            'local_entity_id' => $treatmentId,
            'target_resource_type' => 'Procedure',
            'terminology_system' => 'http://snomed.info/sct',
            'target_code' => '2340003',
            'target_display' => 'Test procedure',
            'status' => SatusehatCodeMapping::STATUS_ACTIVE,
            'version' => 1,
            'effective_date' => now()->toDateString(),
        ]);
    }
}

if (! function_exists('ssOdontogram')) {
    /**
     * SATUSEHAT-3 golden odontogram fixture. `$teeth` = ['48' => 'caries', ...].
     * Deterministic, synthetic, no real patient/NIK.
     *
     * @param  array<string, string>  $teeth
     */
    function ssOdontogram(array $ctx, array $teeth, ?string $summary = null): Odontogram
    {
        $payload = ['teeth' => []];
        foreach ($teeth as $number => $status) {
            $payload['teeth'][(string) $number] = ['status' => $status, 'note' => '', 'conditions' => []];
        }

        return Odontogram::create([
            'clinic_visit_id' => $ctx['visit']->id,
            'branch_id' => $ctx['branch']->id,
            'medical_record_id' => $ctx['mr']->id,
            'status' => Odontogram::STATUS_FINALIZED,
            'summary_notes' => $summary,
            'tooth_map_payload' => $payload,
        ]);
    }
}

if (! function_exists('ssDiagnosis')) {
    /**
     * SATUSEHAT-4A — attach a structured diagnosis (with optional ACTIVE
     * Condition mapping) to a visit's medical record. Synthetic fixture only.
     */
    function ssDiagnosis(array $ctx, string $code = 'K02.9', string $role = 'primary', bool $mapped = false): MedicalRecordDiagnosis
    {
        $master = ClinicalDiagnosis::query()->firstOrCreate(
            ['code_system' => 'ICD-10', 'code' => $code],
            ['display' => 'Fixture diagnosis '.$code, 'status' => ClinicalDiagnosis::STATUS_ACTIVE],
        );

        if ($mapped) {
            SatusehatCodeMapping::query()->firstOrCreate(
                [
                    'environment' => 'sandbox',
                    'local_entity_type' => 'diagnosis',
                    'local_entity_id' => $master->id,
                    'local_code' => $code,
                    'target_resource_type' => 'Condition',
                    'status' => SatusehatCodeMapping::STATUS_ACTIVE,
                ],
                [
                    'terminology_system' => 'http://hl7.org/fhir/sid/icd-10',
                    'target_code' => $code,
                    'target_display' => 'Fixture condition '.$code,
                    'version' => 1,
                    'effective_date' => now()->toDateString(),
                ],
            );
        }

        return MedicalRecordDiagnosis::create([
            'medical_record_id' => $ctx['mr']->id,
            'clinic_visit_id' => $ctx['visit']->id,
            'branch_id' => $ctx['branch']->id,
            'clinical_diagnosis_id' => $master->id,
            'diagnosis_role' => $role,
            'diagnosed_at' => now(),
        ]);
    }
}

if (! function_exists('ssSyncIssues')) {
    /**
     * SATUSEHAT-4A — generate candidate (if needed) + run the rule engine.
     *
     * @return array{candidate: SatusehatCandidate, summary: array<string, int>}
     */
    function ssSyncIssues(array $ctx, ?User $actor = null): array
    {
        $candidate = ssService()->generateForVisit($ctx['visit']->fresh(['medicalRecord']))
            ?? SatusehatCandidate::query()->where('clinic_visit_id', $ctx['visit']->id)->firstOrFail();

        $summary = app(SatusehatDataQualityIssueService::class)
            ->syncForCandidate($candidate, $actor);

        return ['candidate' => $candidate->fresh(), 'summary' => $summary];
    }
}

if (! function_exists('ssActivateDentalMappings')) {
    /**
     * Seed + activate the dental tooth-condition + bodySite mappings needed to
     * make a golden odontogram dental_ready. Uses the official codes from config.
     *
     * @param  array<string, string>  $teeth  ['48' => 'caries', ...]
     */
    function ssActivateDentalMappings(array $teeth, string $env = 'sandbox'): void
    {
        $cfg = config('satusehat_dental');
        $statuses = array_unique(array_values($teeth));
        $numbers = array_unique(array_keys($teeth));

        foreach ($statuses as $status) {
            $def = $cfg['tooth_condition_map'][$status] ?? null;
            if ($def === null) {
                continue;
            }
            SatusehatCodeMapping::create([
                'environment' => $env,
                'local_entity_type' => 'odontogram_tooth_condition',
                'local_code' => (string) $status,
                'target_resource_type' => 'Observation',
                'terminology_system' => $cfg['systems'][$def['system']],
                'target_code' => $def['code'],
                'target_display' => $def['display'],
                'profile_family' => 'dental',
                'official_source' => $cfg['official_profile']['annex_url'],
                'official_source_version' => 'v1.5',
                'verified_at' => now(),
                'mapping_confidence' => 'verified_official',
                'status' => SatusehatCodeMapping::STATUS_ACTIVE,
                'version' => 1,
                'effective_date' => now()->toDateString(),
            ]);
        }

        foreach ($numbers as $number) {
            $code = $cfg['fdi_bodysite_map'][(string) $number] ?? null;
            if ($code === null) {
                continue;
            }
            SatusehatCodeMapping::create([
                'environment' => $env,
                'local_entity_type' => 'odontogram_tooth_bodysite',
                'local_code' => (string) $number,
                'target_resource_type' => 'Observation',
                'terminology_system' => $cfg['systems']['snomed'],
                'target_code' => $code,
                'target_display' => 'FDI '.$number,
                'profile_family' => 'dental',
                'official_source' => $cfg['official_profile']['annex_url'],
                'official_source_version' => 'v1.5',
                'verified_at' => now(),
                'mapping_confidence' => 'verified_official',
                'status' => SatusehatCodeMapping::STATUS_ACTIVE,
                'version' => 1,
                'effective_date' => now()->toDateString(),
            ]);
        }
    }
}
