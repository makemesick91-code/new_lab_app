<?php

namespace App\Services\Monitoring;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class PilotPerformanceSnapshotService
{
    /**
     * Operator-tunable byte budget for a single log-source scan.
     *
     * Named as a constant because the "not covered" warning quotes it: telling an
     * operator to raise a budget is only useful if the message says which one.
     */
    public const LOG_SCAN_BUDGET_CONFIG_KEY = 'foundation_monitoring.log_scan.max_source_bytes';

    /** Default budget. Unchanged from the value this monitor has always used. */
    public const LOG_SCAN_BUDGET_DEFAULT_BYTES = 2 * 1024 * 1024;

    /** Below this a scan is too small to say anything useful, so configuration cannot go lower. */
    public const LOG_SCAN_BUDGET_MIN_BYTES = 64 * 1024;

    /**
     * Hard ceiling. Coverage now costs real reads, which is exactly the pressure that
     * tempts someone to raise this without limit; a monitor that can be configured into
     * an unbounded read is a denial-of-service switch, so the budget is clamped and an
     * over-large source simply stays uncovered.
     */
    public const LOG_SCAN_BUDGET_MAX_BYTES = 64 * 1024 * 1024;

    public function __construct(
        private readonly PilotPerformanceSnapshotClassifier $classifier = new PilotPerformanceSnapshotClassifier,
        private readonly PilotPerformanceSnapshotLogAnalyzer $logAnalyzer = new PilotPerformanceSnapshotLogAnalyzer,
        private readonly PilotPerformanceSnapshotDiskProbe $diskProbe = new PilotPerformanceSnapshotDiskProbe,
        private readonly MonitoringLogSourceResolver $logSourceResolver = new MonitoringLogSourceResolver,
    ) {}

    /**
     * @param  array{skip_db:bool,skip_http:bool,since:string,log_path?:string|null}  $options
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

        $logs = $this->collectLogSummary($options['since'], $warnings, $options['log_path'] ?? null);
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
        $diskFreeBytes = $this->diskProbe->freeBytes($storagePath);
        $diskFreeGb = $diskFreeBytes !== null ? round($diskFreeBytes / 1024 / 1024 / 1024, 2) : null;

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
     * @return array{status:string, reason:string, metrics:array<string, mixed>}
     */
    private function collectLogSummary(string $sinceLabel, array &$warnings, ?string $logPathOverride = null): array
    {
        $since = PilotPerformanceSnapshotLogAnalyzer::parseSinceDuration($sinceLabel);

        if ($since === null) {
            $warnings[] = 'Invalid --since duration; log freshness could not be evaluated.';

            return [
                'status' => PilotPerformanceSnapshotClassifier::STATUS_WATCH,
                'reason' => 'Invalid lookback window.',
                'metrics' => [
                    'lookback_window' => $sinceLabel,
                    'fresh_error_like_count' => null,
                    'historical_tail_error_like_count' => null,
                    'timestamp_parse_status' => 'invalid_since',
                ],
            ];
        }

        $now = now();
        $cutoff = $now->copy()->subSeconds($since['seconds']);

        // Which files carry this application's log events is a question only the effective
        // logging configuration can answer. Reading a hardcoded storage/logs/laravel.log
        // was correct only while the configured channel happened to write there; the
        // moment it does not, the monitor reports on a file the application has abandoned.
        $resolution = $this->resolveLogSources($logPathOverride, $cutoff, $now);
        $scan = $this->scanLogSources($resolution['sources'], $since, $now, $warnings);

        if ($resolution['source_set_truncated'] ?? false) {
            // The window asked for more rotated days than the resolver will expand, so its
            // oldest stretch was never looked at. Say so rather than answering from the
            // part that fit.
            $warnings[] = sprintf(
                'Lookback window %s spans more rotated log files than are scanned in one pass; the oldest part of the window was not covered.',
                $since['label'],
            );

            if ($scan['metrics'] !== null) {
                $scan['metrics']['window_fully_covered'] = false;
                $scan['source_metrics']['source_coverage_complete'] = false;
            }
        }

        if ($scan['metrics'] === null) {
            // Nothing readable was observed at all, so there is no analysis to classify.
            // Fail closed rather than emit an all-zero "clean" section.
            return [
                'status' => PilotPerformanceSnapshotClassifier::STATUS_WATCH,
                'reason' => $scan['reason'],
                // The full metric key set is emitted even when nothing could be read. The
                // JSON payload is a contract for downstream consumers, and a verdict that
                // silently drops half the schema is harder to alert on than one that says
                // "unknown" in every field it could not fill.
                'metrics' => array_merge($this->emptyLogMetrics($since['label']), $scan['source_metrics']),
            ];
        }

        $metrics = array_merge($scan['metrics'], $scan['source_metrics']);

        $classification = PilotPerformanceSnapshotClassifier::classifyFreshLogErrors(
            $metrics['fresh_error_like_count'],
            $metrics['critical_fresh_count'],
            $metrics['timestamp_parse_status'],
            $metrics['orphan_unparseable_error_like_count'],
            $metrics['historical_tail_error_like_count'],
            $metrics['historical_stack_trace_line_count'],
            $metrics['undated_error_like_count'],
            $metrics['window_fully_covered'],
        );

        if (
            $classification['status'] === PilotPerformanceSnapshotClassifier::STATUS_OK
            && $metrics['historical_tail_error_like_count'] > 0
        ) {
            $stackNote = $metrics['historical_stack_trace_line_count'] > 0
                ? sprintf(', %d stack trace continuation lines grouped', $metrics['historical_stack_trace_line_count'])
                : '';

            $warnings[] = sprintf(
                'Historical error events exist outside lookback window (%s): %d in scanned tail%s.',
                $since['label'],
                $metrics['historical_tail_error_like_count'],
                $stackNote,
            );
        }

        return [
            'status' => $classification['status'],
            'reason' => $classification['reason'],
            'metrics' => $metrics,
        ];
    }

    /**
     * Resolve which log files the monitor is required to read.
     *
     * An explicit `log_path` override still exists for callers that point the monitor at
     * one specific file; it is treated as an unrotated single source. Everything else
     * comes from the effective logging configuration.
     *
     * @return array{sources:list<MonitoringLogSource>, monitored_channels:list<string>, default_channel:string, resolution_status:string}
     */
    private function resolveLogSources(?string $logPathOverride, CarbonInterface $cutoff, CarbonInterface $now): array
    {
        if ($logPathOverride !== null) {
            return [
                'sources' => [new MonitoringLogSource(
                    channel: 'override',
                    driver: 'single',
                    path: $logPathOverride,
                    support: MonitoringLogSource::SUPPORT_SUPPORTED,
                )],
                'monitored_channels' => ['override'],
                'default_channel' => 'override',
                'resolution_status' => 'resolved',
                'source_set_truncated' => false,
            ];
        }

        return $this->logSourceResolver->resolve($cutoff, $now);
    }

    /**
     * Read and analyse every resolved source, then fold the results into one verdict.
     *
     * Coverage is the point of this method. Counts alone cannot distinguish "scanned the
     * whole window and found nothing" from "never looked at most of it", and only the
     * first of those may ever be reported as OK. So each source is asked whether it was
     * read back as far as it was responsible for, and anything the monitor could not read
     * — an unsupported driver, a vanished file, a failed open — marks the window as not
     * fully covered rather than being quietly dropped from the total.
     *
     * @param  list<MonitoringLogSource>  $sources
     * @param  array{seconds:int, label:string}  $since
     * @param  list<string>  $warnings
     * @return array{metrics:array<string, mixed>|null, source_metrics:array<string, mixed>, reason:string}
     */
    /**
     * The scan budget, clamped to a range the monitor can honour.
     *
     * A source larger than the budget is read tail-only and therefore never counts as
     * covered, so this value is the lever an operator pulls to make a busy host provable
     * again. It is clamped rather than trusted: raising coverage must not be a way to
     * turn the monitor into an unbounded read of an arbitrarily large file.
     */
    private function resolveLogScanBudgetBytes(): int
    {
        $configured = config(self::LOG_SCAN_BUDGET_CONFIG_KEY, self::LOG_SCAN_BUDGET_DEFAULT_BYTES);

        if (! is_numeric($configured)) {
            return self::LOG_SCAN_BUDGET_DEFAULT_BYTES;
        }

        return max(
            self::LOG_SCAN_BUDGET_MIN_BYTES,
            min(self::LOG_SCAN_BUDGET_MAX_BYTES, (int) $configured),
        );
    }

    private function scanLogSources(array $sources, array $since, CarbonInterface $now, array &$warnings): array
    {
        $maxTailBytes = $this->resolveLogScanBudgetBytes();

        $aggregate = null;
        $descriptors = [];
        $read = 0;
        $absent = 0;
        $unreadable = 0;
        $unsupported = 0;
        $coverageComplete = true;

        foreach ($sources as $source) {
            if (! $source->isSupported()) {
                $unsupported++;
                $coverageComplete = false;
                $descriptors[] = ['channel' => $source->channel, 'driver' => $source->driver, 'file' => null, 'status' => 'unsupported'];

                $warnings[] = sprintf(
                    'Log channel "%s" uses the %s driver, which this monitor cannot read; its error events are not covered.',
                    $source->channel,
                    $source->driver,
                );

                continue;
            }

            $path = (string) $source->path;
            $file = basename($path);

            if (! is_file($path)) {
                $absent++;
                $tolerated = $this->absentSourceIsProvenEmpty($source, $now);
                $descriptors[] = [
                    'channel' => $source->channel,
                    'driver' => $source->driver,
                    'file' => $file,
                    'status' => $tolerated ? 'absent_proven_empty' : 'absent',
                ];

                if ($tolerated) {
                    // A rotating day-file is created on the first write of its day. When
                    // the directory it lives in is readable and the day is still inside
                    // the channel's retention horizon, "not there" is an observation that
                    // nothing was written, not an unknown — nothing could have removed it.
                    continue;
                }

                $coverageComplete = false;
                $warnings[] = sprintf(
                    'Configured log source %s (channel "%s") is missing; log health for that source was not verified.',
                    $file,
                    $source->channel,
                );

                continue;
            }

            // One stat, inside readTail(). A second one here would be a separate
            // observation of a file that can change underneath both.
            $tail = $this->readTail($path, $maxTailBytes);

            if ($tail === null) {
                // The file is there but could not be read (permissions, a race, a vanished
                // inode). An unreadable log is an unknown, and an unknown is never OK.
                $unreadable++;
                $coverageComplete = false;
                $descriptors[] = ['channel' => $source->channel, 'driver' => $source->driver, 'file' => $file, 'status' => 'unreadable'];
                $warnings[] = sprintf(
                    'Could not open log source %s (channel "%s") for reading; log health was not evaluated.',
                    $file,
                    $source->channel,
                );

                continue;
            }

            $read++;
            $coverage = $tail['coverage'];
            $metrics = $this->logAnalyzer->analyzeTail($tail['content'], $since['label'], $since['seconds'], $now);
            $metrics['tail_bytes_scanned'] = $coverage->bytesScanned;
            $truncated = $coverage->isTruncated();

            // Coverage is settled by the read, never by what the read happened to contain.
            //
            // This used to ask whether the oldest event timestamp in the scanned tail was
            // already older than the window cutoff, and if so declared the source covered
            // — reasoning that the skipped prefix must be older still. That silently
            // assumed a strictly chronological file and, worse, let the log's own contents
            // certify bytes the monitor never opened: one line beginning
            // `[2019-01-01 00:00:00]` inside the tail was enough to report "No fresh error
            // events within lookback window" while a real in-window ERROR sat unread in
            // the prefix. An event cannot testify about bytes nobody read, so the only
            // thing that counts now is whether the read started at byte 0.
            $sourceCovered = $coverage->isComplete();

            if ($truncated) {
                $warnings[] = sprintf(
                    'Log scan truncated to the last %d bytes of a %d byte file (%s); the first %d bytes were not examined, so error counts for the %s window are a lower bound.',
                    $coverage->bytesScanned,
                    $coverage->fileBytes,
                    $file,
                    $coverage->skippedBytes(),
                    $since['label'],
                );
            }

            if (! $sourceCovered) {
                $coverageComplete = false;
                $warnings[] = sprintf(
                    'Log scan of %s did not reach the start of the %s window: %d bytes of the source were never read, and an event inside the window cannot be ruled out there. Raise %s or rotate the log.',
                    $file,
                    $since['label'],
                    $coverage->skippedBytes(),
                    self::LOG_SCAN_BUDGET_CONFIG_KEY,
                );
            }

            $metrics['tail_truncated'] = $truncated;
            $metrics['tail_bytes_skipped'] = $coverage->skippedBytes();
            $metrics['source_bytes_total'] = $coverage->fileBytes;
            $descriptors[] = ['channel' => $source->channel, 'driver' => $source->driver, 'file' => $file, 'status' => 'read'];
            $aggregate = $aggregate === null ? $metrics : $this->mergeLogMetrics($aggregate, $metrics);
        }

        $sourceMetrics = [
            'log_sources' => $descriptors,
            'log_sources_read' => $read,
            'log_sources_absent' => $absent,
            'log_sources_unreadable' => $unreadable,
            'log_sources_unsupported' => $unsupported,
            'source_coverage_complete' => $coverageComplete && $read > 0,
            // Retained because existing consumers read it: true when at least one
            // configured source was actually present and read.
            'file_exists' => $read > 0,
        ];

        if ($read === 0) {
            // Every configured source was missing, unreadable or unreadable-by-design. The
            // monitor observed nothing whatsoever, which is the one state that must never
            // be dressed up as a clean bill of health.
            $warnings[] = 'No configured Laravel log source could be read; log health was not verified. '
                .'Confirm the configured log channel still writes to a readable file.';

            return [
                'metrics' => null,
                'source_metrics' => $sourceMetrics,
                'reason' => 'No readable Laravel log source; log health not verified.',
            ];
        }

        $aggregate['window_fully_covered'] = $coverageComplete;

        return [
            'metrics' => $aggregate,
            'source_metrics' => $sourceMetrics,
            'reason' => '',
        ];
    }

    /**
     * The log metric schema with every countable field marked unknown.
     *
     * Used when no source could be read: the shape of the payload stays constant so
     * dashboards and alerts keep parsing, while `null` says plainly that the monitor did
     * not measure this rather than that it measured zero.
     *
     * @return array<string, mixed>
     */
    private function emptyLogMetrics(string $lookbackWindow): array
    {
        return [
            'lookback_window' => $lookbackWindow,
            'fresh_error_like_count' => null,
            'historical_tail_error_like_count' => null,
            'critical_fresh_count' => null,
            'unparseable_error_like_count' => null,
            'fresh_stack_trace_line_count' => null,
            'historical_stack_trace_line_count' => null,
            'orphan_unparseable_error_like_count' => null,
            'attached_unparseable_line_count' => null,
            'undated_error_like_count' => null,
            'timestamped_lines' => null,
            'timestamp_parse_status' => 'failed',
            'log_grouping_status' => 'none',
            'oldest_fresh_error_at' => null,
            'latest_fresh_error_at' => null,
            'latest_historical_error_at' => null,
            'oldest_scanned_event_at' => null,
            'tail_bytes_scanned' => 0,
            'tail_bytes_skipped' => 0,
            'source_bytes_total' => 0,
            'tail_truncated' => false,
            'window_fully_covered' => false,
        ];
    }

    /**
     * Whether a missing source is an observation rather than a blind spot.
     *
     * Only a rotating day-file qualifies, and only when its directory could actually be
     * listed and its day is still young enough that the channel's own retention cannot
     * have deleted it. A `single` file is never rotated, so its absence means it was
     * removed or the channel moved — precisely the drift this monitor exists to catch.
     */
    private function absentSourceIsProvenEmpty(MonitoringLogSource $source, CarbonInterface $now): bool
    {
        if ($source->driver !== 'daily' || $source->coversFrom === null) {
            return false;
        }

        $directory = dirname((string) $source->path);

        if (! is_dir($directory) || ! is_readable($directory)) {
            return false;
        }

        $retentionDays = (int) config('logging.channels.'.$source->channel.'.days', 14);

        if ($retentionDays <= 0) {
            return false;
        }

        return $source->coversFrom->greaterThanOrEqualTo($now->copy()->subDays($retentionDays)->startOfDay());
    }

    /**
     * Fold a second source's analysis into the running total.
     *
     * Counts add up. A stack that writes the same record to two files will therefore count
     * it twice — which can only ever overstate severity, never understate it, so it cannot
     * turn a failing system green. Statuses take the worse of the two, and the timestamp
     * extremes widen, so one degraded source is never averaged away by a healthy one.
     *
     * @param  array<string, mixed>  $carry
     * @param  array<string, mixed>  $next
     * @return array<string, mixed>
     */
    private function mergeLogMetrics(array $carry, array $next): array
    {
        foreach ([
            'fresh_error_like_count',
            'historical_tail_error_like_count',
            'critical_fresh_count',
            'unparseable_error_like_count',
            'fresh_stack_trace_line_count',
            'historical_stack_trace_line_count',
            'orphan_unparseable_error_like_count',
            'attached_unparseable_line_count',
            'undated_error_like_count',
            'timestamped_lines',
            'tail_bytes_scanned',
            'tail_bytes_skipped',
            'source_bytes_total',
        ] as $key) {
            $carry[$key] = (int) ($carry[$key] ?? 0) + (int) ($next[$key] ?? 0);
        }

        $carry['tail_truncated'] = ($carry['tail_truncated'] ?? false) || ($next['tail_truncated'] ?? false);

        $carry['timestamp_parse_status'] = $this->worstOf(
            $carry['timestamp_parse_status'] ?? 'ok',
            $next['timestamp_parse_status'] ?? 'ok',
            ['ok' => 0, 'partial' => 1, 'failed' => 2, 'invalid_since' => 2],
        );

        $carry['log_grouping_status'] = $this->worstOf(
            $carry['log_grouping_status'] ?? 'none',
            $next['log_grouping_status'] ?? 'none',
            ['grouped' => 0, 'none' => 1, 'partial' => 2],
        );

        foreach (['oldest_fresh_error_at', 'oldest_scanned_event_at'] as $key) {
            $carry[$key] = $this->pickTimestamp($carry[$key] ?? null, $next[$key] ?? null, earliest: true);
        }

        foreach (['latest_fresh_error_at', 'latest_historical_error_at'] as $key) {
            $carry[$key] = $this->pickTimestamp($carry[$key] ?? null, $next[$key] ?? null, earliest: false);
        }

        return $carry;
    }

    /**
     * @param  array<string, int>  $ranking
     */
    private function worstOf(string $a, string $b, array $ranking): string
    {
        return ($ranking[$b] ?? 0) > ($ranking[$a] ?? 0) ? $b : $a;
    }

    private function pickTimestamp(?string $a, ?string $b, bool $earliest): ?string
    {
        if ($a === null || $b === null) {
            return $a ?? $b;
        }

        $keepB = $earliest
            ? Carbon::parse($b)->lessThan(Carbon::parse($a))
            : Carbon::parse($b)->greaterThan(Carbon::parse($a));

        return $keepB ? $b : $a;
    }

    /**
     * Read the trailing window of the log file.
     *
     * Returns null when the file could not be opened, so the caller can tell "read it,
     * it was empty" apart from "could not read it at all". Those two must never collapse
     * into the same all-zero analysis.
     */
    /**
     * Read the last $maxBytes of a source, reporting what was physically examined.
     *
     * The coverage object is produced here, at the only place that knows the real read
     * offset, so the caller cannot recompute it from a stale second stat() and drift.
     * That drift is not hypothetical: coverage that disagrees with the bytes actually
     * read is exactly how a monitor ends up certifying a region it never opened.
     *
     * @return array{content:string, coverage:MonitoringLogScanCoverage}|null
     */
    private function readTail(string $path, int $maxBytes): ?array
    {
        // Both calls are diagnostics-suppressed because their failure is handled
        // explicitly below. Without this the emitted warning is promoted to an
        // ErrorException by the framework's error handler and the whole snapshot aborts —
        // a monitor that dies on an unreadable log is strictly worse than one that
        // reports WATCH and says why.
        $size = @filesize($path);

        if ($size === false) {
            return null;
        }

        if ($size === 0) {
            // Nothing in the file, so nothing was skipped: an empty source is completely
            // examined rather than merely unread.
            return ['content' => '', 'coverage' => MonitoringLogScanCoverage::empty()];
        }

        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return null;
        }

        $offset = max(0, $size - $maxBytes);
        fseek($handle, $offset);
        $content = stream_get_contents($handle);
        fclose($handle);

        if ($content === false) {
            // The open succeeded but the read did not. `?: ''` used to collapse this into
            // an empty string, which for a file below the budget is indistinguishable from
            // "read the whole thing, found nothing" — coverage complete, zero events, OK,
            // from a read that returned nothing. That is the same shape as the fopen
            // failure MONITORING-LOGS-WATCH-ROOT-CAUSE-1 closed, and it is a false green.
            // It also swallowed a legitimate one-byte file containing "0", which is falsy.
            return null;
        }

        // strlen($content), not min($size, $maxBytes): if the file was appended to or
        // rotated between the stat and the read, the honest number is what came back.
        return [
            'content' => $content,
            'coverage' => MonitoringLogScanCoverage::fromRead($size, $offset, strlen($content)),
        ];
    }

    /**
     * @param  array<string, array{status:string, reason?:string, metrics?:array<string, mixed>}>  $sections
     * @return array<string, mixed>
     */
    private function flattenMetrics(array $sections): array
    {
        $flat = [];

        foreach ($sections as $name => $section) {
            $flat[$name] = array_merge(
                ['status' => $section['status']],
                isset($section['reason']) ? ['reason' => $section['reason']] : [],
                $section['metrics'] ?? [],
            );
        }

        return $flat;
    }
}
