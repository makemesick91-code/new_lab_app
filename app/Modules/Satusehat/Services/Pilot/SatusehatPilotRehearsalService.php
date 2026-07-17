<?php

namespace App\Modules\Satusehat\Services\Pilot;

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\Branch\Services\BranchService;
use App\Modules\Satusehat\Models\SatusehatAuditLog;
use App\Modules\Satusehat\Models\SatusehatPilotRehearsalRun;
use App\Modules\Satusehat\Services\DataQuality\SatusehatRehearsalService;
use App\Modules\Satusehat\Services\DataQuality\SatusehatSyntheticPilotService;
use App\Modules\Satusehat\Services\SatusehatAuditLogger;
use Illuminate\Validation\ValidationException;

/**
 * SATUSEHAT-4C — branch-scoped internal pilot rehearsal.
 *
 * Wraps the credential-independent SATUSEHAT-4A synthetic rehearsal (which uses
 * the isolated SYN* synthetic pack and never touches real patients or performs
 * an external request) and records a branch-scoped rehearsal run so the target
 * branch's eligibility rehearsal gate can be satisfied. The terminal result is
 * honestly PILOT_READY_INTERNAL or BLOCKED_EXTERNAL_CREDENTIAL — never
 * submitted/sent/succeeded_external.
 */
class SatusehatPilotRehearsalService
{
    public function __construct(
        private readonly BranchService $branches,
        private readonly SatusehatRehearsalService $rehearsal,
        private readonly SatusehatSyntheticPilotService $synthetic,
        private readonly SatusehatBranchReadinessProfileService $profiles,
        private readonly SatusehatAuditLogger $audit,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function run(Branch $branch, ?User $actor, bool $dryRun = true): array
    {
        if (! in_array((int) $branch->id, $this->branches->rmeEnabledIds(), true)) {
            throw ValidationException::withMessages([
                'branch_id' => 'Rehearsal pilot hanya untuk cabang RME aktif (bukan MAIN).',
            ]);
        }

        $startedAt = now();

        // A confirmed (persisted) run ensures the isolated synthetic pack exists
        // first — idempotent, marker-scoped, network-silent.
        if (! $dryRun) {
            $this->synthetic->seed($actor);
        }

        // The underlying rehearsal is always network-silent; prepareBatch stays
        // false (batch preparation is a separate, gated step).
        $result = $this->rehearsal->rehearse($actor, false, true);

        $clean = (bool) ($result['internal_pipeline_clean'] ?? false);
        $runResult = $clean
            ? SatusehatPilotRehearsalRun::RESULT_BLOCKED_EXTERNAL_CREDENTIAL
            : SatusehatPilotRehearsalRun::RESULT_FAILED;

        $payload = [
            'branch_id' => (int) $branch->id,
            'dry_run' => $dryRun,
            'result' => $runResult,
            'final_stage' => (string) ($result['final_state'] ?? 'UNKNOWN'),
            'internal_pipeline_clean' => $clean,
            'stages' => $result['stages'] ?? [],
            'external_submitted' => false,
        ];

        if ($dryRun) {
            return $payload;
        }

        $run = SatusehatPilotRehearsalRun::create([
            'environment' => (string) config('satusehat.environment'),
            'branch_id' => (int) $branch->id,
            'mode' => 'synthetic',
            'dry_run' => false,
            'result' => $runResult,
            'final_stage' => (string) ($result['final_state'] ?? 'UNKNOWN'),
            'stage_results' => $this->sanitizeStages($result['stages'] ?? []),
            'run_by' => $actor?->id,
            'started_at' => $startedAt,
            'completed_at' => now(),
        ]);

        $profile = $this->profiles->profileFor((int) $branch->id);
        $profile->update([
            'last_rehearsal_at' => now(),
            'last_rehearsal_result' => $runResult,
        ]);

        $this->audit->log(
            'satusehat_pilot_rehearsal_run',
            (int) $run->id,
            SatusehatAuditLog::EVENT_PILOT_REHEARSAL_COMPLETED,
            'Rehearsal pilot internal sintetis dijalankan',
            [
                'branch_id' => (int) $branch->id,
                'result' => $runResult,
                'final_stage' => (string) ($result['final_state'] ?? 'UNKNOWN'),
                'external_submitted' => false,
            ],
            (int) $branch->id,
            $actor,
        );

        $payload['run_id'] = (int) $run->id;

        return $payload;
    }

    /**
     * Keep only scalar stage fields (already PII-free from the rehearsal engine).
     *
     * @param  list<array<string, mixed>>  $stages
     * @return list<array<string, string>>
     */
    private function sanitizeStages(array $stages): array
    {
        return array_map(static fn ($stage) => [
            'stage' => (string) ($stage['stage'] ?? ''),
            'status' => (string) ($stage['status'] ?? ''),
            'detail' => mb_substr((string) ($stage['detail'] ?? ''), 0, 300),
        ], $stages);
    }
}
