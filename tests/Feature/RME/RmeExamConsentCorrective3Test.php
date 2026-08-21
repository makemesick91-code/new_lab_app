<?php

// FIX-RME-EXAM-CONSENT-ODONTOGRAM-HISTORY-3 — CORRECTIVE-03
//
// The product contract this file pins, end to end:
//
//   Pendaftaran -> Antrian -> "Mulai Pemeriksaan" -> in_progress
//     consent UNSIGNED  : active RME and active Odontogram are READ-ONLY,
//                         every history stays readable, and the examination
//                         cannot be finished;
//     consent SIGNED    : both active workspaces become editable;
//     clinical work done: the visit is STILL in_progress — completeness never
//                         ends an examination;
//     "Selesai Pemeriksaan" (explicit, authorized) -> cashier_pending
//     -> and only then is the visit billable.
//
// CORRECTIVE-03 supersedes the earlier rule that consent gated only RME
// authoring and left the active odontogram workflow unchanged.

use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\ClinicVisit\Services\ClinicVisitService;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\MedicalRecord\Models\MedicalRecordHandwriting;
use App\Modules\MedicalRecord\Services\MedicalRecordService;
use App\Modules\Odontogram\Models\Odontogram;
use App\Modules\Odontogram\Services\OdontogramService;
use App\Modules\Patient\Models\Patient;
use App\Modules\RmeInvoice\Services\RmeInvoiceService;
use App\Modules\RmeOnlineContext\Middleware\EnsureRmeOnlineContext;
use Database\Seeders\BranchSeeder;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    test()->seed(BranchSeeder::class);
    seedAccessControl();

    Branch::where('code', Branch::MAIN_CODE)->update(['is_rme_enabled' => false]);
    $this->branch = Branch::factory()->create(['code' => 'ATG3', 'is_rme_enabled' => true]);

    $this->doctorUser = userWith([
        'view_clinic_visits', 'manage_clinic_visits', 'complete_rme_examination', 'manage_rme_consents',
    ]);
    $this->cashier = userWith(['manage_rme_billing']);
    $this->patient = Patient::factory()->create(['branch_id' => $this->branch->id]);

    $this->actingAs($this->doctorUser);
});

function c3Visit(string $status, ?Patient $patient = null): ClinicVisit
{
    return ClinicVisit::factory()->create([
        'branch_id' => test()->branch->id,
        'patient_id' => ($patient ?? test()->patient)->id,
        'status' => $status,
    ]);
}

function c3Odontogram(ClinicVisit $visit): Odontogram
{
    return Odontogram::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $visit->branch_id,
    ]);
}

function c3Record(ClinicVisit $visit): MedicalRecord
{
    $record = MedicalRecord::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $visit->branch_id,
        'patient_id' => $visit->patient_id,
        'doctor_id' => $visit->doctor_id,
    ]);

    MedicalRecordHandwriting::factory()->create([
        'medical_record_id' => $record->id,
        'clinic_visit_id' => $visit->id,
    ]);

    return $record;
}

/** A real charted finding, so the payload is clinical content and not an empty draft. */
function c3ToothPayload(string $status = 'caries'): array
{
    return ['tooth_map_payload' => ['teeth' => ['11' => ['conditions' => [$status]]]]];
}

/*
|--------------------------------------------------------------------------
| §22 — the ACTIVE odontogram is READ-ONLY until consent is signed
|--------------------------------------------------------------------------
*/

it('lets an authorized doctor VIEW the active odontogram while consent is unsigned', function () {
    $visit = c3Visit(ClinicVisit::STATUS_IN_PROGRESS);

    $this->withoutMiddleware(EnsureRmeOnlineContext::class)
        ->get(route('rme.visits.odontogram.show', $visit))
        ->assertOk();
});

it('denies active odontogram mutation while consent is unsigned', function () {
    $visit = c3Visit(ClinicVisit::STATUS_IN_PROGRESS);
    $odontogram = c3Odontogram($visit);

    expect(fn () => app(OdontogramService::class)
        ->updatePlaceholder($odontogram, c3ToothPayload(), $this->doctorUser))
        ->toThrow(ValidationException::class);

    expect($odontogram->fresh()->tooth_map_payload)->toBeNull();
});

it('denies active odontogram finalization while consent is unsigned', function () {
    $visit = c3Visit(ClinicVisit::STATUS_IN_PROGRESS);
    $odontogram = c3Odontogram($visit);

    expect(fn () => app(OdontogramService::class)->finalize($odontogram, $this->doctorUser))
        ->toThrow(ValidationException::class);

    expect($odontogram->fresh()->status)->toBe(Odontogram::STATUS_DRAFT);
});

it('denies a crafted odontogram PATCH while consent is unsigned', function () {
    $visit = c3Visit(ClinicVisit::STATUS_IN_PROGRESS);
    $odontogram = c3Odontogram($visit);

    $this->withoutMiddleware(EnsureRmeOnlineContext::class)
        ->patch(route('rme.odontograms.update', $odontogram), c3ToothPayload())
        ->assertSessionHasErrors();

    expect($odontogram->fresh()->tooth_map_payload)->toBeNull();
});

it('denies a crafted odontogram finalize POST while consent is unsigned', function () {
    $visit = c3Visit(ClinicVisit::STATUS_IN_PROGRESS);
    $odontogram = c3Odontogram($visit);

    $this->withoutMiddleware(EnsureRmeOnlineContext::class)
        ->post(route('rme.odontograms.finalize', $odontogram))
        ->assertSessionHasErrors();

    expect($odontogram->fresh()->status)->toBe(Odontogram::STATUS_DRAFT);
});

it('allows active odontogram authoring once consent is signed', function () {
    $visit = rmeActiveConsentedEncounter(c3Visit(ClinicVisit::STATUS_IN_PROGRESS));
    $odontogram = c3Odontogram($visit);

    app(OdontogramService::class)->updatePlaceholder($odontogram, c3ToothPayload(), $this->doctorUser);

    expect($odontogram->fresh()->tooth_map_payload['teeth']['11']['conditions'])->toBe(['caries']);
});

it('allows active odontogram finalization once consent is signed', function () {
    $visit = rmeActiveConsentedEncounter(c3Visit(ClinicVisit::STATUS_IN_PROGRESS));
    $odontogram = c3Odontogram($visit);

    app(OdontogramService::class)->finalize($odontogram, $this->doctorUser);

    expect($odontogram->fresh()->status)->toBe(Odontogram::STATUS_FINALIZED);
});

it('shows the read-only notice on the odontogram page while consent is unsigned', function () {
    $visit = c3Visit(ClinicVisit::STATUS_IN_PROGRESS);

    $this->withoutMiddleware(EnsureRmeOnlineContext::class)
        ->get(route('rme.visits.odontogram.show', $visit))
        ->assertOk()
        ->assertSee('Persetujuan Tindakan Medis belum ditandatangani')
        ->assertDontSee('Simpan Odontogram');
});

/*
|--------------------------------------------------------------------------
| §23 — history is permanently read-only, and always readable
|--------------------------------------------------------------------------
*/

it('keeps a previous visit odontogram unwritable even during a consented encounter', function () {
    $historical = c3Visit(ClinicVisit::STATUS_COMPLETED);
    $historicalOdontogram = c3Odontogram($historical);

    rmeActiveConsentedEncounter(c3Visit(ClinicVisit::STATUS_IN_PROGRESS));

    expect(fn () => app(OdontogramService::class)
        ->updatePlaceholder($historicalOdontogram, c3ToothPayload('missing'), $this->doctorUser))
        ->toThrow(ValidationException::class);

    expect($historicalOdontogram->fresh()->tooth_map_payload)->toBeNull();
});

it('keeps the odontogram history readable while consent is unsigned', function () {
    $historical = c3Visit(ClinicVisit::STATUS_COMPLETED);
    Odontogram::factory()->create([
        'clinic_visit_id' => $historical->id,
        'branch_id' => $historical->branch_id,
        'tooth_map_payload' => ['teeth' => ['11' => ['conditions' => ['caries']]]],
    ]);

    $current = c3Visit(ClinicVisit::STATUS_IN_PROGRESS);

    $history = app(OdontogramService::class)->patientHistoryForVisit($current, $this->doctorUser);

    expect($history)->toHaveCount(1);
});

it('keeps the medical record page readable while consent is unsigned', function () {
    $visit = c3Visit(ClinicVisit::STATUS_IN_PROGRESS);
    c3Record($visit);

    $this->withoutMiddleware(EnsureRmeOnlineContext::class)
        ->get(route('rme.visits.medical-record.show', $visit))
        ->assertSuccessful();
});

/*
|--------------------------------------------------------------------------
| §24 — clinical completeness NEVER completes an examination
|--------------------------------------------------------------------------
*/

it('keeps the visit in progress after the medical record is finalized', function () {
    $visit = rmeActiveConsentedEncounter(c3Visit(ClinicVisit::STATUS_IN_PROGRESS));
    $record = c3Record($visit);

    app(MedicalRecordService::class)->finalize($record);

    expect($visit->fresh()->status)->toBe(ClinicVisit::STATUS_IN_PROGRESS);
});

it('keeps the visit in progress after the odontogram is saved and finalized', function () {
    $visit = rmeActiveConsentedEncounter(c3Visit(ClinicVisit::STATUS_IN_PROGRESS));
    $odontogram = c3Odontogram($visit);

    app(OdontogramService::class)->updatePlaceholder($odontogram, c3ToothPayload(), $this->doctorUser);
    app(OdontogramService::class)->finalize($odontogram, $this->doctorUser);

    expect($visit->fresh()->status)->toBe(ClinicVisit::STATUS_IN_PROGRESS);
});

it('keeps the visit in progress when the record and the odontogram are both complete', function () {
    $visit = rmeActiveConsentedEncounter(c3Visit(ClinicVisit::STATUS_IN_PROGRESS));
    $record = c3Record($visit);
    $odontogram = c3Odontogram($visit);

    app(OdontogramService::class)->updatePlaceholder($odontogram, c3ToothPayload(), $this->doctorUser);
    app(OdontogramService::class)->finalize($odontogram, $this->doctorUser);
    app(MedicalRecordService::class)->finalize($record);

    expect($visit->fresh()->status)->toBe(ClinicVisit::STATUS_IN_PROGRESS);
});

/*
|--------------------------------------------------------------------------
| §24 — "Selesai Pemeriksaan" needs consent, and is the only way out
|--------------------------------------------------------------------------
*/

it('denies finishing the examination while consent is unsigned', function () {
    $visit = c3Visit(ClinicVisit::STATUS_IN_PROGRESS);

    expect(fn () => app(ClinicVisitService::class)
        ->transitionStatus($visit, ClinicVisit::STATUS_CASHIER_PENDING))
        ->toThrow(ValidationException::class);

    expect($visit->fresh()->status)->toBe(ClinicVisit::STATUS_IN_PROGRESS);
});

it('denies a crafted Selesai Pemeriksaan request while consent is unsigned', function () {
    $visit = c3Visit(ClinicVisit::STATUS_IN_PROGRESS);

    $this->withoutMiddleware(EnsureRmeOnlineContext::class)
        ->post(route('rme.visits.transition', $visit), ['status' => ClinicVisit::STATUS_CASHIER_PENDING])
        ->assertSessionHasErrors();

    expect($visit->fresh()->status)->toBe(ClinicVisit::STATUS_IN_PROGRESS);
});

it('allows the explicit Selesai Pemeriksaan once consent is signed', function () {
    $visit = rmeActiveConsentedEncounter(c3Visit(ClinicVisit::STATUS_IN_PROGRESS));

    $this->withoutMiddleware(EnsureRmeOnlineContext::class)
        ->post(route('rme.visits.transition', $visit), ['status' => ClinicVisit::STATUS_CASHIER_PENDING])
        ->assertRedirect(route('rme.visits.show', $visit));

    expect($visit->fresh()->status)->toBe(ClinicVisit::STATUS_CASHIER_PENDING);
});

/*
|--------------------------------------------------------------------------
| §25 — billing follows the workflow state, never consent directly
|--------------------------------------------------------------------------
*/

it('refuses to bill a visit that has not reached cashier_pending', function (string $status) {
    $visit = c3Visit($status);

    $this->actingAs($this->cashier);

    expect(fn () => app(RmeInvoiceService::class)->create($visit, $this->cashier, [
        'items' => [['description' => 'Tindakan', 'quantity' => 1, 'unit_price' => 50000]],
    ]))->toThrow(ValidationException::class);
})->with([
    ClinicVisit::STATUS_REGISTERED,
    ClinicVisit::STATUS_WAITING,
    ClinicVisit::STATUS_IN_PROGRESS,
]);

it('refuses to bill an in-progress visit even when its consent is signed', function () {
    $visit = rmeActiveConsentedEncounter(c3Visit(ClinicVisit::STATUS_IN_PROGRESS));

    $this->actingAs($this->cashier);

    expect(fn () => app(RmeInvoiceService::class)->create($visit, $this->cashier, [
        'items' => [['description' => 'Tindakan', 'quantity' => 1, 'unit_price' => 50000]],
    ]))->toThrow(ValidationException::class);
});
