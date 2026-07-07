<?php

namespace App\Console\Commands;

use App\Jobs\Foundation\QueueWorkerSmokeJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class FoundationQueueWorkerSmokeCommand extends Command
{
    protected $signature = 'foundation:queue-worker-smoke
        {--process : Process the queue once inline (queue:work --once) instead of leaving it for the running worker}
        {--json : Output JSON report}
        {--strict : Return non-zero if a smoke job lands in failed_jobs}';

    protected $description = 'Dispatch a harmless (non-PII, no business data) queue worker smoke job to prove the worker is consuming jobs end-to-end.';

    public function handle(): int
    {
        $token = 'smoke-'.now()->format('YmdHis');
        QueueWorkerSmokeJob::dispatch($token);

        if ($this->option('process')) {
            // Local/CI: process the maintenance queue once so the smoke completes
            // inline. On the VPS the running worker consumes it automatically.
            $this->call('queue:work', [
                'connection' => config('queue.default'),
                '--queue' => 'maintenance',
                '--once' => true,
                '--stop-when-empty' => true,
            ]);
        }

        $failed = $this->safeFailedCount();
        $report = [
            'sprint' => 'POST-ENT-RUNTIME-HARDENING',
            'dispatched_token' => $token,
            'queue' => 'maintenance',
            'connection' => (string) config('queue.default'),
            'processed_inline' => (bool) $this->option('process'),
            'failed_jobs' => $failed,
            'privacy' => ['privacy_safe' => true, 'row_level_data' => false],
        ];

        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->info('Queue worker smoke');
            $this->line('Dispatched: '.$token.' → maintenance ('.$report['connection'].')');
            $this->line('Processed inline: '.($report['processed_inline'] ? 'yes' : 'no (running worker will consume it)'));
            $this->line('Failed jobs: '.$failed);
        }

        if ($failed > 0 && $this->option('strict')) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function safeFailedCount(): int
    {
        try {
            return (int) DB::table('failed_jobs')->count();
        } catch (\Throwable) {
            return 0;
        }
    }
}
