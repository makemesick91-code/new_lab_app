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
 * The capability therefore DISARMS rather than pretends:
 * DoctorSessionLeaseService::enabled() requires {@see self::observable()} as
 * well as its flag, so a deployment that cannot see an incumbent enforces
 * nothing instead of enforcing a rule it cannot evaluate.
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
}
