<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Modules\DoctorDevice\Services\DoctorAppLoginGate;
use App\Modules\DoctorDevice\Services\DoctorDeviceSessionService;
use App\Modules\RmeOnlineContext\Services\UserOnlineContextService;
use App\Services\Auth\PostAuthenticationRedirectService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AuthenticatedSessionController extends Controller
{
    /**
     * Display the login view.
     */
    public function create(): View
    {
        return view('auth.login');
    }

    /**
     * Handle an incoming authentication request.
     */
    public function store(LoginRequest $request): RedirectResponse
    {
        $request->authenticate();

        $request->session()->regenerate();

        // REVISION-DOCTOR-AUTO-DEVICE-APPROVAL-APP-ONLY-LOGIN-1 — the app-only
        // gate, consulted in exactly ONE place.
        //
        // ENFORCEMENT IS OFF IN PRODUCTION. With the flag off this returns null
        // before touching the database, so every doctor logs in exactly as they
        // did before and an empty device registry can lock nobody out. There is
        // deliberately no second copy of this decision anywhere: enforcement
        // that lives in two places eventually disagrees with itself.
        //
        // What denies a browser login when the flag IS on is the ABSENCE of a
        // server-verified device session — never a User-Agent, a header or any
        // other value the client gets to assert.
        $gate = app(DoctorAppLoginGate::class);
        $denial = $gate->denyBrowserSessionReason($request->user(), $request);

        if ($denial !== null) {
            $user = $request->user();

            /*
             * DOCTOR-PWA-WEBAUTHN-1 — a denial that a trusted BROWSER can still
             * answer.
             *
             * The only denial reachable at login time is "no device session",
             * because a binding is written by redemption and none has happened
             * yet. Until now that was the end of the story for a browser. It no
             * longer has to be: a browser enrolled on an approved clinic device
             * can prove itself with a WebAuthn assertion and earn exactly the
             * same binding the Clinic App earns.
             *
             * THE PRIVILEGED SESSION IS TORN DOWN FIRST, ALWAYS.
             *
             * `invalidate()` runs before the pending marker is written, so a
             * doctor waiting at the biometric prompt is NOT logged in — the
             * password step alone never yields a usable session. What survives
             * is a short-lived note of which account passed the password, which
             * grants nothing on its own.
             *
             * `canAssert()` is checked BEFORE redirecting because a doctor with
             * no registered credential would otherwise be sent to a ceremony
             * that cannot succeed. A clear denial is better than a dead end.
             */
            $mayAssert = $denial === DoctorAppLoginGate::DENY_NO_DEVICE_SESSION
                && $user !== null
                && $gate->deviceCredentialLoginAvailable($user);

            $sessions = app(DoctorDeviceSessionService::class);

            $sessions->invalidate($request, $user, $denial);

            if ($mayAssert) {
                $sessions->beginDeviceCredentialLogin($request, $user);

                return redirect()->route('doctor-device-webauthn.show');
            }

            throw ValidationException::withMessages([
                'email' => $gate->denialMessage($denial),
            ]);
        }

        // FIX-LOGIN-REDIRECT-RUNTIME-PERMISSIONS — a stored `url.intended` may
        // only win when it is internal, well-formed, and authorized for the
        // user; otherwise the role-aware default landing page is used. This
        // prevents a stale intended `/dashboard` from sending a Lab-only Admin
        // Lab account into a 403.
        return redirect()->to(
            app(PostAuthenticationRedirectService::class)->resolve($request)
        );
    }

    /**
     * Destroy an authenticated session.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $user = $request->user();

        if ($user !== null) {
            app(UserOnlineContextService::class)->markOffline($user);
        }

        Auth::guard('web')->logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        return redirect('/');
    }
}
