<?php

use App\Console\Commands\AssignOwnerRoleCommand;
use App\Console\Commands\PatientDocumentsAuditCommand;
use App\Console\Commands\PatientDocumentsPruneTempCommand;
use App\Console\Commands\PruneInventoryAnalyticsSummaryCommand;
use App\Console\Commands\RefreshInventoryAnalyticsSummaryCommand;
use App\Modules\ClinicVisit\Middleware\EnsureVisitRoomAssigned;
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
        $middleware->web(append: [
            TouchOnlineContextLastSeen::class,
            EnsureRmeOnlineContext::class,
        ]);

        $middleware->alias([
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
            // Hotfix Sprint 60.8 — RME room-assignment gate before examination.
            'visit.room' => EnsureVisitRoomAssigned::class,
            // Sprint 66.0 — doctor/admin online context gate (alias for selective use).
            'rme.online-context' => EnsureRmeOnlineContext::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
