<?php

use App\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * FIX-LOGIN-REDIRECT-RUNTIME-PERMISSIONS — authenticated authorization + runtime
 * cache smoke command.
 */
function seedSmokeRolesAndPermissions(): void
{
    foreach (['view dashboard', 'view_owner_dashboard', 'view_lab_orders'] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }

    Role::findOrCreate('Super Admin', 'web');
    Role::findOrCreate('Admin Lab', 'web')->givePermissionTo('view_lab_orders');
}

test('smoke reports GO when the runtime cache, permission cache, and role authorization are correct', function () {
    seedSmokeRolesAndPermissions();

    User::factory()->create()->assignRole('Admin Lab');
    User::factory()->create()->assignRole('Super Admin');

    $this->artisan('deploy:auth-landing-smoke --json')->assertExitCode(0);

    $this->artisan('deploy:auth-landing-smoke')
        ->expectsOutputToContain('DEPLOY AUTH LANDING SMOKE: GO')
        ->assertExitCode(0);
});

test('smoke exercises the runtime cache and permission cache probes', function () {
    seedSmokeRolesAndPermissions();

    $this->artisan('deploy:auth-landing-smoke')
        ->expectsOutputToContain('runtime_cache')
        ->expectsOutputToContain('permission_cache')
        ->assertExitCode(0);
});

test('smoke degrades to WATCH (not a fake GO) when no role accounts exist', function () {
    seedSmokeRolesAndPermissions();

    // No users assigned any role → role checks are skipped as WATCH.
    $this->artisan('deploy:auth-landing-smoke')
        ->expectsOutputToContain('DEPLOY AUTH LANDING SMOKE: WATCH')
        ->assertExitCode(0);
});

test('smoke fails on WATCH only under strict + fail-on-warning', function () {
    seedSmokeRolesAndPermissions();

    $this->artisan('deploy:auth-landing-smoke --strict --fail-on-warning')->assertExitCode(1);
});
