<?php

declare(strict_types=1);

/*
| FEATURE-DAILY-BRANCH-CONTEXT-LOCK-1 — shared fixtures for the daily
| working-branch lock suites.
|
| Deliberately NOT added to tests/Pest.php. These helpers are only meaningful to
| this feature, and the global helper file is already the busiest shared surface
| in the suite.
*/

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\RmeOnlineContext\Interfaces\BranchChangeRequestRepositoryInterface;
use App\Modules\RmeOnlineContext\Services\BranchChangeApprovalService;
use App\Modules\RmeOnlineContext\Services\DailyBranchContextService;
use App\Modules\RmeOnlineContext\Services\UserOnlineContextService;
use App\Support\Clinical\ClinicalClock;

if (! function_exists('dbcBranch')) {
    /**
     * An active, RME-enabled branch — the only kind a locked role may work in.
     */
    function dbcBranch(string $code): Branch
    {
        return Branch::factory()->create([
            'code' => $code,
            'name' => 'Cabang '.$code,
            'is_active' => true,
            'is_rme_enabled' => true,
        ]);
    }
}

if (! function_exists('dbcOnline')) {
    function dbcOnline(): UserOnlineContextService
    {
        return app(UserOnlineContextService::class);
    }
}

if (! function_exists('dbcDaily')) {
    function dbcDaily(): DailyBranchContextService
    {
        return app(DailyBranchContextService::class);
    }
}

if (! function_exists('dbcApprovals')) {
    function dbcApprovals(): BranchChangeApprovalService
    {
        return app(BranchChangeApprovalService::class);
    }
}

if (! function_exists('dbcRequests')) {
    function dbcRequests(): BranchChangeRequestRepositoryInterface
    {
        return app(BranchChangeRequestRepositoryInterface::class);
    }
}

if (! function_exists('dbcStart')) {
    /**
     * Start a working context through the REAL service — the same call the HTTP
     * endpoint makes. Tests must never write the context row directly, or they
     * would prove nothing about the guard.
     */
    function dbcStart(User $user, Branch $branch, string $role = 'kasir'): void
    {
        match ($role) {
            'kasir' => dbcOnline()->startKasirSession($user, (int) $branch->id),
            'admin_clinic' => dbcOnline()->startAdminClinicSession($user, (int) $branch->id),
            'perawat' => dbcOnline()->startPerawatSession($user, (int) $branch->id),
        };
    }
}

if (! function_exists('dbcSuperAdmin')) {
    function dbcSuperAdmin(): User
    {
        return userInRole('Super Admin');
    }
}

if (! function_exists('dbcClinicalToday')) {
    function dbcClinicalToday(): string
    {
        return app(ClinicalClock::class)->todayString();
    }
}
