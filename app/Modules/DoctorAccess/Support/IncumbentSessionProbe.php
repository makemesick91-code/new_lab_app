<?php

declare(strict_types=1);

namespace App\Modules\DoctorAccess\Support;

use App\Modules\DoctorAccess\Models\DoctorSessionLease;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * DOCTOR-ACCESS-SINGLE-SESSION-BRANCH-LOCK-1 — the ONLY place that answers
 * "is the incumbent session still alive?", and the place that refuses to guess
 * when it cannot see.
 *
 * WHY OBSERVABILITY IS A PRECONDITION FOR ARMING ANYTHING. Liveness is read
 * from the server-side `sessions` table. On any driver other than `database`
 * that table is never written, so every incumbent would read DEAD, every
 * second login would reclaim the lease, and the single-session rule would
 * silently degrade to newest-login-wins — a fail-OPEN dressed as enforcement.
 * The capability therefore DISARMS rather than pretends: both
 * DoctorSessionLeaseService::enabled() and
 * DoctorEffectiveBranchResolver::enabled() require {@see self::observable()}.
 *
 * The branch resolver requires it too, and that is not belt-and-braces. Cover
 * expiry is enforced by the lease middleware comparing the branch the session
 * was established under against the branch that is effective now. If the lease
 * engine disarmed while the branch lock stayed armed, an EXPIRED cover would
 * keep granting authority with nothing left to invalidate the session.
 */
class IncumbentSessionProbe
{
    /**
     * Can this deployment actually see whether a session is alive?
     *
     * `Schema::hasTable()` is checked as well as the driver because the table
     * is created by the framework migration and a deployment mid-migration must
     * not be told an incumbent is dead.
     */
    public function observable(): bool
    {
        return config('session.driver') === 'database' && Schema::hasTable('sessions');
    }

    /**
     * Does this lease holder still have a live server-side session?
     *
     * KEYED ON user_id, NOT on the session id recorded at claim time. The
     * framework mints a new id on every re-authentication
     * (Store::migrate() -> SessionGuard::updateSession() -> regenerate(true)),
     * and every login path regenerates again afterwards, so the recorded id is
     * stale within the same request. `sessions.user_id` is written by the
     * database handler, is indexed, and does not rotate.
     *
     * EXCLUDING THE CLAIMANT'S OWN ID is belt-and-braces rather than load-
     * bearing: at the moment the claim runs, regenerate(true) has already
     * destroyed the old row and the new one is not written until the response,
     * so the claimant has no row at all. The exclusion can never wrongly mark a
     * FOREIGN session dead, and it keeps the predicate correct if that timing
     * ever changes.
     *
     * The window mirrors the database session handler's own expiry test, so
     * "live" here means exactly what the session driver means by it.
     * `last_activity` is an integer unix timestamp.
     *
     * THERE IS DELIBERATELY NO DRIVER GUARD IN THIS METHOD, and it must not
     * grow one. Answering ALIVE because the driver is unobservable would deny
     * every login on a file, redis or array driver; answering DEAD would be the
     * fail-open this class exists to prevent. Observability is answered ONCE,
     * by {@see self::observable()}, and the capability disarms on it — so a
     * caller must gate on that before ever reaching this predicate.
     */
    public function isLive(DoctorSessionLease $lease, ?string $excludeSessionId = null): bool
    {
        // DMS-RME-COMPREHENSIVE-CLOSURE-1 (OD-7-7; amends rule 153's death
        // predicate, contract unchanged) — a lease claimed moments ago is a login
        // still IN FLIGHT: its sessions row is written only at the end of that
        // login's response. Reading it as dead let a concurrent second login
        // reclaim the lease and evict the first browser, the one outcome rule 153
        // forbids ("refuse the second login, never evict the first"). This is no
        // idle timer: once the window passes, only the sessions row decides.
        if ($this->inFlight($lease)) {
            return true;
        }

        $lifetimeMinutes = (int) config('session.lifetime', 120);

        return DB::table('sessions')
            ->where('user_id', (int) $lease->user_id)
            ->when(
                $excludeSessionId !== null,
                fn ($query) => $query->where('id', '!=', $excludeSessionId),
            )
            ->where('last_activity', '>=', now()->subMinutes($lifetimeMinutes)->getTimestamp())
            ->exists();
    }

    /**
     * DMS-RME-COMPREHENSIVE-CLOSURE-1 (OD-7-7) — is this the SAME browser that
     * claimed the lease, logging in again while that claim is still in flight?
     *
     * Both POSTs of a double tap post the same page's CSRF token, which
     * VerifyCsrfToken proved equal to that browser's pre-login session token;
     * another browser cannot present it. The lease stores only a SHA-256 digest
     * of it (`claim_nonce_hash`). Within the in-flight window a
     * matching digest means "my own first login", which may be superseded —
     * nobody else is evicted. Outside the window, or with no digest on either
     * side, the answer is no and the ordinary rule applies.
     */
    public function isSameBrowserInFlight(DoctorSessionLease $lease, ?string $claimNonceHash): bool
    {
        return $claimNonceHash !== null
            && is_string($lease->claim_nonce_hash)
            && $lease->claim_nonce_hash !== ''
            && hash_equals($lease->claim_nonce_hash, $claimNonceHash)
            && $this->inFlight($lease);
    }

    /**
     * A lease claimed less than `doctor_access.lease.in_flight_grace_seconds`
     * ago belongs to a login whose session row may not be written yet.
     */
    private function inFlight(DoctorSessionLease $lease): bool
    {
        $grace = max(0, (int) config('doctor_access.lease.in_flight_grace_seconds', 30));

        return $grace > 0
            && $lease->claimed_at !== null
            && $lease->claimed_at->greaterThan(now()->subSeconds($grace));
    }
}
