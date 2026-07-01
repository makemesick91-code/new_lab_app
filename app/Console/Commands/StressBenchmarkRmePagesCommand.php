<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class StressBenchmarkRmePagesCommand extends Command
{
    private const ALLOWED_ENVIRONMENTS = ['local', 'stress', 'testing'];

    private const STRESS_DATABASE = 'daengtisia_stress';

    protected $signature = 'stress:benchmark-rme-pages
        {--runs=5 : Number of timed requests per page (min 1, max 100)}
        {--warmup=1 : Warmup requests per page (not counted in stats)}
        {--base-url=http://127.0.0.1:8008 : Stress app base URL}
        {--branch-code=TST : Stress branch code for context and synthetic IDs}
        {--include-owner : Include Owner Dashboard KPI targets on /dashboard}
        {--json : Print machine-readable JSON summary (no PII)}
        {--timeout-ms=5000 : Per-request curl timeout in milliseconds}
        {--dry-run : Resolve targets and validate environment without HTTP requests}
        {--output= : Optional file path for benchmark output (CSV lines)}';

    protected $description = 'Benchmark authenticated RME and Owner dashboard pages against local/stress server. Never runs in pilot/production.';

    public function handle(): int
    {
        if (! app()->environment(self::ALLOWED_ENVIRONMENTS)) {
            $this->error('This command only runs in local, stress, or testing environments (never pilot/production).');

            return self::FAILURE;
        }

        if (app()->environment('stress') && $this->currentDatabase() !== self::STRESS_DATABASE) {
            $this->error('Refusing to run in stress environment: DB_DATABASE must be '.self::STRESS_DATABASE.'.');

            return self::FAILURE;
        }

        $runs = min(100, max(1, (int) $this->option('runs')));
        $warmup = max(0, (int) $this->option('warmup'));
        $baseUrl = rtrim((string) $this->option('base-url'), '/');
        $branchCode = (string) $this->option('branch-code');
        $timeoutMs = max(500, (int) $this->option('timeout-ms'));
        $dryRun = (bool) $this->option('dry-run');
        $asJson = (bool) $this->option('json');

        $context = $this->resolveBenchmarkContext($branchCode);
        if ($context === null) {
            return self::FAILURE;
        }

        $targets = $this->buildTargets($baseUrl, $context, (bool) $this->option('include-owner'));

        if ($dryRun) {
            $this->printDryRunSummary($targets, $runs, $warmup, $branchCode, $asJson);

            return self::SUCCESS;
        }

        $sessions = $this->openAuthSessions($baseUrl, $timeoutMs, $branchCode);
        if ($sessions === null) {
            return self::FAILURE;
        }

        try {
            $results = [];

            foreach ($targets as $target) {
                $cookieJar = $sessions[$target['session']];
                $results[] = $this->benchmarkTarget($target, $cookieJar, $runs, $warmup, $timeoutMs);
            }

            if ($asJson) {
                $this->line(json_encode([
                    'generated_at' => now()->toIso8601String(),
                    'environment' => app()->environment(),
                    'database' => $this->currentDatabase(),
                    'branch_code' => $branchCode,
                    'branch_id' => $context['branch_id'],
                    'runs' => $runs,
                    'warmup' => $warmup,
                    'targets' => array_map(fn (array $row) => $this->jsonRow($row), $results),
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            } else {
                $this->printTableSummary($results, $runs, $warmup, $branchCode, $context);
            }

            if ($path = $this->option('output')) {
                file_put_contents($path, $this->buildCsvOutput($results, $runs, $warmup, $baseUrl, $context));
                $this->info("Wrote benchmark output to {$path}");
            }
        } finally {
            foreach ($sessions as $jar) {
                @unlink($jar);
            }
        }

        return self::SUCCESS;
    }

    /**
     * @return array{branch_id:int,visit_id:int,patient_id:int}|null
     */
    private function resolveBenchmarkContext(string $branchCode): ?array
    {
        $branchId = (int) DB::table('mst_branches')->where('code', $branchCode)->value('id');
        if ($branchId === 0) {
            $this->error("Stress branch [{$branchCode}] not found. Run: php artisan stress:seed-foundation --env=stress");

            return null;
        }

        $visit = DB::table('trx_clinic_visits')
            ->where('branch_id', $branchId)
            ->orderByDesc('id')
            ->first(['id', 'patient_id']);

        if ($visit === null) {
            $this->error('No clinic visits found for stress benchmark. Run: php artisan stress:seed-rme-history --env=stress');

            return null;
        }

        foreach (['stress.admin001@daengtisia.test', 'stress.cashier001@daengtisia.test', 'stress.owner001@daengtisia.test'] as $email) {
            if (! DB::table('users')->where('email', $email)->exists()) {
                $this->error("Stress user [{$email}] not found. Run: php artisan stress:seed-foundation --env=stress");

                return null;
            }
        }

        return [
            'branch_id' => $branchId,
            'visit_id' => (int) $visit->id,
            'patient_id' => (int) $visit->patient_id,
        ];
    }

    /**
     * @param  array{branch_id:int,visit_id:int,patient_id:int}  $context
     * @return list<array{label:string,method:string,route:string,path:string,session:string}>
     */
    protected function buildTargets(string $baseUrl, array $context, bool $includeOwner): array
    {
        $visitId = $context['visit_id'];
        $branchId = $context['branch_id'];

        $targets = [
            [
                'label' => 'rme_patient_queue',
                'method' => 'GET',
                'route' => 'rme.patient-queue.index',
                'path' => "{$baseUrl}/rme/patient-queue",
                'session' => 'rme',
            ],
            [
                'label' => 'rme_visits',
                'method' => 'GET',
                'route' => 'rme.visits.index',
                'path' => "{$baseUrl}/rme/visits",
                'session' => 'rme',
            ],
            [
                'label' => 'rme_visit_show',
                'method' => 'GET',
                'route' => 'rme.visits.show',
                'path' => "{$baseUrl}/rme/visits/{$visitId}",
                'session' => 'rme',
            ],
            [
                'label' => 'rme_reports_patients',
                'method' => 'GET',
                'route' => 'rme.reports.patients',
                'path' => "{$baseUrl}/rme/reports/patients?branch_id={$branchId}",
                'session' => 'rme',
            ],
            [
                'label' => 'rme_receivables',
                'method' => 'GET',
                'route' => 'rme.cashier.receivables',
                'path' => "{$baseUrl}/rme/cashier/receivables",
                'session' => 'cashier',
            ],
            [
                'label' => 'rme_reports_payments',
                'method' => 'GET',
                'route' => 'rme.reports.payments',
                'path' => "{$baseUrl}/rme/reports/payments?branch_id={$branchId}",
                'session' => 'cashier',
            ],
            [
                'label' => 'rme_dashboard',
                'method' => 'GET',
                'route' => 'rme.dashboard',
                'path' => "{$baseUrl}/rme/dashboard",
                'session' => 'rme',
            ],
            [
                'label' => 'rme_medical_record',
                'method' => 'GET',
                'route' => 'rme.visits.medical-record.show',
                'path' => "{$baseUrl}/rme/visits/{$visitId}/medical-record",
                'session' => 'rme',
            ],
            [
                'label' => 'rme_odontogram',
                'method' => 'GET',
                'route' => 'rme.visits.odontogram.show',
                'path' => "{$baseUrl}/rme/visits/{$visitId}/odontogram",
                'session' => 'rme',
            ],
            [
                'label' => 'rme_cashier',
                'method' => 'GET',
                'route' => 'rme.cashier.index',
                'path' => "{$baseUrl}/rme/cashier",
                'session' => 'cashier',
            ],
        ];

        if ($includeOwner) {
            $targets[] = [
                'label' => 'owner_dashboard',
                'method' => 'GET',
                'route' => 'dashboard',
                'path' => "{$baseUrl}/dashboard",
                'session' => 'owner',
            ];
            $targets[] = [
                'label' => 'owner_dashboard_kpi_month',
                'method' => 'GET',
                'route' => 'dashboard',
                'path' => "{$baseUrl}/dashboard?range=month",
                'session' => 'owner',
            ];
            $targets[] = [
                'label' => 'owner_dashboard_branch',
                'method' => 'GET',
                'route' => 'dashboard',
                'path' => "{$baseUrl}/dashboard?branch_id={$branchId}&range=month",
                'session' => 'owner',
            ];
        }

        return $targets;
    }

    /**
     * @return array<string, string>|null
     */
    private function openAuthSessions(string $baseUrl, int $timeoutMs, string $branchCode): ?array
    {
        $password = (string) env('STRESS_LOGIN_PASSWORD', 'Password123!');

        $definitions = [
            'rme' => ['email' => 'stress.admin001@daengtisia.test', 'online_context' => true],
            'cashier' => ['email' => 'stress.cashier001@daengtisia.test', 'online_context' => true],
            'owner' => ['email' => 'stress.owner001@daengtisia.test', 'online_context' => false],
        ];

        $sessions = [];

        foreach ($definitions as $key => $definition) {
            $cookieJar = tempnam(sys_get_temp_dir(), "stress-bench-{$key}-");
            if ($cookieJar === false) {
                $this->error('Could not create temp cookie jar.');

                return null;
            }

            try {
                $this->authenticate($baseUrl, $definition['email'], $password, $cookieJar, $timeoutMs);
                if ($definition['online_context']) {
                    $this->setAdminOnlineContext($baseUrl, $cookieJar, $timeoutMs, $branchCode);
                }
            } catch (\Throwable $exception) {
                @unlink($cookieJar);
                $this->error("Authentication failed for {$definition['email']}: {$exception->getMessage()}");
                $this->line('Ensure the stress server is running, e.g. php artisan serve --env=stress --port=8008');

                return null;
            }

            $sessions[$key] = $cookieJar;
        }

        return $sessions;
    }

    /**
     * @param  array{label:string,method:string,route:string,path:string,session:string}  $target
     * @return array{
     *     label:string,
     *     method:string,
     *     route:string,
     *     path:string,
     *     status:int,
     *     success_count:int,
     *     error_count:int,
     *     avg_ms:float,
     *     p50_ms:float,
     *     p95_ms:float,
     *     max_ms:float,
     *     error_message:?string
     * }
     */
    private function benchmarkTarget(array $target, string $cookieJar, int $runs, int $warmup, int $timeoutMs): array
    {
        $times = [];
        $successCount = 0;
        $errorCount = 0;
        $lastStatus = 0;
        $errorMessage = null;

        try {
            for ($i = 0; $i < $warmup; $i++) {
                $this->request($target['method'], $target['path'], $cookieJar, timeoutMs: $timeoutMs);
            }

            for ($i = 0; $i < $runs; $i++) {
                $result = $this->request($target['method'], $target['path'], $cookieJar, returnTiming: true, timeoutMs: $timeoutMs);
                $lastStatus = $result['code'];
                $times[] = $result['time_ms'];

                if ($result['code'] >= 200 && $result['code'] < 400) {
                    $successCount++;
                } else {
                    $errorCount++;
                    $errorMessage = "HTTP {$result['code']}";
                }
            }
        } catch (\Throwable $exception) {
            $errorCount = max(1, $runs - $successCount);
            $errorMessage = $exception->getMessage();
        }

        if ($times === []) {
            return [
                'label' => $target['label'],
                'method' => $target['method'],
                'route' => $target['route'],
                'path' => $target['path'],
                'status' => $lastStatus,
                'success_count' => $successCount,
                'error_count' => $errorCount,
                'avg_ms' => 0.0,
                'p50_ms' => 0.0,
                'p95_ms' => 0.0,
                'max_ms' => 0.0,
                'error_message' => $errorMessage,
            ];
        }

        sort($times);
        $count = count($times);
        $p50Index = (int) floor(($count - 1) * 0.50);
        $p95Index = max(0, (int) ceil($count * 0.95) - 1);

        return [
            'label' => $target['label'],
            'method' => $target['method'],
            'route' => $target['route'],
            'path' => $target['path'],
            'status' => $lastStatus,
            'success_count' => $successCount,
            'error_count' => $errorCount,
            'avg_ms' => array_sum($times) / $count,
            'p50_ms' => $times[$p50Index],
            'p95_ms' => $times[$p95Index],
            'max_ms' => $times[$count - 1],
            'error_message' => $errorMessage,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $targets
     */
    private function printDryRunSummary(array $targets, int $runs, int $warmup, string $branchCode, bool $asJson): void
    {
        if ($asJson) {
            $this->line(json_encode([
                'dry_run' => true,
                'environment' => app()->environment(),
                'branch_code' => $branchCode,
                'runs' => $runs,
                'warmup' => $warmup,
                'targets' => array_map(fn (array $target) => [
                    'label' => $target['label'],
                    'method' => $target['method'],
                    'route' => $target['route'],
                    'path' => $target['path'],
                    'session' => $target['session'],
                ], $targets),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return;
        }

        $this->info('Dry run — targets resolved, no HTTP requests made.');
        $this->table(
            ['label', 'method', 'route', 'session', 'path'],
            array_map(fn (array $target) => [
                $target['label'],
                $target['method'],
                $target['route'],
                $target['session'],
                $target['path'],
            ], $targets),
        );
    }

    /**
     * @param  list<array<string, mixed>>  $results
     */
    private function printTableSummary(array $results, int $runs, int $warmup, string $branchCode, array $context): void
    {
        $this->info(sprintf(
            'Benchmark complete — env=%s branch=%s visit_id=%d runs=%d warmup=%d',
            app()->environment(),
            $branchCode,
            $context['visit_id'],
            $runs,
            $warmup,
        ));

        $this->table(
            ['label', 'status', 'ok', 'err', 'avg_ms', 'p50_ms', 'p95_ms', 'max_ms', 'route'],
            array_map(fn (array $row) => [
                $row['label'],
                $row['status'],
                $row['success_count'],
                $row['error_count'],
                number_format($row['avg_ms'], 2),
                number_format($row['p50_ms'], 2),
                number_format($row['p95_ms'], 2),
                number_format($row['max_ms'], 2),
                $row['route'],
            ], $results),
        );

        foreach ($results as $row) {
            if ($row['error_message'] !== null) {
                $this->warn("{$row['label']}: {$row['error_message']}");
            }
        }
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function jsonRow(array $row): array
    {
        return [
            'label' => $row['label'],
            'method' => $row['method'],
            'route' => $row['route'],
            'path' => $row['path'],
            'status' => $row['status'],
            'success_count' => $row['success_count'],
            'error_count' => $row['error_count'],
            'avg_ms' => round($row['avg_ms'], 2),
            'p50_ms' => round($row['p50_ms'], 2),
            'p95_ms' => round($row['p95_ms'], 2),
            'max_ms' => round($row['max_ms'], 2),
            'error_message' => $row['error_message'],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $results
     */
    private function buildCsvOutput(array $results, int $runs, int $warmup, string $baseUrl, array $context): string
    {
        $lines = [];
        $lines[] = '=== RME HTTP benchmark '.now()->toIso8601String().' ===';
        $lines[] = "BASE_URL={$baseUrl} RUNS={$runs} WARMUP={$warmup} VISIT_ID={$context['visit_id']}";
        $lines[] = 'label,status,success,errors,avg_ms,p50_ms,p95_ms,max_ms,route,path';

        foreach ($results as $row) {
            $lines[] = sprintf(
                '%s,%d,%d,%d,%.2f,%.2f,%.2f,%.2f,%s,%s',
                $row['label'],
                $row['status'],
                $row['success_count'],
                $row['error_count'],
                $row['avg_ms'],
                $row['p50_ms'],
                $row['p95_ms'],
                $row['max_ms'],
                $row['route'],
                $row['path'],
            );
        }

        return implode(PHP_EOL, $lines).PHP_EOL;
    }

    private function authenticate(string $baseUrl, string $email, string $password, string $cookieJar, int $timeoutMs): void
    {
        $loginHtml = $this->request('GET', "{$baseUrl}/login", $cookieJar, timeoutMs: $timeoutMs);
        $token = $this->extractCsrfToken($loginHtml);

        $this->request('POST', "{$baseUrl}/login", $cookieJar, [
            'email' => $email,
            'password' => $password,
            '_token' => $token,
        ], timeoutMs: $timeoutMs);
    }

    private function setAdminOnlineContext(string $baseUrl, string $cookieJar, int $timeoutMs, string $branchCode): void
    {
        $branchId = (int) DB::table('mst_branches')->where('code', $branchCode)->value('id');

        $selectHtml = $this->request('GET', "{$baseUrl}/rme/online-context/select", $cookieJar, timeoutMs: $timeoutMs);
        $token = $this->extractCsrfToken($selectHtml);

        $this->request('POST', "{$baseUrl}/rme/online-context/admin-clinic", $cookieJar, [
            '_token' => $token,
            'branch_id' => (string) $branchId,
        ], timeoutMs: $timeoutMs);
    }

    /**
     * @param  array<string, string>  $fields
     * @return ($returnTiming is true ? array{code:int,time_ms:float} : string)
     */
    private function request(
        string $method,
        string $url,
        string $cookieJar,
        array $fields = [],
        bool $returnTiming = false,
        int $timeoutMs = 5000,
    ): array|string {
        $ch = curl_init($url);
        $timeoutSeconds = max(1, (int) ceil($timeoutMs / 1000));

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_COOKIEJAR => $cookieJar,
            CURLOPT_COOKIEFILE => $cookieJar,
            CURLOPT_CONNECTTIMEOUT => min(5, $timeoutSeconds),
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HEADER => false,
        ]);

        if ($method === 'POST' && $fields !== []) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields));
        }

        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $timeMs = (float) curl_getinfo($ch, CURLINFO_TOTAL_TIME) * 1000;
        $error = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            throw new \RuntimeException("Request failed for {$url}: {$error}");
        }

        if ($returnTiming) {
            return ['code' => $code, 'time_ms' => $timeMs];
        }

        return $body;
    }

    private function extractCsrfToken(string $html): string
    {
        if (preg_match('/name="_token"\s+value="([^"]+)"/', $html, $matches) !== 1) {
            throw new \RuntimeException('CSRF token not found in response HTML.');
        }

        return $matches[1];
    }

    private function currentDatabase(): string
    {
        return (string) config('database.connections.'.config('database.default').'.database');
    }
}
