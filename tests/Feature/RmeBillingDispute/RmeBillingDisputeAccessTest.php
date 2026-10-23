<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| IMPLEMENTATION-RME-BILLING-DISPUTE-ESCALATION-OPS-OQ-1 — who may report,
| review, escalate, note and resolve a billing dispute (rule 203).
|--------------------------------------------------------------------------
|
| Three layers, each tested on its own: the route middleware (permission), the
| policy (permission + server-resolved branch scope), and the service, which
| re-reads the PERSISTED account under the invoice row lock — so an inactive,
| soft-deleted or demoted account is refused even when it still holds a live
| session and a stale User object. A dispute outside the caller's scope is a
| 404, never a 403 that would confirm it exists. No request field ever chooses
| the branch.
*/

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\RmeInvoice\Models\RmeBillingDispute;
use App\Modules\RmeInvoice\Services\RmeBillingDisputeService;
use App\Modules\RmeInvoice\Support\RmeBillingDisputeAuditEvent;
use App\Modules\RmeInvoice\Support\RmeBillingDisputeDenied;
use App\Modules\RmeInvoice\Support\RmeBillingDisputeOutcome;
use App\Modules\RmeInvoice\Support\RmeBillingDisputeReason;
use App\Modules\RmeInvoice\Support\RmeBillingDisputeStatus;
use Spatie\Permission\Models\Role;

require_once __DIR__.'/helpers.php';

beforeEach(function () {
    rbdSetup();
    $this->branchB = Branch::factory()->create(['code' => 'IC02', 'name' => 'Cabang Lain', 'is_rme_enabled' => true, 'is_active' => true]);
});

function rbdDenied(callable $work, string $code): void
{
    try {
        $work();
    } catch (RmeBillingDisputeDenied $denied) {
        expect($denied->reasonCode)->toBe($code);

        return;
    }

    test()->fail("Expected the dispute action to be denied with {$code}.");
}

function rbdFrontOffice(Branch $branch): User
{
    $user = User::factory()->create();
    rmeMakeFrontOfficeActive($user, $branch);

    return $user->fresh();
}

function rbdStorePayload(): array
{
    return [
        'reason_category' => RmeBillingDisputeReason::AMOUNT_INCORRECT,
        'description' => 'Harga tambal tertulis 200.000, seharusnya 50.000.',
    ];
}

// ─── The persisted account decides, not the session ────────────────────────

it('refuses an inactive, a soft-deleted and a permission-revoked account even with a stale User object', function () {
    $invoice = ricInvoice();

    $inactive = userWith(['manage_rme_billing', 'view_clinic_visits']);
    $stale = User::query()->find($inactive->id);
    $inactive->forceFill(['is_active' => false])->save();
    rbdDenied(fn () => rbdReport($invoice, $stale), RmeBillingDisputeDenied::ACTOR_UNUSABLE);

    $deleted = userWith(['manage_rme_billing', 'view_clinic_visits']);
    $staleDeleted = User::query()->find($deleted->id);
    $deleted->delete();
    rbdDenied(fn () => rbdReport($invoice, $staleDeleted), RmeBillingDisputeDenied::ACTOR_UNUSABLE);

    $demoted = userWith(['manage_rme_billing', 'view_clinic_visits']);
    $staleDemoted = User::query()->find($demoted->id);
    $staleDemoted->getAllPermissions(); // the stale object has loaded its permissions
    $demoted->revokePermissionTo('manage_rme_billing');
    rbdDenied(fn () => rbdReport($invoice, $staleDemoted), RmeBillingDisputeDenied::PERMISSION_MISSING);

    expect(RmeBillingDispute::query()->count())->toBe(0)
        ->and(rbdAudit(RmeBillingDisputeAuditEvent::ACTION_REFUSED))->toHaveCount(3);
});

it('refuses a review action from an account that lost the review permission', function () {
    $dispute = rbdReport(ricInvoice());
    $stale = User::query()->find(test()->reviewer->id);
    $stale->getAllPermissions();
    test()->reviewer->revokePermissionTo('review_rme_billing_disputes');

    rbdDenied(fn () => rbdConfirmOriginal($dispute, $stale), RmeBillingDisputeDenied::PERMISSION_MISSING);
    expect($dispute->fresh()->status)->toBe(RmeBillingDisputeStatus::OPEN);
});

it('never lets a reporter or a read-only viewer review, escalate or resolve', function () {
    $dispute = rbdReport(ricInvoice());
    $service = app(RmeBillingDisputeService::class);

    rbdDenied(fn () => $service->startReview($dispute, test()->cashier), RmeBillingDisputeDenied::PERMISSION_MISSING);
    rbdDenied(fn () => $service->escalate($dispute, test()->cashier, 'Kasir mencoba eskalasi.'), RmeBillingDisputeDenied::PERMISSION_MISSING);
    rbdDenied(fn () => rbdConfirmOriginal($dispute, test()->cashier), RmeBillingDisputeDenied::PERMISSION_MISSING);
    rbdDenied(fn () => rbdConfirmOriginal($dispute, test()->viewer), RmeBillingDisputeDenied::PERMISSION_MISSING);
    rbdDenied(fn () => $service->addNote($dispute, test()->viewer, 'Owner menulis catatan.'), RmeBillingDisputeDenied::PERMISSION_MISSING);

    expect($dispute->fresh()->status)->toBe(RmeBillingDisputeStatus::OPEN);
});

it('never lets the invoice-correction roles touch a dispute merely by holding correction powers', function () {
    $dispute = rbdReport(ricInvoice());

    rbdDenied(fn () => rbdConfirmOriginal($dispute, test()->checker), RmeBillingDisputeDenied::PERMISSION_MISSING);
    rbdDenied(fn () => rbdConfirmOriginal($dispute, test()->applier), RmeBillingDisputeDenied::PERMISSION_MISSING);
});

// ─── Branch scope comes from the server ─────────────────────────────────────

it('scopes a context-bound Front Office account to its working branch', function () {
    $own = ricInvoice();
    $other = ricInvoice(ricVisit($this->branchB));
    $frontOffice = rbdFrontOffice(test()->branch);

    $dispute = rbdReport($own, $frontOffice);
    expect((int) $dispute->branch_id)->toBe((int) test()->branch->id);

    rbdDenied(fn () => rbdReport($other, $frontOffice), RmeBillingDisputeDenied::BRANCH_OUT_OF_SCOPE);
    expect(RmeBillingDispute::query()->where('rme_invoice_id', $other->id)->count())->toBe(0);
});

it('answers 404 for an out-of-scope invoice or dispute over HTTP, and ignores a forged branch_id', function () {
    $own = ricInvoice();
    $other = ricInvoice(ricVisit($this->branchB));
    $foreign = rbdReport($other); // reported by the unscoped cashier

    $frontOffice = rbdFrontOffice(test()->branch);
    rbdActingAs($frontOffice);

    test()->get(route('rme.billing-disputes.create', [$other->clinic_visit_id, $other->id]))->assertNotFound();
    test()->post(route('rme.billing-disputes.store', [$other->clinic_visit_id, $other->id]), rbdStorePayload())->assertNotFound();
    test()->get(route('rme.billing-disputes.show', $foreign->id))->assertNotFound();
    test()->post(route('rme.billing-disputes.notes.store', $foreign->id), ['note' => 'Catatan lintas cabang.'])->assertNotFound();

    test()->post(route('rme.billing-disputes.store', [$own->clinic_visit_id, $own->id]), rbdStorePayload() + ['branch_id' => $this->branchB->id])
        ->assertRedirect();
    $mine = RmeBillingDispute::query()->where('rme_invoice_id', $own->id)->firstOrFail();
    expect((int) $mine->branch_id)->toBe((int) test()->branch->id);

    $listing = test()->get(route('rme.billing-disputes.index', ['status' => 'ALL']))->assertOk();
    $listing->assertSee($mine->dispute_number)->assertDontSee($foreign->dispute_number);
});

it('answers 404 when the invoice does not belong to the visit in the URL', function () {
    $a = ricInvoice();
    $b = ricInvoice();
    rbdActingAs(test()->cashier);

    test()->get(route('rme.billing-disputes.create', [$a->clinic_visit_id, $b->id]))->assertNotFound();
    test()->post(route('rme.billing-disputes.store', [$a->clinic_visit_id, $b->id]), rbdStorePayload())->assertNotFound();
    expect(RmeBillingDispute::query()->count())->toBe(0);
});

// ─── HTTP surface ───────────────────────────────────────────────────────────

it('lets the cashier report over HTTP, then follows an active report instead of a second form', function () {
    $invoice = ricInvoice();
    rbdActingAs(test()->cashier);

    test()->get(route('rme.cashier.show', [$invoice->clinic_visit_id, $invoice->id]))
        ->assertOk()->assertSee('data-billing-dispute-report-button', false);
    test()->get(route('rme.billing-disputes.create', [$invoice->clinic_visit_id, $invoice->id]))
        ->assertOk()->assertSee('data-billing-dispute-form', false);

    $response = test()->post(route('rme.billing-disputes.store', [$invoice->clinic_visit_id, $invoice->id]), rbdStorePayload());
    $dispute = RmeBillingDispute::query()->firstOrFail();
    $response->assertRedirect(route('rme.billing-disputes.show', $dispute->id));

    test()->get(route('rme.billing-disputes.create', [$invoice->clinic_visit_id, $invoice->id]))
        ->assertRedirect(route('rme.billing-disputes.show', $dispute->id));

    test()->get(route('rme.cashier.show', [$invoice->clinic_visit_id, $invoice->id]))
        ->assertOk()
        ->assertSee('data-billing-dispute-active', false)
        ->assertDontSee('data-billing-dispute-report-button', false);

    test()->get(route('rme.billing-disputes.show', $dispute->id))
        ->assertOk()
        ->assertSee('data-billing-dispute-hold-notice', false)
        ->assertSee('data-billing-dispute-note', false)
        ->assertDontSee('data-billing-dispute-resolve', false)
        ->assertDontSee((string) test()->patient->name)
        ->assertDontSee((string) test()->patient->ktp_number);
});

it('runs review, escalation and resolution over HTTP for the reviewer only', function () {
    $invoice = ricInvoice();
    $dispute = rbdReport($invoice);

    rbdActingAs(test()->cashier);
    test()->post(route('rme.billing-disputes.review', $dispute->id))->assertForbidden();
    test()->post(route('rme.billing-disputes.resolve', $dispute->id), [
        'outcome' => RmeBillingDisputeOutcome::ORIGINAL_CONFIRMED_CORRECT,
        'resolution_reason' => 'Kasir menyatakan benar.',
        'resolution_reference' => 'KASIR-SENDIRI-01',
    ])->assertForbidden();

    rbdActingAs(test()->reviewer);
    test()->get(route('rme.billing-disputes.show', $dispute->id))->assertOk()->assertSee('data-billing-dispute-resolve', false);
    test()->post(route('rme.billing-disputes.review', $dispute->id), ['note' => ''])->assertRedirect();
    expect($dispute->fresh()->status)->toBe(RmeBillingDisputeStatus::UNDER_REVIEW);

    test()->post(route('rme.billing-disputes.escalate', $dispute->id), ['note' => ''])->assertSessionHasErrors('note');
    test()->post(route('rme.billing-disputes.escalate', $dispute->id), ['note' => 'Perlu keputusan Owner soal tarif.'])->assertRedirect();
    expect($dispute->fresh()->status)->toBe(RmeBillingDisputeStatus::ESCALATED);

    test()->post(route('rme.billing-disputes.resolve', $dispute->id), [
        'outcome' => RmeBillingDisputeOutcome::ORIGINAL_CONFIRMED_CORRECT,
        'resolution_reason' => 'Owner memastikan harga benar.',
    ])->assertSessionHasErrors('resolution_reference');

    test()->post(route('rme.billing-disputes.resolve', $dispute->id), [
        'outcome' => RmeBillingDisputeOutcome::ORIGINAL_CONFIRMED_CORRECT,
        'resolution_reason' => 'Owner memastikan harga benar.',
        'resolution_reference' => 'OWNER-OK/2026-10-24/002',
    ])->assertRedirect();
    expect($dispute->fresh()->status)->toBe(RmeBillingDisputeStatus::RESOLVED);
});

it('gives the owner-style viewer a read-only page, and every write a 403', function () {
    $dispute = rbdReport(ricInvoice());
    rbdActingAs(test()->viewer);

    test()->get(route('rme.billing-disputes.index'))->assertOk()->assertSee($dispute->dispute_number);
    test()->get(route('rme.billing-disputes.show', $dispute->id))
        ->assertOk()
        ->assertDontSee('data-billing-dispute-actions', false);

    test()->post(route('rme.billing-disputes.notes.store', $dispute->id), ['note' => 'Owner menulis catatan.'])->assertForbidden();
    test()->post(route('rme.billing-disputes.review', $dispute->id))->assertForbidden();
    test()->post(route('rme.billing-disputes.escalate', $dispute->id), ['note' => 'Owner eskalasi sendiri.'])->assertForbidden();
    test()->post(route('rme.billing-disputes.resolve', $dispute->id), ['outcome' => RmeBillingDisputeOutcome::CORRECTION_APPLIED, 'resolution_reason' => 'Owner menyelesaikan.'])->assertForbidden();
    test()->get(route('rme.billing-disputes.create', [$dispute->invoice->clinic_visit_id, $dispute->rme_invoice_id]))->assertForbidden();

    expect($dispute->fresh()->status)->toBe(RmeBillingDisputeStatus::OPEN);
});

it('keeps the whole surface closed to an account holding none of the dispute permissions', function () {
    $dispute = rbdReport(ricInvoice());
    $doctorLike = userWith(['view_clinic_visits', 'manage_clinic_visits']);
    rbdActingAs($doctorLike);

    test()->get(route('rme.billing-disputes.index'))->assertForbidden();
    test()->get(route('rme.billing-disputes.show', $dispute->id))->assertForbidden();
    test()->post(route('rme.billing-disputes.notes.store', $dispute->id), ['note' => 'Dokter menulis catatan.'])->assertForbidden();
});

it('seeds the dispute permissions conservatively: review to no operational role, read-only view to Owner', function () {
    $reviewRoles = Role::query()
        ->whereHas('permissions', fn ($q) => $q->where('name', 'review_rme_billing_disputes'))
        ->pluck('name')->sort()->values()->all();
    $viewRoles = Role::query()
        ->whereHas('permissions', fn ($q) => $q->where('name', 'view_rme_billing_disputes'))
        ->pluck('name')->sort()->values()->all();

    expect($reviewRoles)->toBe(['Super Admin'])
        ->and($viewRoles)->toBe(['Owner', 'Super Admin']);

    foreach (['Owner', 'Front Office', 'Admin Klinik', 'Kasir', 'Doctor', 'Perawat', 'Supervisor RME'] as $role) {
        expect(userInRole($role)->can('review_rme_billing_disputes'))->toBeFalse("{$role} must not resolve disputes");
    }
    expect(userInRole('Super Admin')->can('review_rme_billing_disputes'))->toBeTrue();
});
