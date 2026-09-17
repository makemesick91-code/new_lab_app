<?php

namespace App\Modules\DoctorAccess\Services;

use App\Models\User;
use App\Modules\DoctorAccess\Support\DoctorGlobalEnforcementPrerequisite as Prerequisite;
use App\Modules\DoctorDevice\Interfaces\DoctorDeviceRolloutReadinessRepositoryInterface;
use App\Modules\DoctorDevice\Services\DoctorAppLoginGate;
use App\Services\Foundation\FeatureFlagService;
use App\Support\Android\AndroidDoctorEnforcementScope;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Throwable;

/**
 * DOCTOR-ACCESS-GLOBAL-DEVICE-ENFORCEMENT-READINESS-1 — the producer
 * `rollback_to_browser_login_proven` never had.
 *
 * WHAT WAS ACTUALLY MISSING, AND WHAT WAS NOT.
 *
 * The rollback was not undocumented and it was not untested.
 * `DoctorAccessEnforcementRollbackTest` performs each disarm and asserts that
 * browser login comes back, that no foundation row is destroyed, and that the
 * flags resolve through a cached config the way production does. Those tests
 * are good and this class does not replace them.
 *
 * What was missing is narrower and it is the thing the GATE needs: the
 * prerequisite named `rollback_to_browser_login_proven` had **zero occurrences
 * under `app/`**. A green test suite on a developer's branch is not a
 * measurement a production gate can read. So the prerequisite could only ever
 * be "satisfied" by flipping a hand-signed boolean in config — recording
 * something nobody had measured on the deployment being activated.
 *
 * This class measures it, on the deployment it is run against.
 *
 * HOW IT PROVES A ROLLBACK WITHOUT PERFORMING ONE.
 *
 * Every collaborator in the enforcement decision reads `config()` on each call
 * and memoizes nothing — verified: `AndroidDoctorEnforcementScope` holds no
 * properties and no constructor, and `FeatureFlagService::definitions()` reads
 * the config repository every time. So the posture can be moved in-process,
 * observed, and moved back, with the restoration verifiable by comparison.
 *
 * The rehearsal therefore:
 *
 *   1. CAPTURES the live pre-activation posture — mode, cohort, global
 *      permission, the enforcement flag and the governance phase.
 *   2. Moves the in-process config to the ACTIVATED posture and asks the real
 *      {@see DoctorAppLoginGate} whether a real doctor who is NOT in today's
 *      cohort may hold a browser session. Under Half B they may not.
 *   3. Restores the CAPTURED posture and asks again. The rollback is proven
 *      when the same doctor, on the same deployment, is admitted again.
 *   4. Confirms the global denial is no longer effective from the resolved
 *      scope rather than from the value that was written back.
 *   5. Confirms the captured values are back, key for key.
 *
 * WHAT IT NEVER DOES. It writes no file, no row and no audit entry; it runs no
 * `config:cache`; it changes no environment value; and every override it makes
 * lives in the process's own config repository for the length of one method and
 * is undone in a `finally`. It is safe to run against production, and the only
 * database access it makes is reading the doctor accounts it needs a subject
 * from.
 *
 * WHY IT USES A REAL DOCTOR. A synthetic user would prove that the gate denies
 * a synthetic user. The question the prerequisite asks is whether THIS
 * deployment's doctors get their browser back, so the subject is one of them,
 * read-only, and named in the report so the reading can be reproduced.
 *
 * WHY THE SUBJECT IS OUTSIDE THE COHORT. A doctor already inside the pilot
 * cohort is denied before AND after the rehearsal, so they can demonstrate
 * nothing about rolling a WIDENING back. The subject is a doctor Half B would
 * newly capture — exactly the population a rollback has to release.
 */
class DoctorHalfBRollbackProofService
{
    /** The posture keys this rehearsal moves, and therefore must restore. */
    public const CONFIG_SCOPE_MODE = 'doctor_device_enforcement.scope.mode';

    public const CONFIG_COHORT_SINGULAR = 'doctor_device_enforcement.scope.pilot.doctor_user_id';

    public const CONFIG_COHORT_PLURAL = 'doctor_device_enforcement.scope.pilot.doctor_user_ids';

    public const CONFIG_GLOBAL_PERMITTED = 'android_release.enforcement.scope.global_permitted';

    public const CONFIG_FEATURE_FLAGS = 'feature_flags.flags';

    public const STEP_POSTURE_CAPTURED = 'pre_activation_posture_captured';

    public const STEP_SUBJECT_RESOLVED = 'rollback_subject_resolved';

    public const STEP_GLOBAL_DENIES = 'global_posture_denies_browser_login';

    public const STEP_ROLLBACK_RESTORES = 'rollback_restores_browser_login';

    public const STEP_GLOBAL_NOT_EFFECTIVE = 'global_denial_not_effective_after_rollback';

    public const STEP_CONFIG_RESTORED = 'captured_posture_restored_exactly';

    /** Nobody holds the Doctor role, so the rehearsal has no subject at all. */
    public const REASON_NO_DOCTORS = 'no_doctor_accounts_to_measure';

    /**
     * Every doctor is already covered, so widening releases nobody.
     *
     * Reported UNVERIFIED rather than PASS. If the live posture is already
     * global then "roll back to the captured posture" restores a posture that
     * denies the subject too, and a rehearsal that cannot observe the
     * difference has not proven the rollback — it has only proven that two
     * identical postures behave identically.
     */
    public const REASON_ALREADY_GLOBAL = 'captured_posture_already_covers_every_doctor';

    public function __construct(
        private readonly DoctorDeviceRolloutReadinessRepositoryInterface $estate,
        private readonly DoctorAppLoginGate $gate,
        private readonly AndroidDoctorEnforcementScope $scope,
        private readonly FeatureFlagService $flags,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function prove(): array
    {
        $captured = $this->capture();
        $steps = [];

        $steps[] = $this->step(
            self::STEP_POSTURE_CAPTURED,
            $this->postureIsWellFormed($captured) ? Prerequisite::PASS : Prerequisite::FAIL,
            $this->postureIsWellFormed($captured)
                ? 'The live pre-activation posture was captured key for key: mode "'.$captured['scope_mode']
                .'", cohort ['.implode(',', $captured['cohort']).'], global_permitted '
                .var_export($captured['global_permitted'], true).'.'
                : 'The live posture is not well formed, so there is nothing coherent to roll back TO. '
                .'A rollback target that cannot be described cannot be restored.',
        );

        $subject = $this->subject();

        if (! $subject instanceof User) {
            $steps[] = $this->step(
                self::STEP_SUBJECT_RESOLVED,
                Prerequisite::UNVERIFIED,
                'No doctor could serve as a rollback subject: '.$this->subjectRefusal()
                .' An unmeasurable rollback is UNVERIFIED and never a pass.',
            );

            return $this->report($steps, $captured, null);
        }

        $steps[] = $this->step(
            self::STEP_SUBJECT_RESOLVED,
            Prerequisite::PASS,
            'Rollback subject is user '.$subject->id.' ("'.$subject->name.'"), who holds the Doctor role and is '
            .'NOT covered by the captured scope — so Half B would newly capture them and a rollback must '
            .'release them.',
        );

        /*
         * FROM HERE THE PROCESS CONFIG IS MOVED. Everything below runs inside
         * the try, and the finally restores the captured values whether the
         * rehearsal passed, failed or threw. There is no early return between
         * the move and the restore.
         */
        try {
            $this->applyGlobalPosture();

            $deniedUnderGlobal = $this->browserDenyReason($subject);

            $steps[] = $this->step(
                self::STEP_GLOBAL_DENIES,
                $deniedUnderGlobal !== null ? Prerequisite::PASS : Prerequisite::FAIL,
                $deniedUnderGlobal !== null
                    ? 'Under the activated posture the gate refuses this doctor a browser session ('
                    .$deniedUnderGlobal.'), which is the state a rollback has to be able to undo.'
                    : 'Under the activated posture the gate still ADMITS this doctor to a browser session. '
                    .'Half B would not take effect, so there is no denial for a rollback to reverse and this '
                    .'rehearsal proves nothing.',
            );

            $this->restore($captured);

            $admittedAfterRollback = $this->browserDenyReason($subject);

            $steps[] = $this->step(
                self::STEP_ROLLBACK_RESTORES,
                $admittedAfterRollback === null ? Prerequisite::PASS : Prerequisite::FAIL,
                $admittedAfterRollback === null
                    ? 'After restoring the captured posture the same doctor is admitted to a browser session '
                    .'again. The rollback was executed, not described.'
                    : 'After restoring the captured posture this doctor is STILL refused a browser session ('
                    .$admittedAfterRollback.'). The rollback does not release the population Half B captures.',
            );

            $stillGlobal = $this->scope->isUnscopedMode() && $this->scope->globalPermitted();

            $steps[] = $this->step(
                self::STEP_GLOBAL_NOT_EFFECTIVE,
                $stillGlobal ? Prerequisite::FAIL : Prerequisite::PASS,
                $stillGlobal
                    ? 'The resolved scope is STILL unscoped-and-permitted after the rollback. A restored value '
                    .'that does not change the resolved scope is a rollback in name only.'
                    : 'The resolved scope is no longer unscoped-and-permitted. Measured from the scope itself, '
                    .'never from the value that was written back.',
            );
        } catch (Throwable $e) {
            $steps[] = $this->step(
                self::STEP_ROLLBACK_RESTORES,
                Prerequisite::FAIL,
                'The rollback rehearsal threw and could not complete: '.$e->getMessage(),
            );
        } finally {
            $this->restore($captured);
        }

        $restored = $this->capture();

        $steps[] = $this->step(
            self::STEP_CONFIG_RESTORED,
            $restored == $captured ? Prerequisite::PASS : Prerequisite::FAIL,
            $restored == $captured
                ? 'Every posture key this rehearsal moved holds its captured value again.'
                : 'The process configuration did NOT return to its captured values. This rehearsal must be '
                .'treated as having disturbed the runtime posture and the deployment re-verified.',
        );

        return $this->report($steps, $captured, $subject);
    }

    /**
     * The live posture, as values rather than as a description of values.
     *
     * Captured — never remembered. A rollback that restores a constant
     * somebody wrote down restores whatever was true when they wrote it, and
     * the cohort on this deployment is the UNION of two host variables that
     * has already moved once inside a single day.
     *
     * @return array<string,mixed>
     */
    public function capture(): array
    {
        return [
            'scope_mode' => config(self::CONFIG_SCOPE_MODE),
            'cohort_singular' => config(self::CONFIG_COHORT_SINGULAR),
            'cohort_plural' => config(self::CONFIG_COHORT_PLURAL),
            'global_permitted' => config(self::CONFIG_GLOBAL_PERMITTED),
            'enforcement_flag_armed' => $this->flags->enabled(DoctorAppLoginGate::ENFORCEMENT_FLAG),
            'cohort' => $this->scope->pilotDoctorUserIds(),
            'resolved_mode' => $this->scope->mode(),
        ];
    }

    /**
     * @param  array<string,mixed>  $captured
     */
    private function postureIsWellFormed(array $captured): bool
    {
        $mode = (string) $captured['resolved_mode'];

        if (! in_array($mode, [AndroidDoctorEnforcementScope::MODE_PILOT, AndroidDoctorEnforcementScope::MODE_UNSCOPED], true)) {
            return false;
        }

        // A pilot posture whose cohort does not resolve is not a rollback
        // target: restoring it would restore "covers nobody", which is a
        // different posture from the one the deployment is actually running.
        return $mode !== AndroidDoctorEnforcementScope::MODE_PILOT || $captured['cohort'] !== [];
    }

    /**
     * A real doctor whom the captured scope does NOT cover.
     */
    private function subject(): ?User
    {
        if ($this->scope->isUnscopedMode() && $this->scope->globalPermitted()) {
            return null;
        }

        return $this->estate->doctorAccounts()
            ->first(fn (User $user): bool => ! $this->scope->coversUser((int) $user->id));
    }

    private function subjectRefusal(): string
    {
        if ($this->scope->isUnscopedMode() && $this->scope->globalPermitted()) {
            return self::REASON_ALREADY_GLOBAL.' — the live posture already enforces every doctor, so widening '
                .'captures nobody new and a restoration cannot be observed.';
        }

        return self::REASON_NO_DOCTORS.' — every Doctor account is already inside the captured scope, or there '
            .'are none at all.';
    }

    /**
     * Move the process config to the posture Half B would run in.
     */
    private function applyGlobalPosture(): void
    {
        config()->set(self::CONFIG_SCOPE_MODE, AndroidDoctorEnforcementScope::MODE_UNSCOPED);
        config()->set(self::CONFIG_GLOBAL_PERMITTED, true);

        $flags = (array) config(self::CONFIG_FEATURE_FLAGS, []);
        $flags[DoctorAppLoginGate::ENFORCEMENT_FLAG]['default'] = true;
        $flags[DoctorAppLoginGate::ENFORCEMENT_FLAG]['env_value'] = true;
        config()->set(self::CONFIG_FEATURE_FLAGS, $flags);
    }

    /**
     * @param  array<string,mixed>  $captured
     */
    private function restore(array $captured): void
    {
        config()->set(self::CONFIG_SCOPE_MODE, $captured['scope_mode']);
        config()->set(self::CONFIG_COHORT_SINGULAR, $captured['cohort_singular']);
        config()->set(self::CONFIG_COHORT_PLURAL, $captured['cohort_plural']);
        config()->set(self::CONFIG_GLOBAL_PERMITTED, $captured['global_permitted']);

        $flags = (array) config(self::CONFIG_FEATURE_FLAGS, []);
        $flags[DoctorAppLoginGate::ENFORCEMENT_FLAG]['default'] = $captured['enforcement_flag_armed'];
        $flags[DoctorAppLoginGate::ENFORCEMENT_FLAG]['env_value'] = $captured['enforcement_flag_armed'];
        config()->set(self::CONFIG_FEATURE_FLAGS, $flags);
    }

    /**
     * Ask the real gate, with a real browser-shaped request.
     *
     * The session is empty and detached from any store because that is exactly
     * what a browser login is: no device binding, because only ticket
     * redemption writes one.
     */
    private function browserDenyReason(User $user): ?string
    {
        $request = Request::create('/dashboard', 'GET');
        $request->setLaravelSession(new Store('doctor_half_b_rehearsal', new ArraySessionHandler(1)));

        return $this->gate->denyBrowserSessionReason($user, $request);
    }

    /**
     * @return array<string,mixed>
     */
    private function step(string $id, string $status, string $detail): array
    {
        return ['step' => $id, 'status' => $status, 'detail' => $detail];
    }

    /**
     * @param  list<array<string,mixed>>  $steps
     * @param  array<string,mixed>  $captured
     * @return array<string,mixed>
     */
    private function report(array $steps, array $captured, ?User $subject): array
    {
        return [
            'prerequisite' => Prerequisite::ROLLBACK_TO_BROWSER_LOGIN_PROVEN,
            'measured' => Prerequisite::worst(array_map(
                static fn (array $step): string => (string) $step['status'],
                $steps,
            )),
            'steps' => $steps,
            'subject_user_id' => $subject?->id,
            'captured_posture' => [
                'scope_mode' => $captured['resolved_mode'],
                'cohort' => $captured['cohort'],
                'global_permitted' => $captured['global_permitted'],
                'enforcement_flag_armed' => $captured['enforcement_flag_armed'],
            ],
            'mutations' => 0,
            'semantics' => 'This is a REHEARSAL against the live posture, executed in this process and undone '
                .'before it returns. It proves the rollback CHAIN works on this deployment. It does not arm, '
                .'disarm or authorize anything, and a PASS here authorizes no activation.',
        ];
    }
}
