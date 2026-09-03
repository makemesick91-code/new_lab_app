<?php

namespace App\Modules\DoctorDevice\Middleware;

use App\Modules\DoctorDevice\Services\DoctorAppLoginGate;
use App\Modules\DoctorDevice\Services\DoctorDeviceSessionService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * REVISION-DOCTOR-AUTO-DEVICE-APPROVAL-APP-ONLY-LOGIN-1 — session/device
 * binding, checked on every protected request.
 *
 * WHY THIS EXISTS AT ALL
 *
 * A login-time check alone lets a session outlive the trust it was built on.
 * Revoking a tablet at 09:00 would leave the doctor holding it working until
 * they happened to log out — which is precisely the moment revocation is
 * supposed to matter. So the binding is re-verified per request.
 *
 * WHILE ENFORCEMENT IS OFF THIS IS A NO-OP.
 *
 * The first line returns. No query runs, no device is looked up, and an empty
 * authorization table cannot deny anybody. That is deliberate and is pinned by
 * a test: shipping the capability must not change a single thing for the
 * doctors working in production today.
 */
class EnsureDoctorDeviceSession
{
    public function __construct(
        private readonly DoctorAppLoginGate $gate,
        private readonly DoctorDeviceSessionService $sessions,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        // OFF by default. One config read, then out.
        if (! $this->gate->enforcementEnabled()) {
            return $next($request);
        }

        $user = $request->user();

        if ($user === null || ! $this->gate->appliesTo($user)) {
            return $next($request);
        }

        // The redemption route is how a doctor GETS a binding, so it cannot
        // require one; and logging out must never be blocked by the very check
        // that is refusing the session.
        if ($request->routeIs('doctor-device-login.redeem', 'logout', 'login')) {
            return $next($request);
        }

        $reason = $this->gate->denySessionReason($user, $request);

        if ($reason === null) {
            return $next($request);
        }

        $this->sessions->invalidate($request, $user, $reason);

        $message = $this->gate->denialMessage($reason);

        if ($request->expectsJson()) {
            return response()->json(['message' => $message], 403);
        }

        return redirect()->route('login')->withErrors(['email' => $message]);
    }
}
