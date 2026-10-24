<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| HARDENING-RME-BILLING-DISPUTE-BRANCH-REVIEWER-CONTROL-PAYMENT-1 — owner
| decision OPS-OQ-1a (rule 204): an EXPLICITLY designated branch responsible
| person may review and resolve that branch's billing disputes.
|--------------------------------------------------------------------------
|
| What must hold, and what this suite pins:
|  - Authority comes from an ACCOUNT-specific, BRANCH-specific designation row,
|    never from a role. Front Office is the exact union of Admin Klinik and
|    Kasir, so a role grant would reach every front-desk account at once.
|  - A designation authorizes ONLY that branch, ANDed with the account's live
|    working-branch scope, and only while the account is usable.
|  - It grants no Spatie permission and therefore no correction, price, refund,
|    cancellation or payment power.
|  - Super Admin keeps its existing path; the Owner stays read-only and cannot
|    be designated; nobody designates themself.
|  - Grant and revoke are audited; revoke is soft and the row never changes or
|    disappears afterwards.
*/

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\RmeInvoice\Models\RmeBillingDispute;
use App\Modules\RmeInvoice\Models\RmeBillingDisputeReviewer;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use App\Modules\RmeInvoice\Services\RmeBillingDisputeEscalationService;
use App\Modules\RmeInvoice\Services\RmeBillingDisputeReviewerService;
use App\Modules\RmeInvoice\Services\RmeBillingDisputeService;
use App\Modules\RmeInvoice\Services\RmeInvoiceCorrectionService;
use App\Modules\RmeInvoice\Support\RmeBillingDisputeAuditEvent;
use App\Modules\RmeInvoice\Support\RmeBillingDisputeDenied;
use App\Modules\RmeInvoice\Support\RmeBillingDisputeOutcome;
use App\Modules\RmeInvoice\Support\RmeBillingDisputeStatus;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

require_once __DIR__.'/helpers.php';

beforeEach(function () {
    rbdSetup();

    $this->otherBranch = Branch::factory()->create(['code' => 'IC02', 'name' => 'Cabang Lain', 'is_rme_enabled' => true, 'is_active' => true]);
    // The appointer: holds the new governance permission and nothing else.
    $this->appointer = userWith(['manage_rme_billing_dispute_reviewers', 'view_clinic_visits']);
});

/** A designation made through the real service by the appointer. */
function rbrDesignate(User $target, Branch $branch, ?User $actor = null, string $reason = 'Penanggung jawab tagihan cabang ditunjuk pemilik.'): RmeBillingDisputeReviewer
{
    return app(RmeBillingDisputeReviewerService::class)->designate($actor ?? test()->appointer, $target, (int) $branch->id, $reason);
}

function rbrRevoke(RmeBillingDisputeReviewer $designation, ?User $actor = null, string $reason = 'Penanggung jawab dipindahkan tugasnya.'): RmeBillingDisputeReviewer
{
    return app(RmeBillingDisputeReviewerService::class)->revoke($actor ?? test()->appointer, $designation->fresh(), $reason);
}

/** A Front Office account working (online context) at $branch. */
function rbrFrontOffice(Branch $branch): User
{
    $user = userInRole('Front Office');
    rmeMakeFrontOfficeActive($user, $branch);

    return $user->fresh();
}

/**
 * A branch responsible person: an Admin Klinik account working (online context)
 * at $branch. It also holds the billing permission through Admin Klinik, which
 * is exactly why rule 204 §15 adds the independence check on resolution.
 */
function rbrBranchManager(Branch $branch): User
{
    $user = userWith(['view_clinic_visits']);
    rmeMakeAdminClinicActive($user, $branch);

    return $user->fresh();
}

/** A plain non-context-bound account: its working scope is every RME branch. */
function rbrPlainAccount(): User
{
    return userWith(['view_clinic_visits']);
}

function rbrStartReview(RmeBillingDispute $dispute, User $actor): RmeBillingDispute
{
    test()->actingAs($actor);

    return app(RmeBillingDisputeService::class)->startReview($dispute->fresh(), $actor, 'Saya cek nota tindakan dan harga.');
}

function rbrExpectDenied(callable $work, string $code): void
{
    try {
        $work();
    } catch (RmeBillingDisputeDenied $denied) {
        expect($denied->reasonCode)->toBe($code);

        return;
    }

    test()->fail("Expected a {$code} refusal; the action was accepted.");
}

function rbrExpectValidation(callable $work, string $code): void
{
    try {
        $work();
    } catch (ValidationException $e) {
        expect(implode(' ', array_merge(...array_values($e->errors()))))->toContain('['.$code.']');

        return;
    }

    test()->fail("Expected a [{$code}] validation refusal; the action was accepted.");
}

function rbrOtherBranchDispute(): RmeBillingDispute
{
    $cashier = userWith(['manage_rme_billing', 'view_clinic_visits']);

    return rbdReport(ricInvoice(ricVisit(test()->otherBranch)), $cashier);
}

// ─── 01–02  Super Admin and Owner keep exactly what they had ─────────────────

it('01 keeps the existing Super Admin review path without any designation', function () {
    $superAdmin = userInRole('Super Admin');
    $dispute = rbdReport(ricInvoice());

    expect(rbrStartReview($dispute, $superAdmin)->fresh()->status)->toBe(RmeBillingDisputeStatus::UNDER_REVIEW)
        ->and(RmeBillingDisputeReviewer::query()->count())->toBe(0);
});

it('02 keeps the Owner read-only and refuses to designate an Owner account', function () {
    $owner = userInRole('Owner');
    $dispute = rbdReport(ricInvoice());

    expect($owner->can('view', $dispute))->toBeTrue()
        ->and($owner->can('review', $dispute))->toBeFalse();
    rbrExpectDenied(fn () => rbrStartReview($dispute, $owner), RmeBillingDisputeDenied::PERMISSION_MISSING);

    rbrExpectValidation(fn () => rbrDesignate($owner, test()->branch), 'BILLING_DISPUTE_REVIEWER_OWNER_READ_ONLY');
    expect(RmeBillingDisputeReviewer::query()->count())->toBe(0);
});

// ─── 03–07  designation is the authority, never a role ──────────────────────

it('03 refuses review to an account without a designation', function () {
    $frontOffice = rbrFrontOffice(test()->branch);
    $dispute = rbdReport(ricInvoice());

    rbrExpectDenied(fn () => rbrStartReview($dispute, $frontOffice), RmeBillingDisputeDenied::PERMISSION_MISSING);
    expect($dispute->fresh()->status)->toBe(RmeBillingDisputeStatus::OPEN);
});

it('04 lets an explicitly designated branch reviewer review, escalate and resolve that branch', function () {
    $designee = rbrBranchManager(test()->branch);
    rbrDesignate($designee, test()->branch);
    $dispute = rbdReport(ricInvoice());

    expect(rbrStartReview($dispute, $designee)->fresh()->status)->toBe(RmeBillingDisputeStatus::UNDER_REVIEW);

    $resolved = rbdConfirmOriginal($dispute, $designee);
    expect($resolved->fresh()->status)->toBe(RmeBillingDisputeStatus::RESOLVED)
        ->and((int) $resolved->fresh()->resolved_by)->toBe((int) $designee->id);
});

it('05 refuses a designee on a branch they are not designated for', function () {
    $designee = rbrPlainAccount();
    rbrDesignate($designee, test()->branch);
    $foreign = rbrOtherBranchDispute();

    rbrExpectDenied(fn () => rbrStartReview($foreign, $designee), RmeBillingDisputeDenied::PERMISSION_MISSING);
    expect($designee->can('view', $foreign))->toBeFalse()
        ->and($foreign->fresh()->status)->toBe(RmeBillingDisputeStatus::OPEN);
});

it('06 never propagates a designation to another account of the same role (Front Office / Admin Klinik inheritance)', function () {
    $designated = rbrBranchManager(test()->branch);
    rbrDesignate($designated, test()->branch);

    $peer = rbrFrontOffice(test()->branch);
    $legacyAdmin = rmeAdminClinicUser(test()->branch);
    $dispute = rbdReport(ricInvoice());

    rbrExpectDenied(fn () => rbrStartReview($dispute, $peer), RmeBillingDisputeDenied::PERMISSION_MISSING);
    rbrExpectDenied(fn () => rbrStartReview($dispute, $legacyAdmin), RmeBillingDisputeDenied::PERMISSION_MISSING);

    expect($peer->can('review', $dispute))->toBeFalse()
        ->and($legacyAdmin->can('review', $dispute))->toBeFalse()
        ->and($designated->can('review', $dispute))->toBeTrue();
});

it('07 refuses a Kasir (cashier) without a designation', function () {
    $kasir = userInRole('Kasir');
    rmeMakeKasirActive($kasir, test()->branch);
    $dispute = rbdReport(ricInvoice());

    rbrExpectDenied(fn () => rbrStartReview($dispute, $kasir->fresh()), RmeBillingDisputeDenied::PERMISSION_MISSING);
});

// ─── 08–12  usability, staleness, revocation ────────────────────────────────

it('08 refuses an inactive designee even with a stale in-memory account', function () {
    $designee = rbrPlainAccount();
    rbrDesignate($designee, test()->branch);
    $dispute = rbdReport(ricInvoice());

    DB::table('users')->where('id', $designee->id)->update(['is_active' => false]);

    // $designee still says is_active = true in memory.
    rbrExpectDenied(fn () => rbrStartReview($dispute, $designee), RmeBillingDisputeDenied::ACTOR_UNUSABLE);
});

it('09 refuses a soft-deleted designee', function () {
    $designee = rbrPlainAccount();
    rbrDesignate($designee, test()->branch);
    $dispute = rbdReport(ricInvoice());

    DB::table('users')->where('id', $designee->id)->update(['deleted_at' => now()]);

    rbrExpectDenied(fn () => rbrStartReview($dispute, $designee), RmeBillingDisputeDenied::ACTOR_UNUSABLE);
});

it('10 decides on the persisted designation, never on a stale in-memory one', function () {
    $designee = rbrPlainAccount();
    $designation = rbrDesignate($designee, test()->branch);
    $dispute = rbdReport(ricInvoice());

    // Revoked by another process; the caller still holds the old model.
    DB::table('mst_rme_billing_dispute_reviewers')->where('id', $designation->id)->update([
        'revoked_at' => now(), 'revoked_by' => test()->appointer->id, 'revoke_reason' => 'Dicabut oleh proses lain.',
    ]);
    expect($designation->revoked_at)->toBeNull();

    rbrExpectDenied(fn () => rbrStartReview($dispute, $designee), RmeBillingDisputeDenied::PERMISSION_MISSING);
});

it('11 refuses the next action after a designation is revoked', function () {
    $designee = rbrPlainAccount();
    $designation = rbrDesignate($designee, test()->branch);
    $dispute = rbdReport(ricInvoice());
    rbrStartReview($dispute, $designee);

    rbrRevoke($designation);

    rbrExpectDenied(fn () => rbdConfirmOriginal($dispute, $designee), RmeBillingDisputeDenied::PERMISSION_MISSING);
    expect($dispute->fresh()->status)->toBe(RmeBillingDisputeStatus::UNDER_REVIEW)
        ->and($designee->can('review', $dispute->fresh()))->toBeFalse();
});

it('12 never widens a designee beyond the designated branch when their role (and so their working scope) changes', function () {
    $designee = rbrBranchManager(test()->branch);
    rbrDesignate($designee, test()->branch);

    // Losing the context-bound role widens the WORKING scope to every RME branch …
    $designee->removeRole('Admin Klinik');
    $designee = $designee->fresh();
    $foreign = rbrOtherBranchDispute();

    // … but the designation still names exactly one branch.
    rbrExpectDenied(fn () => rbrStartReview($foreign, $designee), RmeBillingDisputeDenied::PERMISSION_MISSING);
    expect(rbrStartReview(rbdReport(ricInvoice()), $designee)->fresh()->status)->toBe(RmeBillingDisputeStatus::UNDER_REVIEW);
});

it('12b refuses a context-bound designee who is working at another branch (branch transfer)', function () {
    $designee = rbrBranchManager(test()->otherBranch);
    rbrDesignate($designee, test()->branch);
    $dispute = rbdReport(ricInvoice());

    rbrExpectDenied(fn () => rbrStartReview($dispute, $designee), RmeBillingDisputeDenied::BRANCH_OUT_OF_SCOPE);
});

// ─── 13–18  forged input and designation governance ─────────────────────────

it('13 ignores a forged reviewer id in the request: the actor is always the session account', function () {
    $designee = rbrPlainAccount();
    rbrDesignate($designee, test()->branch);
    $intruder = rbrPlainAccount();
    $dispute = rbdReport(ricInvoice());

    rbdActingAs($intruder);
    test()->post(route('rme.billing-disputes.review', $dispute->id), [
        'note' => 'Saya mengaku sebagai peninjau cabang.',
        'reviewer_id' => $designee->id,
        'user_id' => $designee->id,
    ])->assertForbidden();

    expect($dispute->fresh()->status)->toBe(RmeBillingDisputeStatus::OPEN);
});

it('14 refuses a designation for a forged, non-RME or inactive branch, and for an unknown account', function () {
    $target = rbrPlainAccount();
    // A non-RME branch (production's MAIN is one; the test seed's MAIN is not, so
    // the fixture states it explicitly instead of relying on a seeded code).
    $nonRme = Branch::factory()->create(['code' => 'IC08', 'is_rme_enabled' => false, 'is_active' => true]);
    $inactive = Branch::factory()->create(['code' => 'IC09', 'is_rme_enabled' => true, 'is_active' => false]);

    rbrExpectValidation(fn () => rbrDesignate($target, $nonRme), 'BILLING_DISPUTE_REVIEWER_BRANCH_NOT_RME');
    rbrExpectValidation(fn () => rbrDesignate($target, $inactive), 'BILLING_DISPUTE_REVIEWER_BRANCH_NOT_RME');
    rbrExpectValidation(fn () => app(RmeBillingDisputeReviewerService::class)
        ->designate(test()->appointer, $target, 999999, 'Cabang yang tidak ada di sistem.'), 'BILLING_DISPUTE_REVIEWER_BRANCH_NOT_RME');

    rbdActingAs(userInRole('Super Admin'));
    test()->post(route('rme.billing-dispute-reviewers.store'), [
        'user_id' => 999999, 'branch_id' => test()->branch->id, 'reason' => 'Akun yang tidak pernah ada.',
    ])->assertSessionHasErrors('user_id');

    expect(RmeBillingDisputeReviewer::query()->count())->toBe(0);
});

it('14b refuses a designee without access to the Klinik & RME workspace (an inert designation is never recorded)', function () {
    $outsider = userWith(['view_inventory']);

    rbrExpectValidation(fn () => rbrDesignate($outsider, test()->branch), 'BILLING_DISPUTE_REVIEWER_TARGET_INELIGIBLE');
    expect(RmeBillingDisputeReviewer::query()->count())->toBe(0);
});

it('14c refuses designating an inactive account', function () {
    $inactive = rbrPlainAccount();
    DB::table('users')->where('id', $inactive->id)->update(['is_active' => false]);

    rbrExpectValidation(fn () => rbrDesignate($inactive, test()->branch), 'BILLING_DISPUTE_REVIEWER_TARGET_INELIGIBLE');
    expect(RmeBillingDisputeReviewer::query()->count())->toBe(0);
});

it('15 hides another branch\'s dispute detail from a designee (404, never 403)', function () {
    $designee = rbrPlainAccount();
    rbrDesignate($designee, test()->branch);
    $foreign = rbrOtherBranchDispute();
    $own = rbdReport(ricInvoice());

    rbdActingAs($designee);
    test()->get(route('rme.billing-disputes.show', $foreign->id))->assertNotFound();
    test()->get(route('rme.billing-disputes.show', $own->id))->assertOk()->assertSee('data-billing-dispute-actions', false);
    test()->get(route('rme.billing-disputes.index'))->assertOk()
        ->assertSee((string) $own->dispute_number)
        ->assertDontSee((string) $foreign->dispute_number);
});

it('16 refuses self-designation, Super Admin included', function () {
    rbrExpectDenied(fn () => rbrDesignate(test()->appointer, test()->branch, test()->appointer), RmeBillingDisputeDenied::SELF_DESIGNATION);

    $superAdmin = userInRole('Super Admin');
    rbrExpectDenied(fn () => rbrDesignate($superAdmin, test()->branch, $superAdmin), RmeBillingDisputeDenied::SELF_DESIGNATION);

    expect(RmeBillingDisputeReviewer::query()->count())->toBe(0);
});

it('16b refuses designating an account that already reviews every branch (redundant grant)', function () {
    rbrExpectValidation(fn () => rbrDesignate(userInRole('Super Admin'), test()->branch), 'BILLING_DISPUTE_REVIEWER_REDUNDANT');
});

it('17 refuses a grant by an account without the governance permission, at every layer', function () {
    $frontOffice = rbrFrontOffice(test()->branch);
    $target = rbrPlainAccount();

    rbrExpectDenied(fn () => rbrDesignate($target, test()->branch, $frontOffice), RmeBillingDisputeDenied::PERMISSION_MISSING);

    rbdActingAs($frontOffice);
    test()->post(route('rme.billing-dispute-reviewers.store'), [
        'user_id' => $target->id, 'branch_id' => test()->branch->id, 'reason' => 'Front Office menunjuk dirinya sendiri.',
    ])->assertForbidden();
    test()->get(route('rme.billing-dispute-reviewers.index'))->assertForbidden();

    expect(RmeBillingDisputeReviewer::query()->count())->toBe(0);
});

it('18 refuses a revoke by the designee, by a peer and over HTTP without the governance permission', function () {
    $designee = rbrBranchManager(test()->branch);
    $designation = rbrDesignate($designee, test()->branch);
    $peer = rbrFrontOffice(test()->branch);

    rbrExpectDenied(fn () => rbrRevoke($designation, $designee), RmeBillingDisputeDenied::PERMISSION_MISSING);
    rbrExpectDenied(fn () => rbrRevoke($designation, $peer), RmeBillingDisputeDenied::PERMISSION_MISSING);

    rbdActingAs($designee);
    test()->post(route('rme.billing-dispute-reviewers.revoke', $designation->id), ['reason' => 'Saya cabut sendiri penunjukan.'])
        ->assertForbidden();

    expect($designation->fresh()->revoked_at)->toBeNull();
});

// ─── 19–25  a reviewer is not a financial authority ─────────────────────────

it('19–23 grants no Spatie permission and therefore no price, correction, refund, cancellation or payment power', function () {
    $designee = rbrPlainAccount();
    $before = $designee->getAllPermissions()->pluck('name')->sort()->values()->all();
    $directBefore = DB::table('model_has_permissions')->count();

    rbrDesignate($designee, test()->branch);
    $designee = $designee->fresh();

    expect($designee->getAllPermissions()->pluck('name')->sort()->values()->all())->toBe($before)
        ->and(DB::table('model_has_permissions')->count())->toBe($directBefore);

    foreach ([
        'manage_rme_billing', 'request_rme_invoice_correction', 'approve_rme_invoice_correction',
        'apply_rme_invoice_correction', 'review_rme_billing_disputes', 'manage_rme_billing_dispute_reviewers',
    ] as $power) {
        expect($designee->can($power))->toBeFalse("a designation must not grant {$power}");
    }

    // The cashier surfaces stay closed to them.
    $invoice = ricInvoice();
    rbdActingAs($designee);
    test()->get(route('rme.cashier.payment.create', [$invoice->clinic_visit_id, $invoice->id]))->assertForbidden();
});

it('20 cannot approve an invoice correction even when the designee is a correction-capable role', function () {
    $designee = rbrBranchManager(test()->branch);
    $designee->givePermissionTo('request_rme_invoice_correction');
    rbrDesignate($designee, test()->branch);
    $invoice = ricInvoice();
    $correction = ricSubmit($invoice);

    // Rule 197 (D1e): only the designated pilot checker approves. A dispute
    // designation changes nothing about that.
    expect(fn () => ricApprove($correction, $designee))->toThrow(Exception::class);
    expect($correction->fresh()->status)->toBe('SUBMITTED');
});

it('23 resolving as a designee changes nothing in the invoice ledger (never PAID, CANCELLED or Rp0)', function () {
    $designee = rbrPlainAccount();
    rbrDesignate($designee, test()->branch);
    $dispute = rbdReport(ricInvoice());
    $ledger = rbdLedger();

    rbdConfirmOriginal($dispute, $designee);

    expect(rbdLedger())->toBe($ledger)
        ->and($dispute->invoice->fresh()->status)->toBe(RmeInvoice::STATUS_UNPAID);
});

it('24 refuses a designee who reported the dispute from certifying the original as correct', function () {
    $designee = rbrBranchManager(test()->branch);
    rbrDesignate($designee, test()->branch);
    $dispute = rbdReport(ricInvoice(), $designee);

    rbrExpectDenied(fn () => rbdConfirmOriginal($dispute, $designee), RmeBillingDisputeDenied::SELF_RESOLUTION);
    expect($dispute->fresh()->status)->toBe(RmeBillingDisputeStatus::OPEN);
});

it('25 never resolves without verifiable evidence: a designee cannot claim an applied correction that does not exist', function () {
    $designee = rbrPlainAccount();
    rbrDesignate($designee, test()->branch);
    $dispute = rbdReport(ricInvoice());

    test()->actingAs($designee);
    rbrExpectValidation(fn () => app(RmeBillingDisputeService::class)->resolve($dispute->fresh(), $designee, [
        'outcome' => RmeBillingDisputeOutcome::CORRECTION_APPLIED,
        'resolution_reason' => 'Katanya sudah dikoreksi oleh kasir.',
    ]), 'BILLING_DISPUTE_RESOLUTION_EVIDENCE_MISSING');

    expect($dispute->fresh()->status)->toBe(RmeBillingDisputeStatus::OPEN);
});

// ─── 26–27, 30  audit, immutability, one active designation ─────────────────

it('26 audits a designation with ids and the reason length only', function () {
    $target = rbrPlainAccount();
    $designation = rbrDesignate($target, test()->branch, null, 'Rahasia: penunjukan pemilik untuk cabang koreksi.');

    $row = rbdAudit(RmeBillingDisputeAuditEvent::ACTION_REVIEWER_DESIGNATED)->sole();
    expect((int) $row->entity_id)->toBe((int) $designation->id)
        ->and((int) $row->performed_by)->toBe((int) test()->appointer->id)
        ->and($row->new_values['user_id'])->toBe((int) $target->id)
        ->and($row->new_values['branch_id'])->toBe((int) test()->branch->id)
        ->and($row->new_values['reason_length'])->toBeGreaterThan(0)
        ->and(json_encode($row->new_values))->not->toContain('Rahasia');
});

it('27 audits a revocation, keeps the row, and never lets it change or disappear afterwards', function () {
    $designation = rbrDesignate(rbrPlainAccount(), test()->branch);
    rbrRevoke($designation);
    $revoked = $designation->fresh();

    expect($revoked->revoked_at)->not->toBeNull()
        ->and((int) $revoked->revoked_by)->toBe((int) test()->appointer->id)
        ->and(rbdAudit(RmeBillingDisputeAuditEvent::ACTION_REVIEWER_REVOKED))->toHaveCount(1);

    // A second revoke is a no-op: no new audit row, nothing rewritten.
    rbrRevoke($designation);
    expect(rbdAudit(RmeBillingDisputeAuditEvent::ACTION_REVIEWER_REVOKED))->toHaveCount(1);

    expect(fn () => $revoked->forceFill(['revoke_reason' => 'Diubah setelah dicabut.'])->save())->toThrow(LogicException::class)
        ->and(fn () => $revoked->fresh()->forceFill(['branch_id' => test()->otherBranch->id])->save())->toThrow(LogicException::class)
        ->and(fn () => $revoked->fresh()->delete())->toThrow(LogicException::class);
});

it('30 keeps at most one active designation per account and branch, and a re-grant after revocation is a new row', function () {
    $target = rbrPlainAccount();
    $first = rbrDesignate($target, test()->branch);

    rbrExpectValidation(fn () => rbrDesignate($target, test()->branch), 'BILLING_DISPUTE_REVIEWER_DUPLICATE');

    rbrRevoke($first);
    $second = rbrDesignate($target, test()->branch);

    expect((int) $second->id)->not->toBe((int) $first->id)
        ->and(RmeBillingDisputeReviewer::query()->where('user_id', $target->id)->count())->toBe(2)
        ->and($first->fresh()->revoked_at)->not->toBeNull();

    // The database itself refuses a second ACTIVE row (partial unique index).
    expect(fn () => DB::transaction(fn () => DB::table('mst_rme_billing_dispute_reviewers')->insert([
        'user_id' => $target->id, 'branch_id' => test()->branch->id,
        'granted_by' => test()->appointer->id, 'granted_at' => now(), 'grant_reason' => 'Baris aktif kedua lewat SQL mentah.',
        'created_at' => now(), 'updated_at' => now(),
    ])))->toThrow(QueryException::class);
});

// ─── routing, HTTP surface, operations ──────────────────────────────────────

it('routes a new report to the branch designee and counts them as a reachable reviewer', function () {
    $designee = rbrPlainAccount();
    rbrDesignate($designee, test()->branch);
    $outsider = rbrPlainAccount();
    rbrDesignate($outsider, test()->otherBranch);

    rbdReport(ricInvoice());

    expect(DB::table('notifications')->where('notifiable_id', $designee->id)->count())->toBe(1)
        ->and(DB::table('notifications')->where('notifiable_id', $outsider->id)->count())->toBe(0);

    $reviewers = app(RmeBillingDisputeEscalationService::class)->reviewersFor((int) test()->branch->id);
    expect($reviewers->pluck('id')->map(fn ($id) => (int) $id)->all())->toContain((int) $designee->id)
        ->not->toContain((int) $outsider->id);
});

it('lets a designee without any billing permission work the branch inbox over HTTP, and nothing else', function () {
    $designee = rbrPlainAccount();
    rbrDesignate($designee, test()->branch);
    $dispute = rbdReport(ricInvoice());

    rbdActingAs($designee);
    test()->post(route('rme.billing-disputes.review', $dispute->id), ['note' => 'Ditinjau oleh penanggung jawab cabang.'])
        ->assertRedirect();
    test()->post(route('rme.billing-disputes.notes.store', $dispute->id), ['note' => 'Menunggu nota dari dokter.'])
        ->assertRedirect();

    expect($dispute->fresh()->status)->toBe(RmeBillingDisputeStatus::UNDER_REVIEW);

    // An account with the same permissions but no designation is refused at the route.
    rbdActingAs(rbrPlainAccount());
    test()->get(route('rme.billing-disputes.index'))->assertForbidden();
    test()->post(route('rme.billing-disputes.review', $dispute->id), ['note' => 'Bukan penanggung jawab cabang.'])->assertForbidden();
});

it('lets a Super Admin designate and revoke a branch reviewer through the administration page', function () {
    $superAdmin = userInRole('Super Admin');
    $target = rbrPlainAccount();
    rbdActingAs($superAdmin);

    test()->get(route('rme.billing-dispute-reviewers.index'))->assertOk();
    test()->post(route('rme.billing-dispute-reviewers.store'), [
        'user_id' => $target->id, 'branch_id' => test()->branch->id, 'reason' => 'Ditunjuk sebagai penanggung jawab tagihan cabang.',
    ])->assertRedirect();

    $designation = RmeBillingDisputeReviewer::query()->sole();
    expect((int) $designation->user_id)->toBe((int) $target->id)
        ->and((int) $designation->granted_by)->toBe((int) $superAdmin->id);

    test()->post(route('rme.billing-dispute-reviewers.revoke', $designation->id), ['reason' => 'Tugas penanggung jawab berakhir.'])
        ->assertRedirect();
    expect($designation->fresh()->revoked_at)->not->toBeNull();
});

it('seeds the reviewer-designation governance permission to no operational role', function () {
    $roles = Role::query()
        ->whereHas('permissions', fn ($q) => $q->where('name', 'manage_rme_billing_dispute_reviewers'))
        ->pluck('name')->sort()->values()->all();

    expect($roles)->toBe(['Super Admin']);
    foreach (['Owner', 'Front Office', 'Admin Klinik', 'Kasir', 'Supervisor RME', 'Kepala Cabang'] as $role) {
        expect(userInRole($role)->can('manage_rme_billing_dispute_reviewers'))->toBeFalse("{$role} must not appoint reviewers");
    }
});

// ─── 31–36  security review fixes (rule 204 §7, §9, §12, §13, §15) ──────────

it('31 refuses designating a counter account (Front Office or Kasir): the counter reports, it never reviews', function () {
    $frontOffice = rbrFrontOffice(test()->branch);
    $kasir = userInRole('Kasir');
    rmeMakeKasirActive($kasir, test()->branch);

    rbrExpectValidation(fn () => rbrDesignate($frontOffice, test()->branch), 'BILLING_DISPUTE_REVIEWER_COUNTER_ROLE');
    rbrExpectValidation(fn () => rbrDesignate($kasir->fresh(), test()->branch), 'BILLING_DISPUTE_REVIEWER_COUNTER_ROLE');

    expect(RmeBillingDisputeReviewer::query()->count())->toBe(0)
        ->and(app(RmeBillingDisputeReviewerService::class)->candidatesFor(test()->appointer)->pluck('id')->all())
        ->not->toContain((int) $frontOffice->id)
        ->not->toContain((int) $kasir->id);
});

it('32 revalidates eligibility on every use: a designee who later becomes a counter or Owner account loses the authority', function () {
    $becomesCounter = rbrBranchManager(test()->branch);
    rbrDesignate($becomesCounter, test()->branch);
    $becomesOwner = rbrPlainAccount();
    rbrDesignate($becomesOwner, test()->branch);
    $dispute = rbdReport(ricInvoice());

    $becomesCounter->assignRole('Front Office');
    $becomesOwner->assignRole('Owner');
    $reviewers = app(RmeBillingDisputeReviewerService::class);

    foreach ([$becomesCounter->fresh(), $becomesOwner->fresh()] as $account) {
        rbrExpectDenied(fn () => rbrStartReview($dispute, $account), RmeBillingDisputeDenied::PERMISSION_MISSING);
        expect($account->can('review', $dispute->fresh()))->toBeFalse()
            ->and($reviewers->isDesignatedFor($account, (int) test()->branch->id))->toBeFalse()
            ->and($reviewers->designatedBranchIds($account))->toBe([]);
    }

    // Routing stops reaching them too, although both rows are still active.
    expect($reviewers->designatedUserIdsFor((int) test()->branch->id))->toBe([])
        ->and(RmeBillingDisputeReviewer::query()->whereNull('revoked_at')->count())->toBe(2)
        ->and($dispute->fresh()->status)->toBe(RmeBillingDisputeStatus::OPEN);
});

it('33 refuses a designee who ISSUED the invoice from certifying it correct, then lets an independent reviewer do it', function () {
    $designee = rbrBranchManager(test()->branch);
    rbrDesignate($designee, test()->branch);
    $invoice = ricInvoice();
    // The designee is the cashier recorded on the invoice (created at their counter).
    DB::table('trx_rme_invoices')->where('id', $invoice->id)->update(['cashier_id' => $designee->id]);
    $dispute = rbdReport($invoice);

    rbrExpectDenied(fn () => rbdConfirmOriginal($dispute, $designee), RmeBillingDisputeDenied::RESOLVER_NOT_INDEPENDENT);
    expect($dispute->fresh()->status)->toBe(RmeBillingDisputeStatus::OPEN);

    $independent = rbrPlainAccount();
    rbrDesignate($independent, test()->branch);
    expect(rbdConfirmOriginal($dispute, $independent)->fresh()->status)->toBe(RmeBillingDisputeStatus::RESOLVED);
});

it('34 refuses a designee who COLLECTED a payment on the invoice from certifying it correct', function () {
    $designee = rbrBranchManager(test()->branch);
    rbrDesignate($designee, test()->branch);
    $invoice = ricInvoice();
    rbdPay($invoice, 100000, [], $designee);
    $dispute = rbdReport($invoice);

    rbrExpectDenied(fn () => rbdConfirmOriginal($dispute, $designee), RmeBillingDisputeDenied::RESOLVER_NOT_INDEPENDENT);
    expect($dispute->fresh()->status)->toBe(RmeBillingDisputeStatus::OPEN)
        ->and(rbdAudit(RmeBillingDisputeAuditEvent::ACTION_REFUSED)->last()?->new_values['code'] ?? null)
        ->toBe(RmeBillingDisputeDenied::RESOLVER_NOT_INDEPENDENT);
});

it('35 leaves the existing Super Admin path unchanged: the independence rule binds designees only', function () {
    $superAdmin = userInRole('Super Admin');
    $invoice = ricInvoice();
    DB::table('trx_rme_invoices')->where('id', $invoice->id)->update(['cashier_id' => $superAdmin->id]);
    $dispute = rbdReport($invoice);

    expect(rbdConfirmOriginal($dispute, $superAdmin)->fresh()->status)->toBe(RmeBillingDisputeStatus::RESOLVED);
});

it('36 audits every designation refusal with its stable code only, including eligibility refusals', function () {
    $owner = userInRole('Owner');
    $frontOffice = rbrFrontOffice(test()->branch);

    rbrExpectValidation(fn () => rbrDesignate($owner, test()->branch, null, 'Rahasia pemilik: tunjuk Owner.'), 'BILLING_DISPUTE_REVIEWER_OWNER_READ_ONLY');
    rbrExpectValidation(fn () => rbrDesignate($frontOffice, test()->branch), 'BILLING_DISPUTE_REVIEWER_COUNTER_ROLE');

    $codes = rbdAudit(RmeBillingDisputeAuditEvent::ACTION_REVIEWER_REFUSED)->map(fn ($row) => $row->new_values['code'])->all();
    expect($codes)->toBe(['BILLING_DISPUTE_REVIEWER_OWNER_READ_ONLY', 'BILLING_DISPUTE_REVIEWER_COUNTER_ROLE'])
        ->and(json_encode(rbdAudit(RmeBillingDisputeAuditEvent::ACTION_REVIEWER_REFUSED)->map->new_values))->not->toContain('Rahasia');
});

it('37 keeps the route doors narrow: reviewAny is the review permission or an eligible designation, never a role', function () {
    $designee = rbrBranchManager(test()->branch);
    rbrDesignate($designee, test()->branch);
    $frontOffice = rbrFrontOffice(test()->branch);
    $kasir = userInRole('Kasir');
    rmeMakeKasirActive($kasir, test()->branch);

    // The route layer is asserted on its own: the controller's per-record policy
    // would otherwise mask a door that opened to everyone (mutation M17).
    foreach ([$frontOffice, $kasir->fresh(), userInRole('Owner'), rbrPlainAccount()] as $account) {
        expect($account->can('reviewAny', RmeBillingDispute::class))->toBeFalse();
    }
    expect($designee->can('reviewAny', RmeBillingDispute::class))->toBeTrue()
        ->and(userInRole('Super Admin')->can('reviewAny', RmeBillingDispute::class))->toBeTrue();

    foreach (['rme.billing-disputes.review', 'rme.billing-disputes.escalate', 'rme.billing-disputes.resolve'] as $name) {
        expect(app('router')->getRoutes()->getByName($name)->gatherMiddleware())
            ->toContain('can:reviewAny,App\Modules\RmeInvoice\Models\RmeBillingDispute');
    }

    $dispute = rbdReport(ricInvoice());
    rbdActingAs($frontOffice);
    test()->post(route('rme.billing-disputes.review', $dispute->id), ['note' => 'Saya cek sendiri dari loket.'])->assertForbidden();
    expect($dispute->fresh()->status)->toBe(RmeBillingDisputeStatus::OPEN);
});

it('38 refuses a designee who DECIDED a correction of the invoice from certifying its original as correct', function () {
    $designee = rbrBranchManager(test()->branch);
    rbrDesignate($designee, test()->branch);
    $invoice = ricInvoice();

    // The designee is the pilot checker who rejected a correction of this invoice:
    // a judgement about this very price, made through the real correction service.
    ricPilotIdentities([(int) test()->maker->id], [(int) $designee->id]);
    $correction = ricSubmit($invoice);
    test()->actingAs($designee);
    app(RmeInvoiceCorrectionService::class)->reject($correction->fresh(), $designee, 'Harga tambal sudah sesuai nota tindakan.');
    expect((int) $correction->fresh()->decided_by)->toBe((int) $designee->id);

    $dispute = rbdReport($invoice);

    rbrExpectDenied(fn () => rbdConfirmOriginal($dispute, $designee), RmeBillingDisputeDenied::RESOLVER_NOT_INDEPENDENT);
    expect($dispute->fresh()->status)->toBe(RmeBillingDisputeStatus::OPEN);
});
