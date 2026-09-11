<?php

declare(strict_types=1);

namespace App\Modules\DoctorAccess\Middleware;

use App\Modules\DoctorAccess\Services\DoctorSessionLeaseService;
use App\Modules\RmeOnlineContext\Services\UserOnlineContextService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * DOCTOR-ACCESS-SINGLE-SESSION-BRANCH-LOCK-1 — per-request lease
 * revalidation, and the next-request eviction primitive an out-of-band release
 * relies on.
 *
 * IT REVALIDATES THE LEASE AND NOTHING ELSE. The token in the session must
 * still resolve to a live lease, and that lease must still belong to this
 * account. A session whose lease was released — at logout, or by an operator
 * clearing a stuck one — is torn down on its next request, which is what turns
 * a row update into an actual eviction. No other property of the session is
 * re-derived here; the device middleware answers for the tablet, and this one
 * deliberately invents no third condition.
 *
 * THE PASS-THROUGH LINE IS THE WHOLE COMPATIBILITY STORY. A session that
 * carries no lease token is let through unconditionally and NEVER has a lease
 * created for it. The lease is claimed on one event, and the thousands of
 * sessions this application's test suite mints authenticate through a different
 * one; denying "no lease at all" would deny all of them. It also keeps working
 * the harnesses that snapshot and restore session data to simulate a second
 * browser.
 *
 * IT RUNS FIRST IN THE WEB APPEND LIST, AND THAT ORDER IS LOAD-BEARING.
 *
 *  - Before TouchOnlineContextLastSeen, which unconditionally refreshes
 *    `last_seen_at` for any authenticated user holding an ONLINE context. The
 *    room-occupancy check keys off exactly that row, so appended later the very
 *    request that evicts a doctor would first refresh their presence, leaving a
 *    ghost holding a clinic room. Running first, the evicting request
 *    short-circuits before Touch executes at all.
 *  - Before EnsureRmeOnlineContext, which redirects a doctor with no live
 *    context to the branch selector. Appended after it, an evicted doctor would
 *    be redirected and this check would never run — which is why running first
 *    makes selector-route exemptions unnecessary.
 *
 * It cannot be PREPENDED: group prepends land before StartSession, so a
 * prepended middleware has neither a session nor an authenticated user.
 */
class EnsureDoctorSessionLease
{
    public function __construct(
        private readonly DoctorSessionLeaseService $leases,
        private readonly UserOnlineContextService $onlineContexts,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        // OFF by default, and disarmed on a session driver whose liveness this
        // deployment cannot observe. One read, then out.
        if (! $this->leases->enabled()) {
            return $next($request);
        }

        $user = $request->user();

        if ($user === null || ! $this->leases->subjectTo($user)) {
            return $next($request);
        }

        // Never block the way in or the way out. The login POST is unnamed, so
        // only the GET form and the logout POST can be matched by name — which
        // is enough: a denied login is refused by the claim itself, not here.
        if ($request->routeIs('login', 'logout')) {
            return $next($request);
        }

        if (! $request->hasSession()) {
            return $next($request);
        }

        // NO LEASE AT ALL -> PASS THROUGH. See the class note. Asked here as
        // a plain session read rather than by resolving the lease, so that the
        // overwhelmingly common case costs no query at all.
        if (! $this->holdsLeaseToken($request)) {
            return $next($request);
        }

        $reason = $this->leases->revalidate($request, $user);

        if ($reason === null) {
            return $next($request);
        }

        // FREE THE CLINIC ROOM BEFORE TEARING THE SESSION DOWN. Without this an
        // evicted doctor's room stays occupied and blocks the doctor taking
        // over, for as long as the presence record counts as recent. The logout
        // controller already does the two in this order.
        $this->onlineContexts->markOffline($user);

        $this->leases->evict($request, $user, $reason);

        $message = $this->leases->denialMessage($reason);

        // The shape proven to render inside the Android WebView: the login view
        // prints only the `email` error bag entry.
        return $request->expectsJson()
            ? response()->json(['message' => $message], 403)
            : redirect()->route('login')->withErrors(['email' => $message]);
    }

    /**
     * Does this session claim to hold a lease at all?
     *
     * CLAIM, not proof. A token whose lease has since been released — by an
     * operator clearing a stuck lease — still answers true here, and
     * revalidation is what turns it into an eviction.
     * That distinction is the whole point: a session with NO token is left
     * alone forever, a session with a token is answerable for it.
     */
    private function holdsLeaseToken(Request $request): bool
    {
        $token = $request->session()->get(DoctorSessionLeaseService::SESSION_LEASE_TOKEN);

        return is_string($token) && $token !== '';
    }
}
