<?php

/**
 * LEGACY-RME-PDF-1A — permissions, policies and branch isolation.
 *
 * The archive is operated from Master Data RME by Super Admin. The permissions
 * exist as named abilities from day one, no role receives them by default, and
 * every per-row ability is additionally branch-scoped server-side.
 */

use App\Modules\AccessControl\Services\PermissionGroupingService;
use App\Modules\Branch\Models\Branch;
use App\Modules\LegacyRme\Models\LegacyRmeImport;
use App\Modules\LegacyRme\Models\LegacyRmeRecord;
use App\Modules\LegacyRme\Policies\LegacyRmeImportPolicy;
use App\Modules\LegacyRme\Policies\LegacyRmeRecordPolicy;
use App\Modules\LegacyRme\Support\LegacyRmeWorkspaceScope;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

const LEGACY_RME_PERMISSIONS = [
    'view_legacy_rme_imports',
    'create_legacy_rme_imports',
    'review_legacy_rme_imports',
    'publish_legacy_rme_imports',
    'void_legacy_rme_imports',
];

beforeEach(function () {
    seedAccessControl();
});

it('registers every legacy RME permission on the web guard', function () {
    foreach (LEGACY_RME_PERMISSIONS as $permission) {
        expect(Permission::where('name', $permission)->where('guard_name', 'web')->exists())
            ->toBeTrue("Permission {$permission} should be seeded");
    }
});

it('seeds the legacy RME permissions idempotently', function () {
    $before = Permission::whereIn('name', LEGACY_RME_PERMISSIONS)->count();

    $this->seed(PermissionSeeder::class);

    expect(Permission::whereIn('name', LEGACY_RME_PERMISSIONS)->count())->toBe($before)
        ->and($before)->toBe(count(LEGACY_RME_PERMISSIONS));
});

it('splits the legacy RME import permissions into a maker-checker pair and grants them to nobody else', function () {
    // LEGACY-RME-PDF-ROLL-4-WAVE-1 changed this contract deliberately. Until
    // Wave-1 the answer was "no operational role holds any of these", which
    // left Super Admin as the only possible actor and made separation of
    // duties impossible to demonstrate.
    //
    // Two staffed accounts now exist, so the abilities are split:
    //   Admin Klinik   — MAKER: files the document (view + create)
    //   Supervisor RME — CHECKER: certifies it (view + review + publish)
    //
    // The assertion is exact, not a subset: a role must hold precisely its
    // half. That is what stops a future edit from quietly handing `publish` to
    // the maker or `create` to the checker and collapsing the pair, which is
    // exactly the failure this split exists to prevent. `void` stays with
    // neither — it is a correction authority, not a migration one.
    $expected = [
        'Admin Klinik' => ['create_legacy_rme_imports', 'view_legacy_rme_imports'],
        'Supervisor RME' => ['publish_legacy_rme_imports', 'review_legacy_rme_imports', 'view_legacy_rme_imports'],
    ];

    foreach (array_keys(RoleSeeder::ROLE_PERMISSIONS) as $roleName) {
        if ($roleName === 'Super Admin') {
            continue;
        }

        $role = Role::where('name', $roleName)->first();

        if ($role === null) {
            continue;
        }

        $granted = $role->permissions->pluck('name')->intersect(LEGACY_RME_PERMISSIONS)->sort()->values()->all();

        expect($granted)->toBe(
            $expected[$roleName] ?? [],
            "Role {$roleName} holds an unexpected set of legacy RME import permissions"
        );
    }
});

it('never lets one role hold both halves of the legacy import maker-checker pair', function () {
    // The property that matters, stated independently of who currently holds
    // what: no single role may both file and publish a legacy archive.
    foreach (array_keys(RoleSeeder::ROLE_PERMISSIONS) as $roleName) {
        if ($roleName === 'Super Admin') {
            continue;
        }

        $role = Role::where('name', $roleName)->first();

        if ($role === null) {
            continue;
        }

        $names = $role->permissions->pluck('name');

        expect($names->contains('create_legacy_rme_imports') && $names->contains('publish_legacy_rme_imports'))
            ->toBeFalse("Role {$roleName} may not both create and publish a legacy archive");
    }
});

it('grants every legacy RME permission to Super Admin', function () {
    $role = Role::where('name', 'Super Admin')->first();

    expect($role)->not->toBeNull()
        ->and($role->permissions->pluck('name')->all())->toContain(...LEGACY_RME_PERMISSIONS);
});

it('classifies the legacy RME permissions inside the RME permission group', function () {
    $groups = app(PermissionGroupingService::class)
        ->group(Permission::orderBy('name')->get());

    $rme = collect($groups)->firstWhere('key', 'rme');

    expect($rme)->not->toBeNull()
        ->and(collect($rme['permissions'])->pluck('name')->all())
        ->toContain(...LEGACY_RME_PERMISSIONS);
});

it('lets Super Admin through every legacy RME ability via the global bypass', function () {
    $user = superAdmin();
    $import = LegacyRmeImport::factory()->reviewed()->create();
    $record = LegacyRmeRecord::factory()->create();

    expect(Gate::forUser($user)->allows('viewAny', LegacyRmeImport::class))->toBeTrue()
        ->and(Gate::forUser($user)->allows('view', $import))->toBeTrue()
        ->and(Gate::forUser($user)->allows('create', LegacyRmeImport::class))->toBeTrue()
        ->and(Gate::forUser($user)->allows('review', $import))->toBeTrue()
        ->and(Gate::forUser($user)->allows('publish', $import))->toBeTrue()
        ->and(Gate::forUser($user)->allows('void', $record))->toBeTrue();
});

it('denies every legacy RME ability to a user without the permissions', function () {
    $user = userWith(['view_clinic_visits']);
    $import = LegacyRmeImport::factory()->reviewed()->create();
    $record = LegacyRmeRecord::factory()->create();

    expect(Gate::forUser($user)->allows('viewAny', LegacyRmeImport::class))->toBeFalse()
        ->and(Gate::forUser($user)->allows('view', $import))->toBeFalse()
        ->and(Gate::forUser($user)->allows('create', LegacyRmeImport::class))->toBeFalse()
        ->and(Gate::forUser($user)->allows('review', $import))->toBeFalse()
        ->and(Gate::forUser($user)->allows('publish', $import))->toBeFalse()
        ->and(Gate::forUser($user)->allows('void', $record))->toBeFalse();
});

it('scopes a non-governance viewer to their own branch', function () {
    $ownBranch = Branch::factory()->create(['is_active' => true, 'is_rme_enabled' => true]);
    $otherBranch = Branch::factory()->create(['is_active' => true, 'is_rme_enabled' => true]);

    $user = userWith(['view_legacy_rme_imports']);
    $user->forceFill(['branch_id' => $ownBranch->id])->save();

    $own = LegacyRmeImport::factory()->create(['origin_branch_id' => $ownBranch->id]);
    $foreign = LegacyRmeImport::factory()->create(['origin_branch_id' => $otherBranch->id]);

    expect(Gate::forUser($user)->allows('view', $own))->toBeTrue()
        ->and(Gate::forUser($user)->allows('view', $foreign))->toBeFalse();
});

it('hides rows without an origin branch from a non-governance viewer', function () {
    $branch = Branch::factory()->create(['is_active' => true, 'is_rme_enabled' => true]);

    $user = userWith(['view_legacy_rme_imports']);
    $user->forceFill(['branch_id' => $branch->id])->save();

    $unscoped = LegacyRmeImport::factory()->create(['origin_branch_id' => null]);

    expect(Gate::forUser($user)->allows('view', $unscoped))->toBeFalse();
});

it('lets the governance tier see every RME branch including unscoped rows', function () {
    $branchA = Branch::factory()->create(['is_active' => true, 'is_rme_enabled' => true]);
    $branchB = Branch::factory()->create(['is_active' => true, 'is_rme_enabled' => true]);

    $user = userWith(['view_legacy_rme_imports', 'review_legacy_rme_imports']);

    $scope = app(LegacyRmeWorkspaceScope::class);

    expect($scope->branchIdsFor($user))->toContain($branchA->id, $branchB->id)
        ->and($scope->includesUnscopedRowsFor($user))->toBeTrue();

    $unscoped = LegacyRmeImport::factory()->create(['origin_branch_id' => null]);

    expect(Gate::forUser($user)->allows('view', $unscoped))->toBeTrue();
});

it('fails closed when a viewer branch cannot be resolved to an RME branch', function () {
    // MAIN is never RME-enabled, so a user pinned to it resolves to an empty scope.
    $main = Branch::factory()->main()->create(['is_active' => true, 'is_rme_enabled' => false]);
    Branch::factory()->create(['is_active' => true, 'is_rme_enabled' => true]);

    $user = userWith(['view_legacy_rme_imports']);
    $user->forceFill(['branch_id' => $main->id])->save();

    $scope = app(LegacyRmeWorkspaceScope::class);
    $import = LegacyRmeImport::factory()->create();

    expect($scope->branchIdsFor($user))->toBe([])
        ->and($scope->includesUnscopedRowsFor($user))->toBeFalse()
        ->and(Gate::forUser($user)->allows('view', $import))->toBeFalse();
});

it('only allows publishing from a reviewed import', function () {
    $user = userWith(['publish_legacy_rme_imports']);
    $policy = app(LegacyRmeImportPolicy::class);

    $reviewed = LegacyRmeImport::factory()->reviewed()->create();
    $draft = LegacyRmeImport::factory()->create();
    $published = LegacyRmeImport::factory()->published()->create();

    expect($policy->publish($user, $reviewed))->toBeTrue()
        ->and($policy->publish($user, $draft))->toBeFalse()
        ->and($policy->publish($user, $published))->toBeFalse();
});

it('refuses to review or cancel a terminal import', function () {
    $user = userWith(['review_legacy_rme_imports', 'create_legacy_rme_imports']);
    $policy = app(LegacyRmeImportPolicy::class);

    $published = LegacyRmeImport::factory()->published()->create();
    $cancelled = LegacyRmeImport::factory()->cancelled()->create();

    expect($policy->review($user, $published))->toBeFalse()
        ->and($policy->review($user, $cancelled))->toBeFalse()
        ->and($policy->cancel($user, $published))->toBeFalse()
        ->and($policy->cancel($user, $cancelled))->toBeFalse();
});

it('keeps a published legacy record immutable and voidable only once', function () {
    $user = userWith(['void_legacy_rme_imports']);
    $policy = app(LegacyRmeRecordPolicy::class);

    $published = LegacyRmeRecord::factory()->create();
    $voided = LegacyRmeRecord::factory()->voided()->create();

    expect($policy->void($user, $published))->toBeTrue()
        ->and($policy->void($user, $voided))->toBeFalse()
        ->and($policy->update($user, $published))->toBeFalse()
        ->and($policy->delete($user, $published))->toBeFalse();
});

it('denies update and delete on a legacy record even for Super Admin at the policy level', function () {
    // Gate::before still bypasses for Super Admin by design; the policy itself
    // must never authorise mutation of a published legacy record.
    $policy = app(LegacyRmeRecordPolicy::class);
    $user = superAdmin();
    $record = LegacyRmeRecord::factory()->create();

    expect($policy->update($user, $record))->toBeFalse()
        ->and($policy->delete($user, $record))->toBeFalse();
});

it('registers both legacy RME policies with the container', function () {
    expect(Gate::getPolicyFor(LegacyRmeImport::class))->toBeInstanceOf(LegacyRmeImportPolicy::class)
        ->and(Gate::getPolicyFor(LegacyRmeRecord::class))->toBeInstanceOf(LegacyRmeRecordPolicy::class);
});
