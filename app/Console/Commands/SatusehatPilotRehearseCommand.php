<?php

namespace App\Console\Commands;

use App\Modules\Branch\Services\BranchService;
use App\Modules\Satusehat\Services\Pilot\SatusehatPilotRehearsalService;
use Illuminate\Console\Command;

/**
 * SATUSEHAT-4C — branch-scoped internal pilot rehearsal. Credential-independent
 * and network-silent (synthetic pack only). Dry-run by default; `--confirm`
 * persists a rehearsal run. Terminal state is honestly PILOT_READY_INTERNAL or
 * BLOCKED_EXTERNAL_CREDENTIAL — never submitted/sent externally.
 */
class SatusehatPilotRehearseCommand extends Command
{
    protected $signature = 'satusehat:pilot-rehearse {--branch=} {--synthetic} {--dry-run} {--confirm} {--json}';

    protected $description = 'SATUSEHAT-4C internal pilot rehearsal (synthetic, network-silent)';

    public function handle(SatusehatPilotRehearsalService $rehearsal, BranchService $branches): int
    {
        if (! is_numeric($this->option('branch'))) {
            $this->error('Wajib menyertakan --branch=<id> cabang RME.');

            return self::FAILURE;
        }

        $branchId = (int) $this->option('branch');
        $branch = $branches->find($branchId);
        if ($branch === null || ! in_array($branchId, $branches->rmeEnabledIds(), true)) {
            $this->error('Cabang bukan cabang RME aktif (atau MAIN) — rehearsal ditolak.');

            return self::FAILURE;
        }

        // A persisted run requires explicit --confirm; otherwise dry-run.
        $dryRun = ! $this->option('confirm') || (bool) $this->option('dry-run');

        $result = $rehearsal->run($branch, null, $dryRun);

        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->info(sprintf('Rehearsal %s — result: %s (final_stage: %s)',
                $dryRun ? 'dry-run' : 'tercatat', $result['result'], $result['final_stage']));
            $this->line('  external_submitted: '.($result['external_submitted'] ? 'yes' : 'NO'));
        }

        return $result['result'] === 'failed' ? self::FAILURE : self::SUCCESS;
    }
}
