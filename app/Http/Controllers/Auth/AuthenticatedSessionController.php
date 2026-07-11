<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Modules\RmeOnlineContext\Services\UserOnlineContextService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
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

        return redirect()->intended($this->redirectPathFor($request));
    }

    /**
     * Resolve the default post-login landing path for the authenticated user.
     *
     * Only controls the default landing page — an existing intended() URL still
     * takes precedence and authorization is enforced by the target route itself.
     */
    private function redirectPathFor(Request $request): string
    {
        $user = $request->user();
        $onlineContext = app(UserOnlineContextService::class);

        if ($user !== null && ! $onlineContext->hasSatisfiedContext($user)) {
            if ($onlineContext->requiresDoctorContext($user)
                || $onlineContext->requiresAdminClinicContext($user)
                || $onlineContext->requiresPerawatContext($user)) {
                return route('rme.online-context.select', absolute: false);
            }
        }

        if ($user?->hasRole('Admin Warehouse')) {
            return route('inventory.executive-dashboard', absolute: false);
        }

        // FIX-ADMIN-LAB-LAB-ONLY-ACCESS — Admin Lab is a Lab-only role and no longer
        // holds `view dashboard`, so the generic cross-module dashboard is forbidden.
        // Land it on the canonical Lab Workflow V2 orders workspace instead. Guard
        // with Route::has so this never throws if the Lab route set is unavailable.
        // Skip the redirect for a Super Admin (who legitimately reaches the dashboard)
        // so a platform admin that also carries the Admin Lab role is not downgraded.
        if ($user?->hasRole('Admin Lab')
            && ! $user->hasRole('Super Admin')
            && Route::has('lab-v2-orders.index')) {
            return route('lab-v2-orders.index', absolute: false);
        }

        return route('dashboard', absolute: false);
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
