<?php

/**
 * ENT-10 — CI/CD Enterprise Gate configuration.
 *
 * Read-only registry that declares what a safe enterprise CI/CD gate must
 * enforce so App\Support\Cicd\CicdEnterpriseGateScanner and
 * App\Services\Foundation\CicdEnterpriseGateGovernanceService can validate the
 * deploy script, the CI workflow/script, the release-evidence profiles, and the
 * release-safety pre-deploy gate against it — without mutating anything.
 *
 * Extends the shipped NSF-9/NSF-10 flag/smoke/evidence gates into a full
 * CI/CD enterprise gate (critical tests, migration safety, route/config
 * sanity, smoke commands).
 *
 * SAFETY:
 *  - Every destructive-command / migration-safety literal lives HERE, so app
 *    code, the deploy script, and the CI scripts never carry the sensitive
 *    patterns inline (mirrors the ENT-9 config-not-code convention).
 *  - The scanner only reads files and config; it never runs a destructive
 *    command and never writes anything.
 */
return [
    'sprint' => 'ENT-10',

    // Master switch for the ENT-10 governance surface.
    'enabled' => (bool) env('CICD_ENTERPRISE_GATE_ENABLED', true),

    // When true, warning-level findings are treated as failures by default in
    // the governance command (mirrors the health-check/observability configs).
    'strict' => (bool) env('CICD_ENTERPRISE_GATE_STRICT', false),

    // Deploy / CI gate files the enterprise gate inspects. Missing any of these
    // is a hard FAIL.
    'gate_files' => [
        'deploy_script' => 'scripts/deploy-vps.sh',
        'ci_workflow' => '.github/workflows/foundation-evidence-gates.yml',
        'ci_script' => 'scripts/ci/foundation-evidence-gates.sh',
    ],

    // Foundation governance commands the CI/deploy chain must run before a
    // release. These are the ENT-5..9 + roadmap + release-safety checks the
    // enterprise gate encodes. The deploy script and the CI script/workflow are
    // scanned for the presence of every command name below.
    'required_foundation_commands' => [
        'architecture:foundation-roadmap-check',
        'foundation:queue-retry-failed-job-check',
        'foundation:idempotency-outbox-check',
        'foundation:developer-console-check',
        'foundation:health-check',
        'foundation:security-compliance-check',
        'foundation:release-safety-check',
        'foundation:cicd-enterprise-gate-check',
    ],

    // The deploy script must also run the NSF-10 release-evidence capture/check
    // chain (profile-aware evidence) before rebuild.
    'required_deploy_evidence_commands' => [
        'release:evidence-capture',
        'release:evidence-check',
        'release:automated-smoke',
    ],

    // Migration-safety contract. The deploy script must run `migrate --force`
    // and must NEVER contain a destructive database command.
    'migration_safety' => [
        'required_migrate_command' => 'migrate --force',
        // Deploy must back up the DB (pg_dump) before pulling/migrating.
        'required_backup_command' => 'pg_dump',
    ],

    // Destructive command literals that must never appear in the deploy script,
    // the CI workflow, or the CI script. Kept here (config) so no app/CI/deploy
    // source file carries the literal patterns inline. Case-insensitive scan.
    'forbidden_destructive_patterns' => [
        'migrate:fresh',
        'migrate:reset',
        'db:wipe',
        'schema:drop',
        'drop database',
        'drop schema',
        'truncate',
    ],

    // ENT-8 cache-order hardening: route/config cache must be cleared BEFORE the
    // route-dependent governance gates run in the deploy script. The scanner
    // asserts the clear markers appear before the first governance-gate marker.
    'cache_order' => [
        // Markers that clear stale caches ahead of the gate phase. Matched as
        // executable `php artisan` lines so a comment mentioning the gate does
        // not skew the ordering check.
        'pre_gate_clear_markers' => [
            'php artisan route:clear',
            'php artisan config:clear',
        ],
        // First route-dependent governance gate whose position the clears must
        // precede (matched as the executable line, not a comment mention).
        'first_route_dependent_gate' => 'php artisan foundation:developer-console-check',
        // Caches that must be rebuilt after the gate phase (route/config sanity).
        'post_gate_rebuild_markers' => [
            'config:cache',
            'route:cache',
            'view:cache',
        ],
    ],

    // The CI workflow must run the enterprise gate on pull requests to the base
    // branch (failed CI blocks merge). The scanner asserts a pull_request
    // trigger is present.
    'ci_workflow_expectations' => [
        'required_triggers' => ['pull_request'],
        'base_branch' => 'feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report',
        // The CI script must fail hard on any gate error, never continue.
        'fail_fast_marker' => 'set -euo pipefail',
    ],

    // Release-evidence profiles that must declare the ENT-10 evidence artifact
    // plus the ENT-5..9 artifacts (read from config/release_evidence.php).
    'evidence' => [
        'artifact' => 'cicd-enterprise-gate-check.json',
        'required_in_profiles' => ['ci', 'vps'],
        // ENT-5..9 artifacts that must also stay required in the ci/vps profiles.
        'required_sibling_artifacts' => [
            'queue-governance-check.json',
            'idempotency-outbox-check.json',
            'developer-console-check.json',
            'health-check-check.json',
            'security-compliance-check.json',
        ],
    ],

    // Release-safety pre-deploy gate (config/release_safety.php) must include
    // these command names (base name, options ignored).
    'required_pre_deploy_gate_commands' => [
        'foundation:developer-console-check',
        'foundation:health-check',
        'foundation:security-compliance-check',
        'foundation:cicd-enterprise-gate-check',
    ],
];
