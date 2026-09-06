<?php

namespace App\Support\Android;

/**
 * PHASE4A-DOCTOR-ANDROID-PILOT-PREPARATION-1 — who doctor device enforcement
 * applies to.
 *
 * `doctor.trusted_device_enforcement` is a single boolean, and its own registry
 * entry says what turning it on does: it "DENIES browser login for every
 * account holding the Doctor role". Before this class the only two reachable
 * states were therefore enforcement for nobody, or enforcement for the entire
 * fleet — and `enforcement.stages` has listed `pilot_branch_or_device` as a
 * distinct stage since Phase 3.5 with nothing implementing it.
 *
 * That mattered more once Phase 4A became non-destructive. Lock task was what
 * physically kept a doctor away from a browser; without it the app-only
 * property rests entirely on this server-side decision. A pilot that cannot be
 * scoped is not a pilot.
 *
 * THE DIRECTION THIS FAILS IN, AND WHY
 *
 * This class only ever NARROWS. Every unusable, contradictory or unrecognised
 * configuration covers NOBODY rather than everybody:
 *
 *   - pilot mode with no target doctor      -> nobody
 *   - fleet-wide mode without explicit permission -> nobody
 *   - an unrecognised mode                  -> nobody
 *
 * That is deliberate, and it is the opposite of the usual deny-by-default
 * reflex, so it is worth being explicit about. "Deny" here does not withhold
 * data from an attacker — every RBAC, branch, room and consent gate is still in
 * force either way, and a doctor still needs their password. What "deny"
 * withholds is a doctor's ability to reach their patients at all. A mistyped
 * environment variable that locked every doctor out of every branch would be a
 * clinical incident; the same mistake resolving to "enforce nobody" leaves
 * production exactly as it is today, grants no new access, and is caught by
 * `android:phase4a-pilot-readiness`, which FAILS when the flag is armed while
 * the scope covers nobody. The loud failure is the compensating control for the
 * quiet direction.
 *
 * WHERE THE INPUTS COME FROM
 *
 * Split across two files on purpose. The mode and the pilot doctor are host
 * values in config/doctor_device_enforcement.php, because a user id is not
 * portable between this repository and production. Fleet-wide permission is a
 * source-controlled false in config/android_release.php, because its blast
 * radius is every doctor in every branch and it should cost a review.
 *
 * IDENTIFIERS COME FROM THE ENVIRONMENT, NOT FROM SOURCE CONTROL
 *
 * The pilot target is a user id, and user ids are not portable between this
 * repository and production — a committed id would point at whoever happens to
 * hold it locally. So the committed default is null, the real value is set on
 * the host at activation time, and a null target means this class covers
 * nobody.
 */
final class AndroidDoctorEnforcementScope
{
    /** Enforcement applies to exactly one declared doctor. */
    public const MODE_PILOT = 'pilot';

    /** Enforcement applies to every Doctor-role account. Phase 5 only. */
    public const MODE_UNSCOPED = 'unscoped';

    public const REASON_UNKNOWN_MODE = 'unknown_scope_mode';

    public const REASON_PILOT_WITHOUT_DOCTOR = 'pilot_mode_without_doctor_user_id';

    public const REASON_GLOBAL_NOT_PERMITTED = 'global_scope_not_permitted';

    /**
     * The declared mode, lowercased and trimmed. Returned verbatim even when
     * unrecognised: a reader needs to see what was actually configured, and
     * silently normalising an unknown value to a known one is how a typo
     * becomes a policy.
     */
    public function mode(): string
    {
        $mode = strtolower(trim((string) config('doctor_device_enforcement.scope.mode')));

        // An empty runtime value means "this deployment declared nothing", which
        // is answered by the policy default rather than by an empty string that
        // would fall through to `unknown_scope_mode`.
        if ($mode === '') {
            $mode = strtolower(trim((string) config('android_release.enforcement.scope.default_mode')));
        }

        return $mode;
    }

    public function isPilotMode(): bool
    {
        return $this->mode() === self::MODE_PILOT;
    }

    public function isUnscopedMode(): bool
    {
        return $this->mode() === self::MODE_UNSCOPED;
    }

    /**
     * The pilot target, or null when there is not exactly one usable positive
     * integer there. `'abc'`, `''`, `0`, `-1` and a float are all null: an id
     * that cannot be compared is not a narrower scope, it is an absent one.
     */
    public function pilotDoctorUserId(): ?int
    {
        $raw = config('doctor_device_enforcement.scope.pilot.doctor_user_id');

        if (is_int($raw)) {
            return $raw > 0 ? $raw : null;
        }

        if (is_string($raw) && ctype_digit($raw) && (int) $raw > 0) {
            return (int) $raw;
        }

        return null;
    }

    /** Advisory only. The authority for a doctor's branch is BranchContext. */
    public function pilotBranchCode(): ?string
    {
        $code = strtoupper(trim((string) config('doctor_device_enforcement.scope.pilot.branch_code')));

        return $code === '' ? null : $code;
    }

    /**
     * Is fleet-wide denial permitted at all?
     *
     * Read from the governance record, which does not read the environment. So
     * a fleet-wide clinical lockout is not reachable by setting a variable on a
     * host — it takes a reviewed source-control change. That asymmetry is the
     * point: the pilot target is a host value because it is not portable, and
     * fleet-wide permission is not, because its blast radius is every doctor.
     */
    public function globalPermitted(): bool
    {
        return config('android_release.enforcement.scope.global_permitted') === true;
    }

    /**
     * Could this configuration enforce anything at all?
     *
     * Separate from `coversUser()` so the readiness gate can say "armed but
     * covering nobody" — the state a silent no-op hides in.
     */
    public function isUsable(): bool
    {
        return match ($this->mode()) {
            self::MODE_PILOT => $this->pilotDoctorUserId() !== null,
            self::MODE_UNSCOPED => $this->globalPermitted(),
            default => false,
        };
    }

    /**
     * Why this configuration enforces nothing. Empty when it is usable.
     *
     * @return list<string>
     */
    public function invalidReasons(): array
    {
        return match ($this->mode()) {
            self::MODE_PILOT => $this->pilotDoctorUserId() === null ? [self::REASON_PILOT_WITHOUT_DOCTOR] : [],
            self::MODE_UNSCOPED => $this->globalPermitted() ? [] : [self::REASON_GLOBAL_NOT_PERMITTED],
            default => [self::REASON_UNKNOWN_MODE],
        };
    }

    /**
     * Is this user inside the enforced scope?
     *
     * Takes an id rather than a User so the browser path keeps the property its
     * own docblock promises: it decides without touching the database.
     */
    public function coversUser(int $userId): bool
    {
        if (! $this->isUsable()) {
            return false;
        }

        return match ($this->mode()) {
            self::MODE_PILOT => $userId === $this->pilotDoctorUserId(),
            self::MODE_UNSCOPED => true,

            // Unreachable, and deliberately kept.
            //
            // `isUsable()` above already returns false for any mode that is not
            // one of the two known ones, so no input reaches this arm — a
            // mutation campaign confirmed it by flipping this to `true` with
            // every test still passing. It stays because `match` without a
            // default throws on an unknown subject, and a thrown
            // UnhandledMatchError inside a login path is a worse outcome than a
            // redundant `false`. Do not "clean this up", and do not spend
            // another campaign trying to kill it.
            default => false,
        };
    }
}
