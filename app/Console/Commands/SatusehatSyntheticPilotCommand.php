<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Modules\Satusehat\Services\DataQuality\SatusehatSyntheticPilotService;
use Illuminate\Console\Command;

/**
 * SATUSEHAT-4A — synthetic rehearsal data pack lifecycle.
 *
 * seed/reset WRITE and therefore require an explicit --confirm; verify is
 * read-only. Everything is isolated inside the synthetic branch (SYN4A);
 * reset only ever removes campaign records — never real data, never a
 * destructive schema command, never an external request.
 */
class SatusehatSyntheticPilotCommand extends Command
{
    protected $signature = 'satusehat:synthetic-pilot
        {action : seed|verify|reset}
        {--confirm : Required for seed/reset (write actions)}
        {--actor= : User id recorded as the acting user (optional)}
        {--json}';

    protected $description = 'SATUSEHAT-4A synthetic rehearsal pack: seed / verify / reset (isolated, marker-scoped, no network)';

    public function handle(SatusehatSyntheticPilotService $service): int
    {
        $action = (string) $this->argument('action');
        $actor = is_numeric($this->option('actor')) ? User::query()->find((int) $this->option('actor')) : null;

        if (! in_array($action, ['seed', 'verify', 'reset'], true)) {
            $this->error('Aksi tidak dikenal — gunakan seed|verify|reset.');

            return self::FAILURE;
        }

        if (in_array($action, ['seed', 'reset'], true) && ! $this->option('confirm')) {
            $this->error("Aksi '{$action}' menulis data — jalankan ulang dengan --confirm.");

            return self::FAILURE;
        }

        $report = match ($action) {
            'seed' => $service->seed($actor),
            'verify' => $service->verify(),
            'reset' => $service->reset($actor),
        };

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->info("satusehat:synthetic-pilot {$action} — ".json_encode($report));
        }

        if ($action === 'verify' && in_array(false, $report, true)) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
