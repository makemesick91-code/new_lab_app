<?php

/*
| DMS-SPN4-READY-PILOT-1 G6 / REM-2a — a refused refund request never flashes a
| NIK-shaped reference back into the session.
|
| Both references are refused by the service exactly when they look like a NIK.
| The controller answers a refusal with back()->withInput(), which bypasses the
| exception handler's dontFlash, so it now excludes both references itself; every
| other field is still flashed so the form keeps what was typed.
|
| The NIK below is synthetic (region code 99 does not exist). Selected by the
| `RmeRefundRequest` critical-gate token in both runner variants and declared in
| critical_gate_mandatory_suites (CG-19).
*/

use App\Modules\RmeInvoice\Models\RmeRefundRequest;

require_once __DIR__.'/helpers.php';

const RRNF_NIK = '9971010101900003';

beforeEach(function () {
    rrrSetup();
});

it('RR-NF-01: a NIK typed as the patient reference is refused and never flashed; the other fields are', function () {
    [$invoice, $obligation] = rrrObligation();

    test()->actingAs(test()->frontOffice)
        ->from(route('rme.refund-requests.create', [$invoice->clinicVisit, $invoice]))
        ->post(route('rme.refund-requests.store', [$invoice->clinicVisit, $invoice]), rrrData($invoice, $obligation, ['patient_request_reference' => RRNF_NIK]))
        ->assertRedirect(route('rme.refund-requests.create', [$invoice->clinicVisit, $invoice]))
        ->assertSessionHasErrors('patient_request_reference');

    expect(session()->getOldInput())->not->toHaveKey('patient_request_reference')
        ->and(session()->getOldInput())->not->toHaveKey('owner_approval_reference')
        ->and(session()->getOldInput('reason_note'))->toBe('Pasien meminta pengembalian kelebihan bayar.')
        ->and((string) json_encode(session()->all()))->not->toContain(RRNF_NIK)
        ->and(RmeRefundRequest::query()->count())->toBe(0);
});

it('RR-NF-02: a NIK typed as the Owner reference is refused and never flashed; the other fields are', function () {
    [$invoice, $obligation] = rrrObligation();

    test()->actingAs(test()->frontOffice)
        ->from(route('rme.refund-requests.create', [$invoice->clinicVisit, $invoice]))
        ->post(route('rme.refund-requests.store', [$invoice->clinicVisit, $invoice]), rrrData($invoice, $obligation, ['owner_approval_reference' => RRNF_NIK]))
        ->assertRedirect()
        ->assertSessionHasErrors('owner_approval_reference');

    expect(session()->getOldInput())->not->toHaveKey('owner_approval_reference')
        ->and(session()->getOldInput('reason_note'))->toBe('Pasien meminta pengembalian kelebihan bayar.')
        ->and((string) json_encode(session()->all()))->not->toContain(RRNF_NIK)
        ->and(RmeRefundRequest::query()->count())->toBe(0);
});

it('RR-NF-03: a valid submission is unchanged: it is filed and redirects to the request', function () {
    [$invoice, $obligation] = rrrObligation();

    test()->actingAs(test()->frontOffice)
        ->post(route('rme.refund-requests.store', [$invoice->clinicVisit, $invoice]), rrrData($invoice, $obligation))
        ->assertRedirect(route('rme.refund-requests.show', RmeRefundRequest::query()->sole()->id))
        ->assertSessionHasNoErrors();
});
