<?php

/**
 * DAENGTISIAMS-SUNU-FINAL-RELEASE-CANDIDATE-1 / D2 — the legacy-role policy,
 * enforced rather than documented.
 *
 * OWNER DECISION, 2026-09-20:
 *   FRONT_OFFICE_LEGACY_ROLE_POLICY =
 *   KEEP_LEGACY_ROLE_DEFINITIONS_FOR_ROLLBACK_AND_COMPATIBILITY
 *
 * "Inactive legacy role" means NO OPERATIONAL USER ASSIGNMENT. It does NOT mean
 * the role is deleted, its permissions cleared, or its historical
 * `model_has_roles` rows destroyed.
 *
 * A future sprint tidying up "unused" roles is exactly how that decision gets
 * quietly reversed, and the damage would only surface on the morning someone
 * needed to roll a front-desk account back. So the policy is pinned here: this
 * file fails if either legacy role is deleted, emptied, or thinned.
 */

use App\Models\User;
use App\Support\AccessControl\FrontOfficeRole;
use Database\Seeders\RoleSeeder;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    seedAccessControl();
});

it('keeps both legacy role definitions in the seeder', function (string $legacy) {
    expect(RoleSeeder::ROLE_PERMISSIONS)->toHaveKey($legacy);
})->with(FrontOfficeRole::LEGACY);

it('keeps both legacy roles present in the database after seeding', function (string $legacy) {
    expect(Role::where('name', $legacy)->exists())->toBeTrue(
        "the {$legacy} role was deleted — the rollback path and the historical ".
        'model_has_roles rows go with it',
    );
})->with(FrontOfficeRole::LEGACY);

it('does not strip the legacy permission grants', function (string $legacy, int $expected) {
    // Counted, not merely non-empty: thinning a role to one permission would
    // pass a `not->toBeEmpty()` while destroying the rollback just as surely.
    expect(Role::findByName($legacy)->permissions)->toHaveCount(
        $expected,
        "the {$legacy} permission grant changed — the owner decision is that it stays intact",
    );
})->with([
    // REVISION-LEGACY-VISIT-BOUND-PREVERIFIED-INGESTION-1 added `verify_legacy_dates_at_ingestion` to Admin Klinik and Front Office,
    // so Admin Klinik moved 20 -> 21. Kasir is deliberately unchanged.
    [FrontOfficeRole::LEGACY_ADMIN_CLINIC, 21],
    [FrontOfficeRole::LEGACY_KASIR, 6],
]);

it('leaves a legacy role able to carry a user again, which is what rollback means', function (string $legacy) {
    $user = User::factory()->create();
    $user->assignRole(FrontOfficeRole::NAME);
    $user = $user->fresh();

    // The rollback: take the merged role off, put the legacy one back, and the
    // account must regain that role's authority — not an empty shell.
    $user->removeRole(FrontOfficeRole::NAME);
    $user->assignRole($legacy);
    $user = $user->fresh();

    expect($user->getRoleNames()->all())->toBe([$legacy])
        ->and($user->getAllPermissions())->not->toBeEmpty()
        ->and($user->can('view_clinic_visits'))->toBeTrue();
})->with(FrontOfficeRole::LEGACY);

it('treats inactive as unassigned, so the roles stay assignable', function () {
    // The policy is about who HOLDS the role, never about whether it exists.
    // Seeding alone must not attach any operational user to either legacy role.
    foreach (FrontOfficeRole::LEGACY as $legacy) {
        expect(User::role($legacy)->count())->toBe(
            0,
            "seeding attached a user to {$legacy}; inactive means no operational assignment",
        );
    }
});
