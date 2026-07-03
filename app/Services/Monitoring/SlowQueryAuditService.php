<?php

namespace App\Services\Monitoring;

use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SlowQueryAuditService
{
    /** @var list<string> */
    private const SENSITIVE_OUTPUT_PATTERNS = [
        'ktp',
        'nik',
        'phone',
        'address',
        'diagnosis',
        'handwriting',
        'notes',
        'patient_name',
    ];

    /** @var array<string, array{module:string, description:string}> */
    private const CRITICAL_TABLES = [
        'mst_patients' => ['module' => 'rme', 'description' => 'Patient master'],
        'trx_clinic_visits' => ['module' => 'rme', 'description' => 'Clinic visits'],
        'trx_medical_records' => ['module' => 'rme', 'description' => 'Medical records'],
        'trx_odontograms' => ['module' => 'rme', 'description' => 'Odontograms'],
        'trx_rme_invoices' => ['module' => 'cashier', 'description' => 'RME invoices'],
        'trx_rme_payments' => ['module' => 'cashier', 'description' => 'RME payments'],
        'trx_rme_receivable_follow_ups' => ['module' => 'cashier', 'description' => 'Receivable follow-ups'],
        'trx_inventory_movements' => ['module' => 'inventory', 'description' => 'Inventory ledger'],
        'inv_products' => ['module' => 'inventory', 'description' => 'Inventory products'],
        'inv_inventory_batches' => ['module' => 'inventory', 'description' => 'Inventory batches'],
        'trx_purchase_requests' => ['module' => 'inventory', 'description' => 'Purchase requests'],
        'trx_purchase_orders' => ['module' => 'inventory', 'description' => 'Purchase orders'],
        'trx_goods_receipts' => ['module' => 'inventory', 'description' => 'Goods receipts'],
        'trx_stock_transfers' => ['module' => 'inventory', 'description' => 'Stock transfers'],
        'trx_stock_opnames' => ['module' => 'inventory', 'description' => 'Stock opnames'],
    ];

    /** @var array<string, array{module:string, note:string}> */
    private const EXPECTED_INDEXES = [
        'trx_clinic_visits_branch_date_status_index' => ['module' => 'rme', 'note' => 'Visit list by branch/date/status'],
        'trx_clinic_visits_visit_number_pattern_idx' => ['module' => 'rme', 'note' => 'Visit number prefix search'],
        'trx_rme_invoices_active_receivable_order_idx' => ['module' => 'cashier', 'note' => 'Active receivable ordering'],
        'trx_rme_payments_branch_paid_at_idx' => ['module' => 'cashier', 'note' => 'Payment trends by branch/date'],
        'trx_rme_payments_rme_invoice_id_index' => ['module' => 'cashier', 'note' => 'Invoice payment lookup'],
        'trx_inventory_movements_branch_location_product_index' => ['module' => 'inventory', 'note' => 'Branch/location/product stock'],
        'trx_inv_movements_branch_date_index' => ['module' => 'inventory', 'note' => 'Branch + movement_date mutation window (NSF-2)'],
        'idx_nsf2_patients_branch_is_active' => ['module' => 'rme', 'note' => 'Branch-scoped active patient lists (NSF-2)'],
    ];

    /** @var array<string, array{module:string, table:string, columns:list<string>, reason:string, aliases?:list<string>}> */
    private const NSF2_INDEX_CANDIDATES = [
        'idx_nsf2_inventory_movements_branch_movement_date' => [
            'module' => 'inventory',
            'table' => 'trx_inventory_movements',
            'columns' => ['branch_id', 'movement_date'],
            'reason' => 'Mutation/stock-card date scans at national scale',
            'aliases' => ['trx_inv_movements_branch_date_index'],
        ],
        'idx_nsf2_patients_branch_is_active' => [
            'module' => 'rme',
            'table' => 'mst_patients',
            'columns' => ['branch_id', 'is_active'],
            'reason' => 'Branch-scoped patient lists and audit filters',
        ],
    ];

    /**
     * @param  array{skip_benchmarks:bool, module?:string|null}  $options
     * @return array<string, mixed>
     */
    public function audit(array $options): array
    {
        $warnings = [];
        $driver = (string) config('database.default');
        $connection = config('database.connections.'.$driver);
        $driverName = is_array($connection) ? (string) ($connection['driver'] ?? '') : '';

        $report = [
            'audited_at' => now()->toIso8601String(),
            'environment' => (string) config('app.env'),
            'metadata' => [
                'app_name' => (string) config('app.name'),
                'laravel_version' => Application::VERSION,
                'php_version' => PHP_VERSION,
                'database_driver' => $driverName,
                'database_name' => null,
            ],
            'modules' => [],
            'table_inventory' => [],
            'index_inventory' => [],
            'nsf2_index_status' => [],
            'deferred_index_recommendations' => [],
            'benchmarks' => [],
            'pg_stat_statements' => ['available' => false, 'top_queries' => []],
            'warnings' => [],
            'privacy' => [
                'patient_names' => false,
                'ktp_nik' => false,
                'medical_content' => false,
                'policy' => 'Aggregates, table names, timings, and index metadata only',
            ],
        ];

        if ($driverName !== 'pgsql') {
            $warnings[] = 'Non-pgsql driver; schema/index audit limited.';
            $report['warnings'] = $warnings;

            return $report;
        }

        try {
            $report['metadata']['database_name'] = $this->safeDatabaseName();
        } catch (\Throwable $exception) {
            $warnings[] = 'Database unavailable: '.$exception->getMessage();
            $report['warnings'] = $warnings;

            return $report;
        }

        $moduleFilter = $this->normalizeModuleFilter($options['module'] ?? null);
        try {
            $report['table_inventory'] = $this->collectTableInventory($moduleFilter, $warnings);
            $report['index_inventory'] = $this->collectIndexInventory($warnings);
            $report['nsf2_index_status'] = $this->collectNsf2IndexStatus($moduleFilter);
            $report['deferred_index_recommendations'] = $this->collectDeferredRecommendations($moduleFilter);
            $report['modules'] = $this->summarizeModules($report['table_inventory'], $report['index_inventory']);

            if (! $options['skip_benchmarks']) {
                $branchId = $this->resolveBenchmarkBranchId();
                $report['benchmark_branch_id'] = $branchId;
                $report['benchmarks'] = $this->runBenchmarks($branchId, $moduleFilter, $warnings);
            } else {
                $report['benchmarks'] = ['skipped' => true, 'reason' => 'skip_benchmarks'];
            }

            $report['pg_stat_statements'] = $this->collectPgStatStatements($warnings);
        } catch (\Throwable $exception) {
            $warnings[] = 'Audit partial failure: '.$exception->getMessage();
            $report['benchmarks'] = ['skipped' => true, 'reason' => 'database_error'];
        }

        $report['warnings'] = $warnings;

        return $report;
    }

    /**
     * @return list<string>
     */
    public function sensitiveOutputPatterns(): array
    {
        return self::SENSITIVE_OUTPUT_PATTERNS;
    }

    private function safeDatabaseName(): ?string
    {
        try {
            return (string) DB::connection()->getDatabaseName();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return list<string>|null
     */
    private function normalizeModuleFilter(?string $module): ?array
    {
        if ($module === null || $module === '' || $module === 'all') {
            return null;
        }

        $allowed = ['rme', 'cashier', 'inventory', 'owner'];

        return in_array($module, $allowed, true) ? [$module] : null;
    }

    /**
     * @param  list<string>|null  $moduleFilter
     * @param  list<string>  $warnings
     * @return list<array<string, mixed>>
     */
    private function collectTableInventory(?array $moduleFilter, array &$warnings): array
    {
        $rows = [];

        foreach (self::CRITICAL_TABLES as $table => $meta) {
            if ($moduleFilter !== null && ! in_array($meta['module'], $moduleFilter, true) && $meta['module'] !== 'owner') {
                continue;
            }

            if (! Schema::hasTable($table)) {
                $warnings[] = "Table missing: {$table}";
                $rows[] = [
                    'table' => $table,
                    'module' => $meta['module'],
                    'description' => $meta['description'],
                    'exists' => false,
                ];

                continue;
            }

            try {
                $estimate = DB::selectOne(
                    'SELECT reltuples::bigint AS estimate FROM pg_class WHERE relname = ?',
                    [$table],
                );
                $rowCount = (int) DB::table($table)->count();
                $indexCount = (int) DB::selectOne(
                    'SELECT COUNT(*)::int AS count FROM pg_indexes WHERE tablename = ?',
                    [$table],
                )->count;

                $rows[] = [
                    'table' => $table,
                    'module' => $meta['module'],
                    'description' => $meta['description'],
                    'exists' => true,
                    'row_count' => $rowCount,
                    'pg_estimate' => $estimate !== null ? (int) $estimate->estimate : null,
                    'index_count' => $indexCount,
                ];
            } catch (\Throwable $exception) {
                $warnings[] = "Table audit failed for {$table}: ".$exception->getMessage();
            }
        }

        return $rows;
    }

    /**
     * @param  list<string>  $warnings
     * @return list<array<string, mixed>>
     */
    private function collectIndexInventory(array &$warnings): array
    {
        $rows = [];

        foreach (self::EXPECTED_INDEXES as $indexName => $meta) {
            try {
                $row = DB::selectOne(
                    'SELECT indexrelid::regclass::text AS name, indisvalid AS valid, indisready AS ready
                     FROM pg_index i
                     JOIN pg_class c ON c.oid = i.indexrelid
                     WHERE c.relname = ?
                     LIMIT 1',
                    [$indexName],
                );

                $rows[] = [
                    'index' => $indexName,
                    'module' => $meta['module'],
                    'note' => $meta['note'],
                    'exists' => $row !== null,
                    'valid' => $row !== null ? (bool) $row->valid : false,
                    'ready' => $row !== null ? (bool) $row->ready : false,
                ];
            } catch (\Throwable $exception) {
                $warnings[] = "Index audit failed for {$indexName}: ".$exception->getMessage();
            }
        }

        return $rows;
    }

    /**
     * @param  list<string>|null  $moduleFilter
     * @return list<array<string, mixed>>
     */
    /**
     * @param  list<string>|null  $moduleFilter
     * @return list<array<string, mixed>>
     */
    private function collectNsf2IndexStatus(?array $moduleFilter): array
    {
        $rows = [];

        foreach (self::NSF2_INDEX_CANDIDATES as $name => $meta) {
            if ($moduleFilter !== null && ! in_array($meta['module'], $moduleFilter, true)) {
                continue;
            }

            $resolved = $this->resolveNsf2IndexPresence($meta['table'], $name, $meta['columns'], $meta['aliases'] ?? []);

            $rows[] = [
                'target_index' => $name,
                'module' => $meta['module'],
                'table' => $meta['table'],
                'columns' => $meta['columns'],
                'reason' => $meta['reason'],
                'present' => $resolved['present'],
                'resolved_index' => $resolved['resolved_index'],
                'status' => $resolved['present'] ? 'applied' : 'missing',
            ];
        }

        return $rows;
    }

    /**
     * @param  list<string>|null  $moduleFilter
     * @return list<array<string, mixed>>
     */
    private function collectDeferredRecommendations(?array $moduleFilter): array
    {
        $rows = [];

        foreach ($this->collectNsf2IndexStatus($moduleFilter) as $status) {
            if ($status['present'] ?? false) {
                continue;
            }

            $rows[] = [
                'recommended_index' => $status['target_index'],
                'module' => $status['module'],
                'table' => $status['table'],
                'columns' => $status['columns'],
                'reason' => $status['reason'],
                'status' => 'deferred',
            ];
        }

        return $rows;
    }

    /**
     * @param  list<string>  $columns
     * @param  list<string>  $aliases
     * @return array{present:bool, resolved_index:?string}
     */
    private function resolveNsf2IndexPresence(string $table, string $targetName, array $columns, array $aliases = []): array
    {
        if (! Schema::hasTable($table)) {
            return ['present' => false, 'resolved_index' => null];
        }

        foreach (array_merge([$targetName], $aliases) as $indexName) {
            $row = DB::selectOne(
                'SELECT indexname FROM pg_indexes WHERE indexname = ? LIMIT 1',
                [$indexName],
            );

            if ($row !== null) {
                return ['present' => true, 'resolved_index' => (string) $row->indexname];
            }
        }

        $needle = '('.implode(', ', $columns).')';
        $row = DB::selectOne(
            'SELECT indexname FROM pg_indexes WHERE tablename = ? AND indexdef ILIKE ? LIMIT 1',
            [$table, '%'.$needle.'%'],
        );

        if ($row !== null) {
            return ['present' => true, 'resolved_index' => (string) $row->indexname];
        }

        return ['present' => false, 'resolved_index' => null];
    }

    /**
     * @param  list<array<string, mixed>>  $tables
     * @param  list<array<string, mixed>>  $indexes
     * @return array<string, array{tables:int, indexes_present:int, indexes_missing:int}>
     */
    private function summarizeModules(array $tables, array $indexes): array
    {
        $summary = [];

        foreach (['rme', 'cashier', 'inventory', 'owner'] as $module) {
            $moduleTables = array_values(array_filter(
                $tables,
                fn (array $row) => ($row['module'] ?? '') === $module && ($row['exists'] ?? false),
            ));
            $moduleIndexes = array_values(array_filter($indexes, fn (array $row) => ($row['module'] ?? '') === $module));

            $summary[$module] = [
                'tables' => count($moduleTables),
                'indexes_present' => count(array_filter($moduleIndexes, fn (array $row) => $row['exists'] ?? false)),
                'indexes_missing' => count(array_filter($moduleIndexes, fn (array $row) => ! ($row['exists'] ?? false))),
            ];
        }

        return $summary;
    }

    private function resolveBenchmarkBranchId(): ?int
    {
        foreach (['trx_clinic_visits', 'trx_rme_payments', 'trx_inventory_movements', 'mst_patients'] as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'branch_id')) {
                continue;
            }

            $row = DB::table($table)
                ->select('branch_id', DB::raw('COUNT(*) as row_count'))
                ->whereNotNull('branch_id')
                ->groupBy('branch_id')
                ->orderByDesc('row_count')
                ->first();

            if ($row !== null) {
                return (int) $row->branch_id;
            }
        }

        if (Schema::hasTable('mst_branches')) {
            $branch = DB::table('mst_branches')
                ->when(Schema::hasColumn('mst_branches', 'is_active'), fn ($q) => $q->where('is_active', true))
                ->orderBy('id')
                ->value('id');

            if ($branch !== null) {
                return (int) $branch;
            }

            // Empty local DB: still run EXPLAIN shapes with a synthetic branch id.
            return 1;
        }

        return null;
    }

    /**
     * @param  list<string>|null  $moduleFilter
     * @param  list<string>  $warnings
     * @return list<array<string, mixed>>
     */
    private function runBenchmarks(?int $branchId, ?array $moduleFilter, array &$warnings): array
    {
        if ($branchId === null) {
            $warnings[] = 'No benchmark branch resolved; benchmarks skipped.';

            return [];
        }

        $fromDate = now()->startOfMonth()->toDateString();
        $toDate = now()->addMonth()->startOfMonth()->toDateString();
        $yearStart = now()->startOfYear()->toDateString();
        $yearEnd = now()->addYear()->startOfYear()->toDateString();

        $definitions = [
            [
                'id' => 'rme_active_visits',
                'module' => 'rme',
                'route_area' => 'rme.visits / patient-queue',
                'sql' => "SELECT COUNT(*) FROM trx_clinic_visits WHERE branch_id = ? AND status NOT IN ('completed', 'cancelled')",
                'bindings' => [$branchId],
            ],
            [
                'id' => 'rme_medical_records_join',
                'module' => 'rme',
                'route_area' => 'rme.visits.medical-record',
                'sql' => 'SELECT COUNT(*) FROM trx_medical_records mr INNER JOIN trx_clinic_visits v ON v.id = mr.clinic_visit_id WHERE v.branch_id = ?',
                'bindings' => [$branchId],
            ],
            [
                'id' => 'cashier_active_receivables',
                'module' => 'cashier',
                'route_area' => 'rme.receivables',
                'sql' => "SELECT COUNT(*) FROM trx_rme_invoices WHERE branch_id = ? AND deleted_at IS NULL AND status IN ('UNPAID', 'PARTIAL') AND grand_total > 0",
                'bindings' => [$branchId],
            ],
            [
                'id' => 'cashier_payment_sum_ytd',
                'module' => 'cashier',
                'route_area' => 'owner dashboard / cashier reports',
                'sql' => 'SELECT COALESCE(SUM(amount), 0) FROM trx_rme_payments WHERE branch_id = ? AND paid_at >= ?::date AND paid_at < ?::date',
                'bindings' => [$branchId, $yearStart, $yearEnd],
            ],
            [
                'id' => 'inv_current_stock_aggregate',
                'module' => 'inventory',
                'route_area' => 'inventory.reports.current-stock',
                'sql' => 'SELECT COUNT(*) FROM (SELECT product_id FROM trx_inventory_movements WHERE branch_id = ? GROUP BY product_id HAVING COALESCE(SUM(quantity_in) - SUM(quantity_out), 0) <> 0) s',
                'bindings' => [$branchId],
            ],
            [
                'id' => 'inv_movements_month_window',
                'module' => 'inventory',
                'route_area' => 'inventory.reports.mutation',
                'sql' => 'SELECT COUNT(*) FROM trx_inventory_movements WHERE branch_id = ? AND movement_date >= ?::date AND movement_date < ?::date',
                'bindings' => [$branchId, $fromDate, $toDate],
            ],
            [
                'id' => 'rme_patients_branch_active',
                'module' => 'rme',
                'route_area' => 'settings.patients / patient audit',
                'sql' => 'SELECT COUNT(*) FROM mst_patients WHERE branch_id = ? AND is_active = true AND deleted_at IS NULL',
                'bindings' => [$branchId],
            ],
            [
                'id' => 'owner_visits_month',
                'module' => 'owner',
                'route_area' => 'dashboard owner KPI',
                'sql' => 'SELECT COUNT(*) FROM trx_clinic_visits WHERE branch_id = ? AND visit_date >= ?::date AND visit_date < ?::date',
                'bindings' => [$branchId, $fromDate, $toDate],
            ],
            [
                'id' => 'owner_unpaid_invoices',
                'module' => 'owner',
                'route_area' => 'dashboard receivables alert',
                'sql' => "SELECT COUNT(*) FROM trx_rme_invoices WHERE branch_id = ? AND deleted_at IS NULL AND status IN ('UNPAID', 'PARTIAL')",
                'bindings' => [$branchId],
            ],
        ];

        $results = [];

        foreach ($definitions as $definition) {
            if ($moduleFilter !== null && ! in_array($definition['module'], $moduleFilter, true)) {
                continue;
            }

            $bench = $this->runExplainAnalyze($definition['sql'], $definition['bindings']);
            $results[] = [
                'id' => $definition['id'],
                'module' => $definition['module'],
                'route_area' => $definition['route_area'],
                'runtime_ms' => $bench['runtime_ms'],
                'status' => $bench['status'],
                'uses_index_scan' => $bench['uses_index_scan'],
                'seq_scan_warning' => $bench['seq_scan_warning'],
                'plan_node_summary' => $bench['plan_node_summary'] ?? null,
                'index_names_used' => $bench['index_names_used'] ?? [],
                'reason' => $bench['reason'] ?? null,
            ];
        }

        return $results;
    }

    /**
     * @param  list<mixed>  $bindings
     * @return array{runtime_ms:?float, status:string, uses_index_scan:bool, seq_scan_warning:bool, plan_node_summary?:array<string,int>, index_names_used?:list<string>, reason?:string}
     */
    private function runExplainAnalyze(string $sql, array $bindings): array
    {
        try {
            return DB::transaction(function () use ($sql, $bindings) {
                DB::statement("SET LOCAL statement_timeout = '5s'");

                $rows = DB::select('EXPLAIN (ANALYZE, BUFFERS) '.$sql, $bindings);
                $plan = collect($rows)->pluck('QUERY PLAN')->implode("\n");
                $runtimeMs = $this->parseExecutionTimeMs($plan);
                $usesIndex = str_contains($plan, 'Index Scan') || str_contains($plan, 'Bitmap Index Scan');
                $seqScan = str_contains($plan, 'Seq Scan');
                $planSummary = $this->summarizePlanNodes($plan);
                $indexNames = $this->extractIndexNamesFromPlan($plan);

                $status = PilotPerformanceSnapshotClassifier::classifySqlRuntimeMs($runtimeMs ?? 0.0);
                if ($seqScan && ! $usesIndex && ($runtimeMs ?? 0) > 100) {
                    $status = PilotPerformanceSnapshotClassifier::worst($status, 'WATCH');
                }

                return [
                    'runtime_ms' => $runtimeMs,
                    'status' => $status,
                    'uses_index_scan' => $usesIndex,
                    'seq_scan_warning' => $seqScan && ! $usesIndex,
                    'plan_node_summary' => $planSummary,
                    'index_names_used' => $indexNames,
                ];
            });
        } catch (\Throwable) {
            return [
                'runtime_ms' => null,
                'status' => PilotPerformanceSnapshotClassifier::STATUS_WATCH,
                'uses_index_scan' => false,
                'seq_scan_warning' => false,
                'plan_node_summary' => [],
                'index_names_used' => [],
                'reason' => 'benchmark_failed',
            ];
        }
    }

    /**
     * @return array<string, int>
     */
    private function summarizePlanNodes(string $plan): array
    {
        $summary = [];
        $patterns = ['Seq Scan', 'Index Scan', 'Index Only Scan', 'Bitmap Heap Scan', 'Bitmap Index Scan'];

        foreach ($patterns as $pattern) {
            $count = substr_count($plan, $pattern);
            if ($count > 0) {
                $summary[$pattern] = $count;
            }
        }

        return $summary;
    }

    /**
     * @return list<string>
     */
    private function extractIndexNamesFromPlan(string $plan): array
    {
        if (preg_match_all('/using (\w+)/', $plan, $matches) < 1) {
            return [];
        }

        $names = [];
        foreach ($matches[1] as $name) {
            if (! in_array($name, $names, true)) {
                $names[] = $name;
            }
        }

        return $names;
    }

    private function parseExecutionTimeMs(string $plan): ?float
    {
        if (preg_match('/Execution Time:\s+([\d.]+)\s+ms/u', $plan, $matches) === 1) {
            return (float) $matches[1];
        }

        return null;
    }

    /**
     * @param  list<string>  $warnings
     * @return array{available:bool, top_queries:list<array<string, mixed>>}
     */
    private function collectPgStatStatements(array &$warnings): array
    {
        try {
            $extension = DB::selectOne("SELECT 1 FROM pg_extension WHERE extname = 'pg_stat_statements' LIMIT 1");
            if ($extension === null) {
                return ['available' => false, 'top_queries' => []];
            }

            $rows = DB::select("
                SELECT
                    LEFT(query, 120) AS query_prefix,
                    calls::bigint AS calls,
                    ROUND(mean_exec_time::numeric, 2) AS mean_ms,
                    ROUND(total_exec_time::numeric, 2) AS total_ms
                FROM pg_stat_statements
                WHERE query NOT ILIKE '%pg_stat_statements%'
                  AND query NOT ILIKE '%mst_patients%name%'
                  AND query NOT ILIKE '%ktp%'
                ORDER BY mean_exec_time DESC
                LIMIT 5
            ");

            $top = [];

            foreach ($rows as $row) {
                $top[] = [
                    'query_prefix' => (string) $row->query_prefix,
                    'calls' => (int) $row->calls,
                    'mean_ms' => (float) $row->mean_ms,
                    'total_ms' => (float) $row->total_ms,
                ];
            }

            return ['available' => true, 'top_queries' => $top];
        } catch (\Throwable $exception) {
            $warnings[] = 'pg_stat_statements unavailable: '.$exception->getMessage();

            return ['available' => false, 'top_queries' => []];
        }
    }
}
