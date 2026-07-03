<?php

namespace App\Services\Monitoring;

use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;

class RuntimeQueryObservabilityService
{
    private const MAX_LIMIT = 100;

    private const DEFAULT_LIMIT = 20;

    private const QUERY_SUMMARY_MAX_LENGTH = 200;

    /** @var list<string> */
    private const SENSITIVE_PATTERNS = [
        'ktp', 'nik', 'phone', 'address', 'diagnosis', 'handwriting', 'notes',
        'patient_name', 'medical_record', 'odontogram', 'consent',
    ];

    /** @var array<string, list<string>> */
    private const MODULE_KEYWORDS = [
        'rme' => ['mst_patients', 'trx_clinic_visits', 'trx_medical_records', 'trx_odontograms'],
        'cashier' => ['trx_rme_invoices', 'trx_rme_payments', 'trx_rme_receivable'],
        'inventory' => ['trx_inventory_movements', 'inv_products', 'inv_inventory_batches', 'trx_stock_'],
        'owner' => ['owner_dashboard', 'reporting', 'trx_clinic_visits', 'trx_rme_payments'],
        'auth' => ['users', 'roles', 'permissions', 'sessions', 'password'],
    ];

    /**
     * @param  array{limit?:int, min_calls?:int, sort?:string, reset_baseline?:bool}  $options
     * @return array{valid:bool, error?:string, report?:array<string, mixed>}
     */
    public function validateOptions(array $options): array
    {
        $limit = (int) ($options['limit'] ?? self::DEFAULT_LIMIT);
        if ($limit < 1 || $limit > self::MAX_LIMIT) {
            return ['valid' => false, 'error' => 'Limit must be between 1 and '.self::MAX_LIMIT.'.'];
        }

        $sort = (string) ($options['sort'] ?? 'total_time');
        if (! in_array($sort, ['total_time', 'mean_time', 'calls'], true)) {
            return ['valid' => false, 'error' => "Invalid sort [{$sort}]. Allowed: total_time, mean_time, calls."];
        }

        $minCalls = (int) ($options['min_calls'] ?? 1);
        if ($minCalls < 1) {
            return ['valid' => false, 'error' => 'min_calls must be at least 1.'];
        }

        return ['valid' => true];
    }

    /**
     * @param  array{limit?:int, min_calls?:int, sort?:string, reset_baseline?:bool}  $options
     * @return array<string, mixed>
     */
    public function collect(array $options): array
    {
        $limit = min(self::MAX_LIMIT, max(1, (int) ($options['limit'] ?? self::DEFAULT_LIMIT)));
        $minCalls = max(1, (int) ($options['min_calls'] ?? 1));
        $sort = (string) ($options['sort'] ?? 'total_time');
        $resetBaseline = (bool) ($options['reset_baseline'] ?? false);

        $driver = (string) config('database.default');
        $connection = config('database.connections.'.$driver);
        $driverName = is_array($connection) ? (string) ($connection['driver'] ?? '') : '';

        $report = [
            'captured_at' => now()->toIso8601String(),
            'environment' => (string) config('app.env'),
            'metadata' => [
                'laravel_version' => Application::VERSION,
                'php_version' => PHP_VERSION,
                'database_driver' => $driverName,
            ],
            'pg_stat_statements' => [
                'available' => false,
                'extension_installed' => false,
                'extension_version' => null,
                'preloaded' => false,
                'shared_preload_libraries' => null,
                'setup_instructions' => null,
            ],
            'total_rows_inspected' => 0,
            'queries' => [],
            'privacy' => [
                'patient_names' => false,
                'ktp_nik' => false,
                'medical_content' => false,
                'raw_bindings' => false,
                'policy' => 'Normalized query summaries only; literals and PII redacted',
            ],
            'warnings' => [],
        ];

        if ($driverName !== 'pgsql') {
            $report['pg_stat_statements']['setup_instructions'] =
                'pg_stat_statements requires PostgreSQL. Current driver: '.$driverName.'.';
            $report['warnings'][] = 'Non-pgsql driver; runtime query observability unavailable.';

            return $report;
        }

        $availability = $this->detectAvailability();
        $report['pg_stat_statements'] = array_merge($report['pg_stat_statements'], $availability);

        if (! $availability['available']) {
            return $report;
        }

        try {
            $columns = $this->resolveStatColumns();
            $sortColumn = $this->mapSortColumn($sort, $columns);
            $rows = $this->fetchStatementRows($limit, $minCalls, $sortColumn, $columns);
            $report['total_rows_inspected'] = count($rows);
            $report['queries'] = $this->formatRows($rows, $columns);

            if ($resetBaseline) {
                DB::statement('SELECT pg_stat_statements_reset()');
                $report['baseline_reset'] = true;
                $report['warnings'][] = 'pg_stat_statements baseline reset after capture.';
            }
        } catch (\Throwable $exception) {
            $report['warnings'][] = 'Query collection failed: '.$exception->getMessage();
        }

        return $report;
    }

    public function sanitizeQueryText(string $query): string
    {
        $text = preg_replace('/\s+/u', ' ', trim($query)) ?? $query;
        $text = preg_replace("/'(?:''|[^'])*'/u", '?', $text) ?? $text;
        $text = preg_replace('/"(?:[^"\\\\]|\\\\.)*"/u', '?', $text) ?? $text;
        $text = preg_replace('/\b\d+(?:\.\d+)?\b/u', '?', $text) ?? $text;
        $text = preg_replace('/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/u', '?@?', $text) ?? $text;
        $text = preg_replace('/\b(?:\+?62|0)\d{8,14}\b/u', '?', $text) ?? $text;
        $text = preg_replace('/\b\d{16}\b/u', '?', $text) ?? $text;

        if (strlen($text) > self::QUERY_SUMMARY_MAX_LENGTH) {
            $text = substr($text, 0, self::QUERY_SUMMARY_MAX_LENGTH).'…';
        }

        return $text;
    }

    /**
     * @return array{available:bool, extension_installed:bool, extension_version:?string, preloaded:bool, shared_preload_libraries:?string, setup_instructions:?string}
     */
    private function detectAvailability(): array
    {
        $result = [
            'available' => false,
            'extension_installed' => false,
            'extension_version' => null,
            'preloaded' => false,
            'shared_preload_libraries' => null,
            'setup_instructions' => null,
        ];

        try {
            $preload = DB::selectOne("SELECT current_setting('shared_preload_libraries', true) AS libs");
            $libs = $preload !== null ? (string) $preload->libs : '';
            $result['shared_preload_libraries'] = $libs !== '' ? $libs : null;
            $result['preloaded'] = str_contains($libs, 'pg_stat_statements');

            $ext = DB::selectOne(
                "SELECT extversion FROM pg_extension WHERE extname = 'pg_stat_statements' LIMIT 1",
            );
            if ($ext !== null) {
                $result['extension_installed'] = true;
                $result['extension_version'] = (string) $ext->extversion;
            }

            $view = DB::selectOne("SELECT to_regclass('pg_stat_statements') IS NOT NULL AS exists");
            $viewExists = $view !== null && (bool) $view->exists;

            if (! $result['preloaded']) {
                $result['setup_instructions'] =
                    'Add pg_stat_statements to shared_preload_libraries in postgresql.conf, restart PostgreSQL, then CREATE EXTENSION pg_stat_statements;';

                return $result;
            }

            if (! $result['extension_installed'] || ! $viewExists) {
                $result['setup_instructions'] =
                    'Run CREATE EXTENSION IF NOT EXISTS pg_stat_statements; in the application database.';

                return $result;
            }

            $result['available'] = true;
        } catch (\Throwable) {
            $result['setup_instructions'] =
                'Unable to inspect pg_stat_statements. Verify PostgreSQL permissions and extension status.';
        }

        return $result;
    }

    /**
     * @return array{total_time:string, mean_time:string, calls:string, rows:string, shared_blks_hit:string, shared_blks_read:string, queryid:string}
     */
    private function resolveStatColumns(): array
    {
        $names = collect(DB::select("
            SELECT column_name
            FROM information_schema.columns
            WHERE table_name = 'pg_stat_statements'
        "))->pluck('column_name')->map(fn ($n) => (string) $n)->all();

        $hasExecTime = in_array('total_exec_time', $names, true);

        return [
            'total_time' => $hasExecTime ? 'total_exec_time' : 'total_time',
            'mean_time' => $hasExecTime ? 'mean_exec_time' : 'mean_time',
            'calls' => 'calls',
            'rows' => in_array('rows', $names, true) ? 'rows' : '0',
            'shared_blks_hit' => in_array('shared_blks_hit', $names, true) ? 'shared_blks_hit' : '0',
            'shared_blks_read' => in_array('shared_blks_read', $names, true) ? 'shared_blks_read' : '0',
            'queryid' => in_array('queryid', $names, true) ? 'queryid' : 'NULL',
        ];
    }

    /**
     * @param  array{total_time:string, mean_time:string, calls:string, rows:string, shared_blks_hit:string, shared_blks_read:string, queryid:string}  $columns
     */
    private function mapSortColumn(string $sort, array $columns): string
    {
        return match ($sort) {
            'mean_time' => $columns['mean_time'],
            'calls' => $columns['calls'],
            default => $columns['total_time'],
        };
    }

    /**
     * @param  array{total_time:string, mean_time:string, calls:string, rows:string, shared_blks_hit:string, shared_blks_read:string, queryid:string}  $columns
     * @return list<object>
     */
    private function fetchStatementRows(int $limit, int $minCalls, string $sortColumn, array $columns): array
    {
        $allowedSort = [$columns['total_time'], $columns['mean_time'], $columns['calls']];
        if (! in_array($sortColumn, $allowedSort, true)) {
            $sortColumn = $columns['total_time'];
        }

        $rowsExpr = $columns['rows'] === '0' ? '0' : $columns['rows'];
        $hitExpr = $columns['shared_blks_hit'] === '0' ? '0' : $columns['shared_blks_hit'];
        $readExpr = $columns['shared_blks_read'] === '0' ? '0' : $columns['shared_blks_read'];
        $queryIdExpr = $columns['queryid'] === 'NULL' ? 'NULL' : $columns['queryid'];

        $sql = "
            SELECT
                {$queryIdExpr}::text AS queryid,
                calls::bigint AS calls,
                ROUND({$columns['total_time']}::numeric, 3) AS total_time_ms,
                ROUND({$columns['mean_time']}::numeric, 3) AS mean_time_ms,
                {$rowsExpr}::bigint AS rows,
                {$hitExpr}::bigint AS shared_blks_hit,
                {$readExpr}::bigint AS shared_blks_read,
                query
            FROM pg_stat_statements
            WHERE calls >= ?
              AND query NOT ILIKE '%pg_stat_statements%'
              AND query NOT ILIKE '%password%'
              AND query NOT ILIKE '%ktp%'
              AND query NOT ILIKE '%handwriting%'
            ORDER BY {$sortColumn} DESC
            LIMIT ?
        ";

        return DB::select($sql, [$minCalls, $limit]);
    }

    /**
     * @param  list<object>  $rows
     * @param  array{total_time:string, mean_time:string, calls:string, rows:string, shared_blks_hit:string, shared_blks_read:string, queryid:string}  $columns
     * @return list<array<string, mixed>>
     */
    private function formatRows(array $rows, array $columns): array
    {
        $formatted = [];

        foreach ($rows as $row) {
            $rawQuery = (string) ($row->query ?? '');
            $summary = $this->sanitizeQueryText($rawQuery);
            $module = $this->guessModule($rawQuery);
            $meanMs = (float) ($row->mean_time_ms ?? 0);
            $totalMs = (float) ($row->total_time_ms ?? 0);
            $calls = (int) ($row->calls ?? 0);
            $sharedRead = (int) ($row->shared_blks_read ?? 0);
            $sharedHit = (int) ($row->shared_blks_hit ?? 0);

            $entry = [
                'queryid' => isset($row->queryid) && $row->queryid !== null ? (string) $row->queryid : null,
                'calls' => $calls,
                'total_time_ms' => $totalMs,
                'mean_time_ms' => $meanMs,
                'rows' => (int) ($row->rows ?? 0),
                'shared_blks_hit' => $sharedHit,
                'shared_blks_read' => $sharedRead,
                'query_summary' => $summary,
                'module_guess' => $module,
                'risk_hint' => $this->riskHint($meanMs, $totalMs, $calls, $sharedRead, $sharedHit),
                'recommendation' => $this->recommendation($meanMs, $totalMs, $calls, $sharedRead, $sharedHit),
            ];

            $formatted[] = $entry;
        }

        return $formatted;
    }

    private function guessModule(string $query): string
    {
        $lower = strtolower($query);

        foreach (self::MODULE_KEYWORDS as $module => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($lower, strtolower($keyword))) {
                    return $module;
                }
            }
        }

        if (preg_match('/\b(select|insert|update|delete)\b.*\b(users|roles|permissions)\b/i', $query) === 1) {
            return 'auth';
        }

        return 'unknown';
    }

    private function riskHint(float $meanMs, float $totalMs, int $calls, int $sharedRead, int $sharedHit): string
    {
        if ($meanMs >= 500) {
            return 'high_mean_latency';
        }

        if ($totalMs >= 5000 && $calls >= 10) {
            return 'high_cumulative_time';
        }

        $totalBlocks = $sharedRead + $sharedHit;
        if ($totalBlocks > 0 && ($sharedRead / $totalBlocks) > 0.3) {
            return 'elevated_disk_reads';
        }

        if ($calls >= 1000 && $meanMs >= 50) {
            return 'frequent_moderate_latency';
        }

        return 'ok';
    }

    private function recommendation(float $meanMs, float $totalMs, int $calls, int $sharedRead, int $sharedHit): string
    {
        $hint = $this->riskHint($meanMs, $totalMs, $calls, $sharedRead, $sharedHit);

        return match ($hint) {
            'high_mean_latency' => 'Investigate EXPLAIN plan and indexes for this query shape.',
            'high_cumulative_time' => 'High total time — consider caching, query rewrite, or index tuning.',
            'elevated_disk_reads' => 'Elevated shared_blks_read — review indexes and working set.',
            'frequent_moderate_latency' => 'Frequently executed with moderate latency — prioritize optimization.',
            default => 'Within acceptable runtime profile for pilot monitoring.',
        };
    }

    /**
     * @return list<string>
     */
    public function sensitivePatterns(): array
    {
        return self::SENSITIVE_PATTERNS;
    }
}
