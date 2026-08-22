<?php

/*
|--------------------------------------------------------------------------
| MON-1 — Foundation Monitoring & Observability Signal Registry
|--------------------------------------------------------------------------
|
| Single canonical, read-only description of the monitoring/observability
| signals that ALREADY exist across the app (health endpoints, deploy/backup
| evidence, governance/audit commands, queue, storage/cache, logs).
|
| MON-1 rule: this registry DESCRIBES existing signals so they are
| discoverable and consolidatable. It does NOT re-implement NSF/CICD gates,
| deploy evidence, smoke gates, or domain audits.
|
| Governance invariant enforced by tests: no signal whose `type` is
| `command` and whose command is expensive may have `auto_run => true`.
| Expensive commands run CLI-only (via --include-audits) or are read from
| their cached evidence artifact — never on a web request.
|
*/

return [

    'enabled' => env('FOUNDATION_MONITORING_ENABLED', true),

    // Strict mode fails the consolidation command on real unsafe FAIL states.
    // WATCH never fails unless --strict is passed AND the state is unsafe.
    'strict_default' => env('FOUNDATION_MONITORING_STRICT_DEFAULT', false),

    // The permission that gates the read-only monitoring UI. Reuses the
    // ENT-7 Developer Console permission (Super Admin only via Gate::before) —
    // MON-1 intentionally adds NO new permission.
    'ui' => [
        'route_name' => 'foundation.monitoring.index',
        'route_path' => '/foundation/monitoring',
        'permission' => 'view_developer_console',
    ],

    // Environments where debug-on / unexpected maintenance are treated as
    // UNSAFE FAIL (production posture). Local/testing are exempt.
    'production_like_environments' => ['production', 'pilot', 'staging'],

    /*
    |----------------------------------------------------------------------
    | Path conventions (mirror the existing ENT-8/11/12 config — not new)
    |----------------------------------------------------------------------
    */
    'paths' => [
        // ENT-12 backup directory (config/backup_dr.php required_backup_directory).
        'backup_directory' => 'storage/app/backups/deploy',
        // NSF-10 release-evidence latest dir (vps profile) holding *-check.json.
        'evidence_directory' => 'storage/release-evidence/latest',
        // Governance summary artifact whose decision MON-1 surfaces (read-only).
        'governance_summary_artifact' => 'foundation-governance-summary.json',
        // Deploy runner status/log (reference/telemetry/deploy-vps-runner.sh).
        'deploy_log_glob' => 'storage/logs/deploy-*.log',
        // NOTE: a `laravel_log` path used to live here. MONITORING-LOG-SOURCE-RESILIENCE-1
        // retired it: the application log is whatever the effective `config/logging.php`
        // channel writes to, resolved by MonitoringLogSourceResolver. A second path
        // declared here could disagree with the running logger — and being the one the
        // monitor actually read, it would win, which is precisely the false green this
        // sprint removed. Do not reintroduce it.
    ],

    // Writable paths MON-1 probes for the storage/cache permission health check
    // (Scope E). Report-only — MON-1 never chmods from web or CLI.
    'writable_paths' => [
        'storage/framework/cache/data',
        'storage/logs',
        'bootstrap/cache',
    ],

    // Non-blocking thresholds. failed_jobs at/above warn => WATCH (never FAIL).
    'thresholds' => [
        'failed_jobs_watch' => env('FOUNDATION_MONITORING_FAILED_JOBS_WATCH', 1),
        // Recent Laravel log errors since tail window => WATCH.
        'log_error_watch' => env('FOUNDATION_MONITORING_LOG_ERROR_WATCH', 1),
        // Backup considered stale (WATCH) if older than this many hours, when present.
        'backup_stale_hours' => env('FOUNDATION_MONITORING_BACKUP_STALE_HOURS', 48),
        'log_tail_lines' => 200,
    ],

    /*
    |----------------------------------------------------------------------
    | Signal registry
    |----------------------------------------------------------------------
    | type: endpoint | path | command | probe
    | auto_run: whether the consolidation MAY execute this on collect().
    |           MUST be false for every `command` type (CLI/evidence only).
    | severity_on_fail: FAIL | WATCH | UNKNOWN
    */
    'signals' => [

        'health_live' => [
            'label' => 'Health — Liveness',
            'type' => 'endpoint',
            'source' => '/health/live',
            'owner' => 'ENT-8',
            'auto_run' => true, // in-process HealthCheckService::liveness(), cheap
            'severity_on_fail' => 'FAIL',
            'doc' => 'docs/architecture/observability-health-check-pack-governance.md',
        ],

        'health_ready' => [
            'label' => 'Health — Readiness',
            'type' => 'endpoint',
            'source' => '/health/ready',
            'owner' => 'ENT-8',
            'auto_run' => true, // in-process HealthCheckService::readiness(), cheap probes
            'severity_on_fail' => 'FAIL',
            'doc' => 'docs/architecture/observability-health-check-pack-governance.md',
        ],

        'health_lb' => [
            'label' => 'Health — Load Balancer',
            'type' => 'endpoint',
            'source' => '/health/lb',
            'owner' => 'LB-1',
            'auto_run' => false, // route presence only; not probed on page load
            'severity_on_fail' => 'WATCH',
            'doc' => 'docs/architecture/load-balancer-pilot-readiness.md',
        ],

        'queue_failed_jobs' => [
            'label' => 'Queue — Failed Jobs',
            'type' => 'probe',
            'source' => 'failed_jobs table (count only)',
            'owner' => 'ENT-5',
            'auto_run' => true, // cheap count; UNKNOWN if table absent
            'severity_on_fail' => 'WATCH',
            'doc' => 'docs/architecture/queue-retry-failed-job-governance.md',
        ],

        'queue_worker' => [
            'label' => 'Queue — Worker / Scheduler',
            'type' => 'probe',
            'source' => 'no reliable in-app source',
            'owner' => 'POST-ENT',
            'auto_run' => true, // returns UNKNOWN with explanation; never fakes green
            'severity_on_fail' => 'UNKNOWN',
            'doc' => 'docs/runbooks/queue-worker-activation-runbook.md',
        ],

        'storage_cache_writable' => [
            'label' => 'Storage / Cache — Writable',
            'type' => 'probe',
            'source' => 'storage/framework/cache/data, storage/logs, bootstrap/cache',
            'owner' => 'MON-1',
            'auto_run' => true, // temp-file create+delete; report-only, no chmod
            'severity_on_fail' => 'FAIL',
            'doc' => 'docs/runbooks/mon-1-foundation-monitoring-observability-runbook.md',
        ],

        'deploy_backup' => [
            'label' => 'Deploy — Latest Backup',
            'type' => 'path',
            'source' => 'storage/app/backups/deploy',
            'owner' => 'ENT-12',
            'auto_run' => true, // directory listing / file stat only
            'severity_on_fail' => 'WATCH',
            'doc' => 'docs/architecture/backup-disaster-recovery-automation-governance.md',
        ],

        'deploy_evidence' => [
            'label' => 'Deploy — Governance Evidence',
            'type' => 'path',
            'source' => 'storage/release-evidence/latest',
            'owner' => 'NSF-10',
            'auto_run' => true, // reads cached governance-summary.json decision only
            'severity_on_fail' => 'WATCH',
            'doc' => 'docs/architecture/release-evidence.md',
        ],

        'laravel_log' => [
            'label' => 'Application Log — Recent Errors',
            'type' => 'path',
            'source' => 'storage/logs/laravel.log (sanitized counts)',
            'owner' => 'OBS-1',
            'auto_run' => true, // tail + masked category counts; no raw payload
            'severity_on_fail' => 'WATCH',
            'doc' => 'docs/architecture/request-correlation-observability-readiness.md',
        ],

        /*
        | Governance & domain audit commands — NEVER auto-run on a web request.
        | The web page reads their cached *-check.json evidence decision;
        | `--include-audits` (CLI) may invoke them and report exit status.
        */
        'audit_ci_runtime_control' => [
            'label' => 'Gate — CI Runtime Control (CICD-CTRL-1)',
            'type' => 'command',
            'source' => 'foundation:ci-runtime-control-check --strict',
            'evidence_artifact' => 'ci-runtime-control-check.json',
            'owner' => 'CICD-CTRL-1',
            'auto_run' => false,
            'severity_on_fail' => 'FAIL',
            'doc' => 'docs/sprints/cicd-ctrl-1-safe-ci-runtime-control.md',
        ],

        'audit_security_compliance' => [
            'label' => 'Gate — Security / PII Compliance (ENT-9)',
            'type' => 'command',
            'source' => 'foundation:security-compliance-check',
            'evidence_artifact' => 'security-compliance-check.json',
            'owner' => 'ENT-9',
            'auto_run' => false,
            'severity_on_fail' => 'FAIL',
            'doc' => 'docs/architecture/security-pii-compliance-hardening-governance.md',
        ],

        'audit_cicd_enterprise_gate' => [
            'label' => 'Gate — CI/CD Enterprise Gate (ENT-10)',
            'type' => 'command',
            'source' => 'foundation:cicd-enterprise-gate-check',
            'evidence_artifact' => 'cicd-enterprise-gate-check.json',
            'owner' => 'ENT-10',
            'auto_run' => false,
            'severity_on_fail' => 'FAIL',
            'doc' => 'docs/architecture/cicd-enterprise-gate-governance.md',
        ],

        'audit_roadmap' => [
            'label' => 'Gate — Foundation Roadmap',
            'type' => 'command',
            'source' => 'foundation:roadmap-check --strict',
            'evidence_artifact' => 'foundation-roadmap-check.json',
            'owner' => 'ROADMAP-1',
            'auto_run' => false,
            'severity_on_fail' => 'WATCH',
            'doc' => 'docs/architecture/foundation-roadmap-canonicalization.md',
        ],

        'audit_inventory_procurement' => [
            'label' => 'Audit — Inventory Procurement Workflow',
            'type' => 'command',
            'source' => 'inventory:procurement-workflow-audit --strict',
            'evidence_artifact' => null,
            'owner' => 'Sprint 68.45',
            'auto_run' => false,
            'severity_on_fail' => 'FAIL',
            'doc' => 'docs/runbooks/inventory-procurement-workflow-audit-runbook.md',
        ],

        'audit_doctor_performance_access' => [
            'label' => 'Audit — Doctor Performance Access',
            'type' => 'command',
            'source' => 'rme:doctor-performance-access-audit --strict',
            'evidence_artifact' => null,
            'owner' => 'FIX-PRE-68-45',
            'auto_run' => false,
            'severity_on_fail' => 'FAIL',
            'doc' => 'docs/sprints/hotfix-fix-pre-68-45-doctor-performance-403.md',
        ],
    ],

    // Audit commands that --include-audits (CLI only) may invoke. Each is the
    // authoritative source; MON-1 only reports their exit status.
    'includable_audits' => [
        'rme:doctor-performance-access-audit' => ['--strict'],
        'inventory:procurement-workflow-audit' => ['--strict'],
    ],
];
