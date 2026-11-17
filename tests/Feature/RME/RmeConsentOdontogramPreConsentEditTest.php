<?php

// FLIPPED IN PLACE by DMS memo M1.1–M1.3 PR2 (rule 241 §2, owner decision
// 2026-11-17): BOTH PTM signatures — the patient's and the treating doctor's
// countersignature — are required before odontogram WRITING, so pre-consent
// charting ends. Every case that pinned "the chart is editable before consent"
// now pins the refusal (its old name is kept in a comment); every case that
// pinned the RME/finish gate, "Mulai Pemeriksaan", actor scope and read-only
// history stands. `rmeSignedConsentFor()` / `rmeActiveConsentedEncounter()`
// now build an EXECUTED consent. The gate itself is pinned in
// RmeOdontogramTwoSignatureGateTest. The original header follows as history.
//
// REVISION-RME-CONSENT-ODONTOGRAM-PRECONSENT-EDIT-1
//
// The product rule this file pins, and the ONE place it is allowed to differ
// from FIX-RME-EXAM-CONSENT-ODONTOGRAM-HISTORY-3 / CORRECTIVE-03:
//
//   "Mulai Pemeriksaan" -> in_progress
//     consent UNSIGNED : the ACTIVE Native Odontogram is EDITABLE,
//                        the ACTIVE RME is still READ-ONLY,
//                        "Selesai Pemeriksaan" is still REFUSED;
//     consent SIGNED   : odontogram AND RME are editable, and the
//                        examination may be finished.
//
// Why the odontogram is carved out and the record is not: charting teeth is
// OBSERVATION. The doctor looks in the mouth and writes down what is already
// there, and that observation is exactly what the patient is then asked to
// consent to — a consent form naming the treatment cannot be filled in before
// anyone has looked. Requiring the signature first made the workflow circular.
// The RME note and the finish transition are different acts: the note records
// the treatment DECISION, and finishing hands the visit to the cashier as a
// bill. Both remain gated.
//
// Removing consent from the odontogram removes ONE of the four conditions in
// RmeVisitConsentService::assertOdontogramAuthoringAllowed(). The other three
// are load-bearing and are pinned here as hard as the new permission is:
//   1. the patient HAS a single current `in_progress` encounter;
//   2. the actor may work on it (branch scope + clinical patient scope);
//   3. the chart being written IS that encounter's own chart.
//
// SUPERSEDES the CORRECTIVE-03 clause "consent gates the active odontogram".
// Everything else CORRECTIVE-03 established stands unchanged.

use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\ClinicVisit\Services\ClinicVisitService;
use App\Modules\Consent\Models\RmeVisitConsentDoctorSignature;
use App\Modules\Consent\Services\RmeVisitConsentService;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\MedicalRecord\Models\MedicalRecordHandwriting;
use App\Modules\MedicalRecord\Services\MedicalRecordService;
use App\Modules\Odontogram\Models\Odontogram;
use App\Modules\Odontogram\Services\OdontogramService;
use App\Modules\Patient\Models\Patient;
use App\Modules\RmeOnlineContext\Middleware\EnsureRmeOnlineContext;
use App\Support\Storage\ClinicalEvidenceStorage;
use Database\Seeders\BranchSeeder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    test()->seed(BranchSeeder::class);
    seedAccessControl();
    // CG-20 — signing writes signature objects to the clinical evidence disk
    // (OD-5-9); never to the real one.
    Storage::fake(ClinicalEvidenceStorage::diskName());

    Branch::where('code', Branch::MAIN_CODE)->update(['is_rme_enabled' => false]);
    $this->branch = Branch::factory()->create(['code' => 'ATG3', 'is_rme_enabled' => true]);

    $this->doctorUser = rmeClinicalAuthor([
        'view_clinic_visits', 'manage_clinic_visits', 'complete_rme_examination', 'manage_rme_consents',
    ]);
    $this->patient = Patient::factory()->create(['branch_id' => $this->branch->id]);

    $this->actingAs($this->doctorUser);
});

function pceVisit(string $status, ?Patient $patient = null): ClinicVisit
{
    return ClinicVisit::factory()->create([
        'branch_id' => test()->branch->id,
        'patient_id' => ($patient ?? test()->patient)->id,
        'status' => $status,
    ]);
}

function pceOdontogram(ClinicVisit $visit): Odontogram
{
    return Odontogram::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $visit->branch_id,
    ]);
}

function pceRecord(ClinicVisit $visit): MedicalRecord
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
function pceToothPayload(string $status = 'caries'): array
{
    return ['tooth_map_payload' => ['teeth' => ['11' => ['conditions' => [$status]]]]];
}

/*
|--------------------------------------------------------------------------
| §21 — the ACTIVE odontogram is READ-ONLY before the executed consent
|       (flipped by rule 241 §2; was "EDITABLE before consent")
|--------------------------------------------------------------------------
*/

// was 'lets the doctor SAVE the active odontogram while consent is unsigned'
it('refuses to SAVE the active odontogram while consent is unsigned', function () {
    $visit = pceVisit(ClinicVisit::STATUS_IN_PROGRESS);
    $odontogram = pceOdontogram($visit);

    expect(fn () => app(OdontogramService::class)->updatePlaceholder($odontogram, pceToothPayload(), $this->doctorUser))
        ->toThrow(ValidationException::class, 'Persetujuan Tindakan Medis belum ditandatangani');

    expect($odontogram->fresh()->tooth_map_payload)->toBeNull();
    expect(app(RmeVisitConsentService::class)->hasValidConsent($visit->fresh()))->toBeFalse();
});

// was 'lets the doctor CREATE the first chart while consent is unsigned'
it('refuses to CREATE the first chart while consent is unsigned', function () {
    $visit = pceVisit(ClinicVisit::STATUS_IN_PROGRESS);

    expect(fn () => app(OdontogramService::class)->saveForVisit($visit, pceToothPayload(), $this->doctorUser))
        ->toThrow(ValidationException::class, 'Persetujuan Tindakan Medis belum ditandatangani');

    expect(Odontogram::where('clinic_visit_id', $visit->id)->exists())->toBeFalse();
});

// was 'lets the doctor FINALIZE the active chart while consent is unsigned'
it('refuses to FINALIZE the active chart while consent is unsigned', function () {
    // Odontogram finalization is a chart-level lifecycle act, NOT "Selesai
    // Pemeriksaan"; it routes through the same single assertion.
    $visit = pceVisit(ClinicVisit::STATUS_IN_PROGRESS);
    $odontogram = pceOdontogram($visit);

    expect(fn () => app(OdontogramService::class)->finalize($odontogram, $this->doctorUser))
        ->toThrow(ValidationException::class, 'Persetujuan Tindakan Medis belum ditandatangani');

    expect($odontogram->fresh()->status)->not->toBe(Odontogram::STATUS_FINALIZED);
    expect($visit->fresh()->status)->toBe(ClinicVisit::STATUS_IN_PROGRESS);
});

// was 'accepts the real odontogram PATCH request while consent is unsigned'
it('refuses the real odontogram PATCH request while consent is unsigned', function () {
    $visit = pceVisit(ClinicVisit::STATUS_IN_PROGRESS);
    $odontogram = pceOdontogram($visit);

    $this->withoutMiddleware(EnsureRmeOnlineContext::class)
        ->patch(route('rme.odontograms.update', $odontogram), pceToothPayload())
        ->assertSessionHasErrors('consent')
        ->assertRedirect();

    expect($odontogram->fresh()->tooth_map_payload)->toBeNull();
});

// was 'accepts the real odontogram store request while consent is unsigned'
it('refuses the real odontogram store request while consent is unsigned', function () {
    $visit = pceVisit(ClinicVisit::STATUS_IN_PROGRESS);

    $this->withoutMiddleware(EnsureRmeOnlineContext::class)
        ->patch(route('rme.visits.odontogram.store', $visit), pceToothPayload())
        ->assertSessionHasErrors('consent');

    expect(Odontogram::where('clinic_visit_id', $visit->id)->exists())->toBeFalse();
});

// was 'exposes the odontogram authoring capability as allowed before consent'
it('exposes the odontogram authoring capability as refused before consent', function () {
    $visit = pceVisit(ClinicVisit::STATUS_IN_PROGRESS);

    expect(app(RmeVisitConsentService::class)->canAuthorOdontogramFor($visit, $this->doctorUser))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| §22 — the ACTIVE RME is STILL blocked before consent  (must not regress)
|--------------------------------------------------------------------------
*/

it('still denies active RME authoring while consent is unsigned', function () {
    $visit = pceVisit(ClinicVisit::STATUS_IN_PROGRESS);
    $record = pceRecord($visit);

    expect(fn () => app(MedicalRecordService::class)
        ->updateDraft($record, ['notes' => 'catatan klinis'], $this->doctorUser))
        ->toThrow(ValidationException::class);

    expect($record->fresh()->notes)->not->toBe('catatan klinis');
});

it('still denies active RME authoring after the odontogram has been charted', function () {
    // The important one: charting must not become an implicit consent that
    // unlocks the record beside it. Since rule 241 §2 the chart cannot be
    // written before consent either, so the chart here is one written before the
    // gate (a fixture row, e.g. a visit open at deploy).
    $visit = pceVisit(ClinicVisit::STATUS_IN_PROGRESS);
    $record = pceRecord($visit);

    pceOdontogram($visit)->forceFill(pceToothPayload())->save();

    expect(fn () => app(MedicalRecordService::class)
        ->updateDraft($record, ['notes' => 'catatan klinis'], $this->doctorUser))
        ->toThrow(ValidationException::class);

    expect(app(RmeVisitConsentService::class)->canAuthorRmeForPatient($visit->patient_id, $this->doctorUser))
        ->toBeFalse();
});

it('still denies a crafted RME update request while consent is unsigned', function () {
    $visit = pceVisit(ClinicVisit::STATUS_IN_PROGRESS);
    $record = pceRecord($visit);

    $this->withoutMiddleware(EnsureRmeOnlineContext::class)
        ->patch(route('rme.visits.medical-record.update', [$visit, $record]), ['notes' => 'catatan klinis'])
        ->assertSessionHasErrors();

    expect($record->fresh()->notes)->not->toBe('catatan klinis');
});

/*
|--------------------------------------------------------------------------
| §23 — "Selesai Pemeriksaan" is STILL blocked before consent
|--------------------------------------------------------------------------
*/

it('still denies finishing the examination while consent is unsigned', function () {
    $visit = pceVisit(ClinicVisit::STATUS_IN_PROGRESS);

    expect(fn () => app(ClinicVisitService::class)
        ->transitionStatus($visit, ClinicVisit::STATUS_CASHIER_PENDING))
        ->toThrow(ValidationException::class);

    expect($visit->fresh()->status)->toBe(ClinicVisit::STATUS_IN_PROGRESS);
});

it('still denies finishing the examination even when the odontogram is complete', function () {
    // §5 verbatim: a finished chart is not a substitute for a signature. The
    // finalized chart is a fixture row (charting itself waits for consent since
    // rule 241 §2).
    $visit = pceVisit(ClinicVisit::STATUS_IN_PROGRESS);
    pceOdontogram($visit)->forceFill(pceToothPayload() + ['status' => Odontogram::STATUS_FINALIZED])->save();

    expect(fn () => app(ClinicVisitService::class)
        ->transitionStatus($visit, ClinicVisit::STATUS_CASHIER_PENDING))
        ->toThrow(ValidationException::class);

    expect($visit->fresh()->status)->toBe(ClinicVisit::STATUS_IN_PROGRESS);
});

it('still denies a crafted Selesai Pemeriksaan request after charting', function () {
    $visit = pceVisit(ClinicVisit::STATUS_IN_PROGRESS);
    pceOdontogram($visit)->forceFill(pceToothPayload())->save();

    $this->withoutMiddleware(EnsureRmeOnlineContext::class)
        ->post(route('rme.visits.transition', $visit), ['status' => ClinicVisit::STATUS_CASHIER_PENDING])
        ->assertSessionHasErrors();

    expect($visit->fresh()->status)->toBe(ClinicVisit::STATUS_IN_PROGRESS);
});

/*
|--------------------------------------------------------------------------
| §24 — after consent, everything opens
|--------------------------------------------------------------------------
*/

it('allows odontogram, RME and finish once consent is signed', function () {
    $visit = rmeActiveConsentedEncounter(pceVisit(ClinicVisit::STATUS_IN_PROGRESS));
    $record = pceRecord($visit);
    $odontogram = pceOdontogram($visit);

    app(OdontogramService::class)->updatePlaceholder($odontogram, pceToothPayload(), $this->doctorUser);
    app(MedicalRecordService::class)->updateDraft($record, ['notes' => 'catatan klinis'], $this->doctorUser);
    app(ClinicVisitService::class)->transitionStatus($visit, ClinicVisit::STATUS_CASHIER_PENDING, $this->doctorUser);

    expect($odontogram->fresh()->tooth_map_payload['teeth']['11']['conditions'])->toBe(['caries']);
    expect($record->fresh()->notes)->toBe('catatan klinis');
    expect($visit->fresh()->status)->toBe(ClinicVisit::STATUS_CASHIER_PENDING);
});

/*
|--------------------------------------------------------------------------
| §25 — "Mulai Pemeriksaan" is still mandatory
|--------------------------------------------------------------------------
| Consent's ABSENCE must never become the only criterion. Every non-active
| status stays refused, with or without a signature.
*/

it('denies odontogram authoring before the examination starts', function (string $status) {
    $visit = pceVisit($status);
    $odontogram = pceOdontogram($visit);

    expect(fn () => app(OdontogramService::class)
        ->updatePlaceholder($odontogram, pceToothPayload(), $this->doctorUser))
        ->toThrow(ValidationException::class);

    expect($odontogram->fresh()->tooth_map_payload)->toBeNull();
})->with([
    ClinicVisit::STATUS_REGISTERED,
    ClinicVisit::STATUS_WAITING,
]);

it('denies creating a first chart before the examination starts', function () {
    $visit = pceVisit(ClinicVisit::STATUS_WAITING);

    expect(fn () => app(OdontogramService::class)
        ->saveForVisit($visit, pceToothPayload(), $this->doctorUser))
        ->toThrow(ValidationException::class);

    expect(Odontogram::where('clinic_visit_id', $visit->id)->exists())->toBeFalse();
});

it('denies odontogram authoring on a visit past the examination', function (string $status) {
    $visit = pceVisit($status);
    $odontogram = pceOdontogram($visit);

    expect(fn () => app(OdontogramService::class)
        ->updatePlaceholder($odontogram, pceToothPayload(), $this->doctorUser))
        ->toThrow(ValidationException::class);

    expect($odontogram->fresh()->tooth_map_payload)->toBeNull();
})->with([
    ClinicVisit::STATUS_CASHIER_PENDING,
    ClinicVisit::STATUS_COMPLETED,
    ClinicVisit::STATUS_CANCELLED,
]);

/*
|--------------------------------------------------------------------------
| §26/§27 — actor scope survives: role, ownership, branch
|--------------------------------------------------------------------------
*/

it('denies a non-doctor actor the active odontogram while consent is unsigned', function () {
    $visit = pceVisit(ClinicVisit::STATUS_IN_PROGRESS);
    $odontogram = pceOdontogram($visit);

    $cashier = userWith(['manage_rme_billing']);

    $this->actingAs($cashier)
        ->withoutMiddleware(EnsureRmeOnlineContext::class)
        ->patch(route('rme.odontograms.update', $odontogram), pceToothPayload())
        ->assertForbidden();

    expect($odontogram->fresh()->tooth_map_payload)->toBeNull();
});

it('denies a view-only actor the active odontogram while consent is unsigned', function () {
    $visit = pceVisit(ClinicVisit::STATUS_IN_PROGRESS);
    $odontogram = pceOdontogram($visit);

    $viewer = rmeClinicalReader(['view_clinic_visits']);

    $this->actingAs($viewer)
        ->withoutMiddleware(EnsureRmeOnlineContext::class)
        ->patch(route('rme.odontograms.update', $odontogram), pceToothPayload())
        ->assertForbidden();

    expect($odontogram->fresh()->tooth_map_payload)->toBeNull();
});

it('denies the odontogram write ability to an actor without manage_clinic_visits', function () {
    // Pins the POLICY itself, not just the HTTP outcome. The three write routes
    // also carry `permission:manage_clinic_visits`, so an HTTP assertion alone
    // cannot tell whether the policy or the middleware refused — and a mutation
    // that opened `OdontogramPolicy::canManage()` survived every HTTP test.
    // Defence in depth is only depth if each layer is asserted separately.
    $visit = pceVisit(ClinicVisit::STATUS_IN_PROGRESS);
    $odontogram = pceOdontogram($visit);

    $viewer = rmeClinicalReader(['view_clinic_visits']);

    expect(Gate::forUser($viewer)->allows('update', $odontogram))->toBeFalse();
    expect(Gate::forUser($viewer)->allows('finalize', $odontogram))->toBeFalse();
    expect(Gate::forUser($viewer)->allows('author', [Odontogram::class, $visit]))->toBeFalse();

    // ...and the same actor may still READ the chart.
    expect(Gate::forUser($viewer)->allows('view', $odontogram))->toBeTrue();
});

it('denies another doctor the active odontogram of a patient they do not handle', function () {
    // IDOR: consent state must never weaken the clinical patient scope.
    $visit = pceVisit(ClinicVisit::STATUS_IN_PROGRESS);
    $odontogram = pceOdontogram($visit);

    $otherDoctorUser = userWith(['view_clinic_visits', 'manage_clinic_visits']);
    $otherDoctor = Doctor::factory()->create([
        'user_id' => $otherDoctorUser->id,
        'branch_id' => $this->branch->id,
    ]);
    $otherDoctorUser->assignRole('Doctor');

    expect($otherDoctor->exists)->toBeTrue();

    expect(fn () => app(OdontogramService::class)
        ->updatePlaceholder($odontogram, pceToothPayload(), $otherDoctorUser))
        ->toThrow(ValidationException::class);

    expect($odontogram->fresh()->tooth_map_payload)->toBeNull();
});

/*
|--------------------------------------------------------------------------
| §28 — historical native charts stay immutable
|--------------------------------------------------------------------------
*/

it('keeps a previous visit chart unwritable while the live encounter is unconsented', function () {
    $historical = pceVisit(ClinicVisit::STATUS_COMPLETED);
    $historicalChart = pceOdontogram($historical);

    pceVisit(ClinicVisit::STATUS_IN_PROGRESS);

    expect(fn () => app(OdontogramService::class)
        ->updatePlaceholder($historicalChart, pceToothPayload('missing'), $this->doctorUser))
        ->toThrow(ValidationException::class);

    expect($historicalChart->fresh()->tooth_map_payload)->toBeNull();
});

it('never lets the request nominate which encounter authorises a chart', function () {
    // The request is not authority. A crafted PATCH on a HISTORICAL chart that
    // names the live encounter in its body must still be refused: the chart's
    // own visit is what is compared, resolved from server state.
    $historical = pceVisit(ClinicVisit::STATUS_COMPLETED);
    $historicalChart = pceOdontogram($historical);

    $live = pceVisit(ClinicVisit::STATUS_IN_PROGRESS);

    $this->withoutMiddleware(EnsureRmeOnlineContext::class)
        ->patch(route('rme.odontograms.update', $historicalChart), array_merge(
            pceToothPayload('missing'),
            ['clinic_visit_id' => $live->id, 'patient_id' => $live->patient_id],
        ))
        ->assertSessionHasErrors();

    expect($historicalChart->fresh()->tooth_map_payload)->toBeNull();
});

/*
|--------------------------------------------------------------------------
| §30/§31/§32/§33 — no side effects, and pre-consent work survives
|--------------------------------------------------------------------------
*/

it('never creates or signs a consent as a side effect of charting', function () {
    // Rule 241 §2: charting needs the executed consent, so the chart is written
    // on one — and writing it adds no consent and no countersignature.
    $visit = rmeActiveConsentedEncounter(pceVisit(ClinicVisit::STATUS_IN_PROGRESS));

    app(OdontogramService::class)->saveForVisit($visit, pceToothPayload(), $this->doctorUser);

    $consents = app(RmeVisitConsentService::class);
    expect($consents->historyFor($visit->fresh()))->toHaveCount(1);
    expect(RmeVisitConsentDoctorSignature::query()->count())->toBe(1);
});

it('never advances the visit status as a side effect of charting', function () {
    $visit = rmeActiveConsentedEncounter(pceVisit(ClinicVisit::STATUS_IN_PROGRESS));

    app(OdontogramService::class)->saveForVisit($visit, pceToothPayload(), $this->doctorUser);
    app(OdontogramService::class)->finalize(
        Odontogram::where('clinic_visit_id', $visit->id)->firstOrFail(),
        $this->doctorUser,
    );

    expect($visit->fresh()->status)->toBe(ClinicVisit::STATUS_IN_PROGRESS);
});

it('keeps pre-consent charting intact after the consent is signed', function () {
    // Since rule 241 §2 a chart written before consent can only predate the gate
    // (a visit open at deploy): a fixture row here. Signs through
    // RmeVisitConsentService, NOT the `rmeSignedConsentFor()` fixture, so a
    // signing side effect that destroyed the chart would be caught (mutation
    // testing proved that gap once). The doctor's countersignature is a fixture
    // row: its own side effects are pinned in RmeVisitConsentDoctorCountersignTest.
    $visit = pceVisit(ClinicVisit::STATUS_IN_PROGRESS);

    pceOdontogram($visit)->forceFill(pceToothPayload())->save();
    $chartId = Odontogram::where('clinic_visit_id', $visit->id)->firstOrFail()->id;

    $consent = app(RmeVisitConsentService::class)->sign($visit, $this->doctorUser, [
        'template_code' => 'PERSETUJUAN_TINDAKAN_MEDIS',
        'consenter_relationship' => 'self',
        'medical_action' => 'Pencabutan gigi 36',
        'treatment_summary' => 'Pencabutan gigi',
        'documentation_consent' => false,
        'consenter_signature' => validPodSignatureData(),
    ]);

    expect(app(RmeVisitConsentService::class)->hasValidConsent($visit->fresh()))->toBeTrue();
    rmeDoctorCountersignatureFor($consent);

    $chart = Odontogram::where('clinic_visit_id', $visit->id)->firstOrFail();
    expect($chart->id)->toBe($chartId);
    expect($chart->tooth_map_payload['teeth']['11']['conditions'])->toBe(['caries']);
    expect(Odontogram::where('clinic_visit_id', $visit->id)->count())->toBe(1);

    // And the record beside it is now writable.
    $record = pceRecord($visit);
    app(MedicalRecordService::class)->updateDraft($record, ['notes' => 'catatan klinis'], $this->doctorUser);
    expect($record->fresh()->notes)->toBe('catatan klinis');
});

// was 'updates one canonical chart across repeated pre-consent saves'
it('updates one canonical chart across repeated saves', function () {
    $visit = rmeActiveConsentedEncounter(pceVisit(ClinicVisit::STATUS_IN_PROGRESS));

    app(OdontogramService::class)->saveForVisit($visit, pceToothPayload('caries'), $this->doctorUser);
    app(OdontogramService::class)->saveForVisit($visit, pceToothPayload('filling'), $this->doctorUser);

    expect(Odontogram::where('clinic_visit_id', $visit->id)->count())->toBe(1);
    expect(Odontogram::where('clinic_visit_id', $visit->id)->firstOrFail()
        ->tooth_map_payload['teeth']['11']['conditions'])->toBe(['filling']);
});

/*
|--------------------------------------------------------------------------
| §34 — same-visit consent only
|--------------------------------------------------------------------------
*/

it('never lets another visit consent unlock the active RME', function () {
    $earlier = pceVisit(ClinicVisit::STATUS_IN_PROGRESS);
    rmeSignedConsentFor($earlier);
    $earlier->forceFill(['status' => ClinicVisit::STATUS_COMPLETED])->save();

    $live = pceVisit(ClinicVisit::STATUS_IN_PROGRESS);
    $record = pceRecord($live);

    expect(fn () => app(MedicalRecordService::class)
        ->updateDraft($record, ['notes' => 'catatan klinis'], $this->doctorUser))
        ->toThrow(ValidationException::class);

    // ...and (rule 241 §2) neither does it unlock the LIVE encounter's chart.
    $chart = pceOdontogram($live);
    expect(fn () => app(OdontogramService::class)->updatePlaceholder($chart, pceToothPayload(), $this->doctorUser))
        ->toThrow(ValidationException::class);
    expect($chart->fresh()->tooth_map_payload)->toBeNull();
});

/*
|--------------------------------------------------------------------------
| §35/§40/§41 — the page agrees with the server
|--------------------------------------------------------------------------
*/

// was 'renders the odontogram page with save controls while consent is unsigned'
it('renders the odontogram page without save controls while consent is unsigned', function () {
    $visit = pceVisit(ClinicVisit::STATUS_IN_PROGRESS);

    $this->withoutMiddleware(EnsureRmeOnlineContext::class)
        ->get(route('rme.visits.odontogram.show', $visit))
        ->assertOk()
        ->assertSee('data-ptm-state="awaiting_patient"', false)
        ->assertDontSee('Simpan Odontogram');
});

// was 'no longer tells the doctor that consent blocks the odontogram'
it('tells the doctor that the odontogram waits for the consent', function () {
    $visit = pceVisit(ClinicVisit::STATUS_IN_PROGRESS);

    $this->withoutMiddleware(EnsureRmeOnlineContext::class)
        ->get(route('rme.visits.odontogram.show', $visit))
        ->assertOk()
        ->assertSee('Persetujuan Tindakan Medis belum ditandatangani')
        ->assertSee('Odontogram, rekam medis dan tombol Selesai Pemeriksaan menunggu');
});

// was 'no longer tells the doctor on the visit page that the odontogram is locked'
it('tells the doctor on the visit page that the record and the odontogram wait for the consent', function () {
    $visit = pceVisit(ClinicVisit::STATUS_IN_PROGRESS);

    $this->withoutMiddleware(EnsureRmeOnlineContext::class)
        ->get(route('rme.visits.show', $visit))
        ->assertOk()
        ->assertSee('Persetujuan Tindakan Medis belum ditandatangani')
        ->assertSee('Rekam medis dan odontogram kunjungan ini belum dapat ditulis')
        ->assertDontSee('Odontogram kunjungan ini sudah dapat dicatat');
});

it('still marks a historical chart read-only on the page', function () {
    $historical = pceVisit(ClinicVisit::STATUS_COMPLETED);
    pceOdontogram($historical);
    pceVisit(ClinicVisit::STATUS_IN_PROGRESS);

    $this->withoutMiddleware(EnsureRmeOnlineContext::class)
        ->get(route('rme.visits.odontogram.show', $historical))
        ->assertOk()
        ->assertSee('hanya-baca')
        ->assertDontSee('Simpan Odontogram');
});
