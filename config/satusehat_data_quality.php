<?php

/*
|--------------------------------------------------------------------------
| SATUSEHAT-4A — Credential-independent operational readiness & data quality
|--------------------------------------------------------------------------
| Canonical registry for the SATUSEHAT data-quality rule engine, the
| operational readiness workspace, the synthetic rehearsal campaign, and the
| production onboarding checklist.
|
| Everything here is credential-independent: no key in this file enables an
| external request. External submission remains governed by config/satusehat.php
| (SATUSEHAT_ENABLED / SATUSEHAT_SEND_ENABLED, both default OFF) and the
| SATUSEHAT-3 production activation guard.
*/

use App\Modules\Satusehat\Services\DataQuality\Rules\DentalCompletenessRule;
use App\Modules\Satusehat\Services\DataQuality\Rules\DeprecatedDiagnosisSelectedRule;
use App\Modules\Satusehat\Services\DataQuality\Rules\DiagnosisCodeInvalidRule;
use App\Modules\Satusehat\Services\DataQuality\Rules\DiagnosisMappingRule;
use App\Modules\Satusehat\Services\DataQuality\Rules\DuplicatePrimaryDiagnosisRule;
use App\Modules\Satusehat\Services\DataQuality\Rules\LocalConformanceRule;
use App\Modules\Satusehat\Services\DataQuality\Rules\LocationReadinessRule;
use App\Modules\Satusehat\Services\DataQuality\Rules\OrganizationReadinessRule;
use App\Modules\Satusehat\Services\DataQuality\Rules\PatientDemographicsRule;
use App\Modules\Satusehat\Services\DataQuality\Rules\PatientIdentityRule;
use App\Modules\Satusehat\Services\DataQuality\Rules\PractitionerReadinessRule;
use App\Modules\Satusehat\Services\DataQuality\Rules\SourceDriftRule;
use App\Modules\Satusehat\Services\DataQuality\Rules\StructuredDiagnosisRule;
use App\Modules\Satusehat\Services\DataQuality\Rules\TreatmentMappingRule;

return [

    /*
    | Registered data-quality rules, in deterministic evaluation order.
    | Every rule implements SatusehatDataQualityRuleInterface, is deterministic,
    | idempotent, side-effect-free on evaluate, and never performs HTTP.
    */
    'rules' => [
        PatientIdentityRule::class,
        PatientDemographicsRule::class,
        PractitionerReadinessRule::class,
        OrganizationReadinessRule::class,
        LocationReadinessRule::class,
        StructuredDiagnosisRule::class,
        DuplicatePrimaryDiagnosisRule::class,
        DeprecatedDiagnosisSelectedRule::class,
        DiagnosisCodeInvalidRule::class,
        DiagnosisMappingRule::class,
        TreatmentMappingRule::class,
        DentalCompletenessRule::class,
        SourceDriftRule::class,
        LocalConformanceRule::class,
    ],

    /*
    | Candidate-level operational statuses (canonical, filterable). One status
    | is resolved per candidate as the single most actionable next step; the
    | full detail lives in the readiness reasons + open issues.
    */
    'operational_statuses' => [
        'READY_INTERNAL',
        'BLOCKED_EXTERNAL_CREDENTIAL',
        'MISSING_PATIENT_IDENTITY',
        'INVALID_PATIENT_DEMOGRAPHICS',
        'MISSING_PRACTITIONER_READINESS',
        'MISSING_ORGANIZATION_MAPPING',
        'MISSING_LOCATION_MAPPING',
        'MISSING_STRUCTURED_DIAGNOSIS',
        'MISSING_DIAGNOSIS_MAPPING',
        'MISSING_PROCEDURE_MAPPING',
        'DENTAL_INCOMPLETE',
        'DENTAL_MAPPING_BLOCKED',
        'LOCAL_CONFORMANCE_FAILED',
        'SOURCE_CHANGED',
        'AWAITING_CLINICAL_REVIEW',
        'AWAITING_OPERATOR_REMEDIATION',
        'UNSUPPORTED_LOCAL_SCHEMA',
    ],

    /*
    | Issue severities. `hard` issues can never be waived and can never be
    | manually resolved while their rule still detects them. Waivers only
    | silence workspace triage — they NEVER make the canonical readiness engine
    | report a candidate as ready.
    */
    'severities' => ['hard', 'soft', 'info'],

    /*
    | Issue lifecycle states.
    */
    'issue_statuses' => [
        'open',
        'acknowledged',
        'in_remediation',
        'awaiting_clinical_review',
        'resolved',
        'reopened',
        'waived',
        'unsupported',
    ],

    /*
    | Patient demographic validation profile (local clinic readiness, NOT a
    | global NIK mandate — NIK stays optional for clinic workflow and is only
    | required for the future external Patient lookup).
    */
    'patient' => [
        'gender_canonical' => ['male', 'female', 'other'],
        'dob_min_year' => 1900,
        'max_age_years' => 130,
    ],

    /*
    | Bounded scan defaults (data-quality scan + readiness recalculation).
    */
    'scan' => [
        'default_batch_size' => 200,
        'max_batch_size' => 1000,
    ],

    /*
    | Synthetic rehearsal campaign. All synthetic records are isolated inside a
    | dedicated branch (the isolation boundary for reset) and carry an explicit
    | marker. The campaign NEVER touches real patients, NEVER fabricates a
    | remote IHS identifier, and NEVER performs an external request.
    */
    'synthetic' => [
        'branch_code' => 'SYN4A',
        'branch_name' => '[SYNTHETIC] SATUSEHAT-4A Rehearsal',
        'marker' => '[SYNTHETIC-SATUSEHAT-4A]',
        'diagnosis_code_system' => 'SYNTHETIC-SATUSEHAT-4A',
        'patient_ktp' => '9999999999994401',
    ],

    /*
    | Production onboarding checklist (internal governance view). Kind:
    | `internal` items can reach ready_internal without credentials; `external`
    | items stay blocked_external until the SATUSEHAT-2 External Credential
    | Closure Campaign provides real evidence.
    */
    'onboarding_checklist' => [
        ['key' => 'sandbox_client_id', 'label' => 'Sandbox Client ID', 'kind' => 'external'],
        ['key' => 'sandbox_client_secret', 'label' => 'Sandbox Client Secret', 'kind' => 'external'],
        ['key' => 'sandbox_organization_id', 'label' => 'Sandbox Organization ID', 'kind' => 'external'],
        ['key' => 'sandbox_location_id', 'label' => 'Sandbox Location ID', 'kind' => 'external'],
        ['key' => 'synthetic_patient_ihs', 'label' => 'Synthetic Patient IHS (Kemkes test data)', 'kind' => 'external'],
        ['key' => 'synthetic_practitioner_ihs', 'label' => 'Synthetic Practitioner IHS (Kemkes test data)', 'kind' => 'external'],
        ['key' => 'satusehat2_live_sandbox_go', 'label' => 'SATUSEHAT-2 live sandbox GO', 'kind' => 'external'],
        ['key' => 'production_credentials', 'label' => 'Production credentials', 'kind' => 'external'],
        ['key' => 'production_organization', 'label' => 'Production Organization onboarding', 'kind' => 'external'],
        ['key' => 'production_locations', 'label' => 'Production Locations onboarding', 'kind' => 'external'],
        ['key' => 'production_practitioner_mapping', 'label' => 'Production Practitioner mapping', 'kind' => 'external'],
        ['key' => 'patient_identity_workflow', 'label' => 'Patient identity remediation workflow', 'kind' => 'internal'],
        ['key' => 'structured_diagnosis_foundation', 'label' => 'Structured diagnosis foundation', 'kind' => 'internal'],
        ['key' => 'clinical_terminology_approval', 'label' => 'Clinical terminology approval workflow', 'kind' => 'internal'],
        ['key' => 'data_quality_workspace', 'label' => 'Data-quality remediation workspace', 'kind' => 'internal'],
        ['key' => 'synthetic_rehearsal', 'label' => 'Credential-independent synthetic rehearsal', 'kind' => 'internal'],
        ['key' => 'monitoring_observability', 'label' => 'Operational metrics & monitoring', 'kind' => 'internal'],
        ['key' => 'incident_runbook', 'label' => 'Incident runbook & drills', 'kind' => 'internal'],
        ['key' => 'rollback_plan', 'label' => 'Non-destructive rollback plan', 'kind' => 'internal'],
        ['key' => 'operator_training', 'label' => 'Operator training (SOP/RACI)', 'kind' => 'internal'],
        ['key' => 'management_approval', 'label' => 'Management approval for production activation', 'kind' => 'external'],
    ],
];
