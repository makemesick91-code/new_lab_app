<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SATUSEHAT-4A — queue health for the dedicated `satusehat` queue. Read-only.
 */
class SatusehatQueueHealthCommand extends Command
{
    protected $signature = 'satusehat:queue-health {--json} {--strict : Exit non-zero when failed jobs exist}';

    protected $description = 'SATUSEHAT queue health (pending + failed jobs on the satusehat queue) — read-only';

    public function handle(): int
    {
        $queue = (string) config('satusehat.queue', 'satusehat');

        $report = [
            'queue' => $queue,
            'connection' => (string) config('queue.default'),
            'pending' => Schema::hasTable('jobs') ? (int) DB::table('jobs')->where('queue', $queue)->count() : null,
            'failed' => Schema::hasTable('failed_jobs') ? (int) DB::table('failed_jobs')->where('queue', $queue)->count() : null,
        ];

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->info('SATUSEHAT queue health: '.json_encode($report));
        }

        return ($this->option('strict') && ($report['failed'] ?? 0) > 0) ? self::FAILURE : self::SUCCESS;
    }
}
