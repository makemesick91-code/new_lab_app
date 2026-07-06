<?php

/**
 * ENT-16 — Enterprise Foundation Closure GO/NO-GO configuration.
 *
 * Read-only registry that declares the mandatory enterprise foundation gates
 * (ENT-5..ENT-15), the roadmap entries that must be present and completed with
 * GO evidence (ENT-1..ENT-16), the 13 canonical closure criteria (from
 * docs/architecture/enterprise-foundation-freeze-rules.md §21), the final
 * closure tag, and the release-evidence / release-safety wiring, so
 * App\Support\Foundation\EnterpriseFoundationClosureScanner and
 * App\Services\Foundation\EnterpriseFoundationClosureGovernanceService can run a
 * real, testable GO / WATCH / NO-GO closure decision WITHOUT running a deploy,
 * mutating a database, or exposing any secret/PII.
 *
 * Scope source: config/foundation_roadmap.php ENT-16 entry (title
 * "Enterprise Foundation Closure GO/NO-GO", category governance, depends
 * ENT-1..ENT-15). ENT-16 closes the enterprise foundation sequence: it verifies
 * every ENT foundation is present, governed, evidenced, and inherited by future
 * work, and — on GO — authorises the final `enterprise-foundation-go` tag that
 * ends the initial Enterprise Foundation Freeze.
 *
 * SAFETY:
 *  - The scanner/service ONLY read config, files, and other read-only governance
 *    services. They never run a deploy, backup, restore, load test, migration,
 *    or any DB/queue mutation.
 *  - Closure evidence is non-sensitive: no secret/credential/environment value
 *    or unmasked KTP/NIK is ever emitted (release-evidence forbidden patterns
 *    apply to the closure docs).
 *  - Destructive literals live HERE (config), so the scanner source carries none
 *    inline (config-not-code convention, mirrors ENT-9..15).
 *  - Closure NEVER weakens a sibling foundation gate; it only aggregates them.
 */
return [
    'sprint' => 'ENT-16',

    // Master switch for the ENT-16 closure governance surface.
    'enabled' => (bool) env('ENTERPRISE_FOUNDATION_CLOSURE_GOVERNANCE_ENABLED', true),

    // When true, WATCH is treated as a failure by default in the governance
    // command (mirrors the ENT-10..15 configs). Strict/--fail-on-warning also
    // block on WATCH regardless of this flag.
    'strict' => (bool) env('ENTERPRISE_FOUNDATION_CLOSURE_GOVERNANCE_STRICT', false),

    // The final enterprise foundation closure tag. Created ONLY on a GO closure
    // decision; ends the initial Enterprise Foundation Freeze. The per-sprint GO
    // tag (ent-16-...-go) is recorded separately in the roadmap entry.
    'final_closure_tag' => 'enterprise-foundation-go',

    // The mandatory enterprise foundation gates. Each must be a completed
    // roadmap entry with a GO tag, publish its governance section, and expose a
    // read-only strict readiness command that resolves GO. A single missing or
    // non-GO gate is a NO-GO for closure.
    'mandatory_gates' => [
        'ENT-5' => [
            'title' => 'Queue, Retry & Failed Job Governance',
            'governance_section' => 'queue_retry_governance',
            'readiness_command' => 'foundation:queue-retry-failed-job-check',
            'go_tag' => 'ent-5-queue-retry-failed-job-governance-go',
        ],
        'ENT-6' => [
            'title' => 'Idempotency & Outbox Foundation',
            'governance_section' => 'idempotency_outbox_governance',
            'readiness_command' => 'foundation:idempotency-outbox-check',
            'go_tag' => 'ent-6-idempotency-outbox-foundation-go',
        ],
        'ENT-7' => [
            'title' => 'Developer Assistance Console',
            'governance_section' => 'developer_console_governance',
            'readiness_command' => 'foundation:developer-console-check',
            'go_tag' => 'ent-7-developer-assistance-console-go',
        ],
        'ENT-8' => [
            'title' => 'Observability & Health Check Pack',
            'governance_section' => 'health_check_governance',
            'readiness_command' => 'foundation:health-check',
            'go_tag' => 'ent-8-observability-health-check-pack-go',
        ],
        'ENT-9' => [
            'title' => 'Security & PII Compliance Hardening',
            'governance_section' => 'security_compliance_governance',
            'readiness_command' => 'foundation:security-compliance-check',
            'go_tag' => 'ent-9-security-pii-compliance-hardening-go',
        ],
        'ENT-10' => [
            'title' => 'CI/CD Enterprise Gate',
            'governance_section' => 'cicd_enterprise_gate_governance',
            'readiness_command' => 'foundation:cicd-enterprise-gate-check',
            'go_tag' => 'ent-10-cicd-enterprise-gate-go',
        ],
        'ENT-11' => [
            'title' => 'Deployment & Rollback Automation',
            'governance_section' => 'deployment_rollback_governance',
            'readiness_command' => 'foundation:deployment-rollback-check',
            'go_tag' => 'ent-11-deployment-rollback-automation-go',
        ],
        'ENT-12' => [
            'title' => 'Backup & Disaster Recovery Automation',
            'governance_section' => 'backup_dr_governance',
            'readiness_command' => 'foundation:backup-dr-check',
            'go_tag' => 'ent-12-backup-disaster-recovery-automation-go',
        ],
        'ENT-13' => [
            'title' => 'Load Test 5 Cabang Baseline',
            'governance_section' => 'load_test_baseline_governance',
            'readiness_command' => 'foundation:load-test-baseline-check',
            'go_tag' => 'ent-13-load-test-5-cabang-baseline-go',
        ],
        'ENT-14' => [
            'title' => 'Load Test Scale Projection',
            'governance_section' => 'load_test_scale_projection_governance',
            'readiness_command' => 'foundation:load-test-scale-projection-check',
            'go_tag' => 'ent-14-load-test-scale-projection-go',
        ],
        'ENT-15' => [
            'title' => 'Enterprise Documentation & Runbook',
            'governance_section' => 'enterprise_documentation_governance',
            'readiness_command' => 'foundation:enterprise-documentation-check',
            'go_tag' => 'ent-15-enterprise-documentation-runbook-go',
        ],
    ],

    // Roadmap entries that must be present AND completed with a non-empty go_tag
    // before closure can be GO. Covers the full ENT-1..ENT-16 enterprise
    // foundation sequence (ENT-16 itself must earn its own GO tag).
    'required_completed_roadmap_ids' => [
        'ENT-1', 'ENT-2', 'ENT-3', 'ENT-4', 'ENT-5', 'ENT-6', 'ENT-7', 'ENT-8',
        'ENT-9', 'ENT-10', 'ENT-11', 'ENT-12', 'ENT-13', 'ENT-14', 'ENT-15',
        'ENT-16',
    ],

    // The 13 canonical closure criteria (freeze-rules §21). Each maps to a
    // scanner posture key that must be satisfied for closure GO. This is the
    // durable, testable form of the "13 closure criteria".
    'closure_criteria' => [
        1 => ['key' => 'architecture_governance', 'title' => 'Architecture governance command/check available'],
        2 => ['key' => 'database_performance_baseline', 'title' => 'Database performance baseline available'],
        3 => ['key' => 'cache_governance', 'title' => 'Cache governance available'],
        4 => ['key' => 'queue_idempotency_outbox', 'title' => 'Queue / failed job / idempotency / outbox governance available'],
        5 => ['key' => 'observability_developer_console', 'title' => 'Observability and Developer Assistance available'],
        6 => ['key' => 'security_pii', 'title' => 'Security and PII hardening available'],
        7 => ['key' => 'cicd_gate', 'title' => 'CI/CD gate runs'],
        8 => ['key' => 'deploy_rollback', 'title' => 'Deploy and rollback automation available'],
        9 => ['key' => 'backup_restore_rehearsal', 'title' => 'Backup and restore rehearsal evidence available'],
        10 => ['key' => 'load_test_scale_projection', 'title' => 'Load test 5 cabang and scale projection available'],
        11 => ['key' => 'documentation_runbook', 'title' => 'Documentation and runbook available'],
        12 => ['key' => 'closure_evidence_pack', 'title' => 'Final GO/NO-GO evidence pack available'],
        13 => ['key' => 'final_closure_tag_declared', 'title' => 'Final GO tag declared: enterprise-foundation-go'],
    ],

    // Release-evidence profiles that must declare the ENT-16 closure artifact
    // (plus the ENT-15 sibling as a linkage anchor).
    'evidence' => [
        'artifact' => 'enterprise-closure-check.json',
        'required_in_profiles' => ['ci', 'vps'],
        'required_sibling_artifacts' => [
            'enterprise-documentation-check.json',
        ],
    ],

    // Release-safety pre-deploy gate (config/release_safety.php) must include
    // this command name.
    'required_pre_deploy_gate_command' => 'foundation:enterprise-closure-check',

    // Deploy/rollback/backup/restore/load-test scripts that must remain present
    // (closure verifies the operational chain is intact — it never runs them).
    'required_scripts' => [
        'deploy' => 'scripts/deploy-vps.sh',
        'rollback' => 'scripts/rollback-vps.sh',
        'backup' => 'scripts/backup-vps.sh',
        'restore_rehearsal' => 'scripts/restore-rehearsal.sh',
        'load_test_baseline' => 'scripts/load-test-baseline.sh',
        'load_test_scale_projection' => 'scripts/load-test-scale-projection.sh',
    ],

    // Mandatory runbooks that must remain present at closure (mirrors the ENT-15
    // registry; closure re-asserts the operator chain is documented).
    'required_runbooks' => [
        'docs/runbooks/enterprise-operations-runbook.md',
        'docs/runbooks/vps-deploy-rollback-runbook.md',
        'docs/runbooks/backup-dr-restore-rehearsal-runbook.md',
        'docs/runbooks/release-evidence-smoke-runbook.md',
        'docs/runbooks/performance-load-test-runbook.md',
    ],

    // Destructive / production-endangering literals. Kept here (config) so the
    // scanner source carries none inline. Closure NO-GO if any of these is
    // executable in the deploy/rollback/backup scripts (outside a comment).
    'forbidden_destructive_patterns' => [
        'migrate:fresh',
        'db:wipe',
        'schema:drop',
        'migrate:reset',
    ],

    // Docs that must exist and stay non-sensitive (release-evidence forbidden
    // scan applies) so future work inherits the closed-foundation rules.
    'closure_docs' => [
        'policy_doc' => 'docs/architecture/enterprise-foundation-closure-go-no-go.md',
        'runbook_doc' => 'docs/runbooks/enterprise-foundation-closure-runbook.md',
        'freeze_rules_doc' => 'docs/architecture/enterprise-foundation-freeze-rules.md',
    ],

    // WATCH is only allowed to remain non-blocking for these explicitly
    // documented conditions (mirrors the roadmap watch_criteria posture). Any
    // WATCH still blocks under --strict / --fail-on-warning.
    'allowed_watch_conditions' => [
        'closure_deferred_with_named_blocking_ent_sprint',
    ],
];
