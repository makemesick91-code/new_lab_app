<?php

namespace App\Console\Commands;

use App\Modules\Satusehat\Models\SatusehatRolloutWave;
use Illuminate\Console\Command;

/**
 * SATUSEHAT-4D — read-only governance audit → GO / WATCH / FAIL.
 *
 * Verifies the credential-independent safety invariants: SATUSEHAT external send
 * disabled, production blocked, single-active-wave rule holds, and no wave/pilot
 * enables external submission. FAIL (a real unsafe kill-switch state) exits
 * non-zero. WATCH (e.g. integration enabled in sandbox, or no active wave) exits
 * non-zero only under --strict. Never performs a network request.
 */
class SatusehatGovernanceAuditCommand extends Command
{
    protected $signature = 'satusehat:governance-audit {--json} {--strict}';

    protected $description = 'SATUSEHAT-4D governance audit (read-only, credential-independent)';

    public function handle(): int
    {
        $checks = [];
        $fail = [];
        $watch = [];

        // --- Hard safety invariants (FAIL) ---
        $sendOff = config('satusehat.send_enabled', false) === false;
        $checks['external_send_disabled'] = $sendOff;
        if (! $sendOff) {
            $fail[] = 'external_send_enabled';
        }

        $prodBlocked = config('satusehat.production_enabled', false) === false
            && config('satusehat.production_approved', false) === false;
        $checks['production_blocked'] = $prodBlocked;
        if (! $prodBlocked) {
            $fail[] = 'production_not_blocked';
        }

        // Single-active-wave invariant.
        $allowMultiple = (bool) config('satusehat_pilot.multi_branch.allow_multiple_active_waves', false);
        $activeWaves = SatusehatRolloutWave::query()
            ->where('environment', (string) config('satusehat.environment'))
            ->whereIn('status', SatusehatRolloutWave::ACTIVE_STATUSES)
            ->count();
        $singleWaveOk = $allowMultiple || $activeWaves <= 1;
        $checks['single_active_wave'] = $singleWaveOk;
        if (! $singleWaveOk) {
            $fail[] = 'multiple_active_waves';
        }

        // --- WATCH conditions ---
        if (config('satusehat.enabled', false) !== false) {
            $watch[] = 'integration_enabled';
        }
        if ($activeWaves === 0) {
            $watch[] = 'no_active_wave';
        }

        $decision = $fail !== [] ? 'FAIL' : ($watch !== [] ? 'WATCH' : 'GO');

        $report = [
            'decision' => $decision,
            'checks' => $checks,
            'fail' => $fail,
            'watch' => $watch,
            'active_waves' => $activeWaves,
            'satusehat_2' => 'WATCH',
            'external_submission_enabled' => (bool) config('satusehat.send_enabled', false),
            'production_blocked' => $prodBlocked,
            'note' => 'SATUSEHAT-4D is credential-independent; external GO requires the SATUSEHAT-2 Credential Closure Campaign.',
        ];

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->line('decision='.$decision);
            $this->line('fail='.(($fail === []) ? 'none' : implode(',', $fail)));
            $this->line('watch='.(($watch === []) ? 'none' : implode(',', $watch)));
            $this->info('External submission remains blocked (SATUSEHAT-2 WATCH).');
        }

        if ($decision === 'FAIL') {
            return self::FAILURE;
        }
        if ($decision === 'WATCH' && $this->option('strict')) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
