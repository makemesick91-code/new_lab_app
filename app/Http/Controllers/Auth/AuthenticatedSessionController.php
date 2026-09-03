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
            app(DoctorDeviceSessionService::class)->invalidate($request, $request->user(), $denial);

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
