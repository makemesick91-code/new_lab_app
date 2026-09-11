<?php

declare(strict_types=1);

namespace App\Modules\DoctorAccess\Middleware;

use App\Modules\DoctorAccess\Services\DoctorEffectiveBranchResolver;
use App\Modules\DoctorAccess\Services\DoctorSessionLeaseService;
use App\Modules\RmeOnlineContext\Services\UserOnlineContextService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * DOCTOR-ACCESS-SINGLE-SESSION-BRANCH-LOCK-1 — per-request lease
 * revalidation, and the next-request eviction primitive the approval workflows
 * consume.
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
 *
 * WHY THE BRANCH IS RECOMPUTED HERE, ON EVERY REQUEST. Section Q requires that
 * cover activation, cover expiry and an approved permanent transfer each
 * invalidate the doctor's session, and that authorization be derivable from
 * current timestamps rather than from a scheduler having run. Recomputing the
 * effective branch and comparing it with the value the lease was established
 * under makes all three the same event, with no cron in the correctness path
 * and no way for a branch to change silently mid-session.
 */
class EnsureDoctorSessionLease
{
    public function __construct(
        private readonly DoctorSessionLeaseService $leases,
        private readonly DoctorEffectiveBranchResolver $branches,
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

        $effective = $this->branches->resolve($user);

        $reason = $this->leases->revalidate(
            $request,
            $user,
            $effective->branchId(),
            $effective->coverId(),
        );

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
     * approver clearing a stuck lease, or by an approved transfer — still
     * answers true here, and revalidation is what turns it into an eviction.
     * That distinction is the whole point: a session with NO token is left
     * alone forever, a session with a token is answerable for it.
     */
    private function holdsLeaseToken(Request $request): bool
    {
        $token = $request->session()->get(DoctorSessionLeaseService::SESSION_LEASE_TOKEN);

        return is_string($token) && $token !== '';
    }
}
