<?php

declare(strict_types=1);

namespace Tests\Support;

use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * CI-PERF-01 — the access-control seed at a fraction of its cost, with the
 * same resulting state.
 *
 * WHY: seedAccessControl() runs PermissionSeeder and RoleSeeder through
 * `db:seed` before nearly every feature test: 186 firstOrCreate queries, 19
 * roles and one syncPermissions per role. Measured on PostgreSQL 16 it costs
 * ~889 ms per test against ~37 ms for an empty test, and the critical gate
 * calls it ~3,400 times — roughly 50 of its ~118 minutes.
 *
 * HOW: the first call in a process whose three tables are empty runs the REAL
 * seeders and captures what they wrote, by name. Every later call on empty
 * tables writes those same names back with three bulk inserts. Anything else
 * (a table already holding rows, no snapshot yet) runs the real seeders, so a
 * test that seeds twice or pre-creates a permission keeps exactly the old
 * behaviour.
 *
 * WHAT IS IDENTICAL: the rows of `permissions`, `roles` and
 * `role_has_permissions` (names, guards, every grant), their id order, their
 * timestamps (the current clock, honouring Carbon::setTestNow, as Eloquent
 * does), and the permission cache, which is cleared exactly as the seeders
 * clear it. IDs are assigned by the database, never copied, so sequences
 * behave as they would under real seeding.
 *
 * WHAT DIFFERS, ON PURPOSE: the restore fires no Eloquent model events and
 * no `db:seed` console events. Spatie events are disabled in this
 * application, and no test asserts on events raised by seeding.
 *
 * The real seeders stay fully exercised: they run on the first call of every
 * test process, and the nine tests that seed directly bypass this helper.
 * AccessControlSeedSnapshotTest pins the equivalence against both the real
 * seeders and the seeders' own constants.
 */
final class AccessControlSeedSnapshot
{
    public const PATH_SEEDERS = 'seeders';

    public const PATH_SNAPSHOT = 'snapshot';

    /**
     * @var array{permissions: list<array{0: string, 1: string}>, roles: list<array{0: string, 1: string}>, grants: list<array{0: string, 1: string, 2: string, 3: string}>}|null
     */
    private static ?array $snapshot = null;

    private static ?string $lastPath = null;

    /**
     * @param  object  $test  the running test case, as `test()` returns it (Pest
     *                        hands back a proxy that forwards to the TestCase)
     */
    public static function seed(object $test): void
    {
        $empty = self::tablesEmpty();

        if ($empty && self::$snapshot !== null) {
            self::restore(self::$snapshot);
            self::$lastPath = self::PATH_SNAPSHOT;

            return;
        }

        $test->seed([PermissionSeeder::class, RoleSeeder::class]);
        self::$lastPath = self::PATH_SEEDERS;

        // Only a seed into EMPTY tables is the seeders' pure output; anything
        // else would capture rows the test created itself.
        if ($empty) {
            self::$snapshot = self::capture();
        }
    }

    /** Which path the last seed() call took — for the contract test. */
    public static function lastPath(): ?string
    {
        return self::$lastPath;
    }

    /** Drop the snapshot so the next seed() runs the real seeders again. */
    public static function forget(): void
    {
        self::$snapshot = null;
        self::$lastPath = null;
    }

    /**
     * The access-control state as names, in id order — what the contract test
     * compares and what the restore writes back.
     *
     * @return array{permissions: list<array{0: string, 1: string}>, roles: list<array{0: string, 1: string}>, grants: list<array{0: string, 1: string, 2: string, 3: string}>}
     */
    public static function capture(): array
    {
        [$permissionsTable, $rolesTable, $grantsTable, $permissionKey, $roleKey] = self::schema();

        $permissions = DB::table($permissionsTable)->orderBy('id')->get(['id', 'name', 'guard_name']);
        $roles = DB::table($rolesTable)->orderBy('id')->get(['id', 'name', 'guard_name']);

        $permissionById = [];
        foreach ($permissions as $row) {
            $permissionById[$row->id] = [(string) $row->name, (string) $row->guard_name];
        }
        $roleById = [];
        foreach ($roles as $row) {
            $roleById[$row->id] = [(string) $row->name, (string) $row->guard_name];
        }

        $grants = [];
        foreach (DB::table($grantsTable)->get([$roleKey, $permissionKey]) as $row) {
            $role = $roleById[$row->{$roleKey}];
            $permission = $permissionById[$row->{$permissionKey}];
            $grants[] = [$role[0], $role[1], $permission[0], $permission[1]];
        }
        sort($grants);

        return [
            'permissions' => array_values($permissionById),
            'roles' => array_values($roleById),
            'grants' => $grants,
        ];
    }

    private static function tablesEmpty(): bool
    {
        [$permissionsTable, $rolesTable, $grantsTable] = self::schema();

        return ! DB::table($permissionsTable)->exists()
            && ! DB::table($rolesTable)->exists()
            && ! DB::table($grantsTable)->exists();
    }

    /**
     * @param  array{permissions: list<array{0: string, 1: string}>, roles: list<array{0: string, 1: string}>, grants: list<array{0: string, 1: string, 2: string, 3: string}>}  $snapshot
     */
    private static function restore(array $snapshot): void
    {
        [$permissionsTable, $rolesTable, $grantsTable, $permissionKey, $roleKey] = self::schema();

        // Eloquent stamps created_at/updated_at with Date::now(); so does this.
        $now = Date::now();
        $named = static fn (array $pair): array => [
            'name' => $pair[0],
            'guard_name' => $pair[1],
            'created_at' => $now,
            'updated_at' => $now,
        ];

        // Inserted in captured id order, one row per chunk entry, so the
        // database hands out ids in the same order the seeders produced.
        foreach (array_chunk(array_map($named, $snapshot['permissions']), 100) as $chunk) {
            DB::table($permissionsTable)->insert($chunk);
        }
        foreach (array_chunk(array_map($named, $snapshot['roles']), 100) as $chunk) {
            DB::table($rolesTable)->insert($chunk);
        }

        $permissionIds = self::idsByNameAndGuard($permissionsTable);
        $roleIds = self::idsByNameAndGuard($rolesTable);

        $rows = array_map(static fn (array $grant): array => [
            $roleKey => $roleIds[$grant[0]."\0".$grant[1]],
            $permissionKey => $permissionIds[$grant[2]."\0".$grant[3]],
        ], $snapshot['grants']);
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table($grantsTable)->insert($chunk);
        }

        // The seeders clear the permission cache before and after writing; a
        // cache warmed while the tables were empty must not survive.
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /** @return array<string, int> keyed "name\0guard" */
    private static function idsByNameAndGuard(string $table): array
    {
        $ids = [];
        foreach (DB::table($table)->get(['id', 'name', 'guard_name']) as $row) {
            $ids[$row->name."\0".$row->guard_name] = (int) $row->id;
        }

        return $ids;
    }

    /** @return array{0: string, 1: string, 2: string, 3: string, 4: string} */
    private static function schema(): array
    {
        $tables = config('permission.table_names');
        $columns = config('permission.column_names');

        return [
            $tables['permissions'],
            $tables['roles'],
            $tables['role_has_permissions'],
            $columns['permission_pivot_key'] ?? 'permission_id',
            $columns['role_pivot_key'] ?? 'role_id',
        ];
    }
}
