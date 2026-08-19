<?php

namespace App\Modules\RmeOnlineContext\Middleware;

use App\Modules\RmeOnlineContext\Services\UserOnlineContextService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sprint 66.0 — Enforce doctor/admin online context before RME workflows.
 */
class EnsureRmeOnlineContext
{
    /**
     * @var array<int, string>
     */
    private const EXEMPT_ROUTE_NAMES = [
        'login',
        'logout',
        'profile.edit',
        'profile.update',
        'profile.destroy',
        'rme.online-context.select',
        'rme.online-context.rooms',
        'rme.online-context.doctor',
        'rme.online-context.admin-clinic',
        'rme.online-context.perawat',
        'rme.online-context.kasir',
        'rme.online-context.offline',
    ];

    public function __construct(
        private readonly UserOnlineContextService $onlineContext,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            return $next($request);
        }

        if ($this->isExemptRoute($request)) {
            return $next($request);
        }

        if ($this->onlineContext->hasSatisfiedContext($user)) {
            return $next($request);
        }

        if (! $this->onlineContext->requiresDoctorContext($user)
            && ! $this->onlineContext->requiresAdminClinicContext($user)
            && ! $this->onlineContext->requiresPerawatContext($user)
            // FIX-CLINIC-OPS-BRANCH-CONTEXT-WA-1 (FIX-03) — Kasir now works from a
            // selected branch too. Without this the cashier would fall through
            // this early return and reach the workspace with no working context.
            && ! $this->onlineContext->requiresKasirContext($user)) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            abort(403, 'Pilih cabang dan ruangan (dokter) atau cabang (admin klinik/perawat/kasir) terlebih dahulu.');
        }

        return redirect()
            ->route('rme.online-context.select')
            ->with('error', 'Lengkapi konteks kerja online Anda terlebih dahulu.');
    }

    private function isExemptRoute(Request $request): bool
    {
        $routeName = $request->route()?->getName();

        if ($routeName === null) {
            return false;
        }

        return in_array($routeName, self::EXEMPT_ROUTE_NAMES, true);
    }
}
