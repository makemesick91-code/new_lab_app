<?php

namespace App\Modules\Satusehat\Services\Pilot;

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\Branch\Services\BranchService;
use App\Modules\Satusehat\Models\SatusehatAuditLog;
use App\Modules\Satusehat\Models\SatusehatBranchPilotProfile;
use App\Modules\Satusehat\Models\SatusehatBranchTransition;
use App\Modules\Satusehat\Models\SatusehatWaveBranchMembership;
use App\Modules\Satusehat\Services\SatusehatAuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * SATUSEHAT-4D — controlled branch readiness promotion / demotion / suspension.
 *
 * Every transition runs server-side, validates gates, is transactional +
 * row-locked, requires a reason + permission (checked by the caller), appends an
 * immutable transition record + score snapshot, stays branch-scoped, and NEVER
 * enables external send or production. Promotion requires every INTERNAL gate to
 * pass and zero open hard blockers; a high score can never override a hard
 * blocker; the external credential blocker is always separate.
 */
class SatusehatBranchPromotionService
{
    public function __construct(
        private readonly BranchService $branches,
        private readonly SatusehatBranchReadinessProfileService $profiles,
        private readonly SatusehatInternalPilotEligibilityService $eligibility,
        private readonly SatusehatScoreSnapshotService $snapshots,
        private readonly SatusehatAuditLogger $audit,
    ) {}

    /** Promote a branch to pilot_ready_internal (INTERNAL GO only). */
    public function promote(Branch $branch, string $reason, User $actor): SatusehatBranchPilotProfile
    {
        $this->assertRmeBranch($branch);
        $reason = $this->assertReason($reason);

        $snapshot = $this->profiles->computeSnapshot((int) $branch->id);
        $result = $this->eligibility->evaluate($snapshot, $this->profiles->existingProfile((int) $branch->id));

        if ((int) ($snapshot['open_hard_issues'] ?? 0) > 0) {
            throw ValidationException::withMessages([
                'branch_id' => 'Promosi ditolak — masih ada isu HARD terbuka (skor tidak dapat menutupi hard blocker).',
            ]);
        }
        if (! ($result['internal_ready'] ?? false)) {
            throw ValidationException::withMessages([
                'branch_id' => 'Promosi ditolak — cabang belum memenuhi seluruh gate kesiapan internal.',
            ]);
        }

        return $this->transition(
            $branch,
            SatusehatBranchPilotProfile::STAGE_PILOT_READY_INTERNAL,
            SatusehatBranchTransition::TYPE_PROMOTION,
            SatusehatAuditLog::EVENT_BRANCH_PROMOTED,
            'Cabang dipromosikan ke siap pilot internal (INTERNAL GO)',
            $reason,
            $actor,
            $result,
        );
    }

    /** Demote a branch (hard readiness regression) — requires a known trigger. */
    public function demote(Branch $branch, string $trigger, string $reason, User $actor): SatusehatBranchPilotProfile
    {
        $this->assertRmeBranch($branch);
        $reason = $this->assertReason($reason);

        $triggers = (array) config('satusehat_pilot.multi_branch.demotion_triggers', []);
        if (! in_array($trigger, $triggers, true)) {
            throw ValidationException::withMessages(['trigger' => 'Pemicu demosi tidak dikenal.']);
        }

        return $this->transition(
            $branch,
            SatusehatBranchPilotProfile::STAGE_REMEDIATION,
            SatusehatBranchTransition::TYPE_DEMOTION,
            SatusehatAuditLog::EVENT_BRANCH_DEMOTED,
            'Cabang diturunkan ke remediasi ('.$trigger.')',
            $reason,
            $actor,
            ['trigger' => $trigger],
        );
    }

    /** Suspend a branch's readiness (reversible), recording a transition. */
    public function suspend(Branch $branch, string $reason, User $actor): SatusehatBranchPilotProfile
    {
        $this->assertRmeBranch($branch);
        $reason = $this->assertReason($reason);

        return $this->transition(
            $branch,
            SatusehatBranchPilotProfile::STAGE_SUSPENDED,
            SatusehatBranchTransition::TYPE_SUSPENSION,
            SatusehatAuditLog::EVENT_BRANCH_TRANSITION_SUSPENDED,
            'Kesiapan cabang ditangguhkan',
            $reason,
            $actor,
        );
    }

    /** Resume a suspended branch back to profiling. */
    public function resume(Branch $branch, string $reason, User $actor): SatusehatBranchPilotProfile
    {
        $this->assertRmeBranch($branch);
        $reason = $this->assertReason($reason);

        return $this->transition(
            $branch,
            SatusehatBranchPilotProfile::STAGE_PROFILING,
            SatusehatBranchTransition::TYPE_RESUME,
            SatusehatAuditLog::EVENT_BRANCH_TRANSITION_RESUMED,
            'Kesiapan cabang dilanjutkan',
            $reason,
            $actor,
        );
    }

    private function transition(
        Branch $branch,
        string $toStage,
        string $type,
        string $event,
        string $summary,
        string $reason,
        User $actor,
        array $gateSnapshot = [],
    ): SatusehatBranchPilotProfile {
        return DB::transaction(function () use ($branch, $toStage, $type, $event, $summary, $reason, $actor, $gateSnapshot) {
            $profile = $this->profiles->profileFor((int) $branch->id);
            $locked = SatusehatBranchPilotProfile::query()->lockForUpdate()->findOrFail($profile->id);

            $waveId = SatusehatWaveBranchMembership::query()
                ->where('environment', (string) config('satusehat.environment'))
                ->where('branch_id', (int) $branch->id)
                ->where('status', SatusehatWaveBranchMembership::STATUS_ENROLLED)
                ->value('rollout_wave_id');

            $fromStage = $locked->readiness_stage;

            $locked->update([
                'readiness_stage' => $toStage,
                'last_transition_at' => now(),
            ]);

            SatusehatBranchTransition::create([
                'environment' => (string) config('satusehat.environment'),
                'branch_id' => (int) $branch->id,
                'rollout_wave_id' => $waveId,
                'from_stage' => $fromStage,
                'to_stage' => $toStage,
                'transition_type' => $type,
                'reason' => $reason,
                'gate_snapshot' => $gateSnapshot ?: null,
                'actor_id' => $actor->id,
                'created_at' => now(),
            ]);

            $this->snapshots->capture($locked->refresh(), $actor);

            $this->audit->log(
                'satusehat_branch_pilot_profile',
                (int) $locked->id,
                $event,
                $summary,
                ['branch_id' => (int) $branch->id, 'from' => $fromStage, 'to' => $toStage, 'reason' => $reason],
                (int) $branch->id,
                $actor,
            );

            return $locked->refresh();
        });
    }

    private function assertRmeBranch(Branch $branch): void
    {
        if (! in_array((int) $branch->id, $this->branches->rmeEnabledIds(), true)) {
            throw ValidationException::withMessages([
                'branch_id' => 'Hanya cabang RME aktif (bukan MAIN) yang dapat ditransisikan.',
            ]);
        }
    }

    private function assertReason(string $reason): string
    {
        $reason = mb_substr(trim($reason), 0, 500);
        if (mb_strlen($reason) < (int) config('satusehat_pilot.change_control.min_reason_length', 10)) {
            throw ValidationException::withMessages(['reason' => 'Alasan transisi wajib diisi (min. 10 karakter).']);
        }

        return $reason;
    }
}
