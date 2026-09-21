<?php

use App\Console\Commands\AssignOwnerRoleCommand;
use App\Console\Commands\PatientDocumentsAuditCommand;
use App\Console\Commands\PatientDocumentsPruneTempCommand;
use App\Console\Commands\PruneInventoryAnalyticsSummaryCommand;
use App\Console\Commands\RefreshInventoryAnalyticsSummaryCommand;
use App\Exceptions\ForbiddenProductionCommandException;
use App\Http\Middleware\AttachRequestCorrelationContext;
use App\Modules\ClinicVisit\Middleware\EnsureVisitRoomAssigned;
use App\Modules\DoctorAccess\Middleware\EnsureDoctorSessionLease;
use App\Modules\DoctorDevice\Middleware\EnsureDoctorDeviceSession;
use App\Modules\FrontOfficeDevice\Middleware\EnsureFrontOfficeDeviceSession;
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

        // ORDER INSIDE THIS LIST IS LOAD-BEARING, NOT COSMETIC. Every entry
        // here runs after StartSession, so each one can read the session and
        // the authenticated user — but they run in the order written, and a
        // middleware that tears a session down must run BEFORE any middleware
        // that writes on the way past.
        $middleware->web(append: [
            // DOCTOR-ACCESS-SINGLE-SESSION-BRANCH-LOCK-1 — one active session
            // per doctor, and the branch that session was established under.
            // A NO-OP while its flag is off and on any session driver whose
            // liveness cannot be observed: the first line reads that and
            // returns. A session carrying no lease token is ALWAYS passed
            // through, so nothing that authenticates any other way is affected.
            //
            // FIRST, DELIBERATELY. TouchOnlineContextLastSeen below refreshes
            // presence unconditionally, and clinic-room occupancy keys off that
            // row — appended later, the very request that evicts a doctor would
            // first refresh their presence and leave a ghost holding a room.
            // EnsureRmeOnlineContext below redirects a doctor with no live
            // context to the branch selector, so appended after it an evicted
            // doctor would be redirected and this check would never run.
            // Prepending is impossible: group prepends land before
            // StartSession, where there is no session and no user.
            EnsureDoctorSessionLease::class,
            TouchOnlineContextLastSeen::class,
            EnsureRmeOnlineContext::class,
            // REVISION-DOCTOR-AUTO-DEVICE-APPROVAL-APP-ONLY-LOGIN-1 — session
            // to device binding. A NO-OP while enforcement is off: its first
            // line reads one config value and returns, so no protected route
            // requires device proof today. Registered globally rather than on a
            // route group because a revoked tablet has to stop working
            // EVERYWHERE, and an enumerated list of protected routes is a list
            // somebody eventually forgets to extend.
            //
            // CORRECTION, recorded rather than quietly fixed: "stop working
            // EVERYWHERE" describes the ROUTE coverage this global registration
            // buys, and nothing more. Being LAST in this list, it does not stop
            // a doomed request from having already refreshed presence and
            // resolved an online context on its way here. It is left last
            // because a dead lease and a revoked device are independent reasons
            // with independent audit actions, and whichever fires first tears
            // the session down.
            EnsureDoctorDeviceSession::class,

            // REVISION-FRONT-OFFICE-BRANCH-DEVICE-LOCK-1 — the same idea for
            // the four approved front-desk accounts: re-check that the session
            // is still on an approved device that still belongs to the branch
            // that account is pinned to.
            //
            // A NO-OP while the flag is off, and a no-op for every account
            // outside the four-id cohort even when it is on — including the four
            // OTHER Front Office accounts, which is the property this sprint
            // exists to preserve.
            //
            // Global for the doctor gate's reason: a disabled tablet has to stop
            // working everywhere, and an enumerated route list is a list
            // somebody eventually forgets to extend. Placed after the doctor
            // gate because the two are independent reasons with independent
            // audit actions, and no account is ever in both cohorts — a Front
            // Office clerk holds no doctor record.
            EnsureFrontOfficeDeviceSession::class,
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
        // DOCTOR-PWA-GLOBAL-ROLLOUT-READINESS-1 — a refused console command
        // must not be written to the application log. Half the reason the REPL
        // is forbidden is that it writes ERROR records which pin the monitoring
        // log signal to WATCH for 24 hours; a guard that logged an ERROR on
        // every refusal would cause the exact harm it prevents. The refusal is
        // reported on the console instead.
        $exceptions->dontReport([
            ForbiddenProductionCommandException::class,
        ]);
    })->create();
