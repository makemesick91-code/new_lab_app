<?php

/*
| ACTIVATION-RME-INVOICE-CORRECTION-PREPAYMENT-2 (rule 195) — the activation
| contract of the pre-payment invoice correction:
|   - owner D1a: the CHECKER's approval APPLIES the correction, atomically;
|   - maker != checker != applier, per ACCOUNT, Super Admin included;
|   - the source-controlled pilot allowlist (SPN4 in production), fail-closed;
|     reject/cancel are never gated by it;
|   - RoleSeeder grants per D1 + the owner's Front Office decision; no operational
|     role holds the separate apply permission;
|   - presentation never rounds a stored amount; provenance and qty residuals;
|   - the read-only production authorization probe.
*/

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\Branch\Services\BranchService;
use App\Modules\ClinicOversight\Support\OversightFormat;
use App\Modules\DoctorAccess\Services\DoctorEffectiveBranchResolver;
use App\Modules\LabOrder\Models\AuditLog;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use App\Modules\RmeInvoice\Models\RmeInvoiceCorrection;
use App\Modules\RmeInvoice\Models\RmeInvoiceCorrectionEvent;
use App\Modules\RmeInvoice\Models\RmeInvoiceDocumentVersion;
use App\Modules\RmeInvoice\Services\RmeInvoiceCorrectionProposalBuilder;
use App\Modules\RmeInvoice\Services\RmeInvoiceCorrectionReadinessService;
use App\Modules\RmeInvoice\Services\RmeInvoiceCorrectionService;
use App\Modules\RmeInvoice\Services\RmeInvoicePricingService;
use App\Modules\RmeInvoice\Support\RmeFinancialAuditEvent;
use App\Modules\RmeInvoice\Support\RmeInvoiceCorrectionDenied;
use App\Modules\RmeInvoice\Support\RmeInvoiceCorrectionPermission;
use App\Modules\RmeInvoice\Support\RmeInvoiceCorrectionPilotScope;
use App\Modules\RmeInvoice\Support\RmeInvoiceCorrectionStatus;
use App\Modules\RmeInvoice\Support\RmeInvoiceCorrectionType;
use App\Modules\RmeInvoice\Support\RmeInvoiceDocument;
use App\Modules\RmeInvoice\Support\RmeInvoicePriceSource;
use App\Modules\RmeOnlineContext\Middleware\EnsureRmeOnlineContext;
use App\Modules\RmeOnlineContext\Services\RmeWorkingBranchScope;
use App\Modules\RmeOnlineContext\Services\UserOnlineContextService;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

require_once __DIR__.'/helpers.php';

beforeEach(fn () => ricSetup());

function rpaDenied(callable $call, string $code): void
{
    try {
        $call();
        test()->fail("Expected denial {$code}");
    } catch (RmeInvoiceCorrectionDenied $e) {
        expect($e->reasonCode)->toBe($code);
    }
}

/** A payment row written past the D10 guard (own copy: this file must run alone too). */
function rpaPaymentRowBypassingTheService(RmeInvoice $invoice, float $amount): void
{
    DB::table('trx_rme_payments')->insert([
        'branch_id' => $invoice->branch_id, 'rme_invoice_id' => $invoice->id,
        'clinic_visit_id' => $invoice->clinic_visit_id, 'patient_id' => $invoice->patient_id,
        'cashier_id' => test()->cashier->id, 'payment_number' => 'PAY-BYPASS-'.uniqid(),
        'amount' => $amount, 'paid_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('trx_rme_invoices')->where('id', $invoice->id)->update(['status' => RmeInvoice::STATUS_PARTIAL]);
}

function rpaService(): RmeInvoiceCorrectionService
{
    return app(RmeInvoiceCorrectionService::class);
}

// ─── Owner D1a — approval APPLIES, atomically ───────────────────────────────

it('applies the correction in the same transaction as the approval (D1a)', function () {
    $invoice = ricInvoice();
    $correction = ricSubmit($invoice);

    $result = ricApprove($correction);

    $invoice->refresh();
    expect($result->status)->toBe(RmeInvoiceCorrectionStatus::APPLIED)
        ->and((int) $result->decided_by)->toBe((int) test()->checker->id)
        ->and((int) $result->applied_by)->toBe((int) test()->checker->id)
        ->and((int) $result->applied_invoice_version)->toBe(1)
        ->and((int) $invoice->correction_version)->toBe(1)
        ->and((float) $invoice->grand_total)->toEqual(350000.0)
        ->and($invoice->status)->toBe(RmeInvoice::STATUS_UNPAID);

    // v0 frozen original + v1 revision, both immutable rows.
    $versions = RmeInvoiceDocumentVersion::query()->where('rme_invoice_id', $invoice->id)->orderBy('version_number')->get();
    expect($versions->pluck('version_number')->all())->toBe([0, 1])
        ->and($versions[0]->version_kind)->toBe(RmeInvoiceDocumentVersion::KIND_ORIGINAL)
        ->and($versions[1]->version_kind)->toBe(RmeInvoiceDocumentVersion::KIND_REVISION);

    // The event chain records BOTH transitions, by the checker.
    $events = RmeInvoiceCorrectionEvent::query()->where('rme_invoice_correction_id', $correction->id)->orderBy('id')->get();
    expect($events->map(fn ($e) => ($e->from_status ?? '∅').'→'.$e->to_status)->all())
        ->toBe(['∅→SUBMITTED', 'SUBMITTED→APPROVED', 'APPROVED→APPLIED'])
        ->and((int) $events[1]->actor_id)->toBe((int) test()->checker->id)
        ->and((int) $events[2]->actor_id)->toBe((int) test()->checker->id);

    $applied = AuditLog::query()->where('action', RmeFinancialAuditEvent::ACTION_CORRECTION_APPLIED)->where('entity_id', $correction->id)->sole();
    expect($applied->new_values['application_mode'])->toBe(RmeInvoiceCorrectionService::APPLICATION_AUTO_ON_APPROVAL);
    $approved = AuditLog::query()->where('action', RmeFinancialAuditEvent::ACTION_CORRECTION_APPROVED)->where('entity_id', $correction->id)->sole();
    expect($approved->new_values['application_mode'])->toBe(RmeInvoiceCorrectionService::APPLICATION_AUTO_ON_APPROVAL);
});

it('needs only the APPROVE permission to approve-and-apply (no separate apply grant)', function () {
    $invoice = ricInvoice();
    $correction = ricSubmit($invoice);

    expect(test()->checker->can(RmeInvoiceCorrectionPermission::APPLY))->toBeFalse();

    expect(ricApprove($correction)->status)->toBe(RmeInvoiceCorrectionStatus::APPLIED);
});

it('records neither the approval nor the application when the application is refused', function () {
    $invoice = ricInvoice();
    $correction = ricSubmit($invoice);
    $before = $invoice->fresh()->only(['grand_total', 'subtotal', 'discount_total', 'correction_version']);

    // A correction type that is not enabled cannot be applied — so it cannot be approved either.
    DB::table('trx_rme_invoice_corrections')->where('id', $correction->id)->update(['correction_type' => 'POST_PAYMENT']);

    expect(fn () => ricApprove($correction->fresh()))->toThrow(ValidationException::class);

    expect($correction->fresh()->status)->toBe(RmeInvoiceCorrectionStatus::SUBMITTED)
        ->and($correction->fresh()->decided_by)->toBeNull()
        ->and($invoice->fresh()->only(['grand_total', 'subtotal', 'discount_total', 'correction_version']))->toBe($before)
        ->and(RmeInvoiceCorrectionEvent::query()->where('rme_invoice_correction_id', $correction->id)->count())->toBe(1)
        ->and(RmeInvoiceDocumentVersion::query()->where('rme_invoice_id', $invoice->id)->count())->toBe(0);
});

it('treats a repeated approval of an applied correction as a no-op, never a second application', function () {
    $invoice = ricInvoice();
    $correction = ricApprove(ricSubmit($invoice));
    $versions = RmeInvoiceDocumentVersion::query()->where('rme_invoice_id', $invoice->id)->count();

    $again = ricApprove($correction);

    expect($again->status)->toBe(RmeInvoiceCorrectionStatus::APPLIED)
        ->and((int) $invoice->fresh()->correction_version)->toBe(1)
        ->and(RmeInvoiceDocumentVersion::query()->where('rme_invoice_id', $invoice->id)->count())->toBe($versions);
});

it('never leaves an approved-but-unapplied correction blocking payment under D1a', function () {
    $invoice = ricInvoice();
    ricApprove(ricSubmit($invoice));

    expect(RmeInvoiceCorrection::query()->where('rme_invoice_id', $invoice->id)->whereIn('status', RmeInvoiceCorrectionStatus::ACTIVE)->exists())->toBeFalse();

    // The new version is payable at once (against the NEW correction_version).
    ricPay($invoice->fresh(), 350000);
    expect($invoice->fresh()->status)->toBe(RmeInvoice::STATUS_PAID);
});

it('marks the correction STALE at approval and applies nothing when the invoice changed', function (string $mutation, string $code) {
    $invoice = ricInvoice();
    $correction = ricSubmit($invoice);

    match ($mutation) {
        'payment' => rpaPaymentRowBypassingTheService($invoice, 100000),
        'content' => DB::table('trx_rme_invoice_items')->where('id', $invoice->items()->first()->id)->update(['description' => 'Diubah di luar alur']),
        'version' => DB::table('trx_rme_invoices')->where('id', $invoice->id)->update(['correction_version' => 7]),
    };

    try {
        ricApprove($correction);
        test()->fail('Expected a STALE refusal');
    } catch (ValidationException $e) {
        expect(json_encode($e->errors()))->toContain('CORRECTION_STALE:'.$code);
    }

    expect($correction->fresh()->status)->toBe(RmeInvoiceCorrectionStatus::STALE)
        ->and($correction->fresh()->decided_by)->toBeNull()
        ->and(RmeInvoiceDocumentVersion::query()->where('rme_invoice_id', $invoice->id)->count())->toBe(0)
        ->and(AuditLog::query()->where('action', RmeFinancialAuditEvent::ACTION_CORRECTION_APPLIED)->count())->toBe(0);
})->with([
    'payment row (defence behind D10)' => ['payment', 'PAYMENT_RECORDED'],
    'content changed outside the workflow' => ['content', 'INVOICE_CONTENT_CHANGED'],
    'version moved' => ['version', 'INVOICE_VERSION_CHANGED'],
]);

it('rolls back the approval too when the applied total would differ from the approved total', function () {
    $invoice = ricInvoice();
    $correction = ricSubmit($invoice);
    DB::table('trx_rme_invoice_corrections')->where('id', $correction->id)->update(['proposed_grand_total' => '1.00', 'difference_amount' => '-499999.00']);

    expect(fn () => ricApprove($correction->fresh()))->toThrow(LogicException::class);

    expect($correction->fresh()->status)->toBe(RmeInvoiceCorrectionStatus::SUBMITTED)
        ->and($correction->fresh()->decided_by)->toBeNull()
        ->and((float) $invoice->fresh()->grand_total)->toEqual(500000.0)
        ->and(RmeInvoiceDocumentVersion::query()->where('rme_invoice_id', $invoice->id)->count())->toBe(0)
        ->and(AuditLog::query()->where('action', RmeFinancialAuditEvent::ACTION_CORRECTION_APPROVED)->count())->toBe(0);
});

// ─── maker != checker != applier, per ACCOUNT ───────────────────────────────

it('refuses an approver who requested the correction even when one account holds both permissions', function () {
    $both = userWith(['request_rme_invoice_correction', 'approve_rme_invoice_correction', 'manage_rme_billing', 'view_clinic_visits']);
    // REVISED by rule 197 (owner D1e): only a designated maker may submit, so this
    // test's own account is designated. Self-decision is checked BEFORE identity
    // and keeps its own code.
    ricDesignateMaker($both);
    $invoice = ricInvoice();
    $correction = ricSubmit($invoice, $both);

    rpaDenied(fn () => ricApprove($correction, $both), RmeInvoiceCorrectionDenied::SELF_APPROVAL);

    expect($correction->fresh()->status)->toBe(RmeInvoiceCorrectionStatus::SUBMITTED)
        ->and((float) $invoice->fresh()->grand_total)->toEqual(500000.0);
});

it('refuses Super Admin approving their own correction', function () {
    $super = userInRole('Super Admin');
    // REVISED by rule 197 (owner D1e): only a designated maker may submit, so this
    // test's own account is designated. Self-decision is checked BEFORE identity
    // and keeps its own code.
    ricDesignateMaker($super);
    $invoice = ricInvoice();
    $correction = ricSubmit($invoice, $super);

    rpaDenied(fn () => ricApprove($correction, $super), RmeInvoiceCorrectionDenied::SELF_APPROVAL);
    expect($correction->fresh()->status)->toBe(RmeInvoiceCorrectionStatus::SUBMITTED);
});

it('refuses the maker applying their own legacy approved correction, Super Admin included', function () {
    $super = userInRole('Super Admin');
    // REVISED by rule 197 (owner D1e): only a designated maker may submit, so this
    // test's own account is designated. Self-decision is checked BEFORE identity
    // and keeps its own code.
    ricDesignateMaker($super);
    $invoice = ricInvoice();
    $correction = ricLegacyApproved(ricSubmit($invoice, $super), test()->checker);

    rpaDenied(fn () => ricApply($correction, $super), RmeInvoiceCorrectionDenied::SELF_APPLICATION);

    expect($correction->fresh()->status)->toBe(RmeInvoiceCorrectionStatus::APPROVED)
        ->and((int) $invoice->fresh()->correction_version)->toBe(0);
});

it('lets a different account complete a legacy approved correction through the recovery path', function () {
    $invoice = ricInvoice();
    $correction = ricLegacyApproved(ricSubmit($invoice), test()->checker);

    $applied = ricApply($correction);

    expect($applied->status)->toBe(RmeInvoiceCorrectionStatus::APPLIED)
        ->and((int) $applied->applied_by)->toBe((int) test()->applier->id);
    $audit = AuditLog::query()->where('action', RmeFinancialAuditEvent::ACTION_CORRECTION_APPLIED)->where('entity_id', $correction->id)->sole();
    expect($audit->new_values['application_mode'])->toBe(RmeInvoiceCorrectionService::APPLICATION_MANUAL_RECOVERY);
});

it('audits a self-application refusal after the rollback', function () {
    $super = userInRole('Super Admin');
    // REVISED by rule 197 (owner D1e): only a designated maker may submit, so this
    // test's own account is designated. Self-decision is checked BEFORE identity
    // and keeps its own code.
    ricDesignateMaker($super);
    $invoice = ricInvoice();
    $correction = ricLegacyApproved(ricSubmit($invoice, $super), test()->checker);

    rpaDenied(fn () => ricApply($correction, $super), RmeInvoiceCorrectionDenied::SELF_APPLICATION);

    $refusal = AuditLog::query()->where('action', RmeFinancialAuditEvent::ACTION_CORRECTION_REFUSED)->latest('id')->first();
    expect($refusal)->not->toBeNull()
        ->and($refusal->new_values['code'])->toBe(RmeInvoiceCorrectionDenied::SELF_APPLICATION)
        ->and($refusal->new_values['attempted_action'])->toBe('apply');
});

// ─── The pilot allowlist (rule 195) ─────────────────────────────────────────

it('ships a source-controlled allowlist that admits exactly SPN4 and reads no environment', function () {
    $file = file_get_contents(config_path('rme_invoice_correction.php'));
    $config = require config_path('rme_invoice_correction.php');

    expect($config['pilot']['branch_codes'])->toBe(['SPN4'])
        ->and($file)->not->toContain('env(');
});

it('refuses submit, approve and apply outside the pilot even with the flag ON', function () {
    $invoice = ricInvoice();
    $submitted = ricSubmit($invoice);
    $legacy = ricLegacyApproved(ricSubmit(ricInvoice(ricVisit())), test()->checker);

    ricPilot(['SPN4']); // the fixture branch IC01 is no longer admitted

    rpaDenied(fn () => ricSubmit(ricInvoice(ricVisit())), RmeInvoiceCorrectionDenied::BRANCH_NOT_IN_PILOT);
    rpaDenied(fn () => ricApprove($submitted), RmeInvoiceCorrectionDenied::BRANCH_NOT_IN_PILOT);
    rpaDenied(fn () => ricApply($legacy), RmeInvoiceCorrectionDenied::BRANCH_NOT_IN_PILOT);

    expect($submitted->fresh()->status)->toBe(RmeInvoiceCorrectionStatus::SUBMITTED)
        ->and((float) $invoice->fresh()->grand_total)->toEqual(500000.0)
        ->and($legacy->fresh()->status)->toBe(RmeInvoiceCorrectionStatus::APPROVED);

    $refusals = AuditLog::query()->where('action', RmeFinancialAuditEvent::ACTION_CORRECTION_REFUSED)->get()
        ->filter(fn ($a) => $a->new_values['code'] === RmeInvoiceCorrectionDenied::BRANCH_NOT_IN_PILOT);
    expect($refusals)->toHaveCount(3);
});

it('still lets an active correction outside the pilot be rejected or cancelled (rollback path)', function () {
    $toReject = ricSubmit(ricInvoice());
    $toCancel = ricSubmit(ricInvoice(ricVisit()));

    ricPilot([]);

    expect(rpaService()->reject($toReject, test()->checker, 'Pilot dipersempit, ditolak.')->status)->toBe(RmeInvoiceCorrectionStatus::REJECTED)
        ->and(rpaService()->cancel($toCancel, test()->maker, 'Pilot dipersempit, dibatalkan.')->status)->toBe(RmeInvoiceCorrectionStatus::CANCELLED);
});

it('admits nothing when the allowlist is empty, malformed or names only MAIN', function (mixed $value) {
    config()->set('rme_invoice_correction.pilot.branch_codes', $value);

    expect(app(RmeInvoiceCorrectionPilotScope::class)->admits((int) test()->branch->id))->toBeFalse()
        ->and(app(RmeInvoiceCorrectionPilotScope::class)->admittedBranchIds())->toBe([]);
})->with([
    'empty' => [[]],
    'null' => [null],
    'string not list' => ['IC01'],
    'MAIN only' => [['MAIN']],
    'prefix only' => [['IC0']],
    'non-string' => [[1, true]],
]);

it('canonicalizes a deprecated alias and matches whole codes only', function () {
    $sunu = Branch::factory()->create(['code' => 'SPN4', 'name' => 'Cabang Sunu', 'is_rme_enabled' => true, 'is_active' => true]);
    Branch::factory()->create(['code' => 'SPN4X', 'name' => 'Cabang Lain', 'is_rme_enabled' => true, 'is_active' => true]);

    ricPilot(['sun4']);

    $scope = app(RmeInvoiceCorrectionPilotScope::class);
    expect($scope->declaredCodes())->toBe(['SPN4'])
        ->and($scope->admittedBranchIds())->toBe([(int) $sunu->id]);
});

it('never admits a live branch coded with a historical alias (widening is a commit, not a master-data edit)', function () {
    $sunu = Branch::factory()->create(['code' => 'SPN4', 'name' => 'Cabang Sunu', 'is_rme_enabled' => true, 'is_active' => true]);
    $alias = Branch::factory()->create(['code' => 'SUN4', 'name' => 'Cabang Sunu Kedua', 'is_rme_enabled' => true, 'is_active' => true]);

    ricPilot(['SPN4']);

    $scope = app(RmeInvoiceCorrectionPilotScope::class);
    expect($scope->admittedBranchIds())->toBe([(int) $sunu->id])
        ->and($scope->admits((int) $alias->id))->toBeFalse();
});

it('admits no branch that is inactive or not RME-enabled even when listed', function () {
    test()->branch->update(['is_rme_enabled' => false]);
    expect(app(RmeInvoiceCorrectionPilotScope::class)->admits((int) test()->branch->id))->toBeFalse();

    test()->branch->update(['is_rme_enabled' => true, 'is_active' => false]);
    expect(app(RmeInvoiceCorrectionPilotScope::class)->admits((int) test()->branch->id))->toBeFalse();
});

it('offers the cashier correction panel only for a pilot branch (mutation M19)', function () {
    $invoice = ricInvoice();

    $inside = rpaService()->panelFor($invoice, test()->maker);
    expect($inside['pilot_admitted'])->toBeTrue()
        ->and($inside['can_request'])->toBeTrue()
        ->and($inside['eligible'])->toBeTrue();

    ricPilot(['SPN4']);
    $outside = rpaService()->panelFor($invoice, test()->maker);
    expect($outside['enabled'])->toBeTrue()
        ->and($outside['pilot_admitted'])->toBeFalse()
        ->and($outside['can_request'])->toBeFalse()
        ->and($outside['eligible'])->toBeFalse();
});

it('refuses everything while the flag is OFF even inside the pilot', function () {
    $correction = ricSubmit(ricInvoice());
    ricFlag(false);

    expect(fn () => ricApprove($correction))->toThrow(ValidationException::class)
        ->and(fn () => ricSubmit(ricInvoice(ricVisit())))->toThrow(ValidationException::class);
    expect($correction->fresh()->status)->toBe(RmeInvoiceCorrectionStatus::SUBMITTED);
});

// ─── HTTP — Front Office per the owner's decision ───────────────────────────

// REVISED by rule 197 (owner D1e): the pilot no longer runs between ANY two Front
// Office accounts (the literal D1b reading). The designated Front Office maker
// files; a second Front Office account of the same branch — holding exactly the
// same grants — is now REFUSED; the designated checker approves.
it('runs the whole pilot flow with the designated Front Office maker and the designated checker, refusing a second Front Office account', function () {
    test()->withoutMiddleware(EnsureRmeOnlineContext::class);

    $maker = User::factory()->create();
    $secondFrontOffice = User::factory()->create();
    rmeMakeFrontOfficeActive($maker, test()->branch);
    rmeMakeFrontOfficeActive($secondFrontOffice, test()->branch);
    $checker = userInRole('Super Admin');
    ricPilotIdentities([(int) $maker->id], [(int) $checker->id]);

    $visit = ricVisit();
    $invoice = ricInvoice($visit);

    test()->actingAs($maker)
        ->get(route('rme.invoice-corrections.create', [$visit, $invoice]))
        ->assertOk();

    test()->actingAs($maker)
        ->post(route('rme.invoice-corrections.store', [$visit, $invoice]), [
            'reason' => 'Harga tambal salah input kasir.',
            'lines' => ricLinesReducing($invoice),
            'idempotency_key' => 'front-office-pilot-1',
        ])->assertRedirect();
    $correction = RmeInvoiceCorrection::query()->where('rme_invoice_id', $invoice->id)->sole();

    // The maker holds approve too, but can never decide their own request.
    test()->actingAs($maker)
        ->post(route('rme.invoice-corrections.approve', $correction->id))
        ->assertForbidden();
    // A second Front Office account holds the same grants and is not designated.
    test()->actingAs($secondFrontOffice)
        ->post(route('rme.invoice-corrections.approve', $correction->id))
        ->assertForbidden();
    expect($correction->fresh()->status)->toBe(RmeInvoiceCorrectionStatus::SUBMITTED);

    test()->actingAs($checker)
        ->post(route('rme.invoice-corrections.approve', $correction->id))
        ->assertRedirect()
        ->assertSessionHas('success', fn ($m) => str_contains($m, 'langsung diterapkan'));

    expect($correction->fresh()->status)->toBe(RmeInvoiceCorrectionStatus::APPLIED)
        ->and((float) $invoice->fresh()->grand_total)->toEqual(350000.0);

    // Front Office holds no separate apply permission (D1a).
    expect($secondFrontOffice->can(RmeInvoiceCorrectionPermission::APPLY))->toBeFalse();
});

it('keeps another branch Front Office account out of the pilot branch correction', function () {
    test()->withoutMiddleware(EnsureRmeOnlineContext::class);

    $other = Branch::factory()->create(['code' => 'IC02', 'name' => 'Cabang Lain', 'is_rme_enabled' => true, 'is_active' => true]);
    $outsider = User::factory()->create();
    rmeMakeFrontOfficeActive($outsider, $other);

    $correction = ricSubmit(ricInvoice());

    test()->actingAs($outsider)->get(route('rme.invoice-corrections.show', $correction->id))->assertNotFound();
    test()->actingAs($outsider)->post(route('rme.invoice-corrections.approve', $correction->id))->assertNotFound();
    expect($correction->fresh()->status)->toBe(RmeInvoiceCorrectionStatus::SUBMITTED);
});

it('answers 404 for the create form and the store outside the pilot, and 403 for approve', function () {
    test()->withoutMiddleware(EnsureRmeOnlineContext::class);
    $visit = ricVisit();
    $invoice = ricInvoice($visit);
    $correction = ricSubmit($invoice);

    ricPilot([]);

    test()->actingAs(test()->maker)->get(route('rme.invoice-corrections.create', [$visit, $invoice]))->assertNotFound();
    test()->actingAs(test()->maker)->post(route('rme.invoice-corrections.store', [$visit, $invoice]), [
        'reason' => 'Harga tambal salah input kasir.', 'lines' => ricLinesReducing($invoice),
    ])->assertNotFound();
    test()->actingAs(test()->checker)->post(route('rme.invoice-corrections.approve', $correction->id))->assertForbidden();

    test()->actingAs(test()->checker)->get(route('rme.invoice-corrections.show', $correction->id))
        ->assertOk()
        ->assertSee('data-correction-outside-pilot', false)
        ->assertDontSee('data-correction-approve-applies', false);
});

it('shows the approve-and-apply action inside the pilot', function () {
    test()->withoutMiddleware(EnsureRmeOnlineContext::class);
    $correction = ricSubmit(ricInvoice());

    test()->actingAs(test()->checker)->get(route('rme.invoice-corrections.show', $correction->id))
        ->assertOk()
        ->assertSee('data-correction-approve-applies', false)
        ->assertSee('Setujui &amp; Terapkan', false);
});

// ─── RoleSeeder — D1 + the Front Office decision ────────────────────────────

it('grants each role exactly the correction permissions of rule 195', function () {
    $expected = RmeInvoiceCorrectionReadinessService::EXPECTED_ROLE_GRANTS;

    foreach (Role::query()->with('permissions')->get() as $role) {
        $held = $role->permissions->pluck('name')->intersect(RmeInvoiceCorrectionPermission::OPERATIONAL)->sort()->values()->all();
        $want = $expected[$role->name] ?? [];
        sort($want);
        expect($held)->toBe($want, "Role {$role->name}");
    }

    expect($expected['Kasir'])->toBe([RmeInvoiceCorrectionPermission::REQUEST])
        ->and($expected['Admin Klinik'])->toBe([RmeInvoiceCorrectionPermission::APPROVE])
        ->and($expected['Front Office'])->not->toContain(RmeInvoiceCorrectionPermission::APPLY);
});

it('keeps the oversight and laboratory roles free of every correction mutation permission', function () {
    foreach (['Finance Klinik', 'Data Analyst', 'Finance', 'Supervisor RME', 'Owner', 'Doctor', 'Perawat', 'Admin Lab'] as $name) {
        $role = Role::findByName($name);
        foreach (RmeInvoiceCorrectionPermission::OPERATIONAL as $permission) {
            expect($role->hasPermissionTo($permission))->toBeFalse("{$name} must not hold {$permission}");
        }
    }
});

it('is idempotent: re-running RoleSeeder changes no correction grant', function () {
    $before = DB::table('role_has_permissions')->orderBy('role_id')->orderBy('permission_id')->get()->map(fn ($r) => $r->role_id.':'.$r->permission_id)->all();

    test()->seed(RoleSeeder::class);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $after = DB::table('role_has_permissions')->orderBy('role_id')->orderBy('permission_id')->get()->map(fn ($r) => $r->role_id.':'.$r->permission_id)->all();
    expect($after)->toBe($before);
});

// ─── Presentation never rounds a stored amount ──────────────────────────────

it('prints a non-whole amount with its cents instead of rounding it', function () {
    expect(RmeInvoiceDocument::rupiah(17500050))->toBe('Rp 175.000,50')
        ->and(RmeInvoiceDocument::rupiah(17500005))->toBe('Rp 175.000,05')
        ->and(RmeInvoiceDocument::rupiah(122500000))->toBe('Rp 1.225.000')
        ->and(RmeInvoiceDocument::rupiah(-15000050))->toBe('- Rp 150.000,50')
        ->and(RmeInvoiceDocument::rupiahDecimal('175000.50'))->toBe('Rp 175.000,50')
        ->and(OversightFormat::rupiahCents(17500050))->toBe('Rp 175.000,50')
        ->and(OversightFormat::rupiahCents(-15000000))->toBe('Rp -150.000')
        ->and(OversightFormat::rupiah('175000.50'))->toBe('Rp 175.000,50');
});

it('shows the exact stored cents on the correction page', function () {
    test()->withoutMiddleware(EnsureRmeOnlineContext::class);
    $invoice = ricInvoice();
    $correction = ricSubmit($invoice, null, ricLinesReducing($invoice, '50000.50'));

    test()->actingAs(test()->checker)->get(route('rme.invoice-corrections.show', $correction->id))
        ->assertOk()
        ->assertSee('Rp 350.000,50')
        ->assertDontSee('Rp 350.001');
});

// ─── Provenance and quantity residuals ──────────────────────────────────────

it('keeps a line price provenance when the correction does not change its price', function () {
    $invoice = ricInvoice();
    $items = $invoice->items()->orderBy('id')->get();
    $originalSource = $items[0]->price_source;

    $lines = ricLinesReducing($invoice);
    $lines[0]['qty'] = 2; // Scaling: same price, quantity changed
    ricApprove(ricSubmit($invoice, null, $lines));

    $scaling = $items[0]->fresh();
    $tambal = $items[1]->fresh();
    expect((int) $scaling->qty)->toBe(2)
        ->and($scaling->price_source)->toBe($originalSource)
        ->and($tambal->price_source)->toBe(RmeInvoicePriceSource::CORRECTION_MANUAL_ENTRY);
});

it('re-labels provenance when the treatment changes even at the same price', function () {
    $invoice = ricInvoice();
    $items = $invoice->items()->orderBy('id')->get();

    $lines = ricLinesReducing($invoice);
    $lines[0]['treatment_id'] = test()->treatment2->id; // Scaling line → Tambal treatment, price unchanged
    ricApprove(ricSubmit($invoice, null, $lines));

    $changed = $items[0]->fresh();
    expect((int) $changed->treatment_id)->toBe((int) test()->treatment2->id)
        ->and((float) $changed->unit_price)->toEqual(300000.0)
        ->and($changed->price_source)->toBe(RmeInvoicePriceSource::CORRECTION_MANUAL_ENTRY);
});

it('uses one quantity bound for invoices and corrections', function () {
    expect(RmeInvoiceCorrectionProposalBuilder::MAX_QTY)
        ->toBe(RmeInvoicePricingService::MAX_QTY);

    $invoice = ricInvoice();
    $lines = ricLinesReducing($invoice);
    $lines[1]['qty'] = 20000;
    $lines[1]['unit_price'] = '50';

    expect(ricApprove(ricSubmit($invoice, null, $lines))->status)->toBe(RmeInvoiceCorrectionStatus::APPLIED);
    expect((int) $invoice->fresh()->items()->orderBy('id')->get()[1]->qty)->toBe(20000);
});

it('still refuses a correction that would create a new Rp0 line (D21g pending)', function () {
    $invoice = ricInvoice();

    expect(fn () => ricSubmit($invoice, null, ricLinesReducing($invoice, '0')))
        ->toThrow(ValidationException::class);
});

it('keeps only the PRE_PAYMENT correction type enabled', function () {
    expect(RmeInvoiceCorrectionType::ENABLED)->toBe([RmeInvoiceCorrectionType::PRE_PAYMENT]);
});

// ─── The read-only production authorization probe ───────────────────────────

it('reports a valid maker/checker pair inside the pilot and writes nothing', function () {
    $maker = User::factory()->create();
    rmeMakeFrontOfficeActive($maker, test()->branch);
    $checker = userInRole('Super Admin');
    // REVISED by rule 197 (owner D1e): a VALID pair must be the designated accounts.
    ricPilotIdentities([(int) $maker->id], [(int) $checker->id]);

    $writes = 0;
    DB::listen(function ($query) use (&$writes) {
        if (preg_match('/^\s*(insert|update|delete)\b/i', $query->sql) === 1) {
            $writes++;
        }
    });

    $report = app(RmeInvoiceCorrectionReadinessService::class)->report([], (int) $maker->id, (int) $checker->id);

    expect($writes)->toBe(0)
        ->and($report['state'])->toBe(RmeInvoiceCorrectionReadinessService::STATE_PILOT_ACTIVE)
        ->and($report['pilot']['admitted_branch_codes'])->toBe(['IC01'])
        ->and($report['maker_checker_pair']['valid'])->toBeTrue()
        ->and($report['maker_checker_pair']['shared_pilot_branches'])->toBe(['IC01'])
        ->and($report['decision'])->toBe(RmeInvoiceCorrectionReadinessService::GO);
});

it('leaves a STALE operator online context untouched (the probe never persists a lazy expiry)', function () {
    $maker = User::factory()->create();
    rmeMakeFrontOfficeActive($maker, test()->branch);

    // Age the context past the inactivity window: resolving the operator's scope
    // now reaches the lazy markExpiredInactive() write (rule 165 item 6a).
    DB::table('trx_user_online_contexts')->where('user_id', $maker->id)->update(['last_seen_at' => now()->subDays(2)]);
    $before = DB::table('trx_user_online_contexts')->where('user_id', $maker->id)->get()->map(fn ($r) => (array) $r)->all();

    app(RmeInvoiceCorrectionReadinessService::class)->report([(int) $maker->id]);

    expect(DB::table('trx_user_online_contexts')->where('user_id', $maker->id)->get()->map(fn ($r) => (array) $r)->all())->toBe($before);
});

it('fails the probe for one account posing as both maker and checker', function () {
    $both = userInRole('Super Admin');

    $report = app(RmeInvoiceCorrectionReadinessService::class)->report([], (int) $both->id, (int) $both->id);

    expect($report['maker_checker_pair']['valid'])->toBeFalse()
        ->and(collect($report['findings'])->pluck('code'))->toContain('PAIR_NOT_DISTINCT_ACCOUNTS')
        ->and($report['decision'])->toBe(RmeInvoiceCorrectionReadinessService::FAIL);
});

it('fails the probe when a role holds a correction permission rule 195 does not grant', function () {
    Role::findByName('Finance Klinik')->givePermissionTo(RmeInvoiceCorrectionPermission::APPROVE);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $report = app(RmeInvoiceCorrectionReadinessService::class)->report();

    expect(collect($report['findings'])->pluck('code'))->toContain('ROLE_GRANT_DRIFT')
        ->and($report['decision'])->toBe(RmeInvoiceCorrectionReadinessService::FAIL);
});

it('reports DORMANT while the flag is off and the command exits zero', function () {
    ricFlag(false);

    $report = app(RmeInvoiceCorrectionReadinessService::class)->report();
    expect($report['state'])->toBe(RmeInvoiceCorrectionReadinessService::STATE_DORMANT);

    expect(Artisan::call('rme:invoice-correction-readiness', ['--json' => true]))->toBe(0);
});

it('reports an active correction outside the pilot as WATCH', function () {
    ricSubmit(ricInvoice());
    ricPilot([]);

    $report = app(RmeInvoiceCorrectionReadinessService::class)->report();
    expect(collect($report['findings'])->pluck('code'))->toContain('ACTIVE_CORRECTION_OUTSIDE_PILOT')
        ->and($report['active_corrections_by_branch'])->toBe(['IC01' => 1]);
});

/*
| OFF-SHIFT vs UNAUTHORIZED (found on production 2026-10-21). A context-bound
| front-desk account has an EMPTY working scope whenever it is not logged in, so
| the first probe reported the owner-designated maker (user 29, pinned to SPN4)
| as "cannot request in the pilot" — a false FAIL that names an authorization
| defect where there is only an operator who is not on shift. The probe now
| separates the two: eligibility decides FAIL, being on shift decides WATCH.
*/

/** A real production code: the Front Office pin policy accepts only the four real codes. */
function ricRealBranch(string $code): Branch
{
    return Branch::query()->firstOrCreate(['code' => $code], [
        'name' => 'Cabang '.$code,
        'is_active' => true,
        'is_rme_enabled' => true,
    ]);
}

/** Arm the branch-context pin (never the device lock) for one account. */
function ricPinFrontOffice(User $user, string $code): void
{
    $flags = config('feature_flags.flags', []);
    $flags['front_office.branch_context_lock']['default'] = true;
    $flags['front_office.branch_context_lock']['env_value'] = null;
    config()->set('feature_flags.flags', $flags);
    config()->set('front_office_device_lock.scope.cohort', $user->id.':'.$code);
}

function ricOffShiftFrontOffice(): User
{
    return User::factory()->create()->assignRole('Front Office');
}

it('reports an off-shift maker pinned to the pilot branch as WATCH, never as an unauthorized FAIL', function () {
    ricRealBranch('SPN4');
    ricPilot(['SPN4']);
    $maker = ricOffShiftFrontOffice();
    ricPinFrontOffice($maker, 'SPN4');
    $checker = userInRole('Super Admin');
    // REVISED by rule 197 (owner D1e): a VALID pair must be the designated accounts.
    ricPilotIdentities([(int) $maker->id], [(int) $checker->id]);

    $report = app(RmeInvoiceCorrectionReadinessService::class)->report([], (int) $maker->id, (int) $checker->id);
    $codes = collect($report['findings'])->pluck('code');
    $makerRow = $report['users'][0];

    expect($makerRow['may_request_in_pilot'])->toBeFalse()
        ->and($makerRow['on_shift_in_pilot'])->toBeFalse()
        ->and($makerRow['eligible_pilot_branches'])->toBe(['SPN4'])
        ->and($makerRow['may_request_when_on_shift'])->toBeTrue()
        ->and($report['maker_checker_pair']['valid'])->toBeTrue()
        ->and($report['maker_checker_pair']['live_now'])->toBeFalse()
        ->and($report['maker_checker_pair']['shared_pilot_branches'])->toBe(['SPN4'])
        ->and($codes)->toContain('PAIR_MAKER_NOT_ON_SHIFT_IN_PILOT')
        ->and($codes)->not->toContain('PAIR_MAKER_CANNOT_REQUEST_IN_PILOT')
        ->and($report['decision'])->toBe(RmeInvoiceCorrectionReadinessService::WATCH);
});

it('still FAILs an off-shift maker pinned to a branch outside the pilot', function () {
    ricRealBranch('SPN4');
    ricRealBranch('LDK2');
    ricPilot(['SPN4']);
    $maker = ricOffShiftFrontOffice();
    ricPinFrontOffice($maker, 'LDK2');

    $report = app(RmeInvoiceCorrectionReadinessService::class)->report([], (int) $maker->id, (int) userInRole('Super Admin')->id);

    expect($report['users'][0]['eligible_pilot_branches'])->toBe([])
        ->and($report['maker_checker_pair']['valid'])->toBeFalse()
        ->and(collect($report['findings'])->pluck('code'))->toContain('PAIR_MAKER_CANNOT_REQUEST_IN_PILOT')
        ->and($report['decision'])->toBe(RmeInvoiceCorrectionReadinessService::FAIL);
});

it('still FAILs an armed maker whose pin cannot be decided (no such live branch)', function () {
    ricRealBranch('SPN4');
    ricPilot(['SPN4']);
    $maker = ricOffShiftFrontOffice();
    // ATG3 is an allowed policy code, but no ATG3 branch exists here: undecidable.
    ricPinFrontOffice($maker, 'ATG3');

    $report = app(RmeInvoiceCorrectionReadinessService::class)->report([], (int) $maker->id, (int) userInRole('Super Admin')->id);

    expect($report['users'][0]['eligible_pilot_branches'])->toBe([])
        ->and(collect($report['findings'])->pluck('code'))->toContain('PAIR_MAKER_CANNOT_REQUEST_IN_PILOT')
        ->and($report['decision'])->toBe(RmeInvoiceCorrectionReadinessService::FAIL);
});

it('treats an off-shift UNPINNED front-desk maker as able to select a pilot branch at login (WATCH)', function () {
    $maker = ricOffShiftFrontOffice();
    $checker = userInRole('Super Admin');
    // REVISED by rule 197 (owner D1e): a VALID pair must be the designated accounts.
    ricPilotIdentities([(int) $maker->id], [(int) $checker->id]);

    $report = app(RmeInvoiceCorrectionReadinessService::class)->report([], (int) $maker->id, (int) $checker->id);

    expect($report['users'][0]['eligible_pilot_branches'])->toBe(['IC01'])
        ->and($report['maker_checker_pair']['valid'])->toBeTrue()
        ->and(collect($report['findings'])->pluck('code'))->toContain('PAIR_MAKER_NOT_ON_SHIFT_IN_PILOT')
        ->and($report['decision'])->toBe(RmeInvoiceCorrectionReadinessService::WATCH);
});

it('still FAILs an off-shift maker whose role holds no request permission', function () {
    $maker = User::factory()->create()->assignRole('Admin Klinik');

    $report = app(RmeInvoiceCorrectionReadinessService::class)->report([], (int) $maker->id, (int) userInRole('Super Admin')->id);

    expect($report['users'][0]['may_request_when_on_shift'])->toBeFalse()
        ->and(collect($report['findings'])->pluck('code'))->toContain('PAIR_MAKER_CANNOT_REQUEST_IN_PILOT')
        ->and($report['decision'])->toBe(RmeInvoiceCorrectionReadinessService::FAIL);
});

it('reports the pair LIVE and GO only when both accounts are on shift in a shared pilot branch', function () {
    $maker = User::factory()->create();
    rmeMakeFrontOfficeActive($maker, test()->branch);
    $checker = userInRole('Super Admin');
    // REVISED by rule 197 (owner D1e): a VALID pair must be the designated accounts.
    ricPilotIdentities([(int) $maker->id], [(int) $checker->id]);

    $report = app(RmeInvoiceCorrectionReadinessService::class)->report([], (int) $maker->id, (int) $checker->id);

    expect($report['users'][0]['on_shift_in_pilot'])->toBeTrue()
        ->and($report['maker_checker_pair']['live_now'])->toBeTrue()
        ->and(collect($report['findings'])->pluck('code'))->not->toContain('PAIR_MAKER_NOT_ON_SHIFT_IN_PILOT')
        ->and($report['decision'])->toBe(RmeInvoiceCorrectionReadinessService::GO);
});

it('keeps the off-shift probe read-only for a pinned account', function () {
    ricRealBranch('SPN4');
    ricPilot(['SPN4']);
    $maker = ricOffShiftFrontOffice();
    ricPinFrontOffice($maker, 'SPN4');
    $checker = userInRole('Super Admin');

    $writes = 0;
    DB::listen(function ($query) use (&$writes) {
        if (preg_match('/^\s*(insert|update|delete)\b/i', $query->sql) === 1) {
            $writes++;
        }
    });

    app(RmeInvoiceCorrectionReadinessService::class)->report([], (int) $maker->id, (int) $checker->id);

    expect($writes)->toBe(0);
});

it('fails closed when a governance account working scope cannot be resolved', function () {
    $maker = userInRole('Super Admin');
    $checker = userInRole('Super Admin');

    // A governance account's eligibility IS its live scope; when that scope cannot
    // be resolved the probe must admit nothing, never fall back to the allowlist.
    app()->instance(RmeWorkingBranchScope::class, new class(app(UserOnlineContextService::class), app(BranchService::class), app(DoctorEffectiveBranchResolver::class)) extends RmeWorkingBranchScope
    {
        public function branchIdsFor(?User $user): array
        {
            throw new RuntimeException('scope unavailable');
        }
    });

    $report = app(RmeInvoiceCorrectionReadinessService::class)->report([], (int) $maker->id, (int) $checker->id);

    expect($report['users'][0]['eligible_pilot_branches'])->toBe([])
        ->and($report['maker_checker_pair']['valid'])->toBeFalse()
        ->and(collect($report['findings'])->pluck('code'))->toContain('PAIR_MAKER_CANNOT_REQUEST_IN_PILOT')
        ->and($report['decision'])->toBe(RmeInvoiceCorrectionReadinessService::FAIL);
});
