<?php

namespace App\Services\Monitoring;

use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class PilotPerformanceSnapshotService
{
    public function __construct(
        private readonly PilotPerformanceSnapshotClassifier $classifier = new PilotPerformanceSnapshotClassifier,
    ) {}

    /**
     * @param  array{skip_db:bool,skip_http:bool,since:string}  $options
     * @return array{
     *   checked_at:string,
     *   environment:string,
     *   overall_status:string,
     *   sections:array<string, array{status:string, metrics?:array<string, mixed>}>,
     *   metrics:array<string, mixed>,
     *   warnings:list<string>,
     *   skipped_checks:list<string>
     * }
     */
    public function collect(array $options): array
    {
        $warnings = [];
        $skipped = [];
        $sections = [];

        $app = $this->collectAppHealth();
        $sections['app'] = $app;

        if ($options['skip_db']) {
            $skipped[] = 'database';
            $sections['database'] = [
                'status' => PilotPerformanceSnapshotClassifier::STATUS_OK,
                'metrics' => ['skipped' => true, 'reason' => '--no-db'],
            ];
        } else {
            $database = $this->collectDatabaseHealth($warnings, $skipped);
            $sections['database'] = $database;
        }

        $resources = $this->collectResourceHealth($warnings);
        $sections['resources'] = $resources;

        if ($options['skip_http']) {
            $skipped[] = 'http';
            $sections['http'] = [
                'status' => PilotPerformanceSnapshotClassifier::STATUS_OK,
                'metrics' => ['skipped' => true, 'reason' => '--no-http'],
            ];
        } else {
            $http = $this->collectHttpHealth($warnings);
            $sections['http'] = $http;
        }

        $logs = $this->collectLogSummary($options['since'], $warnings);
        $sections['logs'] = $logs;

        $sqlStatus = $sections['database']['metrics']['sql_status'] ?? PilotPerformanceSnapshotClassifier::STATUS_OK;
        $httpStatus = $sections['http']['status'] ?? PilotPerformanceSnapshotClassifier::STATUS_OK;
        $paymentCount = (int) ($sections['database']['metrics']['payments'] ?? 0);
        $paymentStatus = PilotPerformanceSnapshotClassifier::classifyPaymentCount($paymentCount, $sqlStatus, $httpStatus);

        if ($paymentStatus !== PilotPerformanceSnapshotClassifier::STATUS_OK) {
            $sections['database']['status'] = PilotPerformanceSnapshotClassifier::worst(
                $sections['database']['status'],
                $paymentStatus,
            );
        }

        $overall = PilotPerformanceSnapshotClassifier::worst(
            $sections['app']['status'],
            $sections['database']['status'],
            $sections['resources']['status'],
            $sections['http']['status'],
            $sections['logs']['status'],
        );

        $metrics = $this->flattenMetrics($sections);

        return [
            'checked_at' => now()->toIso8601String(),
            'environment' => (string) config('app.env'),
            'overall_status' => $overall,
            'sections' => $sections,
            'metrics' => $metrics,
            'warnings' => $warnings,
            'skipped_checks' => $skipped,
        ];
    }

    /**
     * @return array{status:string, metrics:array<string, mixed>}
     */
    private function collectAppHealth(): array
    {
        $environment = (string) config('app.env');
        $debug = (bool) config('app.debug');
        $maintenance = app()->isDownForMaintenance();

        $status = PilotPerformanceSnapshotClassifier::worst(
            PilotPerformanceSnapshotClassifier::classifyDebugMode($debug, $environment),
            PilotPerformanceSnapshotClassifier::classifyMaintenanceMode($maintenance),
        );

        return [
            'status' => $status,
            'metrics' => [
                'app_name' => (string) config('app.name'),
                'environment' => $environment,
                'debug' => $debug,
                'maintenance' => $maintenance,
                'laravel_version' => Application::VERSION,
                'php_version' => PHP_VERSION,
                'timezone' => (string) config('app.timezone'),
            ],
        ];
    }

    /**
     * @param  list<string>  $warnings
     * @param  list<string>  $skipped
     * @return array{status:string, metrics:array<string, mixed>}
     */
    private function collectDatabaseHealth(array &$warnings, array &$skipped): array
    {
        $driver = (string) config('database.default');
        $connection = config('database.connections.'.$driver);
        $driverName = is_array($connection) ? (string) ($connection['driver'] ?? '') : '';

        if ($driverName !== 'pgsql') {
            $warnings[] = 'Database driver is not pgsql; DB checks limited.';

            return [
                'status' => PilotPerformanceSnapshotClassifier::STATUS_WATCH,
                'metrics' => [
                    'driver' => $driverName,
                    'skipped' => true,
                    'reason' => 'non-pgsql driver',
                ],
            ];
        }

        try {
            $databaseName = (string) DB::connection()->getDatabaseName();
            $sizeBytes = (int) DB::selectOne('SELECT pg_database_size(current_database()) AS size')->size;
            $sizeMb = round($sizeBytes / 1024 / 1024, 2);

            $patients = (int) DB::table('mst_patients')->count();
            $visits = (int) DB::table('trx_clinic_visits')->count();
            $invoices = (int) DB::table('trx_rme_invoices')->count();
            $payments = (int) DB::table('trx_rme_payments')->count();

            $index = $this->collectPaymentIndexHealth();
            $connections = $this->collectConnectionStates();
            $longRunning = $this->collectLongRunningQueries();

            $branchId = $this->resolveBenchmarkBranchId();
            $q5 = $branchId !== null
              ? $this->runQ5Benchmark($branchId)
              : ['runtime_ms' => null, 'status' => PilotPerformanceSnapshotClassifier::STATUS_OK, 'reason' => 'no payments'];
            $q6 = $branchId !== null
              ? $this->runQ6Benchmark($branchId)
              : ['runtime_ms' => null, 'status' => PilotPerformanceSnapshotClassifier::STATUS_OK, 'reason' => 'no payments'];

            $sqlStatus = PilotPerformanceSnapshotClassifier::worst(
                $q5['status'],
                $q6['status'],
                PilotPerformanceSnapshotClassifier::classifyLongRunningQueries(
                    (int) ($longRunning['over_30s'] ?? 0),
                    (int) ($longRunning['over_5m'] ?? 0),
                ),
                $index['status'],
            );

            $status = $sqlStatus;

            return [
                'status' => $status,
                'metrics' => [
                    'database' => $databaseName,
                    'size_mb' => $sizeMb,
                    'patients' => $patients,
                    'visits' => $visits,
                    'invoices' => $invoices,
                    'payments' => $payments,
                    'payment_index' => $index['metrics'],
                    'connections_by_state' => $connections,
                    'long_running' => $longRunning,
                    'q5_ms' => $q5['runtime_ms'],
                    'q6_ms' => $q6['runtime_ms'],
                    'q5_status' => $q5['status'],
                    'q6_status' => $q6['status'],
                    'sql_status' => $sqlStatus,
                    'benchmark_branch_id' => $branchId,
                ],
            ];
        } catch (\Throwable $exception) {
            $warnings[] = 'Database health check failed: '.$exception->getMessage();
            $skipped[] = 'database_partial';

            return [
                'status' => PilotPerformanceSnapshotClassifier::STATUS_WATCH,
                'metrics' => [
                    'error' => 'database_unavailable',
                ],
            ];
        }
    }

    /**
     * @return array{status:string, metrics:array<string, mixed>}
     */
    private function collectPaymentIndexHealth(): array
    {
        $row = DB::selectOne("
      SELECT
        i.indisvalid AS valid,
        i.indisready AS ready,
        pg_relation_size(c.oid) AS size_bytes
      FROM pg_class c
      JOIN pg_index i ON i.indexrelid = c.oid
      WHERE c.relname = 'trx_rme_payments_branch_paid_at_idx'
      LIMIT 1
    ");

        if ($row === null) {
            return [
                'status' => PilotPerformanceSnapshotClassifier::STATUS_WATCH,
                'metrics' => [
                    'exists' => false,
                    'valid' => false,
                    'ready' => false,
                    'size_bytes' => 0,
                ],
            ];
        }

        $valid = (bool) $row->valid;
        $ready = (bool) $row->ready;
        $status = ($valid && $ready)
          ? PilotPerformanceSnapshotClassifier::STATUS_OK
          : PilotPerformanceSnapshotClassifier::STATUS_WATCH;

        return [
            'status' => $status,
            'metrics' => [
                'exists' => true,
                'valid' => $valid,
                'ready' => $ready,
                'size_bytes' => (int) $row->size_bytes,
            ],
        ];
    }

    /**
     * @return array<string, int>
     */
    private function collectConnectionStates(): array
    {
        $rows = DB::select('SELECT state, COUNT(*)::int AS count FROM pg_stat_activity GROUP BY state');
        $states = [];

        foreach ($rows as $row) {
            $key = $row->state === null ? 'null' : (string) $row->state;
            $states[$key] = (int) $row->count;
        }

        return $states;
    }

    /**
     * @return array{over_30s:int, over_5m:int}
     */
    private function collectLongRunningQueries(): array
    {
        $over30 = (int) DB::selectOne("
      SELECT COUNT(*)::int AS count
      FROM pg_stat_activity
      WHERE state = 'active'
        AND pid <> pg_backend_pid()
        AND query_start IS NOT NULL
        AND NOW() - query_start > INTERVAL '30 seconds'
    ")->count;

        $over5m = (int) DB::selectOne("
      SELECT COUNT(*)::int AS count
      FROM pg_stat_activity
      WHERE state = 'active'
        AND pid <> pg_backend_pid()
        AND query_start IS NOT NULL
        AND NOW() - query_start > INTERVAL '5 minutes'
    ")->count;

        return ['over_30s' => $over30, 'over_5m' => $over5m];
    }

    private function resolveBenchmarkBranchId(): ?int
    {
        $row = DB::table('trx_rme_payments')
            ->select('branch_id', DB::raw('COUNT(*) as payment_count'))
            ->groupBy('branch_id')
            ->orderByDesc('payment_count')
            ->first();

        if ($row === null) {
            return null;
        }

        return (int) $row->branch_id;
    }

    /**
     * @return array{runtime_ms:?float, status:string, reason?:string}
     */
    private function runQ5Benchmark(int $branchId): array
    {
        $fromDate = now()->startOfYear()->toDateString();
        $toDate = now()->addYear()->startOfYear()->toDateString();

        return $this->runExplainAnalyze(
            'SELECT COALESCE(SUM(amount), 0) FROM trx_rme_payments WHERE branch_id = ? AND paid_at >= ?::date AND paid_at < ?::date',
            [$branchId, $fromDate, $toDate],
        );
    }

    /**
     * @return array{runtime_ms:?float, status:string, reason?:string}
     */
    private function runQ6Benchmark(int $branchId): array
    {
        $fromDate = now()->startOfYear()->toDateString();
        $toDate = now()->addYear()->startOfYear()->toDateString();

        return $this->runExplainAnalyze(
            'SELECT DATE(paid_at) AS day, COALESCE(SUM(amount), 0) FROM trx_rme_payments WHERE branch_id = ? AND paid_at >= ?::date AND paid_at < ?::date GROUP BY DATE(paid_at) ORDER BY day',
            [$branchId, $fromDate, $toDate],
        );
    }

    /**
     * @param  list<mixed>  $bindings
     * @return array{runtime_ms:?float, status:string, reason?:string}
     */
    private function runExplainAnalyze(string $sql, array $bindings): array
    {
        try {
            return DB::transaction(function () use ($sql, $bindings) {
                DB::statement("SET LOCAL statement_timeout = '5s'");

                $explainSql = 'EXPLAIN (ANALYZE, BUFFERS) '.$sql;
                $rows = DB::select($explainSql, $bindings);
                $plan = collect($rows)->pluck('QUERY PLAN')->implode("\n");
                $runtimeMs = $this->parseExecutionTimeMs($plan);
                $status = PilotPerformanceSnapshotClassifier::classifySqlRuntimeMs($runtimeMs);

                return [
                    'runtime_ms' => $runtimeMs,
                    'status' => $status,
                ];
            });
        } catch (\Throwable) {
            return [
                'runtime_ms' => null,
                'status' => PilotPerformanceSnapshotClassifier::STATUS_WATCH,
                'reason' => 'benchmark_failed',
            ];
        }
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
     * @return array{status:string, metrics:array<string, mixed>}
     */
    private function collectResourceHealth(array &$warnings): array
    {
        $storagePath = storage_path();
        $diskFreeBytes = @disk_free_space($storagePath);
        $diskFreeGb = $diskFreeBytes !== false ? round($diskFreeBytes / 1024 / 1024 / 1024, 2) : null;

        $memory = $this->readMemInfo();
        $loadAvg = function_exists('sys_getloadavg') ? sys_getloadavg() : null;

        if ($diskFreeGb === null) {
            $warnings[] = 'Could not read disk free space.';
        }

        $status = PilotPerformanceSnapshotClassifier::classifyDiskFreeGb($diskFreeGb);

        return [
            'status' => $status,
            'metrics' => [
                'disk_free_gb' => $diskFreeGb,
                'disk_path' => $storagePath,
                'memory' => $memory,
                'load_avg' => $loadAvg,
            ],
        ];
    }

    /**
     * @return array<string, int|null>|null
     */
    private function readMemInfo(): ?array
    {
        $path = '/proc/meminfo';

        if (! is_readable($path)) {
            return null;
        }

        $content = @file_get_contents($path);

        if ($content === false) {
            return null;
        }

        $values = [];

        foreach (['MemTotal', 'MemAvailable', 'SwapTotal', 'SwapFree'] as $key) {
            if (preg_match('/^'.$key.':\s+(\d+)\s+kB/m', $content, $matches) === 1) {
                $values[strtolower($key)] = (int) $matches[1];
            }
        }

        return $values === [] ? null : $values;
    }

    /**
     * @param  list<string>  $warnings
     * @return array{status:string, metrics:array<string, mixed>}
     */
    private function collectHttpHealth(array &$warnings): array
    {
        $baseUrl = rtrim((string) config('app.url'), '/');

        if ($baseUrl === '' || $baseUrl === 'http://localhost') {
            $warnings[] = 'APP_URL not configured for HTTP smoke checks.';

            return [
                'status' => PilotPerformanceSnapshotClassifier::STATUS_WATCH,
                'metrics' => [
                    'skipped' => true,
                    'reason' => 'app_url_unconfigured',
                ],
            ];
        }

        $root = $this->probeHttp($baseUrl.'/');
        $login = $this->probeHttp($baseUrl.'/login');

        $status = PilotPerformanceSnapshotClassifier::worst(
            PilotPerformanceSnapshotClassifier::classifyHttpCheck($root),
            PilotPerformanceSnapshotClassifier::classifyHttpCheck($login),
        );

        return [
            'status' => $status,
            'metrics' => [
                'base_url' => $baseUrl,
                'root' => $root,
                'login' => $login,
            ],
        ];
    }

    /**
     * @return array{code:?int, avg_ms:?float, error:?string}
     */
    private function probeHttp(string $url): array
    {
        try {
            $started = microtime(true);
            $response = Http::timeout(10)->withOptions(['allow_redirects' => false])->get($url);
            $avgMs = (microtime(true) - $started) * 1000;

            return [
                'code' => $response->status(),
                'avg_ms' => round($avgMs, 2),
                'error' => null,
            ];
        } catch (\Throwable $exception) {
            return [
                'code' => null,
                'avg_ms' => null,
                'error' => $exception->getMessage(),
            ];
        }
    }

    /**
     * @param  list<string>  $warnings
     * @return array{status:string, metrics:array<string, mixed>}
     */
    private function collectLogSummary(string $sinceLabel, array &$warnings): array
    {
        $logPath = storage_path('logs/laravel.log');

        if (! is_file($logPath)) {
            return [
                'status' => PilotPerformanceSnapshotClassifier::STATUS_OK,
                'metrics' => [
                    'since' => $sinceLabel,
                    'error_like_count' => 0,
                    'file_exists' => false,
                ],
            ];
        }

        $size = filesize($logPath);

        if ($size === false) {
            $warnings[] = 'Could not read Laravel log file size.';

            return [
                'status' => PilotPerformanceSnapshotClassifier::STATUS_WATCH,
                'metrics' => ['since' => $sinceLabel, 'error_like_count' => null],
            ];
        }

        $maxTailBytes = 2 * 1024 * 1024;
        $tail = $this->readTail($logPath, $maxTailBytes);
        $pattern = '/ERROR|CRITICAL|SQLSTATE|timeout|exception/i';
        $count = 0;

        foreach (preg_split('/\R/', $tail) as $line) {
            if ($line !== '' && preg_match($pattern, $line) === 1) {
                $count++;
            }
        }

        $status = $count > 100
          ? PilotPerformanceSnapshotClassifier::STATUS_INVESTIGATE
          : ($count > 20 ? PilotPerformanceSnapshotClassifier::STATUS_WATCH : PilotPerformanceSnapshotClassifier::STATUS_OK);

        return [
            'status' => $status,
            'metrics' => [
                'since' => $sinceLabel,
                'error_like_count' => $count,
                'tail_bytes_scanned' => min($size, $maxTailBytes),
                'file_exists' => true,
            ],
        ];
    }

    private function readTail(string $path, int $maxBytes): string
    {
        $size = filesize($path);

        if ($size === false || $size === 0) {
            return '';
        }

        $handle = fopen($path, 'rb');

        if ($handle === false) {
            return '';
        }

        $offset = max(0, $size - $maxBytes);
        fseek($handle, $offset);
        $content = stream_get_contents($handle) ?: '';
        fclose($handle);

        return $content;
    }

    /**
     * @param  array<string, array{status:string, metrics?:array<string, mixed>}>  $sections
     * @return array<string, mixed>
     */
    private function flattenMetrics(array $sections): array
    {
        $flat = [];

        foreach ($sections as $name => $section) {
            $flat[$name] = array_merge(
                ['status' => $section['status']],
                $section['metrics'] ?? [],
            );
        }

        return $flat;
    }
}
