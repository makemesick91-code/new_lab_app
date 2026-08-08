<?php

use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;

beforeEach(function () {
    seedAccessControl();
});

it('lists permissions for an authorized user', function () {
    $response = $this->actingAs(userWith(['manage permissions']))
        ->get(route('settings.permissions.index'))
        ->assertOk()
        ->assertViewIs('settings.permissions.index');

    // CICD-FIX-4 — this used to assert `assertSee('manage users')`, which only
    // held because of the database collation, not because of any product rule.
    // The index is an alphabetical listing paginated at 50, and there are 142
    // seeded permissions. SQLite sorts BINARY, where the space in 'manage users'
    // (0x20) sorts before the underscore in 'manage_lab_pickups' (0x5F), putting
    // it 36th — page 1. PostgreSQL's en_US.utf8 collation de-weights those
    // separators, so 'manage_lab_pickups' compares as 'managelabpickups' and
    // sorts BEFORE 'manageusers', pushing 'manage users' to 66th — page 2.
    //
    // WHICH page a row lands on is a collation detail. That the page renders the
    // rows the paginator actually resolved is the contract, so assert that.
    $page = $response->viewData('permissions');

    expect($page->total())->toBeGreaterThan(0)
        ->and($page->count())->toBeGreaterThan(0);

    foreach ($page as $permission) {
        $response->assertSee($permission->name);
    }
});

it('serves every permission exactly once across the paginated listing', function () {
    // Replaces the reachability the old page-1 assertion was really after, in a
    // way no collation can change: 'manage users' must be listed, and paging
    // must serve each row once — never twice, never dropped between pages.
    $admin = superAdmin();
    $seen = [];
    $pageNumber = 1;

    do {
        $paginator = $this->actingAs($admin)
            ->get(route('settings.permissions.index', ['page' => $pageNumber]))
            ->assertOk()
            ->viewData('permissions');

        foreach ($paginator as $permission) {
            $seen[] = $permission->name;
        }

        $pageNumber++;
    } while ($paginator->hasMorePages());

    expect($seen)->toContain('manage users')
        ->and(count($seen))->toBe(Permission::query()->count())
        ->and(array_unique($seen))->toHaveCount(count($seen));
});

it('keeps pagination stable when two permissions share the same name', function () {
    // `name` is unique only together with `guard_name`, so duplicates are legal.
    // Inserted through the table because only the `web` guard is configured.
    DB::table('permissions')->insert([
        'name' => 'manage users',
        'guard_name' => 'api',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $admin = superAdmin();

    $collectIds = function () use ($admin): array {
        $ids = [];
        $pageNumber = 1;

        do {
            $paginator = $this->actingAs($admin)
                ->get(route('settings.permissions.index', ['page' => $pageNumber]))
                ->assertOk()
                ->viewData('permissions');

            foreach ($paginator as $permission) {
                $ids[] = $permission->id;
            }

            $pageNumber++;
        } while ($paginator->hasMorePages());

        return $ids;
    };

    $first = $collectIds();
    $second = $collectIds();

    // Both same-named rows are served, each exactly once, and repeating the
    // walk yields an identical composition in an identical order.
    expect(array_unique($first))->toHaveCount(count($first))
        ->and(count($first))->toBe(Permission::query()->count())
        ->and($second)->toBe($first);
});

it('filters permissions by search', function () {
    $this->actingAs(superAdmin())
        ->get(route('settings.permissions.index', ['search' => 'invoices']))
        ->assertOk()
        ->assertSee('manage invoices')
        ->assertDontSee('manage deliveries');
});

it('denies permission listing without the manage permissions permission', function () {
    $this->actingAs(userWith(['manage users']))
        ->get(route('settings.permissions.index'))
        ->assertForbidden();
});

it('grants the Super Admin access to every settings area via gate bypass', function () {
    $admin = superAdmin();

    $this->actingAs($admin)->get(route('settings.users.index'))->assertOk();
    $this->actingAs($admin)->get(route('settings.roles.index'))->assertOk();
    $this->actingAs($admin)->get(route('settings.permissions.index'))->assertOk();
});
