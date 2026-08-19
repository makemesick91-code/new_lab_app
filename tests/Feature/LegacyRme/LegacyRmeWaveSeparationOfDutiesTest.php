<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\Branch\Services\BranchService;
use App\Modules\LegacyRme\Models\LegacyRmeMigrationWave;
use App\Modules\LegacyRme\Policies\LegacyRmeMigrationWavePolicy;
use App\Modules\LegacyRme\Services\LegacyRmeWaveGovernanceService;
use App\Modules\LegacyRme\Support\LegacyRmeWaveStatus;
use App\Modules\LegacyRme\Support\LegacyRmeWorkspaceScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * LEGACY-RME-PDF-ROLL-4-WAVE-1 — separation of duties on a migration wave.
 *
 * WHY THIS FILE EXISTS. ROLL-4 shipped the *mechanism* for separation of duties
 * — `approve` split from `manage`, plus a server-side approver-is-not-creator
 * check — but shipped it switched off, with a recorded note saying to turn it on
 * "once two staffed accounts exist". Until Wave-1 no operational role held any
 * legacy migration permission at all, so every prior migration ran as a single
 * Super Admin and the mechanism had nothing to act on: all five records in
 * production before this wave were imported AND published by the same user id.
 *
 * Wave-1 supplies the two staffed accounts, so these tests pin the property the
 * switch is supposed to buy. The distinction they defend is between a rule that
 * is *configured* and a rule that is *enforced* — a role matrix that merely
 * looks separated is worth nothing if the service still lets one actor sign
 * their own wave.
 *
 * WHAT IS DELIBERATELY NOT ASSERTED HERE: that a UI hides a button. Every check
 * below goes through the governance service, because that is the only boundary
 * an attacker has to pass.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    seedAccessControl();
    Storage::fake('legacy_rme_private');
    legacyRmeArchiveFlag(true);
    legacyRmeBranch('LDK2', 'Cabang Landak');

    // The switch this sprint turns on in production. Without it the service
    // permits self-approval by design, which is the state these tests exist to
    // leave behind.
    config()->set('legacy_rme_operations.require_separate_approver', true);
});

function sodGovernance(): LegacyRmeWaveGovernanceService
{
    return app(LegacyRmeWaveGovernanceService::class);
}

/** A wave creator: holds `manage`, and deliberately NOT `approve`. */
function sodCreator(): User
{
    return userWith(['manage_legacy_rme_migration_operations', 'view_legacy_rme_migration_operations']);
}

function sodRegisterWave(User $creator, string $code = 'SOD-WAVE'): LegacyRmeMigrationWave
{
    config()->set('legacy_rme_rollout.admission.wave', $code);
    legacyRmeApproveWave('SOD-APPROVAL-2026-08-14', ['LDK2']);
    legacyRmeAdmittedBranches(['LDK2']);

    // FIX-LEGACY-RME-ROUTINE-OPS-1 — a batch is time-bounded, so every caller
    // (this fixture included) declares its window. Fixed dates keep the test
    // deterministic; nothing here depends on the window's position in time.
    return sodGovernance()->createWave($creator, $code, 'Gelombang Uji SoD', ['LDK2'], 5, 5, '2026-08-14', '2026-08-20');
}

it('refuses to let the wave creator approve their own wave', function () {
    // The core property. A creator who is also allowed to approve would make
    // the whole maker-checker structure decorative.
    $creator = sodCreator();
    $creator->givePermissionTo('approve_legacy_rme_migration_wave');
    $creator = $creator->fresh();

    $wave = sodRegisterWave($creator);

    expect($wave->created_by)->toBe($creator->getKey());

    sodGovernance()->approve($creator, $wave);
})->throws(ValidationException::class, 'Gelombang migrasi harus disetujui oleh pengguna yang berbeda dari pembuatnya.');

it('lets a different, permitted actor approve the wave', function () {
    $creator = sodCreator();
    $wave = sodRegisterWave($creator);

    $approver = userInRole('Supervisor RME');

    $approved = sodGovernance()->approve($approver, $wave);

    expect($approved->status)->toBe(LegacyRmeWaveStatus::APPROVED)
        ->and($approved->approved_by)->toBe($approver->getKey())
        ->and($approved->approved_by)->not->toBe($approved->created_by);
});

it('composes the seeded roles into a working operator and approver pair', function () {
    // The seeder half of the contract: the two production roles this sprint
    // grants must actually be able to play their parts, and only their parts.
    $operator = userInRole('Admin Klinik');
    $approver = userInRole('Supervisor RME');

    expect($operator->getKey())->not->toBe($approver->getKey());

    // Maker files, cannot certify.
    expect($operator->can('create_legacy_rme_imports'))->toBeTrue()
        ->and($operator->can('publish_legacy_rme_imports'))->toBeFalse()
        ->and($operator->can('approve_legacy_rme_migration_wave'))->toBeFalse();

    // Checker certifies and approves, cannot file.
    expect($approver->can('publish_legacy_rme_imports'))->toBeTrue()
        ->and($approver->can('approve_legacy_rme_migration_wave'))->toBeTrue()
        ->and($approver->can('create_legacy_rme_imports'))->toBeFalse();

    // And neither may shape the wave they are working under.
    expect($operator->can('manage_legacy_rme_migration_operations'))->toBeFalse()
        ->and($approver->can('manage_legacy_rme_migration_operations'))->toBeFalse();
});

it('still refuses an approver who lacks the approval permission', function () {
    // Being a different person is necessary, not sufficient.
    $creator = sodCreator();
    $wave = sodRegisterWave($creator);

    $operator = userInRole('Admin Klinik');

    expect($operator->can('approve_legacy_rme_migration_wave'))->toBeFalse()
        ->and(app(LegacyRmeMigrationWavePolicy::class)->approve($operator, $wave))
        ->toBeFalse();
});

it('keeps the intake operator pinned to their own branch and puts the checker in the governance tier', function () {
    // THE CONSEQUENCE OF THE SPLIT THAT IS EASIEST TO MISS.
    //
    // LegacyRmeWorkspaceScope treats `review`/`publish`/`void` as GOVERNANCE
    // permissions: holding any one of them widens a user's view to every
    // RME-enabled branch. So granting the checker `review` + `publish` is not
    // only a certifying authority, it is also a cross-branch read — which is
    // correct for an RME-wide supervisor and consistent with the cross-branch
    // reporting that role already has, but it must be a decision on the record
    // rather than a side effect nobody noticed.
    //
    // The intake operator deliberately holds NONE of those three, so they fall
    // through to BranchContext and stay pinned to their own clinic. That is the
    // property that stops a branch operator from reading another branch's
    // archive, and it is asserted here so a future grant of `review` to the
    // maker cannot silently unpin them.
    $scope = app(LegacyRmeWorkspaceScope::class);
    $allRmeBranchIds = app(BranchService::class)->rmeEnabledIds();

    $ldk2 = Branch::query()->where('code', 'LDK2')->firstOrFail();

    $operator = userInRole('Admin Klinik');
    $operator->forceFill(['branch_id' => $ldk2->getKey()])->save();
    $operator = $operator->fresh();

    expect($scope->branchIdsFor($operator))->toBe([(int) $ldk2->getKey()]);

    $approver = userInRole('Supervisor RME');

    expect($scope->branchIdsFor($approver))->toBe($allRmeBranchIds)
        ->and(count($allRmeBranchIds))->toBeGreaterThan(0);
});

it('records the creator and approver as distinct, auditable identities', function () {
    // A wave is governance evidence. Whichever way the rollout goes afterwards,
    // it must stay reconstructable who shaped it and who signed it.
    $creator = sodCreator();
    $wave = sodRegisterWave($creator);
    $approver = userInRole('Supervisor RME');

    $approved = sodGovernance()->approve($approver, $wave);

    expect($approved->created_by)->not->toBeNull()
        ->and($approved->approved_by)->not->toBeNull()
        ->and($approved->approved_at)->not->toBeNull()
        ->and($approved->created_by)->not->toBe($approved->approved_by);
});
