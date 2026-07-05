<?php

namespace App\Services\Foundation;

use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * DBPERF-2 — Read-only PgBouncer readiness & PostgreSQL runtime tuning
 * governance.
 *
 * Validates config/postgres_runtime_governance.php completeness, validates
 * the four foundation.db.pg_bouncer_ and postgres_runtime_ feature flags
 * exist and are safely configured, detects whether the app appears to already
 * route through PgBouncer (DB_PORT 6432 / host heuristic), and optionally
 * reads safe PostgreSQL runtime settings + connection stats and probes for a
 * local PgBouncer install/service/listener.
 *
 * Emits GO / WATCH / FAIL:
 *  - GO    : governance config valid, flags safe, no cutover detected,
 *            runtime audit safe (or not_applicable on non-pgsql).
 *  - WATCH : PgBouncer probe not requested/not installed while cutover is
 *            disabled, or non-pgsql connection (config-only checks apply).
 *  - FAIL  : config missing/incomplete, runtime apply flag enabled, cutover
 *            flag enabled without proof, or app appears to route through
 *            PgBouncer without approval evidence.
 */
class PostgresRuntimeGovernanceService
{
    private const REQUIRED_FLAGS = [
        'foundation.db.pg_bouncer_readiness',
        'foundation.db.pg_bouncer_cutover_enabled',
        'foundation.db.postgres_runtime_tuning_recommendations',
        'foundation.db.postgres_runtime_apply_enabled',
    ];

    public function __construct(private readonly FeatureFlagService $flags) {}

    /**
     * @return array<string, mixed>
     */
    public function collect(bool $includeDbStats = false, bool $includePgBouncerProbe = false): array
    {
        $config = config('postgres_runtime_governance');

        if (! is_array($config) || $config === []) {
            return $this->finalize([], [
                $this->fail('PGRUNTIME-CONFIG-EXISTS', 'config/postgres_runtime_governance.php is missing or empty.'),
            ], includeDbStats: $includeDbStats, includePgBouncerProbe: $includePgBouncerProbe);
        }

        $checks = [];
        $checks[] = $this->pass('PGRUNTIME-CONFIG-EXISTS', 'postgres_runtime_governance config present and non-empty.');

        $metadata = (array) ($config['metadata'] ?? []);
        $checks[] = ($metadata['sprint'] ?? '') === 'DBPERF-2' && ($metadata['status'] ?? '') === 'implemented'
            ? $this->pass('PGRUNTIME-METADATA', 'DBPERF-2 metadata present with implemented status.')
            : $this->fail('PGRUNTIME-METADATA', 'DBPERF-2 metadata missing or incomplete.');

        $globalRules = (array) ($config['global_rules'] ?? []);
        $requiredRules = [
            'no_production_cutover_without_approval',
            'no_env_db_host_port_change_in_dbperf_2',
            'no_postgresql_conf_change_in_dbperf_2',
            'no_postgres_restart_without_approval',
            'no_heavy_load_test_on_vps',
            'no_secret_in_runtime_artifacts',
            'runtime_evidence_required',
            'rollback_plan_required_before_cutover',
            'pool_mode_must_be_declared',
            'transaction_pooling_requires_app_compatibility_check',
            'session_features_must_be_audited',
            'prepared_statement_behavior_must_be_documented',
            'migration_connections_must_not_use_transaction_pooler',
            'queue_workers_must_have_connection_pool_policy_before_enablement',
        ];
        $missingRules = array_filter($requiredRules, fn (string $rule) => ! ($globalRules[$rule] ?? false));
        $checks[] = $missingRules === []
            ? $this->pass('PGRUNTIME-GLOBAL-RULES', 'All global PgBouncer/runtime safety rules are enabled.')
            : $this->fail('PGRUNTIME-GLOBAL-RULES', 'Missing/disabled global rules: '.implode(', ', $missingRules));

        $denied = (array) ($config['denied_actions'] ?? []);
        $checks[] = $denied !== []
            ? $this->pass('PGRUNTIME-DENIED-ACTIONS', count($denied).' denied actions documented.')
            : $this->fail('PGRUNTIME-DENIED-ACTIONS', 'No denied actions defined.');

        [$flagChecks, $flags] = $this->auditFlags();
        array_push($checks, ...$flagChecks);

        [$cutoverChecks, $cutoverDetection] = $this->auditAppCutoverDetection($flags);
        array_push($checks, ...$cutoverChecks);

        $driver = (string) config('database.default');
        $isPgsql = $this->connectionDriver() === 'pgsql';

        $dbStats = null;
        if ($includeDbStats) {
            if (! $isPgsql) {
                $checks[] = $this->warn('PGRUNTIME-DB-STATS', "Skipped: active connection driver is not pgsql (driver={$driver}).");
            } else {
                $dbStats = $this->readRuntimeSettings();
                $checks[] = $this->pass('PGRUNTIME-DB-STATS', 'PostgreSQL runtime settings and connection stats read successfully.');
            }
        } else {
            $checks[] = $this->pass('PGRUNTIME-DB-STATS-SKIPPED', 'DB stats skipped (not requested); normal GO does not require PostgreSQL introspection.');
        }

        $pgBouncerProbe = null;
        if ($includePgBouncerProbe) {
            $pgBouncerProbe = $this->probePgBouncer();
            $installed = $pgBouncerProbe['installed'] ?? false;
            $running = $pgBouncerProbe['listener_active'] ?? false;

            $cutoverEnabled = $flags['foundation.db.pg_bouncer_cutover_enabled']['enabled'] ?? false;

            if ($cutoverEnabled && (! $installed || ! $running)) {
                $checks[] = $this->fail('PGRUNTIME-PGBOUNCER-PROBE', 'PgBouncer cutover flag is enabled but the probe found PgBouncer not installed/running.');
            } elseif (! $installed || ! $running) {
                $checks[] = $this->warn('PGRUNTIME-PGBOUNCER-PROBE', 'PgBouncer not installed/running — non-blocking because production routing/cutover is disabled.');
            } else {
                $checks[] = $this->pass('PGRUNTIME-PGBOUNCER-PROBE', 'PgBouncer probe found an installed/running instance (readiness only — not routed to production traffic).');
            }
        } else {
            $checks[] = $this->pass('PGRUNTIME-PGBOUNCER-PROBE-SKIPPED', 'PgBouncer probe skipped (not requested); GO does not require PgBouncer installed in DBPERF-2.');
        }

        $recommendations = $this->buildRecommendations($dbStats);

        return $this->finalize(
            $config,
            $checks,
            includeDbStats: $includeDbStats,
            includePgBouncerProbe: $includePgBouncerProbe,
            dbStats: $dbStats,
            pgBouncerProbe: $pgBouncerProbe,
            flags: $flags,
            cutoverDetection: $cutoverDetection,
            recommendations: $recommendations,
        );
    }

    /**
     * @return array{0: list<array<string, mixed>>, 1: array<string, array<string, mixed>>}
     */
    private function auditFlags(): array
    {
        $checks = [];
        $flags = [];
        $missing = [];
        $unsafe = [];

        foreach (self::REQUIRED_FLAGS as $key) {
            try {
                $flag = $this->flags->get($key);
                $flags[$key] = $flag;
            } catch (Throwable) {
                $missing[] = $key;

                continue;
            }
        }

        $checks[] = $missing === []
            ? $this->pass('PGRUNTIME-FLAGS-EXIST', 'All four PgBouncer/runtime feature flags are registered.')
            : $this->fail('PGRUNTIME-FLAGS-EXIST', 'Missing required feature flag(s): '.implode(', ', $missing));

        if (($flags['foundation.db.pg_bouncer_cutover_enabled']['enabled'] ?? false) === true) {
            $unsafe[] = 'foundation.db.pg_bouncer_cutover_enabled';
        }
        if (($flags['foundation.db.postgres_runtime_apply_enabled']['enabled'] ?? false) === true) {
            $unsafe[] = 'foundation.db.postgres_runtime_apply_enabled';
        }

        $checks[] = $unsafe === []
            ? $this->pass('PGRUNTIME-FLAGS-SAFE-STATE', 'Cutover and runtime-apply flags are both disabled.')
            : $this->fail('PGRUNTIME-FLAGS-SAFE-STATE', 'Unsafe flag(s) enabled in DBPERF-2: '.implode(', ', $unsafe));

        return [$checks, $flags];
    }

    /**
     * Detects whether the app's own DB connection config already appears to
     * route through PgBouncer (DB_PORT 6432 or a host name containing
     * "pgbouncer"). Never prints DB_PASSWORD.
     *
     * @param  array<string, array<string, mixed>>  $flags
     * @return array{0: list<array<string, mixed>>, 1: array<string, mixed>}
     */
    private function auditAppCutoverDetection(array $flags): array
    {
        $checks = [];

        $connectionName = (string) config('database.default');
        $connectionConfig = (array) config("database.connections.{$connectionName}", []);
        $host = (string) ($connectionConfig['host'] ?? '');
        $port = (string) ($connectionConfig['port'] ?? '');

        $safePilotPort = (string) config('postgres_runtime_governance.pgbouncer_readiness.safe_pilot_port', '6432');
        $looksLikeCutover = $port === $safePilotPort || str_contains(strtolower($host), 'pgbouncer');

        $detection = [
            'potential_cutover' => $looksLikeCutover,
            'port' => $port,
            'host_hint_present' => str_contains(strtolower($host), 'pgbouncer'),
            'expected' => 'direct_postgresql',
        ];

        $cutoverEnabled = $flags['foundation.db.pg_bouncer_cutover_enabled']['enabled'] ?? false;

        if ($looksLikeCutover && ! $cutoverEnabled) {
            $checks[] = $this->fail('PGRUNTIME-APP-CUTOVER-DETECTION', 'App DB connection appears to route through PgBouncer (port/host heuristic) but the cutover flag is not enabled/approved.');
        } elseif ($looksLikeCutover && $cutoverEnabled) {
            $checks[] = $this->warn('PGRUNTIME-APP-CUTOVER-DETECTION', 'App DB connection appears to route through PgBouncer and the cutover flag is enabled — verify rollback plan/approval evidence exists.');
        } else {
            $checks[] = $this->pass('PGRUNTIME-APP-CUTOVER-DETECTION', 'App DB connection is direct to PostgreSQL as expected in DBPERF-2 (no PgBouncer routing detected).');
        }

        return [$checks, $detection];
    }

    private function connectionDriver(): string
    {
        try {
            return DB::connection()->getDriverName();
        } catch (Throwable) {
            return (string) config('database.default');
        }
    }

    /**
     * Safe, read-only PostgreSQL runtime settings + sanitized connection
     * counts. Never selects application row data, never includes query text.
     *
     * @return array<string, mixed>
     */
    private function readRuntimeSettings(): array
    {
        $settingNames = (array) config('postgres_runtime_governance.postgres_runtime_audit.settings', []);
        $settings = [];

        foreach ($settingNames as $name) {
            try {
                $rows = DB::select('SHOW '.$name);
                $value = $rows !== [] ? (string) array_values((array) $rows[0])[0] : null;
                $settings[$name] = $value;
            } catch (Throwable) {
                $settings[$name] = null;
            }
        }

        try {
            $rows = DB::select("SELECT 1 FROM pg_extension WHERE extname = 'pg_stat_statements'");
            $settings['pg_stat_statements_available'] = $rows !== [];
        } catch (Throwable) {
            $settings['pg_stat_statements_available'] = false;
        }

        $connectionStats = ['active' => 0, 'idle' => 0, 'idle_in_transaction' => 0, 'total' => 0];
        try {
            $rows = DB::select('
                SELECT state, count(*) AS n
                FROM pg_stat_activity
                WHERE datname = current_database()
                GROUP BY state
            ');
            foreach ($rows as $row) {
                $state = (string) ($row->state ?? '');
                $n = (int) $row->n;
                $connectionStats['total'] += $n;
                match (true) {
                    $state === 'active' => $connectionStats['active'] += $n,
                    $state === 'idle' => $connectionStats['idle'] += $n,
                    $state === 'idle in transaction' || $state === 'idle in transaction (aborted)' => $connectionStats['idle_in_transaction'] += $n,
                    default => null,
                };
            }
        } catch (Throwable) {
            // pg_stat_activity unreadable in this environment — leave zeroed, non-fatal.
        }

        return [
            'settings' => $settings,
            'connection_stats' => $connectionStats,
        ];
    }

    /**
     * Detect a local PgBouncer install/service/listener without requiring
     * one to be present. Never prints admin credentials.
     *
     * @return array<string, mixed>
     */
    private function probePgBouncer(): array
    {
        $binaryPresent = false;
        $binaryPath = @shell_exec('command -v pgbouncer 2>/dev/null');
        if (is_string($binaryPath) && trim($binaryPath) !== '') {
            $binaryPresent = true;
        }

        $serviceActive = null;
        $systemctlOutput = @shell_exec('systemctl is-active pgbouncer 2>/dev/null');
        if (is_string($systemctlOutput)) {
            $trimmed = trim($systemctlOutput);
            $serviceActive = $trimmed !== '' ? $trimmed === 'active' : null;
        }

        $port = (int) config('postgres_runtime_governance.pgbouncer_readiness.safe_pilot_port', 6432);
        $listenerActive = false;
        $portCheck = @shell_exec("timeout 1 bash -c 'echo > /dev/tcp/127.0.0.1/{$port}' 2>/dev/null; echo $?");
        if (is_string($portCheck) && trim($portCheck) === '0') {
            $listenerActive = true;
        }

        return [
            'binary_present' => $binaryPresent,
            'service_active' => $serviceActive,
            'listener_active' => $listenerActive || ($serviceActive === true),
            'installed' => $binaryPresent || $serviceActive !== null,
            'probed_port' => $port,
        ];
    }

    /**
     * Read-only tuning recommendations. Never applies anything. If server
     * RAM is unknown, sizing-dependent settings are classified as
     * requires_capacity_baseline rather than given a blind suggested value.
     *
     * @param  array<string, mixed>|null  $dbStats
     * @return list<array<string, mixed>>
     */
    private function buildRecommendations(?array $dbStats): array
    {
        $settings = $dbStats['settings'] ?? [];
        $ramKnown = false; // DBPERF-2 does not read host RAM; sizing stays capacity-baseline pending.

        $sizingDependent = ['shared_buffers', 'effective_cache_size', 'work_mem', 'maintenance_work_mem', 'max_connections'];

        $catalog = [
            'max_connections' => ['rationale' => 'Must be sized against PgBouncer pool + app + migration + report connections combined.', 'restart_required' => true],
            'shared_buffers' => ['rationale' => 'Typically ~25% of RAM; requires a known RAM baseline before suggesting a value.', 'restart_required' => true],
            'effective_cache_size' => ['rationale' => 'Typically ~50-75% of RAM; requires a known RAM baseline.', 'restart_required' => false],
            'work_mem' => ['rationale' => 'Per-sort/hash operation memory; oversizing risks OOM under concurrent connections.', 'restart_required' => false],
            'maintenance_work_mem' => ['rationale' => 'Used by VACUUM/CREATE INDEX; safe to raise moderately without RAM baseline.', 'restart_required' => false],
            'checkpoint_timeout' => ['rationale' => 'Longer intervals reduce checkpoint I/O spikes at the cost of longer recovery time.', 'restart_required' => false],
            'max_wal_size' => ['rationale' => 'Raising reduces checkpoint frequency under write-heavy load.', 'restart_required' => false],
            'statement_timeout' => ['rationale' => 'Not currently enforced; a conservative timeout protects against runaway queries.', 'restart_required' => false],
            'idle_in_transaction_session_timeout' => ['rationale' => 'Not currently enforced; protects PgBouncer transaction pooling from held-open transactions.', 'restart_required' => false],
            'log_min_duration_statement' => ['rationale' => 'Enabling slow-query logging aids future DBPERF audits without exposing query text at INFO level here.', 'restart_required' => false],
            'track_io_timing' => ['rationale' => 'Small overhead, improves EXPLAIN (ANALYZE, BUFFERS) I/O timing visibility for future audits.', 'restart_required' => false],
            'shared_preload_libraries' => ['rationale' => 'pg_stat_statements requires this to be preloaded; only actionable in an approved maintenance window (restart required).', 'restart_required' => true],
        ];

        $recommendations = [];
        foreach ($catalog as $setting => $meta) {
            $current = $settings[$setting] ?? null;

            $classification = in_array($setting, $sizingDependent, true) && ! $ramKnown
                ? 'requires_capacity_baseline'
                : ($meta['restart_required'] ? 'requires_maintenance_window' : 'safe_documentation_only');

            $recommendations[] = [
                'setting' => $setting,
                'current_value' => $current,
                'recommendation' => $current === null
                    ? 'Not read in this environment (introspection not requested or unavailable).'
                    : $meta['rationale'],
                'classification' => $classification,
                'restart_required' => $meta['restart_required'],
                'risk' => $meta['restart_required'] ? 'medium' : 'low',
                'rollback_note' => $meta['restart_required']
                    ? 'Revert via ALTER SYSTEM RESET <setting>; then RELOAD or restart depending on parameter context (requires approval).'
                    : 'Revert via ALTER SYSTEM RESET <setting>; then SELECT pg_reload_conf() (requires approval).',
                'next_action' => $classification === 'requires_capacity_baseline'
                    ? 'Collect host RAM/CPU baseline before proposing a concrete value.'
                    : 'Review in a future approved maintenance window; no automatic apply in DBPERF-2.',
            ];
        }

        return $recommendations;
    }

    /**
     * @param  list<array<string, mixed>>  $checks
     * @param  array<string, mixed>|null  $dbStats
     * @param  array<string, mixed>|null  $pgBouncerProbe
     * @param  array<string, array<string, mixed>>  $flags
     * @param  array<string, mixed>|null  $cutoverDetection
     * @param  list<array<string, mixed>>  $recommendations
     * @return array<string, mixed>
     */
    private function finalize(
        array $config,
        array $checks,
        bool $includeDbStats,
        bool $includePgBouncerProbe,
        ?array $dbStats = null,
        ?array $pgBouncerProbe = null,
        array $flags = [],
        ?array $cutoverDetection = null,
        array $recommendations = [],
    ): array {
        $errors = count(array_filter($checks, fn (array $c) => ($c['status'] ?? '') === 'failed'));
        $warnings = count(array_filter($checks, fn (array $c) => ($c['status'] ?? '') === 'warning'));
        $passed = count(array_filter($checks, fn (array $c) => ($c['status'] ?? '') === 'passed'));

        $decision = $errors > 0 ? 'FAIL' : ($warnings > 0 ? 'WATCH' : 'GO');

        return [
            'generated_at' => now()->toIso8601String(),
            'sprint' => 'DBPERF-2',
            'environment' => (string) config('app.env'),
            'db_driver' => $this->connectionDriver(),
            'metadata' => $config['metadata'] ?? [],
            'global_rules' => $config['global_rules'] ?? [],
            'flags' => collect($flags)->map(fn (array $f) => [
                'key' => $f['key'] ?? null,
                'enabled' => $f['enabled'] ?? false,
                'default' => $f['default'] ?? false,
                'risk_level' => $f['risk_level'] ?? null,
                'rollout_status' => $f['rollout_status'] ?? null,
            ])->all(),
            'app_cutover_detection' => $cutoverDetection,
            'db_stats_requested' => $includeDbStats,
            'db_stats' => $dbStats,
            'pgbouncer_probe_requested' => $includePgBouncerProbe,
            'pgbouncer_probe' => $pgBouncerProbe,
            'recommendations' => $recommendations,
            'checks' => $checks,
            'summary' => [
                'decision' => $decision,
                'checks' => count($checks),
                'passed' => $passed,
                'warnings' => $warnings,
                'errors' => $errors,
            ],
            'privacy' => ['privacy_safe' => true, 'row_level_data' => false],
        ];
    }

    private function pass(string $id, string $message): array
    {
        return ['check_id' => $id, 'status' => 'passed', 'blocking' => false, 'message' => $message];
    }

    private function warn(string $id, string $message): array
    {
        return ['check_id' => $id, 'status' => 'warning', 'blocking' => false, 'message' => $message];
    }

    private function fail(string $id, string $message): array
    {
        return ['check_id' => $id, 'status' => 'failed', 'blocking' => true, 'message' => $message];
    }
}
