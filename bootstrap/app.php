<?php

use App\Console\Commands\AssignOwnerRoleCommand;
use App\Console\Commands\PruneInventoryAnalyticsSummaryCommand;
use App\Console\Commands\RefreshInventoryAnalyticsSummaryCommand;
use App\Modules\ClinicVisit\Middleware\EnsureVisitRoomAssigned;
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
        PruneInventoryAnalyticsSummaryCommand::class,
        RefreshInventoryAnalyticsSummaryCommand::class,
    ])
    ->withMiddleware(function (Middleware $middleware) {
        // Spatie Permission middleware aliases (TASK-0105).
        $middleware->alias([
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
            // Hotfix Sprint 60.8 — RME room-assignment gate before examination.
            'visit.room' => EnsureVisitRoomAssigned::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
