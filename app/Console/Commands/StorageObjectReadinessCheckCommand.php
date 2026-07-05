<?php

namespace App\Console\Commands;

use App\Support\Storage\ObjectStorageReadinessService;
use Illuminate\Console\Command;

class StorageObjectReadinessCheckCommand extends Command
{
    protected $signature = 'storage:object-readiness-check
        {--write-test : Additionally write/read/delete a small healthcheck object}
        {--json : Output JSON}
        {--strict : Exit non-zero if enabled but misconfigured, or write test fails}';

    protected $description = 'STORAGE-1 — read-only object storage readiness check (OFF by default).';

    public function handle(ObjectStorageReadinessService $service): int
    {
        $result = $service->check((bool) $this->option('write-test'));

        if ($this->option('json')) {
            $this->line((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->renderText($result);
        }

        if ($this->option('strict') && in_array($result['status'], ['misconfigured', 'write_test_failed'], true)) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function renderText(array $result): void
    {
        $this->info('Object Storage Readiness (STORAGE-1)');
        $this->line('Enabled: '.($result['enabled'] ? 'yes' : 'no'));
        $this->line('Disk: '.$result['disk']);
        $this->line('Bucket configured: '.($result['bucket_configured'] ? 'yes' : 'no'));
        $this->line('Endpoint configured: '.($result['endpoint_configured'] ? 'yes' : 'no'));
        $this->line('Write test: '.$result['write_test']);

        if ($result['missing_env'] !== []) {
            $this->warn('Missing required env keys: '.implode(', ', $result['missing_env']));
        }

        if (! empty($result['write_test_error'])) {
            $this->error('Write test error: '.$result['write_test_error']);
        }

        $this->newLine();
        $this->line('Status: '.$result['status']);
    }
}
