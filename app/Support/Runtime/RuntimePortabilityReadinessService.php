<?php

namespace App\Support\Runtime;

use App\Support\Storage\ObjectStorageReadinessService;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * STATELESS-1 — read-only runtime statelessness & deploy portability readiness.
 *
 * Never changes any session/cache/queue/filesystem/log driver. It only
 * reports the current runtime posture, flags drivers that are risky for
 * horizontal scale, and (optionally) runs a non-destructive write/read/
 * delete healthcheck against an allowed local write path.
 */
class RuntimePortabilityReadinessService
{
    public function __construct(
        private readonly ObjectStorageReadinessService $objectStorageReadiness,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function check(bool $writeTest = false): array
    {
        $sessionDriver = (string) config('session.driver');
        $cacheStore = (string) config('cache.default');
        $queueConnection = (string) config('queue.default');
        $filesystemDisk = (string) config('filesystems.default');
        $logChannel = (string) config('logging.default');
        $appEnv = (string) config('app.env');
        $appDebug = (bool) config('app.debug');

        $allowedPaths = $this->allowedLocalWritePaths();
        $writablePaths = $this->writablePathsStatus();
        $driverWarnings = $this->driverWarnings($sessionDriver, $cacheStore, $queueConnection);
        $logNotes = $this->logChannelNotes($logChannel);
        $horizontalScaleWarnings = array_merge($driverWarnings, $logNotes);

        $objectStorage = $this->objectStorageReadiness->check(false);

        $writeTestStatus = 'skipped';
        $writeTestError = null;

        if ($writeTest) {
            try {
                $this->runWriteTest();
                $writeTestStatus = 'passed';
            } catch (Throwable $e) {
                $writeTestStatus = 'failed';
                $writeTestError = $e->getMessage();
            }
        }

        $unwritablePaths = collect($writablePaths)->reject(fn (bool $writable) => $writable)->keys()->all();

        $status = match (true) {
            $unwritablePaths !== [] => 'fail',
            $writeTestStatus === 'failed' => 'fail',
            $driverWarnings !== [] => 'warning',
            $logNotes !== [] => 'ready_single_node',
            default => 'portable_ready',
        };

        return [
            'status' => $status,
            'app_env' => $appEnv,
            'app_debug_safe' => ! ($appEnv === 'production' && $appDebug),
            'session_driver' => $sessionDriver,
            'cache_store' => $cacheStore,
            'queue_connection' => $queueConnection,
            'filesystem_default_disk' => $filesystemDisk,
            'log_channel' => $logChannel,
            'object_storage_enabled' => (bool) ($objectStorage['enabled'] ?? false),
            'object_storage_status' => (string) ($objectStorage['status'] ?? 'unknown'),
            'local_write_paths_allowed' => $allowedPaths,
            'writable_paths_status' => $writablePaths,
            'horizontal_scale_warnings' => $horizontalScaleWarnings,
            'recommendations' => $this->recommendations($sessionDriver, $cacheStore, $queueConnection, $logChannel),
            'write_test_status' => $writeTestStatus,
            'write_test_error' => $writeTestError,
        ];
    }

    /**
     * @return list<string>
     */
    public function allowedLocalWritePaths(): array
    {
        return (array) config('runtime_portability.allowed_local_write_paths', ['storage', 'bootstrap/cache']);
    }

    /**
     * @return array<string, bool>
     */
    private function writablePathsStatus(): array
    {
        $paths = (array) config('runtime_portability.required_writable_paths', []);

        $status = [];
        foreach ($paths as $relative) {
            $status[$relative] = File::isDirectory(base_path($relative)) && File::isWritable(base_path($relative));
        }

        return $status;
    }

    /**
     * @return list<string>
     */
    private function driverWarnings(string $sessionDriver, string $cacheStore, string $queueConnection): array
    {
        $risky = (array) config('runtime_portability.risky_drivers', []);
        $warnings = [];

        if (in_array($sessionDriver, (array) ($risky['session'] ?? []), true)) {
            $warnings[] = "session driver '{$sessionDriver}' stores session state on local disk — not safe for multiple instances without a shared/sticky-session strategy.";
        }

        if (in_array($cacheStore, (array) ($risky['cache'] ?? []), true)) {
            $warnings[] = "cache store '{$cacheStore}' is local-disk only — cached values will diverge across instances.";
        }

        if (in_array($queueConnection, (array) ($risky['queue'] ?? []), true)) {
            $warnings[] = "queue connection '{$queueConnection}' runs jobs inline — no shared/async queue backing for multi-instance workers.";
        }

        return $warnings;
    }

    /**
     * @return list<string>
     */
    private function logChannelNotes(string $logChannel): array
    {
        if (! in_array($logChannel, ['single', 'daily', 'stack'], true)) {
            return [];
        }

        return ["log channel '{$logChannel}' writes to local disk — acceptable for a single VPS pilot but plan centralized logging before scale-out."];
    }

    /**
     * @return list<string>
     */
    private function recommendations(string $sessionDriver, string $cacheStore, string $queueConnection, string $logChannel): array
    {
        $recommendations = [];

        if ($sessionDriver === 'file') {
            $recommendations[] = 'Move session driver to database/redis before running multiple app instances.';
        }

        if ($cacheStore === 'file') {
            $recommendations[] = 'Move cache store to database/redis before running multiple app instances.';
        }

        if ($queueConnection === 'sync') {
            $recommendations[] = 'Move to an async queue connection (database/redis) with a dedicated worker before relying on background processing at scale.';
        }

        if (in_array($logChannel, ['single', 'daily', 'stack'], true)) {
            $recommendations[] = 'Plan centralized/aggregated logging (e.g. syslog, external log shipper) ahead of horizontal scale-out.';
        }

        if ($recommendations === []) {
            $recommendations[] = 'Current runtime configuration has no local-disk-only session/cache/queue dependency; already portable-ready for multi-instance.';
        }

        return $recommendations;
    }

    private function runWriteTest(): void
    {
        $disk = (string) config('runtime_portability.healthcheck_disk', 'local');
        $allowed = $this->allowedLocalWritePaths();

        if ($disk === 'local' && ! in_array('storage', $allowed, true)) {
            throw new RuntimeException("Healthcheck disk 'local' resolves under storage/, which is not in the allowed local write paths.");
        }

        $prefix = trim((string) config('runtime_portability.healthcheck_prefix', 'healthchecks/stateless'), '/');
        $token = bin2hex(random_bytes(8));
        $path = $prefix.'/'.now()->format('Ymd-His').'-'.$token.'.txt';
        $contents = 'daengtisiams-stateless-readiness-healthcheck-'.$token;

        $filesystem = Storage::disk($disk);

        $filesystem->put($path, $contents);

        try {
            $readBack = $filesystem->get($path);

            if ($readBack !== $contents) {
                throw new RuntimeException('Healthcheck file content mismatch after read-back.');
            }
        } finally {
            $filesystem->delete($path);
        }
    }
}
