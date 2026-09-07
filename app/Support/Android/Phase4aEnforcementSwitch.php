<?php

namespace App\Support\Android;

use App\Models\User;
use App\Modules\DoctorDevice\Services\DoctorAppLoginGate;
use App\Modules\LabOrder\Services\AuditLogService;
use Illuminate\Validation\ValidationException;

/**
 * PHASE4A-DOCTOR-ANDROID-PILOT-ACTIVATION-1 — arming and disarming the pilot,
 * accountably.
 *
 * WHY THIS EXISTS
 *
 * The runbook requires `pilot_enforcement_scope_changed` in the audit trail,
 * and nothing produced it. Arming was a host file edit plus a cache rebuild:
 * no application code ran, so no audit row could be written. The single most
 * consequential action in the pilot — denying a clinician their browser — was
 * the one action leaving no trace, while renaming a device label produced a
 * complete before/after with a named actor.
 *
 * WHY POST-VERIFICATION RUNS IN A SEPARATE PROCESS
 *
 * Feature flags are captured from the environment at config-build time. Writing
 * the environment file does not change `config()` inside the process that wrote
 * it, so a self-check here would read the OLD value and cheerfully confirm a
 * change that had not taken effect. The verifier therefore shells out to the
 * scope diagnostic, which is the same thing an operator would run and the only
 * reading that reflects the rebuilt cache.
 *
 * THE DIRECTION IT FAILS IN
 *
 * Arming is refused unless every precondition holds: a reason, an authorised
 * actor, fleet-wide permission off, a usable scope covering exactly one doctor,
 * and that doctor being the declared target. If post-change verification does
 * not come back GO, the environment file is restored and the failure is raised
 * — a half-applied clinical lockout is the worst outcome available, so it is
 * the one case that must never survive.
 *
 * Disarming carries no such gate. Refusing to disarm because a precondition is
 * unmet would strand a doctor for the sake of tidiness.
 */
final class Phase4aEnforcementSwitch
{
    public const AUDIT_ACTION = 'PILOT_ENFORCEMENT_SCOPE_CHANGED';

    public const AUDIT_ENTITY = 'phase4a_pilot_enforcement';

    /** Enough that "fix" or "test" does not pass for an explanation. */
    public const MINIMUM_REASON_LENGTH = 10;

    /** @var callable(): array<string,mixed> */
    private $verifier;

    /** @var callable(): void */
    private $cacheRebuilder;

    public function __construct(
        private readonly AuditLogService $auditLogs,
        private readonly AndroidDoctorEnforcementScope $scope,
        private readonly DoctorAppLoginGate $gate,
        private readonly EnvFileWriter $env,
        ?callable $verifier = null,
        ?callable $cacheRebuilder = null,
    ) {
        $this->verifier = $verifier ?? fn (): array => $this->env->verifyThroughFreshProcess();
        $this->cacheRebuilder = $cacheRebuilder ?? function () use ($env): void {
            $env->rebuildConfigCache();
        };
    }

    /** Read-only. Writes nothing, changes nothing, audits nothing. */
    public function status(): array
    {
        return [
            'enforcement_flag_armed' => $this->gate->enforcementEnabled(),
            'scope_mode' => $this->scope->mode(),
            'scope_usable' => $this->scope->isUsable(),
            'target_user_id' => $this->scope->pilotDoctorUserId(),
            'target_branch_code_advisory' => $this->scope->pilotBranchCode(),
            'global_enforcement_permitted' => $this->scope->globalPermitted(),
        ];
    }

    /**
     * Arm pilot-scoped enforcement.
     *
     * @param  array<string,mixed>  $preChange  the scope report taken before the change
     */
    public function arm(User $actor, string $reason, array $preChange): array
    {
        $this->assertReason($reason);
        $this->assertArmPreconditions($preChange);

        return $this->applyAndRecord($actor, $reason, $preChange, armed: true);
    }

    /** Disarm. Deliberately ungated beyond a reason and an actor. */
    public function disarm(User $actor, string $reason, array $preChange): array
    {
        $this->assertReason($reason);

        return $this->applyAndRecord($actor, $reason, $preChange, armed: false);
    }

    private function assertReason(string $reason): void
    {
        if (mb_strlen(trim($reason)) < self::MINIMUM_REASON_LENGTH) {
            throw ValidationException::withMessages([
                'reason' => 'Alasan perubahan wajib diisi dan harus menjelaskan konteksnya.',
            ]);
        }
    }

    /**
     * Every one of these is a way a pilot becomes a fleet lockout or a silent
     * no-op, so each is refused by name rather than by a single vague check.
     *
     * @param  array<string,mixed>  $preChange
     */
    private function assertArmPreconditions(array $preChange): void
    {
        if ($this->scope->globalPermitted()) {
            throw ValidationException::withMessages([
                'scope' => 'Fleet-wide enforcement permitted: Phase 4A tidak mengizinkan penguncian seluruh armada.',
            ]);
        }

        if ($this->scope->mode() !== AndroidDoctorEnforcementScope::MODE_PILOT) {
            throw ValidationException::withMessages([
                'scope' => 'Scope mode bukan "pilot": hanya pilot yang boleh diaktifkan pada Phase 4A.',
            ]);
        }

        $target = $this->scope->pilotDoctorUserId();

        if ($target === null) {
            throw ValidationException::withMessages([
                'scope' => 'Pilot doctor belum ditetapkan: mengaktifkan flag tanpa target tidak menegakkan apa pun.',
            ]);
        }

        $covered = array_values((array) ($preChange['covered_doctor_user_ids'] ?? []));

        if (count($covered) !== 1) {
            throw ValidationException::withMessages([
                'scope' => 'Scope harus mencakup tepat satu dokter, bukan '.count($covered).'.',
            ]);
        }

        if ((int) $covered[0] !== $target) {
            throw ValidationException::withMessages([
                'scope' => 'Dokter yang tercakup bukan target pilot yang dideklarasikan.',
            ]);
        }
    }

    /**
     * Apply, verify in a fresh process, then record. In that order.
     *
     * The audit is written only after verification succeeds, so the trail never
     * claims a change that did not take effect. If verification fails the
     * environment file is restored and nothing is recorded except the failure
     * raised to the operator.
     *
     * @param  array<string,mixed>  $preChange
     */
    private function applyAndRecord(User $actor, string $reason, array $preChange, bool $armed): array
    {
        $before = $this->gate->enforcementEnabled();
        $restore = $this->env->snapshot();

        $this->env->setFlag($armed);

        try {
            ($this->cacheRebuilder)();
            $postChange = ($this->verifier)();
        } catch (\Throwable $e) {
            $this->env->restore($restore);
            ($this->cacheRebuilder)();

            throw $e;
        }

        $verdict = (string) ($postChange['verdict'] ?? 'UNKNOWN');
        $globalActive = (bool) ($postChange['global_enforcement_active'] ?? false);

        // A half-applied clinical lockout is the worst outcome available.
        if ($verdict !== 'GO' || $globalActive) {
            $this->env->restore($restore);
            ($this->cacheRebuilder)();

            throw ValidationException::withMessages([
                'verification' => 'Verifikasi setelah perubahan gagal (verdict '.$verdict.'), perubahan dikembalikan.',
            ]);
        }

        $this->auditLogs->log(
            self::AUDIT_ENTITY,
            null,
            self::AUDIT_ACTION,
            ['armed' => $before],
            [
                'armed' => $armed,
                'reason' => $reason,
                'scope_mode' => (string) ($postChange['enforcement_scope_mode'] ?? ''),
                'target_user_id' => $postChange['declared_pilot_doctor_user_id'] ?? null,
                // Advisory context. BranchContext remains the authority on where
                // a doctor is working; this is here so a reader can see what was
                // intended, never so anything can be decided from it.
                'target_branch_code_advisory' => $postChange['declared_pilot_branch_code'] ?? null,
                'global_enforcement_active' => $globalActive,
                'covered_doctor_count' => $postChange['covered_doctor_count'] ?? null,
                'browser_denied_doctor_count' => $postChange['browser_denied_doctor_count'] ?? null,
                'browser_allowed_doctor_count' => $postChange['browser_allowed_doctor_count'] ?? null,
                'post_change_scope_verdict' => $verdict,
            ],
            $actor,
        );

        return [
            'before_armed' => $before,
            'after_armed' => $armed,
            'post_change' => $postChange,
        ];
    }
}
