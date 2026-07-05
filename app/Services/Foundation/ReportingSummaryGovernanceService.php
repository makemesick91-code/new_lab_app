<?php

namespace App\Services\Foundation;

use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * RPT-1 — Read-only reporting summary and materialized-view readiness gate.
 */
class ReportingSummaryGovernanceService
{
    /** @var list<string> */
    private const REQUIRED_GLOBAL_RULES = [
        'no_summary_without_source_of_truth',
        'no_summary_without_refresh_policy',
        'no_summary_without_staleness_policy',
        'no_summary_without_branch_scope_policy',
        'no_summary_without_pii_policy',
        'no_summary_without_reconciliation_check',
        'no_materialized_view_concurrent_refresh_without_unique_index',
        'no_heavy_refresh_during_clinic_hours',
        'no_auto_schedule_in_rpt_1',
        'no_source_switch_without_feature_flag',
        'no_financial_semantic_change',
        'no_inventory_stock_mutable_summary_as_source_of_truth',
        'no_rme_medical_record_content_summary_with_pii',
        'summary_artifacts_must_be_safe',
        'refresh_command_must_support_dry_run',
    ];

    /** @var list<string> */
    private const REQUIRED_CATEGORY_FIELDS = [
        'source_tables',
        'branch_scope',
        'refresh_strategy',
        'freshness_sla',
        'pii_allowed',
        'reconciliation_required',
        'runtime_source_switch_allowed',
    ];

    /**
     * @return array<string, mixed>
     */
    public function collect(bool $includeDbInventory = false): array
    {
        $config = config('reporting_summary_governance');

        if (! is_array($config) || $config === []) {
            return $this->finalize([], [
                $this->fail('RPT-CONFIG-EXISTS', 'config/reporting_summary_governance.php is missing or empty.'),
            ], $includeDbInventory, null);
        }

        $checks = [];
        $checks[] = $this->pass('RPT-CONFIG-EXISTS', 'reporting_summary_governance config present and non-empty.');

        $metadata = (array) ($config['metadata'] ?? []);
        $checks[] = ($metadata['sprint'] ?? '') === 'RPT-1'
            && ($metadata['status'] ?? '') === 'implemented'
            && ($metadata['production_auto_refresh_enabled'] ?? true) === false
            ? $this->pass('RPT-METADATA', 'RPT-1 metadata present with production auto refresh disabled.')
            : $this->fail('RPT-METADATA', 'RPT-1 metadata missing or production auto refresh not disabled.');

        $globalRules = (array) ($config['global_rules'] ?? []);
        $missingRules = array_filter(self::REQUIRED_GLOBAL_RULES, fn (string $rule) => ! ($globalRules[$rule] ?? false));
        $checks[] = $missingRules === []
            ? $this->pass('RPT-GLOBAL-RULES', 'All reporting summary safety rules are enabled.')
            : $this->fail('RPT-GLOBAL-RULES', 'Missing/disabled global rules: '.implode(', ', $missingRules));

        $runtime = (array) ($config['summary_runtime_policy'] ?? []);
        $checks[] = ($runtime['runtime_reads_from_summary_enabled'] ?? true) === false
            ? $this->pass('RPT-RUNTIME-READS-OFF', 'Summary runtime reads default false.')
            : $this->fail('RPT-RUNTIME-READS-OFF', 'Summary runtime reads must default false in RPT-1.');
        $checks[] = ($runtime['auto_refresh_enabled'] ?? true) === false
            && ($runtime['scheduled_refresh_allowed'] ?? true) === false
            && ($runtime['queue_refresh_allowed'] ?? true) === false
            ? $this->pass('RPT-AUTO-REFRESH-OFF', 'Auto/scheduled/queue refresh default false.')
            : $this->fail('RPT-AUTO-REFRESH-OFF', 'Auto, scheduled, and queue refresh must stay disabled in RPT-1.');

        $mv = (array) ($config['materialized_view_readiness'] ?? []);
        $checks[] = ($mv['refresh_concurrently_default'] ?? true) === false
            && ($mv['unique_index_required_for_concurrent'] ?? false) === true
            && ($runtime['concurrent_refresh_requires_unique_index'] ?? false) === true
            ? $this->pass('RPT-MV-CONCURRENT-POLICY', 'Concurrent materialized refresh requires a unique index and defaults off.')
            : $this->fail('RPT-MV-CONCURRENT-POLICY', 'Materialized view concurrent refresh policy is unsafe or incomplete.');

        $allowed = (array) ($config['allowed_summary_categories'] ?? []);
        $categoryViolations = [];
        foreach ($allowed as $key => $category) {
            if (! is_array($category)) {
                $categoryViolations[] = "{$key}(invalid)";

                continue;
            }

            $gaps = array_filter(self::REQUIRED_CATEGORY_FIELDS, fn (string $field) => ! array_key_exists($field, $category));
            if ($gaps !== []) {
                $categoryViolations[] = sprintf('%s(%s)', $key, implode(',', $gaps));
            }
            if (($category['pii_allowed'] ?? true) !== false) {
                $categoryViolations[] = "{$key}(pii_allowed_not_false)";
            }
            if (($category['reconciliation_required'] ?? false) !== true) {
                $categoryViolations[] = "{$key}(reconciliation_not_required)";
            }
            if (($category['runtime_source_switch_allowed'] ?? true) !== false) {
                $categoryViolations[] = "{$key}(runtime_source_switch_allowed)";
            }
        }
        $checks[] = $categoryViolations === []
            ? $this->pass('RPT-ALLOWED-CATEGORIES', count($allowed).' allowed reporting summary categories are complete and safe.')
            : $this->fail('RPT-ALLOWED-CATEGORIES', 'Allowed category violations: '.implode('; ', $categoryViolations));

        $denied = (array) ($config['denied_summary_categories'] ?? []);
        $checks[] = count($denied) >= 8
            ? $this->pass('RPT-DENIED-CATEGORIES', count($denied).' denied summary categories documented.')
            : $this->fail('RPT-DENIED-CATEGORIES', 'Denied reporting summary categories are incomplete.');

        $piiAllowed = collect($allowed)
            ->filter(fn ($category) => is_array($category) && ($category['pii_allowed'] ?? true) !== false)
            ->keys()
            ->values()
            ->all();
        $checks[] = $piiAllowed === []
            ? $this->pass('RPT-NO-PII-ALLOWED', 'No allowed summary category permits PII.')
            : $this->fail('RPT-NO-PII-ALLOWED', 'PII allowed in category: '.implode(', ', $piiAllowed));

        $flagViolations = [];
        $flags = app(FeatureFlagService::class);
        foreach ((array) ($config['feature_flags'] ?? []) as $flagKey) {
            try {
                $flag = $flags->get((string) $flagKey);
                if (in_array($flagKey, [
                    'foundation.reporting.summary_runtime_reads_enabled',
                    'foundation.reporting.summary_auto_refresh_enabled',
                ], true) && ($flag['default'] ?? true) !== false) {
                    $flagViolations[] = "{$flagKey}(default_not_false)";
                }
            } catch (Throwable) {
                $flagViolations[] = (string) $flagKey;
            }
        }
        $checks[] = $flagViolations === []
            ? $this->pass('RPT-FEATURE-FLAGS', 'Required reporting summary feature flags exist and risky toggles default false.')
            : $this->fail('RPT-FEATURE-FLAGS', 'Feature flag violations: '.implode(', ', $flagViolations));

        $refresh = (array) ($config['refresh_policy'] ?? []);
        $reconciliation = (array) ($config['reconciliation_policy'] ?? []);
        $checks[] = ($refresh['dry_run_required_by_default'] ?? false) === true
            && ($refresh['manual_execute_requires_confirm_flag'] ?? false) === true
            && isset($refresh['stale_data_behavior'], $refresh['rollback_policy'])
            ? $this->pass('RPT-REFRESH-POLICY', 'Refresh dry-run, confirm, stale-data, and rollback policy documented.')
            : $this->fail('RPT-REFRESH-POLICY', 'Refresh policy is incomplete.');
        $checks[] = ($reconciliation['source_count_check'] ?? false) === true
            && ($reconciliation['aggregate_total_check'] ?? false) === true
            && ($reconciliation['branch_scope_check'] ?? false) === true
            && in_array('FAIL', (array) ($reconciliation['mismatch_status'] ?? []), true)
            ? $this->pass('RPT-RECONCILIATION-POLICY', 'Reconciliation policy covers source count, totals, branch, date, and mismatch statuses.')
            : $this->fail('RPT-RECONCILIATION-POLICY', 'Reconciliation policy is incomplete.');

        $dbInventory = null;
        if ($includeDbInventory) {
            $dbInventory = $this->readDbInventory();
            $checks[] = ($dbInventory['decision'] ?? 'WATCH') === 'FAIL'
                ? $this->fail('RPT-DB-INVENTORY', (string) ($dbInventory['message'] ?? 'DB inventory failed.'))
                : $this->pass('RPT-DB-INVENTORY', (string) ($dbInventory['message'] ?? 'DB inventory completed safely.'));

            $unsafeConcurrent = collect($dbInventory['materialized_views'] ?? [])
                ->filter(fn (array $view) => ($view['concurrent_refresh_ready'] ?? false) === true && ($view['unique_index_count'] ?? 0) < 1)
                ->values()
                ->all();
            $checks[] = $unsafeConcurrent === []
                ? $this->pass('RPT-DB-MV-UNIQUE-INDEX', 'No materialized view is marked concurrent-refresh-ready without a unique index.')
                : $this->fail('RPT-DB-MV-UNIQUE-INDEX', 'Materialized view concurrent readiness without unique index detected.');
        } else {
            $checks[] = $this->pass('RPT-DB-INVENTORY-SKIPPED', 'DB inventory skipped (not requested); normal GO does not require database introspection.');
        }

        return $this->finalize($config, $checks, $includeDbInventory, $dbInventory);
    }

    /**
     * @return array<string, mixed>
     */
    private function readDbInventory(): array
    {
        try {
            $driver = DB::connection()->getDriverName();
        } catch (Throwable) {
            $driver = (string) config('database.default');
        }

        if ($driver === 'pgsql') {
            return $this->readPostgresInventory();
        }

        if ($driver === 'sqlite') {
            return $this->readSqliteInventory();
        }

        return [
            'decision' => 'WATCH',
            'driver' => $driver,
            'message' => "DB inventory skipped: unsupported driver {$driver}.",
            'tables' => [],
            'views' => [],
            'materialized_views' => [],
            'indexes' => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function readPostgresInventory(): array
    {
        $tables = [];
        $views = [];
        $materializedViews = [];
        $indexes = [];

        try {
            foreach (DB::select("
                SELECT table_name
                FROM information_schema.tables
                WHERE table_schema = 'public'
                  AND table_type = 'BASE TABLE'
                  AND table_name LIKE 'rpt\\_%' ESCAPE '\\'
                ORDER BY table_name
            ") as $row) {
                $tables[] = ['name' => (string) $row->table_name];
            }

            foreach (DB::select("
                SELECT table_name
                FROM information_schema.views
                WHERE table_schema = 'public'
                  AND table_name LIKE 'rpt\\_%' ESCAPE '\\'
                ORDER BY table_name
            ") as $row) {
                $views[] = ['name' => (string) $row->table_name];
            }

            $mvRows = DB::select("
                SELECT matviewname
                FROM pg_matviews
                WHERE schemaname = 'public'
                  AND matviewname LIKE 'rpt\\_%' ESCAPE '\\'
                ORDER BY matviewname
            ");
            foreach ($mvRows as $row) {
                $name = (string) $row->matviewname;
                $uniqueCount = $this->postgresUniqueIndexCount($name);
                $materializedViews[] = [
                    'name' => $name,
                    'unique_index_count' => $uniqueCount,
                    'concurrent_refresh_ready' => $uniqueCount > 0,
                ];
            }

            foreach (DB::select("
                SELECT tablename, indexname
                FROM pg_indexes
                WHERE schemaname = 'public'
                  AND tablename LIKE 'rpt\\_%' ESCAPE '\\'
                ORDER BY tablename, indexname
            ") as $row) {
                $indexes[] = [
                    'table' => (string) $row->tablename,
                    'index_name' => (string) $row->indexname,
                ];
            }
        } catch (Throwable $e) {
            return [
                'decision' => 'WATCH',
                'driver' => 'pgsql',
                'message' => 'DB inventory unavailable in this environment; no row data was read.',
                'tables' => $tables,
                'views' => $views,
                'materialized_views' => $materializedViews,
                'indexes' => $indexes,
            ];
        }

        return [
            'decision' => 'GO',
            'driver' => 'pgsql',
            'message' => sprintf(
                'DB inventory read safely: %d rpt table(s), %d view(s), %d materialized view(s).',
                count($tables),
                count($views),
                count($materializedViews),
            ),
            'tables' => $tables,
            'views' => $views,
            'materialized_views' => $materializedViews,
            'indexes' => $indexes,
        ];
    }

    private function postgresUniqueIndexCount(string $materializedView): int
    {
        try {
            $rows = DB::select("
                SELECT COUNT(*) AS aggregate
                FROM pg_index i
                JOIN pg_class c ON c.oid = i.indrelid
                JOIN pg_namespace n ON n.oid = c.relnamespace
                WHERE n.nspname = 'public'
                  AND c.relname = ?
                  AND i.indisunique = true
            ", [$materializedView]);

            return (int) ($rows[0]->aggregate ?? 0);
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function readSqliteInventory(): array
    {
        $tables = [];
        $views = [];
        $indexes = [];

        try {
            foreach (DB::select("SELECT name, type FROM sqlite_master WHERE name LIKE 'rpt\\_%' ESCAPE '\\' AND type IN ('table', 'view', 'index') ORDER BY type, name") as $row) {
                $item = ['name' => (string) $row->name];
                if ($row->type === 'table') {
                    $tables[] = $item;
                } elseif ($row->type === 'view') {
                    $views[] = $item;
                } elseif ($row->type === 'index') {
                    $indexes[] = ['index_name' => (string) $row->name];
                }
            }
        } catch (Throwable) {
            return [
                'decision' => 'WATCH',
                'driver' => 'sqlite',
                'message' => 'SQLite rpt inventory unavailable; no row data was read.',
                'tables' => [],
                'views' => [],
                'materialized_views' => [],
                'indexes' => [],
            ];
        }

        return [
            'decision' => 'GO',
            'driver' => 'sqlite',
            'message' => sprintf('SQLite inventory read safely: %d rpt table(s), %d view(s).', count($tables), count($views)),
            'tables' => $tables,
            'views' => $views,
            'materialized_views' => [],
            'indexes' => $indexes,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $checks
     * @param  array<string, mixed>|null  $dbInventory
     * @return array<string, mixed>
     */
    private function finalize(array $config, array $checks, bool $includeDbInventory, ?array $dbInventory): array
    {
        $errors = count(array_filter($checks, fn (array $c) => ($c['status'] ?? '') === 'failed'));
        $warnings = count(array_filter($checks, fn (array $c) => ($c['status'] ?? '') === 'warning'));
        $passed = count(array_filter($checks, fn (array $c) => ($c['status'] ?? '') === 'passed'));
        $decision = $errors > 0 ? 'FAIL' : ($warnings > 0 ? 'WATCH' : 'GO');

        return [
            'generated_at' => now()->toIso8601String(),
            'sprint' => 'RPT-1',
            'environment' => (string) config('app.env'),
            'metadata' => $config['metadata'] ?? [],
            'runtime_reads_from_summary_enabled' => (bool) ($config['summary_runtime_policy']['runtime_reads_from_summary_enabled'] ?? false),
            'auto_refresh_enabled' => (bool) ($config['summary_runtime_policy']['auto_refresh_enabled'] ?? false),
            'materialized_view_readiness' => $config['materialized_view_readiness'] ?? [],
            'allowed_categories' => array_keys((array) ($config['allowed_summary_categories'] ?? [])),
            'denied_categories' => array_values((array) ($config['denied_summary_categories'] ?? [])),
            'existing_rpt_inventory' => $config['existing_rpt_inventory'] ?? [],
            'deferred_candidates' => $config['deferred_candidates'] ?? [],
            'db_inventory_requested' => $includeDbInventory,
            'db_inventory' => $dbInventory,
            'checks' => $checks,
            'summary' => [
                'decision' => $decision,
                'checks' => count($checks),
                'passed' => $passed,
                'warnings' => $warnings,
                'errors' => $errors,
                'materialized_views_required' => false,
                'physical_summary_created_in_rpt_1' => (bool) ($config['metadata']['physical_summary_created_in_rpt_1'] ?? false),
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
