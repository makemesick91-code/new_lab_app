<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\AccessControlSeedSnapshot;

/*
|--------------------------------------------------------------------------
| CI-PERF-01 — the access-control seed snapshot is equivalent to the seeders
|--------------------------------------------------------------------------
| seedAccessControl() restores a per-process snapshot instead of re-running
| PermissionSeeder and RoleSeeder before every test. This suite is what makes
| that safe: the restored state must equal what the real seeders write, and
| both must equal an oracle built from the seeders' own constants — so a
| capture that went wrong cannot certify itself.
*/

/** Empty the three tables the seeders write, children first. */
function snapshotTestWipeAccessControl(): void
{
    DB::table(config('permission.table_names.role_has_permissions'))->delete();
    DB::table(config('permission.table_names.roles'))->delete();
    DB::table(config('permission.table_names.permissions'))->delete();
}

/**
 * The state the seeders are SUPPOSED to produce, from their constants alone.
 *
 * @return array{permissions: list<array{0: string, 1: string}>, roles: list<array{0: string, 1: string}>, grants: list<array{0: string, 1: string, 2: string, 3: string}>}
 */
function snapshotTestOracle(): array
{
    $permissions = array_values(array_unique(PermissionSeeder::PERMISSIONS));
    $grants = [];
    foreach (RoleSeeder::ROLE_PERMISSIONS as $role => $granted) {
        foreach (array_unique($granted === '*' ? $permissions : $granted) as $permission) {
            $grants[] = [$role, 'web', $permission, 'web'];
        }
    }
    sort($grants);

    return [
        'permissions' => array_map(fn (string $name): array => [$name, 'web'], $permissions),
        'roles' => array_map(fn (string $name): array => [$name, 'web'], array_keys(RoleSeeder::ROLE_PERMISSIONS)),
        'grants' => $grants,
    ];
}

beforeEach(function () {
    // Every case starts from "no snapshot yet", whatever ran earlier in this
    // process; the first seed of each case is therefore the real seeders.
    AccessControlSeedSnapshot::forget();
});

afterEach(function () {
    AccessControlSeedSnapshot::forget();
});

it('restores exactly the rows the real seeders write, in the same id order', function () {
    seedAccessControl();
    expect(AccessControlSeedSnapshot::lastPath())->toBe(AccessControlSeedSnapshot::PATH_SEEDERS);
    $fromSeeders = AccessControlSeedSnapshot::capture();

    snapshotTestWipeAccessControl();
    seedAccessControl();
    expect(AccessControlSeedSnapshot::lastPath())->toBe(AccessControlSeedSnapshot::PATH_SNAPSHOT);
    $fromSnapshot = AccessControlSeedSnapshot::capture();

    // capture() lists rows in id order, so equality also proves the database
    // handed out ids in the order the seeders produced them.
    expect($fromSnapshot)->toBe($fromSeeders)
        ->and($fromSeeders)->toBe(snapshotTestOracle());
});

it('lets every role resolve exactly its declared permissions after a restore', function () {
    seedAccessControl();
    snapshotTestWipeAccessControl();
    seedAccessControl();
    expect(AccessControlSeedSnapshot::lastPath())->toBe(AccessControlSeedSnapshot::PATH_SNAPSHOT);

    $all = array_values(array_unique(PermissionSeeder::PERMISSIONS));
    foreach (RoleSeeder::ROLE_PERMISSIONS as $role => $granted) {
        $user = User::factory()->create();
        $user->assignRole($role);

        $expected = array_values(array_unique($granted === '*' ? $all : $granted));
        sort($expected);
        $actual = $user->getAllPermissions()->pluck('name')->sort()->values()->all();

        expect($actual)->toBe($expected, "role {$role}");
    }
});

it('clears a permission cache that was warmed while the tables were empty', function () {
    seedAccessControl();
    snapshotTestWipeAccessControl();

    // Warm the cache with the EMPTY permission set, as a test that looked up a
    // permission before seeding would.
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    expect(app(PermissionRegistrar::class)->getPermissions())->toHaveCount(0);

    seedAccessControl();
    expect(AccessControlSeedSnapshot::lastPath())->toBe(AccessControlSeedSnapshot::PATH_SNAPSHOT);

    $role = array_key_first(array_filter(RoleSeeder::ROLE_PERMISSIONS, fn ($p) => is_array($p) && $p !== []));
    $permission = RoleSeeder::ROLE_PERMISSIONS[$role][0];
    $user = User::factory()->create();
    $user->assignRole($role);

    expect($user->can($permission))->toBeTrue("a stale empty cache would deny {$permission} to {$role}");
});

it('falls back to the real seeders when a table already holds rows', function () {
    seedAccessControl();
    snapshotTestWipeAccessControl();

    // A permission the seeders also write already exists: restoring the
    // snapshot blindly would collide on its unique name.
    $existing = PermissionSeeder::PERMISSIONS[0];
    Permission::create(['name' => $existing, 'guard_name' => 'web']);

    seedAccessControl();

    expect(AccessControlSeedSnapshot::lastPath())->toBe(AccessControlSeedSnapshot::PATH_SEEDERS)
        ->and(AccessControlSeedSnapshot::capture())->toBe(snapshotTestOracle())
        ->and(Permission::where('name', $existing)->count())->toBe(1);
});

it('never captures a row the test created before seeding', function () {
    // A test's own fixture must not leak into every later test of the process.
    Permission::create(['name' => 'snapshot-test-only-fixture', 'guard_name' => 'web']);
    seedAccessControl();
    expect(AccessControlSeedSnapshot::lastPath())->toBe(AccessControlSeedSnapshot::PATH_SEEDERS);

    // Empty tables again: a polluted snapshot would be restored here.
    snapshotTestWipeAccessControl();
    seedAccessControl();

    expect(Permission::where('name', 'snapshot-test-only-fixture')->exists())->toBeFalse()
        ->and(AccessControlSeedSnapshot::capture())->toBe(snapshotTestOracle());
});

it('keeps a second call in the same test idempotent', function () {
    seedAccessControl();
    seedAccessControl();

    expect(AccessControlSeedSnapshot::lastPath())->toBe(AccessControlSeedSnapshot::PATH_SEEDERS)
        ->and(AccessControlSeedSnapshot::capture())->toBe(snapshotTestOracle());
});

it('stamps restored rows with the current clock, as the seeders do', function () {
    $clock = '2031-02-03 04:05:06';

    Carbon::setTestNow($clock);
    try {
        seedAccessControl();
        $seeded = DB::table('permissions')->pluck('created_at')->merge(DB::table('roles')->pluck('updated_at'))->unique()->values()->all();

        snapshotTestWipeAccessControl();
        seedAccessControl();
        expect(AccessControlSeedSnapshot::lastPath())->toBe(AccessControlSeedSnapshot::PATH_SNAPSHOT);
        $restored = DB::table('permissions')->pluck('created_at')->merge(DB::table('roles')->pluck('updated_at'))->unique()->values()->all();
    } finally {
        Carbon::setTestNow();
    }

    expect(array_map(fn ($v) => Carbon::parse($v)->format('Y-m-d H:i:s'), $seeded))->toBe([$clock])
        ->and(array_map(fn ($v) => Carbon::parse($v)->format('Y-m-d H:i:s'), $restored))->toBe([$clock]);
});

it('leaves the real seeders to the tests that call them directly', function () {
    // seedAccessControl() is the only caller of the snapshot; the seeders
    // themselves are untouched and still run on every process's first seed.
    $helper = file_get_contents(base_path('tests/Pest.php'));

    expect($helper)->toContain('AccessControlSeedSnapshot::seed(test());')
        ->and(file_get_contents(base_path('database/seeders/PermissionSeeder.php')))->not->toContain('AccessControlSeedSnapshot')
        ->and(file_get_contents(base_path('database/seeders/RoleSeeder.php')))->not->toContain('AccessControlSeedSnapshot');
});
