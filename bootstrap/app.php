<?php

use App\Console\Commands\AssignOwnerRoleCommand;
use App\Console\Commands\PatientDocumentsAuditCommand;
use App\Console\Commands\PatientDocumentsPruneTempCommand;
use App\Console\Commands\PruneInventoryAnalyticsSummaryCommand;
use App\Console\Commands\RefreshInventoryAnalyticsSummaryCommand;
use App\Http\Middleware\AttachRequestCorrelationContext;
use App\Modules\ClinicVisit\Middleware\EnsureVisitRoomAssigned;
use App\Modules\DoctorDevice\Middleware\EnsureDoctorDeviceSession;
use App\Modules\RmeOnlineContext\Middleware\EnsureRmeOnlineContext;
use App\Modules\RmeOnlineContext\Middleware\TouchOnlineContextLastSeen;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        // FEATURE-DOCTOR-TRUSTED-ANDROID-DEVICE-LOCK-1 Phase 3 — the Android
        // Clinic App's device channel. Registered as an API group on purpose:
        // stateless, no session cookie, no CSRF token, because the caller is
        // hardware proving possession of a key rather than a logged-in human.
        // These routes never authenticate a Doctor; enforcement stays OFF.
        api: __DIR__.'/../routes/device_api.php',
        apiPrefix: 'device-api',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withCommands([
        AssignOwnerRoleCommand::class,
        PatientDocumentsAuditCommand::class,
        PatientDocumentsPruneTempCommand::class,
        PruneInventoryAnalyticsSummaryCommand::class,
        RefreshInventoryAnalyticsSummaryCommand::class,
    ])
    ->withMiddleware(function (Middleware $middleware) {
        // LB-1 — trusted proxies are opt-in via LB_TRUSTED_PROXIES; empty
        // (default) leaves Laravel's stock TrustProxies behavior untouched
        // (trusts nothing), which is safe for a single VPS pilot.
        $trustedProxies = array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('LB_TRUSTED_PROXIES', ''))
        )));
        if ($trustedProxies !== []) {
            $middleware->trustProxies(at: $trustedProxies);
        }

        // OBS-1 — attach request/correlation id + safe log context as early
        // as possible, and set the response header as late as possible.
        $middleware->web(prepend: [
            AttachRequestCorrelationContext::class,
        ]);

        $middleware->web(append: [
            TouchOnlineContextLastSeen::class,
            EnsureRmeOnlineContext::class,
            // REVISION-DOCTOR-AUTO-DEVICE-APPROVAL-APP-ONLY-LOGIN-1 — session
            // to device binding. A NO-OP while enforcement is off: its first
            // line reads one config value and returns, so no protected route
            // requires device proof today. Registered globally rather than on a
            // route group because a revoked tablet has to stop working
            // EVERYWHERE, and an enumerated list of protected routes is a list
            // somebody eventually forgets to extend.
            EnsureDoctorDeviceSession::class,
        ]);

        $middleware->alias([
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
            // Hotfix Sprint 60.8 — RME room-assignment gate before examination.
            'visit.room' => EnsureVisitRoomAssigned::class,
            // Sprint 66.0 — doctor/admin online context gate (alias for selective use).
            'rme.online-context' => EnsureRmeOnlineContext::class,
            // REVISION-DOCTOR-AUTO-DEVICE-APPROVAL-APP-ONLY-LOGIN-1 — the same
            // binding check, aliased so a future controlled pilot can scope it
            // to specific routes without editing the global stack.
            'doctor.device.session' => EnsureDoctorDeviceSession::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
