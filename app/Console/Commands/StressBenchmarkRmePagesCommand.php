<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class StressBenchmarkRmePagesCommand extends Command
{
    protected $signature = 'stress:benchmark-rme-pages
        {--runs=5 : Number of timed requests per page}
        {--base-url=http://127.0.0.1:8008 : Stress app base URL}
        {--email=stress.admin001@daengtisia.test : Stress login email}
        {--output= : Optional file path for benchmark output}';

    protected $description = 'Benchmark authenticated RME pages against local stress server. APP_ENV=stress only.';

    public function handle(): int
    {
        if (! app()->environment('stress')) {
            $this->error('This command only runs in APP_ENV=stress.');

            return self::FAILURE;
        }

        if (config('database.connections.pgsql.database') !== 'daengtisia_stress') {
            $this->error('Refusing to run: DB_DATABASE must be daengtisia_stress.');

            return self::FAILURE;
        }

        $runs = max(1, (int) $this->option('runs'));
        $baseUrl = rtrim((string) $this->option('base-url'), '/');
        $email = (string) $this->option('email');
        $password = (string) env('STRESS_LOGIN_PASSWORD', 'Password123!');

        $cookieJar = tempnam(sys_get_temp_dir(), 'stress-bench-');
        if ($cookieJar === false) {
            $this->error('Could not create temp cookie jar.');

            return self::FAILURE;
        }

        try {
            $this->authenticate($baseUrl, $email, $password, $cookieJar);
            $this->setAdminOnlineContext($baseUrl, $cookieJar);

            $visitId = (int) DB::table('trx_clinic_visits')
                ->where('visit_number', 'like', 'TST-VIS-2026-%')
                ->max('id');

            $pages = [
                'rme_dashboard' => "{$baseUrl}/rme/dashboard",
                'rme_visits' => "{$baseUrl}/rme/visits",
                'rme_visit_show' => "{$baseUrl}/rme/visits/{$visitId}",
                'rme_medical_record' => "{$baseUrl}/rme/visits/{$visitId}/medical-record",
                'rme_odontogram' => "{$baseUrl}/rme/visits/{$visitId}/odontogram",
                'rme_cashier' => "{$baseUrl}/rme/cashier",
                'rme_receivables' => "{$baseUrl}/rme/cashier/receivables",
                'rme_reports_patients' => "{$baseUrl}/rme/reports/patients",
                'rme_reports_payments' => "{$baseUrl}/rme/reports/payments",
            ];

            $lines = [];
            $lines[] = '=== Stage RME benchmark '.now()->toIso8601String().' ===';
            $lines[] = "BASE_URL={$baseUrl} RUNS={$runs} VISIT_ID={$visitId}";
            $lines[] = 'label,http,n,min,avg,max,p95,url';

            $summaryRows = [];

            foreach ($pages as $label => $url) {
                $result = $this->benchmarkUrl($url, $cookieJar, $runs);
                $lines[] = sprintf(
                    '%s,%d,%d,%.6f,%.6f,%.6f,%.6f,%s',
                    $label,
                    $result['code'],
                    $runs,
                    $result['min'],
                    $result['avg'],
                    $result['max'],
                    $result['p95'],
                    $url,
                );
                $summaryRows[] = [
                    $label,
                    $result['code'],
                    $runs,
                    number_format($result['min'], 4),
                    number_format($result['avg'], 4),
                    number_format($result['max'], 4),
                    number_format($result['p95'], 4),
                ];
            }

            $output = implode(PHP_EOL, $lines).PHP_EOL;
            $this->line($output);

            $this->table(['label', 'http', 'n', 'min', 'avg', 'max', 'p95'], $summaryRows);

            if ($path = $this->option('output')) {
                file_put_contents($path, $output);
                $this->info("Wrote benchmark output to {$path}");
            }
        } finally {
            @unlink($cookieJar);
        }

        return self::SUCCESS;
    }

    private function authenticate(string $baseUrl, string $email, string $password, string $cookieJar): void
    {
        $loginHtml = $this->request('GET', "{$baseUrl}/login", $cookieJar);
        $token = $this->extractCsrfToken($loginHtml);

        $this->request('POST', "{$baseUrl}/login", $cookieJar, [
            'email' => $email,
            'password' => $password,
            '_token' => $token,
        ]);
    }

    private function setAdminOnlineContext(string $baseUrl, string $cookieJar): void
    {
        $branchId = (int) DB::table('mst_branches')->where('code', 'TST')->value('id');

        $selectHtml = $this->request('GET', "{$baseUrl}/rme/online-context/select", $cookieJar);
        $token = $this->extractCsrfToken($selectHtml);

        $this->request('POST', "{$baseUrl}/rme/online-context/admin-clinic", $cookieJar, [
            '_token' => $token,
            'branch_id' => (string) $branchId,
        ]);
    }

    /**
     * @return array{code:int,min:float,avg:float,max:float,p95:float}
     */
    private function benchmarkUrl(string $url, string $cookieJar, int $runs): array
    {
        $times = [];

        for ($i = 0; $i < $runs; $i++) {
            $result = $this->request('GET', $url, $cookieJar, returnTiming: true);
            $times[] = $result['time'];
        }

        sort($times);
        $count = count($times);
        $p95Index = max(0, (int) ceil($count * 0.95) - 1);

        $last = $this->request('GET', $url, $cookieJar, returnTiming: true);

        return [
            'code' => $last['code'],
            'min' => $times[0],
            'avg' => array_sum($times) / $count,
            'max' => $times[$count - 1],
            'p95' => $times[$p95Index],
        ];
    }

    /**
     * @param  array<string, string>  $fields
     * @return ($returnTiming is true ? array{code:int,time:float,body:string} : string)
     */
    private function request(
        string $method,
        string $url,
        string $cookieJar,
        array $fields = [],
        bool $returnTiming = false,
    ): array|string {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_COOKIEJAR => $cookieJar,
            CURLOPT_COOKIEFILE => $cookieJar,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CUSTOMREQUEST => $method,
        ]);

        if ($method === 'POST' && $fields !== []) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields));
        }

        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $time = (float) curl_getinfo($ch, CURLINFO_TOTAL_TIME);
        $error = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            throw new \RuntimeException("Request failed for {$url}: {$error}");
        }

        if ($returnTiming) {
            return ['code' => $code, 'time' => $time, 'body' => $body];
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
}
