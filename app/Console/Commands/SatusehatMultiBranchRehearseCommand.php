<?php

namespace App\Console\Commands;

use App\Modules\Satusehat\Models\SatusehatRolloutWave;
use App\Modules\Satusehat\Services\Pilot\SatusehatMultiBranchRehearsalService;
use Illuminate\Console\Command;

/**
 * SATUSEHAT-4D — multi-branch synthetic rehearsal across a wave.
 *
 * Credential-independent, network-silent. Dry-run by default; --confirm persists
 * the isolated synthetic pack. Honest terminal state is BLOCKED_EXTERNAL_CREDENTIAL
 * (or INCOMPLETE if a branch's internal pipeline is not clean) — never submitted.
 */
class SatusehatMultiBranchRehearseCommand extends Command
{
    protected $signature = 'satusehat:multi-branch-rehearse {--wave= : Wave id (required)} {--synthetic : synthetic pack (always)} {--dry-run} {--confirm} {--json}';

    protected $description = 'SATUSEHAT-4D multi-branch synthetic rehearsal (no network, no external submission)';

    public function handle(SatusehatMultiBranchRehearsalService $svc): int
    {
        if (! is_numeric($this->option('wave'))) {
            $this->error('--wave=<id> wajib diisi.');

            return self::FAILURE;
        }

        $wave = SatusehatRolloutWave::query()
            ->where('environment', (string) config('satusehat.environment'))
            ->find((int) $this->option('wave'));
        if ($wave === null) {
            $this->error('Wave tidak ditemukan.');

            return self::FAILURE;
        }

        // Dry-run unless --confirm is explicitly passed (write path is guarded).
        $dryRun = ! $this->option('confirm');

        $result = $svc->run($wave, null, $dryRun);

        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            foreach ($result['branch_results'] as $r) {
                $this->line(sprintf('branch=%d ok=%s result=%s', $r['branch_id'], ($r['ok'] ?? false) ? 'yes' : 'no', $r['result'] ?? ($r['error'] ?? 'n/a')));
            }
            $this->info(sprintf('final_wave_state=%s external_submitted=%s (SATUSEHAT-2 WATCH).',
                $result['final_wave_state'], $result['external_submitted'] ? 'yes' : 'NO'));
        }

        return $result['all_clean'] ? self::SUCCESS : self::FAILURE;
    }
}
