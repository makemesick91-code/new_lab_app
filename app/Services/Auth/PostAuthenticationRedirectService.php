<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Modules\RmeOnlineContext\Services\UserOnlineContextService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Throwable;

/**
 * FIX-LOGIN-REDIRECT-RUNTIME-PERMISSIONS.
 *
 * Single source of truth for where an authenticated user lands after any auth
 * completion path (login, registration, email verification, password
 * confirmation, online-context selection).
 *
 * Two guarantees:
 *  1. The default landing page is permission/role-aware — a user is never sent
 *     to a route they are forbidden from (e.g. Admin Lab is Lab-only and must
 *     not land on the generic `/dashboard`, which requires
 *     `view dashboard|view_owner_dashboard`).
 *  2. A stored `url.intended` may only take precedence when the destination is
 *     internal, well-formed, resolvable to a route, AND authorized for the
 *     current user. An unauthorized, external, or malformed intended URL is
 *     discarded and the role-aware default is used instead.
 *
 * This intentionally does NOT widen any permission: authorization is decided by
 * inspecting the target route's own `permission:` / `role:` middleware against
 * the user's effective abilities (which honor Spatie roles + `Gate::before`).
 */
class PostAuthenticationRedirectService
{
    public function __construct(private readonly UserOnlineContextService $onlineContext) {}

    /**
     * Resolve the final post-authentication redirect path for the user.
     *
     * Honors a safe, authorized `url.intended` if present (consuming it), else
     * falls back to the role-aware default landing page.
     */
    public function resolve(Request $request, ?User $user = null): string
    {
        $user ??= $request->user();

        $default = $this->defaultLandingPath($user);

        if ($user === null) {
            return $default;
        }

        $intended = $request->session()->pull('url.intended');

        if (is_string($intended) && $intended !== '') {
            $localPath = $this->toLocalPath($intended);

            if ($localPath !== null && $this->userCanAccessPath($localPath, $user)) {
                return $localPath;
            }
        }

        return $default;
    }

    /**
     * Role/permission-aware default landing page for the given user.
     *
     * Mirrors the pre-existing role routing but centralizes it so every auth
     * completion path stays consistent and never defaults a permission-less
     * role onto the forbidden generic dashboard.
     */
    public function defaultLandingPath(?User $user): string
    {
        if ($user === null) {
            return route('dashboard', absolute: false);
        }

        // Online-context roles must select their RME branch/context first.
        if (! $this->onlineContext->hasSatisfiedContext($user)
            && ($this->onlineContext->requiresDoctorContext($user)
                || $this->onlineContext->requiresAdminClinicContext($user)
                || $this->onlineContext->requiresPerawatContext($user))) {
            return route('rme.online-context.select', absolute: false);
        }

        if ($user->hasRole('Admin Warehouse') && Route::has('inventory.executive-dashboard')) {
            return route('inventory.executive-dashboard', absolute: false);
        }

        // FIX-ADMIN-LAB-LAB-ONLY-ACCESS — Admin Lab is Lab-only and no longer
        // holds `view dashboard`, so the generic dashboard is forbidden (403).
        // Land it on the canonical Lab Workflow V2 workspace. A Super Admin that
        // also carries the Admin Lab role legitimately reaches the dashboard and
        // is not downgraded.
        if ($user->hasRole('Admin Lab')
            && ! $user->hasRole('Super Admin')
            && Route::has('lab-v2-orders.index')) {
            return route('lab-v2-orders.index', absolute: false);
        }

        return route('dashboard', absolute: false);
    }

    /**
     * Public authorization probe reused by the deploy authenticated-smoke gate:
     * whether the user may access the given local path, decided by the target
     * route's own permission:/role: middleware. Fail-closed on unknown routes.
     */
    public function userMayAccessLocalPath(User $user, string $localPath): bool
    {
        return $this->userCanAccessPath($localPath, $user);
    }

    /**
     * Normalize a stored intended destination to a same-application local path
     * ("/path?query"), or null when it is external, malformed, or uses an
     * unsafe scheme. Blocks open-redirect / protocol-relative / javascript:
     * data: vbscript: vectors.
     */
    private function toLocalPath(string $intended): ?string
    {
        $intended = trim($intended);

        if ($intended === '') {
            return null;
        }

        // Explicitly reject dangerous / non-navigational schemes and
        // protocol-relative URLs ("//evil.example").
        if (preg_match('#^[a-z][a-z0-9+.\-]*:#i', $intended, $m)) {
            $scheme = strtolower(rtrim($m[0], ':'));
            if (! in_array($scheme, ['http', 'https'], true)) {
                return null;
            }
        }

        if (str_starts_with($intended, '//')) {
            return null;
        }

        $parts = parse_url($intended);

        if ($parts === false) {
            return null;
        }

        if (isset($parts['scheme']) && ! in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            return null;
        }

        // Reject any cross-host destination.
        if (isset($parts['host'])) {
            $appHost = parse_url((string) config('app.url'), PHP_URL_HOST);

            if ($appHost === null || strcasecmp($parts['host'], $appHost) !== 0) {
                return null;
            }
        }

        $path = $parts['path'] ?? '/';

        if (! str_starts_with($path, '/') || str_starts_with($path, '//')) {
            return null;
        }

        $query = isset($parts['query']) && $parts['query'] !== '' ? '?'.$parts['query'] : '';

        return $path.$query;
    }

    /**
     * Whether the user may access the given local path, decided by the target
     * route's own permission:/role: middleware. Unknown/unmatchable routes are
     * treated as inaccessible (fail closed).
     */
    private function userCanAccessPath(string $localPath, User $user): bool
    {
        $route = $this->matchGetRoute($localPath);

        if ($route === null) {
            return false;
        }

        return $this->userSatisfiesRouteMiddleware($route, $user);
    }

    private function matchGetRoute(string $localPath): ?\Illuminate\Routing\Route
    {
        try {
            $request = Request::create($localPath, 'GET');

            return Route::getRoutes()->match($request);
        } catch (Throwable) {
            return null;
        }
    }

    private function userSatisfiesRouteMiddleware(\Illuminate\Routing\Route $route, User $user): bool
    {
        try {
            $middleware = $route->gatherMiddleware();
        } catch (Throwable) {
            return false;
        }

        foreach ($middleware as $mw) {
            if (! is_string($mw)) {
                continue;
            }

            if (str_starts_with($mw, 'permission:')) {
                $needed = array_filter(array_map('trim', explode('|', substr($mw, strlen('permission:')))));

                if ($needed !== [] && ! $this->userHasAnyPermission($user, $needed)) {
                    return false;
                }
            }

            if (str_starts_with($mw, 'role:')) {
                $roles = array_filter(array_map('trim', explode('|', substr($mw, strlen('role:')))));

                if ($roles !== [] && ! $user->hasAnyRole($roles)) {
                    return false;
                }
            }
        }

        return true;
    }

    private function userHasAnyPermission(User $user, array $permissions): bool
    {
        foreach ($permissions as $permission) {
            try {
                if ($user->can($permission)) {
                    return true;
                }
            } catch (Throwable) {
                // Unknown permission string — treat as not granted.
            }
        }

        return false;
    }
}
