<?php

/**
 * SATUSEHAT-3 — Dental Use-Case Expansion canonical registry.
 *
 * This file is the SINGLE SOURCE OF TRUTH for:
 *   1. The dental coverage matrix (which local dental variable maps to which
 *      official FHIR resource/path/terminology, and its implementation status).
 *   2. The official profile snapshot metadata (citation + version + verified
 *      date) — metadata only; it does NOT copy the copyrighted documentation.
 *   3. The DRAFT seed of official terminology codes read verbatim from the
 *      official SATUSEHAT "Rawat Jalan Gigi" playbook v1.5 (7 Aug 2024) +
 *      terminology annex. Seeded mappings are DRAFT — nothing is auto-activated;
 *      a human must verify + activate each one (SATUSEHAT-1 versioned-mapping
 *      rule), and the dental readiness engine reports `dental_mapping_blocked`
 *      until then. No clinical code is ever guessed at runtime.
 *
 * Governance: all runtime dental resources are LOCAL PREVIEW ONLY. Nothing here
 * enables an external call. Production activation is separately gated (see
 * config/satusehat.php production_* keys + SatusehatProductionActivationGuard).
 *
 * Official source (audited 2026-07-16):
 *   https://satusehat.kemkes.go.id/platform/docs/id/interoperability/rawat-jalan-gigi/
 *   https://satusehat.kemkes.go.id/platform/docs/id/terminology/lampiran-terminologi/rawat-jalan-gigi/
 */

return [

    'profile_family' => 'dental',

    // -----------------------------------------------------------------------
    // Official profile snapshot (metadata only).
    // -----------------------------------------------------------------------
    'official_profile' => [
        'name' => 'Rawat Jalan Gigi (SATUSEHAT)',
        'source_url' => 'https://satusehat.kemkes.go.id/platform/docs/id/interoperability/rawat-jalan-gigi/',
        'annex_url' => 'https://satusehat.kemkes.go.id/platform/docs/id/terminology/lampiran-terminologi/rawat-jalan-gigi/',
        'source_version' => 'Playbook Gigi v1.5',
        'source_dated' => '2024-08-07',
        'audited_at' => '2026-07-16',
        'legal_basis' => 'Permenkes 89/2015; Permenkes 30/2022 (OHIS)',
    ],

    // Confidence labels used on mappings (mst_satusehat_code_mappings.mapping_confidence).
    'confidence' => [
        'verified_official' => 'verified_official',
        'needs_verification' => 'snippet_needs_verification',
        'unverified' => 'unverified',
    ],

    // Terminology system URIs (verified from the official annex).
    'systems' => [
        'snomed' => 'http://snomed.info/sct',
        'loinc' => 'http://loinc.org',
        'kemkes_clinical_term' => 'http://terminology.kemkes.go.id/CodeSystem/clinical-term',
        'observation_category' => 'http://terminology.hl7.org/CodeSystem/observation-category',
    ],

    // -----------------------------------------------------------------------
    // Coverage matrix — the canonical decision table. `status` is the honest
    // implementation posture for THIS sprint (SATUSEHAT-3). Builder/validator
    // exist for `supported*` rows; `official_mapping_unverified` rows are
    // surfaced in the audit but never emitted as a fabricated payload.
    //
    // status ∈ supported | supported_with_mapping | incomplete_local_data
    //          | unsupported_local_schema | official_mapping_unverified
    //          | out_of_scope | blocked
    // -----------------------------------------------------------------------
    'coverage' => [
        [
            'variable' => 'odontogram_examination',
            'label' => 'Pemeriksaan Odontogram (parent)',
            'resource' => 'Observation',
            'path' => 'Observation.code / valueBoolean',
            'system' => 'kemkes_clinical_term',
            'official_code' => 'OC000061',
            'local_source' => 'trx_odontograms',
            'local_field' => 'tooth_map_payload (presence)',
            'status' => 'supported',
        ],
        [
            'variable' => 'tooth_condition',
            'label' => 'Keadaan Gigi per gigi (bodySite + component)',
            'resource' => 'Observation',
            'path' => 'Observation.bodySite (FDI/SNOMED) + component[Tooth finding 278544002]',
            'system' => 'snomed',
            'official_code' => '278544002',
            'local_source' => 'trx_odontograms',
            'local_field' => 'tooth_map_payload.teeth[*].status',
            'status' => 'supported_with_mapping',
        ],
        [
            'variable' => 'dmf_decayed',
            'label' => 'Jumlah Gigi Decayed (D)',
            'resource' => 'Observation',
            'path' => 'Observation.code / valueString',
            'system' => 'snomed',
            'official_code' => '251319000',
            'local_source' => 'trx_odontograms',
            'local_field' => 'dmftCounts().D',
            'status' => 'supported',
        ],
        [
            'variable' => 'dmf_missing',
            'label' => 'Jumlah Gigi Missing (M)',
            'resource' => 'Observation',
            'path' => 'Observation.code / valueString',
            'system' => 'snomed',
            'official_code' => '251317003',
            'local_source' => 'trx_odontograms',
            'local_field' => 'dmftCounts().M',
            'status' => 'supported',
        ],
        [
            'variable' => 'dmf_filled',
            'label' => 'Jumlah Gigi Filled (F)',
            'resource' => 'Observation',
            'path' => 'Observation.code / valueString',
            'system' => 'snomed',
            'official_code' => '251318008',
            'local_source' => 'trx_odontograms',
            'local_field' => 'dmftCounts().F',
            'status' => 'supported',
        ],
        [
            'variable' => 'occlusion',
            'label' => 'Oklusi',
            'resource' => 'Observation',
            'path' => 'Observation.code (SNOMED 25272006) / valueCodeableConcept',
            'system' => 'snomed',
            'official_code' => '25272006',
            'local_source' => 'trx_odontograms',
            'local_field' => 'additional_conditions / tooth_map_payload.occlusion',
            'status' => 'official_mapping_unverified',
        ],
        [
            'variable' => 'torus_palatinus',
            'label' => 'Torus Palatinus',
            'resource' => 'Observation',
            'path' => 'Observation.code (SNOMED 46752004) / valueCodeableConcept',
            'system' => 'snomed',
            'official_code' => '46752004',
            'local_source' => 'trx_odontograms',
            'local_field' => 'additional_conditions (free text)',
            'status' => 'incomplete_local_data',
        ],
        [
            'variable' => 'torus_mandibularis',
            'label' => 'Torus Mandibularis',
            'resource' => 'Observation',
            'path' => 'Observation.code (SNOMED 11625007) / valueCodeableConcept',
            'system' => 'snomed',
            'official_code' => '11625007',
            'local_source' => 'trx_odontograms',
            'local_field' => 'additional_conditions (free text)',
            'status' => 'incomplete_local_data',
        ],
        [
            'variable' => 'palatum',
            'label' => 'Palatum',
            'resource' => 'Observation',
            'path' => 'Observation.code (LOINC 32460-8) / valueCodeableConcept',
            'system' => 'loinc',
            'official_code' => '32460-8',
            'local_source' => 'trx_odontograms',
            'local_field' => 'additional_conditions (free text)',
            'status' => 'incomplete_local_data',
        ],
        [
            'variable' => 'diastema',
            'label' => 'Diastema',
            'resource' => 'Observation',
            'path' => 'Observation.code (SNOMED 734009000) / valueCodeableConcept',
            'system' => 'snomed',
            'official_code' => '734009000',
            'local_source' => 'trx_odontograms',
            'local_field' => 'additional_conditions (free text)',
            'status' => 'incomplete_local_data',
        ],
        [
            'variable' => 'dental_anomaly',
            'label' => 'Gigi Anomali',
            'resource' => 'Observation',
            'path' => 'Observation.code (SNOMED 81256000) / valueCodeableConcept',
            'system' => 'snomed',
            'official_code' => '81256000',
            'local_source' => 'trx_odontograms',
            'local_field' => 'additional_conditions (free text)',
            'status' => 'incomplete_local_data',
        ],
        [
            'variable' => 'other_oral_condition',
            'label' => 'Kondisi Gigi dan Mulut Lainnya',
            'resource' => 'Observation',
            'path' => 'Observation.code (Kemkes OC000060) / valueString',
            'system' => 'kemkes_clinical_term',
            'official_code' => 'OC000060',
            'local_source' => 'trx_odontograms',
            'local_field' => 'summary_notes / additional_conditions',
            'status' => 'supported',
        ],
        [
            'variable' => 'dental_diagnosis',
            'label' => 'Diagnosis Gigi (Condition)',
            'resource' => 'Condition',
            'path' => 'Condition.code (ICD-10 — delegated to Resume Medis module)',
            'system' => 'unverified',
            'official_code' => null,
            'local_source' => 'trx_medical_records',
            'local_field' => '(no structured diagnosis — handwriting RM primary)',
            'status' => 'unsupported_local_schema',
        ],
        [
            'variable' => 'dental_procedure',
            'label' => 'Tindakan Gigi (Procedure)',
            'resource' => 'Procedure',
            'path' => 'Procedure.code (per active treatment mapping)',
            'system' => 'snomed',
            'official_code' => null,
            'local_source' => 'trx_rme_invoice_items',
            'local_field' => 'treatment_id → active Procedure mapping',
            'status' => 'supported_with_mapping',
        ],
        [
            'variable' => 'encounter',
            'label' => 'Kunjungan (Encounter)',
            'resource' => 'Encounter',
            'path' => 'Encounter (reused from SATUSEHAT-1)',
            'system' => null,
            'official_code' => null,
            'local_source' => 'trx_clinic_visits',
            'local_field' => 'visit + IHS identifiers',
            'status' => 'supported',
        ],
        [
            'variable' => 'clinical_impression',
            'label' => 'Prognosis (ClinicalImpression)',
            'resource' => 'ClinicalImpression',
            'path' => 'ClinicalImpression (preview/readiness only)',
            'system' => null,
            'official_code' => null,
            'local_source' => 'trx_medical_records',
            'local_field' => '(no structured prognosis field)',
            'status' => 'unsupported_local_schema',
        ],
        [
            'variable' => 'service_request',
            'label' => 'Penunjang (ServiceRequest)',
            'resource' => 'ServiceRequest',
            'path' => 'ServiceRequest (preview/readiness only)',
            'system' => null,
            'official_code' => null,
            'local_source' => 'trx_lab_orders (future)',
            'local_field' => '(no structured follow-up request in RME scope)',
            'status' => 'out_of_scope',
        ],
    ],

    // -----------------------------------------------------------------------
    // Local tooth-condition status → official candidate code (Lampiran 5/7).
    // Consumed by the seeder as DRAFT mappings (local_entity_type =
    // 'odontogram_tooth_condition', local_code = local status). Nothing is
    // auto-activated. `confidence=verified_official` means the code was read
    // verbatim from the annex; `needs_verification` flags a documented source
    // ambiguity that a human must confirm before activation.
    // -----------------------------------------------------------------------
    'tooth_condition_map' => [
        // Verbatim from the official annex (Lampiran 5 Keadaan Gigi / Lampiran 7
        // Restorasi). local status → official SNOMED coding.
        'normal' => ['system' => 'snomed', 'code' => '162005007', 'display' => 'No tooth problem', 'confidence' => 'verified_official'],
        'caries' => ['system' => 'snomed', 'code' => '80967001', 'display' => 'Dental caries', 'confidence' => 'verified_official'],
        'missing' => ['system' => 'snomed', 'code' => '234948008', 'display' => 'Tooth absent', 'confidence' => 'verified_official'],
        'filling' => ['system' => 'snomed', 'code' => '278550007', 'display' => 'Dental filling present', 'confidence' => 'verified_official'],
        'crown' => ['system' => 'snomed', 'code' => '272289008', 'display' => 'Crown', 'confidence' => 'verified_official'],
        // Local "root_treated" (PSA) → official "rct" Root canal post. The
        // official display is a device/material concept used for treatment
        // status; flagged for human sign-off before activation.
        'root_treated' => ['system' => 'snomed', 'code' => '706370006', 'display' => 'Root canal post', 'confidence' => 'needs_verification'],
    ],

    // FDI (ISO-3950) tooth number → SNOMED CT bodySite. Verbatim from the
    // official annex Lampiran 1 (52 rows, permanent + deciduous). system =
    // http://snomed.info/sct for every entry. Consumed by the bodySite builder
    // and seeded as DRAFT bodySite mappings.
    'fdi_bodysite_map' => [
        '11' => '422653006', '12' => '424877001', '13' => '860767006', '14' => '57826002',
        '15' => '36492000', '16' => '865995000', '17' => '863902006', '18' => '68085002',
        '21' => '424399000', '22' => '423185002', '23' => '860780009', '24' => '61897005',
        '25' => '23226009', '26' => '865988009', '27' => '863901004', '28' => '87704003',
        '31' => '425106001', '32' => '423331005', '33' => '860782001', '34' => '2400006',
        '35' => '24573005', '36' => '866006002', '37' => '863898000', '38' => '74344005',
        '41' => '424575004', '42' => '423937004', '43' => '860785004', '44' => '80140008',
        '45' => '8873007', '46' => '866005003', '47' => '863899008', '48' => '38994002',
        '51' => '88824007', '52' => '65624003', '53' => '30618001', '54' => '17505006',
        '55' => '27855007', '61' => '51678005', '62' => '43622005', '63' => '73937000',
        '64' => '45234009', '65' => '51943008', '71' => '89552004', '72' => '14770005',
        '73' => '43281008', '74' => '38896004', '75' => '49330006', '81' => '67834006',
        '82' => '22445006', '83' => '6062009', '84' => '58646007', '85' => '61868007',
    ],

    // DMF count observations (verified valueString per the official table).
    'dmf_map' => [
        'D' => ['system' => 'snomed', 'code' => '251319000', 'display' => 'Decayed tooth', 'value_type' => 'valueString'],
        'M' => ['system' => 'snomed', 'code' => '251317003', 'display' => 'Missing teeth', 'value_type' => 'valueString'],
        'F' => ['system' => 'snomed', 'code' => '251318008', 'display' => 'Filled tooth', 'value_type' => 'valueString'],
    ],

    // -----------------------------------------------------------------------
    // Production-readiness framework categories. Read-only readiness reporting;
    // NONE of these activate anything. Expected statuses for SATUSEHAT-3 are the
    // honest posture (external items blocked; internal dental impl ready).
    //
    // status ∈ not_started | blocked_external | in_progress | ready_internal
    //          | verified_external
    // -----------------------------------------------------------------------
    'production_readiness' => [
        ['key' => 'organization_onboarding', 'label' => 'Organization onboarding', 'kind' => 'external', 'expected' => 'blocked_external'],
        ['key' => 'location_onboarding', 'label' => 'Location onboarding', 'kind' => 'external', 'expected' => 'blocked_external'],
        ['key' => 'practitioner_identity', 'label' => 'Practitioner identity readiness', 'kind' => 'external', 'expected' => 'blocked_external'],
        ['key' => 'patient_identity', 'label' => 'Patient identity readiness', 'kind' => 'external', 'expected' => 'blocked_external'],
        ['key' => 'credential_readiness', 'label' => 'Credential readiness', 'kind' => 'external', 'expected' => 'blocked_external'],
        ['key' => 'terminology_readiness', 'label' => 'Terminology readiness (dental mappings verified+active)', 'kind' => 'internal', 'expected' => 'in_progress'],
        ['key' => 'profile_conformance', 'label' => 'Profile conformance (local validator)', 'kind' => 'internal', 'expected' => 'ready_internal'],
        ['key' => 'queue_readiness', 'label' => 'Queue readiness', 'kind' => 'internal', 'expected' => 'ready_internal'],
        ['key' => 'observability', 'label' => 'Observability (dental metrics)', 'kind' => 'internal', 'expected' => 'ready_internal'],
        ['key' => 'incident_response', 'label' => 'Incident response runbook', 'kind' => 'internal', 'expected' => 'ready_internal'],
        ['key' => 'security_privacy', 'label' => 'Security / privacy (PII masking)', 'kind' => 'internal', 'expected' => 'ready_internal'],
        ['key' => 'data_governance', 'label' => 'Data governance (audit + versioned mappings)', 'kind' => 'internal', 'expected' => 'ready_internal'],
        ['key' => 'rollback', 'label' => 'Rollback (fail-closed + kill switch)', 'kind' => 'internal', 'expected' => 'ready_internal'],
        ['key' => 'support_ownership', 'label' => 'Support ownership', 'kind' => 'internal', 'expected' => 'ready_internal'],
        ['key' => 'production_host_lock', 'label' => 'Production host lock (sandbox-only guard)', 'kind' => 'internal', 'expected' => 'ready_internal'],
        ['key' => 'sandbox_round_trip', 'label' => 'Verified sandbox round-trip (SATUSEHAT-2 GO)', 'kind' => 'external', 'expected' => 'blocked_external'],
        ['key' => 'production_activation', 'label' => 'Production activation', 'kind' => 'external', 'expected' => 'not_started'],
    ],
];
