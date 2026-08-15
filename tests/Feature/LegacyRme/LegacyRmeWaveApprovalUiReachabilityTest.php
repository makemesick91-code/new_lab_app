<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\LegacyRme\Models\LegacyRmeMigrationWave;
use App\Modules\LegacyRme\Support\LegacyRmeWaveStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

/**
 * LEGACY-RME-PDF-ROLL-4-WAVE-2 — the approver must be able to REACH the approval.
 *
 * WHY THIS FILE EXISTS. ROLL-4 split `approve_legacy_rme_migration_wave` from
 * `manage_legacy_rme_migration_operations` precisely so the account that SHAPES a
 * wave is never the account that SIGNS it, and the route comment says so. Wave-1
 * proved the server-side half: a creator cannot approve their own wave.
 *
 * What nobody asserted was the other half — that the designated approver can
 * actually get to the button. The wave detail view nested the approve form inside
 * an `@if ($canManage)` block, so it rendered only for holders of the MANAGE
 * permission, which the approver deliberately does not hold. The inner permission
 * check was dead code for the one role it existed for.
 *
 * The consequence was not cosmetic. With no reachable human path, separation of
 * duties could only be satisfied by a CLI actor impersonating the approver — i.e.
 * the control could be *configured* but not *exercised by a person*, which is the
 * same failure mode the existing service-level tests were written to prevent.
 *
 * Those service tests state they deliberately do not assert "that a UI hides a
 * button", on the grounds that the service is the only boundary an attacker must
 * pass. That is right for ATTACK surface and wrong for REACHABILITY: hiding an
 * action from an attacker and hiding it from its own operator are different bugs,
 * and only the second one is invisible to a service-level test.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    seedAccessControl();
    Storage::fake('legacy_rme_private');
    legacyRmeArchiveFlag(true);
    legacyRmeBranch('LDK2', 'Cabang Landak');
});

/** The checker: may approve and may look, and deliberately may NOT manage. */
function approvalUiChecker(): User
{
    return userWith([
        'approve_legacy_rme_migration_wave',
        'view_legacy_rme_migration_operations',
    ]);
}

/** The rollout manager: shapes the wave, and deliberately may NOT approve. */
function approvalUiManager(): User
{
    return userWith([
        'manage_legacy_rme_migration_operations',
        'view_legacy_rme_migration_operations',
    ]);
}

function approvalUiWave(string $status = LegacyRmeWaveStatus::DRAFT): LegacyRmeMigrationWave
{
    // The wave row is only half the state: governance also re-reads the
    // deployment's admission binding, and refuses a wave whose code is not the
    // bound one. Without this the approve POST redirects back with a validation
    // error and the status silently stays DRAFT — which is why the assertions
    // below check for an absence of session errors rather than trusting a 302.
    config()->set('legacy_rme_rollout.admission.wave', 'UI-WAVE');
    legacyRmeApproveWave('UI-APPROVAL-2026-08-16', ['LDK2']);

    return LegacyRmeMigrationWave::factory()->create([
        'code' => 'UI-WAVE',
        'status' => $status,
        'approval_reference' => 'UI-APPROVAL-2026-08-16',
        'approved_branch_codes' => ['LDK2'],
        'created_by' => approvalUiManager()->getKey(),
        'approved_by' => null,
    ]);
}

it('shows the approve control to an approver who cannot manage the rollout', function () {
    // THE REGRESSION. This is the exact account shape used in production:
    // Supervisor RME holds `approve` and none of `manage`.
    $wave = approvalUiWave();

    $this->actingAs(approvalUiChecker())
        ->get(route('settings.rme.migration-operations.show', $wave))
        ->assertOk()
        ->assertSee(route('settings.rme.migration-operations.approve', $wave), escape: false);
});

it('lets that approver actually approve through the canonical UI route', function () {
    // Reaching the button is worth nothing if the POST then fails. The approver
    // is not the creator, so the server-side rule is satisfied too.
    $wave = approvalUiWave();
    $checker = approvalUiChecker();

    // A rejected approval ALSO redirects, so a bare assertRedirect would pass
    // against a wave that never moved. The absence of session errors is what
    // distinguishes "approved" from "refused and sent back".
    $this->actingAs($checker)
        ->post(route('settings.rme.migration-operations.approve', $wave))
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $wave->refresh();

    expect($wave->status)->toBe(LegacyRmeWaveStatus::APPROVED)
        ->and($wave->approved_by)->toBe($checker->getKey())
        ->and($wave->approved_by)->not->toBe($wave->created_by);
});

it('does not show the approve control to a manager who may not approve', function () {
    // The fix must not over-correct into showing the action to everyone who can
    // see the page — that would re-merge the two halves from the other side.
    $wave = approvalUiWave();

    $this->actingAs(approvalUiManager())
        ->get(route('settings.rme.migration-operations.show', $wave))
        ->assertOk()
        ->assertDontSee(route('settings.rme.migration-operations.approve', $wave), escape: false);
});

it('hides the approve control once the wave is terminal', function () {
    // Gating on the POLICY rather than the bare permission means the button is
    // absent exactly when the action would be refused, instead of offering an
    // approval that 403s.
    $wave = approvalUiWave(LegacyRmeWaveStatus::COMPLETED);

    $this->actingAs(approvalUiChecker())
        ->get(route('settings.rme.migration-operations.show', $wave))
        ->assertOk()
        ->assertDontSee(route('settings.rme.migration-operations.approve', $wave), escape: false);
});
