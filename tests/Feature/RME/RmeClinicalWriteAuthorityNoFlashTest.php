<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| DMS-RME-COMPREHENSIVE-CLOSURE-1 — clinical canvases never reach the session
|--------------------------------------------------------------------------
|
| On a validation failure the framework flashes the whole request input into
| the session (only password fields are excluded by default), and the session
| driver is the database. A refused consent, prescription or RM page therefore
| copied the patient's signature image, the prescription R/ canvas or a whole
| handwriting page into the sessions table — clinical evidence that rule 121 and
| CG-11 keep on the private disk only.
|
| bootstrap/app.php now lists those five fields in dontFlash. No form restores
| them with old(), so nothing a user sees changes; every other field is still
| flashed so the form keeps what was typed.
|
| Selected by the existing `RmeClinicalWriteAuthority` critical-gate token in both runner
| variants and declared in critical_gate_mandatory_suites (CG-19). Helper prefix
| `rmecp` is unique because the critical gate loads every suite into one process.
*/

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;

const RMECP_CANVAS_FIELDS = [
    'consenter_signature',
    'doctor_signature',
    'prescription_canvas_data',
    'doctor_signature_canvas_data',
    'handwriting_data',
];

function rmecpDataUri(): string
{
    // Shape only: the handler never decodes it.
    return 'data:image/png;base64,'.base64_encode(str_repeat("\x89PNG-rmecp", 64));
}

beforeEach(function () {
    Route::middleware('web')->post('/_rmecp/refuse', function () {
        throw ValidationException::withMessages(['medical_action' => 'refused for the test']);
    });
});

it('NF-01 registers every clinical canvas field with the exception handler', function () {
    $handler = app(ExceptionHandler::class);
    $dontFlash = (fn () => $this->dontFlash)->call($handler);

    expect($dontFlash)->toContain(...RMECP_CANVAS_FIELDS)
        // The framework defaults are kept, not replaced.
        ->and($dontFlash)->toContain('password', 'password_confirmation', 'current_password');
});

it('NF-02 keeps every canvas field out of the flashed input and keeps the other fields', function () {
    $payload = array_fill_keys(RMECP_CANVAS_FIELDS, rmecpDataUri()) + [
        'medical_action' => 'Pencabutan gigi 36',
        'notes' => 'kept for the form',
    ];

    $this->from('/_rmecp/form')
        ->post('/_rmecp/refuse', $payload)
        ->assertRedirect('/_rmecp/form')
        ->assertSessionHasErrors(['medical_action' => 'refused for the test']);

    $old = session()->getOldInput();

    expect($old)->toHaveKey('medical_action', 'Pencabutan gigi 36')
        ->and($old)->toHaveKey('notes', 'kept for the form');

    foreach (RMECP_CANVAS_FIELDS as $field) {
        expect($old)->not->toHaveKey($field);
    }

    // Nothing of the image survives anywhere in the session payload.
    expect(json_encode(session()->all()))->not->toContain('data:image/png;base64');
});
