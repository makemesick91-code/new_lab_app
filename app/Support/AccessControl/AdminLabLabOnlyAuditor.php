<?php

namespace App\Support\AccessControl;

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * FIX-ADMIN-LAB-LAB-ONLY-ACCESS — read-only audit + guarded repair helpers that keep
 * the canonical "Admin Lab" role Lab-only and detect / fix live accounts that still
 * carry non-Lab access (direct permissions or a leftover Super Admin role).
 *
 * Design rules:
 *  - Never modifies the primary Super Admin. Demotion refuses to leave zero Super Admins.
 *  - Never touches permission DEFINITIONS (they are shared by other roles).
 *  - Never renders passwords / tokens / KTP / NIK — only id/name/email/role/permission
 *    metadata, which is what an operator needs to act.
 *  - The canonical Lab-only grant is the single source of truth in
 *    {@see RoleSeeder::ROLE_PERMISSIONS} and the revoked set in
 *    {@see RoleSeeder::ADMIN_LAB_REVOKED_NON_LAB}.
 */
class AdminLabLabOnlyAuditor
{
    public const ROLE = 'Admin Lab';

    public const SUPER_ADMIN_ROLE = 'Super Admin';

    /**
     * @return list<string>
     */
    public function canonicalLabPermissions(): array
    {
        return RoleSeeder::ROLE_PERMISSIONS[self::ROLE];
    }

    /**
     * @return list<string>
     */
    public function revokedNonLabPermissions(): array
    {
        return RoleSeeder::ADMIN_LAB_REVOKED_NON_LAB;
    }

    /**
     * Read-only audit of the Admin Lab role definition and every account that
     * carries it (or is named "Lab Admin"). Never mutates state.
     *
     * @return array<string, mixed>
     */
    public function audit(): array
    {
        $role = Role::where('name', self::ROLE)->where('guard_name', 'web')->first();
        $canonical = $this->canonicalLabPermissions();
        $revoked = $this->revokedNonLabPermissions();

        $rolePermissions = $role
            ? $role->permissions->pluck('name')->sort()->values()->all()
            : [];

        $roleExtraNonLab = array_values(array_intersect($rolePermissions, $revoked));
        $roleExtraOther = array_values(array_diff($rolePermissions, $canonical, $revoked));
        $roleMissingLab = array_values(array_diff($canonical, $rolePermissions));

        $anomalies = [];

        if (! $role) {
            $anomalies[] = 'admin_lab_role_missing';
        }
        if ($roleExtraNonLab !== []) {
            $anomalies[] = 'role_holds_revoked_non_lab_permissions';
        }
        if ($roleMissingLab !== []) {
            $anomalies[] = 'role_missing_canonical_lab_permissions';
        }

        $adminLabUsers = [];
        if ($role) {
            foreach (User::role(self::ROLE)->orderBy('id')->get() as $user) {
                $roles = $user->getRoleNames()->values()->all();
                $directRevoked = array_values(array_intersect(
                    $user->getDirectPermissions()->pluck('name')->all(),
                    $revoked,
                ));
                $hasSuperAdmin = in_array(self::SUPER_ADMIN_ROLE, $roles, true);

                if ($hasSuperAdmin) {
                    $anomalies[] = 'admin_lab_user_also_super_admin';
                }
                if ($directRevoked !== []) {
                    $anomalies[] = 'admin_lab_user_holds_revoked_direct_permissions';
                }

                $adminLabUsers[] = [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'roles' => $roles,
                    'has_super_admin' => $hasSuperAdmin,
                    'revoked_direct_permissions' => $directRevoked,
                    // Effective permissions restricted to the revoked set — this is the
                    // leak surface an operator must confirm is empty after repair.
                    'effective_revoked_permissions' => array_values(array_intersect(
                        $user->getAllPermissions()->pluck('name')->all(),
                        $revoked,
                    )),
                ];
            }
        }

        // Named "Lab Admin" operational accounts — audit even if the role is not yet
        // assigned, because the VPS account historically ran as Super Admin.
        $labAdminNamed = [];
        foreach (User::where('name', 'Lab Admin')->orderBy('id')->get() as $user) {
            $roles = $user->getRoleNames()->values()->all();
            $hasSuperAdmin = in_array(self::SUPER_ADMIN_ROLE, $roles, true);
            if ($hasSuperAdmin) {
                $anomalies[] = 'named_lab_admin_is_super_admin';
            }
            $labAdminNamed[] = [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'roles' => $roles,
                'has_super_admin' => $hasSuperAdmin,
                'has_admin_lab_role' => in_array(self::ROLE, $roles, true),
            ];
        }

        $superAdminCount = User::role(self::SUPER_ADMIN_ROLE)->count();
        $anomalies = array_values(array_unique($anomalies));

        $critical = array_values(array_intersect($anomalies, [
            'admin_lab_user_also_super_admin',
            'named_lab_admin_is_super_admin',
        ]));

        $decision = 'GO';
        if ($critical !== []) {
            // Super Admin leakage cannot be auto-resolved without verifying the account
            // identity first — surface as WATCH so the operator runs a guarded demote.
            $decision = 'WATCH';
        }
        if (in_array('admin_lab_role_missing', $anomalies, true)
            || $roleExtraNonLab !== []
            || $roleMissingLab !== []) {
            // Role definition drift is a hard NO-GO — the seeder/sync must run.
            $decision = 'NO-GO';
        }

        return [
            'generated_at' => now()->toIso8601String(),
            'environment' => app()->environment(),
            'role' => self::ROLE,
            'role_exists' => (bool) $role,
            'role_permission_count' => count($rolePermissions),
            'canonical_lab_permission_count' => count($canonical),
            'role_permissions' => $rolePermissions,
            'role_extra_non_lab' => $roleExtraNonLab,
            'role_extra_other' => $roleExtraOther,
            'role_missing_lab' => $roleMissingLab,
            'super_admin_count' => $superAdminCount,
            'admin_lab_users' => $adminLabUsers,
            'named_lab_admin_accounts' => $labAdminNamed,
            'summary' => [
                'anomalies' => count($anomalies),
                'anomaly_codes' => $anomalies,
                'critical_codes' => $critical,
                'decision' => $decision,
            ],
        ];
    }

    /**
     * Idempotently re-sync ONLY the Admin Lab role to its canonical Lab-only grant.
     * Does not touch any other role or permission definition.
     *
     * @return array<string, mixed>
     */
    public function syncRole(): array
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $role = Role::firstOrCreate(['name' => self::ROLE, 'guard_name' => 'web']);
        $before = $role->permissions->pluck('name')->sort()->values()->all();

        $role->syncPermissions($this->canonicalLabPermissions());
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $after = $role->fresh()->permissions->pluck('name')->sort()->values()->all();

        return [
            'role' => self::ROLE,
            'removed' => array_values(array_diff($before, $after)),
            'added' => array_values(array_diff($after, $before)),
            'permission_count' => count($after),
        ];
    }

    /**
     * Strip revoked non-Lab DIRECT permissions from every Admin Lab account. Role
     * memberships are untouched (a legitimate extra role stays); only stray direct
     * grants of the revoked non-Lab set are revoked.
     *
     * @return list<array<string, mixed>>
     */
    public function stripDirectRevokedFromAdminLabUsers(): array
    {
        $revoked = $this->revokedNonLabPermissions();
        $changes = [];

        foreach (User::role(self::ROLE)->orderBy('id')->get() as $user) {
            $directRevoked = array_values(array_intersect(
                $user->getDirectPermissions()->pluck('name')->all(),
                $revoked,
            ));

            if ($directRevoked === []) {
                continue;
            }

            foreach ($directRevoked as $permission) {
                $user->revokePermissionTo($permission);
            }

            $changes[] = [
                'id' => $user->id,
                'name' => $user->name,
                'revoked_direct_permissions' => $directRevoked,
            ];
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $changes;
    }

    /**
     * Guarded demotion of a verified operational Lab account from Super Admin to
     * Admin Lab. Refuses to leave zero Super Admins so the platform admin is never
     * orphaned. Only the Super Admin role is removed; every other role is preserved.
     *
     * @return array<string, mixed>
     *
     * @throws \RuntimeException when the demotion is unsafe.
     */
    public function demoteSuperAdminToAdminLab(int $userId): array
    {
        return DB::transaction(function () use ($userId) {
            /** @var User|null $user */
            $user = User::whereKey($userId)->lockForUpdate()->first();

            if (! $user) {
                throw new \RuntimeException("User #{$userId} not found.");
            }

            $rolesBefore = $user->getRoleNames()->values()->all();

            if (! in_array(self::SUPER_ADMIN_ROLE, $rolesBefore, true)) {
                throw new \RuntimeException("User #{$userId} does not hold the Super Admin role — nothing to demote.");
            }

            $superAdminCount = User::role(self::SUPER_ADMIN_ROLE)->count();
            if ($superAdminCount <= 1) {
                throw new \RuntimeException('Refusing to demote the only Super Admin account. Create another Super Admin first.');
            }

            $user->assignRole(self::ROLE);
            $user->removeRole(self::SUPER_ADMIN_ROLE);

            app(PermissionRegistrar::class)->forgetCachedPermissions();
            $user = $user->fresh();

            return [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'roles_before' => $rolesBefore,
                'roles_after' => $user->getRoleNames()->values()->all(),
                'super_admin_count_after' => User::role(self::SUPER_ADMIN_ROLE)->count(),
            ];
        });
    }
}
