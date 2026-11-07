<?php

/**
 * DMS-RME-REVISION-PROGRAM-8C PR1 (rule 228) — D3-NEW-4: the cross-branch
 * clinical DOCUMENT read is an allowlist.
 *
 * 8B (D1) narrowed the context-bound front desk and cashier to their working
 * branch. Every OTHER account kept the RME-wide set, so a role created at runtime
 * that holds view_clinic_visits (production already has one, "Tester RME") would
 * read every branch's clinical documents without anyone deciding it. Owner
 * decision (2026-11-07): an allowlist — clinical roles and Owner keep the
 * cross-branch read they have today; an unclassified account reads none.
 */

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\Branch\Services\BranchService;
use App\Modules\RmeOnlineContext\Services\RmeWorkingBranchScope;
use Database\Seeders\RoleSeeder;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    seedAccessControl();

    $this->branchA = Branch::factory()->create(['is_active' => true, 'is_rme_enabled' => true]);
    $this->branchB = Branch::factory()->create(['is_active' => true, 'is_rme_enabled' => true]);
    $this->scope = app(RmeWorkingBranchScope::class);
    $this->rmeWide = app(BranchService::class)->rmeEnabledIds();
    sort($this->rmeWide);
});

function crsaIds(array $ids): array
{
    sort($ids);

    return array_values($ids);
}

it('A-01 refuses an unclassified runtime role that holds view_clinic_visits every branch', function () {
    $role = Role::findOrCreate('Tester RME', 'web');
    $role->syncPermissions(['view_clinic_visits', 'manage_clinic_visits']);
    $tester = User::factory()->create()->assignRole($role);

    expect($this->scope->clinicalDocumentBranchIdsFor($tester->fresh()))->toBe([])
        ->and($this->scope->allowsClinicalDocument($tester->fresh(), $this->branchA->id))->toBeFalse();
});

it('A-02 keeps Owner reading every RME branch', function () {
    $owner = userInRole('Owner');

    expect(crsaIds($this->scope->clinicalDocumentBranchIdsFor($owner)))->toBe($this->rmeWide);
});

it('A-03 keeps every clinical role reading every RME branch', function (string $role) {
    $user = userInRole($role);

    if ($role === 'Perawat') {
        rmeMakePerawatActive($user, $this->branchA);
    }

    expect(crsaIds($this->scope->clinicalDocumentBranchIdsFor($user->fresh())))->toBe($this->rmeWide);
})->with(['Doctor', 'Perawat', 'Supervisor RME', 'Super Admin']);

it('A-04 keeps the front desk and the cashier on their working branch (8B D1 unchanged)', function (string $role) {
    $user = User::factory()->create();

    match ($role) {
        'Kasir' => rmeMakeKasirActive($user, $this->branchA),
        'Front Office' => rmeMakeFrontOfficeActive($user, $this->branchA),
        default => rmeMakeAdminClinicActive($user, $this->branchA),
    };

    expect($this->scope->clinicalDocumentBranchIdsFor($user->fresh()))->toBe([$this->branchA->id]);
})->with(['Kasir', 'Front Office', 'Admin Klinik']);

it('A-05 classifies every seeded role that holds a clinic-visit permission', function () {
    $classified = RmeWorkingBranchScope::CROSS_BRANCH_DOCUMENT_READ_ROLES;
    $contextBound = ['Admin Klinik', 'Front Office', 'Kasir'];

    foreach (RoleSeeder::ROLE_PERMISSIONS as $role => $permissions) {
        $permissions = (array) $permissions;
        $holds = in_array('*', $permissions, true)
            || array_intersect(['view_clinic_visits', 'manage_clinic_visits'], $permissions) !== [];

        if (! $holds) {
            continue;
        }

        expect(in_array($role, $classified, true) || in_array($role, $contextBound, true))
            ->toBeTrue("Seeded role {$role} reads clinical documents but is neither on the cross-branch allowlist nor a working-branch role.");
    }
});
