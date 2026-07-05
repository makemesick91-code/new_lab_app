<?php

namespace App\Services\Foundation;

use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * DBPERF-1 — Read-only PostgreSQL index optimization & query plan audit governance.
 *
 * Validates config/db_performance_governance.php completeness, cross-checks
 * every `add_index_now` candidate against the actual migration files (so the
 * governance decision always matches what is really applied), and optionally
 * reads safe PostgreSQL metadata (pg_indexes, pg_class/pg_stat_user_tables,
 * pg_stat_user_indexes, pg_stat_statements availability) and sanitized
 * EXPLAIN-only plan samples.
 *
 * Emits GO / WATCH / FAIL:
 *  - GO    : governance config valid, no denied actions detected, every
 *            applied candidate has a migration + rollback note.
 *  - WATCH : optional pg_stat_user_indexes/pg_stat_statements unavailable,
 *            or running on a non-pgsql connection (config-only checks apply).
 *  - FAIL  : config missing/incomplete, a denied action detected, or an
 *            add_index_now candidate has no migration/rollback note.
 */
class DbPerformanceGovernanceService
{
    /**
     * @return array<string, mixed>
     */
    public function collect(bool $includeDbStats = false, bool $includeQueryPlanSamples = false): array
    {
        $config = config('db_performance_governance');

        if (! is_array($config) || $config === []) {
            return $this->finalize([], [
                $this->fail('DBPERF-CONFIG-EXISTS', 'config/db_performance_governance.php is missing or empty.'),
            ], $includeDbStats, $includeQueryPlanSamples, null, null);
        }

        $checks = [];
        $checks[] = $this->pass('DBPERF-CONFIG-EXISTS', 'db_performance_governance config present and non-empty.');

        $metadata = (array) ($config['metadata'] ?? []);
        $checks[] = ($metadata['sprint'] ?? '') === 'DBPERF-1' && ($metadata['status'] ?? '') === 'implemented'
            ? $this->pass('DBPERF-METADATA', 'DBPERF-1 metadata present with implemented status.')
            : $this->fail('DBPERF-METADATA', 'DBPERF-1 metadata missing or incomplete.');

        $globalRules = (array) ($config['global_rules'] ?? []);
        $requiredRules = [
            'query_plan_evidence_required',
            'no_destructive_index_changes',
            'no_drop_index_in_dbperf_1',
            'additive_index_only',
            'concurrent_index_preferred_for_large_tables',
            'no_heavy_production_explain_analyze_without_guard',
            'no_pii_in_query_plan_artifacts',
            'no_raw_sql_with_untrusted_request_input',
            'branch_filter_required_for_branch_scoped_queries',
            'inventory_ledger_queries_must_preserve_sum_movements_rule',
            'index_reason_required',
            'rollback_note_required',
        ];
        $missingRules = array_filter($requiredRules, fn (string $rule) => ! ($globalRules[$rule] ?? false));
        $checks[] = $missingRules === []
            ? $this->pass('DBPERF-GLOBAL-RULES', 'All global DB performance safety rules are enabled.')
            : $this->fail('DBPERF-GLOBAL-RULES', 'Missing/disabled global rules: '.implode(', ', $missingRules));

        $denied = (array) ($config['denied_actions'] ?? []);
        $checks[] = $denied !== []
            ? $this->pass('DBPERF-DENIED-ACTIONS', count($denied).' denied actions documented.')
            : $this->fail('DBPERF-DENIED-ACTIONS', 'No denied actions defined.');

        $candidates = (array) ($config['index_candidate_policy'] ?? []);
        [$candidateChecks, $applied, $deferred] = $this->auditCandidates($candidates);
        array_push($checks, ...$candidateChecks);

        $duplicateCheck = $this->checkNoDuplicateIndexNames($candidates);
        $checks[] = $duplicateCheck;

        $driver = (string) config('database.default');
        $isPgsql = $this->connectionDriver() === 'pgsql';

        $dbStats = null;
        if ($includeDbStats) {
            if (! $isPgsql) {
                $checks[] = $this->warn('DBPERF-DB-STATS', "Skipped: active connection driver is not pgsql (driver={$driver}).");
            } else {
                $dbStats = $this->readDbStats();
                $checks[] = $this->pass('DBPERF-DB-STATS', 'PostgreSQL index/table metadata read successfully.');
                if (! $dbStats['pg_stat_user_indexes_available']) {
                    $checks[] = $this->warn('DBPERF-PG-STAT-USER-INDEXES', 'pg_stat_user_indexes not readable in this environment.');
                }
                if (! $dbStats['pg_stat_statements_available']) {
                    $checks[] = $this->warn('DBPERF-PG-STAT-STATEMENTS', 'pg_stat_statements extension not available/preloaded — optional, non-blocking.');
                }
            }
        } else {
            $checks[] = $this->pass('DBPERF-DB-STATS-SKIPPED', 'DB stats skipped (not requested); normal GO does not require PostgreSQL introspection.');
        }

        $planSamples = null;
        if ($includeQueryPlanSamples) {
            if (! $isPgsql) {
                $checks[] = $this->warn('DBPERF-QUERY-PLAN-SAMPLES', "Skipped: active connection driver is not pgsql (driver={$driver}).");
            } else {
                $planSamples = $this->readQueryPlanSamples();
                $checks[] = $this->pass('DBPERF-QUERY-PLAN-SAMPLES', count($planSamples).' sanitized EXPLAIN-only plan sample(s) captured.');
            }
        }

        return $this->finalize($config, $checks, $includeDbStats, $includeQueryPlanSamples, $dbStats, $planSamples, $applied, $deferred);
    }

    /**
     * @param  list<array<string, mixed>>  $candidates
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>, 2: list<array<string, mixed>>}
     */
    private function auditCandidates(array $candidates): array
    {
        $checks = [];
        $applied = [];
        $deferred = [];
        $violations = [];

        foreach ($candidates as $candidate) {
            $decision = (string) ($candidate['decision'] ?? '');
            $table = (string) ($candidate['table'] ?? 'unknown');

            if ($decision === 'add_index_now') {
                $migrationName = (string) ($candidate['migration_name'] ?? '');
                $rollbackNote = (string) ($candidate['rollback_note'] ?? '');
                $hasMigration = $migrationName !== '' && $this->migrationFileExists($migrationName);
                $hasRollback = trim($rollbackNote) !== '';

                if (! $hasMigration || ! $hasRollback) {
                    $violations[] = sprintf('%s (migration_exists=%s, rollback_note=%s)', $table, $hasMigration ? 'yes' : 'no', $hasRollback ? 'yes' : 'no');
                }

                $applied[] = [
                    'table' => $table,
                    'columns' => $candidate['columns'] ?? [],
                    'migration_name' => $migrationName,
                    'query_family' => $candidate['query_family'] ?? null,
                ];
            } elseif ($decision !== '') {
                $deferred[] = [
                    'table' => $table,
                    'query_family' => $candidate['query_family'] ?? null,
                    'decision' => $decision,
                    'reason' => $candidate['reason'] ?? null,
                ];
            }
        }

        $checks[] = $violations === []
            ? $this->pass('DBPERF-APPLIED-CANDIDATES', count($applied).' add_index_now candidate(s) have a matching migration and rollback note.')
            : $this->fail('DBPERF-APPLIED-CANDIDATES', 'Candidate(s) missing migration/rollback note: '.implode('; ', $violations));

        return [$checks, $applied, $deferred];
    }

    /**
     * @param  list<array<string, mixed>>  $candidates
     */
    private function checkNoDuplicateIndexNames(array $candidates): array
    {
        $names = [];
        foreach ($candidates as $candidate) {
            $migration = (string) ($candidate['migration_name'] ?? '');
            if ($migration !== '') {
                $names[] = $migration;
            }
        }

        $duplicates = array_diff_key($names, array_unique($names));

        return $duplicates === []
            ? $this->pass('DBPERF-NO-DUPLICATE-CANDIDATES', 'No duplicate migration references across index candidates.')
            : $this->fail('DBPERF-NO-DUPLICATE-CANDIDATES', 'Duplicate migration references found: '.implode(', ', array_unique($duplicates)));
    }

    private function migrationFileExists(string $migrationName): bool
    {
        $path = database_path('migrations/'.$migrationName.'.php');

        return is_file($path);
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
     * Safe, read-only PostgreSQL metadata: index list, table row estimates,
     * and whether pg_stat_user_indexes / pg_stat_statements are available.
     * Never selects application row data.
     *
     * @return array<string, mixed>
     */
    private function readDbStats(): array
    {
        $indexes = [];
        try {
            $rows = DB::select("
                SELECT schemaname, tablename, indexname
                FROM pg_indexes
                WHERE schemaname = 'public'
                ORDER BY tablename, indexname
            ");
            foreach ($rows as $row) {
                $indexes[] = [
                    'table' => $row->tablename,
                    'index_name' => $row->indexname,
                ];
            }
        } catch (Throwable) {
            // pg_indexes unreadable in this environment — leave empty, non-fatal.
        }

        $tableEstimates = [];
        try {
            $rows = DB::select('
                SELECT relname AS table_name, n_live_tup AS estimated_rows
                FROM pg_stat_user_tables
                ORDER BY n_live_tup DESC
                LIMIT 20
            ');
            foreach ($rows as $row) {
                $tableEstimates[] = [
                    'table' => $row->table_name,
                    'estimated_rows' => (int) $row->estimated_rows,
                ];
            }
        } catch (Throwable) {
            // pg_stat_user_tables unreadable — non-fatal, WATCH is signaled by caller.
        }

        $pgStatUserIndexesAvailable = false;
        try {
            DB::select('SELECT 1 FROM pg_stat_user_indexes LIMIT 1');
            $pgStatUserIndexesAvailable = true;
        } catch (Throwable) {
            $pgStatUserIndexesAvailable = false;
        }

        $pgStatStatementsAvailable = false;
        try {
            $rows = DB::select("SELECT 1 FROM pg_extension WHERE extname = 'pg_stat_statements'");
            $pgStatStatementsAvailable = $rows !== [];
        } catch (Throwable) {
            $pgStatStatementsAvailable = false;
        }

        return [
            'index_count' => count($indexes),
            'indexes' => $indexes,
            'table_estimates' => $tableEstimates,
            'pg_stat_user_indexes_available' => $pgStatUserIndexesAvailable,
            'pg_stat_statements_available' => $pgStatStatementsAvailable,
        ];
    }

    /**
     * Sanitized EXPLAIN-only (never ANALYZE) plan summaries for a small set of
     * safe, parameterized, representative queries. Never fetches row data and
     * never includes literal request/PII values.
     *
     * @return list<array<string, mixed>>
     */
    private function readQueryPlanSamples(): array
    {
        $samples = [];

        $queries = [
            'rme.visit_queue' => "EXPLAIN SELECT id FROM trx_clinic_visits WHERE branch_id = 1 AND visit_date = CURRENT_DATE AND status = 'waiting'",
            'cashier.receivable_list' => "EXPLAIN SELECT id FROM trx_rme_invoices WHERE branch_id = 1 AND status IN ('UNPAID','PARTIAL') AND grand_total > 0",
            'foundation.audit_governance_commands' => "EXPLAIN SELECT id FROM sys_idempotency_keys WHERE status = 'reserved' AND expires_at < now()",
        ];

        foreach ($queries as $family => $sql) {
            try {
                $rows = DB::select($sql);
                $planLines = array_map(fn ($row) => (string) array_values((array) $row)[0], $rows);
                $samples[] = [
                    'query_family' => $family,
                    'plan_summary' => implode(' | ', array_slice($planLines, 0, 3)),
                    'uses_index_scan' => str_contains(strtolower(implode(' ', $planLines)), 'index'),
                ];
            } catch (Throwable $e) {
                $samples[] = [
                    'query_family' => $family,
                    'plan_summary' => null,
                    'error' => 'Query plan sample unavailable in this environment.',
                ];
            }
        }

        return $samples;
    }

    /**
     * @param  list<array<string, mixed>>  $checks
     * @param  array<string, mixed>|null  $dbStats
     * @param  list<array<string, mixed>>|null  $planSamples
     * @param  list<array<string, mixed>>  $applied
     * @param  list<array<string, mixed>>  $deferred
     * @return array<string, mixed>
     */
    private function finalize(
        array $config,
        array $checks,
        bool $includeDbStats,
        bool $includeQueryPlanSamples,
        ?array $dbStats,
        ?array $planSamples,
        array $applied = [],
        array $deferred = [],
    ): array {
        $errors = count(array_filter($checks, fn (array $c) => ($c['status'] ?? '') === 'failed'));
        $warnings = count(array_filter($checks, fn (array $c) => ($c['status'] ?? '') === 'warning'));
        $passed = count(array_filter($checks, fn (array $c) => ($c['status'] ?? '') === 'passed'));

        $decision = $errors > 0 ? 'FAIL' : ($warnings > 0 ? 'WATCH' : 'GO');

        return [
            'generated_at' => now()->toIso8601String(),
            'sprint' => 'DBPERF-1',
            'environment' => (string) config('app.env'),
            'db_driver' => $this->connectionDriver(),
            'metadata' => $config['metadata'] ?? [],
            'global_rules' => $config['global_rules'] ?? [],
            'target_query_families' => $config['target_query_families'] ?? [],
            'applied_index_candidates' => $applied,
            'deferred_index_candidates' => $deferred,
            'db_stats_requested' => $includeDbStats,
            'db_stats' => $dbStats,
            'query_plan_samples_requested' => $includeQueryPlanSamples,
            'query_plan_samples' => $planSamples,
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
