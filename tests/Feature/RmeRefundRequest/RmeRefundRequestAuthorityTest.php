<?php

/*
| FEATURE-RME-BILLING-REFUND-REQUEST-APPROVAL-1 — authority (owner RF-1, RF-1a,
| RF-8, RF-8a): separation of duties, branch isolation, roles, actor validity,
| oversight, privacy and the structural guarantees (TDD RR-SOD / RR-BR / RR-ROLE /
| RR-ACT / RR-OVS / RR-PRIV / RR-SEC).
*/

use App\Models\User;
use App\Modules\LabOrder\Models\AuditLog;
use App\Modules\RmeInvoice\Models\RmeRefundRequest;
use App\Modules\RmeInvoice\Models\RmeRefundRequestEvent;
use App\Modules\RmeInvoice\Services\RmeRefundRequestService;
use App\Modules\RmeInvoice\Support\RmeFinancialAuditEvent;
use App\Modules\RmeInvoice\Support\RmeRefundRequestRefused as R;
use App\Modules\RmeInvoice\Support\RmeRefundRequestStatus as S;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

require_once __DIR__.'/helpers.php';

beforeEach(function () {
    rrrSetup();
});

/** A request filed on the fixture invoice, then the obligation and request. */
function rrrFiled(): array
{
    [$invoice, $obligation] = rrrObligation();

    return [$invoice, $obligation, rrrSubmit($invoice, $obligation)];
}

// ─── RR-SOD ──────────────────────────────────────────────────────────────────

it('RR-SOD-01 / RR-SOD-02: the maker can neither approve nor reject its own request', function () {
    [, , $request] = rrrFiled();
    // The maker ALSO holds a reviewer role for the branch and is designated as a
    // reviewer, so maker ≠ checker is the only rule left that can stop it.
    test()->frontOffice->assignRole('Kepala Cabang');
    test()->frontOffice->forceFill(['branch_id' => test()->branch->id])->save();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    rrrIdentities([], [(int) test()->frontOffice->id]);

    rrrRefused(fn () => rrrApprove($request, test()->frontOffice->fresh()), R::SELF_APPROVAL_FORBIDDEN);
    rrrRefused(fn () => rrrReject($request, test()->frontOffice->fresh()), R::SELF_DECISION_FORBIDDEN);
    expect($request->fresh()->status)->toBe(S::REQUESTED);
});

it('RR-SOD-03: Super Admin cannot approve a request it filed — refused in the service, before the database', function () {
    [$invoice, $obligation, $request] = rrrFiled();
    DB::table('trx_rme_refund_requests')->where('id', $request->id)->update(['requested_by' => test()->superAdmin->id]);

    rrrRefused(fn () => rrrApprove($request->fresh(), test()->superAdmin), R::SELF_APPROVAL_FORBIDDEN);
    expect($request->fresh()->status)->toBe(S::REQUESTED);
});

it('RR-SOD-05: Super Admin approves a request another account filed (owner-approved role scope)', function () {
    [, , $request] = rrrFiled();

    expect(rrrApprove($request, test()->superAdmin)->status)->toBe(S::APPROVED);
});

// ─── RR-BR ───────────────────────────────────────────────────────────────────

it('RR-BR-01 / RR-ROLE-05 / RR-BR-06: a maker whose working branch is another branch gets a 404 and no row', function () {
    [$invoice, $obligation] = rrrObligation();
    $other = rrrOtherBranch();
    $maker = userInRole('Front Office');
    rmeMakeFrontOfficeActive($maker, $other);
    rrrDesignate(makers: [$maker]);

    rrrNotFound(fn () => rrrSubmit($invoice, $obligation, maker: $maker));
    expect(RmeRefundRequest::query()->count())->toBe(0);
});

it('RR-BR-02: approve, reject, cancel and show on another branch\'s request are 404', function () {
    [, , $request] = rrrFiled();
    $other = rrrOtherBranch();
    $foreignHead = userInRole('Kepala Cabang');
    $foreignHead->forceFill(['branch_id' => $other->id])->save();
    rrrDesignate(reviewers: [$foreignHead]);

    rrrNotFound(fn () => rrrApprove($request, $foreignHead));
    rrrNotFound(fn () => rrrReject($request, $foreignHead));
    rrrNotFound(fn () => rrrCancel($request, $foreignHead));
    expect(app(RmeRefundRequestService::class)->mayView($foreignHead, (int) $request->branch_id))->toBeFalse();
    test()->actingAs($foreignHead)->get(route('rme.refund-requests.show', $request->id))->assertNotFound();
});

it('RR-BR-04 / RR-ROLE-07: a Kepala Cabang of another branch is refused without learning the request exists', function () {
    [, , $request] = rrrFiled();
    $other = rrrOtherBranch();
    $head = userInRole('Kepala Cabang');
    $head->forceFill(['branch_id' => $other->id])->save();
    rrrDesignate(reviewers: [$head]);

    rrrNotFound(fn () => rrrApprove($request, $head));
    test()->actingAs($head)->post(route('rme.refund-requests.approve', $request->id))->assertNotFound();
    expect($request->fresh()->status)->toBe(S::REQUESTED);
});

it('RR-BR-05: a permission held through a direct grant, without the role, never makes a maker or a reviewer', function () {
    [$invoice, $obligation, $request] = rrrFiled();
    $grantedMaker = userWith(['request_rme_refund', 'manage_rme_billing']);
    $grantedReviewer = userWith(['approve_rme_refund']);
    rrrDesignate(makers: [$grantedMaker], reviewers: [$grantedReviewer]);

    rrrRefused(fn () => rrrSubmit($invoice, $obligation, ['owner_approval_reference' => rrrOwnerReference('09')], $grantedMaker), R::MAKER_NOT_AUTHORIZED);
    rrrRefused(fn () => rrrApprove($request, $grantedReviewer), R::REVIEWER_NOT_AUTHORIZED);
});

// ─── RR-ROLE ─────────────────────────────────────────────────────────────────

it('RR-ROLE-01..04: Kasir, Admin Klinik, Super Admin and Supervisor RME cannot file a request', function (string $role) {
    [$invoice, $obligation] = rrrObligation();
    $actor = $role === 'Kasir' || $role === 'Admin Klinik' ? userInRole($role) : test()->{$role === 'Super Admin' ? 'superAdmin' : 'supervisor'};
    if ($role === 'Kasir') {
        rmeMakeKasirActive($actor, test()->branch);
    } elseif ($role === 'Admin Klinik') {
        rmeMakeAdminClinicActive($actor, test()->branch);
    }
    rrrDesignate(makers: [$actor]);

    rrrRefused(fn () => rrrSubmit($invoice, $obligation, maker: $actor), R::MAKER_NOT_AUTHORIZED);
    expect(RmeRefundRequest::query()->count())->toBe(0);
})->with(['Kasir', 'Admin Klinik', 'Super Admin', 'Supervisor RME']);

it('RR-ROLE-06: a Kepala Cabang approves a request of its own managed branch', function () {
    [, , $request] = rrrFiled();

    expect(rrrApprove($request, test()->kepalaCabang)->status)->toBe(S::APPROVED);
});

it('RR-ROLE-08: the Kepala Cabang grant is narrow — no visit, medical-record, cashier or billing permission', function () {
    expect(RoleSeeder::ROLE_PERMISSIONS['Kepala Cabang'])->toBe([
        'view dashboard',
        'view_inventory',
        'view_purchase_request',
        'manage_purchase_request',
        'approve_rme_refund',
    ]);

    $head = test()->kepalaCabang;
    foreach (['view_clinic_visits', 'manage_clinic_visits', 'manage_rme_billing', 'request_rme_refund', 'view_billing_correction_oversight', 'request_rme_invoice_correction', 'approve_rme_invoice_correction'] as $permission) {
        expect($head->can($permission))->toBeFalse();
    }

    test()->actingAs($head)->get(route('rme.cashier.index'))->assertForbidden();
    test()->actingAs($head)->get(route('rme.refund-requests.index'))->assertOk();
});

it('RR-ROLE-09: Supervisor RME approves inside its authorized scope', function () {
    [, , $request] = rrrFiled();

    expect(rrrApprove($request, test()->supervisor)->status)->toBe(S::APPROVED);
});

it('RR-ROLE-10: Super Admin is refused for a branch that is not an active RME branch', function () {
    [, , $request] = rrrFiled();
    test()->branch->forceFill(['is_rme_enabled' => false])->save();

    rrrNotFound(fn () => rrrApprove($request, test()->superAdmin));
});

it('RR-ROLE-11: Front Office, Kasir, Admin Klinik, Owner, Finance Klinik and Data Analyst cannot decide', function (string $role) {
    [, , $request] = rrrFiled();
    $actor = $role === 'Front Office' ? test()->frontOffice : userInRole($role);
    rrrDesignate(reviewers: $role === 'Front Office' ? [] : [$actor]);

    rrrRefused(fn () => rrrApprove($request, $actor), R::REVIEWER_NOT_AUTHORIZED);
    rrrRefused(fn () => rrrReject($request, $actor), R::REVIEWER_NOT_AUTHORIZED);
    expect($request->fresh()->status)->toBe(S::REQUESTED);
})->with(['Front Office', 'Kasir', 'Admin Klinik', 'Owner', 'Finance Klinik', 'Data Analyst']);

it('RR-ROLE-12: one account holding Front Office and Kepala Cabang cannot approve the request it filed', function () {
    [$invoice, $obligation] = rrrObligation();
    $dual = userInRole('Front Office');
    $dual->assignRole('Kepala Cabang');
    $dual->forceFill(['branch_id' => test()->branch->id])->save();
    rmeMakeFrontOfficeActive($dual, test()->branch);
    rrrIdentities([(int) $dual->id], [(int) test()->supervisor->id]);

    $request = rrrSubmit($invoice, $obligation, maker: $dual);
    rrrIdentities([], [(int) $dual->id]);

    rrrRefused(fn () => rrrApprove($request, $dual), R::SELF_APPROVAL_FORBIDDEN);
});

// ─── RR-ACT ──────────────────────────────────────────────────────────────────

it('RR-ACT-01: a maker deactivated between render and submit is refused', function () {
    [$invoice, $obligation] = rrrObligation();
    test()->frontOffice->forceFill(['is_active' => false])->save();

    rrrRefused(fn () => rrrSubmit($invoice, $obligation), R::ACTOR_UNUSABLE);
});

it('RR-ACT-02: a reviewer whose permission was removed between render and approve is refused', function () {
    [, , $request] = rrrFiled();
    test()->supervisor->removeRole('Supervisor RME');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    rrrRefused(fn () => rrrApprove($request, test()->supervisor->fresh()), R::REVIEWER_NOT_AUTHORIZED);
    expect($request->fresh()->status)->toBe(S::REQUESTED);
});

// ─── RR-OVS ──────────────────────────────────────────────────────────────────

it('RR-OVS-01 / RR-OVS-02: oversight readers get GET-only access; out of scope is 404', function (string $role) {
    [, , $request] = rrrFiled();
    $reader = userInRole($role);

    test()->actingAs($reader)->get(route('oversight.billing-corrections.refund-requests.index'))->assertOk();
    test()->actingAs($reader)->get(route('oversight.billing-corrections.refund-requests.show', $request->id))->assertOk();
    test()->actingAs($reader)->post(route('rme.refund-requests.approve', $request->id))->assertForbidden();
    test()->actingAs($reader)->post(route('rme.refund-requests.reject', $request->id))->assertForbidden();
    test()->actingAs($reader)->post(route('rme.refund-requests.cancel', $request->id))->assertForbidden();
    test()->actingAs($reader)->get(route('oversight.billing-corrections.refund-requests.show', 999999))->assertNotFound();
})->with(['Finance Klinik', 'Data Analyst', 'Owner']);

it('RR-OVS-03 / RR-OVS-05: an approved request is reported apart, never as revenue, receivables, cash or bank', function () {
    [$invoice, $obligation, $request] = rrrFiled();
    $totals = DB::table('trx_rme_invoices')->where('id', $invoice->id)->first(['grand_total', 'status']);
    rrrApprove($request);

    $summary = app(RmeRefundRequestService::class)->approvedUnsettledByBranch([(int) test()->branch->id]);
    expect($summary)->toBe([(int) test()->branch->id => ['count' => 1, 'amount_cents' => 15000000]])
        ->and(DB::table('trx_rme_invoices')->where('id', $invoice->id)->first(['grand_total', 'status']))->toEqual($totals);

    test()->actingAs(userInRole('Owner'))
        ->get(route('oversight.billing-corrections.refund-requests.index'))
        ->assertOk()
        ->assertSee('Refund disetujui — belum dibayar')
        ->assertSee('tidak dikurangkan dari omzet', false);
});

it('RR-OVS-04: Owner reads oversight but holds no export, maker, reviewer or invoice-correction permission', function () {
    $owner = userInRole('Owner');

    expect($owner->can('view_billing_correction_oversight'))->toBeTrue();
    foreach (['export_oversight_data', 'request_rme_refund', 'approve_rme_refund', 'request_rme_invoice_correction', 'approve_rme_invoice_correction', 'apply_rme_invoice_correction'] as $permission) {
        expect($owner->can($permission))->toBeFalse();
    }

    test()->actingAs($owner)->get(route('oversight.billing-corrections.export'))->assertForbidden();
    test()->actingAs($owner)->get(route('oversight.billing-corrections.print'))->assertForbidden();
    test()->actingAs($owner)->get(route('rme.refund-requests.index'))->assertForbidden();
});

// ─── RR-PRIV ─────────────────────────────────────────────────────────────────

it('RR-PRIV-01: audit rows and events carry no name, NIK, RM number, note or reference text', function () {
    [, , $request] = rrrFiled();
    rrrReject($request, test()->supervisor, 'Catatan rahasia penolakan pasien.');

    $blob = json_encode([
        AuditLog::query()->where('entity_type', RmeFinancialAuditEvent::ENTITY_REFUND_REQUEST)->get(['old_values', 'new_values'])->toArray(),
        RmeRefundRequestEvent::query()->get(['metadata'])->toArray(),
    ]);

    foreach ([test()->patient->name, test()->patient->ktp_number, test()->patient->medical_record_number, 'Catatan rahasia', 'Pasien meminta', rrrOwnerReference(), rrrPatientReference(), strtolower(rrrOwnerReference())] as $secret) {
        expect($blob)->not->toContain($secret);
    }
});

it('RR-PRIV-03: an out-of-scope request reveals nothing — the same 404 as a missing one', function () {
    [, , $request] = rrrFiled();
    $outsider = userInRole('Kepala Cabang');
    $outsider->forceFill(['branch_id' => rrrOtherBranch()->id])->save();

    $missing = test()->actingAs($outsider)->get(route('rme.refund-requests.show', 999999));
    $foreign = test()->actingAs($outsider)->get(route('rme.refund-requests.show', $request->id));
    expect($foreign->status())->toBe(404)->and($missing->status())->toBe(404);
});

// ─── RR-SEC ──────────────────────────────────────────────────────────────────

it('RR-SEC-07: status, decision and Owner reference are not mass-assignable', function () {
    [, , $request] = rrrFiled();

    $request->fill(['status' => S::APPROVED, 'decided_by' => test()->supervisor->id, 'owner_approval_reference' => 'OWNER-FORGED-0001']);
    expect($request->isDirty(['status', 'decided_by', 'owner_approval_reference']))->toBeFalse();
});

it('RR-SEC-11: approval never settles — payout is a separate capability, route set and permission', function () {
    // SUPERSEDED in part by FEATURE-RME-REFUND-SETTLEMENT-1 (rule 216): SETTLED now
    // exists, but the refund REQUEST service can never reach it. Only the separate
    // settlement service moves APPROVED → SETTLED, behind its own permission.
    expect(method_exists(RmeRefundRequestService::class, 'settle'))->toBeFalse()
        ->and(method_exists(RmeRefundRequestService::class, 'pay'))->toBeFalse()
        ->and(S::canTransition(S::REQUESTED, S::SETTLED))->toBeFalse()
        ->and(S::TRANSITIONS[S::APPROVED])->toContain(S::SETTLED);

    $payoutRoutes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($r) => preg_match('/refund[^\n]*(settle|payout|pay\b)/i', (string) $r->getName().' '.$r->uri()) === 1);
    expect($payoutRoutes)->not->toBeEmpty();
    foreach ($payoutRoutes as $route) {
        expect($route->getActionName())->toContain('RefundSettlementController');
    }

    expect(collect(Permission::all())->pluck('name')->filter(fn ($n) => str_contains($n, 'refund') && str_contains($n, 'settle'))->values()->all())
        ->toBe(['settle_rme_refund']);
});

it('RR-SEC-12: the refund service reads no session, request, auth or BranchContext', function () {
    $source = (string) file_get_contents(app_path('Modules/RmeInvoice/Services/RmeRefundRequestService.php'));
    $code = preg_replace('#/\*.*?\*/|//[^\n]*#s', '', $source);

    foreach (['Auth::', 'auth()', 'request()', 'session()', 'BranchContext'] as $forbidden) {
        expect($code)->not->toContain($forbidden);
    }
});

// ─── Navigation (courtesy only — the route, policy and service decide) ─────

it('RR-NAV-01: shows the refund queue to a Kepala Cabang and a Front Office maker only while the flag is ON', function () {
    $link = 'data-sidebar-link="refund-requests"';

    $this->actingAs(test()->kepalaCabang)->get(route('rme.refund-requests.index'))
        ->assertOk()->assertSee($link, false);
    $this->actingAs(test()->frontOffice)->get(route('rme.refund-requests.index'))
        ->assertOk()->assertSee($link, false);

    // Flag OFF: the queue 404s and no page carries the entry.
    rrrFlag(false);
    $this->actingAs(test()->kepalaCabang)->get(route('rme.refund-requests.index'))->assertNotFound();
    $this->actingAs(test()->kepalaCabang)->get(route('dashboard'))->assertOk()->assertDontSee($link, false);
});

it('RR-NAV-02: hides the refund queue from an account holding neither refund permission', function () {
    $viewer = User::factory()->create();
    $viewer->givePermissionTo('view dashboard');

    $this->actingAs($viewer->fresh())->get(route('dashboard'))
        ->assertOk()->assertDontSee('data-sidebar-link="refund-requests"', false);
    $this->actingAs($viewer->fresh())->get(route('rme.refund-requests.index'))->assertForbidden();
});

// ─── Security review hardening (independent review of 2026-10-31) ─────────

it('RR-SR-01: the checker sees the case — requester, reason and both references — on the operational page only', function () {
    [, , $request] = rrrFiled();

    test()->actingAs(test()->supervisor)->get(route('rme.refund-requests.show', $request->id))
        ->assertOk()
        ->assertSee('data-refund-case', false)
        ->assertSee(test()->frontOffice->name)
        ->assertSee($request->reason_note)
        ->assertSee($request->owner_approval_reference)
        ->assertSee($request->patient_request_reference);

    // Oversight readers see the facts and the staff names, never the free text or references.
    test()->actingAs(userInRole('Finance Klinik'))->get(route('oversight.billing-corrections.refund-requests.show', $request->id))
        ->assertOk()
        ->assertSee(test()->frontOffice->name)
        ->assertDontSee('data-refund-case', false)
        ->assertDontSee($request->reason_note)
        ->assertDontSee($request->owner_approval_reference)
        ->assertDontSee($request->patient_request_reference);
});

it('RR-SR-02: an out-of-scope reviewer gets 404 even with an invalid note — input is never checked before scope', function () {
    [, , $request] = rrrFiled();
    $other = rrrOtherBranch();
    $head = userInRole('Kepala Cabang');
    $head->forceFill(['branch_id' => $other->id])->save();
    rrrDesignate(reviewers: [$head]);

    rrrNotFound(fn () => rrrApprove($request, $head, ['decision_note' => 'pendek']));
    rrrNotFound(fn () => rrrReject($request, $head, 'pendek'));
    test()->actingAs($head)->post(route('rme.refund-requests.approve', $request->id), ['decision_note' => 'pendek'])->assertNotFound();
    test()->actingAs($head)->post(route('rme.refund-requests.reject', $request->id), ['decision_note' => 'pendek'])->assertNotFound();
    expect($request->fresh()->status)->toBe(S::REQUESTED);
});

it('RR-SR-03: a Front Office account of another branch gets 404 on cancel, whatever the reason it sends', function () {
    [, , $request] = rrrFiled();
    $foreignMaker = userInRole('Front Office');
    rmeMakeFrontOfficeActive($foreignMaker, rrrOtherBranch());

    test()->actingAs($foreignMaker)->post(route('rme.refund-requests.cancel', $request->id), ['cancel_reason' => 'pendek'])->assertNotFound();
    rrrNotFound(fn () => rrrCancel($request, $foreignMaker, 'pendek'));
    expect($request->fresh()->status)->toBe(S::REQUESTED);
});

it('RR-SR-04: a permission holder without reviewer authority gets the same answer for a real and a missing id', function () {
    [, , $request] = rrrFiled();
    $granted = userWith(['approve_rme_refund']);

    $real = test()->actingAs($granted)->post(route('rme.refund-requests.approve', $request->id));
    $missing = test()->actingAs($granted)->post(route('rme.refund-requests.approve', 999999));

    $real->assertStatus(302)->assertSessionHasErrors('refund');
    $missing->assertStatus(302)->assertSessionHasErrors('refund');
    expect(session('errors')->first('refund'))->toContain(R::REVIEWER_NOT_AUTHORIZED)
        ->and($request->fresh()->status)->toBe(S::REQUESTED);
});

it('RR-SR-05: filing on another branch\'s invoice is 404 before any field is validated', function () {
    [$invoice] = rrrObligation();
    $visit = $invoice->clinicVisit;
    $foreignMaker = userInRole('Front Office');
    rmeMakeFrontOfficeActive($foreignMaker, rrrOtherBranch());

    test()->actingAs($foreignMaker)->post(route('rme.refund-requests.store', [$visit, $invoice]), [])->assertNotFound();
    // Control: the in-scope maker reaches validation with the same empty payload.
    test()->actingAs(test()->frontOffice)->post(route('rme.refund-requests.store', [$visit, $invoice]), [])
        ->assertStatus(302)->assertSessionHasErrors('obligation_id');
    expect(RmeRefundRequest::query()->count())->toBe(0);
});

it('RR-SR-06: the model refuses an edge the state machine does not have', function () {
    [, , $request] = rrrFiled();
    rrrCancel($request);

    $cancelled = $request->fresh();
    expect(fn () => $cancelled->forceFill(['status' => S::APPROVED])->save())->toThrow(LogicException::class)
        ->and($request->fresh()->status)->toBe(S::CANCELLED);
});

it('RR-SR-07: PostgreSQL refuses a decided request revoked by its own maker', function () {
    if (DB::getDriverName() !== 'pgsql') {
        test()->markTestSkipped('PostgreSQL CHECK constraint.');
    }

    [, , $request] = rrrFiled();
    rrrApprove($request);

    $thrown = null;
    try {
        DB::transaction(fn () => DB::table('trx_rme_refund_requests')->where('id', $request->id)->update([
            'status' => S::CANCELLED,
            'cancelled_by' => $request->requested_by,
            'cancelled_at' => now(),
            'cancel_reason' => 'Pembuat mencabut persetujuan sendiri.',
        ]));
    } catch (QueryException $e) {
        $thrown = $e->getMessage();
    }

    expect($thrown)->toContain('trx_rme_refund_req_revoke_not_maker_chk')
        ->and($request->fresh()->status)->toBe(S::APPROVED);
});

it('RR-SR-08: a replayed submission of a decided request says so instead of "filed"', function () {
    [$invoice, $obligation] = rrrObligation();
    $data = rrrData($invoice, $obligation);
    $route = route('rme.refund-requests.store', [$invoice->clinicVisit, $invoice]);

    test()->actingAs(test()->frontOffice)->post($route, $data)->assertRedirect();
    $request = RmeRefundRequest::query()->sole();
    rrrApprove($request);

    test()->actingAs(test()->frontOffice)->post($route, $data)->assertRedirect(route('rme.refund-requests.show', $request->id))
        ->assertSessionHas('success', fn (string $m) => str_contains($m, 'sudah tercatat sebelumnya'));
    expect(RmeRefundRequest::query()->count())->toBe(1);
});
