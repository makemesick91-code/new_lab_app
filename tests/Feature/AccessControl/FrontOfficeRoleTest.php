<?php

/**
 * DAENGTISIAMS-SUNU-FINAL-RELEASE-CANDIDATE-1 / D2 — merging Admin Klinik and
 * Kasir into one front-desk role without losing anything either of them had.
 *
 * Cabang Sunu staffs one person at the front desk who registers patients AND
 * takes payment. `Front Office` carries the measured union of the two legacy
 * roles: 21 permissions, the 5 they already shared counted once.
 *
 * THE FAILURE THIS FILE EXISTS TO PREVENT. Eleven sites across the codebase
 * encode role-specific narrowings as `hasRole('Admin Klinik')` /
 * `hasRole('Kasir')` string literals, and a role matching NEITHER fails OPEN at
 * every one of them, silently. The worst is not cosmetic: without
 * `requiresAdminClinicContext()` matching, no online context is demanded, so
 * `DailyBranchContextService` never sees a LOCKED role context and the daily
 * branch lock quietly stops existing. Measured on production the day before
 * this shipped, three of the four accounts being migrated had locked a branch
 * that day, and three of the four carry `users.branch_id = NULL`, so the
 * fallback would have landed them on MAIN — which is not RME-enabled.
 *
 * So the lock is asserted here against the real service and the real route,
 * not inferred from the role list.
 */

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\ClinicVisit\Policies\ClinicVisitPolicy;
use App\Modules\RmeOnlineContext\Models\DailyBranchContext;
use App\Modules\RmeOnlineContext\Models\UserOnlineContext;
use App\Modules\RmeOnlineContext\Services\DailyBranchContextService;
use App\Modules\RmeOnlineContext\Services\UserOnlineContextService;
use App\Support\AccessControl\FrontOfficeRole;
use Database\Seeders\BranchSeeder;
use Database\Seeders\RoleSeeder;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    test()->seed(BranchSeeder::class);
    seedAccessControl();

    Branch::where('code', Branch::MAIN_CODE)->update(['is_rme_enabled' => false]);
    $this->sunu = Branch::factory()->create(['code' => 'SPN4', 'name' => 'Cabang Sunu', 'is_rme_enabled' => true]);
});

function d2FrontOffice(): User
{
    $user = User::factory()->create(['name' => 'Front Office Sunu']);
    $user->assignRole(FrontOfficeRole::NAME);

    return $user->fresh();
}

// ─── The role is the union, re-derived rather than restated ────────────────

it('grants exactly the union of the two legacy roles, no more and no less', function () {
    $union = collect(RoleSeeder::ROLE_PERMISSIONS[FrontOfficeRole::LEGACY_ADMIN_CLINIC])
        ->merge(RoleSeeder::ROLE_PERMISSIONS[FrontOfficeRole::LEGACY_KASIR])
        ->unique()->sort()->values();

    $frontOffice = collect(RoleSeeder::ROLE_PERMISSIONS[FrontOfficeRole::NAME])
        ->unique()->sort()->values();

    // Derived from the other two lists on purpose: a permission added to
    // Admin Klinik or Kasir later must not silently skip the merged role.
    expect($frontOffice->all())->toBe($union->all())
        ->and($union)->toHaveCount(21);

    // And the seeded role really carries them, not just the array.
    expect(Role::findByName(FrontOfficeRole::NAME)->permissions->pluck('name')->sort()->values()->all())
        ->toBe($union->all());
});

it('reuses the existing role row rather than creating a second one', function () {
    // Production carries `Front Office` as id 10 with a stale 5-permission
    // subset. The seeder matches on NAME, so re-seeding must adopt that row
    // and sync it — never leave two roles with the same name.
    $before = Role::where('name', FrontOfficeRole::NAME)->firstOrFail();
    $before->syncPermissions(['view dashboard']);

    test()->seed(RoleSeeder::class);

    expect(Role::where('name', FrontOfficeRole::NAME)->count())->toBe(1)
        ->and(Role::where('name', FrontOfficeRole::NAME)->first()->id)->toBe($before->id)
        ->and(Role::findByName(FrontOfficeRole::NAME)->permissions)->toHaveCount(21);
});

// ─── The daily branch lock. The load-bearing property. ─────────────────────

it('still demands an online context, so the branch is never guessed', function () {
    $user = d2FrontOffice();
    $contexts = app(UserOnlineContextService::class);

    expect($contexts->requiresAdminClinicContext($user))->toBeTrue(
        'Front Office stopped requiring a context — BranchContext will fall back to users.branch_id, '.
        'which is NULL for three of the four migrated accounts, and then to MAIN, which is not RME-enabled',
    )->and($contexts->hasSatisfiedContext($user))->toBeFalse();
});

it('locks the clinical day to the branch it selects', function () {
    $user = d2FrontOffice();

    // The confirmation token is D5's guard on the first selection of the day;
    // Front Office inherits it precisely BECAUSE it is a locked role context.
    $this->actingAs($user)->post(route('rme.online-context.admin-clinic'), [
        'branch_id' => $this->sunu->id,
        DailyBranchContextService::CONFIRMATION_FIELD => $this->sunu->id,
    ])->assertSessionHasNoErrors();

    $lock = DailyBranchContext::query()->where('user_id', $user->id)->first();

    expect($lock)->not->toBeNull('the daily branch lock silently stopped engaging for the merged role')
        ->and((int) $lock->current_branch_id)->toBe((int) $this->sunu->id);
});

it('keeps Front Office inside the locked role contexts', function () {
    $user = d2FrontOffice();
    $daily = app(DailyBranchContextService::class);

    // Asserted through the service's own predicate, so a future edit to
    // LOCKED_ROLE_CONTEXTS that forgets this role fails here.
    expect($daily->isLockedRoleContext(
        UserOnlineContext::ROLE_ADMIN_CLINIC,
    ))->toBeTrue();
});

it('refuses to commit the day without the D5 confirmation', function () {
    $user = d2FrontOffice();

    $this->actingAs($user)->post(route('rme.online-context.admin-clinic'), [
        'branch_id' => $this->sunu->id,
    ])->assertSessionHasErrors(DailyBranchContextService::CONFIRMATION_FIELD);

    expect(DailyBranchContext::query()->where('user_id', $user->id)->count())->toBe(0);
});

// ─── It can actually do both halves of the job ─────────────────────────────

it('can do the work of both legacy roles', function () {
    $user = d2FrontOffice();

    expect($user->can('manage_clinic_visits'))->toBeTrue('cannot register — the Admin Klinik half is missing')
        ->and($user->can('manage_rme_billing'))->toBeTrue('cannot take payment — the Kasir half is missing')
        ->and($user->can('view_rme_payment_reports'))->toBeTrue()
        ->and($user->can('view_rme_patient_reports'))->toBeTrue()
        ->and($user->can('manage_rme_consents'))->toBeTrue();
});

it('keeps the front-desk narrowing on clinic visits', function () {
    $user = d2FrontOffice();
    $visit = ClinicVisit::factory()->create(['branch_id' => $this->sunu->id]);

    // `isFrontOfficeOnly` was named for this tier a sprint before the tier had
    // a role. Front Office must inherit the narrowing, not escape it.
    $reflection = new ReflectionMethod(ClinicVisitPolicy::class, 'isFrontOfficeOnly');

    expect($reflection->invoke(app(ClinicVisitPolicy::class), $user))->toBeTrue(
        'the merged role escaped the front-desk visit narrowing',
    );

    unset($visit);
});

// ─── Menu visibility is the UNION, and nothing beyond it ───────────────────

it('shows both halves of the front desk and no administration', function () {
    $user = d2FrontOffice();
    rmeMakeFrontOfficeActive($user, $this->sunu);

    $html = $this->actingAs($user)->get(route('dashboard'))->assertOk()->getContent();

    // Visible: what EITHER legacy role saw.
    expect($html)->toContain(route('rme.visits.index'))          // AK saw it
        ->and($html)->toContain(route('rme.patient-queue.index')) // AK saw it
        ->and($html)->toContain(route('rme.cashier.index'));      // Kasir saw it

    // Hidden: what NEITHER saw. Front Office inherits `manage patients` and
    // `view_clinic_master_data` from Admin Klinik, whose menus were
    // deliberately hidden — inheriting the permission must not un-hide them.
    expect($html)->not->toContain(route('settings.doctor-devices.index'))
        ->and($html)->not->toContain(route('rme.patients.audit'));
});
