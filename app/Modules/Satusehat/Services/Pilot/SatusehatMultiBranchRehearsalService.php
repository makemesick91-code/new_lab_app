<?php

namespace App\Modules\Satusehat\Services\Pilot;

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\Satusehat\Models\SatusehatAuditLog;
use App\Modules\Satusehat\Models\SatusehatPilotRehearsalRun;
use App\Modules\Satusehat\Models\SatusehatRolloutWave;
use App\Modules\Satusehat\Models\SatusehatWaveBranchMembership;
use App\Modules\Satusehat\Services\SatusehatAuditLogger;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * SATUSEHAT-4D — multi-branch synthetic rehearsal across a wave.
 *
 * Runs the credential-independent, network-silent 4C per-branch rehearsal (which
 * uses the isolated SATUSEHAT-4A synthetic pack) for every enrolled branch of a
 * wave. A failure on one branch is isolated and never aborts the others. NO
 * external request, no OAuth, no token — the honest terminal wave state is
 * READY_INTERNAL (all branches clean) or BLOCKED_EXTERNAL_CREDENTIAL (clean but
 * only the external credential remains); it is NEVER submitted/external_success.
 */
class SatusehatMultiBranchRehearsalService
{
    public const WAVE_READY_INTERNAL = 'READY_INTERNAL';

    public const WAVE_BLOCKED_EXTERNAL_CREDENTIAL = 'BLOCKED_EXTERNAL_CREDENTIAL';

    public const WAVE_INCOMPLETE = 'INCOMPLETE'; // one or more branches failed the internal pipeline

    public function __construct(
        private readonly SatusehatPilotRehearsalService $rehearsal,
        private readonly SatusehatAuditLogger $audit,
    ) {}

    private function env(): string
    {
        return (string) config('satusehat.environment');
    }

    /**
     * @return array{wave_id:int, dry_run:bool, branch_results:list<array<string,mixed>>, all_clean:bool, final_wave_state:string, external_submitted:bool}
     */
    public function run(SatusehatRolloutWave $wave, ?User $actor, bool $dryRun = true): array
    {
        $branches = SatusehatWaveBranchMembership::query()
            ->where('environment', $this->env())
            ->where('rollout_wave_id', (int) $wave->id)
            ->where('status', SatusehatWaveBranchMembership::STATUS_ENROLLED)
            ->pluck('branch_id')->map(fn ($id) => (int) $id)->all();

        if ($branches === []) {
            throw ValidationException::withMessages(['wave' => 'Wave tidak memiliki cabang terdaftar untuk rehearsal.']);
        }

        $results = [];
        $allClean = true;

        foreach ($branches as $branchId) {
            $branch = Branch::find($branchId);
            if ($branch === null) {
                $results[] = ['branch_id' => $branchId, 'ok' => false, 'error' => 'branch_missing'];
                $allClean = false;

                continue;
            }

            try {
                // Per-branch rehearsal — network-silent, synthetic, isolated.
                $r = $this->rehearsal->run($branch, $actor, $dryRun);
                $clean = ($r['result'] ?? null) === SatusehatPilotRehearsalRun::RESULT_BLOCKED_EXTERNAL_CREDENTIAL;
                $results[] = [
                    'branch_id' => $branchId,
                    'ok' => $clean,
                    'result' => $r['result'] ?? null,
                    'final_stage' => $r['final_stage'] ?? null,
                    'external_submitted' => false,
                ];
                $allClean = $allClean && $clean;
            } catch (Throwable $e) {
                // Isolate a branch failure — never abort the rest of the wave.
                $results[] = ['branch_id' => $branchId, 'ok' => false, 'error' => 'rehearsal_failed'];
                $allClean = false;
            }
        }

        $finalState = $allClean
            ? self::WAVE_BLOCKED_EXTERNAL_CREDENTIAL
            : self::WAVE_INCOMPLETE;

        $this->audit->log(
            'satusehat_rollout_wave',
            (int) $wave->id,
            SatusehatAuditLog::EVENT_MULTI_BRANCH_REHEARSAL_RUN,
            'Rehearsal multi-cabang dijalankan (sintetis, tanpa jaringan)',
            [
                'wave' => $wave->name,
                'branches' => count($branches),
                'all_clean' => $allClean,
                'final_wave_state' => $finalState,
                'external_submitted' => false,
            ],
            null,
            $actor,
        );

        return [
            'wave_id' => (int) $wave->id,
            'dry_run' => $dryRun,
            'branch_results' => $results,
            'all_clean' => $allClean,
            'final_wave_state' => $finalState,
            'external_submitted' => false,
        ];
    }
}
