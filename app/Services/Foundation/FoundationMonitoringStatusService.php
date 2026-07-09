<?php

namespace App\Services\Foundation;

use App\Support\DeveloperConsole\SensitiveValueMasker;
use App\Support\Health\HealthCheckService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * MON-1 — Foundation Monitoring & Observability status consolidation.
 *
 * Read-only. Aggregates ALREADY-EXISTING observability signals (ENT-8 health,
 * ENT-5 failed jobs, ENT-12 backups, NSF-10 evidence, storage/cache writability,
 * application log, governance/domain audit evidence) into ONE explainable
 * decision: GO | WATCH | FAIL | UNKNOWN.
 *
 * Guarantees:
 *  - Never re-implements a health probe, a smoke gate, a deploy-evidence
 *    pipeline, or a domain audit — it reuses/reads them.
 *  - Never throws out of collect(): every signal is independently guarded and
 *    degrades to UNKNOWN on error (missing file/table/permission).
 *  - Never exposes secrets, env values, DB credentials, raw stack traces,
 *    KTP/NIK, or raw failed-job payloads — only sanitized counts/metadata.
 *  - Never mutates runtime state (no chmod, no queue retry, no log clear).
 *  - Never runs an expensive command on a web request; audit command execution
 *    is opt-in and CLI-only via the `include_audits` option.
 */
class FoundationMonitoringStatusService
{
    public const GO = 'GO';

    public const WATCH = 'WATCH';

    public const FAIL = 'FAIL';

    public const UNKNOWN = 'UNKNOWN';

    public function __construct(
        private readonly HealthCheckService $health,
        private readonly SensitiveValueMasker $masker,
    ) {}

    /**
     * Collect all signals and compute the consolidated decision.
     *
     * @param  array{include_audits?: bool}  $options
     */
    public function collect(array $options = []): array
    {
        $includeAudits = (bool) ($options['include_audits'] ?? false);

        $signals = [
            $this->runtimeSignal(),
            $this->healthLivenessSignal(),
            $this->healthReadinessSignal(),
            $this->loadBalancerSignal(),
            $this->storageCacheWritableSignal(),
            $this->failedJobsSignal(),
            $this->queueWorkerSignal(),
            $this->deployBackupSignal(),
            $this->deployEvidenceSignal(),
            $this->applicationLogSignal(),
        ];

        foreach ($this->auditSignals($includeAudits) as $auditSignal) {
            $signals[] = $auditSignal;
        }

        $decision = $this->decide($signals);
        $unsafe = collect($signals)->contains(fn ($s) => ! empty($s['unsafe']));

        return [
            'decision' => $decision,
            'unsafe' => $unsafe,
            'generated_at' => now()->toIso8601String(),
            'include_audits' => $includeAudits,
            'summary' => $this->summaryCounts($signals),
            'reasons' => $this->reasons($signals),
            'signals' => $signals,
        ];
    }

    /**
     * Pure aggregation of signal statuses into one decision. Unit-testable.
     *
     * @param  array<int, array{status?: string}>  $signals
     */
    public function decide(array $signals): string
    {
        $statuses = array_map(fn ($s) => (string) ($s['status'] ?? self::UNKNOWN), $signals);

        if (in_array(self::FAIL, $statuses, true)) {
            return self::FAIL;
        }
        if (in_array(self::WATCH, $statuses, true)) {
            return self::WATCH;
        }
        if ($statuses === [] || ! in_array(self::GO, $statuses, true)) {
            return self::UNKNOWN;
        }

        return self::GO;
    }

    // ---------------------------------------------------------------------
    // Individual signals
    // ---------------------------------------------------------------------

    private function runtimeSignal(): array
    {
        return $this->guard('app_runtime', 'Runtime — Aplikasi', function () {
            $env = (string) app()->environment();
            $debug = (bool) config('app.debug');
            $maintenance = (bool) app()->isDownForMaintenance();
            $productionLike = in_array($env, (array) config('foundation_monitoring.production_like_environments', []), true);

            $status = self::GO;
            $unsafe = false;
            $notes = [];

            if ($debug && $productionLike) {
                $status = self::FAIL;
                $unsafe = true;
                $notes[] = 'APP_DEBUG aktif di environment produksi/pilot.';
            }
            if ($maintenance && $productionLike) {
                $status = self::FAIL;
                $unsafe = true;
                $notes[] = 'Maintenance mode aktif tak terduga.';
            }

            return [
                'status' => $status,
                'unsafe' => $unsafe,
                'summary' => sprintf('env=%s debug=%s maintenance=%s', $env, $debug ? 'on' : 'off', $maintenance ? 'on' : 'off'),
                'remediation' => $unsafe ? 'Matikan APP_DEBUG di produksi dan/atau `php artisan up`.' : null,
                'details' => [
                    'environment' => $env,
                    'debug' => $debug,
                    'maintenance' => $maintenance,
                    'php_version' => PHP_VERSION,
                    'laravel_version' => app()->version(),
                    'commit' => $this->safeGit('git rev-parse --short HEAD'),
                    'tag' => $this->safeGit('git describe --tags --exact-match HEAD'),
                ],
                'notes' => $notes,
            ];
        });
    }

    private function healthLivenessSignal(): array
    {
        return $this->guard('health_live', (string) config('foundation_monitoring.signals.health_live.label', 'Health — Liveness'), function () {
            $payload = $this->health->liveness();
            $ok = ($payload['status'] ?? null) === HealthCheckService::STATUS_OK;

            return [
                'status' => $ok ? self::GO : self::FAIL,
                'unsafe' => ! $ok,
                'summary' => 'status='.($payload['status'] ?? 'unknown'),
                'remediation' => $ok ? null : 'Periksa /health/live dan proses PHP-FPM.',
                'details' => ['check' => $payload['check'] ?? 'live', 'status' => $payload['status'] ?? null],
            ];
        });
    }

    private function healthReadinessSignal(): array
    {
        return $this->guard('health_ready', (string) config('foundation_monitoring.signals.health_ready.label', 'Health — Readiness'), function () {
            $payload = $this->health->readiness();
            $overall = (string) ($payload['status'] ?? HealthCheckService::STATUS_DOWN);

            $status = match ($overall) {
                HealthCheckService::STATUS_OK => self::GO,
                HealthCheckService::STATUS_DEGRADED => self::WATCH,
                default => self::FAIL,
            };

            return [
                'status' => $status,
                'unsafe' => $status === self::FAIL,
                'summary' => 'status='.$overall,
                'remediation' => $status === self::GO ? null : 'Lihat komponen non-ok pada /health/ready (DB/cache/queue/storage).',
                'details' => [
                    'overall' => $overall,
                    'components' => (array) ($payload['components'] ?? []),
                ],
            ];
        });
    }

    private function loadBalancerSignal(): array
    {
        return $this->guard('health_lb', 'Health — Load Balancer', function () {
            $present = Route::has('health.lb');

            return [
                'status' => $present ? self::GO : self::WATCH,
                'unsafe' => false,
                'summary' => $present ? 'endpoint /health/lb terdaftar' : '/health/lb tidak terdaftar',
                'remediation' => $present ? null : 'Aktifkan LB_HEALTH_ENDPOINT_ENABLED bila di belakang load balancer.',
                'details' => ['route_registered' => $present],
            ];
        });
    }

    /**
     * Scope E — storage/cache writability. Report-only, never chmod.
     */
    private function storageCacheWritableSignal(): array
    {
        return $this->guard('storage_cache_writable', 'Storage / Cache — Writable', function () {
            $paths = (array) config('foundation_monitoring.writable_paths', []);
            $results = [];
            $failing = [];

            foreach ($paths as $rel) {
                $check = $this->probeWritable(base_path($rel));
                $results[] = ['path' => $rel] + $check;
                if (! $check['writable']) {
                    $failing[] = $rel;
                }
            }

            $ok = $failing === [];

            return [
                'status' => $ok ? self::GO : self::FAIL,
                'unsafe' => ! $ok,
                'summary' => $ok
                    ? 'semua path cache/log/bootstrap dapat ditulis'
                    : 'tidak dapat menulis: '.implode(', ', $failing),
                'remediation' => $ok
                    ? null
                    : 'Reset kepemilikan/izin storage & bootstrap/cache lewat deploy runner (chown www-data, chmod ug+rwx). Jangan chmod dari UI.',
                'details' => ['paths' => $results],
            ];
        });
    }

    /**
     * Create a short-lived temp file to confirm real write access, then delete.
     *
     * @return array{exists: bool, writable: bool, reason: ?string}
     */
    private function probeWritable(string $absolute): array
    {
        if (! is_dir($absolute)) {
            return ['exists' => false, 'writable' => false, 'reason' => 'directory missing'];
        }
        if (! is_writable($absolute)) {
            return ['exists' => true, 'writable' => false, 'reason' => 'not writable'];
        }

        $probe = rtrim($absolute, '/').'/.mon1-writable-'.substr(md5((string) getmypid().microtime()), 0, 10).'.tmp';

        try {
            $bytes = @file_put_contents($probe, 'mon1');
            if ($bytes === false) {
                return ['exists' => true, 'writable' => false, 'reason' => 'write failed'];
            }

            return ['exists' => true, 'writable' => true, 'reason' => null];
        } catch (Throwable) {
            return ['exists' => true, 'writable' => false, 'reason' => 'write exception'];
        } finally {
            if (is_file($probe)) {
                @unlink($probe);
            }
        }
    }

    private function failedJobsSignal(): array
    {
        return $this->guard('queue_failed_jobs', 'Queue — Failed Jobs', function () {
            if (! Schema::hasTable('failed_jobs')) {
                return [
                    'status' => self::UNKNOWN,
                    'unsafe' => false,
                    'summary' => 'tabel failed_jobs tidak ada',
                    'remediation' => 'Jalankan migrasi queue bila queue database dipakai.',
                    'details' => ['table_exists' => false, 'connection' => (string) config('queue.default')],
                ];
            }

            $count = (int) DB::table('failed_jobs')->count();
            $watch = (int) config('foundation_monitoring.thresholds.failed_jobs_watch', 1);
            $status = $count >= $watch ? self::WATCH : self::GO;

            return [
                'status' => $status,
                'unsafe' => false,
                'summary' => $count.' failed job(s)',
                'remediation' => $status === self::WATCH ? 'Tinjau via CLI `queue:failed`; jangan retry/hapus dari UI monitoring.' : null,
                'details' => ['table_exists' => true, 'failed_count' => $count, 'connection' => (string) config('queue.default')],
            ];
        });
    }

    private function queueWorkerSignal(): array
    {
        return $this->guard('queue_worker', 'Queue — Worker / Scheduler', function () {
            // No reliable in-app source of truth for worker/scheduler liveness.
            // Never fake a green status — report UNKNOWN with a clear pointer.
            return [
                'status' => self::UNKNOWN,
                'unsafe' => false,
                'summary' => 'status worker/scheduler tidak dapat dipastikan dari aplikasi',
                'remediation' => 'Cek di VPS: `systemctl status daengtisiams-queue-worker` / crontab scheduler.',
                'details' => ['connection' => (string) config('queue.default'), 'source' => 'unavailable'],
            ];
        });
    }

    private function deployBackupSignal(): array
    {
        return $this->guard('deploy_backup', 'Deploy — Latest Backup', function () {
            $dir = base_path((string) config('foundation_monitoring.paths.backup_directory'));

            if (! is_dir($dir)) {
                return [
                    'status' => self::WATCH,
                    'unsafe' => false,
                    'summary' => 'direktori backup belum ada',
                    'remediation' => 'Backup dibuat otomatis saat deploy (scripts/deploy-vps-runner.sh).',
                    'details' => ['directory_exists' => false],
                ];
            }

            $files = glob(rtrim($dir, '/').'/*') ?: [];
            $files = array_values(array_filter($files, 'is_file'));

            if ($files === []) {
                return [
                    'status' => self::WATCH,
                    'unsafe' => false,
                    'summary' => 'belum ada file backup',
                    'remediation' => 'Backup dibuat otomatis saat deploy berikutnya.',
                    'details' => ['directory_exists' => true, 'backup_count' => 0],
                ];
            }

            usort($files, fn ($a, $b) => filemtime($b) <=> filemtime($a));
            $latest = $files[0];
            $ageHours = (time() - (int) filemtime($latest)) / 3600;
            $staleHours = (float) config('foundation_monitoring.thresholds.backup_stale_hours', 48);
            $stale = $ageHours > $staleHours;

            return [
                'status' => $stale ? self::WATCH : self::GO,
                'unsafe' => false,
                'summary' => sprintf('backup terakhir %s (%.1f jam lalu)', basename($latest), $ageHours),
                'remediation' => $stale ? 'Backup terakhir sudah lama; verifikasi jadwal backup/deploy.' : null,
                'details' => [
                    'directory_exists' => true,
                    'backup_count' => count($files),
                    'latest_file' => basename($latest),
                    'latest_size_bytes' => (int) filesize($latest),
                    'latest_age_hours' => round($ageHours, 1),
                ],
            ];
        });
    }

    private function deployEvidenceSignal(): array
    {
        return $this->guard('deploy_evidence', 'Deploy — Governance Evidence', function () {
            $decision = $this->readEvidenceDecision(
                (string) config('foundation_monitoring.paths.governance_summary_artifact')
            );

            if ($decision === null) {
                return [
                    'status' => self::UNKNOWN,
                    'unsafe' => false,
                    'summary' => 'belum ada evidence governance tersimpan',
                    'remediation' => 'Dihasilkan oleh deploy/CI (release:evidence-capture).',
                    'details' => ['artifact_present' => false],
                ];
            }

            $normalized = strtoupper($decision);
            $status = match (true) {
                in_array($normalized, ['NO-GO', 'NO_GO', 'FAIL'], true) => self::FAIL,
                in_array($normalized, ['WATCH'], true) => self::WATCH,
                in_array($normalized, ['GO', 'PASS'], true) => self::GO,
                default => self::UNKNOWN,
            };

            return [
                'status' => $status,
                'unsafe' => $status === self::FAIL,
                'summary' => 'keputusan governance terakhir: '.$normalized,
                'remediation' => $status === self::FAIL ? 'Governance summary NO-GO; jalankan foundation:* checks di CLI.' : null,
                'details' => ['artifact_present' => true, 'decision' => $normalized],
            ];
        });
    }

    private function applicationLogSignal(): array
    {
        return $this->guard('laravel_log', 'Application Log — Recent Errors', function () {
            $path = storage_path((string) config('foundation_monitoring.paths.laravel_log'));

            if (! is_file($path)) {
                return [
                    'status' => self::GO,
                    'unsafe' => false,
                    'summary' => 'belum ada file log',
                    'remediation' => null,
                    'details' => ['log_exists' => false],
                ];
            }

            $lines = $this->tail($path, (int) config('foundation_monitoring.thresholds.log_tail_lines', 200));
            $errors = 0;
            $warnings = 0;
            foreach ($lines as $line) {
                if (preg_match('/\.(ERROR|CRITICAL|ALERT|EMERGENCY):/', $line)) {
                    $errors++;
                } elseif (preg_match('/\.WARNING:/', $line)) {
                    $warnings++;
                }
            }

            $watch = (int) config('foundation_monitoring.thresholds.log_error_watch', 1);
            $status = $errors >= $watch ? self::WATCH : self::GO;

            // Sanitized single-line excerpt of the most recent error, masked.
            $lastErrorExcerpt = null;
            for ($i = count($lines) - 1; $i >= 0; $i--) {
                if (preg_match('/\.(ERROR|CRITICAL|ALERT|EMERGENCY):/', $lines[$i])) {
                    $lastErrorExcerpt = $this->masker->mask(mb_substr(trim($lines[$i]), 0, 200));
                    break;
                }
            }

            return [
                'status' => $status,
                'unsafe' => false,
                'summary' => sprintf('%d error, %d warning (window %d baris)', $errors, $warnings, count($lines)),
                'remediation' => $status === self::WATCH ? 'Tinjau ringkasan error; stack trace penuh hanya via CLI/VPS, bukan di UI.' : null,
                'details' => [
                    'log_exists' => true,
                    'error_count' => $errors,
                    'warning_count' => $warnings,
                    'window_lines' => count($lines),
                    'last_error_excerpt' => $lastErrorExcerpt,
                ],
            ];
        });
    }

    /**
     * Governance/domain audit signals. Default: read cached evidence decision
     * (static metadata — no execution). include_audits: invoke includable
     * audits (CLI only) and report exit status. Never re-implements audit logic.
     *
     * @return array<int, array>
     */
    private function auditSignals(bool $includeAudits): array
    {
        $registry = (array) config('foundation_monitoring.signals', []);
        $includable = (array) config('foundation_monitoring.includable_audits', []);
        $out = [];

        foreach ($registry as $key => $meta) {
            if (($meta['type'] ?? null) !== 'command') {
                continue;
            }

            $out[] = $this->guard($key, (string) ($meta['label'] ?? $key), function () use ($key, $meta, $includeAudits, $includable) {
                $command = $this->commandName((string) ($meta['source'] ?? ''));

                if ($includeAudits && $command !== null && array_key_exists($command, $includable)) {
                    $code = $this->runAudit($command, (array) $includable[$command]);
                    $pass = $code === 0;

                    return [
                        'status' => $pass ? self::GO : self::FAIL,
                        'unsafe' => ! $pass,
                        'summary' => sprintf('%s exit=%d', $command, $code),
                        'remediation' => $pass ? null : "Audit gagal — jalankan `php artisan {$command} --strict` untuk detail.",
                        'details' => ['command' => $command, 'executed' => true, 'exit_code' => $code, 'owner' => $meta['owner'] ?? null],
                    ];
                }

                $artifact = $meta['evidence_artifact'] ?? null;
                $decision = $artifact ? $this->readEvidenceDecision((string) $artifact) : null;

                if ($decision === null) {
                    return [
                        'status' => self::UNKNOWN,
                        'unsafe' => false,
                        'summary' => 'status tidak dijalankan otomatis — gunakan CLI',
                        'remediation' => 'Jalankan `php artisan '.($meta['source'] ?? $key).'` untuk audit penuh.',
                        'details' => ['command' => $command, 'executed' => false, 'evidence_present' => false, 'owner' => $meta['owner'] ?? null],
                    ];
                }

                $normalized = strtoupper($decision);
                $status = match (true) {
                    in_array($normalized, ['NO-GO', 'NO_GO', 'FAIL'], true) => self::FAIL,
                    $normalized === 'WATCH' => self::WATCH,
                    in_array($normalized, ['GO', 'PASS'], true) => self::GO,
                    default => self::UNKNOWN,
                };

                return [
                    'status' => $status,
                    'unsafe' => $status === self::FAIL,
                    'summary' => 'evidence terakhir: '.$normalized,
                    'remediation' => $status === self::FAIL ? 'Jalankan `php artisan '.($meta['source'] ?? $key).'` untuk detail.' : null,
                    'details' => ['command' => $command, 'executed' => false, 'evidence_present' => true, 'decision' => $normalized, 'owner' => $meta['owner'] ?? null],
                ];
            });
        }

        return $out;
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    /**
     * Wrap a signal producer so it always returns a normalized array and never
     * throws. On error the signal degrades to UNKNOWN (never a false FAIL/GO).
     */
    private function guard(string $key, string $label, callable $producer): array
    {
        try {
            $signal = $producer();
        } catch (Throwable $e) {
            $signal = [
                'status' => self::UNKNOWN,
                'unsafe' => false,
                'summary' => 'signal tidak dapat dibaca',
                'remediation' => 'Periksa ketersediaan sumber signal.',
                'details' => ['error' => 'unavailable'],
            ];
        }

        return array_merge([
            'key' => $key,
            'label' => $label,
            'status' => self::UNKNOWN,
            'unsafe' => false,
            'summary' => '',
            'remediation' => null,
            'details' => [],
        ], $signal, ['key' => $key, 'label' => $label]);
    }

    private function commandName(string $source): ?string
    {
        $source = trim($source);
        if ($source === '') {
            return null;
        }

        return explode(' ', $source)[0] ?: null;
    }

    private function runAudit(string $command, array $params): int
    {
        try {
            $normalizedParams = [];
            foreach ($params as $param) {
                $normalizedParams[$param] = true;
            }

            return (int) Artisan::call($command, $normalizedParams);
        } catch (Throwable) {
            return 1;
        }
    }

    private function readEvidenceDecision(string $artifact): ?string
    {
        try {
            $path = base_path((string) config('foundation_monitoring.paths.evidence_directory')).'/'.$artifact;
            if (! is_file($path)) {
                return null;
            }

            $data = json_decode((string) file_get_contents($path), true);
            if (! is_array($data)) {
                return null;
            }

            return $data['decision']
                ?? ($data['combined']['decision'] ?? null)
                ?? ($data['status'] ?? null);
        } catch (Throwable) {
            return null;
        }
    }

    private function safeGit(string $command): ?string
    {
        if (! function_exists('shell_exec')) {
            return null;
        }

        try {
            $output = @shell_exec($command.' 2>/dev/null');

            $value = $output !== null ? trim((string) $output) : '';

            return $value !== '' ? $value : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Tail the last N lines of a file without loading the whole file.
     *
     * @return array<int, string>
     */
    private function tail(string $path, int $lines): array
    {
        $lines = max(1, $lines);
        $result = [];

        try {
            $handle = @fopen($path, 'rb');
            if ($handle === false) {
                return [];
            }

            $buffer = '';
            $chunk = 4096;
            fseek($handle, 0, SEEK_END);
            $pos = ftell($handle);
            $count = 0;

            while ($pos > 0 && $count <= $lines) {
                $read = (int) min($chunk, $pos);
                $pos -= $read;
                fseek($handle, $pos, SEEK_SET);
                $buffer = fread($handle, $read).$buffer;
                $count = substr_count($buffer, "\n");
            }
            fclose($handle);

            $all = explode("\n", $buffer);
            $result = array_slice(array_filter($all, fn ($l) => $l !== ''), -$lines);
        } catch (Throwable) {
            return [];
        }

        return array_values($result);
    }

    /**
     * @param  array<int, array{status?: string}>  $signals
     * @return array<string, int>
     */
    private function summaryCounts(array $signals): array
    {
        $counts = [self::GO => 0, self::WATCH => 0, self::FAIL => 0, self::UNKNOWN => 0];
        foreach ($signals as $signal) {
            $status = (string) ($signal['status'] ?? self::UNKNOWN);
            if (isset($counts[$status])) {
                $counts[$status]++;
            }
        }
        $counts['total'] = count($signals);

        return $counts;
    }

    /**
     * @param  array<int, array>  $signals
     * @return array<int, string>
     */
    private function reasons(array $signals): array
    {
        $reasons = [];
        foreach ($signals as $signal) {
            $status = (string) ($signal['status'] ?? self::UNKNOWN);
            if (in_array($status, [self::FAIL, self::WATCH], true)) {
                $reasons[] = sprintf('[%s] %s — %s', $status, $signal['label'] ?? $signal['key'] ?? '?', $signal['summary'] ?? '');
            }
        }

        return $reasons;
    }
}
