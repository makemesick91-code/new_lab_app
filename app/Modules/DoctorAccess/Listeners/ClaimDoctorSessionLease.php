<?php

declare(strict_types=1);

namespace App\Modules\DoctorAccess\Listeners;

use App\Models\User;
use App\Modules\DoctorAccess\Services\DoctorEffectiveBranchResolver;
use App\Modules\DoctorAccess\Services\DoctorSessionLeaseService;
use Illuminate\Auth\Events\Login;

/**
 * DOCTOR-ACCESS-SINGLE-SESSION-BRANCH-LOCK-1 — the ONE place a lease is
 * claimed.
 *
 * WHY A Login LISTENER AND NOT THE LOGIN CONTROLLERS.
 *
 * `Illuminate\Auth\Events\Login` is fired by the session guard from the
 * ordinary login AND from the remember-me recaller path, which resurrects a
 * fully authenticated session through no controller at all. Remember-me is live
 * end to end here — the login view has the checkbox and the form request passes
 * it to Auth::attempt — so a claim placed at login call sites would be
 * bypassable by design. This listener also covers the Android ticket
 * redemption, the browser WebAuthn login and registration for free.
 *
 * AND WHY THAT IS ALSO WHAT MAKES IT IMPLEMENTABLE. `setUser()` fires a
 * different event, and the test suite's `actingAs()` goes through `setUser()`.
 * So this listener covers every production authentication entry and none of the
 * thousands of existing test sessions — which is exactly the property the
 * middleware depends on, because a session that never claimed a lease must pass
 * through untouched forever.
 *
 * HOW A LISTENER DENIES A LOGIN THAT HAS ALREADY HAPPENED. At this point the
 * guard has written the auth id into the session and regenerated the session
 * id, and it may have queued a recaller cookie — but nothing is persisted yet:
 * the session row is written on the response and the cookie is attached on the
 * response. So the refusal works by making the response carry a dead session
 * and no recaller cookie, and by throwing. Returning false CANNOT deny: the
 * dispatcher only halts propagation and the guard discards the return value.
 * The throw is not swallowed — the dispatcher invokes listeners with no
 * try/catch — and because it unwinds before `setUser()` runs, the guard stays
 * logged out for the rest of the request.
 *
 * ONE HONEST CONSEQUENCE. The throw unwinds through `Auth::attempt()` inside
 * the login form request, so neither the rate limiter's hit nor its clear runs.
 * A denied doctor is therefore neither pushed toward the login lockout nor
 * credited with a clear. That is wanted — a doctor whose other tablet is still
 * open must not also be locked out of the form — but it is a real deviation
 * from the failed-password path, and it belongs here rather than hidden.
 *
 * REGISTERED EXPLICITLY in AppServiceProvider::boot(). This application never
 * calls withEvents(), so there is no event discovery: a listener dropped into
 * an app/Listeners directory would silently never run.
 */
final class ClaimDoctorSessionLease
{
    public function __construct(
        private readonly DoctorSessionLeaseService $leases,
        private readonly DoctorEffectiveBranchResolver $branches,
    ) {}

    public function handle(Login $event): void
    {
        // The device channel is a stateless API group and must never mint a
        // lease; only the session guard can hold one.
        if ($event->guard !== 'web') {
            return;
        }

        if (! $this->leases->enabled()) {
            return;
        }

        $user = $event->user;

        if (! $user instanceof User || ! $this->leases->subjectTo($user)) {
            return;
        }

        $request = request();

        // Covers Auth::login() from a console command or a seeder, which has no
        // session to bind a lease to.
        if (! $request->hasSession()) {
            return;
        }

        // EFFECTIVE_CLINICAL_BRANCH AT CLAIM TIME. Null is an ordinary answer —
        // the branch lock may be disarmed, the account unlinked, the lock
        // UNSET, or the locked branch no longer usable — and a null claim is
        // never compared against later, so it can never evict anyone.
        $effective = $this->branches->resolve($user);

        $this->leases->claimOrDeny(
            $user,
            $request,
            $effective->branchId(),
            $effective->coverId(),
        );
    }
}
