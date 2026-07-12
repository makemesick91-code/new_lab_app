<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Modules\RmeOnlineContext\Services\UserOnlineContextService;
use App\Services\Auth\PostAuthenticationRedirectService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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
