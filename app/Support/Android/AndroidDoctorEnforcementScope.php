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
 *   - a cohort with one unreadable id       -> nobody
 *   - a cohort larger than review permits   -> nobody
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
 *
 * WHAT A COHORT DOES NOT CHANGE
 *
 * DOCTOR-PWA-MULTI-DOCTOR-PILOT-1 made the pilot a list. It deliberately did
 * NOT make the list a shortcut to Phase 5. Two properties hold it short of one:
 * `global_permitted` is still a source-controlled false that no host variable
 * reaches, and `pilot_cohort_maximum` caps how many ids the word "pilot" may
 * name — because an explicit list long enough to name every doctor is
 * fleet-wide denial wearing a pilot's label, and it would pass every guard
 * written to watch for fleet-wide denial. Widening either one costs a review.
 *
 * Being in the cohort is also not admission. It decides only that enforcement
 * APPLIES to a doctor; whether they can then get in still needs an active
 * device, an active authorization and a usable device-bound credential, each
 * re-asserted on every protected request by services this class never calls.
 */
final class AndroidDoctorEnforcementScope
{
    /**
     * Enforcement applies to the explicitly declared cohort and nobody else.
     *
     * DOCTOR-PWA-MULTI-DOCTOR-PILOT-1 widened this from exactly one doctor to
     * an explicit list bounded by a source-controlled maximum. The mode name
     * is unchanged because the guarantee is unchanged: covered means named.
     */
    public const MODE_PILOT = 'pilot';

    /** Enforcement applies to every Doctor-role account. Phase 5 only. */
    public const MODE_UNSCOPED = 'unscoped';

    public const REASON_UNKNOWN_MODE = 'unknown_scope_mode';

    public const REASON_PILOT_WITHOUT_DOCTOR = 'pilot_mode_without_doctor_user_id';

    public const REASON_GLOBAL_NOT_PERMITTED = 'global_scope_not_permitted';

    /** DOCTOR-PWA-MULTI-DOCTOR-PILOT-1. One unusable entry voids the cohort. */
    public const REASON_PILOT_COHORT_MALFORMED = 'pilot_cohort_contains_unusable_entry';

    /** DOCTOR-PWA-MULTI-DOCTOR-PILOT-1. A pilot may not be widened on a host. */
    public const REASON_PILOT_COHORT_EXCEEDS_MAXIMUM = 'pilot_cohort_exceeds_reviewed_maximum';

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
     * The pilot cohort: every doctor this deployment enforces, ascending.
     *
     * DOCTOR-PWA-MULTI-DOCTOR-PILOT-1. Until this sprint the pilot was one
     * `?int` compared with `===`, so "a pilot" and "one doctor" were the same
     * statement and a second doctor could only be added by going fleet-wide.
     * The cohort separates them: the pilot is now an explicit LIST, and the
     * list is the only expansion shape there is. No wildcard, no role, no
     * branch, no "all doctors" — an id is covered because somebody wrote that
     * id down.
     *
     * Empty whenever the configuration is not usable, which keeps the
     * direction this class has always failed in: every contradictory or
     * unreadable scope covers NOBODY rather than everybody.
     *
     * @return list<int>
     */
    public function pilotDoctorUserIds(): array
    {
        return $this->parsePilotCohort()['ids'];
    }

    /**
     * The pilot target when the cohort names exactly one doctor, else null.
     *
     * Kept, and kept meaning what it used to mean, because callers that print
     * "the declared pilot doctor" are only truthful when there is one of them.
     * A three-doctor cohort has no single target, and inventing one — the first
     * id, the lowest — would put a real doctor's id in a field that claims to
     * name the whole scope. Callers that need the cohort ask for the cohort.
     */
    public function pilotDoctorUserId(): ?int
    {
        $ids = $this->pilotDoctorUserIds();

        return count($ids) === 1 ? $ids[0] : null;
    }

    /**
     * The largest cohort the word "pilot" is permitted to mean here.
     *
     * Read from the governance record, not the runtime file, for the same
     * reason `globalPermitted()` is: a ceiling an operator can raise on a host
     * is not a ceiling. Without it an explicit list is still a route to
     * fleet-wide denial — write every doctor's id down and enforcement is
     * fleet-wide while `global_enforcement_active` still reports false.
     *
     * Defaults to 1 rather than to "unlimited" when the policy value is
     * missing or unreadable. A ceiling that fails open is not one.
     */
    public function pilotCohortMaximum(): int
    {
        $max = config('android_release.enforcement.scope.pilot_cohort_maximum');

        return is_int($max) && $max > 0 ? $max : 1;
    }

    /**
     * Resolve both declared sources into one cohort.
     *
     * WHY ONE BAD ENTRY VOIDS THE WHOLE LIST
     *
     * Dropping it would be the friendlier choice and the wrong one. `18,19,2O`
     * with a letter O resolves, under "drop the bad entry", to a working
     * two-doctor pilot — and the doctor whose id was mistyped is silently
     * unenforced behind a list that still reads correctly. Voiding the cohort
     * instead covers nobody, which trips `armed_but_covers_nobody` in the
     * readiness report. Loud beats quiet, and this class already accepts a
     * loud failure as the price of never over-covering.
     *
     * @return array{ids: list<int>, declared: bool, malformed: bool, exceeded: bool}
     */
    private function parsePilotCohort(): array
    {
        $declared = false;
        $malformed = false;

        /** @var list<int> $ids */
        $ids = [];

        // The singular key, unchanged. Strict: no surrounding whitespace is
        // tolerated, because on a scalar host variable padding is a mistake
        // rather than syntax, and ' 4242' has always resolved to no target.
        $single = config('doctor_device_enforcement.scope.pilot.doctor_user_id');

        if ($single !== null && $single !== '') {
            $declared = true;
            $id = $this->normalizeUserId($single, false);

            if ($id === null) {
                $malformed = true;
            } else {
                $ids[] = $id;
            }
        }

        // The cohort key. Here whitespace around an entry IS syntax: a comma
        // separated list is normally written `18, 19`, and rejecting the space
        // after a separator would be hostile rather than strict.
        foreach ($this->declaredCohortEntries($declared) as $entry) {
            $id = $this->normalizeUserId($entry, true);

            if ($id === null) {
                $malformed = true;

                continue;
            }

            $ids[] = $id;
        }

        if ($malformed) {
            return ['ids' => [], 'declared' => true, 'malformed' => true, 'exceeded' => false];
        }

        $ids = array_values(array_unique($ids));
        sort($ids);

        // Duplicates are normalised away above rather than counted, so
        // `18,18,18` is a one-doctor pilot and not a three-doctor one.
        if (count($ids) > $this->pilotCohortMaximum()) {
            return ['ids' => [], 'declared' => $declared, 'malformed' => false, 'exceeded' => true];
        }

        return ['ids' => $ids, 'declared' => $declared, 'malformed' => false, 'exceeded' => false];
    }

    /**
     * The raw entries of the cohort key, and whether anything was declared.
     *
     * @return list<mixed>
     */
    private function declaredCohortEntries(bool &$declared): array
    {
        $raw = config('doctor_device_enforcement.scope.pilot.doctor_user_ids');

        if (is_string($raw)) {
            if (trim($raw) === '') {
                return [];
            }

            $declared = true;

            return explode(',', $raw);
        }

        if (is_array($raw)) {
            if ($raw === []) {
                return [];
            }

            $declared = true;

            return array_values($raw);
        }

        if ($raw === null) {
            return [];
        }

        // An int, float or bool sitting in a key documented as a list. It was
        // declared, and it is not a list, so it is malformed rather than
        // quietly coerced into a single-entry cohort.
        $declared = true;

        return [$raw];
    }

    /**
     * One entry to a usable id, or null.
     *
     * A float, a bool and an array are null however they would cast: an id
     * that cannot be compared is not a narrower scope, it is an absent one.
     */
    private function normalizeUserId(mixed $raw, bool $separatorWhitespaceIsSyntax): ?int
    {
        if (is_int($raw)) {
            return $raw > 0 ? $raw : null;
        }

        if (! is_string($raw)) {
            return null;
        }

        $value = $separatorWhitespaceIsSyntax ? trim($raw) : $raw;

        return ctype_digit($value) && (int) $value > 0 ? (int) $value : null;
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
            self::MODE_PILOT => $this->pilotDoctorUserIds() !== [],
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
            self::MODE_PILOT => $this->pilotInvalidReasons(),
            self::MODE_UNSCOPED => $this->globalPermitted() ? [] : [self::REASON_GLOBAL_NOT_PERMITTED],
            default => [self::REASON_UNKNOWN_MODE],
        };
    }

    /**
     * Why the pilot cohort is empty. Empty when it is not.
     *
     * The three causes are reported separately because they call for different
     * actions: nothing was declared, something declared cannot be read, or the
     * cohort is larger than review permits. Collapsing them into "no doctor"
     * would send an operator looking for a missing variable when the real
     * answer is a typo, or a ceiling.
     *
     * @return list<string>
     */
    private function pilotInvalidReasons(): array
    {
        $cohort = $this->parsePilotCohort();

        if ($cohort['ids'] !== []) {
            return [];
        }

        if ($cohort['malformed']) {
            return [self::REASON_PILOT_COHORT_MALFORMED];
        }

        if ($cohort['exceeded']) {
            return [self::REASON_PILOT_COHORT_EXCEEDS_MAXIMUM];
        }

        return [self::REASON_PILOT_WITHOUT_DOCTOR];
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
            // Strict membership.
            //
            // An equivalent mutant, and recorded as one rather than left for
            // the next campaign to rediscover: dropping the third argument
            // changes no test, because `$userId` is a typed int and
            // `pilotDoctorUserIds()` returns only ints, and `==` and `===`
            // cannot disagree over two integers.
            //
            // It stays because the equivalence is a property of the LIST, not
            // of this line. `parsePilotCohort()` is what guarantees the
            // elements are ints; the day something puts a numeric string in
            // there, strictness is load-bearing again and this argument is
            // already in place. The guarantee itself is pinned by
            // MultiDoctorPilotCohortTest, so it cannot erode silently.
            self::MODE_PILOT => in_array($userId, $this->pilotDoctorUserIds(), true),
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
