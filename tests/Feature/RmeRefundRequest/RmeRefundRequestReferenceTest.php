<?php

/*
| FEATURE-RME-BILLING-REFUND-REQUEST-APPROVAL-1 — the Owner approval reference
| (owner RF-1, RF-6; registry rule 214) and the patient request reference (RF-5 = B)
| (TDD RR-REF / RR-PAT / RR-PRIV-02). PostgreSQL-only backstops live in the
| concurrency suite.
*/

use App\Modules\RmeInvoice\Models\RmePayment;
use App\Modules\RmeInvoice\Models\RmeRefundRequest;
use App\Modules\RmeInvoice\Services\RmeInvoiceCorrectionService;
use App\Modules\RmeInvoice\Services\RmeOwnerApprovalReferenceRegistry;
use App\Modules\RmeInvoice\Support\RmeInvoiceCorrectionStatus;
use App\Modules\RmeInvoice\Support\RmeRefundRequestRefused as R;
use App\Modules\RmeInvoice\Support\RmeRefundRequestStatus as S;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

require_once __DIR__.'/helpers.php';

beforeEach(function () {
    rrrSetup();
});

function rrrRegistryOwner(string $reference): ?array
{
    $row = DB::table('trx_rme_owner_approval_references')->where('reference_folded', strtolower($reference))->first();

    return $row === null ? null : ['scope_type' => $row->scope_type, 'scope_id' => (int) $row->scope_id];
}

// ─── RR-REF: Owner approval reference ───────────────────────────────────────

it('RR-REF-01: a missing Owner reference refuses the submission and leaves no row, no claim', function (mixed $value) {
    [$invoice, $obligation] = rrrObligation();
    $claims = DB::table('trx_rme_owner_approval_references')->count();

    rrrRefused(fn () => rrrSubmit($invoice, $obligation, ['owner_approval_reference' => $value]), R::OWNER_REFERENCE_REQUIRED);
    expect(RmeRefundRequest::query()->count())->toBe(0)
        ->and(DB::table('trx_rme_owner_approval_references')->count())->toBe($claims);
})->with([[null], [''], ['   ']]);

it('RR-REF-02: a malformed or NIK-shaped Owner reference is refused', function (string $value) {
    [$invoice, $obligation] = rrrObligation();

    rrrRefused(fn () => rrrSubmit($invoice, $obligation, ['owner_approval_reference' => $value]), R::OWNER_REFERENCE_INVALID);
})->with([['abc'], ['OWNER REF 01'], ['7371010101900001'], ['ref-737101-010190-0001'], [str_repeat('A', 121)], ['REF<script>']]);

it('RR-REF-03 / RR-REF-07: a reference already on another refund is refused, in any case', function () {
    [$invoice, $obligation] = rrrObligation();
    [$otherInvoice, $otherObligation] = rrrObligation('71');
    rrrSubmit($invoice, $obligation, ['owner_approval_reference' => 'OWNER-REF-SHARED-01']);

    rrrRefused(fn () => rrrSubmit($otherInvoice, $otherObligation, ['owner_approval_reference' => 'owner-ref-shared-01', 'patient_request_reference' => rrrPatientReference('09')]), R::OWNER_REFERENCE_REUSED);
});

it('RR-REF-04: the source correction\'s own reference cannot back the refund', function () {
    [$invoice, $obligation] = rrrObligation('72');

    rrrRefused(fn () => rrrSubmit($invoice, $obligation, ['owner_approval_reference' => rppReference('72')]), R::OWNER_REFERENCE_REUSED);
    rrrRefused(fn () => rrrSubmit($invoice, $obligation, ['owner_approval_reference' => strtolower(rppReference('72'))]), R::OWNER_REFERENCE_REUSED);
});

it('RR-REF-06: a reference recorded on a post-payment correction of another invoice is refused', function () {
    [$invoice, $obligation] = rrrObligation();
    rrrObligation('73');

    rrrRefused(fn () => rrrSubmit($invoice, $obligation, ['owner_approval_reference' => rppReference('73')]), R::OWNER_REFERENCE_REUSED);
});

it('RR-REF-08 (sequential half): a post-payment correction refuses a reference already used by a refund', function () {
    [$invoice, $obligation] = rrrObligation();
    rrrSubmit($invoice, $obligation, ['owner_approval_reference' => 'OWNER-REF-REFUND-ONLY-01']);
    $other = rppPaidInvoice(200000);

    rppExpectRefusal(fn () => rppSubmit($other, reference: 'owner-ref-refund-only-01'), 'CORRECTION_OWNER_APPROVAL_REFERENCE_REUSED');
    expect(rrrRegistryOwner('OWNER-REF-REFUND-ONLY-01')['scope_type'])->toBe(RmeOwnerApprovalReferenceRegistry::SCOPE_REFUND_REQUEST);
});

it('RR-REF-09: a rejected, withdrawn or revoked request\'s reference cannot back the re-filed request', function (string $ending) {
    [$invoice, $obligation] = rrrObligation();
    $first = rrrSubmit($invoice, $obligation, ['owner_approval_reference' => 'OWNER-REF-ONCE-01']);
    match ($ending) {
        'rejected' => rrrReject($first),
        'withdrawn' => rrrCancel($first),
        'revoked' => rrrCancel(rrrApprove($first), test()->kepalaCabang, 'Persetujuan dicabut sebelum pembayaran.'),
    };

    rrrRefused(fn () => rrrSubmit($invoice, $obligation, ['owner_approval_reference' => 'OWNER-REF-ONCE-01']), R::OWNER_REFERENCE_REUSED);
    expect(rrrSubmit($invoice, $obligation, ['owner_approval_reference' => 'OWNER-REF-ONCE-02'])->status)->toBe(S::REQUESTED);
})->with(['rejected', 'withdrawn', 'revoked']);

it('RR-REF-10: PP-2 is preserved — a post-payment correction resubmitted on the same invoice keeps its reference', function () {
    $invoice = rppPaidInvoice(200000);
    $first = rppSubmit($invoice, reference: rppReference('74'));
    app(RmeInvoiceCorrectionService::class)->reject($first->fresh(), test()->checker, 'Harga belum sesuai, ajukan ulang.');

    $second = rppSubmit($invoice->fresh(), reference: strtolower(rppReference('74')));

    expect($second->status)->toBe(RmeInvoiceCorrectionStatus::SUBMITTED)
        ->and(rrrRegistryOwner(rppReference('74')))->toBe([
            'scope_type' => RmeOwnerApprovalReferenceRegistry::SCOPE_POST_PAYMENT_CORRECTION_INVOICE,
            'scope_id' => (int) $invoice->id,
        ]);
});

it('RR-REF-15: a reference sent with the approval form is ignored', function () {
    [$invoice, $obligation] = rrrObligation();
    $request = rrrSubmit($invoice, $obligation, ['owner_approval_reference' => 'OWNER-REF-KEPT-01']);

    $approved = rrrApprove($request, test()->supervisor, ['owner_approval_reference' => 'OWNER-REF-INJECTED-01']);

    expect($approved->owner_approval_reference)->toBe('OWNER-REF-KEPT-01')
        ->and(rrrRegistryOwner('OWNER-REF-INJECTED-01'))->toBeNull();
});

it('RR-REF-16: an approval whose registry row is scoped to another request is refused', function () {
    [$invoice, $obligation] = rrrObligation();
    $request = rrrSubmit($invoice, $obligation, ['owner_approval_reference' => 'OWNER-REF-TAMPER-01']);
    DB::table('trx_rme_owner_approval_references')->where('reference_folded', 'owner-ref-tamper-01')->update(['scope_id' => (int) $request->id + 1000]);

    rrrRefused(fn () => rrrApprove($request), R::OWNER_REFERENCE_INVALID);
    expect($request->fresh()->status)->toBe(S::REQUESTED);
});

it('binds the consumed reference to THIS refund request in the shared registry', function () {
    [$invoice, $obligation] = rrrObligation();
    $request = rrrSubmit($invoice, $obligation, ['owner_approval_reference' => 'OWNER-REF-BIND-01']);

    expect(rrrRegistryOwner('OWNER-REF-BIND-01'))->toBe([
        'scope_type' => RmeOwnerApprovalReferenceRegistry::SCOPE_REFUND_REQUEST,
        'scope_id' => (int) $request->id,
    ]);
});

// ─── RR-PAT: patient request reference ──────────────────────────────────────

it('RR-PAT-04: a missing patient reference refuses the submission and leaves no row, no claim', function (mixed $value) {
    [$invoice, $obligation] = rrrObligation();

    rrrRefused(fn () => rrrSubmit($invoice, $obligation, ['patient_request_reference' => $value]), R::PATIENT_REFERENCE_REQUIRED);
    expect(RmeRefundRequest::query()->count())->toBe(0)
        ->and(DB::table('trx_rme_refund_patient_references')->count())->toBe(0);
})->with([[null], [''], ['  ']]);

it('RR-PAT-03: a NIK-shaped or malformed patient reference is refused', function (string $value) {
    [$invoice, $obligation] = rrrObligation();

    rrrRefused(fn () => rrrSubmit($invoice, $obligation, ['patient_request_reference' => $value]), R::PATIENT_REFERENCE_INVALID);
})->with([['7371010101900001'], ['7371.0101.0190.0001'], ['abc'], [str_repeat('B', 121)], ['FORM PASIEN']]);

it('RR-PAT-01: a patient reference used for another obligation is refused, in any case', function () {
    [$invoice, $obligation] = rrrObligation();
    [$otherInvoice, $otherObligation] = rrrObligation('71');
    rrrSubmit($invoice, $obligation, ['patient_request_reference' => 'FORM-PASIEN-SHARED']);

    rrrRefused(fn () => rrrSubmit($otherInvoice, $otherObligation, ['patient_request_reference' => 'form-pasien-shared', 'owner_approval_reference' => rrrOwnerReference('05')]), R::PATIENT_REFERENCE_REUSED);
    expect(RmeRefundRequest::query()->where('rme_overpayment_obligation_id', $otherObligation->id)->count())->toBe(0);
});

it('RR-PAT-02 / RR-PAT-05: a re-filed request for the SAME obligation may repeat its patient reference', function (string $ending) {
    [$invoice, $obligation] = rrrObligation();
    $first = rrrSubmit($invoice, $obligation, ['patient_request_reference' => 'FORM-PASIEN-SAME', 'owner_approval_reference' => rrrOwnerReference('11')]);
    match ($ending) {
        'rejected' => rrrReject($first),
        'cancelled' => rrrCancel($first),
    };

    $again = rrrSubmit($invoice, $obligation, ['patient_request_reference' => 'form-pasien-same', 'owner_approval_reference' => rrrOwnerReference('12')]);

    expect($again->status)->toBe(S::REQUESTED)
        ->and(DB::table('trx_rme_refund_patient_references')->where('reference_folded', 'form-pasien-same')->value('rme_overpayment_obligation_id'))->toBe((int) $obligation->id);
})->with(['rejected', 'cancelled']);

it('RR-PAT-05 (STALE): a re-filed request after STALE may repeat its patient reference with a new Owner reference', function () {
    [$invoice, $obligation] = rrrObligation();
    $first = rrrSubmit($invoice, $obligation, ['patient_request_reference' => 'FORM-PASIEN-STALE']);
    $payment = RmePayment::factory()->create([
        'branch_id' => $invoice->branch_id, 'rme_invoice_id' => $invoice->id,
        'clinic_visit_id' => $invoice->clinic_visit_id, 'patient_id' => $invoice->patient_id, 'amount' => 1,
    ]);
    rrrRefused(fn () => rrrApprove($first), R::REQUEST_STALE);
    DB::table('trx_rme_payments')->where('id', $payment->id)->delete();

    $again = rrrSubmit($invoice, $obligation, ['patient_request_reference' => 'FORM-PASIEN-STALE', 'owner_approval_reference' => rrrOwnerReference('13')]);
    expect($again->status)->toBe(S::REQUESTED);
});

// ─── RR-PRIV-02 ─────────────────────────────────────────────────────────────

it('RR-PRIV-02: the oversight list and detail render no NIK, Owner reference or patient reference', function () {
    [$invoice, $obligation] = rrrObligation();
    $request = rrrSubmit($invoice, $obligation, ['owner_approval_reference' => 'OWNER-REF-HIDDEN-01', 'patient_request_reference' => 'FORM-PASIEN-HIDDEN']);
    $reader = userInRole('Finance Klinik');

    foreach ([route('oversight.billing-corrections.refund-requests.index'), route('oversight.billing-corrections.refund-requests.show', $request->id)] as $url) {
        test()->actingAs($reader)->get($url)->assertOk()
            ->assertDontSee(test()->patient->ktp_number)
            ->assertDontSee('OWNER-REF-HIDDEN-01')
            ->assertDontSee('FORM-PASIEN-HIDDEN');
    }
});

it('RR-REF-17: a registry row alone — with no refund and no correction carrying the reference — refuses the submission', function () {
    [$invoice, $obligation] = rrrObligation();
    [$otherInvoice] = rrrObligation('74');

    // The registry is the database authority (rule 214). The application-level
    // checks read refund and correction rows; this reference is on neither, so
    // only the registry can refuse it.
    $reference = 'OWNER-REF-REGISTRY-ONLY-01';
    DB::transaction(fn () => app(RmeOwnerApprovalReferenceRegistry::class)->consume(
        $reference,
        RmeOwnerApprovalReferenceRegistry::SCOPE_POST_PAYMENT_CORRECTION_INVOICE,
        (int) $otherInvoice->id,
        test()->superAdmin,
    ));
    expect(DB::table('trx_rme_invoice_corrections')->whereRaw('lower(owner_approval_reference) = ?', [strtolower($reference)])->exists())->toBeFalse();

    $before = RmeRefundRequest::count();
    rrrRefused(fn () => rrrSubmit($invoice, $obligation, ['owner_approval_reference' => strtolower($reference)]), R::OWNER_REFERENCE_REUSED);

    expect(RmeRefundRequest::count())->toBe($before)
        ->and(DB::table('trx_rme_refund_patient_references')->count())->toBe(0);
});
