<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Modules\Satusehat\Services\DataQuality\SatusehatRehearsalService;
use Illuminate\Console\Command;

/**
 * SATUSEHAT-4A — credential-independent end-to-end rehearsal.
 *
 * Refuses to run without --synthetic (real patient data is never rehearsed).
 * Dry-run by default; --prepare-batch --confirm enables the one controlled
 * local write (batch preparation — still zero network). The honest expected
 * final state without credentials is BLOCKED_EXTERNAL_CREDENTIAL — the
 * command NEVER reports submitted/succeeded.
 */
class SatusehatRehearseCommand extends Command
{
    protected $signature = 'satusehat:rehearse
        {--synthetic : REQUIRED — rehearse only the synthetic campaign}
        {--dry-run : Evaluation only (default behavior)}
        {--prepare-batch : Also prepare a LOCAL submission batch (needs --confirm)}
        {--confirm : Confirm the controlled batch-preparation write}
        {--actor= : User id used as the acting reviewer for batch preparation}
        {--json}';

    protected $description = 'SATUSEHAT-4A synthetic end-to-end rehearsal — stops honestly at BLOCKED_EXTERNAL_CREDENTIAL (no network)';

    public function handle(SatusehatRehearsalService $rehearsal): int
    {
        if (! $this->option('synthetic')) {
            $this->error('Rehearsal hanya boleh berjalan pada data sintetis — tambahkan --synthetic.');

            return self::FAILURE;
        }

        $prepareBatch = (bool) $this->option('prepare-batch');
        if ($prepareBatch && ! $this->option('confirm')) {
            $this->error('--prepare-batch menulis batch lokal — tambahkan --confirm.');

            return self::FAILURE;
        }

        $actor = is_numeric($this->option('actor'))
            ? User::query()->find((int) $this->option('actor'))
            : ($prepareBatch ? User::role('Super Admin')->orderBy('id')->first() : null);

        $result = $rehearsal->rehearse(
            actor: $actor,
            prepareBatch: $prepareBatch,
            dryRun: ! $prepareBatch,
        );

        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            foreach ($result['stages'] as $stage) {
                $this->line(sprintf('  [%-16s] %-26s %s', $stage['status'], $stage['stage'], $stage['detail']));
            }
            $this->info('Final state: '.$result['final_state']);
        }

        $acceptable = in_array($result['final_state'], ['BLOCKED_EXTERNAL_CREDENTIAL', 'READY_INTERNAL'], true);

        return $acceptable ? self::SUCCESS : self::FAILURE;
    }
}
