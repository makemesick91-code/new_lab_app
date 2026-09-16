<?php

namespace App\Support\Android;

use App\Modules\DoctorDevice\Services\DoctorAppLoginGate;
use App\Services\Foundation\FeatureFlagService;

/**
 * PHASE4A-DOCTOR-ANDROID-PILOT-PREPARATION-1 — is the next sprint allowed to
 * start?
 *
 * Answers one question: can an operator install the already-approved production
 * APK on exactly one already-approved tablet, and arm enforcement for exactly
 * one already-approved doctor, without improvising anything?
 *
 * It reads configuration and the recorded release manifest. It signs nothing,
 * builds nothing, installs nothing, opens no vault, reaches no network, touches
 * no device and holds no credential. A test asserts that this file contains
 * none of the primitives that could do any of those things, because a
 * preparation gate that can act is a preparation gate that will one day act.
 *
 * Rules live in config/android_release.php rather than inline. That is the
 * Phase 3 lesson: a scanner whose own source matches the strings it forbids
 * reddens on a codebase that is obeying it, and gets deleted for being wrong.
 */
class Phase4aPilotPreparationScanner
{
    /**
     * Enforcement is not a boolean, and reporting it as one is what made this
     * gate wrong. DOCTOR-PWA-GLOBAL-ROLLOUT-READINESS-1 names the four states
     * the programme actually passes through, so "armed" stops meaning both
     * "an owner-approved pilot is running" and "the fleet is locked out".
     */
    public const POSTURE_OFF = 'off';

    /** A named, ceilinged cohort is enforced. Every other doctor keeps browser login. */
    public const POSTURE_BOUNDED_PILOT = 'bounded_pilot';

    /** The bounded pilot still runs, and the fleet is being measured for a widening that has NOT happened. */
    public const POSTURE_GLOBAL_ROLLOUT_READINESS = 'global_rollout_readiness';

    /** Phase 5. Never reachable from this phase. */
    public const POSTURE_GLOBAL = 'global';

    /** Armed over a scope that resolves to nobody — a state, not a declaration. */
    public const POSTURE_INDETERMINATE = 'indeterminate';

    /**
     * Declarable postures. `indeterminate` is deliberately absent: it is
     * something a deployment can be observed in, never something a reviewer
     * may sign off on.
     *
     * @var list<string>
     */
    public const POSTURES = [
        self::POSTURE_OFF,
        self::POSTURE_BOUNDED_PILOT,
        self::POSTURE_GLOBAL_ROLLOUT_READINESS,
        self::POSTURE_GLOBAL,
    ];

    /**
     * How much is enforced, ordered. Used to compare a deployment against the
     * declared ceiling.
     *
     * `global_rollout_readiness` sits at the SAME strength as `bounded_pilot`
     * and not above it, which is the whole point of the posture: measuring the
     * fleet for a widening enforces nobody new. If readiness ever became a
     * stronger rung than the pilot it describes, it would be an activation.
     *
     * @var array<string,int>
     */
    public const POSTURE_STRENGTH = [
        self::POSTURE_OFF => 0,
        self::POSTURE_BOUNDED_PILOT => 1,
        self::POSTURE_GLOBAL_ROLLOUT_READINESS => 1,
        self::POSTURE_GLOBAL => 2,
        self::POSTURE_INDETERMINATE => 2,
    ];

    /**
     * DOCTOR-ACCESS-GLOBAL-ACTIVATION-BLOCKER-CLOSURE-1 (B2) — which governance
     * phase this deployment is being audited AGAINST.
     *
     * THE DEFECT THIS EXISTS TO FIX. Four checks in this scanner are correct
     * for Phase 4A and only for Phase 4A: they assert that fleet-wide
     * enforcement is neither permitted, declared, nor live. A Phase 5 that
     * honestly grants the permission and arms the fleet would therefore make
     * this scanner FAIL on the exact state the programme was built to reach —
     * a gate reddening on its own success teaches operators to ignore it, and
     * an ignored gate protects nothing. That is the same argument
     * DOCTOR-PWA-GLOBAL-ROLLOUT-READINESS-1 already made about
     * `enforcement_inactive`, applied to the four siblings it left behind.
     *
     * WHAT THIS IS NOT. It is not a way to switch a safety check off. In
     * `phase_4a` every affected check behaves byte-identically to before this
     * key existed, so nothing currently green moves. Outside `phase_4a` they
     * report {@see self::STATUS_NOT_APPLICABLE} — visible in the report,
     * counted separately, and never PASS. A check that was not evaluated
     * reporting PASS is precisely the false green this family of gates exists
     * to prevent.
     *
     * WHY IT LIVES IN SOURCE CONTROL. Same reason as `global_permitted` and
     * `expected_posture`: a phase a host could edit would not audit the host
     * values, it would just be a second copy of them agreeing with itself.
     * Moving the programme to a later phase costs a reviewed change.
     *
     * UNRECOGNISED VALUES FAIL TOWARDS THE STRICTEST PHASE. An unreadable or
     * misspelled declaration resolves to `phase_4a`, so a typo tightens the
     * audit rather than silently disabling four checks.
     */
    public const PHASE_4A = 'phase_4a';

    /** Prerequisites are being assembled for a widening that has NOT been applied. */
    public const PHASE_GLOBAL_ACTIVATION_TARGET = 'global_activation_target';

    /** Phase 5 is live: fleet-wide enforcement is the expected, approved state. */
    public const PHASE_GLOBAL_ACTIVATED = 'global_activated';

    /** @var list<string> */
    public const GOVERNANCE_PHASES = [
        self::PHASE_4A,
        self::PHASE_GLOBAL_ACTIVATION_TARGET,
        self::PHASE_GLOBAL_ACTIVATED,
    ];

    /**
     * A check that does not apply to the declared phase.
     *
     * Deliberately a FOURTH token beside PASS/WATCH/FAIL rather than a reuse of
     * PASS. {@see self::scan()} counts it on its own and excludes it from
     * `passed`, so a reader can never mistake "not evaluated" for "evaluated
     * and satisfied".
     */
    public const STATUS_NOT_APPLICABLE = 'NOT_APPLICABLE';

    public function __construct(
        private readonly FeatureFlagService $flags,
        private readonly AndroidDoctorEnforcementScope $scope,
        private readonly string $basePath,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function scan(): array
    {
        $checks = array_merge(
            $this->pilotAuthorityChecks(),
            $this->nonDestructiveModelChecks(),
            $this->deferralChecks(),
            $this->enforcementScopeChecks(),
            $this->boundaryChecks(),
            $this->releaseArtifactChecks(),
            $this->updateAndRollbackChecks(),
            $this->preparationHygieneChecks(),
        );

        $failed = array_values(array_filter($checks, fn (array $c): bool => $c['status'] === 'FAIL'));
        $watch = array_values(array_filter($checks, fn (array $c): bool => $c['status'] === 'WATCH'));

        // NOT_APPLICABLE is neither a pass nor a failure, so it moves the
        // verdict in no direction at all. It is counted on its own below rather
        // than folded into `passed`, because a check that was never evaluated
        // contributing to a pass count is the false green this scanner exists
        // to prevent.
        $notApplicable = array_values(array_filter(
            $checks,
            fn (array $c): bool => $c['status'] === self::STATUS_NOT_APPLICABLE,
        ));

        $status = $failed !== [] ? 'FAIL' : ($watch !== [] ? 'WATCH' : 'GO');
        $boundary = $this->boundary();

        return [
            'status' => $status,
            'checks' => $checks,
            'summary' => [
                'total' => count($checks),
                'passed' => count(array_filter($checks, fn (array $c): bool => $c['status'] === 'PASS')),
                'watch' => count($watch),
                'failed' => count($failed),
                'not_applicable' => count($notApplicable),

                // The phase every scoped check was judged against. Printed so a
                // reader never has to infer why a row says NOT_APPLICABLE.
                'governance_phase' => $this->governancePhase(),

                // Derived from the verdict, never asserted independently. A
                // summary field that can disagree with the checks under it is
                // the false green this whole family of gates exists to prevent.
                'phase4a_pilot_preparation' => $status === 'GO',

                'preparation_state' => (string) config('android_release.phase_4a.preparation.state'),
                'pilot_model' => (string) config('android_release.device_management.pilot_model'),
                'factory_reset_required' => config('android_release.phase_4a.factory_reset_required') === true,
                'device_owner_required' => config('android_release.phase_4a.device_owner_required') === true,
                'full_kiosk_required' => config('android_release.phase_4a.full_kiosk_required') === true,

                'enforcement_scope_mode' => $this->scope->mode(),
                'enforcement_scope_usable' => $this->scope->isUsable(),
                'enforcement_flag_armed' => $this->flags->enabled(DoctorAppLoginGate::ENFORCEMENT_FLAG),

                // Derived, never asserted — same contract as
                // `phase4a_pilot_preparation` above. The declared counterpart is
                // config, so a reader can see both halves of the comparison the
                // `enforcement_posture` check makes.
                'enforcement_posture' => $this->observedPosture(),
                'enforcement_posture_declared' => (string) config('android_release.enforcement.expected_posture'),

                // Measured from the resolved scope. The activation-boundary
                // block below carries a `global_enforcement_active` claim too,
                // but that one is a hardcoded record of what a past sprint did
                // not do; this one asks the running system.
                'global_enforcement_active_live' => $this->globalEnforcementActiveLive(),

                // Every one of these is a thing this sprint did not do.
                'apk_distributed' => $boundary['apk_distributed'] ?? null,
                'apk_installed' => $boundary['apk_installed'] ?? null,
                'tablet_touched' => $boundary['tablet_touched'] ?? null,
                'adb_used' => $boundary['adb_used'] ?? null,
                'device_enrolled' => $boundary['device_enrolled'] ?? null,
                'pilot_activated' => $boundary['pilot_activated'] ?? null,
                'pilot_browser_denial_active' => $boundary['pilot_browser_denial_active'] ?? null,
                'global_enforcement_active' => $boundary['global_enforcement_active'] ?? null,
            ],
        ];
    }

    // -----------------------------------------------------------------------
    // A. Pilot authority
    // -----------------------------------------------------------------------

    /**
     * @return list<array<string,mixed>>
     */
    private function pilotAuthorityChecks(): array
    {
        $doctor = trim((string) config('android_release.enforcement.owner_signoff.pilot_doctor'));
        $branch = trim((string) config('android_release.enforcement.owner_signoff.pilot_branch'));
        $label = trim((string) config('android_release.phase_4a.pilot_device_label'));
        $authorized = config('android_release.enforcement.owner_signoff.phase_4a_pilot_authorized') === true;

        $missing = [];

        foreach (['pilot_doctor' => $doctor, 'pilot_branch' => $branch, 'pilot_device_label' => $label] as $key => $value) {
            if ($value === '') {
                $missing[] = $key;
            }
        }

        if (! $authorized) {
            $missing[] = 'phase_4a_pilot_authorized';
        }

        $checks = [$this->check(
            'pilot_authority_declared',
            $missing === [] ? 'PASS' : 'FAIL',
            $missing === []
                ? "Owner-approved pilot scope is recorded: {$doctor} at {$branch} on {$label}."
                : 'Pilot authority is incomplete: '.implode(', ', $missing).'.',
        )];

        // A serial identifies one physical object and is useless for running a
        // pilot. Committing one would be a hardware identifier nobody asked for.
        $logical = config('android_release.real_device_preflight.device_serial_may_be_committed') === false
            && (string) config('android_release.real_device_preflight.device_reference_style') === 'logical_label';

        $checks[] = $this->check(
            'pilot_device_reference_is_logical_label',
            $logical ? 'PASS' : 'FAIL',
            $logical
                ? 'The pilot device is referenced by logical label; serials stay out of source control.'
                : 'The device reference policy no longer forbids committing a hardware serial.',
        );

        return $checks;
    }

    // -----------------------------------------------------------------------
    // The non-destructive decision
    // -----------------------------------------------------------------------

    /**
     * @return list<array<string,mixed>>
     */
    private function nonDestructiveModelChecks(): array
    {
        $destructive = [];

        foreach ([
            'factory_reset_required',
            'device_owner_required',
            'full_kiosk_required',
            'managed_google_play_required',
        ] as $key) {
            if (config('android_release.phase_4a.'.$key) !== false) {
                $destructive[] = $key;
            }
        }

        $checks = [$this->check(
            'phase_4a_is_non_destructive',
            $destructive === [] ? 'PASS' : 'FAIL',
            $destructive === []
                ? 'Phase 4A requires no factory reset, no Device Owner, no kiosk and no Managed Google Play.'
                : 'Phase 4A has become destructive again: '.implode(', ', $destructive).' is no longer false.',
        )];

        $model = (string) config('android_release.device_management.pilot_model');
        $supported = (array) config('android_release.device_management.supported_models');
        $declared = (string) config('android_release.phase_4a.model');

        // Two ways to get this wrong: point the pilot at a model nobody
        // supports, or let the two places that name a model drift apart.
        $ok = $model !== '' && in_array($model, $supported, true) && $model === $declared;

        $checks[] = $this->check(
            'phase_4a_model_is_supported',
            $ok ? 'PASS' : 'FAIL',
            $ok
                ? "The pilot uses the supported '{$model}' model."
                : "Pilot model '{$model}' is not a supported model, or disagrees with phase_4a.model '{$declared}'.",
        );

        return $checks;
    }

    // -----------------------------------------------------------------------
    // Rule 147 — deferral, not deletion
    // -----------------------------------------------------------------------

    /**
     * @return list<array<string,mixed>>
     */
    private function deferralChecks(): array
    {
        $deferred = (array) config('android_release.phase_4a.deferred_to_dedicated_device_phase');
        $applicable = (array) config('android_release.phase_4a.acceptance_checks');
        $historical = (array) config('android_release.pilot.acceptance_checks');

        // Iterated from the CANONICAL set, not from the deferral list. Walking
        // the deferral list would let an entry be removed from it and disappear
        // from the loop in the same move — the requirement gone, the check
        // green. The canonical set is the authority for what is owed; the
        // deferral list only says where it went.
        $canonical = (array) config('android_release.scanner.dedicated_device_kiosk_checks');
        $problems = [];

        foreach ($canonical as $check) {
            // Absent from the deferral list is a deleted requirement wearing a
            // deferral's clothes.
            if (! in_array($check, $deferred, true)) {
                $problems[] = "{$check} is no longer recorded as deferred";
            }

            // Present in the Phase 4A matrix is a requirement the pilot still
            // cannot meet on a tablet that was not wiped.
            if (in_array($check, $applicable, true)) {
                $problems[] = "{$check} is deferred yet still required by Phase 4A";
            }

            if (! in_array($check, $historical, true)) {
                $problems[] = "{$check} is not in the historical acceptance record";
            }
        }

        if ($canonical === []) {
            $problems[] = 'no canonical kiosk check set is declared, so nothing can be proven deferred';
        }

        $checks = [$this->check(
            'deferred_kiosk_checks_declared',
            $problems === [] ? 'PASS' : 'FAIL',
            $problems === []
                ? count($canonical).' Device Owner and lock-task checks are deferred to a dedicated-device phase, and none of them is silently dropped.'
                : 'Deferral is inconsistent: '.implode('; ', $problems).'.',
        )];

        $controls = array_values(array_filter(
            (array) config('android_release.phase_4a.compensating_controls'),
            fn ($c): bool => is_string($c) && trim($c) !== '',
        ));

        // The two that carry the security weight. Screen lock and USB debugging
        // are hygiene; these two are the reason the boundary still exists.
        $required = ['app_only_boundary_enforced_server_side', 'pilot_enforcement_scope_armed_before_app_only_claimed'];
        $missing = array_values(array_diff($required, $controls));

        $checks[] = $this->check(
            'deferral_has_compensating_controls',
            $controls !== [] && $missing === [] ? 'PASS' : 'FAIL',
            $controls !== [] && $missing === []
                ? count($controls).' compensating controls replace the deferred kiosk properties.'
                : ($controls === []
                    ? 'Kiosk checks are deferred with no compensating control recorded.'
                    : 'Compensating controls are missing: '.implode(', ', $missing).'.'),
        );

        return $checks;
    }

    // -----------------------------------------------------------------------
    // Enforcement scope: available, and inert
    // -----------------------------------------------------------------------

    /**
     * @return list<array<string,mixed>>
     */
    private function enforcementScopeChecks(): array
    {
        $mode = $this->scope->mode();
        $modes = (array) config('android_release.enforcement.scope.modes');
        $known = in_array($mode, $modes, true);

        $checks = [$this->check(
            'enforcement_scope_mechanism_available',
            $known ? 'PASS' : 'FAIL',
            $known
                ? "A pilot-scoped enforcement mechanism exists and is set to '{$mode}'."
                : "Enforcement scope mode '{$mode}' is not one of the declared modes.",
        )];

        // Fleet-wide denial belongs to Phase 5 and has its own prerequisites.
        // During Phase 4A it must not be permitted, whatever the mode says.
        $globalPermitted = $this->scope->globalPermitted();

        // B2: phase-scoped, not weakened. Inside Phase 4A the rule is unchanged
        // — a permission grant is a FAIL even though granting alone enforces
        // nobody, because the grant is the reviewed decision this phase forbids.
        // Outside Phase 4A the grant is the intended state, and a check that
        // reddened on it would be reddening on the programme's own success.
        $checks[] = $this->inPhase4a()
            ? $this->check(
                'global_scope_not_permitted_in_phase_4a',
                $globalPermitted ? 'FAIL' : 'PASS',
                $globalPermitted
                    ? 'Fleet-wide doctor enforcement is permitted. That is a Phase 5 decision and must not ship armed in Phase 4A.'
                    : 'Fleet-wide doctor enforcement is not permitted; only a declared pilot scope can enforce.',
            )
            : $this->notApplicable(
                'global_scope_not_permitted_in_phase_4a',
                'Fleet-wide doctor enforcement is '.($globalPermitted ? 'permitted' : 'not permitted').'.',
            );

        $armed = $this->flags->enabled(DoctorAppLoginGate::ENFORCEMENT_FLAG);
        $configuredOff = config('android_release.enforcement.active') === false
            && config('android_release.enforcement.doctor_browser_login_denied') === false;

        // Two distinct failures, deliberately not collapsed.
        //
        // The first is enforcement live in a preparation sprint. The second is
        // subtler and is the one the scope mechanism creates: the flag armed
        // while the scope covers nobody. That state denies no doctor anything,
        // so it cannot lock a clinic out — but an operator reading only the flag
        // would believe doctors were locked to devices when they are not. A
        // silent no-op in a security control has to be loud somewhere, and this
        // is where.
        // One branch per outcome, in the same order the status is decided, so a
        // row can never carry a message that argues with its own verdict. The
        // narrowing that made a live pilot PASS originally left this chain
        // alone, and the result was a PASS row telling operators to "ship it
        // off" — which is exactly the self-contradicting governance text this
        // sprint set out to remove.
        if ($armed && ! $this->scope->isUsable()) {
            // FAIL. Enforcement that denies nobody, reading as protection.
            $detail = 'The enforcement flag is armed while the scope covers nobody ('
                .implode(', ', $this->scope->invalidReasons()).'). No doctor is enforced; do not read the flag as protection.';
        } elseif (! $configuredOff) {
            // FAIL. Browser denial configured outside a declared scope.
            $detail = 'Doctor browser login is denied outside a declared pilot scope. Enforcement that is not scoped '
                .'to named doctors is a clinic-wide lockout wearing a pilot label.';
        } elseif ($armed) {
            // PASS. The intended state of a live, owner-approved pilot.
            $detail = 'Doctor device enforcement is live for a declared, bounded scope covering '
                .count($this->scope->pilotDoctorUserIds()).' named doctor account(s). That is the intended state of an '
                .'approved pilot, not a failure; `enforcement_posture` checks it against the declaration in source control.';
        } else {
            $detail = 'Doctor device enforcement is off: the flag is not armed and no browser denial is configured.';
        }

        // DOCTOR-PWA-GLOBAL-ROLLOUT-READINESS-1 — the status is deliberately
        // NARROWER than it was, and narrower than the detail above.
        //
        // The original predicate failed on `$armed` alone. That was correct for
        // a preparation sprint, whose whole claim was that it shipped nothing
        // armed. It stopped being correct the moment an owner-approved pilot
        // went live: a correctly configured, bounded, three-doctor pilot made
        // this gate exit non-zero and print NOT READY. A gate that reddens on
        // the outcome the programme was built to reach teaches operators to
        // ignore it, and an ignored gate protects nothing.
        //
        // The genuinely unsafe state is narrower, and it is already computed
        // for the detail immediately above: the flag armed while the scope
        // covers nobody. That denies no doctor anything, so it cannot lock a
        // clinic out, but it reads as protection while providing none. Browser
        // denial configured outside a declared scope stays a failure for
        // exactly the reason it always was.
        //
        // What replaces the dropped breadth is not nothing: `enforcement_posture`
        // below asserts that the enforcement state actually observed is the one
        // a reviewer declared in source control.
        $armedOverNobody = $armed && ! $this->scope->isUsable();

        // B2: this check has two halves with different lifetimes, and they are
        // scoped separately rather than skipped together.
        //
        //   `! $configuredOff` is Phase-4A-exclusive. An honest Phase 5
        //   declares enforcement active, so failing on it after Phase 4A would
        //   redden on the intended state.
        //
        //   `$armedOverNobody` NEVER becomes acceptable. A flag armed over a
        //   scope covering nobody reads as protection while providing none, in
        //   every phase. It stays evaluated, and stays a FAIL.
        //
        // So outside Phase 4A the row reports the surviving half when it is
        // violated, and NOT_APPLICABLE only when the sole remaining reason to
        // fail is the phase-scoped one.
        if ($this->inPhase4a()) {
            $checks[] = $this->check(
                'enforcement_inactive',
                ($armedOverNobody || ! $configuredOff) ? 'FAIL' : 'PASS',
                $detail,
            );
        } elseif ($armedOverNobody) {
            $checks[] = $this->check(
                'enforcement_inactive',
                'FAIL',
                $detail.' The enforcement flag is armed over a scope that covers nobody. That is unsafe in '
                .'every phase, so this half of the check is never skipped.',
            );
        } else {
            $checks[] = $this->notApplicable('enforcement_inactive', $detail);
        }

        $checks[] = $this->postureCheck();
        $checks[] = $this->liveGlobalEnforcementCheck();
        $checks[] = $this->globalPrerequisiteCheck();
        $checks[] = $this->activationTestPrerequisiteCheck();

        return $checks;
    }

    /**
     * Which enforcement posture is this deployment actually in?
     *
     * Derived from what the scope and the flag really say, never from what
     * anyone declared. The declaration is the thing this is compared against.
     */
    /**
     * The governance phase declared in source control.
     *
     * Fails towards the strictest phase: anything unrecognised — a typo, a
     * removed key, a host that somehow injected a value — resolves to
     * {@see self::PHASE_4A}, where every phase-scoped check is fully evaluated.
     * A misdeclaration can therefore only tighten this audit, never disable it.
     */
    public function governancePhase(): string
    {
        $declared = (string) config('android_release.enforcement.governance_phase', self::PHASE_4A);

        return in_array($declared, self::GOVERNANCE_PHASES, true)
            ? $declared
            : self::PHASE_4A;
    }

    /**
     * Are the Phase-4A-exclusive checks in force?
     *
     * The four checks this gates assert that fleet-wide enforcement is not
     * permitted, not declared and not live. Every one of them is correct while
     * the programme is in Phase 4A and contradicts the intended state after it.
     */
    public function inPhase4a(): bool
    {
        return $this->governancePhase() === self::PHASE_4A;
    }

    /**
     * A check that this phase does not evaluate.
     *
     * Never PASS. See {@see self::STATUS_NOT_APPLICABLE}.
     */
    private function notApplicable(string $id, string $detail): array
    {
        return $this->check(
            $id,
            self::STATUS_NOT_APPLICABLE,
            $detail.' Not evaluated: this check is scoped to '.self::PHASE_4A
            .' and the declared governance phase is "'.$this->governancePhase().'".',
        );
    }

    public function observedPosture(): string
    {
        $armed = $this->flags->enabled(DoctorAppLoginGate::ENFORCEMENT_FLAG);

        if ($this->scope->isUnscopedMode() && $this->scope->globalPermitted()) {
            return self::POSTURE_GLOBAL;
        }

        if (! $armed) {
            return self::POSTURE_OFF;
        }

        if ($this->scope->isPilotMode() && $this->scope->isUsable()) {
            return self::POSTURE_BOUNDED_PILOT;
        }

        // Armed, but over nobody, or in a mode whose scope does not resolve.
        // `enforcement_inactive` already fails this; naming it here keeps the
        // posture vocabulary total rather than quietly defaulting to `off`.
        return self::POSTURE_INDETERMINATE;
    }

    /**
     * Does the observed posture match the one a reviewer declared in source?
     *
     * The declaration lives in config/android_release.php and nowhere a host
     * can reach, for the same reason `global_permitted` and
     * `pilot_cohort_maximum` do: a declaration a host can edit is not a
     * declaration, it is a second copy of the value being audited.
     */
    private function postureCheck(): array
    {
        $observed = $this->observedPosture();
        $declared = (string) config('android_release.enforcement.expected_posture');

        if (! in_array($declared, self::POSTURES, true)) {
            return $this->check(
                'enforcement_posture',
                'FAIL',
                'No recognised enforcement posture is declared (found "'.$declared.'"). '
                .'An undeclared posture cannot be contradicted, so it audits nothing.',
            );
        }

        // Phase 5, and only Phase 5, may declare this.
        //
        // B2: the refusal is scoped to Phase 4A rather than removed. Outside it
        // the special case simply stops firing and control falls through to the
        // ceiling comparison below, which already handles `global` correctly
        // without any change: POSTURE_STRENGTH gives it 2, so a deployment
        // observed at `global` against a declared `global` is equal and passes,
        // while one observed at `global` against a quieter declaration is still
        // caught as the widening-nobody-reviewed drift. Nothing about the
        // ceiling is relaxed; it is merely allowed to apply.
        if ($this->inPhase4a() && ($declared === self::POSTURE_GLOBAL || $observed === self::POSTURE_GLOBAL)) {
            return $this->check(
                'enforcement_posture',
                'FAIL',
                'Fleet-wide enforcement is in play (declared "'.$declared.'", observed "'.$observed.'"). '
                .'That is a Phase 5 decision and must not be reachable from this phase.',
            );
        }

        if ($observed === self::POSTURE_INDETERMINATE) {
            return $this->check(
                'enforcement_posture',
                'FAIL',
                'The enforcement flag is armed over a scope that resolves to nobody, so this deployment is in no '
                .'declarable posture at all. Declared "'.$declared.'".',
            );
        }

        // The declaration is a CEILING, not an equality.
        //
        // A deployment quieter than the reviewed intent is safe and ordinary:
        // the same source runs on a developer machine with enforcement off, in
        // CI with no scope at all, and on production with the pilot armed. All
        // three are the same reviewed code, and demanding they report the same
        // posture would either redden CI or force the declaration down to the
        // weakest deployment — which would stop it auditing production.
        //
        // What must never happen is the opposite: a deployment enforcing MORE
        // than anyone reviewed. That is the drift worth failing on, and it is
        // the only direction that can lock a clinic out.
        if (self::POSTURE_STRENGTH[$observed] > self::POSTURE_STRENGTH[$declared]) {
            return $this->check(
                'enforcement_posture',
                'FAIL',
                'This deployment enforces MORE than source control declares: declared "'.$declared.'", '
                .'observed "'.$observed.'". A widening nobody reviewed is exactly the drift this check exists for.',
            );
        }

        if ($observed === $declared) {
            return $this->check(
                'enforcement_posture',
                'PASS',
                'The enforcement posture observed is the one declared in source control: "'.$declared.'".',
            );
        }

        return $this->check(
            'enforcement_posture',
            'PASS',
            'This deployment enforces less than the declared ceiling: declared "'.$declared.'", observed '
            .'"'.$observed.'". Quieter than the reviewed intent is safe; the check exists to catch the reverse.',
        );
    }

    /**
     * Is fleet-wide enforcement live RIGHT NOW?
     *
     * The activation boundary carries a `global_enforcement_active` claim too,
     * but that block is a historical record of what one preparation sprint did
     * not do, and it is a hardcoded false. A safety assertion that is true
     * because somebody typed `false` is not an assertion, and the activation
     * checklist reads this line before arming anything. So this one is
     * measured: it asks the scope.
     */
    private function liveGlobalEnforcementCheck(): array
    {
        $live = $this->globalEnforcementActiveLive();

        // B2: the assertion INVERTS at the last phase rather than switching off.
        //
        // In `phase_4a` and `global_activation_target` the widening has not been
        // approved to apply, so live fleet-wide enforcement is a failure — the
        // rule this check has always carried, unchanged.
        //
        // In `global_activated` the same measurement answers the opposite
        // question: fleet-wide enforcement is the approved state, so its ABSENCE
        // is the anomaly worth reporting. A deployment that declares Phase 5 and
        // then quietly enforces nobody is degraded, and reporting that as a pass
        // would hide an activation that silently failed to take.
        if ($this->governancePhase() === self::PHASE_GLOBAL_ACTIVATED) {
            return $this->check(
                'global_enforcement_active',
                $live ? 'PASS' : 'FAIL',
                $live
                    ? 'Fleet-wide doctor enforcement is live, which is the declared state for this phase. '
                    .'Measured from the resolved scope rather than read from a recorded claim.'
                    : 'This deployment declares fleet-wide enforcement but does not have it: the resolved scope '
                    .'is not unscoped-and-permitted-and-armed. The declared activation is not in force.',
            );
        }

        return $this->check(
            'global_enforcement_not_active',
            $live ? 'FAIL' : 'PASS',
            $live
                ? 'Fleet-wide doctor enforcement is LIVE: the scope is unscoped and global is permitted. '
                .'Every doctor account is enforced, which is a Phase 5 state.'
                : 'Fleet-wide doctor enforcement is not live, measured from the resolved scope rather than '
                .'read from a recorded claim.',
        );
    }

    /**
     * B2 — the Phase-4A prohibition is replaced by a Phase-5 PRECONDITION, not
     * by nothing.
     *
     * `global_prerequisites` has been declared in config since Phase 3.5 and
     * read by no code: five strings with a string-membership test attached.
     * Scoping the prohibition away without putting something in its place would
     * leave the later phases asserting strictly less than Phase 4A did, which
     * is the direction this programme must never move in.
     *
     * So outside Phase 4A every declared prerequisite must carry an explicit
     * recorded attestation. The attestations live in source control beside the
     * list, so recording one costs a reviewed change — the same price as
     * granting `global_permitted`. A prerequisite with no attestation, or one
     * attested anything other than exactly `true`, fails.
     *
     * This asserts that somebody SIGNED for each prerequisite. It cannot and
     * does not measure the world: "a spare device is available at every branch"
     * is a fact about a room, not about a database.
     */
    /**
     * REVISION-DOCTOR-TRUSTED-DEVICE-ESTATE-CAPACITY-POLICY-1 — the ESTATE
     * prerequisite of controlled activation TESTING.
     *
     * WHY THIS IS NOT PHASE-SCOPED AWAY LIKE ITS SIBLING. The check above is
     * NOT_APPLICABLE inside Phase 4A because fleet-wide enforcement is a Phase-5
     * question. Activation TESTING is the opposite: it is the 4A-era activity
     * itself, so a list that went quiet exactly when the testing happens would
     * be read by nobody at the only moment it matters.
     *
     * WHAT IT ASSERTS, AND WHY IT IS INTEGRITY AND NOT SATISFACTION.
     *
     * A first draft of this check asserted that every declared activation-test
     * prerequisite was signed `true`, mirroring its sibling — and running the
     * suite showed why that is wrong. The prerequisite is unmet today and will
     * be until a tablet reaches TLK1, so the check turned this scanner red for
     * MONTHS over a question it is not asking. THIS SCANNER'S SUBJECT IS THE
     * BOUNDED PHASE-4A PILOT, which is live and prepared; whether a LATER rung
     * has enough hardware is a different question, and reddening one because of
     * the other is precisely the conflation the capacity-policy revision
     * exists to end. A gate that is red for months gets deleted rather than
     * fixed.
     *
     * So this asserts the list's INTEGRITY: every declared prerequisite has a
     * signature slot, and every slot holds a boolean. That catches the failure
     * this scanner CAN catch — the list and its signature block drifting apart,
     * which reads identically to "nobody has signed yet" and is not that.
     *
     * WHETHER THE PREREQUISITE IS TRUE has an owner, and it is not this file:
     * `doctor:estate-resilience` measures Level 1, composes it with credential
     * and authorization coverage, and FAILS a gate of its own if a signature
     * here contradicts what the estate holds. That is the surface an activation
     * preflight runs. Two surfaces, one list, neither of them silent — and the
     * list is no longer the declared-but-unread kind the block above it
     * records.
     *
     * NO QUERY, deliberately: this scanner must stay safe to run with no
     * database.
     *
     * An EMPTY list fails. "Nothing declared" is a broken precondition, not a
     * satisfied one — the same reading the sibling takes.
     */
    private function activationTestPrerequisiteCheck(): array
    {
        $declared = (array) config('android_release.enforcement.activation_test_prerequisites', []);
        $attested = (array) config('android_release.enforcement.activation_test_prerequisites_attested', []);

        if ($declared === []) {
            return $this->check(
                'activation_test_prerequisites_declared',
                'FAIL',
                'No activation-testing prerequisite is declared, so nothing can be measured or signed. An '
                .'empty precondition list is not a satisfied one.',
            );
        }

        $drifted = [];

        foreach ($declared as $name) {
            $key = (string) $name;

            if (! array_key_exists($key, $attested) || ! is_bool($attested[$key])) {
                $drifted[] = $key;
            }
        }

        $signed = count(array_filter(
            $declared,
            fn ($name): bool => ($attested[(string) $name] ?? null) === true,
        ));

        return $this->check(
            'activation_test_prerequisites_declared',
            $drifted === [] ? 'PASS' : 'FAIL',
            $drifted === []
                ? 'The activation-testing prerequisite list and its signature block agree: '
                    .count($declared).' declared, each with a recorded boolean ('.$signed.' signed true). '
                    .'This asserts the list is INTACT and is NOT a statement that the prerequisite is '
                    .'satisfied — run doctor:estate-resilience for that, which measures Level 1 and fails '
                    .'if a signature here contradicts the estate.'
                : 'Declared activation-testing prerequisite(s) with no recorded boolean signature slot: '
                    .implode(', ', $drifted).'. The list and the signature block have drifted apart, which '
                    .'reads identically to "nobody has signed yet" and is not that.',
        );
    }

    private function globalPrerequisiteCheck(): array
    {
        if ($this->inPhase4a()) {
            return $this->notApplicable(
                'global_prerequisites_attested',
                'Global activation prerequisites are a Phase 5 precondition.',
            );
        }

        $declared = (array) config('android_release.enforcement.global_prerequisites', []);
        $attested = (array) config('android_release.enforcement.global_prerequisites_attested', []);

        if ($declared === []) {
            return $this->check(
                'global_prerequisites_attested',
                'FAIL',
                'No global activation prerequisites are declared, so nothing can be attested. An empty '
                .'precondition list is not a satisfied one.',
            );
        }

        $missing = array_values(array_filter(
            $declared,
            fn ($name): bool => ($attested[(string) $name] ?? null) !== true,
        ));

        return $this->check(
            'global_prerequisites_attested',
            $missing === [] ? 'PASS' : 'FAIL',
            $missing === []
                ? 'Every declared global activation prerequisite carries a recorded attestation ('
                .count($declared).' of '.count($declared).').'
                : 'Global activation prerequisites are not attested: '.implode(', ', array_map('strval', $missing))
                .'. Each must be recorded true in source control before fleet-wide enforcement is permitted.',
        );
    }

    /**
     * Measured, never asserted. See liveGlobalEnforcementCheck().
     */
    public function globalEnforcementActiveLive(): bool
    {
        return $this->scope->isUnscopedMode()
            && $this->scope->globalPermitted()
            && $this->flags->enabled(DoctorAppLoginGate::ENFORCEMENT_FLAG);
    }

    // -----------------------------------------------------------------------
    // B/O. Preparation is not activation; approval is not distribution
    // -----------------------------------------------------------------------

    /**
     * @return array<string,mixed>
     */
    private function boundary(): array
    {
        return (array) config('android_release.phase_4a.activation_boundary');
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function boundaryChecks(): array
    {
        $boundary = $this->boundary();
        $claimed = [];

        foreach ($boundary as $key => $value) {
            if ($value !== false) {
                $claimed[] = (string) $key;
            }
        }

        $checks = [$this->check(
            'activation_boundary_all_false',
            $boundary !== [] && $claimed === [] ? 'PASS' : 'FAIL',
            $boundary === []
                ? 'No activation boundary is recorded, so nothing asserts that the pilot has not started.'
                : ($claimed === []
                    ? count($boundary).' activation claims are all false: nothing was distributed, installed, enrolled or activated.'
                    : 'Activation is claimed for: '.implode(', ', $claimed).'.'),
        )];

        $state = (string) config('android_release.phase_4a.preparation.state');
        $states = (array) config('android_release.phase_4a.preparation.states');
        $terminal = (string) config('android_release.phase_4a.preparation.terminal_preparation_state');
        $activated = (array) config('android_release.phase_4a.preparation.states_that_imply_activation');

        // An unrecognised state is a FAIL, not a pass. "probably_fine" is
        // exactly how an unset decision passes for a made one.
        $isTerminal = $state !== '' && in_array($state, $states, true) && $state === $terminal;

        $checks[] = $this->check(
            'preparation_state_is_terminal',
            $isTerminal ? 'PASS' : 'FAIL',
            $isTerminal
                ? "Preparation stops at '{$state}', the declared terminal preparation state."
                : "Preparation state '{$state}' is not the declared terminal preparation state '{$terminal}', or is not a known state.",
        );

        // Rule 147 the other way round: relaxing the state must not let a
        // later, activated state read as preparation. An unknown state fails
        // here too — it cannot be proven NOT to imply activation.
        $implies = in_array($state, $activated, true) || ! in_array($state, $states, true);

        $checks[] = $this->check(
            'preparation_state_does_not_imply_activation',
            $implies ? 'FAIL' : 'PASS',
            $implies
                ? "State '{$state}' implies the pilot is running, or is unknown and cannot be proven otherwise."
                : "State '{$state}' is a preparation state; it claims no running pilot.",
        );

        $manifest = $this->manifest();
        $approved = ($manifest['approval_status'] ?? null) === 'approved';
        $manifestDistributed = ($manifest['rollout_state']['apk_distributed'] ?? null) === true;
        $configDistributed = ($boundary['apk_distributed'] ?? null) !== false;

        $checks[] = $this->check(
            'approval_does_not_imply_distribution',
            (! $manifestDistributed && ! $configDistributed) ? 'PASS' : 'FAIL',
            (! $manifestDistributed && ! $configDistributed)
                ? 'The release is '.($approved ? 'approved' : 'not approved').' and still undistributed; approval is not delivery.'
                : 'Distribution is claimed. An approved artifact is not a distributed one.',
        );

        return $checks;
    }

    // -----------------------------------------------------------------------
    // C. The artifact an operator is about to install
    // -----------------------------------------------------------------------

    /**
     * The recorded release manifest, or an empty array when unreadable.
     *
     * @return array<string,mixed>
     */
    private function manifest(): array
    {
        $relative = (string) config('android_release.phase_4a.release_manifest');
        $directory = trim((string) config('android_release.phase_4a.release_manifest_directory'), '/');

        // The declared path must sit exactly one level inside the declared
        // directory, with no traversal segment anywhere. This class reads; it
        // does not resolve arbitrary paths on behalf of a config value.
        if ($directory === ''
            || str_contains($relative, '..')
            || $relative !== $directory.'/'.basename($relative)) {
            return [];
        }

        $path = $this->basePath.'/'.$relative;

        if (! is_file($path)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function releaseArtifactChecks(): array
    {
        $manifest = $this->manifest();
        $checks = [];

        $sha = strtolower(trim((string) ($manifest['artifact_sha256'] ?? '')));
        $size = $manifest['artifact_size_bytes'] ?? null;
        $filename = trim((string) ($manifest['apk_filename'] ?? ''));

        // 64 lowercase hex, and nothing shorter. A truncated digest that still
        // "looks like" a hash is how a prefix comparison gets introduced later.
        $shaOk = preg_match('/^[0-9a-f]{64}$/', $sha) === 1
            && is_int($size) && $size > 0
            && $filename !== '';

        $checks[] = $this->check(
            'release_artifact_recorded',
            $shaOk ? 'PASS' : 'FAIL',
            $shaOk
                ? "The signed artifact is recorded: {$filename}, {$size} bytes, full-length SHA-256."
                : 'The release manifest does not record a filename, a positive size and a full-length SHA-256.',
        );

        $pin = strtolower(trim((string) config('android_release.signing.production_certificate_sha256')));
        $signer = strtolower(trim((string) ($manifest['signer_certificate_sha256'] ?? '')));

        // Exact, full-length, constant-time-shaped equality against the pin in
        // SOURCE CONTROL. Never against the copy in the manifest: whoever can
        // swap the APK can swap the file sitting next to it.
        $pinOk = preg_match('/^[0-9a-f]{64}$/', $pin) === 1
            && preg_match('/^[0-9a-f]{64}$/', $signer) === 1
            && hash_equals($pin, $signer);

        $checks[] = $this->check(
            'release_signer_matches_pin',
            $pinOk ? 'PASS' : 'FAIL',
            $pinOk
                ? 'The recorded signer is exactly the pinned production certificate.'
                : 'The recorded signer is not a full-length exact match for the pinned production certificate.',
        );

        $packageId = trim((string) config('android_release.distribution.package_id'));
        $manifestPackage = trim((string) ($manifest['package_name'] ?? ''));
        $versionName = trim((string) ($manifest['version_name'] ?? ''));
        $versionCode = $manifest['version_code'] ?? null;

        $identityOk = $packageId !== ''
            && $manifestPackage !== ''
            && $packageId === $manifestPackage
            && $versionName !== ''
            && is_int($versionCode) && $versionCode >= 1;

        $checks[] = $this->check(
            'release_package_identity_matches_policy',
            $identityOk ? 'PASS' : 'FAIL',
            $identityOk
                ? "The artifact is {$manifestPackage} {$versionName} (versionCode {$versionCode}), matching the permanent package id."
                : 'The recorded application id, versionName or versionCode does not match the permanent package policy.',
        );

        $steps = (array) config('android_release.phase_4a.preinstall_verification');
        $requiredSteps = (array) config('android_release.phase_4a.preinstall_verification_required');
        $missingSteps = array_values(array_diff($requiredSteps, $steps));

        $checks[] = $this->check(
            'preinstall_verification_declared',
            $missingSteps === [] ? 'PASS' : 'FAIL',
            $missingSteps === []
                ? count($steps).' preinstall verification steps must pass before the artifact goes near the tablet.'
                : 'Preinstall verification is missing: '.implode(', ', $missingSteps).'.',
        );

        return $checks;
    }

    // -----------------------------------------------------------------------
    // M. Update in place, and getting back out
    // -----------------------------------------------------------------------

    /**
     * @return list<array<string,mixed>>
     */
    private function updateAndRollbackChecks(): array
    {
        $contract = [
            'update_contract.signer_mismatch_blocks_update' => true,
            'update_contract.uninstall_destroys_keystore_identity' => true,
            'update_contract.clear_app_data_destroys_keystore_identity' => true,
            'update_contract.in_place_update_preserves_device_identity' => true,
            'update_contract.trust_restored_by_installation_alone' => false,
            'versioning.version_code_decrement_permitted' => false,
            'versioning.version_code_reuse_permitted' => false,
        ];

        $broken = [];

        foreach ($contract as $key => $expected) {
            if (config('android_release.'.$key) !== $expected) {
                $broken[] = $key;
            }
        }

        $checks = [$this->check(
            'update_in_place_contract_recorded',
            $broken === [] ? 'PASS' : 'FAIL',
            $broken === []
                ? 'Update in place requires the same signer and a non-decreasing versionCode; uninstall and clear-data destroy the device identity.'
                : 'The update-in-place contract has drifted: '.implode(', ', $broken).'.',
        )];

        // Uninstall is the cheap reflex that costs a re-enrolment, so the
        // recorded rollback mechanism must not name it as the routine route.
        $mechanism = strtolower((string) config('android_release.versioning.rollback_mechanism'));
        $routineUninstall = str_contains($mechanism, 'uninstall');

        $checks[] = $this->check(
            'rollback_never_routine_uninstall',
            $routineUninstall ? 'FAIL' : 'PASS',
            $routineUninstall
                ? "The recorded rollback mechanism '{$mechanism}' routes through uninstall, which destroys the device identity."
                : 'Rollback is a forward fix; uninstall is not a routine step.',
        );

        $rollback = (array) config('android_release.phase_4a.rollback');
        $cases = [
            'server_side_pilot_problem',
            'device_authorization_problem',
            'bad_apk_behaviour',
            'app_unusable',
            'signer_mismatch',
            'device_identity_lost',
        ];
        $missing = [];

        foreach ($cases as $case) {
            if (trim((string) ($rollback[$case] ?? '')) === '') {
                $missing[] = $case;
            }
        }

        $checks[] = $this->check(
            'rollback_matrix_complete',
            $missing === [] ? 'PASS' : 'FAIL',
            $missing === []
                ? count($cases).' failure modes each have a recorded rollback route, decided before activation.'
                : 'No rollback route is recorded for: '.implode(', ', $missing).'.',
        );

        return $checks;
    }

    // -----------------------------------------------------------------------
    // D. What preparation is allowed to require
    // -----------------------------------------------------------------------

    /**
     * @return list<array<string,mixed>>
     */
    private function preparationHygieneChecks(): array
    {
        $requires = [];

        foreach (['requires_signing_key_access', 'requires_device_access', 'requires_adb'] as $key) {
            if (config('android_release.phase_4a.preparation.'.$key) !== false) {
                $requires[] = $key;
            }
        }

        $checks = [$this->check(
            'preparation_needs_no_key_device_or_adb',
            $requires === [] ? 'PASS' : 'FAIL',
            $requires === []
                ? 'Preparation needs no signing key, no tablet and no adb; it works from public release evidence only.'
                : 'Preparation claims to require: '.implode(', ', $requires).'.',
        )];

        $checklist = trim((string) config('android_release.phase_4a.operator_checklist'));
        $exists = $checklist !== '' && is_file($this->basePath.'/'.$checklist);
        $registered = in_array($checklist, (array) config('android_release.scanner.required_documents'), true);

        $checks[] = $this->check(
            'operator_checklist_present',
            ($exists && $registered) ? 'PASS' : 'FAIL',
            ($exists && $registered)
                ? "The activation operator checklist is present at {$checklist} and is a required document."
                : ($exists
                    ? "Checklist {$checklist} exists but is not a required document, so it can go missing silently."
                    : "The activation operator checklist '{$checklist}' does not exist."),
        );

        $events = array_values(array_filter(
            (array) config('android_release.phase_4a.audit_events_required'),
            fn ($e): bool => is_string($e) && trim($e) !== '',
        ));

        // Which events are mandatory is a rule, so it lives in config.
        $requiredEvents = (array) config('android_release.phase_4a.audit_events_mandatory');
        $missingEvents = array_values(array_diff($requiredEvents, $events));

        $checks[] = $this->check(
            'audit_requirements_declared',
            $missingEvents === [] ? 'PASS' : 'FAIL',
            $missingEvents === []
                ? count($events).' audit events are required of the activation sprint.'
                : 'Required audit events are not declared: '.implode(', ', $missingEvents).'.',
        );

        return $checks;
    }

    /**
     * @return array<string,mixed>
     */
    private function check(string $id, string $status, string $detail): array
    {
        return ['id' => $id, 'status' => $status, 'detail' => $detail];
    }
}
