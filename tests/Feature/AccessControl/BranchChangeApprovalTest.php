<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| FEATURE-DAILY-BRANCH-CONTEXT-LOCK-1 — the Super Admin approval workflow.
|--------------------------------------------------------------------------
|
| An approval authorises ONE move: this user, from this branch, to this branch,
| on this clinical day, once. Every one of those five bindings gets its own
| failing case here, because a binding that is only asserted in a comment is not
| a binding.
*/

use App\Modules\Branch\Models\Branch;
use App\Modules\Branch\Services\BranchContext;
use App\Modules\RmeOnlineContext\Models\BranchChangeRequest;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/helpers.php';

beforeEach(function () {
    seedAccessControl();
});

afterEach(function () {
    Carbon::setTestNow();
});

/*
|--------------------------------------------------------------------------
| Filing a request
|--------------------------------------------------------------------------
*/

it('files a pending request and derives every binding server-side', function () {
    $a = dbcBranch('AAA');
    $b = dbcBranch('BBB');
    $user = userInRole('Kasir');
    dbcStart($user, $a);

    $request = dbcApprovals()->request($user, (int) $b->id, 'Menggantikan kasir yang berhalangan.');

    expect($request->status)->toBe(BranchChangeRequest::STATUS_PENDING)
        ->and((int) $request->requester_user_id)->toBe((int) $user->id)
        // The source was NOT supplied by the caller — it came from the live context.
        ->and((int) $request->source_branch_id)->toBe((int) $a->id)
        ->and((int) $request->destination_branch_id)->toBe((int) $b->id)
        ->and($request->clinical_date->toDateString())->toBe(dbcClinicalToday())
        ->and($request->role_context)->toBe('kasir')
        ->and($request->applied_at)->toBeNull();
});

it('leaves the working branch untouched while a request is pending', function () {
    $a = dbcBranch('AAA');
    $b = dbcBranch('BBB');
    $user = userInRole('Kasir');
    dbcStart($user, $a);

    dbcApprovals()->request($user, (int) $b->id, 'Menunggu persetujuan dulu.');

    $this->actingAs($user);

    expect((int) dbcDaily()->lockedBranchIdFor($user))->toBe((int) $a->id)
        ->and(app(BranchContext::class)->forUser($user))->toBe((int) $a->id);

    // And the pending request does not itself unlock the selector.
    expect(fn () => dbcStart($user, $b))->toThrow(ValidationException::class);
});

it('refuses a second pending request for the same user and clinical day', function () {
    $a = dbcBranch('AAA');
    $b = dbcBranch('BBB');
    $c = dbcBranch('CCC');
    $user = userInRole('Kasir');
    dbcStart($user, $a);

    dbcApprovals()->request($user, (int) $b->id, 'Permintaan pertama.');

    expect(fn () => dbcApprovals()->request($user, (int) $c->id, 'Permintaan kedua.'))
        ->toThrow(ValidationException::class);

    expect(BranchChangeRequest::query()->where('requester_user_id', $user->id)->count())->toBe(1);
});

it('refuses a request whose destination equals the current branch', function () {
    $a = dbcBranch('AAA');
    $user = userInRole('Kasir');
    dbcStart($user, $a);

    expect(fn () => dbcApprovals()->request($user, (int) $a->id, 'Cabang yang sama.'))
        ->toThrow(ValidationException::class);
});

it('refuses a request from a user with no daily context to move', function () {
    $b = dbcBranch('BBB');
    $user = userInRole('Kasir');

    expect(fn () => dbcApprovals()->request($user, (int) $b->id, 'Belum memilih cabang.'))
        ->toThrow(ValidationException::class);
});

/*
|--------------------------------------------------------------------------
| Approval applies the switch atomically
|--------------------------------------------------------------------------
*/

it('moves the working branch atomically on approval and relocks it there', function () {
    $a = dbcBranch('AAA');
    $b = dbcBranch('BBB');
    $user = userInRole('Kasir');
    dbcStart($user, $a);

    $request = dbcApprovals()->request($user, (int) $b->id, 'Pindah ke cabang BBB.');
    $approved = dbcApprovals()->approve((int) $request->id, dbcSuperAdmin(), 'Disetujui.');

    expect($approved->status)->toBe(BranchChangeRequest::STATUS_APPROVED)
        ->and($approved->applied_at)->not->toBeNull()
        ->and($approved->decided_at)->not->toBeNull();

    $context = dbcDaily()->currentFor($user);

    expect((int) $context->current_branch_id)->toBe((int) $b->id)
        // The initial choice is preserved for the audit trail.
        ->and((int) $context->initial_branch_id)->toBe((int) $a->id)
        ->and((int) $context->change_count)->toBe(1);

    $this->actingAs($user);
    expect(app(BranchContext::class)->forUser($user))->toBe((int) $b->id);
});

it('relocks the destination so the operator cannot go back without a new approval', function () {
    $a = dbcBranch('AAA');
    $b = dbcBranch('BBB');
    $user = userInRole('Kasir');
    dbcStart($user, $a);

    $request = dbcApprovals()->request($user, (int) $b->id, 'Pindah ke BBB.');
    dbcApprovals()->approve((int) $request->id, dbcSuperAdmin());

    // Forward to the approved branch: fine, it is where they now are.
    dbcStart($user, $b);

    // Backward to the branch they started from: refused. An approved move is a
    // move, not a day pass.
    expect(fn () => dbcStart($user, $a))->toThrow(ValidationException::class);
});

it('requires a brand new approval for a third branch later the same day', function () {
    $a = dbcBranch('AAA');
    $b = dbcBranch('BBB');
    $c = dbcBranch('CCC');
    $user = userInRole('Kasir');
    $admin = dbcSuperAdmin();
    dbcStart($user, $a);

    $first = dbcApprovals()->request($user, (int) $b->id, 'Pindah pertama.');
    dbcApprovals()->approve((int) $first->id, $admin);

    // No approval yet for C.
    expect(fn () => dbcStart($user, $c))->toThrow(ValidationException::class);

    $second = dbcApprovals()->request($user, (int) $c->id, 'Pindah kedua.');

    // The SECOND request's source was derived from the moved context, not from
    // where the day began.
    expect((int) $second->source_branch_id)->toBe((int) $b->id);

    dbcApprovals()->approve((int) $second->id, $admin);

    expect((int) dbcDaily()->lockedBranchIdFor($user))->toBe((int) $c->id)
        ->and((int) dbcDaily()->currentFor($user)->change_count)->toBe(2);
});

/*
|--------------------------------------------------------------------------
| Rejection
|--------------------------------------------------------------------------
*/

it('leaves the working branch unchanged on rejection and records the decision', function () {
    $a = dbcBranch('AAA');
    $b = dbcBranch('BBB');
    $user = userInRole('Kasir');
    $admin = dbcSuperAdmin();
    dbcStart($user, $a);

    $request = dbcApprovals()->request($user, (int) $b->id, 'Minta pindah.');
    $rejected = dbcApprovals()->reject((int) $request->id, $admin, 'Tidak perlu hari ini.');

    expect($rejected->status)->toBe(BranchChangeRequest::STATUS_REJECTED)
        ->and((int) $rejected->decided_by_user_id)->toBe((int) $admin->id)
        ->and($rejected->decision_note)->toBe('Tidak perlu hari ini.')
        ->and($rejected->applied_at)->toBeNull();

    expect((int) dbcDaily()->lockedBranchIdFor($user))->toBe((int) $a->id);
    expect(fn () => dbcStart($user, $b))->toThrow(ValidationException::class);
});

it('lets the requester file a fresh request after a rejection', function () {
    $a = dbcBranch('AAA');
    $b = dbcBranch('BBB');
    $user = userInRole('Kasir');
    dbcStart($user, $a);

    $first = dbcApprovals()->request($user, (int) $b->id, 'Permintaan pertama.');
    dbcApprovals()->reject((int) $first->id, dbcSuperAdmin());

    // The partial unique index only constrains PENDING rows, so a decided one
    // does not block a retry.
    $second = dbcApprovals()->request($user, (int) $b->id, 'Permintaan kedua.');

    expect($second->status)->toBe(BranchChangeRequest::STATUS_PENDING);
});

/*
|--------------------------------------------------------------------------
| SINGLE USE / NON-REPLAYABLE
|--------------------------------------------------------------------------
*/

it('cannot be approved twice — the switch applies exactly once', function () {
    $a = dbcBranch('AAA');
    $b = dbcBranch('BBB');
    $user = userInRole('Kasir');
    $admin = dbcSuperAdmin();
    dbcStart($user, $a);

    $request = dbcApprovals()->request($user, (int) $b->id, 'Pindah.');
    dbcApprovals()->approve((int) $request->id, $admin);

    expect(fn () => dbcApprovals()->approve((int) $request->id, $admin))
        ->toThrow(ValidationException::class);

    // change_count is the observable proof the move was not applied twice.
    expect((int) dbcDaily()->currentFor($user)->change_count)->toBe(1);
});

it('cannot be rejected after it has been approved', function () {
    $a = dbcBranch('AAA');
    $b = dbcBranch('BBB');
    $user = userInRole('Kasir');
    $admin = dbcSuperAdmin();
    dbcStart($user, $a);

    $request = dbcApprovals()->request($user, (int) $b->id, 'Pindah.');
    dbcApprovals()->approve((int) $request->id, $admin);

    expect(fn () => dbcApprovals()->reject((int) $request->id, $admin))
        ->toThrow(ValidationException::class);

    expect((int) dbcDaily()->lockedBranchIdFor($user))->toBe((int) $b->id);
});

/*
|--------------------------------------------------------------------------
| STALE SOURCE
|--------------------------------------------------------------------------
*/

it('refuses an approval whose source branch no longer matches the live context', function () {
    $a = dbcBranch('AAA');
    $b = dbcBranch('BBB');
    $c = dbcBranch('CCC');
    $user = userInRole('Kasir');
    $admin = dbcSuperAdmin();
    dbcStart($user, $a);

    // Request A -> C is filed...
    $stale = dbcApprovals()->request($user, (int) $c->id, 'Pindah ke CCC.');

    // ...but A -> B is approved first through a separate request, so the live
    // context is now B and the pending request's starting point is fiction.
    dbcApprovals()->reject((int) $stale->id, $admin);
    $applied = dbcApprovals()->request($user, (int) $b->id, 'Pindah ke BBB.');
    dbcApprovals()->approve((int) $applied->id, $admin);

    // Re-file the C request by hand at the OLD source, simulating a request
    // whose context moved underneath it.
    $refiled = BranchChangeRequest::query()->create([
        'requester_user_id' => (int) $user->id,
        'clinical_date' => dbcClinicalToday(),
        'role_context' => 'kasir',
        'source_branch_id' => (int) $a->id,   // stale: the context is on B now
        'destination_branch_id' => (int) $c->id,
        'reason' => 'Permintaan lama.',
        'requested_at' => now(),
    ]);

    expect(fn () => dbcApprovals()->approve((int) $refiled->id, $admin))
        ->toThrow(ValidationException::class);

    // The context did not move to C.
    expect((int) dbcDaily()->lockedBranchIdFor($user))->toBe((int) $b->id);
});

/*
|--------------------------------------------------------------------------
| CLINICAL DAY BINDING
|--------------------------------------------------------------------------
*/

it('refuses to approve a request from a previous clinical day, with no cron required', function () {
    $a = dbcBranch('AAA');
    $b = dbcBranch('BBB');
    $user = userInRole('Kasir');
    $admin = dbcSuperAdmin();

    Carbon::setTestNow(Carbon::parse('2026-08-29 03:00:00', 'UTC'));
    dbcStart($user, $a);
    $request = dbcApprovals()->request($user, (int) $b->id, 'Permintaan kemarin.');

    // Next clinical day. NOTHING has expired the row: it is still PENDING, so
    // the refusal can only come from the staleness guard itself.
    //
    // An earlier draft stamped the row EXPIRED before opening the decision
    // transaction, which meant this test passed off the isPending() check and
    // the staleness guard was never exercised at all. Mutation testing caught
    // it. Asserting the row is still PENDING at the moment of the attempt is
    // what keeps that from silently coming back.
    Carbon::setTestNow(Carbon::parse('2026-08-30 03:00:00', 'UTC'));
    expect($request->fresh()->status)->toBe(BranchChangeRequest::STATUS_PENDING);

    try {
        dbcApprovals()->approve((int) $request->id, $admin);
        $this->fail('A previous-day request must never be approved.');
    } catch (ValidationException $exception) {
        expect(implode(' ', $exception->validator->errors()->all()))
            ->toContain('kedaluwarsa');
    }

    // Refused, and nothing moved. On the new clinical day the user has no
    // context at all yet — deliberately NOT cast to int, which would turn the
    // null this asserts into a 0.
    expect(dbcDaily()->lockedBranchIdFor($user))->toBeNull();
    expect($request->fresh()->status)->toBe(BranchChangeRequest::STATUS_PENDING);
});

it('refuses to reject a request from a previous clinical day too', function () {
    $a = dbcBranch('AAA');
    $b = dbcBranch('BBB');
    $user = userInRole('Kasir');

    Carbon::setTestNow(Carbon::parse('2026-08-29 03:00:00', 'UTC'));
    dbcStart($user, $a);
    $request = dbcApprovals()->request($user, (int) $b->id, 'Permintaan kemarin.');

    Carbon::setTestNow(Carbon::parse('2026-08-30 03:00:00', 'UTC'));

    expect(fn () => dbcApprovals()->reject((int) $request->id, dbcSuperAdmin()))
        ->toThrow(ValidationException::class);

    expect($request->fresh()->status)->toBe(BranchChangeRequest::STATUS_PENDING);
});

it('expires a stale request through the queue listing, as housekeeping', function () {
    // The bookkeeping half, kept deliberately separate from the boundary above.
    // The queue stops offering a request nobody can act on; the refusal does not
    // depend on this having run.
    $a = dbcBranch('AAA');
    $b = dbcBranch('BBB');
    $user = userInRole('Kasir');

    Carbon::setTestNow(Carbon::parse('2026-08-29 03:00:00', 'UTC'));
    dbcStart($user, $a);
    $request = dbcApprovals()->request($user, (int) $b->id, 'Permintaan kemarin.');

    Carbon::setTestNow(Carbon::parse('2026-08-30 03:00:00', 'UTC'));

    $this->actingAs(dbcSuperAdmin())
        ->get(route('rme.branch-change-requests.index'))
        ->assertOk();

    expect($request->fresh()->status)->toBe(BranchChangeRequest::STATUS_EXPIRED);
});

it('does not let a previous-day approval authorise a switch today', function () {
    $a = dbcBranch('AAA');
    $b = dbcBranch('BBB');
    $c = dbcBranch('CCC');
    $user = userInRole('Kasir');
    $admin = dbcSuperAdmin();

    Carbon::setTestNow(Carbon::parse('2026-08-29 03:00:00', 'UTC'));
    dbcStart($user, $a);
    $yesterday = dbcApprovals()->request($user, (int) $b->id, 'Pindah kemarin.');
    dbcApprovals()->approve((int) $yesterday->id, $admin);

    // New clinical day: a fresh free selection, and yesterday's approval is spent.
    Carbon::setTestNow(Carbon::parse('2026-08-30 03:00:00', 'UTC'));
    dbcStart($user, $a);

    expect(fn () => dbcStart($user, $c))->toThrow(ValidationException::class);
    // Even the branch yesterday's approval named is refused today.
    expect(fn () => dbcStart($user, $b))->toThrow(ValidationException::class);
});

/*
|--------------------------------------------------------------------------
| DESTINATION ELIGIBILITY
|--------------------------------------------------------------------------
*/

it('refuses to approve a move to a branch deactivated after the request was filed', function () {
    $a = dbcBranch('AAA');
    $b = dbcBranch('BBB');
    $user = userInRole('Kasir');
    dbcStart($user, $a);

    $request = dbcApprovals()->request($user, (int) $b->id, 'Pindah ke BBB.');

    // The destination stops being eligible between filing and decision.
    $b->update(['is_active' => false]);

    expect(fn () => dbcApprovals()->approve((int) $request->id, dbcSuperAdmin()))
        ->toThrow(ValidationException::class);

    expect((int) dbcDaily()->lockedBranchIdFor($user))->toBe((int) $a->id);
});

it('refuses to approve a move to a non-RME branch — approval is not a branch grant', function () {
    $a = dbcBranch('AAA');
    $nonRme = Branch::factory()->create([
        'code' => 'MAIN2',
        'is_active' => true,
        'is_rme_enabled' => false,
    ]);
    $b = dbcBranch('BBB');
    $user = userInRole('Kasir');
    dbcStart($user, $a);

    $request = dbcApprovals()->request($user, (int) $b->id, 'Pindah.');
    // Rewrite the destination to a branch the requester could never work in.
    $request->forceFill(['destination_branch_id' => (int) $nonRme->id])->save();

    expect(fn () => dbcApprovals()->approve((int) $request->id, dbcSuperAdmin()))
        ->toThrow(ValidationException::class);

    expect((int) dbcDaily()->lockedBranchIdFor($user))->toBe((int) $a->id);
});

/*
|--------------------------------------------------------------------------
| SELF APPROVAL
|--------------------------------------------------------------------------
*/

it('refuses an approval where the approver is the requester', function () {
    $a = dbcBranch('AAA');
    $b = dbcBranch('BBB');
    $user = userInRole('Kasir');
    dbcStart($user, $a);

    $request = dbcApprovals()->request($user, (int) $b->id, 'Pindah sendiri.');

    // The service compares requester and approver directly, so this holds even
    // for an actor a Gate::before bypass would have waved through.
    expect(fn () => dbcApprovals()->approve((int) $request->id, $user))
        ->toThrow(ValidationException::class);

    expect((int) dbcDaily()->lockedBranchIdFor($user))->toBe((int) $a->id);
});

it('refuses self approval even when the requester also holds Super Admin', function () {
    $a = dbcBranch('AAA');
    $b = dbcBranch('BBB');
    $user = userInRole('Kasir');
    dbcStart($user, $a);

    $request = dbcApprovals()->request($user, (int) $b->id, 'Pindah sendiri.');

    // Granting Super Admin AFTER the context exists is the only way to reach
    // this shape — the online-context service exempts Super Admin, so such a
    // user could never have obtained a daily context in the first place. The
    // service boundary must still hold.
    $user->assignRole('Super Admin');

    expect(fn () => dbcApprovals()->approve((int) $request->id, $user->fresh()))
        ->toThrow(ValidationException::class);

    expect((int) dbcDaily()->lockedBranchIdFor($user))->toBe((int) $a->id);
});

/*
|--------------------------------------------------------------------------
| Cancellation
|--------------------------------------------------------------------------
*/

it('lets a requester cancel their own pending request but not someone elses', function () {
    $a = dbcBranch('AAA');
    $b = dbcBranch('BBB');
    $mine = userInRole('Kasir');
    $theirs = userInRole('Kasir');
    dbcStart($mine, $a);
    dbcStart($theirs, $a);

    $request = dbcApprovals()->request($mine, (int) $b->id, 'Permintaan saya.');

    expect(fn () => dbcApprovals()->cancel((int) $request->id, $theirs))
        ->toThrow(ValidationException::class);

    $cancelled = dbcApprovals()->cancel((int) $request->id, $mine);

    expect($cancelled->status)->toBe(BranchChangeRequest::STATUS_CANCELLED);
    expect((int) dbcDaily()->lockedBranchIdFor($mine))->toBe((int) $a->id);
});

/*
|--------------------------------------------------------------------------
| USER BINDING
|--------------------------------------------------------------------------
*/

it('applies an approval only to the requester, never to another user on the same branches', function () {
    $a = dbcBranch('AAA');
    $b = dbcBranch('BBB');
    $requester = userInRole('Kasir');
    $bystander = userInRole('Kasir');
    dbcStart($requester, $a);
    dbcStart($bystander, $a);

    $request = dbcApprovals()->request($requester, (int) $b->id, 'Pindah.');
    dbcApprovals()->approve((int) $request->id, dbcSuperAdmin());

    expect((int) dbcDaily()->lockedBranchIdFor($requester))->toBe((int) $b->id)
        // Same source, same destination, same day — and completely unaffected.
        ->and((int) dbcDaily()->lockedBranchIdFor($bystander))->toBe((int) $a->id);

    expect(fn () => dbcStart($bystander, $b))->toThrow(ValidationException::class);
});

/*
|--------------------------------------------------------------------------
| AUDIT TRAIL
|--------------------------------------------------------------------------
*/

it('records who asked, who decided and what moved', function () {
    $a = dbcBranch('AAA');
    $b = dbcBranch('BBB');
    $user = userInRole('Kasir');
    $admin = dbcSuperAdmin();
    dbcStart($user, $a);

    $request = dbcApprovals()->request($user, (int) $b->id, 'Alasan operasional.');
    $approved = dbcApprovals()->approve((int) $request->id, $admin, 'Catatan keputusan.');

    expect((int) $approved->requester_user_id)->toBe((int) $user->id)
        ->and((int) $approved->decided_by_user_id)->toBe((int) $admin->id)
        ->and($approved->reason)->toBe('Alasan operasional.')
        ->and($approved->decision_note)->toBe('Catatan keputusan.')
        ->and($approved->requested_at)->not->toBeNull()
        ->and($approved->decided_at)->not->toBeNull()
        ->and($approved->applied_at)->not->toBeNull();

    $this->assertDatabaseHas('sys_audit_logs', [
        'entity_type' => 'trx_branch_change_requests',
        'entity_id' => $approved->id,
        'action' => 'BRANCH_CHANGE_APPROVED',
        'performed_by' => $admin->id,
    ]);

    $this->assertDatabaseHas('sys_audit_logs', [
        'entity_type' => 'trx_branch_change_requests',
        'entity_id' => $request->id,
        'action' => 'BRANCH_CHANGE_REQUESTED',
        'performed_by' => $user->id,
    ]);
});
