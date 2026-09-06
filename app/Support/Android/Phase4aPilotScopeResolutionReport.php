<?php

namespace App\Support\Android;

use App\Models\User;
use App\Modules\DoctorDevice\Services\DoctorAppLoginGate;
use Illuminate\Http\Request;

/**
 * PHASE4A-DOCTOR-ANDROID-PILOT-ACTIVATION-1 — who does the configured
 * enforcement scope actually cover, on this deployment, right now?
 *
 * WHY "USABLE" WAS NOT AN ANSWER
 *
 * `android:phase4a-pilot-readiness` reports the scope MODE and whether it is
 * USABLE. Usable means "this configuration could enforce something" — it is
 * true for any positive integer sitting in the pilot target, including the
 * wrong one. The activation order asks for something stricter twice over:
 * prove the scope resolves to exactly the intended doctor BEFORE arming, and
 * prove afterwards that every other doctor still has their browser. Neither
 * question could be asked.
 *
 * The gap has teeth on this pilot because the target is a `users.id` and every
 * doctor also has an `mst_doctors.id`. The two are adjacent small integers for
 * the same person and neither is labelled in the environment. A mix-up does not
 * fail loudly: it points enforcement at a real, valid, different doctor —
 * leaving the pilot doctor unenforced and denying an unrelated one her browser
 * at the same time.
 *
 * WHY IT ASKS THE GATE INSTEAD OF LOGGING ANYONE IN
 *
 * The honest proof of "another doctor can still log in" is a login, and a login
 * needs that doctor's password. Nobody should be handling one to run a check.
 * `DoctorAppLoginGate` decides a browser session from the user id and the
 * ABSENCE of a device-bound session, so a request carrying no session
 * reproduces the browser decision exactly — no credential, and an answer for
 * every doctor at once rather than the one who happened to be asked.
 *
 * READ-ONLY. It queries users and roles, writes nothing, arms nothing, and is
 * safe to run on production at any point in the activation.
 */
final class Phase4aPilotScopeResolutionReport
{
    public const VERDICT_GO = 'GO';

    public const VERDICT_WATCH = 'WATCH';

    public const VERDICT_FAIL = 'FAIL';

    public const FINDING_ARMED_BUT_COVERS_NOBODY = 'armed_but_covers_nobody';

    public const FINDING_FLEET_WIDE_NOT_PERMITTED = 'fleet_wide_scope_not_permitted_in_phase_4a';

    public const FINDING_COVERS_MORE_THAN_ONE = 'pilot_scope_covers_more_than_one_doctor';

    public const FINDING_TARGET_NOT_COVERED = 'declared_pilot_target_is_not_covered';

    public const FINDING_NOT_CONFIGURED = 'pilot_scope_not_configured_yet';

    public function __construct(
        private readonly DoctorAppLoginGate $gate,
        private readonly AndroidDoctorEnforcementScope $scope,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function build(): array
    {
        $armed = $this->gate->enforcementEnabled();
        $mode = $this->scope->mode();
        $usable = $this->scope->isUsable();
        $declaredTarget = $this->scope->pilotDoctorUserId();

        // A request with no session is the browser case: only ticket redemption
        // ever writes a device binding, so "no session" is precisely what an
        // ordinary Chrome login carries at the moment the gate decides.
        $browserRequest = Request::create('/login', 'POST');

        $doctors = User::query()
            ->whereNull('deleted_at')
            ->get()
            ->filter(fn (User $user): bool => $this->gate->appliesTo($user))
            ->values();

        $covered = [];
        $deniedBrowser = [];
        $allowedBrowser = [];

        foreach ($doctors as $doctor) {
            $inScope = $this->gate->inEnforcementScope($doctor);

            if ($inScope) {
                $covered[] = [
                    'user_id' => (int) $doctor->id,
                    'name' => (string) $doctor->name,
                ];
            }

            // Only meaningful while armed; reported as the prediction it is.
            if ($armed && $this->gate->denyBrowserSessionReason($doctor, $browserRequest) !== null) {
                $deniedBrowser[] = (int) $doctor->id;
            } else {
                $allowedBrowser[] = (int) $doctor->id;
            }
        }

        $coveredIds = array_map(static fn (array $row): int => $row['user_id'], $covered);

        $findings = $this->findings($armed, $mode, $usable, $declaredTarget, $coveredIds);

        return [
            'sprint' => 'PHASE4A-DOCTOR-ANDROID-PILOT-ACTIVATION-1',
            'enforcement_flag_armed' => $armed,
            'enforcement_scope_mode' => $mode,
            'enforcement_scope_usable' => $usable,
            'global_enforcement_active' => $armed && $this->scope->isUnscopedMode() && $this->scope->globalPermitted(),
            'global_scope_permitted' => $this->scope->globalPermitted(),
            'declared_pilot_doctor_user_id' => $declaredTarget,
            'declared_pilot_branch_code' => $this->scope->pilotBranchCode(),
            'doctor_role_account_count' => $doctors->count(),
            'covered_doctor_count' => count($covered),
            'covered_doctor_user_ids' => $coveredIds,
            'covered_doctors' => $covered,
            'browser_denied_doctor_count' => count($deniedBrowser),
            'browser_allowed_doctor_count' => count($allowedBrowser),
            'findings' => $findings,
            'verdict' => $this->verdict($findings, $usable, $coveredIds),
        ];
    }

    /**
     * @param  list<int>  $coveredIds
     * @return list<string>
     */
    private function findings(bool $armed, string $mode, bool $usable, ?int $declaredTarget, array $coveredIds): array
    {
        $findings = [];

        // Phase 4A does not permit fleet-wide denial at all. Checked before the
        // "covers nobody" rule, because a fleet-wide scope that is refused
        // permission covers nobody too, and the more specific fact is the
        // useful one to print.
        if ($mode === AndroidDoctorEnforcementScope::MODE_UNSCOPED && $this->scope->globalPermitted()) {
            $findings[] = self::FINDING_FLEET_WIDE_NOT_PERMITTED;
        }

        // Armed and enforcing nothing. Reached either by a scope that covers
        // nobody, or by one pointed at an account that is not a doctor — the
        // role predicate still has to hold, so a usable scope is not enough.
        if ($armed && $coveredIds === []) {
            $findings[] = self::FINDING_ARMED_BUT_COVERS_NOBODY;
        }

        if ($mode === AndroidDoctorEnforcementScope::MODE_PILOT) {
            if (count($coveredIds) > 1) {
                $findings[] = self::FINDING_COVERS_MORE_THAN_ONE;
            }

            // The declared target exists but did not turn up in the covered
            // set: it names an account that is missing, deleted, or not a
            // doctor. The pilot is not running on the doctor it claims.
            if ($declaredTarget !== null && ! in_array($declaredTarget, $coveredIds, true)) {
                $findings[] = self::FINDING_TARGET_NOT_COVERED;
            }
        }

        if (! $usable && ! $armed) {
            $findings[] = self::FINDING_NOT_CONFIGURED;
        }

        return $findings;
    }

    /**
     * @param  list<string>  $findings
     * @param  list<int>  $coveredIds
     */
    private function verdict(array $findings, bool $usable, array $coveredIds): string
    {
        // An unconfigured deployment is the honest pre-activation state. It is
        // not a pass — nothing is scoped — and it is not a failure either.
        if ($findings === [self::FINDING_NOT_CONFIGURED]) {
            return self::VERDICT_WATCH;
        }

        if ($findings !== []) {
            return self::VERDICT_FAIL;
        }

        return $usable && count($coveredIds) === 1
            ? self::VERDICT_GO
            : self::VERDICT_WATCH;
    }
}
