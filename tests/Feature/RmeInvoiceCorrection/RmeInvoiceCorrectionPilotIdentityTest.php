<?php

/*
| HARDENING-RME-INVOICE-CORRECTION-PILOT-IDENTITY-PAYMENT-ACTOR-1 (rule 197,
| owner decision D1e, 2026-10-21) — the SPN4 pre-payment correction pilot is
| confined to two named ACCOUNTS:
|   - only the designated maker (production: user 29) may SUBMIT;
|   - only the designated checker (production: user 1) may APPROVE (= apply,
|     D1a), REJECT or run the recovery APPLY;
|   - every other account — other Front Office accounts, other Super Admins,
|     Finance Klinik, Data Analyst — is refused, whatever its role grants;
|   - the allowlist is source-controlled and FAIL-CLOSED (empty, malformed or
|     overlapping lists admit nobody);
|   - an inactive, deleted or unsaved account is refused;
|   - CANCEL is deliberately not identity-gated: it is the maker's own
|     de-escalation and the release path that keeps a narrowed allowlist from
|     stranding an invoice behind the D10 payment block.
|
| The enforcement point is the SERVICE, under the invoice row lock. Super Admin
| passes every policy through Gate::before, so a policy is a courtesy here,
| never the control — every refusal below is asserted against the service and,
| where an operator reaches it, over HTTP.
*/

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\LabOrder\Models\AuditLog;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use App\Modules\RmeInvoice\Models\RmeInvoiceCorrection;
use App\Modules\RmeInvoice\Models\RmePayment;
use App\Modules\RmeInvoice\Services\RmeInvoiceCorrectionReadinessService;
use App\Modules\RmeInvoice\Services\RmeInvoiceCorrectionService;
use App\Modules\RmeInvoice\Support\RmeFinancialAuditEvent;
use App\Modules\RmeInvoice\Support\RmeInvoiceCorrectionDenied;
use App\Modules\RmeInvoice\Support\RmeInvoiceCorrectionPermission;
use App\Modules\RmeInvoice\Support\RmeInvoiceCorrectionStatus;
use App\Modules\RmeOnlineContext\Middleware\EnsureRmeOnlineContext;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/helpers.php';

beforeEach(function () {
    ricSetup();

    // Production-shaped actors: the designated maker is a Front Office account
    // working in the pilot branch (user 29), the designated checker a Super Admin
    // (user 1). The "others" hold exactly the same roles and grants.
    $t = test();
    $t->pilotMaker = User::factory()->create();
    rmeMakeFrontOfficeActive($t->pilotMaker, $t->branch);
    $t->pilotChecker = userInRole('Super Admin');

    $t->otherFrontOffice = User::factory()->create();
    rmeMakeFrontOfficeActive($t->otherFrontOffice, $t->branch);
    $t->otherSuperAdmin = userInRole('Super Admin');

    ricPilotIdentities([(int) $t->pilotMaker->id], [(int) $t->pilotChecker->id]);
});

function rpiDenied(callable $call, string $code): void
{
    try {
        $call();
    } catch (RmeInvoiceCorrectionDenied $e) {
        expect($e->reasonCode)->toBe($code);

        return;
    }

    test()->fail("Expected the correction service to refuse with {$code}; it allowed the action.");
}

function rpiService(): RmeInvoiceCorrectionService
{
    return app(RmeInvoiceCorrectionService::class);
}

/** A SUBMITTED correction by the designated maker. */
function rpiSubmitted(?RmeInvoice $invoice = null): RmeInvoiceCorrection
{
    return ricSubmit($invoice ?? ricInvoice(), test()->pilotMaker);
}

/**
 * A SUBMITTED correction whose maker is NOT the designated maker — produced by
 * widening the maker list for the submission only, then restoring it. This is
 * how a correction filed before a narrowing looks, and it lets a test refuse the
 * designated maker on IDENTITY rather than on self-approval.
 */
function rpiSubmittedByAnotherMaker(): RmeInvoiceCorrection
{
    ricPilotIdentities([(int) test()->pilotMaker->id, (int) test()->maker->id], [(int) test()->pilotChecker->id]);
    $correction = ricSubmit(ricInvoice(), test()->maker);
    ricPilotIdentities([(int) test()->pilotMaker->id], [(int) test()->pilotChecker->id]);

    return $correction;
}

function rpiStatus(RmeInvoiceCorrection $correction): string
{
    return (string) $correction->fresh()->status;
}

// ─── The designated maker ───────────────────────────────────────────────────

it('lets the designated maker submit a correction', function () {
    $correction = rpiSubmitted();

    expect($correction->status)->toBe(RmeInvoiceCorrectionStatus::SUBMITTED)
        ->and((int) $correction->requested_by)->toBe((int) test()->pilotMaker->id);
});

it('refuses the designated maker approving, even a correction filed by somebody else', function () {
    $correction = rpiSubmittedByAnotherMaker();

    test()->actingAs(test()->pilotMaker);
    rpiDenied(fn () => rpiService()->approve($correction, test()->pilotMaker), 'PILOT_IDENTITY_NOT_AUTHORIZED');

    expect(rpiStatus($correction))->toBe(RmeInvoiceCorrectionStatus::SUBMITTED);
});

it('refuses the designated maker rejecting a correction', function () {
    $correction = rpiSubmittedByAnotherMaker();

    test()->actingAs(test()->pilotMaker);
    rpiDenied(fn () => rpiService()->reject($correction, test()->pilotMaker, 'Ditolak oleh pengaju pilot.'), 'PILOT_IDENTITY_NOT_AUTHORIZED');

    expect(rpiStatus($correction))->toBe(RmeInvoiceCorrectionStatus::SUBMITTED);
});

it('refuses the designated maker approving their own correction', function () {
    $correction = rpiSubmitted();

    test()->actingAs(test()->pilotMaker);
    rpiDenied(fn () => rpiService()->approve($correction, test()->pilotMaker), RmeInvoiceCorrectionDenied::SELF_APPROVAL);

    expect(rpiStatus($correction))->toBe(RmeInvoiceCorrectionStatus::SUBMITTED);
});

it('refuses the designated maker the recovery apply of a legacy APPROVED row', function () {
    $correction = ricLegacyApproved(rpiSubmitted(), test()->pilotChecker);

    test()->actingAs(test()->pilotMaker);
    expect(fn () => rpiService()->apply($correction, test()->pilotMaker))->toThrow(RmeInvoiceCorrectionDenied::class);

    expect(rpiStatus($correction))->toBe(RmeInvoiceCorrectionStatus::APPROVED);
});

// ─── The designated checker ─────────────────────────────────────────────────

it('lets the designated checker approve, which applies the correction (D1a)', function () {
    $correction = rpiSubmitted();

    $result = ricApprove($correction, test()->pilotChecker);

    expect($result->status)->toBe(RmeInvoiceCorrectionStatus::APPLIED)
        ->and((int) $result->decided_by)->toBe((int) test()->pilotChecker->id)
        ->and((int) $result->applied_by)->toBe((int) test()->pilotChecker->id);
});

it('lets the designated checker reject', function () {
    $correction = rpiSubmitted();

    test()->actingAs(test()->pilotChecker);
    rpiService()->reject($correction, test()->pilotChecker, 'Koreksi tidak diperlukan.');

    expect(rpiStatus($correction))->toBe(RmeInvoiceCorrectionStatus::REJECTED);
});

it('refuses the designated checker submitting a correction', function () {
    $invoice = ricInvoice();

    expect(test()->pilotChecker->can(RmeInvoiceCorrectionPermission::REQUEST))->toBeTrue();
    rpiDenied(fn () => ricSubmit($invoice, test()->pilotChecker), 'PILOT_IDENTITY_NOT_AUTHORIZED');

    expect(RmeInvoiceCorrection::query()->where('rme_invoice_id', $invoice->id)->count())->toBe(0);
});

it('lets only the designated checker run the recovery apply', function () {
    $correction = ricLegacyApproved(rpiSubmitted(), test()->pilotChecker);

    test()->actingAs(test()->otherSuperAdmin);
    rpiDenied(fn () => rpiService()->apply($correction, test()->otherSuperAdmin), 'PILOT_IDENTITY_NOT_AUTHORIZED');
    expect(rpiStatus($correction))->toBe(RmeInvoiceCorrectionStatus::APPROVED);

    test()->actingAs(test()->pilotChecker);
    $applied = rpiService()->apply($correction, test()->pilotChecker);

    expect($applied->status)->toBe(RmeInvoiceCorrectionStatus::APPLIED);
});

// ─── Everybody else ─────────────────────────────────────────────────────────

it('refuses another Front Office account of the pilot branch every mutation', function () {
    $other = test()->otherFrontOffice;
    expect($other->can(RmeInvoiceCorrectionPermission::REQUEST))->toBeTrue()
        ->and($other->can(RmeInvoiceCorrectionPermission::APPROVE))->toBeTrue();

    $invoice = ricInvoice();
    rpiDenied(fn () => ricSubmit($invoice, $other), 'PILOT_IDENTITY_NOT_AUTHORIZED');

    $correction = rpiSubmitted(ricInvoice());
    test()->actingAs($other);
    rpiDenied(fn () => rpiService()->approve($correction, $other), 'PILOT_IDENTITY_NOT_AUTHORIZED');
    rpiDenied(fn () => rpiService()->reject($correction, $other, 'Bukan pemeriksa pilot.'), 'PILOT_IDENTITY_NOT_AUTHORIZED');

    expect(rpiStatus($correction))->toBe(RmeInvoiceCorrectionStatus::SUBMITTED)
        ->and(RmeInvoiceCorrection::query()->where('rme_invoice_id', $invoice->id)->count())->toBe(0);
});

it('refuses another Super Admin every mutation, whatever Gate::before grants', function () {
    $other = test()->otherSuperAdmin;

    $invoice = ricInvoice();
    rpiDenied(fn () => ricSubmit($invoice, $other), 'PILOT_IDENTITY_NOT_AUTHORIZED');

    $correction = rpiSubmitted(ricInvoice());
    test()->actingAs($other);
    rpiDenied(fn () => rpiService()->approve($correction, $other), 'PILOT_IDENTITY_NOT_AUTHORIZED');
    rpiDenied(fn () => rpiService()->reject($correction, $other, 'Bukan pemeriksa pilot.'), 'PILOT_IDENTITY_NOT_AUTHORIZED');

    expect(rpiStatus($correction))->toBe(RmeInvoiceCorrectionStatus::SUBMITTED);
});

it('refuses the read-only oversight roles before identity is even considered', function (string $role) {
    $user = userInRole($role);
    $correction = rpiSubmitted();
    test()->actingAs($user);

    rpiDenied(fn () => ricSubmit(ricInvoice(), $user), RmeInvoiceCorrectionDenied::PERMISSION_MISSING);
    rpiDenied(fn () => rpiService()->approve($correction, $user), RmeInvoiceCorrectionDenied::PERMISSION_MISSING);
    rpiDenied(fn () => rpiService()->reject($correction, $user, 'Peran pengawasan saja.'), RmeInvoiceCorrectionDenied::PERMISSION_MISSING);
})->with(['Finance Klinik', 'Data Analyst']);

it('audits an identity refusal with ids and the code only', function () {
    $other = test()->otherFrontOffice;
    $invoice = ricInvoice();
    rpiDenied(fn () => ricSubmit($invoice, $other), 'PILOT_IDENTITY_NOT_AUTHORIZED');

    $row = AuditLog::query()->where('action', RmeFinancialAuditEvent::ACTION_CORRECTION_REFUSED)->latest('id')->firstOrFail();
    expect($row->new_values['code'])->toBe('PILOT_IDENTITY_NOT_AUTHORIZED')
        ->and($row->new_values['attempted_action'])->toBe('submit')
        ->and((int) $row->new_values['rme_invoice_id'])->toBe((int) $invoice->id)
        ->and(json_encode($row->new_values))->not->toContain('Pasien Rahasia');
});

// ─── Unusable actors ────────────────────────────────────────────────────────

it('refuses the designated maker once deactivated', function () {
    test()->pilotMaker->forceFill(['is_active' => false])->save();
    $invoice = ricInvoice();

    rpiDenied(fn () => ricSubmit($invoice, test()->pilotMaker->fresh()), 'PILOT_IDENTITY_ACTOR_UNUSABLE');
});

it('refuses the designated checker once deactivated or deleted', function () {
    $correction = rpiSubmitted();

    test()->pilotChecker->forceFill(['is_active' => false])->save();
    test()->actingAs(test()->pilotChecker->fresh());
    rpiDenied(fn () => rpiService()->approve($correction, test()->pilotChecker->fresh()), 'PILOT_IDENTITY_ACTOR_UNUSABLE');

    test()->pilotChecker->forceFill(['is_active' => true])->save();
    test()->pilotChecker->delete();
    rpiDenied(fn () => rpiService()->approve($correction, test()->pilotChecker), 'PILOT_IDENTITY_ACTOR_UNUSABLE');

    expect(rpiStatus($correction))->toBe(RmeInvoiceCorrectionStatus::SUBMITTED);
});

it('refuses an unsaved account object that merely carries a designated id', function () {
    $forged = new User(['name' => 'Bukan akun', 'email' => 'forged@example.test']);
    $forged->id = test()->pilotChecker->id; // in-memory only, never persisted
    $forged->is_active = true;

    $correction = rpiSubmitted();

    rpiDenied(fn () => rpiService()->approve($correction, $forged), 'PILOT_IDENTITY_ACTOR_UNUSABLE');
    expect(rpiStatus($correction))->toBe(RmeInvoiceCorrectionStatus::SUBMITTED);
});

// ─── Fail-closed configuration ──────────────────────────────────────────────

it('fails closed when the identity allowlist is empty, missing or malformed', function (mixed $identities) {
    config()->set('rme_invoice_correction.pilot.identities', $identities);
    $invoice = ricInvoice();

    test()->actingAs(test()->pilotMaker);
    rpiDenied(fn () => ricSubmit($invoice, test()->pilotMaker), 'PILOT_IDENTITY_NOT_AUTHORIZED');
})->with([
    'missing' => [null],
    'not an array' => ['29'],
    'empty' => [[]],
    'empty lists' => [['maker_user_ids' => [], 'checker_user_ids' => []]],
    'string ids' => [fn () => ['maker_user_ids' => [(string) test()->pilotMaker->id], 'checker_user_ids' => [(string) test()->pilotChecker->id]]],
    'zero id' => [fn () => ['maker_user_ids' => [0, test()->pilotMaker->id], 'checker_user_ids' => [test()->pilotChecker->id]]],
    'overlapping lists' => [fn () => ['maker_user_ids' => [test()->pilotMaker->id], 'checker_user_ids' => [test()->pilotChecker->id, test()->pilotMaker->id]]],
    'no checker list' => [fn () => ['maker_user_ids' => [test()->pilotMaker->id]]],
]);

it('refuses the designated maker when no checker is designated, so nothing can be filed that nobody may decide', function () {
    ricPilotIdentities([(int) test()->pilotMaker->id], []);
    $invoice = ricInvoice();

    rpiDenied(fn () => ricSubmit($invoice, test()->pilotMaker), 'PILOT_IDENTITY_NOT_AUTHORIZED');
    expect(RmeInvoiceCorrection::query()->where('rme_invoice_id', $invoice->id)->count())->toBe(0);
});

it('refuses the designated maker when the only designated checker is not a usable account (security review L2)', function () {
    test()->pilotChecker->forceFill(['is_active' => false])->save();
    $invoice = ricInvoice();

    rpiDenied(fn () => ricSubmit($invoice, test()->pilotMaker), 'PILOT_IDENTITY_NOT_AUTHORIZED');
    expect(RmeInvoiceCorrection::query()->where('rme_invoice_id', $invoice->id)->count())->toBe(0);

    $report = app(RmeInvoiceCorrectionReadinessService::class)->report();
    expect(collect($report['findings'])->firstWhere('code', 'PILOT_IDENTITY_ACCOUNT_UNUSABLE')['detail'] ?? null)
        ->toBe((string) test()->pilotChecker->id)
        ->and($report['decision'])->toBe(RmeInvoiceCorrectionReadinessService::FAIL);
});

it('fails the probe when a designated id names no account at all', function () {
    ricPilotIdentities([(int) test()->pilotMaker->id], [987654]);

    $report = app(RmeInvoiceCorrectionReadinessService::class)->report();

    expect(collect($report['findings'])->pluck('code'))->toContain('PILOT_IDENTITY_ACCOUNT_UNUSABLE')
        ->and($report['decision'])->toBe(RmeInvoiceCorrectionReadinessService::FAIL);
});

it('refuses the designated checker when the allowlist is malformed', function () {
    $correction = rpiSubmitted();
    config()->set('rme_invoice_correction.pilot.identities', ['maker_user_ids' => [test()->pilotMaker->id], 'checker_user_ids' => ['1']]);

    test()->actingAs(test()->pilotChecker);
    rpiDenied(fn () => rpiService()->approve($correction, test()->pilotChecker), 'PILOT_IDENTITY_NOT_AUTHORIZED');
    expect(rpiStatus($correction))->toBe(RmeInvoiceCorrectionStatus::SUBMITTED);
});

it('keeps the flag a global kill switch above the identity allowlist', function () {
    ricFlag(false);

    expect(fn () => ricSubmit(ricInvoice(), test()->pilotMaker))->toThrow(ValidationException::class);
    expect(RmeInvoiceCorrection::query()->count())->toBe(0);
});

it('keeps the branch allowlist in force for the designated checker', function () {
    $correction = rpiSubmitted();
    ricPilot([]);

    test()->actingAs(test()->pilotChecker);
    rpiDenied(fn () => rpiService()->approve($correction, test()->pilotChecker), RmeInvoiceCorrectionDenied::BRANCH_NOT_IN_PILOT);
    expect(rpiStatus($correction))->toBe(RmeInvoiceCorrectionStatus::SUBMITTED);
});

// ─── Release paths survive a narrowed allowlist ─────────────────────────────

it('lets the maker cancel their own correction after the allowlist no longer names them, releasing D10', function () {
    $invoice = ricInvoice();
    $correction = rpiSubmitted($invoice);
    ricPilotIdentities([], [(int) test()->pilotChecker->id]);

    test()->actingAs(test()->pilotMaker);
    rpiService()->cancel($correction, test()->pilotMaker, 'Salah pilih tagihan, dibatalkan.');
    expect(rpiStatus($correction))->toBe(RmeInvoiceCorrectionStatus::CANCELLED);

    // The invoice is payable again — the narrowing stranded nothing.
    ricPay($invoice, 100000);
    expect(RmePayment::query()->where('rme_invoice_id', $invoice->id)->count())->toBe(1);
});

it('lets the designated checker reject after the maker list was emptied', function () {
    $correction = rpiSubmitted();
    ricPilotIdentities([], [(int) test()->pilotChecker->id]);

    test()->actingAs(test()->pilotChecker);
    rpiService()->reject($correction, test()->pilotChecker, 'Ditolak setelah pilot dipersempit.');

    expect(rpiStatus($correction))->toBe(RmeInvoiceCorrectionStatus::REJECTED);
});

it('never lets another account cancel somebody else\'s correction', function () {
    $correction = rpiSubmitted();

    foreach ([test()->otherFrontOffice, test()->pilotChecker, test()->otherSuperAdmin] as $user) {
        test()->actingAs($user);
        rpiDenied(fn () => rpiService()->cancel($correction, $user, 'Bukan pengaju koreksi ini.'), RmeInvoiceCorrectionDenied::NOT_THE_MAKER);
    }

    expect(rpiStatus($correction))->toBe(RmeInvoiceCorrectionStatus::SUBMITTED);
});

// ─── HTTP — the surface an operator reaches ─────────────────────────────────

it('serves the designated maker the request form and refuses it to everyone else', function () {
    test()->withoutMiddleware(EnsureRmeOnlineContext::class);
    $visit = ricVisit();
    $invoice = ricInvoice($visit);

    test()->actingAs(test()->pilotMaker)->get(route('rme.invoice-corrections.create', [$visit, $invoice]))->assertOk();

    foreach ([test()->pilotChecker, test()->otherFrontOffice, test()->otherSuperAdmin] as $user) {
        Auth::logout();
        test()->actingAs($user)->get(route('rme.invoice-corrections.create', [$visit, $invoice]))->assertForbidden();
        test()->actingAs($user)->post(route('rme.invoice-corrections.store', [$visit, $invoice]), [
            'reason' => 'Harga tambal salah input kasir.',
            'lines' => ricLinesReducing($invoice),
        ])->assertForbidden();
    }

    expect(RmeInvoiceCorrection::query()->where('rme_invoice_id', $invoice->id)->count())->toBe(0);
});

it('ignores a forged actor or branch field in the request body', function () {
    test()->withoutMiddleware(EnsureRmeOnlineContext::class);
    $visit = ricVisit();
    $invoice = ricInvoice($visit);
    $elsewhere = Branch::factory()->create(['code' => 'IC09', 'is_rme_enabled' => true, 'is_active' => true]);

    test()->actingAs(test()->otherFrontOffice)->post(route('rme.invoice-corrections.store', [$visit, $invoice]), [
        'reason' => 'Harga tambal salah input kasir.',
        'lines' => ricLinesReducing($invoice),
        'requested_by' => test()->pilotMaker->id,
        'user_id' => test()->pilotMaker->id,
        'maker_id' => test()->pilotMaker->id,
    ])->assertForbidden();
    expect(RmeInvoiceCorrection::query()->count())->toBe(0);

    test()->actingAs(test()->pilotMaker)->post(route('rme.invoice-corrections.store', [$visit, $invoice]), [
        'reason' => 'Harga tambal salah input kasir.',
        'lines' => ricLinesReducing($invoice),
        'branch_id' => $elsewhere->id,
        'requested_by' => test()->otherFrontOffice->id,
    ])->assertRedirect();

    $correction = RmeInvoiceCorrection::query()->sole();
    expect((int) $correction->requested_by)->toBe((int) test()->pilotMaker->id)
        ->and((int) $correction->branch_id)->toBe((int) test()->branch->id);
});

it('refuses non-designated approvers over HTTP and lets the designated checker approve', function () {
    test()->withoutMiddleware(EnsureRmeOnlineContext::class);
    $correction = rpiSubmitted();

    foreach ([test()->pilotMaker, test()->otherFrontOffice, test()->otherSuperAdmin] as $user) {
        Auth::logout();
        test()->actingAs($user)->post(route('rme.invoice-corrections.approve', $correction->id))->assertForbidden();
        test()->actingAs($user)->post(route('rme.invoice-corrections.reject', $correction->id), ['note' => 'Bukan pemeriksa pilot.'])->assertForbidden();
    }
    expect(rpiStatus($correction))->toBe(RmeInvoiceCorrectionStatus::SUBMITTED);

    Auth::logout();
    test()->actingAs(test()->pilotChecker)->post(route('rme.invoice-corrections.approve', $correction->id))->assertRedirect();
    expect(rpiStatus($correction))->toBe(RmeInvoiceCorrectionStatus::APPLIED);
});

it('refuses an unauthenticated mutation', function () {
    $correction = rpiSubmitted();
    Auth::logout();

    test()->post(route('rme.invoice-corrections.approve', $correction->id))->assertRedirect(route('login'));
    expect(rpiStatus($correction))->toBe(RmeInvoiceCorrectionStatus::SUBMITTED);
});

it('shows the decision controls only to the designated checker', function () {
    test()->withoutMiddleware(EnsureRmeOnlineContext::class);
    $correction = rpiSubmitted();

    test()->actingAs(test()->pilotChecker)->get(route('rme.invoice-corrections.show', $correction->id))
        ->assertOk()
        ->assertSee('data-correction-approve-applies', false)
        ->assertSee('data-correction-reject', false)
        ->assertDontSee('data-correction-cancel', false);

    foreach ([test()->otherSuperAdmin, test()->otherFrontOffice] as $user) {
        Auth::logout();
        test()->actingAs($user)->get(route('rme.invoice-corrections.show', $correction->id))
            ->assertOk()
            ->assertDontSee('data-correction-approve-applies', false)
            ->assertDontSee('data-correction-reject', false)
            ->assertDontSee('data-correction-cancel', false);
    }

    Auth::logout();
    test()->actingAs(test()->pilotMaker)->get(route('rme.invoice-corrections.show', $correction->id))
        ->assertOk()
        ->assertDontSee('data-correction-approve-applies', false)
        ->assertDontSee('data-correction-reject', false)
        ->assertSee('data-correction-cancel', false);
});

it('offers the request action on the cashier page only to the designated maker', function () {
    $invoice = ricInvoice();
    $service = rpiService();

    expect($service->panelFor($invoice, test()->pilotMaker)['can_request'])->toBeTrue()
        ->and($service->panelFor($invoice, test()->pilotChecker)['can_request'])->toBeFalse()
        ->and($service->panelFor($invoice, test()->otherFrontOffice)['can_request'])->toBeFalse()
        ->and($service->panelFor($invoice, test()->otherSuperAdmin)['can_request'])->toBeFalse();
});

// ─── The readiness probe reports the identities ─────────────────────────────

it('reports the identity allowlist and validates the pair against it', function () {
    $probe = app(RmeInvoiceCorrectionReadinessService::class);

    $ok = $probe->report([], (int) test()->pilotMaker->id, (int) test()->pilotChecker->id);
    expect($ok['pilot_identities']['valid'])->toBeTrue()
        ->and($ok['pilot_identities']['maker_user_ids'])->toBe([(int) test()->pilotMaker->id])
        ->and($ok['pilot_identities']['checker_user_ids'])->toBe([(int) test()->pilotChecker->id])
        ->and($ok['maker_checker_pair']['valid'])->toBeTrue();

    $wrong = $probe->report([], (int) test()->otherFrontOffice->id, (int) test()->otherSuperAdmin->id);
    $codes = collect($wrong['findings'])->pluck('code');
    expect($wrong['maker_checker_pair']['valid'])->toBeFalse()
        ->and($codes)->toContain('PAIR_MAKER_NOT_PILOT_IDENTITY')
        ->and($codes)->toContain('PAIR_CHECKER_NOT_PILOT_IDENTITY')
        ->and($wrong['decision'])->toBe(RmeInvoiceCorrectionReadinessService::FAIL);
});

it('fails the probe when the identity allowlist is malformed', function () {
    config()->set('rme_invoice_correction.pilot.identities', ['maker_user_ids' => [7], 'checker_user_ids' => [7]]);

    $report = app(RmeInvoiceCorrectionReadinessService::class)->report();

    expect($report['pilot_identities']['valid'])->toBeFalse()
        ->and(collect($report['findings'])->pluck('code'))->toContain('PILOT_IDENTITY_CONFIG_INVALID')
        ->and($report['decision'])->toBe(RmeInvoiceCorrectionReadinessService::FAIL);
});

// ─── The shipped allowlist is exactly the owner's decision ──────────────────

it('ships exactly the owner-designated accounts, source-controlled with no environment read', function () {
    $source = file_get_contents(base_path('config/rme_invoice_correction.php'));
    $shipped = require base_path('config/rme_invoice_correction.php');

    expect($shipped['pilot']['identities'])->toBe([
        'maker_user_ids' => [29],
        'checker_user_ids' => [1],
    ])
        ->and($shipped['pilot']['branch_codes'])->toBe(['SPN4'])
        ->and($source)->not->toContain('env(');
});
