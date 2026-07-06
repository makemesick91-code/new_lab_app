<?php

/**
 * ENT-15 — Enterprise Documentation & Runbook configuration.
 *
 * Read-only registry that declares which enterprise runbooks/SOPs must exist,
 * which sections each runbook must contain, which topics the runbook set must
 * cover, and which foundation commands the runbooks must reference, so
 * App\Support\Documentation\EnterpriseDocumentationScanner and
 * App\Services\Foundation\EnterpriseDocumentationGovernanceService can validate
 * enterprise documentation completeness and safety WITHOUT running a deploy,
 * touching a database, or exposing any secret/PII.
 *
 * Scope source: config/foundation_roadmap.php ENT-15 entry (title
 * "Enterprise Documentation & Runbook", category documentation, depends ENT-14).
 * ENT-15 consolidates all prior ENT outputs (deploy, rollback, backup/DR,
 * restore rehearsal, release evidence/smoke, security/PII, health, developer
 * console, queue/outbox, load-test baseline, scale projection) into a governed,
 * testable runbook set right before the ENT-16 closure GO/NO-GO.
 *
 * SAFETY:
 *  - Documentation NEVER contains secrets, credentials, environment values, or
 *    unmasked KTP/NIK. The scanner reuses the release-evidence forbidden
 *    patterns/regex to enforce this.
 *  - Destructive commands (migrate:fresh, db:wipe, ...) may only appear inside a
 *    runbook's explicit "forbidden commands" / warning section; any destructive
 *    literal outside a safe-zone section is a violation. All destructive
 *    literals live HERE (config), so the scanner source carries none inline
 *    (mirrors the ENT-9..14 config-not-code convention).
 *  - The scanner only reads files and config; it never runs a deploy, a backup,
 *    a restore, or any database/queue command.
 */
return [
    'sprint' => 'ENT-15',

    // Master switch for the ENT-15 governance surface.
    'enabled' => (bool) env('ENTERPRISE_DOCUMENTATION_GOVERNANCE_ENABLED', true),

    // When true, warning-level findings are treated as failures by default in
    // the governance command (mirrors the ENT-10..14 configs).
    'strict' => (bool) env('ENTERPRISE_DOCUMENTATION_GOVERNANCE_STRICT', false),

    // Read-only artisan command that renders the runbook registry summary. Must
    // be a registered command (analysis-only, no deploy/DB side effect).
    'summary_command' => 'docs:enterprise-runbook-summary',

    // Sections every mandatory runbook must contain (matched as a markdown
    // heading substring, case-insensitive). Makes runbooks actionable, not just
    // descriptive.
    'required_sections' => [
        'Purpose',
        'When to Use',
        'Prerequisites',
        'Safe Commands',
        'Forbidden Commands',
        'Evidence',
        'Rollback',
        'Troubleshooting',
        'Smoke Verification',
        'Security',
        'Owner',
        'Review Cadence',
    ],

    // Heading fragments that mark a "safe zone" section where a destructive
    // command literal is allowed to be *named as forbidden* (never as an example
    // to run). A destructive literal anywhere else is a violation.
    'destructive_safe_zone_headings' => [
        'Forbidden Commands',
        'Larangan',
        'Do Not Run',
        'Never Run',
        'Warning',
        'Danger',
    ],

    // Destructive / production-endangering literals. Kept here (config) so the
    // scanner source carries none inline. Each runbook must document these as
    // forbidden, and they must never appear outside a safe-zone section.
    'forbidden_destructive_patterns' => [
        'migrate:fresh',
        'db:wipe',
        'schema:drop',
        'migrate:reset',
    ],

    // Foundation readiness commands the runbook set must reference (collectively)
    // so operators are pointed at the real gates.
    'required_command_references' => [
        'foundation:queue-retry-failed-job-check',
        'foundation:idempotency-outbox-check',
        'foundation:developer-console-check',
        'foundation:health-check',
        'foundation:security-compliance-check',
        'foundation:cicd-enterprise-gate-check',
        'foundation:deployment-rollback-check',
        'foundation:backup-dr-check',
        'foundation:load-test-baseline-check',
        'foundation:load-test-scale-projection-check',
        'foundation:enterprise-documentation-check',
    ],

    // Topics the runbook set must collectively cover (each maps to at least one
    // mandatory runbook). Consolidates every prior ENT foundation output.
    'required_topics' => [
        'operations',
        'deploy',
        'rollback',
        'backup_dr',
        'restore_rehearsal',
        'release_evidence',
        'smoke',
        'security_pii',
        'health',
        'developer_console',
        'queue_outbox',
        'load_test_baseline',
        'scale_projection',
    ],

    // The mandatory enterprise runbook registry. Each runbook declares its path,
    // the topics it covers, and (optionally) the commands it must reference.
    // Missing any runbook file is a hard FAIL.
    'mandatory_runbooks' => [
        'enterprise_operations' => [
            'path' => 'docs/runbooks/enterprise-operations-runbook.md',
            'title' => 'Enterprise Operations Runbook',
            'topics' => ['operations', 'developer_console', 'queue_outbox', 'health'],
            'must_reference_commands' => [
                'foundation:developer-console-check',
                'foundation:health-check',
                'foundation:queue-retry-failed-job-check',
                'foundation:idempotency-outbox-check',
            ],
        ],
        'vps_deploy_rollback' => [
            'path' => 'docs/runbooks/vps-deploy-rollback-runbook.md',
            'title' => 'VPS Deploy & Rollback Runbook',
            'topics' => ['deploy', 'rollback'],
            'must_reference_commands' => [
                'foundation:cicd-enterprise-gate-check',
                'foundation:deployment-rollback-check',
            ],
        ],
        'backup_dr_restore_rehearsal' => [
            'path' => 'docs/runbooks/backup-dr-restore-rehearsal-runbook.md',
            'title' => 'Backup, DR & Restore Rehearsal Runbook',
            'topics' => ['backup_dr', 'restore_rehearsal'],
            'must_reference_commands' => [
                'foundation:backup-dr-check',
            ],
        ],
        'release_evidence_smoke' => [
            'path' => 'docs/runbooks/release-evidence-smoke-runbook.md',
            'title' => 'Release Evidence & Smoke Runbook',
            'topics' => ['release_evidence', 'smoke', 'security_pii'],
            'must_reference_commands' => [
                'foundation:security-compliance-check',
                'foundation:enterprise-documentation-check',
            ],
        ],
        'performance_load_test' => [
            'path' => 'docs/runbooks/performance-load-test-runbook.md',
            'title' => 'Performance & Load Test Runbook',
            'topics' => ['load_test_baseline', 'scale_projection'],
            'must_reference_commands' => [
                'foundation:load-test-baseline-check',
                'foundation:load-test-scale-projection-check',
            ],
        ],
    ],

    // Release-evidence profiles that must declare the ENT-15 evidence artifact
    // plus the ENT-12..14 sibling artifacts (read from config/release_evidence.php).
    'evidence' => [
        'artifact' => 'enterprise-documentation-check.json',
        'required_in_profiles' => ['ci', 'vps'],
        'required_sibling_artifacts' => [
            'load-test-scale-projection-check.json',
            'load-test-baseline-check.json',
            'backup-dr-check.json',
        ],
    ],

    // Release-safety pre-deploy gate (config/release_safety.php) must include
    // this command name.
    'required_pre_deploy_gate_command' => 'foundation:enterprise-documentation-check',

    // The enterprise documentation governance doc.
    'policy_doc' => 'docs/architecture/enterprise-documentation-runbook-governance.md',
];
