<?php

/*
|--------------------------------------------------------------------------
| SATUSEHAT-4C — Branch Readiness Remediation & Internal Pilot Operations
|--------------------------------------------------------------------------
| Canonical, versioned governance for the branch-readiness operating system:
| readiness stages, the deterministic internal readiness score, pilot
| eligibility thresholds, issue SLA/priority/escalation, and pilot selection.
|
| Everything here is credential-independent — NO key enables an external
| request. External submission stays governed by config/satusehat.php
| (SATUSEHAT_ENABLED / SATUSEHAT_SEND_ENABLED, both default OFF) and the
| SATUSEHAT-3 production activation guard. "Pilot ready" here means
| INTERNAL readiness only; external readiness remains a separate blocker
| owned by the SATUSEHAT-2 External Credential Closure Campaign.
*/

return [

    /*
    | Feature flag for the branch-readiness/pilot workspace (routes + UI). The
    | runtime engines are always safe to run; this only gates the operator UI.
    */
    'enabled' => env('SATUSEHAT_PILOT_WORKSPACE_ENABLED', true),

    /*
    | Readiness stages, in progression order. There is deliberately NO
    | `externally_ready` / `production_ready` stage — external readiness is
    | never asserted without real SATUSEHAT-2 credentials + proof.
    */
    'stages' => [
        'not_started',
        'profiling',
        'remediation',
        'uat_ready',
        'pilot_ready_internal',
        'blocked_external_credential',
        'suspended',
    ],

    /*
    | Pilot status held on the branch profile.
    */
    'pilot_statuses' => [
        'none',
        'candidate',
        'approved',
        'suspended',
    ],

    /*
    | Deterministic internal readiness score. Transparent + versioned — NOT an
    | opaque AI score. Weights are normalized to 100 at calculation time; a
    | component with no measurable data contributes 0 of its own weight but the
    | denominator excludes it (null → "N/A", never a fake 0). Hard blockers cap
    | the final score at `hard_blocker_score_ceiling` regardless of components,
    | and always prevent `pilot_ready_internal`.
    */
    'score' => [
        'version' => 1,
        'weights' => [
            'patient_readiness' => 15,
            'practitioner_readiness' => 10,
            'organization_readiness' => 5,
            'location_readiness' => 5,
            'diagnosis_adoption' => 20,
            'diagnosis_mapping' => 10,
            'treatment_mapping' => 10,
            'dental_readiness' => 10,
            'local_conformance' => 10,
            'operational_rehearsal' => 5,
        ],
        'hard_blocker_score_ceiling' => 60,
    ],

    /*
    | Pilot eligibility thresholds. These are config DEFAULTS; a branch may hold
    | reasoned, audited overrides on its profile (threshold_overrides). Safe
    | defaults, branch-scoped, never a global enforcement switch. Changing a
    | threshold NEVER silently resolves an issue and NEVER makes a candidate
    | ready — the canonical rule engine is untouched.
    */
    'thresholds' => [
        'version' => 1,
        'diagnosis_adoption_min_percent' => 60,
        'treatment_mapping_min_percent' => 60,
        'dental_readiness_min_percent' => 50,
        'patient_readiness_min_percent' => 80,
        'local_conformance_min_percent' => 90,
        'max_open_hard_issues' => 0,
        'max_source_changed_candidates' => 0,
        'max_overdue_critical_issues' => 0,
    ],

    /*
    | Overridable threshold keys (branch profile). Any key not listed here can
    | never be branch-overridden — a defensive allowlist for the governance UI.
    |
    | The hard/critical/source-drift BUDGET keys (max_open_hard_issues,
    | max_overdue_critical_issues, max_source_changed_candidates) are
    | deliberately NOT overridable: a reasoned override must never be able to
    | raise the hard-issue budget so a branch with open HARD data-quality issues
    | reaches pilot_ready_internal. Only the percent thresholds are tunable.
    */
    'overridable_thresholds' => [
        'diagnosis_adoption_min_percent',
        'treatment_mapping_min_percent',
        'dental_readiness_min_percent',
        'patient_readiness_min_percent',
        'local_conformance_min_percent',
    ],

    /*
    | Issue SLA + priority. `due_hours` maps a priority to hours-from-assignment
    | until due. Hard issues default to `hard_default_priority`.
    */
    'sla' => [
        'priorities' => ['low', 'normal', 'high', 'critical_internal'],
        'default_priority' => 'normal',
        'hard_default_priority' => 'high',
        'due_hours' => [
            'low' => 168,
            'normal' => 72,
            'high' => 24,
            'critical_internal' => 8,
        ],
        'min_reason_length' => 10,
    ],

    /*
    | Escalation ladder. `none` is the baseline; escalation moves an issue up
    | the ladder and is audited. No escalation ever sends an external
    | notification — routing is internal in-app only.
    */
    'escalation' => [
        'levels' => [
            'none',
            'branch_supervisor',
            'clinical_reviewer',
            'it_operator',
            'super_admin',
            'management',
        ],
        'clinical_rule_codes' => [
            'structured_diagnosis',
            'duplicate_primary_diagnosis',
            'deprecated_diagnosis_selected',
            'diagnosis_code_invalid',
            'diagnosis_mapping',
            'treatment_mapping',
            'dental_completeness',
        ],
    ],

    /*
    | Pilot selection governance.
    */
    'pilot' => [
        // One primary internal pilot branch at a time unless governance
        // explicitly enables multiple. MAIN is never eligible (enforced in the
        // service against BranchService::rmeEnabledIds + MAIN exclusion).
        'max_primary_pilot_branches' => 1,
        'allow_multiple' => false,
    ],

    /*
    | Synthetic pilot rehearsal — reuses the SATUSEHAT-4A synthetic pack + branch
    | isolation. A rehearsal NEVER performs an external request and honestly ends
    | at `BLOCKED_EXTERNAL_CREDENTIAL` (or `PILOT_READY_INTERNAL` when every
    | internal gate passes but credentials are the only remaining blocker).
    */
    'rehearsal' => [
        'terminal_states' => [
            'PILOT_READY_INTERNAL',
            'BLOCKED_EXTERNAL_CREDENTIAL',
        ],
        'max_scan_candidates' => 2000,
    ],

    /*
    |--------------------------------------------------------------------------
    | SATUSEHAT-4D — Multi-branch scale-up & operational governance
    |--------------------------------------------------------------------------
    | Everything below stays credential-independent. No wave state, promotion,
    | change request, or rehearsal can enable SATUSEHAT external send or
    | production. A "pilot_ready_internal" wave/branch still has the external
    | credential blocker owned by the SATUSEHAT-2 campaign.
    */
    'multi_branch' => [

        // Feature flag for the 4D multi-branch workspace/UI. Runtime engines are
        // always safe to run read-only regardless of this flag.
        'enabled' => env('SATUSEHAT_MULTI_BRANCH_WORKSPACE_ENABLED', true),

        /*
        | Rollout wave lifecycle states, in progression order. There is
        | deliberately NO externally_ready / production_ready state.
        */
        'wave_states' => [
            'draft',
            'profiling',
            'approved',
            'in_remediation',
            'uat_scheduled',
            'uat_in_progress',
            'rehearsal_ready',
            'pilot_ready_internal',
            'blocked_external_credential',
            'suspended',
            'closed',
        ],

        // Terminal (non-progressable) wave states.
        'wave_terminal_states' => ['closed'],

        // At most one wave may be 'active' (non-draft, non-closed, non-suspended)
        // by default. A branch may belong to only ONE active wave.
        'max_active_waves' => 1,
        'allow_multiple_active_waves' => false,

        /*
        | Branch readiness transition types + the demotion trigger codes that a
        | governed demotion/suspension may cite.
        */
        'transition_types' => ['promotion', 'demotion', 'suspension', 'resume'],
        'demotion_triggers' => [
            'hard_issue_reopened',
            'diagnosis_adoption_below_threshold',
            'mapping_deprecated',
            'source_drift_backlog',
            'local_conformance_regression',
            'failed_rehearsal',
            'operator_coverage_unavailable',
            'production_guard_failure',
            'unauthorized_configuration_change',
            'data_privacy_incident',
        ],

        // Comparative matrix + export bounds.
        'matrix' => [
            'per_page' => 25,
            'max_export_rows' => 2000,
        ],
    ],

    /*
    | Human operator UAT governance. Required participant roles and the canonical
    | scenario catalog. Evidence must be synthetic / PII-safe; automated tests
    | never substitute for a real signed-off run.
    */
    'uat' => [
        'run_states' => ['draft', 'in_progress', 'completed', 'signed_off', 'rejected'],
        'outcomes' => ['pending', 'pass', 'fail', 'blocked'],
        'finding_severities' => ['none', 'low', 'medium', 'high', 'critical'],
        'required_signoff_roles' => [
            'admin_klinik',
            'doctor',
            'supervisor_rme',
            'clinical_reviewer',
            'it_operator',
            'owner',
        ],
        'signoff_decisions' => ['approved', 'rejected'],
        'min_reason_length' => 10,
    ],

    /*
    | Change-control governance. Categories a change request may target. No
    | category may enable external send or production during SATUSEHAT-4D.
    */
    'change_control' => [
        'categories' => [
            'readiness_threshold',
            'scoring_weight',
            'wave_membership',
            'pilot_status',
            'branch_suspension',
            'terminology_activation',
            'rollout_mode',
            'production_guard_config',
            'credential_state',
        ],
        'states' => ['pending', 'reviewed', 'approved', 'rejected', 'applied', 'rolled_back'],
        // Categories that can NEVER be auto-applied / must stay blocked in 4D.
        'blocked_categories' => ['production_guard_config', 'credential_state'],
        'min_reason_length' => 10,
    ],

    /*
    | Hermetic incident-drill catalog (no-network). Each drill documents a
    | trigger, expected safe state, diagnostic command, and rollback.
    */
    'incident_drills' => [
        'cross_branch_idor',
        'incorrect_wave_enrollment',
        'duplicate_active_wave_membership',
        'threshold_tampering',
        'score_manipulation',
        'hard_issue_reopening',
        'mapping_deprecation',
        'source_drift',
        'rehearsal_partial_failure',
        'synthetic_reset_scope',
        'queue_worker_stopped',
        'redis_unavailable',
        'stale_assignment',
        'unauthorized_suspension',
        'external_send_flag_tampering',
        'production_flag_tampering',
        'integration_disabled',
        'nginx_default_server_regression',
        'co_tenant_shadowing',
        'privacy_leak_attempt',
        'rollback_to_safe_state',
    ],
];
