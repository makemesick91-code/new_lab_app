<?php

namespace App\Modules\FrontOfficeDevice\Middleware;

use App\Modules\FrontOfficeDevice\Services\FrontOfficeBranchDeviceLockService;
use App\Modules\FrontOfficeDevice\Services\FrontOfficeDeviceSessionService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * REVISION-FRONT-OFFICE-BRANCH-DEVICE-LOCK-1 — the lock re-asked on every
 * protected request.
 *
 * WHY LOGIN-TIME VALIDATION IS NOT ENOUGH.
 *
 * A session established this morning on an approved tablet is a claim about the
 * past. Between then and this request the device may have been disabled or
 * revoked, its branch ownership may have been corrected, the account may have
 * been removed from the cohort, or its branch may have lost `is_active`. Each of
 * those should end the session, and none of them can be noticed by a check that
 * only ever ran at login.
 *
 * So the decision is re-evaluated here, from the same service, and a session
 * that no longer satisfies it is TORN DOWN rather than merely redirected — a
 * still-valid session cookie must not remain a path into another branch's data.
 *
 * COST. While the flag is off this returns on its first line. While it is on it
 * costs one primary-key device lookup and one branch lookup per request for the
 * armed accounts only — four accounts, not eight, and nobody else in the system.
 */
class EnsureFrontOfficeDeviceSession
{
    public function __construct(
        private readonly FrontOfficeBranchDeviceLockService $lock,
        private readonly FrontOfficeDeviceSessionService $sessions,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->lock->enforcementEnabled()) {
            return $next($request);
        }

        $user = $request->user();

        // A guest, a non-Front-Office account, or a Front Office account that
        // is not armed. All three continue untouched.
        if ($user === null || ! $this->lock->appliesTo($user)) {
            return $next($request);
        }

        /*
         * The routes that must stay reachable for a session being torn down or
         * rebuilt. Without `logout` an armed operator on a wrong-branch device
         * could not even log themselves out, and without the ceremony routes a
         * denied operator could not complete the assertion that fixes it.
         */
        if ($request->routeIs('logout', 'login')
            || $request->routeIs('front-office-device-webauthn.*')) {
            return $next($request);
        }

        $decision = $this->lock->evaluate($user, $request);

        if (! $decision->isDenial()) {
            return $next($request);
        }

        $this->sessions->auditDenial($user, $decision);
        $this->sessions->invalidate($request, $user);

        $message = $decision->message();

        if ($request->expectsJson()) {
            return response()->json(['message' => $message], 403);
        }

        return redirect()->route('login')->withErrors(['email' => $message]);
    }
}
