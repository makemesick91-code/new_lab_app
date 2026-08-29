<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| FEATURE-DAILY-BRANCH-CONTEXT-LOCK-1 — the lock survives every bypass we
| could think of.
|--------------------------------------------------------------------------
|
| The whole point of putting the authority in the database rather than the
| session: a disabled dropdown stops an honest operator and nobody else. Each
| test here is an attack, driven through the real HTTP endpoints wherever the
| attack is an HTTP one.
*/

use App\Modules\Branch\Models\Branch;
use App\Modules\Branch\Services\BranchContext;
use App\Modules\RmeOnlineContext\Models\BranchChangeRequest;
use App\Modules\RmeOnlineContext\Models\DailyBranchContext;
use App\Modules\RmeOnlineContext\Models\UserOnlineContext;
use App\Modules\RmeOnlineContext\Services\UserOnlineContextService;
use Carbon\Carbon;

require_once __DIR__.'/helpers.php';

beforeEach(function () {
    seedAccessControl();
});

afterEach(function () {
    Carbon::setTestNow();
});

/*
|--------------------------------------------------------------------------
| LOGOUT / LOGIN
|--------------------------------------------------------------------------
*/

it('keeps the lock across a logout and a fresh login', function () {
    $a = dbcBranch('AAA');
    $b = dbcBranch('BBB');
    $user = userInRole('Kasir');

    $this->actingAs($user)
        ->post(route('rme.online-context.kasir'), ['branch_id' => $a->id])
        ->assertRedirect();

    $this->post(route('logout'));

    // A brand new authenticated session. Nothing of the old one survives —
    // except the authority, which was never in the session.
    $this->actingAs($user->fresh())
        ->post(route('rme.online-context.kasir'), ['branch_id' => $b->id])
        ->assertSessionHasErrors('branch_id');

    expect((int) dbcDaily()->lockedBranchIdFor($user))->toBe((int) $a->id);
});

it('keeps the lock when the operator goes offline and comes back', function () {
    $a = dbcBranch('AAA');
    $b = dbcBranch('BBB');
    $user = userInRole('Kasir');

    $this->actingAs($user)->post(route('rme.online-context.kasir'), ['branch_id' => $a->id]);
    $this->post(route('rme.online-context.offline'))->assertRedirect();

    $this->post(route('rme.online-context.kasir'), ['branch_id' => $b->id])
        ->assertSessionHasErrors('branch_id');

    // Going offline and back on at the SAME branch is of course fine.
    $this->post(route('rme.online-context.kasir'), ['branch_id' => $a->id])
        ->assertSessionHasNoErrors();
});

/*
|--------------------------------------------------------------------------
| A SECOND SESSION / DEVICE
|--------------------------------------------------------------------------
*/

it('refuses a second session trying a different branch', function () {
    $a = dbcBranch('AAA');
    $b = dbcBranch('BBB');
    $user = userInRole('Kasir');

    // Session A commits the day.
    $this->actingAs($user)->post(route('rme.online-context.kasir'), ['branch_id' => $a->id]);

    // Session B — a different browser, a different device. Same authority.
    $this->flushSession();
    $this->actingAs($user->fresh())
        ->post(route('rme.online-context.kasir'), ['branch_id' => $b->id])
        ->assertSessionHasErrors('branch_id');

    expect(DailyBranchContext::query()->where('user_id', $user->id)->count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| THE SESSION IS NOT THE AUTHORITY
|--------------------------------------------------------------------------
*/

it('lets the database override a tampered online-context row', function () {
    $a = dbcBranch('AAA');
    $b = dbcBranch('BBB');
    $user = userInRole('Kasir');

    dbcStart($user, $a);

    // Simulate any path that writes the session-scoped context row without
    // passing the guard — a stale row, a future refactor, a direct write.
    UserOnlineContext::query()
        ->where('user_id', $user->id)
        ->update(['branch_id' => $b->id]);

    $this->actingAs($user);

    // The durable daily context wins. The tampered row does not move the scope.
    expect(app(BranchContext::class)->forUser($user))->toBe((int) $a->id)
        ->and(app(UserOnlineContextService::class)->activeContextBranchId($user))->toBe((int) $a->id);
});

it('leaves an already-online operator undisturbed until their next selection', function () {
    // THE DEPLOYMENT-DAY PATH, and the reason the override is a replacement
    // rather than a gate. On the day this ships, every Kasir and Admin Klinik
    // already has a live online context and NO daily context yet. Their working
    // branch must keep resolving exactly as before — the lock starts at their
    // next selection, it does not strand anyone mid-shift.
    $a = dbcBranch('AAA');
    $user = userInRole('Kasir');

    dbcStart($user, $a);

    // Simulate the pre-migration state: a live session, no daily context.
    DailyBranchContext::query()->where('user_id', $user->id)->delete();

    $this->actingAs($user);

    expect(app(UserOnlineContextService::class)->activeContextBranchId($user))->toBe((int) $a->id)
        ->and(app(BranchContext::class)->forUser($user))->toBe((int) $a->id);

    // And that next selection is a free first choice, as on any new day.
    $b = dbcBranch('BBB');
    dbcStart($user, $b);

    expect((int) dbcDaily()->lockedBranchIdFor($user))->toBe((int) $b->id);
});

it('fails closed when the locked branch is deactivated, rather than falling back to the session row', function () {
    // Found by self-review, not by the first draft of these tests. If the branch
    // a day is committed to loses its eligibility, the override must produce NO
    // working context — falling back to whatever the session row says would turn
    // a deactivated branch into a route back to an arbitrary one.
    $a = dbcBranch('AAA');
    $b = dbcBranch('BBB');
    $user = userInRole('Kasir');

    dbcStart($user, $a);

    // A tampered session row pointing somewhere else...
    UserOnlineContext::query()->where('user_id', $user->id)->update(['branch_id' => $b->id]);
    // ...and the locked branch is deactivated.
    $a->update(['is_active' => false]);

    $this->actingAs($user);

    expect(app(UserOnlineContextService::class)->activeContextBranchId($user))->toBeNull();
});

it('does not pin an unlocked role with a daily context left over from a locked one', function () {
    // A user who worked as a Kasir this morning and is later Perawat-only must
    // not stay pinned by a context that no longer governs them — Perawat is
    // deliberately not a locked role.
    $a = dbcBranch('AAA');
    $b = dbcBranch('BBB');
    $user = userInRole('Kasir');

    dbcStart($user, $a);

    $user->removeRole('Kasir');
    $user->assignRole('Perawat');
    $user = $user->fresh();

    // Perawat selection is unguarded, so this succeeds...
    dbcStart($user, $b, 'perawat');

    $this->actingAs($user);

    // ...and the stale daily context does not drag the scope back to AAA.
    expect(app(UserOnlineContextService::class)->activeContextBranchId($user))->toBe((int) $b->id)
        ->and(app(BranchContext::class)->forUser($user))->toBe((int) $b->id);
});

it('does not resurrect a working context for an offline operator', function () {
    $a = dbcBranch('AAA');
    $user = userInRole('Kasir');

    dbcStart($user, $a);
    app(UserOnlineContextService::class)->markOffline($user);

    // The daily context still says AAA, but the operator is offline: the
    // authority replaces a branch, it never invents a session.
    expect(app(UserOnlineContextService::class)->activeContextBranchId($user))->toBeNull();
    expect((int) dbcDaily()->lockedBranchIdFor($user))->toBe((int) $a->id);
});

/*
|--------------------------------------------------------------------------
| DIRECT REQUEST, NO UI
|--------------------------------------------------------------------------
*/

it('refuses a raw POST at the context endpoint even though the UI hides the option', function () {
    $a = dbcBranch('AAA');
    $b = dbcBranch('BBB');
    $user = userInRole('Kasir');

    $this->actingAs($user)->post(route('rme.online-context.kasir'), ['branch_id' => $a->id]);

    // Go offline so the selector renders rather than redirecting an operator
    // who already has a satisfied context straight to the dashboard.
    $this->post(route('rme.online-context.offline'));

    // The selector now states the lock and offers exactly one branch...
    $page = $this->get(route('rme.online-context.select'));
    $page->assertOk();
    $page->assertSee('terkunci di', false);
    $page->assertSee('Cabang AAA');
    $page->assertDontSee('Cabang BBB');

    // ...and posting the branch it never offered is still refused server-side.
    // The hidden option is a courtesy; this is the boundary.
    $this->post(route('rme.online-context.kasir'), ['branch_id' => $b->id])
        ->assertSessionHasErrors('branch_id');

    expect((int) dbcDaily()->lockedBranchIdFor($user))->toBe((int) $a->id);
});

it('refuses an admin klinik raw POST at its own context endpoint', function () {
    $a = dbcBranch('AAA');
    $b = dbcBranch('BBB');
    $user = userInRole('Admin Klinik');

    $this->actingAs($user)->post(route('rme.online-context.admin-clinic'), ['branch_id' => $a->id]);

    $this->post(route('rme.online-context.admin-clinic'), ['branch_id' => $b->id])
        ->assertSessionHasErrors('branch_id');

    expect((int) dbcDaily()->lockedBranchIdFor($user))->toBe((int) $a->id);
});

/*
|--------------------------------------------------------------------------
| REQUEST PAYLOAD TAMPERING
|--------------------------------------------------------------------------
*/

it('ignores a forged source branch, requester, clinical date and status in the request payload', function () {
    $a = dbcBranch('AAA');
    $b = dbcBranch('BBB');
    $c = dbcBranch('CCC');
    $user = userInRole('Kasir');
    $victim = userInRole('Kasir');
    dbcStart($user, $a);
    dbcStart($victim, $c);

    $this->actingAs($user)->post(route('rme.branch-change-requests.store'), [
        'destination_branch_id' => $b->id,
        'reason' => 'Alasan yang sah sepenuhnya.',
        // Every one of these is an attempt to become the authority.
        'source_branch_id' => $c->id,
        'requester_user_id' => $victim->id,
        'clinical_date' => '2030-01-01',
        'role_context' => 'doctor',
        'status' => BranchChangeRequest::STATUS_APPROVED,
        'decided_by_user_id' => $user->id,
        'applied_at' => now()->toDateTimeString(),
        'change_count' => 99,
    ])->assertRedirect();

    $request = BranchChangeRequest::query()->latest('id')->firstOrFail();

    expect($request->status)->toBe(BranchChangeRequest::STATUS_PENDING)
        ->and((int) $request->requester_user_id)->toBe((int) $user->id)
        ->and((int) $request->source_branch_id)->toBe((int) $a->id)
        ->and($request->clinical_date->toDateString())->toBe(dbcClinicalToday())
        ->and($request->role_context)->toBe('kasir')
        ->and($request->decided_by_user_id)->toBeNull()
        ->and($request->applied_at)->toBeNull();

    // And the forged approval moved nothing.
    expect((int) dbcDaily()->lockedBranchIdFor($user))->toBe((int) $a->id)
        ->and((int) dbcDaily()->lockedBranchIdFor($victim))->toBe((int) $c->id);
});

it('refuses a request whose destination is not an eligible branch', function () {
    $a = dbcBranch('AAA');
    $nonRme = Branch::factory()->create([
        'code' => 'NORME',
        'is_active' => true,
        'is_rme_enabled' => false,
    ]);
    $user = userInRole('Kasir');
    dbcStart($user, $a);

    $this->actingAs($user)->post(route('rme.branch-change-requests.store'), [
        'destination_branch_id' => $nonRme->id,
        'reason' => 'Mencoba cabang non-RME.',
    ])->assertSessionHasErrors('destination_branch_id');

    expect(BranchChangeRequest::query()->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| APPROVAL AUTHORIZATION OVER HTTP
|--------------------------------------------------------------------------
*/

it('refuses the approval queue and the approve action to a non-super-admin', function () {
    $a = dbcBranch('AAA');
    $b = dbcBranch('BBB');
    $user = userInRole('Kasir');
    $other = userInRole('Admin Klinik');
    dbcStart($user, $a);
    dbcStart($other, $a, 'admin_clinic');

    $request = dbcApprovals()->request($user, (int) $b->id, 'Butuh persetujuan.');

    foreach ([$user, $other, userInRole('Owner'), userInRole('Doctor')] as $actor) {
        $this->actingAs($actor->fresh())
            ->get(route('rme.branch-change-requests.index'))
            ->assertForbidden();

        $this->actingAs($actor->fresh())
            ->post(route('rme.branch-change-requests.approve', $request))
            ->assertForbidden();
    }

    expect($request->fresh()->status)->toBe(BranchChangeRequest::STATUS_PENDING)
        ->and((int) dbcDaily()->lockedBranchIdFor($user))->toBe((int) $a->id);
});

it('denies the approve gate itself to every non-super-admin role', function () {
    // The gate, asserted DIRECTLY rather than through the policy behind it.
    //
    // Mutation testing found this gap: widening the gate to `fn () => true` left
    // every HTTP assertion green, because BranchChangeRequestPolicy refused the
    // action a layer later. Defence in depth is why nothing broke — but the gate
    // is also what the sidebar reads, so a widened gate would advertise the
    // approver menu to operators who cannot use it, and would remove one of the
    // two layers with nothing turning red.
    foreach (['Kasir', 'Admin Klinik', 'Perawat', 'Doctor', 'Owner', 'Supervisor RME', 'Admin Lab'] as $role) {
        expect(userInRole($role)->can('branch-change-request.approve'))
            ->toBeFalse("role {$role} must not hold the branch-change approval gate");
    }

    expect(dbcSuperAdmin()->can('branch-change-request.approve'))->toBeTrue();
});

it('keeps the approver menu out of a non-super-admin sidebar', function () {
    $a = dbcBranch('AAA');
    $user = userInRole('Kasir');
    dbcStart($user, $a);

    $this->actingAs($user)
        ->get(route('rme.branch-change-requests.create'))
        ->assertOk()
        ->assertDontSee('Permintaan Pindah Cabang');
});

it('lets a super admin approve over HTTP and moves the branch', function () {
    $a = dbcBranch('AAA');
    $b = dbcBranch('BBB');
    $user = userInRole('Kasir');
    dbcStart($user, $a);

    $request = dbcApprovals()->request($user, (int) $b->id, 'Butuh persetujuan.');

    $this->actingAs(dbcSuperAdmin())
        ->get(route('rme.branch-change-requests.index'))
        ->assertOk()
        ->assertSee('Cabang BBB');

    $this->post(route('rme.branch-change-requests.approve', $request), [
        'decision_note' => 'Disetujui.',
    ])->assertRedirect(route('rme.branch-change-requests.index'));

    expect((int) dbcDaily()->lockedBranchIdFor($user))->toBe((int) $b->id);
});

it('refuses another users pending request to be cancelled over HTTP', function () {
    $a = dbcBranch('AAA');
    $b = dbcBranch('BBB');
    $owner = userInRole('Kasir');
    $stranger = userInRole('Kasir');
    dbcStart($owner, $a);
    dbcStart($stranger, $a);

    $request = dbcApprovals()->request($owner, (int) $b->id, 'Permintaan milik orang lain.');

    $this->actingAs($stranger)
        ->post(route('rme.branch-change-requests.cancel', $request))
        ->assertForbidden();

    expect($request->fresh()->status)->toBe(BranchChangeRequest::STATUS_PENDING);
});

/*
|--------------------------------------------------------------------------
| THE OPERATOR SURFACE
|--------------------------------------------------------------------------
*/

it('shows the locked branch and the request route to a locked operator', function () {
    $a = dbcBranch('AAA');
    $user = userInRole('Kasir');
    dbcStart($user, $a);

    $this->actingAs($user)
        ->get(route('rme.branch-change-requests.create'))
        ->assertOk()
        ->assertSee('Cabang AAA')
        ->assertSee('Cabang kerja terkunci untuk hari ini.', false);
});

it('refuses the request form to a user with no locked context', function () {
    // A Perawat is never locked, so there is nothing for them to request.
    $a = dbcBranch('AAA');
    $user = userInRole('Perawat');
    dbcStart($user, $a, 'perawat');

    $this->actingAs($user)
        ->get(route('rme.branch-change-requests.create'))
        ->assertForbidden();
});
