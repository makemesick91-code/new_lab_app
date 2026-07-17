<?php

namespace App\Console\Commands;

use App\Modules\Satusehat\Models\SatusehatRolloutWave;
use Illuminate\Console\Command;

/**
 * SATUSEHAT-4D — read-only rollout wave status. Credential-independent, PII-free.
 */
class SatusehatWaveStatusCommand extends Command
{
    protected $signature = 'satusehat:wave-status {--json}';

    protected $description = 'SATUSEHAT-4D rollout wave status (read-only)';

    public function handle(): int
    {
        $env = (string) config('satusehat.environment');
        $waves = SatusehatRolloutWave::query()
            ->where('environment', $env)
            ->withCount(['activeMemberships as enrolled_branches'])
            ->orderBy('sequence')->orderBy('id')
            ->get()
            ->map(fn (SatusehatRolloutWave $w) => [
                'id' => $w->id,
                'name' => $w->name,
                'sequence' => $w->sequence,
                'status' => $w->status,
                'active' => $w->isActive(),
                'enrolled_branches' => (int) $w->enrolled_branches,
            ])->all();

        $activeCount = count(array_filter($waves, fn ($w) => $w['active']));
        $report = ['waves' => $waves, 'active_waves' => $activeCount, 'external_blocker' => 'BLOCKED_EXTERNAL_CREDENTIAL'];

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        foreach ($waves as $w) {
            $this->line(sprintf('wave=%d "%s" seq=%d status=%s active=%s branches=%d',
                $w['id'], $w['name'], $w['sequence'], $w['status'], $w['active'] ? 'yes' : 'no', $w['enrolled_branches']));
        }
        $this->info(sprintf('active_waves=%d — no wave enables external send/production.', $activeCount));

        return self::SUCCESS;
    }
}
