<?php

namespace App\Support\AccessControl;

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * DAENGTISIAMS-SUNU-FINAL-RELEASE-CANDIDATE-1 / D2 — moving the four front-desk
 * accounts onto the merged role, as a governed command rather than SQL typed at
 * a production prompt.
 *
 * READ-ONLY BY DEFAULT. `audit()` mutates nothing; `migrate()` is the only
 * writer and the command will not call it without an explicit `--apply`.
 *
 * WHAT IT REFUSES, AND WHY EACH REFUSAL EARNS ITS PLACE.
 *
 *  - Front Office missing, or holding fewer than the full union: migrating into
 *    an under-seeded role would silently STRIP capability from a working
 *    account. Production already carries role id 10 with a stale 5-permission
 *    subset, so this is the live case, not a hypothetical one — run the seeder
 *    first.
 *  - A user holding any role beyond the two legacy ones: merging is defined for
 *    front-desk-only accounts. Someone who is also a Doctor has clinical
 *    authority that this migration was never reasoned about, so it stops and
 *    says so instead of guessing.
 *  - Super Admin: never touched, by name, at every entry point.
 *
 * WHAT IT DELIBERATELY DOES NOT TOUCH.
 *
 * `trx_daily_branch_contexts` rows are keyed on (user_id, clinical_date) and
 * carry no role column, so today's lock survives the migration intact — which
 * matters, because three of the four accounts were locked to a branch the day
 * before this shipped. Online-context rows are likewise left alone: an ex-Kasir
 * account simply re-selects once through the admin-clinic selector, because
 * Front Office resolves through that context (see FrontOfficeRole).
 */
class FrontOfficeMigrationAuditor
{
    public const SUPER_ADMIN_ROLE = 'Super Admin';

    /** @return array<string, mixed> */
    public function audit(): array
    {
        $role = Role::where('name', FrontOfficeRole::NAME)->first();
        $expected = $this->expectedPermissions();
        $actual = $role ? $role->permissions->pluck('name')->sort()->values()->all() : [];

        $candidates = [];
        foreach ($this->legacyHolders() as $user) {
            $candidates[] = $this->describe($user);
        }

        $missing = array_values(array_diff($expected, $actual));

        return [
            'role_exists' => $role !== null,
            'role_id' => $role?->id,
            'expected_permission_count' => count($expected),
            'actual_permission_count' => count($actual),
            'missing_permissions' => $missing,
            'role_ready' => $role !== null && $missing === [],
            'candidates' => $candidates,
            'migratable' => count(array_filter($candidates, fn ($c) => $c['blocked_by'] === null && ! $c['already_migrated'])),
            'blocked' => count(array_filter($candidates, fn ($c) => $c['blocked_by'] !== null)),
        ];
    }

    /**
     * Move ONE account. Transactional, locked, idempotent.
     *
     * @return array<string, mixed>
     */
    public function migrate(int $userId): array
    {
        return DB::transaction(function () use ($userId) {
            /** @var User|null $user */
            $user = User::whereKey($userId)->lockForUpdate()->first();

            if (! $user) {
                throw new RuntimeException("User #{$userId} not found.");
            }

            $before = $user->getRoleNames()->sort()->values()->all();

            if (in_array(self::SUPER_ADMIN_ROLE, $before, true)) {
                throw new RuntimeException("Refusing to touch the Super Admin account #{$userId}.");
            }

            // Re-asserted under the lock, not merely at audit time: the role
            // could have been re-seeded, or emptied, between the two.
            $missing = array_values(array_diff(
                $this->expectedPermissions(),
                Role::where('name', FrontOfficeRole::NAME)->firstOrFail()
                    ->permissions->pluck('name')->all(),
            ));

            if ($missing !== []) {
                throw new RuntimeException(
                    'Front Office is missing '.count($missing).' permission(s); run RoleSeeder before migrating.',
                );
            }

            if ($before === [FrontOfficeRole::NAME]) {
                return ['id' => $user->id, 'name' => $user->name, 'roles_before' => $before,
                    'roles_after' => $before, 'changed' => false];
            }

            $extra = array_diff($before, FrontOfficeRole::LEGACY, [FrontOfficeRole::NAME]);

            if ($extra !== []) {
                throw new RuntimeException(
                    "User #{$userId} also holds ".implode(', ', $extra).
                    ' — migration is defined for front-desk-only accounts. Resolve by hand.',
                );
            }

            if (array_intersect($before, FrontOfficeRole::LEGACY) === []) {
                throw new RuntimeException("User #{$userId} holds no legacy front-desk role.");
            }

            $user->assignRole(FrontOfficeRole::NAME);
            foreach (FrontOfficeRole::LEGACY as $legacy) {
                if ($user->hasRole($legacy)) {
                    $user->removeRole($legacy);
                }
            }

            app(PermissionRegistrar::class)->forgetCachedPermissions();
            $user = $user->fresh();

            return [
                'id' => $user->id,
                'name' => $user->name,
                'roles_before' => $before,
                'roles_after' => $user->getRoleNames()->sort()->values()->all(),
                'changed' => true,
            ];
        });
    }

    /** @return list<string> */
    public function expectedPermissions(): array
    {
        $defined = RoleSeeder::ROLE_PERMISSIONS[FrontOfficeRole::NAME] ?? [];
        sort($defined);

        return array_values(array_unique($defined));
    }

    /** @return Collection<int, User> */
    private function legacyHolders()
    {
        return User::query()
            ->whereHas('roles', fn ($q) => $q->whereIn('name', array_merge(
                FrontOfficeRole::LEGACY,
                [FrontOfficeRole::NAME],
            )))
            ->with('roles')
            ->orderBy('id')
            ->get();
    }

    /** @return array<string, mixed> */
    private function describe(User $user): array
    {
        $roles = $user->getRoleNames()->sort()->values()->all();
        $extra = array_values(array_diff($roles, FrontOfficeRole::LEGACY, [FrontOfficeRole::NAME]));

        $blocked = null;
        if (in_array(self::SUPER_ADMIN_ROLE, $roles, true)) {
            $blocked = 'super_admin';
        } elseif ($extra !== []) {
            $blocked = 'holds_other_roles:'.implode('|', $extra);
        }

        return [
            'id' => $user->id,
            'name' => $user->name,
            'branch_id' => $user->branch_id,
            'roles' => $roles,
            'already_migrated' => $roles === [FrontOfficeRole::NAME],
            'blocked_by' => $blocked,
        ];
    }
}
