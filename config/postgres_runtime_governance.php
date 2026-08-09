<?php

/**
 * DBPERF-2 — PgBouncer readiness & PostgreSQL runtime tuning governance.
 *
 * Read-only registry of PgBouncer connection-pooling readiness rules, the
 * PostgreSQL runtime settings this sprint audits, the app-compatibility
 * checklist required before any pooling cutover, and the GO/WATCH/NO-GO
 * criteria for foundation:postgres-runtime-check.
 *
 * This config never runs anything itself — it only declares policy so
 * App\Services\Foundation\PostgresRuntimeGovernanceService and
 * foundation:postgres-runtime-check can validate against it, and so the
 * deploy script/CI workflow have a single source of truth to check.
 *
 * DBPERF-2 is readiness/audit-first: no PgBouncer production cutover, no
 * DB_HOST/DB_PORT change, no postgresql.conf edit, no PostgreSQL restart,
 * and no automatic tuning apply happen in this sprint. Everything here is
 * detection, recommendation, and rollback-plan documentation only.
 */
return [
    'metadata' => [
        'sprint' => 'DBPERF-2',
        'status' => 'implemented',
        'owner' => 'Foundation',
        'decision' => 'pgbouncer_readiness_and_runtime_audit_only',
        'production_cutover' => false,
    ],

    'global_rules' => [
        'no_production_cutover_without_approval' => true,
        'no_env_db_host_port_change_in_dbperf_2' => true,
        'no_postgresql_conf_change_in_dbperf_2' => true,
        'no_postgres_restart_without_approval' => true,
        'no_heavy_load_test_on_vps' => true,
        'no_secret_in_runtime_artifacts' => true,
        'runtime_evidence_required' => true,
        'rollback_plan_required_before_cutover' => true,
        'pool_mode_must_be_declared' => true,
        'transaction_pooling_requires_app_compatibility_check' => true,
        'session_features_must_be_audited' => true,
        'prepared_statement_behavior_must_be_documented' => true,
        'migration_connections_must_not_use_transaction_pooler' => true,
        'queue_workers_must_have_connection_pool_policy_before_enablement' => true,
    ],

    'pgbouncer_readiness' => [
        'production_routing_enabled' => false,
        'install_required_for_go' => false,
        'service_required_for_go' => false,
        'app_cutover_allowed' => false,
        'default_pool_mode_candidate' => 'transaction',
        'safe_pilot_port' => 6432,
        'required_before_cutover' => [
            'backup',
            'rollback plan',
            'app compatibility audit',
            'migration bypass policy',
            'smoke',
            'connection count baseline',
            'error log watch',
            'explicit owner approval',
        ],
    ],

    'app_compatibility_policy' => [
        'must_audit' => [
            'persistent connections',
            'prepared statements',
            'temp tables',
            'session variables',
            'advisory locks',
            'LISTEN/NOTIFY',
            'transaction usage',
            'migrations',
            'queue workers',
            'Horizon/Supervisor future',
            'long running reports',
            'pg_stat_statements',
            'statement_timeout',
            'idle_in_transaction_session_timeout',
        ],
        // Findings for this DaengtisiaMS codebase as of DBPERF-2 (read-only
        // review — no code changed to reach these conclusions).
        'findings' => [
            'persistent connections' => 'Laravel default (non-persistent per request via PDO); compatible with session or transaction pooling.',
            'prepared statements' => 'Laravel PDO uses emulated prepares by default; native PgBouncer transaction-mode prepared statement caveats are a documented risk, not yet audited against a real pilot connection.',
            'temp tables' => 'No known dependency on session-scoped temp tables in application code as of DBPERF-2; requires re-verification before cutover.',
            'session variables' => 'BranchContext is request/session-scoped in PHP, not a Postgres SET session variable; no known SET-based session state.',
            'advisory locks' => 'No known pg_advisory_lock usage in application code as of DBPERF-2.',
            'LISTEN/NOTIFY' => 'Not used by this application.',
            'transaction usage' => 'DB::transaction() is used extensively (multi-write invariant); transaction pooling is compatible with single-transaction request lifecycles but requires the app not to hold a transaction open across unrelated queries.',
            'migrations' => 'php artisan migrate must bypass the pooler and connect directly to PostgreSQL — DDL and long-lived migration connections are not transaction-pooler safe.',
            'queue workers' => 'No long-running queue worker is enabled (QUEUE-1 governance); connection pool policy must be defined before any worker is enabled.',
            'Horizon/Supervisor future' => 'Not yet adopted; connection pool sizing must be revisited if adopted.',
            'long running reports' => 'Owner KPI dashboard and reporting queries are read-only and short-lived; no known long-held connection.',
            'pg_stat_statements' => 'Extension availability is read via foundation:postgres-runtime-check; not required for GO.',
            'statement_timeout' => 'Not currently set at the role/database level in production; recommendation only in DBPERF-2.',
            'idle_in_transaction_session_timeout' => 'Not currently set at the role/database level in production; recommendation only in DBPERF-2.',
        ],
    ],

    'postgres_runtime_audit' => [
        // Every name here is read with `SHOW <name>`, so every name must be a
        // real PostgreSQL configuration parameter.
        //
        // `version` is NOT one — it is a function, `version()`. `SHOW version`
        // therefore raises SQLSTATE 42704 "unrecognized configuration
        // parameter". Outside a transaction that is harmless (autocommit: the
        // statement fails alone), which is why it survived unnoticed in deploys
        // and CLI runs. Inside a transaction PostgreSQL aborts the ENTIRE
        // transaction and rejects every later statement with SQLSTATE 25P02
        // until rollback — and catching the PDO exception in PHP does not undo
        // that. Every Pest feature test runs inside a RefreshDatabase
        // transaction, so this silently poisoned the rest of the test: the vps
        // evidence capture (the only profile that passes --include-db-stats)
        // then could not produce the required foundation-governance-summary
        // artifact, and the vps release-safety decision was FAIL.
        //
        // `server_version` is the actual parameter, and is what the CI runner
        // health check already probes.
        'settings' => [
            'server_version',
            'max_connections',
            'shared_buffers',
            'effective_cache_size',
            'work_mem',
            'maintenance_work_mem',
            'checkpoint_timeout',
            'max_wal_size',
            'random_page_cost',
            'effective_io_concurrency',
            'default_statistics_target',
            'statement_timeout',
            'idle_in_transaction_session_timeout',
            'lock_timeout',
            'log_min_duration_statement',
            'log_checkpoints',
            'track_io_timing',
            'shared_preload_libraries',
        ],
    ],

    'tuning_recommendation_policy' => [
        'recommendations_only_in_dbperf_2' => true,
        'no_auto_apply' => true,
        'classify' => [
            'safe_documentation_only',
            'requires_staging_test',
            'requires_maintenance_window',
            'requires_restart',
            'not_recommended',
        ],
        'required_fields' => [
            'current_value',
            'suggested_value',
            'rationale',
            'risk',
            'rollback_note',
            'restart_required',
        ],
    ],

    'denied_actions' => [
        'alter_system_set',
        'edit_postgresql_conf',
        'restart_postgresql',
        'route_app_to_pgbouncer',
        'start_long_running_queue_worker',
        'force_redis_queue_runtime',
        'heavy_load_test',
        'destructive_schema_change',
    ],

    'artifact_policy' => [
        'artifacts' => [
            'storage/ci-evidence/postgres-runtime-check.json',
            'storage/release-evidence/latest/postgres-runtime-check.json',
        ],
        'forbidden' => [
            'no_credentials',
            'no_dot_env',
            'no_pii',
            'no_raw_logs',
            'no_full_pg_stat_activity_query_text',
        ],
    ],

    'go_criteria' => [
        'config/postgres_runtime_governance.php is present and complete',
        'no denied_actions detected in this environment',
        'PgBouncer not required to be installed/running for GO',
        'runtime apply feature flag is disabled',
        'PgBouncer cutover feature flag is disabled',
        'pgsql runtime audit reads succeed (or command reports not_applicable on non-pgsql connections)',
    ],

    'watch_criteria' => [
        'PgBouncer probe not run (optional flag not passed) or PgBouncer not installed/running while cutover is disabled',
        'running on a non-pgsql connection (local/CI sqlite) — config-only checks apply',
        'server RAM unknown, so sizing-dependent recommendations stay requires_capacity_baseline',
    ],

    'no_go_criteria' => [
        'PgBouncer production cutover flag/env enabled without proof + rollback plan',
        'PostgreSQL runtime apply flag enabled in DBPERF-2',
        'app .env appears to route DB through PgBouncer without approval evidence',
        'a denied_action (ALTER SYSTEM / restart / routing / heavy load test) is detected',
    ],
];
