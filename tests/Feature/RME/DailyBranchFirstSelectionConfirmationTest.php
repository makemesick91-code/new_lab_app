<?php

/**
 * DAENGTISIAMS-SUNU-FINAL-RELEASE-CANDIDATE-1 / D5 — the first branch choice
 * of a clinical day must be confirmed before it is committed.
 *
 * The daily lock is UNIQUE(user_id, clinical_date) and undoing a wrong pick
 * needs a Super Admin to approve a branch-change request. That is deliberate
 * and stays. What it means in practice, though, is that a mis-click on the
 * very first selection of the day costs an approval round — and on a branch
 * opening for the first time, with staff who have never seen the selector,
 * that is the expected failure rather than an unlikely one.
 *
 * The guard is in the FormRequest specifically so it runs BEFORE the service
 * that writes the lock. A check inside the service would be racing the write
 * it is supposed to guard.
 *
 * Scope note: only Kasir and Admin Klinik are locked roles
 * (DailyBranchContextService::LOCKED_ROLE_CONTEXTS). Perawat and Doctor keep
 * free selection and must NOT start asking for a confirmation.
 */

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\RmeOnlineContext\Models\DailyBranchContext;
use App\Modules\RmeOnlineContext\Services\DailyBranchContextService;
use Database\Seeders\BranchSeeder;

beforeEach(function () {
    test()->seed(BranchSeeder::class);
    seedAccessControl();

    Branch::where('code', Branch::MAIN_CODE)->update(['is_rme_enabled' => false]);
    $this->sunu = Branch::factory()->create(['code' => 'SPN4', 'name' => 'Cabang Sunu', 'is_rme_enabled' => true]);
    $this->other = Branch::factory()->create(['code' => 'TLK1', 'name' => 'Cabang Telkomas', 'is_rme_enabled' => true]);
});

function d5Kasir(): User
{
    $user = User::factory()->create(['name' => 'Kasir Sunu']);
    $user->assignRole('Kasir');

    return $user->fresh();
}

function d5LockCount(User $user): int
{
    return DailyBranchContext::query()->where('user_id', $user->id)->count();
}

const D5_CONFIRM = DailyBranchContextService::CONFIRMATION_FIELD;

// ─── The lock must not exist before confirmation ────────────────────────────

it('does not lock the day when the branch was chosen but not confirmed', function () {
    $kasir = d5Kasir();

    $this->actingAs($kasir)
        ->post(route('rme.online-context.kasir'), ['branch_id' => $this->sunu->id])
        ->assertSessionHasErrors(D5_CONFIRM);

    expect(d5LockCount($kasir))->toBe(0, 'the day was committed without a confirmation');
});

it('locks the day exactly once when the branch is confirmed', function () {
    $kasir = d5Kasir();

    $this->actingAs($kasir)->post(route('rme.online-context.kasir'), [
        'branch_id' => $this->sunu->id,
        D5_CONFIRM => $this->sunu->id,
    ])->assertSessionHasNoErrors();

    expect(d5LockCount($kasir))->toBe(1)
        ->and((int) DailyBranchContext::query()->where('user_id', $kasir->id)->value('current_branch_id'))
        ->toBe((int) $this->sunu->id);
});

// ─── The confirmation has to name the branch actually being committed ───────

it('refuses a confirmation that names a different branch from the selection', function () {
    $kasir = d5Kasir();

    // A stale form, a changed dropdown, or a tampered field: the operator
    // confirmed Telkomas and the submit would have committed Sunu.
    $this->actingAs($kasir)
        ->post(route('rme.online-context.kasir'), [
            'branch_id' => $this->sunu->id,
            D5_CONFIRM => $this->other->id,
        ])
        ->assertSessionHasErrors(D5_CONFIRM);

    expect(d5LockCount($kasir))->toBe(0);
});

it('refuses an empty confirmation token', function () {
    $kasir = d5Kasir();

    $this->actingAs($kasir)
        ->post(route('rme.online-context.kasir'), [
            'branch_id' => $this->sunu->id,
            D5_CONFIRM => '',
        ])
        ->assertSessionHasErrors(D5_CONFIRM);

    expect(d5LockCount($kasir))->toBe(0);
});

// ─── Races and repeats ──────────────────────────────────────────────────────

it('produces one lock, not two, when the confirmed submit is repeated', function () {
    $kasir = d5Kasir();
    $payload = ['branch_id' => $this->sunu->id, D5_CONFIRM => $this->sunu->id];

    $this->actingAs($kasir)->post(route('rme.online-context.kasir'), $payload);
    $this->actingAs($kasir)->post(route('rme.online-context.kasir'), $payload);

    expect(d5LockCount($kasir))->toBe(1, 'a double submit created a second lock');
});

it('stops asking for confirmation once the day is already locked', function () {
    $kasir = d5Kasir();

    $this->actingAs($kasir)->post(route('rme.online-context.kasir'), [
        'branch_id' => $this->sunu->id,
        D5_CONFIRM => $this->sunu->id,
    ])->assertSessionHasNoErrors();

    // Re-selecting the SAME branch later in the day is not a new commitment,
    // so it must not be blocked on a confirmation the operator already gave.
    $this->actingAs($kasir)
        ->post(route('rme.online-context.kasir'), ['branch_id' => $this->sunu->id])
        ->assertSessionHasNoErrors();

    expect(d5LockCount($kasir))->toBe(1);
});

// ─── The guard must not leak to unlocked roles ──────────────────────────────

it('leaves Perawat free selection untouched', function () {
    $perawat = User::factory()->create(['name' => 'Perawat Sunu']);
    $perawat->assignRole('Perawat');

    // Perawat is deliberately NOT a locked role context, so no confirmation
    // token is required and no daily lock is written.
    $this->actingAs($perawat)
        ->post(route('rme.online-context.perawat'), ['branch_id' => $this->sunu->id])
        ->assertSessionHasNoErrors();

    expect(d5LockCount($perawat))->toBe(0);
});
