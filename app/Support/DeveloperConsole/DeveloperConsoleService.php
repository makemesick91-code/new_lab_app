<?php

namespace App\Support\DeveloperConsole;

use App\Models\User;
use App\Modules\LabOrder\Models\AuditLog;
use App\Services\Monitoring\RuntimeQueryObservabilityService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * ENT-7 — read-only aggregator behind the Developer Assistance Console.
 *
 * Every section is independently guarded: a failing surface degrades to
 * status "unavailable" and never breaks the page. All free-text excerpts
 * pass through the SensitiveValueMasker before leaving this service.
 */
class DeveloperConsoleService
{
    public function __construct(
        private readonly SensitiveValueMasker $masker,
        private readonly RuntimeQueryObservabilityService $queryObservability,
    ) {}

    /**
     * @return array<string, array<string, mixed>>
     */
    public function overview(): array
    {
        return [
            'application_log' => $this->section(fn () => $this->applicationLog()),
            'failed_jobs' => $this->section(fn () => $this->failedJobs()),
            'audit_events' => $this->section(fn () => $this->auditEvents()),
            'slow_queries' => $this->section(fn () => $this->slowQueries()),
            'deploy_evidence' => $this->section(fn () => $this->deployEvidence()),
            'storage_health' => $this->section(fn () => $this->storageHealth()),
            'runtime_health' => $this->section(fn () => $this->runtimeHealth()),
            'disk_backup' => $this->section(fn () => $this->diskBackup()),
        ];
    }

    /**
     * Immutable audit trail for every console page view (ENT7-DC003).
     */
    public function recordAccess(User $user, ?string $ipAddress): void
    {
        $audit = (array) config('developer_console.audit_access', []);

        if (($audit['enabled'] ?? true) === false) {
            return;
        }

        try {
            AuditLog::create([
                'entity_type' => (string) ($audit['entity_type'] ?? 'developer_console'),
                'entity_id' => null,
                'action' => (string) ($audit['action'] ?? 'VIEW_DEVELOPER_CONSOLE'),
                'performed_by' => $user->id,
                'performed_at' => now(),
                'ip_address' => $ipAddress,
            ]);
        } catch (Throwable $e) {
            Log::warning('developer_console.audit_write_failed', ['reason' => $e->getMessage()]);
        }

        // OBS-1 middleware already attaches request_id/correlation_id context.
        Log::info('developer_console.accessed', ['user_id' => $user->id]);
    }

    /**
     * @param  callable(): array<string, mixed>  $builder
     * @return array<string, mixed>
     */
    private function section(callable $builder): array
    {
        try {
            return ['status' => 'ok'] + $builder();
        } catch (Throwable $e) {
            return ['status' => 'unavailable', 'reason' => $this->masker->mask($e->getMessage())];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function applicationLog(): array
    {
        $config = (array) config('developer_console.application_log', []);
        $path = storage_path((string) ($config['path'] ?? 'logs/laravel.log'));
        $tail = max(1, (int) ($config['tail_lines'] ?? 80));
        $maxLength = max(80, (int) ($config['max_line_length'] ?? 300));

        if (! is_file($path)) {
            return ['exists' => false, 'lines' => [], 'error_count' => 0];
        }

        $lines = $this->tailFile($path, $tail);
        $masked = [];
        $errorCount = 0;

        foreach ($lines as $line) {
            if (str_contains($line, '.ERROR:') || str_contains($line, '.CRITICAL:')) {
                $errorCount++;
            }
            $masked[] = mb_substr($this->masker->mask($line), 0, $maxLength);
        }

        return [
            'exists' => true,
            'size_bytes' => (int) filesize($path),
            'lines' => array_reverse($masked),
            'error_count' => $errorCount,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function failedJobs(): array
    {
        if (! Schema::hasTable('failed_jobs')) {
            return ['table_exists' => false, 'total' => 0, 'recent' => []];
        }

        $config = (array) config('developer_console.failed_jobs', []);
        $limit = max(1, (int) ($config['limit'] ?? 10));
        $maxException = max(80, (int) ($config['max_exception_length'] ?? 200));

        $recent = DB::table('failed_jobs')
            ->orderByDesc('failed_at')
            ->limit($limit)
            ->get(['uuid', 'connection', 'queue', 'exception', 'failed_at'])
            ->map(fn ($row) => [
                'uuid' => (string) $row->uuid,
                'connection' => (string) $row->connection,
                'queue' => (string) $row->queue,
                'failed_at' => (string) $row->failed_at,
                'exception_excerpt' => mb_substr(
                    $this->masker->mask(strtok((string) $row->exception, "\n") ?: ''),
                    0,
                    $maxException,
                ),
            ])
            ->all();

        return [
            'table_exists' => true,
            'total' => (int) DB::table('failed_jobs')->count(),
            'recent' => $recent,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function auditEvents(): array
    {
        if (! Schema::hasTable('sys_audit_logs')) {
            return ['table_exists' => false, 'total' => 0, 'recent' => []];
        }

        $limit = max(1, (int) config('developer_console.audit_events.limit', 10));

        // Metadata columns only — old/new value payloads stay unrendered.
        $recent = DB::table('sys_audit_logs')
            ->orderByDesc('performed_at')
            ->limit($limit)
            ->get(['action', 'entity_type', 'entity_id', 'performed_by', 'performed_at'])
            ->map(fn ($row) => [
                'action' => (string) $row->action,
                'entity_type' => (string) ($row->entity_type ?? '—'),
                'entity_id' => $row->entity_id !== null ? (int) $row->entity_id : null,
                'performed_by' => $row->performed_by !== null ? (int) $row->performed_by : null,
                'performed_at' => (string) $row->performed_at,
            ])
            ->all();

        return [
            'table_exists' => true,
            'total' => (int) DB::table('sys_audit_logs')->count(),
            'recent' => $recent,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function slowQueries(): array
    {
        $limit = max(1, (int) config('developer_console.slow_queries.limit', 10));
        $report = $this->queryObservability->collect(['limit' => $limit, 'sort' => 'mean_time']);
        $available = (bool) ($report['pg_stat_statements']['available'] ?? false);

        // query_summary is already normalized/PII-redacted by NSF-3; the
        // masker pass is defense in depth.
        $top = array_map(fn (array $row) => [
            'query_summary' => mb_substr($this->masker->mask((string) ($row['query_summary'] ?? '')), 0, 200),
            'calls' => (int) ($row['calls'] ?? 0),
            'mean_time_ms' => (float) ($row['mean_time_ms'] ?? 0),
            'total_time_ms' => (float) ($row['total_time_ms'] ?? 0),
        ], array_slice((array) ($report['queries'] ?? []), 0, $limit));

        return [
            'available' => $available,
            'database_driver' => (string) ($report['metadata']['database_driver'] ?? 'unknown'),
            'warnings' => array_map(fn ($w) => $this->masker->mask((string) $w), (array) ($report['warnings'] ?? [])),
            'top_queries' => $top,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function deployEvidence(): array
    {
        $directory = storage_path((string) config('developer_console.deploy_evidence.directory', 'release-evidence/latest'));
        $maxFiles = max(1, (int) config('developer_console.deploy_evidence.max_files', 25));

        if (! is_dir($directory)) {
            return ['directory_exists' => false, 'files' => [], 'governance_decision' => null];
        }

        $files = collect(glob($directory.'/*.json') ?: [])
            ->map(fn (string $file) => [
                'name' => basename($file),
                'size_bytes' => (int) filesize($file),
                'modified_at' => date('Y-m-d H:i:s', (int) filemtime($file)),
            ])
            ->sortByDesc('modified_at')
            ->take($maxFiles)
            ->values()
            ->all();

        $decision = null;
        $summaryPath = $directory.'/foundation-governance-summary.json';
        if (is_file($summaryPath)) {
            $summary = json_decode((string) file_get_contents($summaryPath), true);
            $decision = $summary['combined']['decision'] ?? $summary['decision'] ?? null;
        }

        return [
            'directory_exists' => true,
            'files' => $files,
            'governance_decision' => is_string($decision) ? $decision : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function storageHealth(): array
    {
        $paths = (array) config('runtime_portability.required_writable_paths', ['storage', 'bootstrap/cache']);
        $checks = [];
        $allWritable = true;

        foreach ($paths as $relative) {
            $absolute = base_path((string) $relative);
            $writable = is_dir($absolute) && is_writable($absolute);
            $allWritable = $allWritable && $writable;
            $checks[] = ['path' => (string) $relative, 'writable' => $writable];
        }

        return ['paths' => $checks, 'all_writable' => $allWritable];
    }

    /**
     * @return array<string, mixed>
     */
    private function runtimeHealth(): array
    {
        $start = microtime(true);
        DB::select('select 1');
        $dbPingMs = round((microtime(true) - $start) * 1000, 2);

        $pendingJobs = Schema::hasTable('jobs') ? (int) DB::table('jobs')->count() : null;
        $failedJobs = Schema::hasTable('failed_jobs') ? (int) DB::table('failed_jobs')->count() : null;

        return [
            'db_connection' => (string) config('database.default'),
            'db_ping_ms' => $dbPingMs,
            'cache_driver' => (string) config('cache.default'),
            'session_driver' => (string) config('session.driver'),
            'queue_connection' => (string) config('queue.default'),
            'pending_jobs' => $pendingJobs,
            'failed_jobs' => $failedJobs,
            'object_storage_enabled' => (bool) config('object_storage.enabled', false),
            'debug' => (bool) config('app.debug'),
            'environment' => (string) app()->environment(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function diskBackup(): array
    {
        $free = @disk_free_space(base_path());
        $total = @disk_total_space(base_path());

        $backupDir = storage_path((string) config('developer_console.disk_backup.backup_directory', 'app/backups/deploy'));
        $maxFiles = max(1, (int) config('developer_console.disk_backup.max_files', 5));

        $backups = [];
        if (is_dir($backupDir)) {
            $backups = collect(glob($backupDir.'/*') ?: [])
                ->filter(fn (string $file) => is_file($file))
                ->map(fn (string $file) => [
                    'name' => basename($file),
                    'size_bytes' => (int) filesize($file),
                    'modified_at' => date('Y-m-d H:i:s', (int) filemtime($file)),
                ])
                ->sortByDesc('modified_at')
                ->take($maxFiles)
                ->values()
                ->all();
        }

        return [
            'disk_free_bytes' => $free !== false ? (int) $free : null,
            'disk_total_bytes' => $total !== false ? (int) $total : null,
            'backup_directory_exists' => is_dir($backupDir),
            'latest_backups' => $backups,
        ];
    }

    /**
     * @return list<string>
     */
    private function tailFile(string $path, int $lines): array
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return [];
        }

        try {
            $buffer = '';
            $chunkSize = 8192;
            $position = (int) filesize($path);

            while ($position > 0 && substr_count($buffer, "\n") <= $lines) {
                $read = min($chunkSize, $position);
                $position -= $read;
                fseek($handle, $position);
                $buffer = fread($handle, $read).$buffer;
            }

            $all = array_filter(explode("\n", $buffer), fn (string $l) => trim($l) !== '');

            return array_slice(array_values($all), -$lines);
        } finally {
            fclose($handle);
        }
    }
}
