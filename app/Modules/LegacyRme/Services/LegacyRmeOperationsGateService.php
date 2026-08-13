<?php

declare(strict_types=1);

namespace App\Modules\LegacyRme\Services;

use App\Models\User;
use App\Modules\LegacyRme\Models\LegacyRmeMigrationWave;
use App\Modules\LegacyRme\Models\LegacyRmeWaveBranch;
use App\Modules\LegacyRme\Models\LegacyRmeWaveOperator;
use App\Modules\LegacyRme\Support\LegacyRmeOperationsDecision;
use App\Modules\LegacyRme\Support\LegacyRmeWaveBranchStatus;
use App\Modules\LegacyRme\Support\LegacyRmeWaveStatus;

/**
 * LEGACY-RME-PDF-ROLL-4 — the single server-side answer to "may THIS operator
 * migrate THIS branch, right now, under the running wave?".
 *
 * THE COMPOSITION RULE — THIS IS THE WHOLE SAFETY ARGUMENT.
 *
 *   ROLL-4 CAN ONLY NARROW. NEVER WIDEN.
 *
 * The caller evaluates ROLL-3's capability, admission and capacity gates FIRST
 * and unchanged. This service is consulted only after all of them admit, and the
 * only thing it can return is "cleared" (proceed to the next gate) or a denial.
 * There is no path here that rescues a ROLL-3 refusal. An auditor asking whether
 * ROLL-4 weakened the rollout only has to verify that one property, and it holds
 * by construction: nothing in this class inspects a ROLL-3 denial, so nothing
 * here can overturn one.
 *
 * THE LAYER IS REQUIRED, NOT OPT-IN. Config declaring an admitted branch with no
 * registered wave is refused (WAVE_NOT_REGISTERED). An operations layer that
 * applies only when someone remembers to create a wave would be advisory — the
 * exact defect ROLL-3 was written to remove, resurfacing one level up.
 *
 * EVALUATION ORDER IS DELIBERATE. Wave state before branch state before operator
 * before quota, because that is the order in which the answers are useful: a
 * paused wave explains everything and reveals nothing about who is assigned, and
 * telling an unassigned operator their quota is fine would be noise.
 *
 * WHAT IT NEVER READS. A request body, a query string, a session or a
 * BranchContext selection. The branch is the RM-DERIVED code the caller resolved
 * (FIX-ROLL2-1); if a client could name it, every gate below would be advisory.
 */
class LegacyRmeOperationsGateService
{
    public function __construct(
        private readonly LegacyRmeWaveBindingService $binding,
        private readonly LegacyRmeMigrationQuotaService $quota,
    ) {}

    /**
     * Whether the operations layer is enforced on this deployment.
     *
     * Exists so a local or CI test can exercise the pre-ROLL-4 path, never so a
     * production deployment can opt out — the readiness gate FAILs when this is
     * off somewhere a real worker is expected.
     */
    public function enforced(): bool
    {
        return (bool) config('legacy_rme_operations.enforced', true);
    }

    /**
     * Decide whether a NEW document may be accepted.
     *
     * @param  string|null  $branchCode  the RM-DERIVED branch code, already resolved
     *                                   and already admitted by ROLL-3
     */
    public function decide(?User $actor, ?string $branchCode): LegacyRmeOperationsDecision
    {
        $branchCode = $branchCode !== null ? strtoupper(trim($branchCode)) : null;

        if (! $this->enforced()) {
            return LegacyRmeOperationsDecision::notEnforced($branchCode);
        }

        $declaredWave = $this->binding->declaredWaveCode();

        if ($declaredWave === null) {
            return LegacyRmeOperationsDecision::deny(
                LegacyRmeOperationsDecision::CODE_WAVE_NOT_DECLARED,
                'Gelombang migrasi belum ditetapkan pada deployment ini, sehingga migrasi belum dapat dijalankan.',
                $branchCode,
            );
        }

        $wave = $this->binding->resolveWave();

        if ($wave === null) {
            return LegacyRmeOperationsDecision::deny(
                LegacyRmeOperationsDecision::CODE_WAVE_NOT_REGISTERED,
                sprintf('Gelombang %s belum terdaftar sebagai gelombang migrasi operasional.', $declaredWave),
                $branchCode,
                $declaredWave,
            );
        }

        if (($waveState = $this->denyForWaveState($wave, $branchCode)) !== null) {
            return $waveState;
        }

        // The mirror must agree with the authority. Checked AFTER the wave is
        // known to be running, so a paused wave is reported as paused rather
        // than as a binding problem the operator cannot act on yet.
        if (! $this->binding->bindingMatches($wave)) {
            return LegacyRmeOperationsDecision::deny(
                LegacyRmeOperationsDecision::CODE_WAVE_BINDING_MISMATCH,
                'Catatan gelombang migrasi tidak cocok dengan persetujuan yang berlaku pada deployment ini.',
                $branchCode,
                $wave->code,
                (int) $wave->getKey(),
            );
        }

        if ($branchCode === null || $branchCode === '') {
            return LegacyRmeOperationsDecision::deny(
                LegacyRmeOperationsDecision::CODE_BRANCH_NOT_ENROLLED,
                'Cabang arsip belum dapat ditentukan, sehingga migrasi tidak dapat dimulai.',
                null,
                $wave->code,
                (int) $wave->getKey(),
            );
        }

        $branch = $this->resolveWaveBranch($wave, $branchCode);

        if ($branch === null) {
            return LegacyRmeOperationsDecision::deny(
                LegacyRmeOperationsDecision::CODE_BRANCH_NOT_ENROLLED,
                sprintf('Cabang %s belum didaftarkan pada gelombang migrasi %s.', $branchCode, $wave->code),
                $branchCode,
                $wave->code,
                (int) $wave->getKey(),
            );
        }

        if (($branchState = $this->denyForBranchState($branch, $wave, $branchCode)) !== null) {
            return $branchState;
        }

        if (! $this->operatorAssigned($actor, $wave, $branch)) {
            return LegacyRmeOperationsDecision::deny(
                LegacyRmeOperationsDecision::CODE_OPERATOR_NOT_ASSIGNED,
                sprintf('Anda belum ditugaskan untuk memigrasikan cabang %s pada gelombang %s.', $branchCode, $wave->code),
                $branchCode,
                $wave->code,
                (int) $wave->getKey(),
            );
        }

        $quotaDenial = $this->quota->preview($wave, $branch, $branchCode);

        if ($quotaDenial !== null) {
            return $quotaDenial;
        }

        return LegacyRmeOperationsDecision::clear($branchCode, $wave->code, (int) $wave->getKey());
    }

    /**
     * Decide whether existing migration WORK may be restarted (a retry).
     *
     * Deliberately narrower than decide(): a retry re-renders a document that
     * was already accepted, already counted against quota and already assigned
     * to an operator when it was created. Charging quota again would silently
     * cost a branch capacity it never used, and re-checking the operator would
     * strand a document because the colleague who uploaded it went on leave.
     *
     * What still applies is the wave and branch STATE: a paused or drained wave
     * means "stop starting work", and a retry starts work.
     */
    public function decideForRetry(?string $branchCode): LegacyRmeOperationsDecision
    {
        $branchCode = $branchCode !== null ? strtoupper(trim($branchCode)) : null;

        if (! $this->enforced()) {
            return LegacyRmeOperationsDecision::notEnforced($branchCode);
        }

        $wave = $this->binding->resolveWave();

        if ($wave === null) {
            // No registered wave means no operational control at all; a retry is
            // new render work and is refused for the same reason an upload is.
            return LegacyRmeOperationsDecision::deny(
                LegacyRmeOperationsDecision::CODE_WAVE_NOT_REGISTERED,
                'Gelombang migrasi operasional belum terdaftar, sehingga pemrosesan ulang tidak dapat dimulai.',
                $branchCode,
                $this->binding->declaredWaveCode(),
            );
        }

        if (($waveState = $this->denyForWaveState($wave, $branchCode)) !== null) {
            return $waveState;
        }

        if ($branchCode === null || $branchCode === '') {
            return LegacyRmeOperationsDecision::deny(
                LegacyRmeOperationsDecision::CODE_BRANCH_NOT_ENROLLED,
                'Cabang asal arsip tidak diketahui, sehingga pemrosesan ulang tidak dapat dimulai.',
                null,
                $wave->code,
                (int) $wave->getKey(),
            );
        }

        $branch = $this->resolveWaveBranch($wave, $branchCode);

        if ($branch === null) {
            return LegacyRmeOperationsDecision::deny(
                LegacyRmeOperationsDecision::CODE_BRANCH_NOT_ENROLLED,
                sprintf('Cabang %s belum didaftarkan pada gelombang migrasi %s.', $branchCode, $wave->code),
                $branchCode,
                $wave->code,
                (int) $wave->getKey(),
            );
        }

        if (($branchState = $this->denyForBranchState($branch, $wave, $branchCode)) !== null) {
            return $branchState;
        }

        return LegacyRmeOperationsDecision::clear($branchCode, $wave->code, (int) $wave->getKey());
    }

    /**
     * The enrollment row for a branch code in a wave.
     *
     * Matched on the denormalized canonical token, which is the same value
     * admission was decided on.
     */
    public function resolveWaveBranch(LegacyRmeMigrationWave $wave, string $branchCode): ?LegacyRmeWaveBranch
    {
        return LegacyRmeWaveBranch::query()
            ->where('wave_id', $wave->getKey())
            ->where('branch_code', strtoupper(trim($branchCode)))
            ->first();
    }

    /**
     * Whether an actor may migrate this branch in this wave.
     *
     * THE RULE. An INTAKE operator — someone holding `create_legacy_rme_imports`
     * and nothing more — must carry an explicit, unrevoked assignment for this
     * exact branch. That is the boundary this gate exists for: a permission can
     * only answer "may this person migrate?", and across a five-branch wave the
     * question that protects a clinic is "may this person migrate THIS branch?".
     *
     * THE ONE EXEMPTION, AND WHY IT IS NOT A BYPASS. A holder of
     * `manage_legacy_rme_migration_operations` governs the wave: they can call
     * assignOperator() for themselves, for any enrolled branch, with no further
     * approval. The set of branches they can ingest into is therefore IDENTICAL
     * whether or not this check is applied to them — requiring the assignment
     * would remove a step, not a capability. Enforcing ceremony that changes
     * nothing trains operators to click through it, which is worse than not
     * having it.
     *
     * What still constrains a governor: the capability flag, ROLL-3 admission,
     * the wave's approval binding, wave and branch state, and quota. The
     * exemption reaches exactly one of the seven gates, and only the one they
     * already control.
     *
     * An unauthenticated actor is never cleared.
     */
    public function operatorAssigned(?User $actor, LegacyRmeMigrationWave $wave, LegacyRmeWaveBranch $branch): bool
    {
        if ($actor === null) {
            return false;
        }

        if ($actor->can('manage_legacy_rme_migration_operations')) {
            return true;
        }

        return LegacyRmeWaveOperator::query()
            ->active()
            ->where('wave_id', $wave->getKey())
            ->where('user_id', $actor->getKey())
            ->where('branch_id', $branch->branch_id)
            ->exists();
    }

    private function denyForWaveState(LegacyRmeMigrationWave $wave, ?string $branchCode): ?LegacyRmeOperationsDecision
    {
        if ($wave->status === LegacyRmeWaveStatus::ACTIVE) {
            return null;
        }

        [$code, $message] = match ($wave->status) {
            LegacyRmeWaveStatus::PAUSED => [
                LegacyRmeOperationsDecision::CODE_WAVE_PAUSED,
                sprintf('Gelombang migrasi %s sedang dijeda, sehingga dokumen baru belum dapat diterima.', $wave->code),
            ],
            LegacyRmeWaveStatus::DRAINING => [
                LegacyRmeOperationsDecision::CODE_WAVE_DRAINING,
                sprintf('Gelombang migrasi %s sedang diakhiri, sehingga dokumen baru tidak lagi diterima.', $wave->code),
            ],
            LegacyRmeWaveStatus::COMPLETED, LegacyRmeWaveStatus::CANCELLED => [
                LegacyRmeOperationsDecision::CODE_WAVE_CLOSED,
                sprintf('Gelombang migrasi %s sudah ditutup.', $wave->code),
            ],
            default => [
                LegacyRmeOperationsDecision::CODE_WAVE_NOT_ACTIVE,
                sprintf('Gelombang migrasi %s belum dijalankan.', $wave->code),
            ],
        };

        return LegacyRmeOperationsDecision::deny($code, $message, $branchCode, $wave->code, (int) $wave->getKey());
    }

    private function denyForBranchState(
        LegacyRmeWaveBranch $branch,
        LegacyRmeMigrationWave $wave,
        string $branchCode,
    ): ?LegacyRmeOperationsDecision {
        if ($branch->status === LegacyRmeWaveBranchStatus::ACTIVE) {
            return null;
        }

        [$code, $message] = match ($branch->status) {
            LegacyRmeWaveBranchStatus::PAUSED => [
                LegacyRmeOperationsDecision::CODE_BRANCH_PAUSED,
                sprintf('Migrasi cabang %s sedang dijeda.', $branchCode),
            ],
            LegacyRmeWaveBranchStatus::DRAINING => [
                LegacyRmeOperationsDecision::CODE_BRANCH_DRAINING,
                sprintf('Migrasi cabang %s sedang diakhiri, sehingga dokumen baru tidak lagi diterima.', $branchCode),
            ],
            LegacyRmeWaveBranchStatus::COMPLETED, LegacyRmeWaveBranchStatus::CANCELLED => [
                LegacyRmeOperationsDecision::CODE_BRANCH_CLOSED,
                sprintf('Migrasi cabang %s sudah ditutup.', $branchCode),
            ],
            default => [
                LegacyRmeOperationsDecision::CODE_BRANCH_NOT_ACTIVE,
                sprintf('Migrasi cabang %s belum dijalankan.', $branchCode),
            ],
        };

        return LegacyRmeOperationsDecision::deny($code, $message, $branchCode, $wave->code, (int) $wave->getKey());
    }
}
