<?php

use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\Patient\Models\Patient;
use App\Modules\Satusehat\Models\SatusehatCodeMapping;
use App\Modules\Satusehat\Models\SatusehatEntityIdentifier;
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
