<?php

use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Consent\Models\RmeVisitConsent;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use App\Modules\RmeInvoice\Models\RmePayment;
use App\Modules\RmeInvoice\Services\RmeInvoiceService;
use App\Modules\Treatment\Models\Treatment;
use Database\Seeders\BranchSeeder;
use Illuminate\Support\Facades\Storage;

/**
 * FIX-RME-CONSENT-WORKFLOW-PRINT-UX-2 / FIX-01 — the HTTP boundary.
 *
 * RmeVisitConsentGateTest proves the service-level rules. This file proves the
 * same rules survive contact with a real request: a crafted POST cannot pay
 * without a signed consent, and consent itself is permission-gated and
 * branch-scoped rather than merely hidden in the UI.
 */
beforeEach(function () {
    test()->seed(BranchSeeder::class);
    seedAccessControl();
    Storage::fake('local');

    $this->branch = Branch::factory()->create([
        'code' => 'CHT1',
        'is_active' => true,
        'is_rme_enabled' => true,
    ]);

    /*
     * The consent routes live inside the RME route group, which already requires
     * permission:view_clinic_visits|manage_clinic_visits. Every role that signs
     * consent in production (Kasir, Admin Klinik, Doctor, Supervisor RME) holds
     * view_clinic_visits, so the fixture mirrors that rather than inventing an
     * operator shape that cannot exist.
     */
    $this->cashier = userWith([
        'view_clinic_visits',
        'manage_rme_billing',
        'manage_rme_consents',
        'view_rme_consents',
    ]);
});

function consentHttpVisit(?Branch $branch = null, string $status = ClinicVisit::STATUS_CASHIER_PENDING): ClinicVisit
{
    $branch ??= test()->branch;

    $visit = ClinicVisit::factory()->create([
        'branch_id' => $branch->id,
        'status' => $status,
    ]);

    MedicalRecord::factory()->final()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $branch->id,
        'patient_id' => $visit->patient_id,
        'doctor_id' => $visit->doctor_id,
    ]);

    return $visit->refresh();
}

function consentHttpInvoice(ClinicVisit $visit, float $amount = 150000): RmeInvoice
{
    $treatment = Treatment::factory()->create(['is_active' => true]);

    return app(RmeInvoiceService::class)->create($visit, test()->cashier, [
        'items' => [[
            'treatment_id' => $treatment->id,
            'description' => 'Tindakan',
            'qty' => 1,
            'unit_price' => $amount,
        ]],
    ]);
}

function consentHttpFormPayload(array $overrides = []): array
{
    return array_merge([
        'template_code' => 'PERSETUJUAN_TINDAKAN_MEDIS',
        'consenter_relationship' => 'self',
        'medical_action' => 'Pencabutan gigi 36',
        'treatment_summary' => 'Pencabutan gigi',
        'documentation_consent' => '0',
        'consenter_signature' => validPodSignatureData(),
    ], $overrides);
}

/*
|--------------------------------------------------------------------------
| A — the payment endpoint cannot be talked into paying
|--------------------------------------------------------------------------
*/

it('accepts a payment POST regardless of any consent claim it carries', function () {
    $visit = consentHttpVisit();
    $invoice = consentHttpInvoice($visit);

    $this->actingAs($this->cashier)
        ->from(route('rme.cashier.payment.create', [$visit, $invoice]))
        ->post(route('rme.cashier.payment.store', [$visit, $invoice]), [
            'amount' => 150000,
            'paid_at' => now()->toDateTimeString(),
            // The exact payload that used to settle the invoice outright.
            'consent_signed_by_patient' => 1,
            'consent_signed_by_doctor' => 1,
        ])
        ->assertSessionHasNoErrors();

    expect(RmePayment::count())->toBe(1);
    expect($invoice->refresh()->status)->toBe(RmeInvoice::STATUS_PAID);
    // PRESERVED: the request still cannot write the attestation columns.
    expect($visit->refresh()->consent_signed_by_patient)->toBeFalse();
});

it('accepts a payment POST that mentions consent not at all', function () {
    $visit = consentHttpVisit();
    $invoice = consentHttpInvoice($visit);

    $this->actingAs($this->cashier)
        ->from(route('rme.cashier.payment.create', [$visit, $invoice]))
        ->post(route('rme.cashier.payment.store', [$visit, $invoice]), [
            'amount' => 150000,
            'paid_at' => now()->toDateTimeString(),
        ])
        ->assertSessionHasNoErrors();

    // SUPERSEDED by FIX-03 — consent is not a payment condition. Inverted rather
    // than deleted so a reintroduced payment consent gate fails here.
    expect(RmePayment::count())->toBe(1);
});

it('accepts the payment once the consent has been signed over HTTP', function () {
    $visit = consentHttpVisit();
    $invoice = consentHttpInvoice($visit);

    $this->actingAs($this->cashier)
        ->post(route('rme.visits.consent.store', $visit), consentHttpFormPayload())
        ->assertRedirect();

    expect(RmeVisitConsent::count())->toBe(1);

    $this->actingAs($this->cashier)
        ->post(route('rme.cashier.payment.store', [$visit, $invoice]), [
            'amount' => 150000,
            'paid_at' => now()->toDateTimeString(),
        ])
        ->assertRedirect();

    expect($invoice->refresh()->status)->toBe(RmeInvoice::STATUS_PAID);
});

/*
|--------------------------------------------------------------------------
| B — consent signing is authorised, not merely hidden
|--------------------------------------------------------------------------
*/

it('denies the consent form to a user without the consent permission', function () {
    $visit = consentHttpVisit();
    // Holds the RME group permission, so this genuinely exercises the consent
    // gate rather than bouncing off the route group.
    $outsider = userWith(['view_clinic_visits', 'manage_rme_billing']);

    $this->actingAs($outsider)
        ->get(route('rme.visits.consent.create', $visit))
        ->assertForbidden();
});

it('denies a crafted consent POST from a user without the consent permission', function () {
    $visit = consentHttpVisit();
    $outsider = userWith(['view_clinic_visits', 'manage_rme_billing']);

    $this->actingAs($outsider)
        ->post(route('rme.visits.consent.store', $visit), consentHttpFormPayload())
        ->assertForbidden();

    expect(RmeVisitConsent::count())->toBe(0);
});

it('opens the consent form as soon as the doctor has started the examination', function () {
    // SUPERSEDED by FIX-02 — consent is taken at the START of the examination.
    $visit = consentHttpVisit(status: ClinicVisit::STATUS_IN_PROGRESS);

    $this->actingAs($this->cashier)
        ->get(route('rme.visits.consent.create', $visit))
        ->assertOk();
});

it('accepts a consent POST for a live visit, and still refuses one on a terminal visit', function () {
    // SUPERSEDED by FIX-02. The timing rule moved; what stays is that a FINISHED
    // visit can never be back-dated a consent.
    $live = consentHttpVisit(status: ClinicVisit::STATUS_IN_PROGRESS);

    $this->actingAs($this->cashier)
        ->from(route('rme.visits.show', $live))
        ->post(route('rme.visits.consent.store', $live), consentHttpFormPayload())
        ->assertSessionHasNoErrors();

    expect(RmeVisitConsent::count())->toBe(1);

    $finished = consentHttpVisit(status: ClinicVisit::STATUS_COMPLETED);

    $this->actingAs($this->cashier)
        ->from(route('rme.visits.show', $finished))
        ->post(route('rme.visits.consent.store', $finished), consentHttpFormPayload())
        ->assertSessionHasErrors('clinic_visit_id');

    expect(RmeVisitConsent::count())->toBe(1);
});

it('requires the documentation answer to be present in the request', function () {
    $visit = consentHttpVisit();

    $payload = consentHttpFormPayload();
    unset($payload['documentation_consent']);

    $this->actingAs($this->cashier)
        ->from(route('rme.visits.consent.create', $visit))
        ->post(route('rme.visits.consent.store', $visit), $payload)
        ->assertSessionHasErrors('documentation_consent');

    expect(RmeVisitConsent::count())->toBe(0);
});

it('accepts TIDAK as a valid documentation answer over HTTP', function () {
    // "0" must survive validation — a `required` rule would have rejected it.
    $visit = consentHttpVisit();

    $this->actingAs($this->cashier)
        ->post(route('rme.visits.consent.store', $visit), consentHttpFormPayload(['documentation_consent' => '0']))
        ->assertRedirect();

    expect(RmeVisitConsent::first()->documentation_consent)->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| C — reading a signed consent is branch-scoped
|--------------------------------------------------------------------------
*/

it('lets an authorised user read and print a signed consent', function () {
    $visit = consentHttpVisit();

    $this->actingAs($this->cashier)
        ->post(route('rme.visits.consent.store', $visit), consentHttpFormPayload())
        ->assertRedirect();

    $consent = RmeVisitConsent::firstOrFail();

    $this->actingAs($this->cashier)
        ->get(route('rme.consents.show', $consent))
        ->assertOk()
        ->assertSee('PERSETUJUAN TINDAKAN MEDIS');

    $this->actingAs($this->cashier)
        ->get(route('rme.consents.print', $consent))
        ->assertOk()
        ->assertSee('PERSETUJUAN TINDAKAN MEDIS');
});

it('renders all eight consent clauses and the declaration on the printed document', function () {
    $visit = consentHttpVisit();

    $this->actingAs($this->cashier)
        ->post(route('rme.visits.consent.store', $visit), consentHttpFormPayload())
        ->assertRedirect();

    $consent = RmeVisitConsent::firstOrFail();
    $clauses = config('rme_consent.templates.PERSETUJUAN_TINDAKAN_MEDIS.clauses');

    $response = $this->actingAs($this->cashier)->get(route('rme.consents.print', $consent))->assertOk();

    foreach ($clauses as $clause) {
        $response->assertSee($clause, false);
    }

    $response->assertSee(config('rme_consent.templates.PERSETUJUAN_TINDAKAN_MEDIS.declaration'), false);
    $response->assertSee('No. Dental Record');
    $response->assertSee('Yang membuat persetujuan');
    $response->assertSee('Dokter');
});

it('denies reading a consent that belongs to another branch', function () {
    $otherBranch = Branch::factory()->create([
        'code' => 'CHT9',
        'is_active' => true,
        'is_rme_enabled' => true,
    ]);

    $otherVisit = consentHttpVisit($otherBranch);

    $this->actingAs($this->cashier)
        ->post(route('rme.visits.consent.store', $otherVisit), consentHttpFormPayload())
        ->assertRedirect();

    $consent = RmeVisitConsent::firstOrFail();

    // A cashier pinned to a different working branch cannot read it.
    $pinned = userWith(['manage_rme_billing', 'view_rme_consents']);
    $pinned->forceFill(['branch_id' => $this->branch->id])->save();
    rmeMakeKasirActive($pinned, $this->branch);

    $this->actingAs($pinned)
        ->get(route('rme.consents.show', $consent))
        ->assertForbidden();
});

it('denies reading a consent without the consent view permission', function () {
    $visit = consentHttpVisit();

    $this->actingAs($this->cashier)
        ->post(route('rme.visits.consent.store', $visit), consentHttpFormPayload())
        ->assertRedirect();

    $consent = RmeVisitConsent::firstOrFail();
    $outsider = userWith(['view_clinic_visits', 'manage_rme_billing']);

    $this->actingAs($outsider)
        ->get(route('rme.consents.show', $consent))
        ->assertForbidden();
});

/*
|--------------------------------------------------------------------------
| D — private signature files
|--------------------------------------------------------------------------
*/

it('streams a signature only to an authorised reader', function () {
    $visit = consentHttpVisit();

    $this->actingAs($this->cashier)
        ->post(route('rme.visits.consent.store', $visit), consentHttpFormPayload())
        ->assertRedirect();

    $consent = RmeVisitConsent::firstOrFail();

    $this->actingAs($this->cashier)
        ->get(route('rme.consents.signature', [$consent, 'consenter']))
        ->assertOk();

    $outsider = userWith(['view_clinic_visits', 'manage_rme_billing']);

    $this->actingAs($outsider)
        ->get(route('rme.consents.signature', [$consent, 'consenter']))
        ->assertForbidden();
});

it('returns 404 for an unknown signature kind rather than guessing', function () {
    $visit = consentHttpVisit();

    $this->actingAs($this->cashier)
        ->post(route('rme.visits.consent.store', $visit), consentHttpFormPayload())
        ->assertRedirect();

    $consent = RmeVisitConsent::firstOrFail();

    $this->actingAs($this->cashier)
        ->get(route('rme.consents.signature', [$consent, 'anything-else']))
        ->assertNotFound();
});

it('returns 404 for a doctor signature that was never captured', function () {
    $visit = consentHttpVisit();

    $this->actingAs($this->cashier)
        ->post(route('rme.visits.consent.store', $visit), consentHttpFormPayload())
        ->assertRedirect();

    $consent = RmeVisitConsent::firstOrFail();

    expect($consent->doctor_signature_path)->toBeNull();

    $this->actingAs($this->cashier)
        ->get(route('rme.consents.signature', [$consent, 'doctor']))
        ->assertNotFound();
});

/*
|--------------------------------------------------------------------------
| E — the Kasir trap
|--------------------------------------------------------------------------
*/

it('lets a real Kasir complete the consent-then-pay workflow', function () {
    /*
     * Kasir holds view_clinic_visits but NOT manage_clinic_visits. If consent had
     * been hung off a clinic-visit management permission, the one role that must
     * complete every payment would have been 403'd out of its own workflow.
     * This test exists to keep that mistake from being reintroduced.
     */
    $visit = consentHttpVisit();
    $invoice = consentHttpInvoice($visit);

    $kasir = userInRole('Kasir');
    $kasir->forceFill(['branch_id' => $this->branch->id])->save();
    rmeMakeKasirActive($kasir, $this->branch);

    expect($kasir->can('manage_clinic_visits'))->toBeFalse();
    expect($kasir->can('manage_rme_consents'))->toBeTrue();

    $this->actingAs($kasir)
        ->get(route('rme.visits.consent.create', $visit))
        ->assertOk();

    $this->actingAs($kasir)
        ->post(route('rme.visits.consent.store', $visit), consentHttpFormPayload())
        ->assertRedirect();

    $this->actingAs($kasir)
        ->post(route('rme.cashier.payment.store', [$visit, $invoice]), [
            'amount' => 150000,
            'paid_at' => now()->toDateTimeString(),
        ])
        ->assertRedirect();

    expect($invoice->refresh()->status)->toBe(RmeInvoice::STATUS_PAID);
});
