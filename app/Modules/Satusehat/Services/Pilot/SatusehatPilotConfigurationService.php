<?php

namespace App\Modules\Satusehat\Services\Pilot;

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\Branch\Services\BranchService;
use App\Modules\Satusehat\Models\SatusehatAuditLog;
use App\Modules\Satusehat\Models\SatusehatBranchPilotProfile;
use App\Modules\Satusehat\Services\SatusehatAuditLogger;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * SATUSEHAT-4C — internal pilot selection/approval/suspension governance.
 *
 * Every transition is transactional, locked, audited, and branch-scoped to the
 * RME-enabled set (MAIN can never be a pilot clinic branch). No branch is a
 * pilot by default. Approval requires the branch to pass every INTERNAL
 * eligibility gate; it is an INTERNAL GO only — external readiness stays a
 * separate credential blocker and is never asserted here.
 */
class SatusehatPilotConfigurationService
{
    public function __construct(
        private readonly BranchService $branches,
        private readonly SatusehatBranchReadinessProfileService $profiles,
        private readonly SatusehatInternalPilotEligibilityService $eligibility,
        private readonly SatusehatAuditLogger $audit,
    ) {}

    /** Mark a branch as a pilot candidate (starts the internal pilot workflow). */
    public function selectCandidate(Branch $branch, User $actor): SatusehatBranchPilotProfile
    {
        $this->assertEligibleBranch($branch);

        return DB::transaction(function () use ($branch, $actor) {
            $profile = $this->profiles->profileFor((int) $branch->id);
            $locked = SatusehatBranchPilotProfile::query()->lockForUpdate()->findOrFail($profile->id);

            $locked->update([
                'pilot_status' => SatusehatBranchPilotProfile::STATUS_CANDIDATE,
                'selected_by' => $actor->id,
                'selected_at' => now(),
                'suspended_at' => null,
                'suspension_reason' => null,
            ]);

            $this->log($locked, SatusehatAuditLog::EVENT_PILOT_CANDIDATE_SELECTED, 'Cabang dipilih sebagai kandidat pilot internal', $actor);

            return $locked->refresh();
        });
    }

    /**
     * Approve a branch for internal pilot. Requires every internal gate to pass
     * (INTERNAL GO only). Enforces the single-primary-pilot rule unless
     * governance explicitly allows multiple.
     */
    public function approvePilot(Branch $branch, User $actor): SatusehatBranchPilotProfile
    {
        $this->assertEligibleBranch($branch);

        $result = $this->eligibility->evaluate(
            $this->profiles->computeSnapshot((int) $branch->id),
            $this->profiles->existingProfile((int) $branch->id),
        );

        if (! $result['internal_ready']) {
            throw ValidationException::withMessages([
                'branch_id' => 'Cabang belum memenuhi seluruh gate kesiapan internal — selesaikan remediasi & rehearsal terlebih dahulu.',
            ]);
        }

        return DB::transaction(function () use ($branch, $actor) {
            if (! (bool) config('satusehat_pilot.pilot.allow_multiple', false)) {
                $otherApproved = SatusehatBranchPilotProfile::query()
                    ->where('environment', (string) config('satusehat.environment'))
                    ->where('pilot_status', SatusehatBranchPilotProfile::STATUS_APPROVED)
                    ->where('branch_id', '!=', (int) $branch->id)
                    ->lockForUpdate()
                    ->exists();

                if ($otherApproved) {
                    throw ValidationException::withMessages([
                        'branch_id' => 'Sudah ada cabang pilot utama yang disetujui. Tangguhkan cabang tersebut sebelum menyetujui cabang lain.',
                    ]);
                }
            }

            $profile = $this->profiles->profileFor((int) $branch->id);
            $locked = SatusehatBranchPilotProfile::query()->lockForUpdate()->findOrFail($profile->id);

            try {
                $locked->update([
                    'pilot_status' => SatusehatBranchPilotProfile::STATUS_APPROVED,
                    'approved_by' => $actor->id,
                    'approved_at' => now(),
                    'suspended_at' => null,
                    'suspension_reason' => null,
                ]);
            } catch (QueryException $e) {
                // The single-approved-pilot partial unique index rejected a
                // concurrent approval of a second branch (the race the row-level
                // check alone cannot close on Postgres).
                throw ValidationException::withMessages([
                    'branch_id' => 'Sudah ada cabang pilot utama yang disetujui. Tangguhkan cabang tersebut sebelum menyetujui cabang lain.',
                ]);
            }

            $this->log($locked, SatusehatAuditLog::EVENT_PILOT_APPROVED, 'Cabang disetujui untuk pilot internal (INTERNAL GO)', $actor);

            return $locked->refresh();
        });
    }

    /** Reversibly suspend a pilot branch (data + history retained). */
    public function suspendPilot(Branch $branch, string $reason, User $actor): SatusehatBranchPilotProfile
    {
        $reason = mb_substr(trim($reason), 0, 500);
        if (mb_strlen($reason) < (int) config('satusehat_pilot.sla.min_reason_length', 10)) {
            throw ValidationException::withMessages(['reason' => 'Alasan penangguhan wajib diisi dengan jelas (min. 10 karakter).']);
        }

        return DB::transaction(function () use ($branch, $reason, $actor) {
            $profile = $this->profiles->profileFor((int) $branch->id);
            $locked = SatusehatBranchPilotProfile::query()->lockForUpdate()->findOrFail($profile->id);

            $locked->update([
                'pilot_status' => SatusehatBranchPilotProfile::STATUS_SUSPENDED,
                'readiness_stage' => SatusehatBranchPilotProfile::STAGE_SUSPENDED,
                'suspended_at' => now(),
                'suspension_reason' => $reason,
            ]);

            $this->log($locked, SatusehatAuditLog::EVENT_PILOT_SUSPENDED, 'Pilot cabang ditangguhkan', $actor, ['reason' => $reason]);

            return $locked->refresh();
        });
    }

    /** Resume a suspended pilot back to candidate (re-approval required). */
    public function resumePilot(Branch $branch, User $actor): SatusehatBranchPilotProfile
    {
        return DB::transaction(function () use ($branch, $actor) {
            $profile = $this->profiles->profileFor((int) $branch->id);
            $locked = SatusehatBranchPilotProfile::query()->lockForUpdate()->findOrFail($profile->id);

            if (! $locked->isSuspended()) {
                throw ValidationException::withMessages(['branch_id' => 'Cabang tidak dalam status ditangguhkan.']);
            }

            $locked->update([
                'pilot_status' => SatusehatBranchPilotProfile::STATUS_CANDIDATE,
                'readiness_stage' => SatusehatBranchPilotProfile::STAGE_PROFILING,
                'suspended_at' => null,
                'suspension_reason' => null,
            ]);

            $this->log($locked, SatusehatAuditLog::EVENT_PILOT_RESUMED, 'Pilot cabang dilanjutkan (perlu persetujuan ulang)', $actor);

            return $locked->refresh();
        });
    }

    private function assertEligibleBranch(Branch $branch): void
    {
        if (! in_array((int) $branch->id, $this->branches->rmeEnabledIds(), true)) {
            throw ValidationException::withMessages([
                'branch_id' => 'Hanya cabang RME aktif (bukan MAIN) yang dapat menjadi pilot internal.',
            ]);
        }
    }

    private function log(SatusehatBranchPilotProfile $profile, string $event, string $summary, User $actor, array $context = []): void
    {
        $this->audit->log(
            'satusehat_branch_pilot_profile',
            (int) $profile->id,
            $event,
            $summary,
            $context + ['branch_id' => (int) $profile->branch_id, 'pilot_status' => $profile->pilot_status],
            (int) $profile->branch_id,
            $actor,
        );
    }
}
