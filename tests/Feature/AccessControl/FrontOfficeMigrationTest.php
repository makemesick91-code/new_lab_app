<?php

/**
 * DAENGTISIAMS-SUNU-FINAL-RELEASE-CANDIDATE-1 / D2 — moving the four live
 * front-desk accounts onto the merged role.
 *
 * Production, measured 2026-09-20: Admin Klinik (role 17) holds users 7 and 16,
 * Kasir (role 12) holds users 8 and 17, none soft-deleted, none carrying direct
 * permissions. Role 10 `Front Office` already exists with a stale 5-permission
 * subset and no users. These tests pin the guards that stand between that state
 * and a hand-typed UPDATE on a production prompt.
 */

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\RmeOnlineContext\Models\DailyBranchContext;
use App\Support\AccessControl\FrontOfficeMigrationAuditor;
use App\Support\AccessControl\FrontOfficeRole;
use Database\Seeders\BranchSeeder;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    test()->seed(BranchSeeder::class);
    seedAccessControl();
    $this->auditor = app(FrontOfficeMigrationAuditor::class);
});

function d2Legacy(string $role): User
{
    $user = User::factory()->create(['name' => "Legacy {$role}"]);
    $user->assignRole($role);

    return $user->fresh();
}

// ─── Dry run is genuinely dry ──────────────────────────────────────────────

it('changes nothing without --apply', function () {
    $ak = d2Legacy(FrontOfficeRole::LEGACY_ADMIN_CLINIC);
    $ks = d2Legacy(FrontOfficeRole::LEGACY_KASIR);

    $this->artisan('rbac:front-office-migrate')->assertExitCode(0);

    expect($ak->fresh()->getRoleNames()->all())->toBe([FrontOfficeRole::LEGACY_ADMIN_CLINIC])
        ->and($ks->fresh()->getRoleNames()->all())->toBe([FrontOfficeRole::LEGACY_KASIR]);
});

it('reports both legacy roles as migratable candidates', function () {
    d2Legacy(FrontOfficeRole::LEGACY_ADMIN_CLINIC);
    d2Legacy(FrontOfficeRole::LEGACY_KASIR);

    $report = $this->auditor->audit();

    expect($report['role_ready'])->toBeTrue()
        ->and($report['expected_permission_count'])->toBe(21)
        ->and($report['migratable'])->toBe(2)
        ->and($report['blocked'])->toBe(0);
});

// ─── Applying it ───────────────────────────────────────────────────────────

it('moves a legacy account onto the merged role', function (string $legacy) {
    $user = d2Legacy($legacy);

    $this->artisan('rbac:front-office-migrate --apply')->assertExitCode(0);

    expect($user->fresh()->getRoleNames()->all())->toBe([FrontOfficeRole::NAME]);
})->with([
    FrontOfficeRole::LEGACY_ADMIN_CLINIC,
    FrontOfficeRole::LEGACY_KASIR,
]);

it('is idempotent', function () {
    $user = d2Legacy(FrontOfficeRole::LEGACY_KASIR);

    $this->artisan('rbac:front-office-migrate --apply')->assertExitCode(0);
    $this->artisan('rbac:front-office-migrate --apply')->assertExitCode(0);

    expect($user->fresh()->getRoleNames()->all())->toBe([FrontOfficeRole::NAME]);
});

it('leaves the daily branch lock standing', function () {
    // The lock is keyed on (user_id, clinical_date) with no role column, so a
    // cashier locked to a branch this morning must still be locked to it this
    // afternoon. Three of the four live accounts were locked the day before
    // this shipped, so this is the real case.
    Branch::where('code', Branch::MAIN_CODE)->update(['is_rme_enabled' => false]);
    $branch = Branch::factory()->create(['code' => 'SPN4', 'is_rme_enabled' => true]);

    $user = d2Legacy(FrontOfficeRole::LEGACY_KASIR);
    rmeMakeKasirActive($user, $branch);
    $user->removeRole(FrontOfficeRole::LEGACY_KASIR);
    $user->assignRole(FrontOfficeRole::LEGACY_KASIR);

    $lockBefore = DailyBranchContext::query()->where('user_id', $user->id)->first();
    expect($lockBefore)->not->toBeNull('fixture is vacuous: no lock was created');

    $this->auditor->migrate($user->id);

    $lockAfter = DailyBranchContext::query()->where('user_id', $user->id)->first();

    expect($lockAfter)->not->toBeNull('the migration destroyed a live daily branch lock')
        ->and((int) $lockAfter->current_branch_id)->toBe((int) $lockBefore->current_branch_id);
});

// ─── The refusals ──────────────────────────────────────────────────────────

it('refuses to migrate into an under-seeded role', function () {
    // The live case: role 10 exists with 5 of the 21 permissions. Migrating
    // into it would STRIP capability from a working account.
    Role::findByName(FrontOfficeRole::NAME)->syncPermissions(['view dashboard']);
    $user = d2Legacy(FrontOfficeRole::LEGACY_ADMIN_CLINIC);

    expect(fn () => $this->auditor->migrate($user->id))
        ->toThrow(RuntimeException::class);

    expect($user->fresh()->getRoleNames()->all())->toBe([FrontOfficeRole::LEGACY_ADMIN_CLINIC]);

    // And the command refuses before touching anything.
    $this->artisan('rbac:front-office-migrate --apply')->assertExitCode(1);
});

it('refuses an account that also holds a clinical role', function () {
    $user = d2Legacy(FrontOfficeRole::LEGACY_ADMIN_CLINIC);
    $user->assignRole('Doctor');

    expect(fn () => $this->auditor->migrate($user->id))
        ->toThrow(RuntimeException::class);

    expect($user->fresh()->getRoleNames())->toContain('Doctor');
});

it('never touches a Super Admin', function () {
    $user = d2Legacy(FrontOfficeRole::LEGACY_KASIR);
    $user->assignRole('Super Admin');

    expect(fn () => $this->auditor->migrate($user->id))
        ->toThrow(RuntimeException::class);

    expect($user->fresh()->getRoleNames())->toContain('Super Admin');
});

it('flags a blocked account under --strict instead of passing quietly', function () {
    $user = d2Legacy(FrontOfficeRole::LEGACY_ADMIN_CLINIC);
    $user->assignRole('Doctor');

    $this->artisan('rbac:front-office-migrate --strict')->assertExitCode(2);
});

it('can be scoped to named accounts', function () {
    $a = d2Legacy(FrontOfficeRole::LEGACY_ADMIN_CLINIC);
    $b = d2Legacy(FrontOfficeRole::LEGACY_KASIR);

    $this->artisan("rbac:front-office-migrate --apply --user={$a->id}")->assertExitCode(0);

    expect($a->fresh()->getRoleNames()->all())->toBe([FrontOfficeRole::NAME])
        ->and($b->fresh()->getRoleNames()->all())->toBe([FrontOfficeRole::LEGACY_KASIR]);
});
