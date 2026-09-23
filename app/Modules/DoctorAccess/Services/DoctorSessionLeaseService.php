<?php

declare(strict_types=1);

namespace App\Modules\DoctorAccess\Services;

use App\Models\User;
use App\Modules\Doctor\Services\DoctorIdentityResolver;
use App\Modules\DoctorAccess\Interfaces\DoctorSessionLeaseRepositoryInterface;
use App\Modules\DoctorAccess\Models\DoctorSessionLease;
use App\Modules\DoctorAccess\Support\DoctorSessionLeaseVerdict;
use App\Modules\DoctorAccess\Support\IncumbentSessionProbe;
use App\Modules\LabOrder\Services\AuditLogService;
use App\Services\Foundation\FeatureFlagService;
use App\Support\Clinical\ClinicalClock;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * DOCTOR-ACCESS-SINGLE-SESSION-BRANCH-LOCK-1 — the claim, deny, renew and
 * release engine. Every lease decision lives here and nowhere else, so a
 * console command, a listener, a middleware and an approver surface cannot
 * disagree about what "one active session" means.
 *
 * REFUSED, NOT EVICTED. The second login is denied; the first is untouched.
 * That single sentence constrains almost everything below:
 *
 *  - THERE IS NO IDLE RECLAIM. `last_seen_at` is diagnostics and operator
 *    display. A lease becomes reclaimable when the incumbent's `sessions` row
 *    is GONE — dead — never when it is merely quiet. Reclaiming on a timer is
 *    eviction through the back door and inverts the requirement.
 *  - THE DENY PATH NEVER CALLS Auth::guard('web')->logout(). That cycles the
 *    SHARED users.remember_token, so refusing login number two would partially
 *    break session number one. logoutCurrentDevice() does not cycle it. This is
 *    also why the device module's invalidate() is not reusable here.
 *
 * THE ENGINE DISARMS ON A DRIVER IT CANNOT OBSERVE. Liveness is read from the
 * server-side `sessions` table. On any other driver that table is empty, every
 * incumbent would read DEAD, and the rule would silently degrade to
 * newest-login-wins — a fail-OPEN that looks green in every test. So
 * {@see self::enabled()} is the flag AND the probe, and it is deliberately NOT
 * "assume the incumbent is alive", which would deny every login instead.
 *
 * A SESSION THAT CARRIES NO LEASE TOKEN IS NEVER TOUCHED. The lease is claimed
 * on one event — Illuminate\Auth\Events\Login — and every other entry point
 * passes through. That is what keeps this capability implementable: the test
 * suite authenticates through setUser(), which fires a different event, so
 * thousands of existing sessions hold no token and must keep working. Nothing
 * in this class may ever create a lease for a session that did not claim one.
 */
class DoctorSessionLeaseService
{
    public const FLAG = 'doctor.single_active_session';

    /**
     * The session-data key holding the lease token.
     *
     * Deliberately NOT under a device prefix: it can never collide with the
     * device session keys and can never be mistaken for a device binding.
     */
    public const SESSION_LEASE_TOKEN = 'doctor_access.session_lease_token';

    /** A live session for this account already holds the lease. */
    public const DENY_ACTIVE_SESSION_ELSEWHERE = 'active_session_elsewhere';

    /** Two logins raced for a free lease and this one lost the insert. */
    public const DENY_LOST_CLAIM_RACE = 'lost_claim_race';

    /** The session carries a token whose lease has been released. */
    public const DENY_LEASE_MISSING = 'lease_missing';

    /** The token resolves to a lease belonging to a different account. */
    public const DENY_LEASE_MISMATCH = 'lease_user_mismatch';

    /** A cover started, a cover ended, or a transfer landed mid-session. */
    public const DENY_BRANCH_CONTEXT_CHANGED = 'branch_context_changed';

    /**
     * LEASE EVENTS GET THEIR OWN AUDIT ACTIONS.
     *
     * A lease eviction is not a device invalidation, and reusing the device
     * action would make the trail unreadable at exactly the moment somebody is
     * trying to explain to a doctor why they were logged out.
     */
    public const ACTION_CLAIMED = 'DOCTOR_SESSION_LEASE_CLAIMED';

    public const ACTION_RECLAIMED = 'DOCTOR_SESSION_LEASE_RECLAIMED';

    public const ACTION_DENIED = 'DOCTOR_SESSION_LEASE_DENIED';

    public const ACTION_RELEASED = 'DOCTOR_SESSION_LEASE_RELEASED';

    public const ACTION_EVICTED = 'DOCTOR_SESSION_LEASE_EVICTED';

    public const ACTION_REVOKED = 'DOCTOR_SESSION_LEASE_REVOKED';

    /** Audit entity for anything that has a lease row. */
    public const AUDIT_ENTITY_LEASES = 'trx_doctor_session_leases';

    /** Audit entity for a refusal that created no row. */
    public const AUDIT_ENTITY_USERS = 'users';

    /**
     * How stale `last_seen_at` may get before a request refreshes it.
     *
     * A plain interval and NOT a cache lock: the cache store is `array` in the
     * suite and in CI, so a cache throttle would be per-request under test and
     * therefore untestable.
     */
    private const RENEW_INTERVAL_MINUTES = 5;

    public function __construct(
        private readonly DoctorSessionLeaseRepositoryInterface $leases,
        private readonly IncumbentSessionProbe $probe,
        private readonly FeatureFlagService $flags,
        private readonly AuditLogService $auditLogs,
        private readonly DoctorIdentityResolver $identities,
        private readonly ClinicalClock $clock,
    ) {}

    /**
     * Is the single-active-session rule armed on this deployment?
     *
     * BOTH CONDITIONS, AND THE SECOND ONE IS NOT DECORATION. See the class
     * note: an unobservable session driver makes every incumbent read DEAD, so
     * the honest failure is to disarm rather than to enforce a rule the
     * deployment cannot actually evaluate.
     *
     * Read through FeatureFlagService and NEVER through the config helper with
     * a dotted flag key: a flag key contains dots, so a dotted config lookup
     * addresses a nested structure the registry never writes, answers null, and
     * reports an armed flag as off.
     */
    public function enabled(): bool
    {
        return $this->flags->enabled(self::FLAG) && $this->probe->observable();
    }

    /**
     * Does the rule apply to this account?
     *
     * Role, not permission, and declared HERE rather than delegated to the
     * device gate: single-session enforcement must work while device
     * enforcement stays off, and entangling the two would make one impossible
     * to arm without the other.
     */
    public function subjectTo(User $user): bool
    {
        return $user->hasRole('Doctor');
    }

    /**
     * ADVISORY: could this account claim a lease right now?
     *
     * Used to pre-check BEFORE a one-time credential is spent, so a refusal
     * does not also burn the credential and force a doctor to authenticate from
     * scratch on a tablet.
     *
     * It is deliberately unlocked and therefore racy, and it is NOT the
     * decision: {@see self::claimOrDeny()} re-evaluates the same question under
     * a row lock and is the only authority. A pre-check that answered "yes" and
     * then lost the race simply produces the ordinary denial one step later.
     */
    public function availableFor(User $user): bool
    {
        if (! $this->enabled() || ! $this->subjectTo($user)) {
            return true;
        }

        $incumbent = $this->leases->activeForUser((int) $user->id);

        if ($incumbent === null) {
            return true;
        }

        return ! $this->probe->isLive($incumbent, null);
    }

    /**
     * Claim the lease for the session in front of us, or refuse the login.
     *
     * THE TRANSACTION SHAPE IS COPIED FROM DailyBranchContextService, WITH ONE
     * DELIBERATE DIVERGENCE.
     *
     * Copied: an outer transaction; the racing INSERT inside a NESTED
     * transaction so the framework emits a SAVEPOINT; the unique violation
     * caught OUTSIDE that nested transaction, after the rollback to savepoint
     * has run; and only THEN a re-read of the incumbent under the lock. Reading
     * the incumbent from inside the catch instead would work on SQLite and fail
     * on PostgreSQL with 25P02, because a failed statement there aborts the
     * whole transaction until something rolls back.
     *
     * Diverged: the exemplar throws its refusal inside the transaction. This
     * one returns a verdict and acts after the commit, because a denial has two
     * consequences that a rollback must not undo — the audit row that records
     * the refusal, and the session teardown, which issues a DELETE on
     * `sessions` through this same connection.
     *
     * @throws ValidationException when the login is refused.
     */
    public function claimOrDeny(
        User $user,
        Request $request,
        ?int $effectiveBranchId = null,
        ?int $effectiveCoverId = null,
    ): void {
        if (! $request->hasSession()) {
            return;
        }

        $userId = (int) $user->id;
        $token = bin2hex(random_bytes(32));
        $sessionId = $request->session()->getId();

        // The doctor record is audit context, recorded at claim time because
        // the link can be broken later and the lease must still say who this
        // was. It is NEVER the key: a Doctor-role account with no linked record
        // binds nobody, and keying on it would silently exempt exactly those
        // accounts from the rule.
        $doctorId = $this->identities->resolveForUser($user)?->id;

        $attributes = [
            'user_id' => $userId,
            'doctor_id' => $doctorId === null ? null : (int) $doctorId,
            'session_id' => $sessionId,
            // The plaintext lives in session data and nowhere else. Only its
            // digest is persisted, so the table is not a bearer-credential
            // store.
            'session_token_hash' => hash('sha256', $token),
            'effective_branch_id' => $effectiveBranchId,
            'effective_cover_id' => $effectiveCoverId,
            'claimed_at' => now(),
            'last_seen_at' => now(),
        ];

        $verdict = DB::transaction(function () use ($userId, $attributes, $sessionId): DoctorSessionLeaseVerdict {
            $incumbent = $this->leases->lockActiveForUser($userId);

            if ($incumbent === null) {
                try {
                    // NESTED -> SAVEPOINT. See the method note.
                    $lease = DB::transaction(
                        fn (): DoctorSessionLease => $this->leases->create($attributes)
                    );

                    return DoctorSessionLeaseVerdict::granted($lease);
                } catch (QueryException $exception) {
                    // CAUGHT OUTSIDE THE NESTED TRANSACTION, so the rollback to
                    // savepoint has already run and this connection is usable.
                    if (! $this->isUniqueViolation($exception)) {
                        throw $exception;
                    }

                    // Lost the race for a free lease. Whoever committed first is
                    // now the authority; fall through and be judged by them.
                    $incumbent = $this->leases->lockActiveForUser($userId);

                    if ($incumbent === null) {
                        // A unique violation with no row behind it is not the
                        // race this handler exists for.
                        throw $exception;
                    }
                }
            }

            if (! $this->probe->isLive($incumbent, $sessionId)) {
                // DEAD, NOT IDLE. The incumbent's session no longer exists, so
                // nobody is evicted by taking the lease back.
                $this->leases->release($incumbent, DoctorSessionLease::RELEASE_DEAD_SESSION_RECLAIMED);

                try {
                    $lease = DB::transaction(
                        fn (): DoctorSessionLease => $this->leases->create($attributes)
                    );

                    return DoctorSessionLeaseVerdict::reclaimed($lease, $incumbent);
                } catch (QueryException $exception) {
                    if (! $this->isUniqueViolation($exception)) {
                        throw $exception;
                    }

                    // Another login reclaimed the same dead lease first.
                    return DoctorSessionLeaseVerdict::denied(self::DENY_LOST_CLAIM_RACE, null);
                }
            }

            return DoctorSessionLeaseVerdict::denied(self::DENY_ACTIVE_SESSION_ELSEWHERE, $incumbent);
        });

        if ($verdict->isDenied()) {
            $reason = (string) $verdict->reason();

            // Audited BEFORE the teardown and OUTSIDE the transaction, so the
            // refusal survives whatever happens to the session next.
            $this->auditLogs->log(
                self::AUDIT_ENTITY_USERS,
                $userId,
                self::ACTION_DENIED,
                null,
                $verdict->toAuditArray() + ['effective_branch_id' => $effectiveBranchId],
                $user,
            );

            $message = $this->denialMessage($reason, $verdict->incumbent());

            $this->tearDown($request);

            throw ValidationException::withMessages(['email' => $message]);
        }

        /** @var DoctorSessionLease $lease */
        $lease = $verdict->lease();

        // The token lands in session DATA, which survives the framework's id
        // regeneration and dies with invalidate() — exactly the distinction a
        // lease needs.
        $request->session()->put(self::SESSION_LEASE_TOKEN, $token);

        $this->auditLogs->log(
            self::AUDIT_ENTITY_LEASES,
            (int) $lease->id,
            $verdict->isReclaim() ? self::ACTION_RECLAIMED : self::ACTION_CLAIMED,
            null,
            $verdict->toAuditArray() + ['effective_branch_id' => $effectiveBranchId],
            $user,
        );
    }

    /**
     * The lease this request holds, or null when it holds none.
     *
     * Null is the ORDINARY answer for a session that never claimed one, and
     * every caller must treat it as "leave this session alone".
     */
    public function currentLease(Request $request): ?DoctorSessionLease
    {
        $token = $this->sessionToken($request);

        return $token === null ? null : $this->leases->activeByTokenHash(hash('sha256', $token));
    }

    /**
     * Per-request revalidation. Returns a denial reason, or null to continue.
     *
     * STEP ONE IS THE WHOLE COMPATIBILITY STORY: a session with no token passes
     * through unconditionally, and no lease is ever created here.
     *
     * STEP FOUR IS THE MECHANISM SECTION Q ASKS FOR — cover activation, cover
     * expiry and an approved permanent transfer all become one comparison, with
     * no scheduler anywhere in the correctness path. Its guards are the
     * delicate part:
     *
     *  - When the resolver declines to answer (the capability is off, the
     *    account is unlinked, the lock is UNSET, or the locked branch has lost
     *    is_active/is_rme_enabled) it returns null, and null must never evict.
     *    Otherwise disarming the branch-lock flag would log out every doctor.
     *  - When the LEASE carries no branch, the session was established before
     *    the capability could answer, and arming the flag must not mass-evict
     *    the doctors already working.
     *  - Only when BOTH sides have an answer are they compared, and then the
     *    COVER identity is compared too. Without that, a cover expiring onto
     *    the same branch as home — or one cover replacing another at the same
     *    target — would compare equal and the session would survive, while
     *    section Q says expiry MUST invalidate.
     */
    public function revalidate(
        Request $request,
        User $user,
        ?int $effectiveBranchIdNow = null,
        ?int $effectiveCoverIdNow = null,
    ): ?string {
        $token = $this->sessionToken($request);

        if ($token === null) {
            return null;
        }

        $lease = $this->leases->activeByTokenHash(hash('sha256', $token));

        if ($lease === null) {
            return self::DENY_LEASE_MISSING;
        }

        if ((int) $lease->user_id !== (int) $user->id) {
            return self::DENY_LEASE_MISMATCH;
        }

        $leaseBranchId = $lease->effective_branch_id === null ? null : (int) $lease->effective_branch_id;

        if ($effectiveBranchIdNow !== null
            && $leaseBranchId !== null
            && ! $lease->establishedUnder($effectiveBranchIdNow, $effectiveCoverIdNow)) {
            return self::DENY_BRANCH_CONTEXT_CHANGED;
        }

        $this->renewIfDue($lease, $request);

        return null;
    }

    /**
     * Evict the session in front of us and record why.
     *
     * The lease row is released only when it belongs to THIS user and the
     * reason is a branch-context change — the one eviction that ends a lease
     * cleanly rather than finding it already broken. A mismatched lease belongs
     * to somebody else and is never written to from here; a missing one has
     * nothing left to release.
     */
    public function evict(Request $request, User $user, string $reason): void
    {
        $lease = $this->currentLease($request);

        if ($lease !== null && (int) $lease->user_id !== (int) $user->id) {
            // Somebody else's lease. Never written to from here.
            $lease = null;
        }

        if ($lease !== null && $reason === self::DENY_BRANCH_CONTEXT_CHANGED) {
            $this->leases->release($lease, DoctorSessionLease::RELEASE_EFFECTIVE_BRANCH_CHANGED);
        }

        $this->auditLogs->log(
            $lease === null ? self::AUDIT_ENTITY_USERS : self::AUDIT_ENTITY_LEASES,
            $lease === null ? (int) $user->id : (int) $lease->id,
            self::ACTION_EVICTED,
            null,
            ['reason' => $reason, 'outcome' => 'evicted'],
            $user,
        );

        $this->tearDown($request);
    }

    /**
     * Tear the current session down without touching any other session.
     *
     * logoutCurrentDevice(), NEVER logout(): the latter cycles the shared
     * users.remember_token, which would invalidate the remember-me cookie for
     * EVERY session of the account — so refusing a second login would partially
     * evict the first, which is precisely what this capability forbids.
     *
     * It audits nothing on purpose. Both callers have already written the one
     * audit row that explains the outcome, and a second row here would double
     * every teardown in the trail.
     */
    public function tearDown(Request $request): void
    {
        Auth::guard('web')->logoutCurrentDevice();

        if ($request->hasSession()) {
            // invalidate() flushes session DATA, which is what actually kills
            // the lease token, and migrates the id.
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }
    }

    /**
     * Release the lease this session holds, on the way out.
     *
     * A no-op when there is no token, so it is safe to call unconditionally
     * from a logout path shared by every role.
     */
    public function releaseCurrent(Request $request, ?User $user, string $reason): void
    {
        $token = $this->sessionToken($request);

        if ($token === null) {
            return;
        }

        $request->session()->forget(self::SESSION_LEASE_TOKEN);

        $lease = $this->leases->activeByTokenHash(hash('sha256', $token));

        if ($lease === null) {
            return;
        }

        if ($user !== null && (int) $lease->user_id !== (int) $user->id) {
            // Not ours to end.
            return;
        }

        $this->leases->release($lease, $reason);

        $this->auditLogs->log(
            self::AUDIT_ENTITY_LEASES,
            (int) $lease->id,
            self::ACTION_RELEASED,
            null,
            ['reason' => $reason, 'outcome' => 'released'],
            $user,
        );
    }

    /**
     * An approver clears a stuck lease, or an approval releases one as part of
     * its own transaction.
     *
     * THIS IS DATA, NOT AN IN-PROCESS LOGOUT. The victim keeps working until
     * their browser makes another request, at which point the middleware finds
     * no lease behind their token and tears the session down. That is
     * NEXT-REQUEST eviction, not instantaneous, and for an idle tablet it can
     * be minutes. There is no cross-session logout primitive in this codebase
     * and this method does not invent one.
     *
     * The audit is written INSIDE the transaction, unlike the denial path,
     * because a caller composing this into an approval wants the release and
     * its record to commit or roll back together.
     */
    public function releaseFor(int $userId, string $reason, ?User $actor = null): ?DoctorSessionLease
    {
        return DB::transaction(function () use ($userId, $reason, $actor): ?DoctorSessionLease {
            $lease = $this->leases->lockActiveForUser($userId);

            if ($lease === null) {
                return null;
            }

            $released = $this->leases->release(
                $lease,
                $reason,
                $actor?->id === null ? null : (int) $actor->id,
            );

            $this->auditLogs->log(
                self::AUDIT_ENTITY_LEASES,
                (int) $released->id,
                self::ACTION_REVOKED,
                null,
                ['reason' => $reason, 'outcome' => 'revoked', 'user_id' => $userId],
                $actor,
            );

            return $released;
        });
    }

    /**
     * The refusal a doctor reads.
     *
     * Keyed on `email`, because that is the only key the login view renders,
     * and Indonesian, because that is the language of the surface. Naming the
     * doctor's OWN other session is their working context, not an estate
     * disclosure — but no device name, IP address, user agent or session id
     * ever appears here.
     */
    public function denialMessage(string $reason, ?DoctorSessionLease $incumbent = null): string
    {
        return match ($reason) {
            self::DENY_ACTIVE_SESSION_ELSEWHERE,
            self::DENY_LOST_CLAIM_RACE => $this->activeElsewhereMessage($incumbent),
            self::DENY_BRANCH_CONTEXT_CHANGED => 'Cabang kerja Anda telah berubah. '
                .'Silakan masuk kembali untuk melanjutkan.',
            default => 'Sesi Anda sudah tidak berlaku. Silakan masuk kembali.',
        };
    }

    /**
     * Refresh the lease's diagnostics, and correct the recorded session id once
     * after the framework has regenerated it.
     *
     * `last_seen_at` IS NOT A TTL and must never become one. It exists so an
     * operator can see when a stuck lease was last used; nothing reclaims on
     * it. Reclamation happens only when the incumbent's `sessions` row is gone.
     */
    private function renewIfDue(DoctorSessionLease $lease, Request $request): void
    {
        $sessionId = $request->hasSession() ? $request->session()->getId() : null;
        $lastSeenAt = $lease->last_seen_at;

        $stale = $lastSeenAt === null
            || $lastSeenAt->lt(now()->subMinutes(self::RENEW_INTERVAL_MINUTES));

        $drifted = $sessionId !== null && (string) $lease->session_id !== $sessionId;

        if (! $stale && ! $drifted) {
            return;
        }

        $this->leases->renew($lease, $sessionId);
    }

    private function activeElsewhereMessage(?DoctorSessionLease $incumbent): string
    {
        $since = $this->clinicalTimeOfDay($incumbent?->claimed_at);

        $opening = $since === null
            ? 'Akun Anda masih aktif di sesi lain. '
            : 'Akun Anda masih aktif di sesi lain sejak pukul '.$since.'. ';

        return $opening
            .'Keluar dari sesi tersebut lebih dulu, lalu masuk kembali di sini. '
            .'Jika sesi itu tidak dapat diakses, hubungi Super Admin atau Supervisor RME '
            .'untuk melepaskannya.';
    }

    /**
     * A wall-clock time in the clinic's canonical timezone, or null.
     *
     * Null rather than a guess: the timezone is configuration and reading it
     * can fail, and a denial that cannot render is worse than a denial without
     * a timestamp in it.
     */
    private function clinicalTimeOfDay(mixed $instant): ?string
    {
        if (! $instant instanceof DateTimeInterface) {
            return null;
        }

        try {
            return CarbonImmutable::instance($instant)
                ->setTimezone($this->clock->timezone())
                ->format('H:i T');
        } catch (Throwable) {
            return null;
        }
    }

    private function sessionToken(Request $request): ?string
    {
        if (! $request->hasSession()) {
            return null;
        }

        $token = $request->session()->get(self::SESSION_LEASE_TOKEN);

        return is_string($token) && $token !== '' ? $token : null;
    }

    /**
     * Recognise a unique-constraint violation across both drivers the project
     * runs on: PostgreSQL in production, SQLite in the suite.
     *
     * SQLSTATE 23000 is deliberately NOT tested as a code. It is the generic
     * integrity-constraint class, so accepting it would misread a foreign-key
     * or not-null violation as a lost race and silently swallow a real bug.
     * SQLite is recognised by its message instead.
     */
    private function isUniqueViolation(QueryException $exception): bool
    {
        if ($exception->getCode() === '23505') {  // PostgreSQL unique_violation
            return true;
        }

        $message = strtolower($exception->getMessage());

        return str_contains($message, 'unique constraint')
            || str_contains($message, 'unique violation')
            || str_contains($message, 'duplicate key');
    }
}
