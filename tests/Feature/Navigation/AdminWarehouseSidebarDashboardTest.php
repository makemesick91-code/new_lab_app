<?php

use App\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Sprint 58.2 — Admin Warehouse sidebar Dashboard replacement hotfix.
 *
 * For role "Admin Warehouse" the single main sidebar Dashboard entry points to
 * inventory.dashboard (/inventory/dashboard); all other roles keep /dashboard.
 * Navigation only — no authorization/redirect changes.
 */
function renderSidebarFor(User $user): string
{
    test()->actingAs($user);

    return view('layouts.partials.sidebar')->render();
}

test('admin warehouse sidebar main dashboard links to inventory dashboard', function () {
    Role::findOrCreate('Admin Warehouse', 'web');
    Permission::findOrCreate('view_inventory', 'web');

    $user = User::factory()->create();
    $user->assignRole('Admin Warehouse');
    $user->givePermissionTo('view_inventory');

    $html = renderSidebarFor($user);

    expect($html)->toContain('/inventory/dashboard"');
});

test('admin warehouse sidebar has no main dashboard link to /dashboard', function () {
    Role::findOrCreate('Admin Warehouse', 'web');

    $user = User::factory()->create();
    $user->assignRole('Admin Warehouse');

    $html = renderSidebarFor($user);

    // The /login brand link uses route('dashboard'); the *menu* must not link there.
    expect($html)->not->toContain('href="'.route('dashboard').'"');
});

test('admin warehouse sidebar does not render a duplicate inventory dashboard entry', function () {
    Role::findOrCreate('Admin Warehouse', 'web');
    Permission::findOrCreate('view_inventory', 'web');

    $user = User::factory()->create();
    $user->assignRole('Admin Warehouse');
    $user->givePermissionTo('view_inventory'); // makes the Persediaan group visible

    $html = renderSidebarFor($user);

    // The "Dashboard Inventory" sub-item (which also targets /inventory/dashboard)
    // is hidden for Admin Warehouse so there is no duplicate dashboard menu entry.
    expect($html)->not->toContain('Dashboard Inventory');

    // Exactly one "Dashboard" menu label (the top-level menu item).
    expect(substr_count($html, '<span>Dashboard</span>'))->toBe(1)
        ->and($html)->toContain('/inventory/dashboard"');
});

test('non-warehouse role keeps the main dashboard link to /dashboard', function () {
    Role::findOrCreate('Owner', 'web');
    Permission::findOrCreate('view dashboard', 'web');

    $user = User::factory()->create();
    $user->assignRole('Owner');
    $user->givePermissionTo('view dashboard');

    $html = renderSidebarFor($user);

    expect($html)->toContain('href="'.route('dashboard').'"')
        ->and($html)->not->toContain('Dashboard Owner'); // plain "Dashboard" label, no owner-dashboard perm
});
