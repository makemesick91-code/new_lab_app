<?php

/**
 * POST-ENT — Enterprise Foundation Runtime Hardening configuration.
 *
 * Read-only registry consumed by App\Support\Foundation\EntFoundationRuntimeHardeningScanner
 * and App\Services\Foundation\EntFoundationRuntimeHardeningGovernanceService via:
 *  - foundation:ent-1-4-audit-check
 *  - foundation:queue-worker-runtime-check
 *  - foundation:runtime-hardening-check
 *
 * This is a POST-CLOSURE hardening sprint that runs AFTER ENT-16 formally closed
 * the ENT-1..ENT-16 Enterprise Foundation Freeze (final tag: enterprise-foundation-go).
 * It is NOT ENT-17 and it must NEVER regress the closed baseline or move an ENT GO
 * tag. It adds three governed, testable postures:
 *   1. An ENT-1..ENT-4 audit that proves those early governance/config/docs locks
 *      are present, completed, GO-tagged and doc-backed — WITHOUT retrofitting them
 *      into runtime modules (their canonical scope was governance/config/docs only).
 *   2. A conservative queue-worker runtime posture on top of ENT-5 queue governance.
 *   3. A deploy-evidence-capture timeout-hardening posture (server-side detached
 *      runner) so a slow VPS evidence phase can outlive an SSH broken pipe.
 *
 * SAFETY:
 *  - Every destructive / sensitive literal lives HERE (config-not-code), mirroring
 *    the ENT-9..ENT-16 convention, so the scripts and app source never carry them.
 *  - The scanner only reads files and config. It never enables a worker, starts a
 *    job, runs a backup/restore, or touches a database.
 *  - Rule/description text never contains a secret key name or KTP/NIK-shaped value
 *    (the release-evidence forbidden-pattern scan runs over the emitted artifacts).
 */
return [
    'sprint' => 'POST-ENT-RUNTIME-HARDENING',

    'enabled' => (bool) env('ENT_RUNTIME_HARDENING_GOVERNANCE_ENABLED', true),
    'strict' => (bool) env('ENT_RUNTIME_HARDENING_GOVERNANCE_STRICT', false),

    // The closed Enterprise Foundation baseline this sprint must preserve.
    'baseline' => [
        'final_closure_tag' => 'enterprise-foundation-go',
        'closed_sequence' => 'ENT-1..ENT-16',
        'next_recommended_sprint' => 'MON-1',
        'is_ent_17' => false,
    ],

    /*
     * ENT-1..ENT-4 AUDIT
     *
     * Canonical scope resolution: ENT-1 (Enterprise Architecture Baseline Lock),
     * ENT-2 (Database Performance Contract), ENT-3 (Reporting Materialized Summary
     * Expansion) and ENT-4 (Redis Cache Enterprise Policy) were governance / config
     * / docs LOCKS, not runtime feature sprints. The audit therefore verifies the
     * durable artifacts exist — roadmap entry completed + GO-tagged, canonical docs
     * present with their rule-id markers — and MUST NOT flag them incomplete merely
     * for lacking a runtime module.
     */
    'ent_1_4_audit' => [
        'expectations' => [
            'ENT-1' => [
                'title' => 'Enterprise Architecture Baseline Lock',
                'canonical_scope' => 'governance_config_docs_lock',
                'runtime_backfill_required' => false,
                'roadmap_docs_keys' => ['baseline_doc'],
                'required_docs' => [
                    'docs/architecture/enterprise-architecture-baseline-lock.md',
                ],
                'doc_markers' => ['ENT1-R001', 'ENT1-R014'],
            ],
            'ENT-2' => [
                'title' => 'Database Performance Contract',
                'canonical_scope' => 'governance_config_docs_lock',
                'runtime_backfill_required' => false,
                'roadmap_docs_keys' => ['contract_doc', 'hotspot_inventory_doc'],
                'required_docs' => [
                    'docs/architecture/database-performance-contract.md',
                    'docs/architecture/database-performance-hotspot-inventory.md',
                ],
                'doc_markers' => ['DBPERF-R001', 'DBPERF-R014'],
            ],
            'ENT-3' => [
                'title' => 'Reporting Materialized Summary Expansion',
                'canonical_scope' => 'governance_config_docs_lock',
                'runtime_backfill_required' => false,
                'roadmap_docs_keys' => ['contract_doc', 'candidate_inventory_doc'],
                'required_docs' => [
                    'docs/architecture/reporting-materialized-summary-contract.md',
                    'docs/architecture/reporting-summary-candidate-inventory.md',
                ],
                'doc_markers' => ['RPTSUM-R001', 'RPTSUM-R016'],
            ],
            'ENT-4' => [
                'title' => 'Redis Cache Enterprise Policy',
                'canonical_scope' => 'governance_config_docs_lock',
                'runtime_backfill_required' => false,
                'roadmap_docs_keys' => ['policy_doc'],
                'required_docs' => [
                    'docs/architecture/redis-cache-enterprise-policy.md',
                    'docs/architecture/cache-ttl-matrix.md',
                    'docs/architecture/cache-invalidation-matrix.md',
                ],
                'doc_markers' => ['CACHE-R001', 'CACHE-R018'],
            ],
        ],
        // Each audited ENT sprint must be completed + GO-tagged in the roadmap.
        'require_completed_status' => true,
        'require_go_tag' => true,
    ],

    /*
     * QUEUE WORKER RUNTIME (builds on ENT-5 queue governance)
     *
     * Conservative, single-process worker. The worker is worker-READY: it is
     * defined and governed here and installed on the VPS via the systemd unit,
     * but the deploy script NEVER starts it (ENT-5 no_long_running_worker_started_by_deploy).
     */
    'queue_worker' => [
        // The worker is allowed to be activated only when the environment's
        // queue connection is a real broker-backed connection (never sync).
        'forbidden_connections_when_enabled' => ['sync'],
        // Conservative worker parameters. These must be reflected in the systemd unit.
        'expected_options' => [
            'sleep' => 3,
            'tries' => 3,
            'timeout' => 120,
            'memory' => 256,
            'max_processes' => 1,
        ],
        // Approved queue names must be a subset of ENT-5 allowed_queue_names.
        'approved_queues' => ['default', 'reports', 'notifications', 'maintenance'],
        // The systemd unit file the runtime scanner validates.
        'service_file' => 'deploy/systemd/daengtisiams-queue-worker.service',
        'service_name' => 'daengtisiams-queue-worker.service',
        'working_directory' => '/var/www/asia-dental-lab-v2',
        'service_user' => 'www-data',
        // Markers the unit MUST contain (safe command + restart-safety + working dir).
        'required_service_markers' => [
            'artisan queue:work',
            '--sleep=3',
            '--tries=3',
            '--timeout=120',
            '--max-time=',
            'Restart=',
            'WorkingDirectory=/var/www/asia-dental-lab-v2',
            'User=www-data',
        ],
        // The unit must run queue:work (NOT queue:listen) and never a destructive
        // queue command. Patterns live here so no script/unit carries them inline.
        'required_worker_command' => 'artisan queue:work',
        'forbidden_worker_markers' => [
            'queue:listen',
            'queue:flush',
            'queue:clear',
            '--daemon',
        ],
        // Deploy must gracefully signal the running worker to restart after code
        // deploy (Laravel graceful restart), not kill mid-job.
        'graceful_restart_marker' => 'php artisan queue:restart',
        // Optional harmless end-to-end smoke.
        'smoke_command' => 'foundation:queue-worker-smoke',
        'smoke_job' => 'App\\Jobs\\Foundation\\QueueWorkerSmokeJob',
        'runbook_doc' => 'docs/runbooks/queue-worker-activation-runbook.md',
        // Worker activation is a post-deploy, operator-run step — NOT a pre-deploy gate.
        'activated_by_deploy' => false,
    ],

    /*
     * DEPLOY EVIDENCE TIMEOUT HARDENING
     *
     * ENT-16's deploy hit an SSH broken pipe during the slow VPS evidence-capture
     * phase. The fix is a server-side detached runner: the long deploy+evidence
     * phase runs under setsid/nohup writing a log + a status file, so the run
     * outlives the SSH pipe. Deploy is never reported GO before the mandatory gates
     * actually pass — the status file captures the real final exit code.
     */
    'deploy_evidence_timeout' => [
        'runner_script' => 'scripts/deploy-vps-runner.sh',
        'deploy_script' => 'scripts/deploy-vps.sh',
        // The runner must detach the long phase and persist a log + status file.
        'required_runner_markers' => [
            'set -euo pipefail',
            'nohup',
            'setsid',
            'DEPLOY_STATUS_FILE',
            'DEPLOY_LOG_FILE',
            'scripts/deploy-vps.sh',
        ],
        // The runner must record the real exit code so a broken SSH pipe cannot
        // be mistaken for success.
        'required_status_markers' => [
            'echo "exit=',
            'wait',
        ],
        // The deploy script must keep the closed-baseline safety posture.
        'deploy_script_required_markers' => [
            'set -euo pipefail',
            'pg_dump',
            'test -s',
            'php artisan migrate --force',
            'php artisan route:clear',
            'php artisan config:clear',
            'DEPLOY OK',
        ],
        // Destructive / production-endangering literals that must never appear in
        // the runner or deploy script. Config-not-code.
        'forbidden_destructive_patterns' => [
            'migrate:fresh',
            'migrate:reset',
            'db:wipe',
            'schema:drop',
        ],
        'runbook_doc' => 'docs/runbooks/vps-deploy-evidence-timeout-recovery-runbook.md',
    ],

    // Release-evidence artifacts (declared OPTIONAL in the ci/vps profiles so a
    // missing artifact never breaks the existing required evidence chain, while
    // the deploy/CI scripts still capture them for auditability). The scanner
    // accepts membership in required OR optional.
    'evidence' => [
        'artifacts' => [
            'ent_1_4_audit' => 'ent-1-4-audit-check.json',
            'queue_worker_runtime' => 'queue-worker-runtime-check.json',
            'runtime_hardening' => 'runtime-hardening-check.json',
        ],
        'required_in_profiles' => ['ci', 'vps'],
    ],

    // Informational governance section published into the foundation summary.
    'governance_section' => 'enterprise_foundation_runtime_hardening_governance',

    'policy_doc' => 'docs/architecture/post-enterprise-foundation-runtime-hardening-governance.md',
];
