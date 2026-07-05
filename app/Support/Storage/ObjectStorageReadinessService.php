<?php

namespace App\Support\Storage;

use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * STORAGE-1 — read-only object storage readiness check.
 *
 * OFF by default: when config('object_storage.enabled') is false, this
 * service never touches the object disk. No existing local/public file is
 * ever read, moved, or deleted by this service.
 */
class ObjectStorageReadinessService
{
    public function enabled(): bool
    {
        return (bool) config('object_storage.enabled', false);
    }

    public function disk(): string
    {
        return (string) config('object_storage.disk', 'object');
    }

    /**
     * @return list<string> names only, never values, of missing required env keys.
     */
    public function missingEnvKeys(): array
    {
        $required = (array) config('object_storage.required_env', []);

        return array_values(array_filter(
            $required,
            static fn (string $key): bool => trim((string) env($key, '')) === ''
        ));
    }

    public function bucketConfigured(): bool
    {
        return trim((string) config('filesystems.disks.'.$this->disk().'.bucket', '')) !== '';
    }

    public function endpointConfigured(): bool
    {
        return trim((string) config('filesystems.disks.'.$this->disk().'.endpoint', '')) !== '';
    }

    /**
     * Non-destructive readiness check. Set $writeTest to true to additionally
     * write, read, verify, and delete a small healthcheck object.
     *
     * @return array<string, mixed>
     */
    public function check(bool $writeTest = false): array
    {
        $enabled = $this->enabled();
        $disk = $this->disk();

        if (! $enabled) {
            return [
                'status' => 'disabled_ready',
                'enabled' => false,
                'disk' => $disk,
                'bucket_configured' => $this->bucketConfigured(),
                'endpoint_configured' => $this->endpointConfigured(),
                'missing_env' => [],
                'write_test' => 'skipped',
                'write_test_error' => null,
            ];
        }

        $missingEnv = $this->missingEnvKeys();
        $bucketConfigured = $this->bucketConfigured();
        $endpointConfigured = $this->endpointConfigured();

        if ($missingEnv !== []) {
            return [
                'status' => 'misconfigured',
                'enabled' => true,
                'disk' => $disk,
                'bucket_configured' => $bucketConfigured,
                'endpoint_configured' => $endpointConfigured,
                'missing_env' => $missingEnv,
                'write_test' => 'skipped',
                'write_test_error' => null,
            ];
        }

        $writeTestResult = 'skipped';
        $writeTestError = null;

        if ($writeTest) {
            try {
                $this->runWriteTest($disk);
                $writeTestResult = 'passed';
            } catch (Throwable $e) {
                $writeTestResult = 'failed';
                $writeTestError = $e->getMessage();
            }
        }

        return [
            'status' => $writeTestResult === 'failed' ? 'write_test_failed' : 'ready',
            'enabled' => true,
            'disk' => $disk,
            'bucket_configured' => $bucketConfigured,
            'endpoint_configured' => $endpointConfigured,
            'missing_env' => [],
            'write_test' => $writeTestResult,
            'write_test_error' => $writeTestError,
        ];
    }

    private function runWriteTest(string $disk): void
    {
        $prefix = trim((string) config('object_storage.healthcheck_prefix', 'healthchecks/daengtisiams'), '/');
        $token = bin2hex(random_bytes(8));
        $path = $prefix.'/'.now()->format('Ymd-His').'-'.$token.'.txt';
        $contents = 'daengtisiams-object-storage-healthcheck-'.$token;

        $filesystem = Storage::disk($disk);

        $filesystem->put($path, $contents);

        try {
            $readBack = $filesystem->get($path);

            if ($readBack !== $contents) {
                throw new \RuntimeException('Healthcheck object content mismatch after read-back.');
            }
        } finally {
            $filesystem->delete($path);
        }
    }
}
