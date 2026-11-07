<?php

/**
 * DMS-RME-REVISION-PROGRAM-8C PR2 (rule 228 §8–§12) — prescription and consent
 * document integrity.
 *
 * Owner decisions 2026-11-07:
 *
 *  I1 OD-8A-1 (after the reviewer's combined HIGH) — LOCK AFTER COMPLETION: once
 *     the visit is completed or cancelled the prescription is read-only.
 *  I2 OD-8A-2 — every create/update writes one audit row (ids, actor, changed
 *     field names; never a value, never patient data).
 *  I3 P8R-S5 — the canvas payload and the decoded pixel size are bounded.
 *  I4 OD-6-2 — the consent signature stream is served `no-store`.
 *  I5 D3-NEW-2 — only the account linked to the prescription's own doctor saves
 *     the doctor-signature canvas; other clinical roles keep content authoring.
 *  I6 D3-NEW-6 — the uncalled, RME-wide MedicalRecordService::paginate() is gone.
 */

use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicRoom\Models\ClinicRoom;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Consent\Models\RmeVisitConsent;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\LabOrder\Models\AuditLog;
use App\Modules\MedicalRecord\Services\MedicalRecordService;
use App\Modules\Patient\Models\Patient;
use App\Modules\Prescription\Models\RmePrescription;
use App\Modules\Prescription\Services\PrescriptionCanvasDecoder;
use App\Modules\Prescription\Services\RmePrescriptionService;
use App\Support\Storage\ClinicalEvidenceStorage;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    seedAccessControl();
    Storage::fake(ClinicalEvidenceStorage::diskName());

    $this->branch = Branch::factory()->create(['is_active' => true, 'is_rme_enabled' => true]);
    $this->room = ClinicRoom::factory()->create(['branch_id' => $this->branch->id, 'status' => ClinicRoom::STATUS_ACTIVE]);
    $this->doctorRecord = Doctor::factory()->withAllowedBranches([$this->branch])->create(['name' => 'drg. Penanda Tangan']);
    $this->namedDoctor = rmeMakeDoctorOnline($this->doctorRecord, $this->branch, $this->room);
    $this->author = rmeClinicalAuthor();
});

function priVisit(object $t, string $status = ClinicVisit::STATUS_IN_PROGRESS): ClinicVisit
{
    $patient = Patient::factory()->create([
        'branch_id' => $t->branch->id,
        'name' => 'Pasien Rahasia Integritas',
        'date_of_birth' => now()->subYears(41)->toDateString(),
    ]);

    return ClinicVisit::factory()->create([
        'branch_id' => $t->branch->id,
        'patient_id' => $patient->id,
        'doctor_id' => $t->doctorRecord->id,
        'clinic_room_id' => $t->room->id,
        'status' => $status,
    ]);
}

function priPrescription(ClinicVisit $visit): RmePrescription
{
    return RmePrescription::factory()->withStoredCanvases()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $visit->branch_id,
        'patient_id' => $visit->patient_id,
        'doctor_id' => $visit->doctor_id,
        'allergy_note' => 'ALERGI ASLI',
    ]);
}

function priForm(array $overrides = []): array
{
    return array_merge([
        'prescription_date' => clinicalToday()->toDateString(),
        'patient_age_snapshot' => '41',
        'allergy_note' => 'Penisilin',
        'pregnant_or_breastfeeding' => 'Tidak',
        'renal_function_issue' => 'Tidak',
        'prescription_canvas_data' => validPodSignatureData(),
        'doctor_signature_canvas_data' => validPodSignatureData(),
    ], $overrides);
}

/** A PNG data URI whose IHDR declares the given size (no pixel data needed to be refused). */
function priOversizedPng(int $width, int $height): string
{
    $ihdr = pack('NNCCCCC', $width, $height, 8, 6, 0, 0, 0);
    $chunk = pack('N', strlen($ihdr)).'IHDR'.$ihdr.pack('N', crc32('IHDR'.$ihdr));
    $idat = gzcompress(str_repeat("\0", 64));
    $idatChunk = pack('N', strlen($idat)).'IDAT'.$idat.pack('N', crc32('IDAT'.$idat));
    $iend = pack('N', 0).'IEND'.pack('N', crc32('IEND'));

    return 'data:image/png;base64,'.base64_encode("\x89PNG\r\n\x1a\n".$chunk.$idatChunk.$iend);
}

/*
|--------------------------------------------------------------------------
| I1 — lock after completion (OD-8A-1)
|--------------------------------------------------------------------------
*/

it('I1-01 refuses updating the prescription of a completed visit and leaves it unchanged', function () {
    $visit = priVisit($this, ClinicVisit::STATUS_COMPLETED);
    $prescription = priPrescription($visit);

    actingAs($this->author)
        ->patch(route('rme.prescriptions.update', $prescription), priForm(['allergy_note' => 'DIUBAH SETELAH SELESAI', 'doctor_signature_canvas_data' => null]))
        ->assertForbidden();

    expect($prescription->fresh()->allergy_note)->toBe('ALERGI ASLI');
});

it('I1-02 refuses creating a prescription on a cancelled visit', function () {
    $visit = priVisit($this, ClinicVisit::STATUS_CANCELLED);

    actingAs($this->author)
        ->post(route('rme.visits.prescription.store', $visit), priForm(['doctor_signature_canvas_data' => null]))
        ->assertForbidden();

    expect(RmePrescription::query()->where('clinic_visit_id', $visit->id)->exists())->toBeFalse();
});

it('I1-03 refuses the same amendment at the service, under the visit row lock', function () {
    $visit = priVisit($this, ClinicVisit::STATUS_COMPLETED);
    $prescription = priPrescription($visit);

    expect(fn () => app(RmePrescriptionService::class)->update($prescription, priForm(['doctor_signature_canvas_data' => null]), $this->author))
        ->toThrow(ValidationException::class);

    expect($prescription->fresh()->allergy_note)->toBe('ALERGI ASLI');
});

it('I1-04 still allows amending the prescription while the visit waits at the cashier', function () {
    $visit = priVisit($this, ClinicVisit::STATUS_CASHIER_PENDING);
    $prescription = priPrescription($visit);

    actingAs($this->author)
        ->patch(route('rme.prescriptions.update', $prescription), priForm(['allergy_note' => 'Revisi sebelum bayar', 'doctor_signature_canvas_data' => null]))
        ->assertRedirect(route('rme.visits.prescription.show', $visit));

    expect($prescription->fresh()->allergy_note)->toBe('Revisi sebelum bayar');
});

it('I1-05 keeps a completed visit\'s prescription readable', function () {
    $visit = priVisit($this, ClinicVisit::STATUS_COMPLETED);
    priPrescription($visit);

    actingAs($this->author)
        ->get(route('rme.visits.prescription.show', $visit))
        ->assertOk();
});

/*
|--------------------------------------------------------------------------
| I2 — audit trail (OD-8A-2)
|--------------------------------------------------------------------------
*/

it('I2-01 writes one audit row on create, attributed to the actor, without values or patient data', function () {
    $visit = priVisit($this);

    actingAs($this->namedDoctor)
        ->post(route('rme.visits.prescription.store', $visit), priForm())
        ->assertRedirect();

    $prescription = RmePrescription::query()->where('clinic_visit_id', $visit->id)->firstOrFail();
    $rows = AuditLog::query()->where('entity_type', 'trx_rme_prescriptions')->where('entity_id', $prescription->id)->get();

    expect($rows)->toHaveCount(1)
        ->and($rows->first()->action)->toBe('RME_PRESCRIPTION_CREATED')
        ->and((int) $rows->first()->performed_by)->toBe($this->namedDoctor->id);

    $payload = json_encode($rows->first()->new_values);
    expect($payload)->not->toContain('Pasien Rahasia Integritas')
        ->and($payload)->not->toContain('Penisilin')
        ->and($payload)->toContain('doctor_signature_canvas');
});

it('I2-02 writes one audit row per update naming only the changed fields', function () {
    $visit = priVisit($this);
    $prescription = priPrescription($visit);

    actingAs($this->author)
        ->patch(route('rme.prescriptions.update', $prescription), priForm([
            'allergy_note' => 'Alergi baru',
            'prescription_canvas_data' => null,
            'doctor_signature_canvas_data' => null,
        ]))
        ->assertRedirect();

    $row = AuditLog::query()->where('entity_type', 'trx_rme_prescriptions')->where('action', 'RME_PRESCRIPTION_UPDATED')->sole();

    expect($row->new_values['changed_fields'])->toContain('allergy_note')
        ->and($row->new_values['changed_fields'])->not->toContain('updated_by')
        ->and(json_encode($row->new_values))->not->toContain('Alergi baru')
        ->and((int) $row->performed_by)->toBe($this->author->id);
});

it('I2-03 writes no audit row for a refused write', function () {
    $visit = priVisit($this, ClinicVisit::STATUS_COMPLETED);
    $prescription = priPrescription($visit);

    actingAs($this->author)->patch(route('rme.prescriptions.update', $prescription), priForm(['doctor_signature_canvas_data' => null]));

    expect(AuditLog::query()->where('entity_type', 'trx_rme_prescriptions')->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| I3 — canvas bounds (P8R-S5)
|--------------------------------------------------------------------------
*/

it('I3-01 refuses an R/ canvas payload longer than the bound before decoding it', function () {
    $visit = priVisit($this);

    actingAs($this->namedDoctor)
        ->post(route('rme.visits.prescription.store', $visit), priForm([
            'prescription_canvas_data' => 'data:image/png;base64,'.str_repeat('A', 3_600_000),
        ]))
        // The size message, not "invalid image": the bound refuses it before the
        // payload is even decoded.
        ->assertSessionHasErrors(['prescription_canvas_data' => 'Ukuran gambar resep melebihi batas yang diizinkan.']);

    expect(RmePrescription::query()->where('clinic_visit_id', $visit->id)->exists())->toBeFalse();
});

it('I3-01b applies the same payload bound at the decoder for a caller that skips the request', function () {
    expect(fn () => app(PrescriptionCanvasDecoder::class)->decode(
        'data:image/png;base64,'.str_repeat('A', PrescriptionCanvasDecoder::MAX_PAYLOAD_LENGTH),
        'prescription_canvas_data',
    ))->toThrow(ValidationException::class, 'Ukuran gambar melebihi batas yang diizinkan.');
});

it('I3-02 refuses a PNG whose header declares more pixels than the cap', function () {
    expect(fn () => app(PrescriptionCanvasDecoder::class)->decode(priOversizedPng(20000, 20000), 'prescription_canvas_data'))
        ->toThrow(ValidationException::class, 'Ukuran gambar melebihi batas yang diizinkan.');

    expect(fn () => app(PrescriptionCanvasDecoder::class)->decode(priOversizedPng(5000, 10), 'prescription_canvas_data'))
        ->toThrow(ValidationException::class, 'Ukuran gambar melebihi batas yang diizinkan.');
});

it('I3-03 still accepts the canvases the application draws', function () {
    expect(app(PrescriptionCanvasDecoder::class)->decode(validPodSignatureData(), 'doctor_signature_canvas_data'))
        ->toBeString();
});

/*
|--------------------------------------------------------------------------
| I4 — consent signature caching (OD-6-2)
|--------------------------------------------------------------------------
*/

it('I4-01 serves the consent signature image with no-store', function () {
    Storage::fake('local');
    $visit = priVisit($this);
    $consent = RmeVisitConsent::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $visit->branch_id,
        'patient_id' => $visit->patient_id,
        'doctor_id' => $visit->doctor_id,
    ]);
    Storage::disk('local')->put($consent->consenter_signature_path, base64_decode(substr(validPodSignatureData(), strlen('data:image/png;base64,'))));

    $response = actingAs(userInRole('Super Admin'))
        ->get(route('rme.consents.signature', [$consent, 'consenter']))
        ->assertOk();

    expect((string) $response->headers->get('Cache-Control'))->toContain('no-store');
});

/*
|--------------------------------------------------------------------------
| I5 — only the named doctor signs (D3-NEW-2)
|--------------------------------------------------------------------------
*/

it('I5-01 lets the prescription\'s own doctor create it with the signature', function () {
    $visit = priVisit($this);

    actingAs($this->namedDoctor)
        ->post(route('rme.visits.prescription.store', $visit), priForm())
        ->assertRedirect(route('rme.visits.prescription.show', $visit));

    expect(RmePrescription::query()->where('clinic_visit_id', $visit->id)->value('doctor_signature_canvas_path'))->not->toBeNull();
});

it('I5-02 refuses another clinician saving the doctor signature and writes nothing', function () {
    $visit = priVisit($this);
    $objects = count(Storage::disk(ClinicalEvidenceStorage::diskName())->allFiles());

    actingAs($this->author)
        ->post(route('rme.visits.prescription.store', $visit), priForm())
        ->assertSessionHasErrors('doctor_signature_canvas_data');

    expect(RmePrescription::query()->where('clinic_visit_id', $visit->id)->exists())->toBeFalse()
        ->and(count(Storage::disk(ClinicalEvidenceStorage::diskName())->allFiles()))->toBe($objects);
});

it('I5-03 lets another clinician author the content without a signature', function () {
    $visit = priVisit($this);

    actingAs($this->author)
        ->post(route('rme.visits.prescription.store', $visit), priForm(['doctor_signature_canvas_data' => null]))
        ->assertRedirect(route('rme.visits.prescription.show', $visit));

    $prescription = RmePrescription::query()->where('clinic_visit_id', $visit->id)->firstOrFail();
    expect($prescription->doctor_signature_canvas_path)->toBeNull()
        ->and($prescription->prescription_canvas_path)->not->toBeNull();
});

it('I5-04 refuses another clinician replacing the signature on update and keeps the old one', function () {
    $visit = priVisit($this);
    $prescription = priPrescription($visit);
    $signature = $prescription->doctor_signature_canvas_path;

    actingAs($this->author)
        ->patch(route('rme.prescriptions.update', $prescription), priForm(['prescription_canvas_data' => null]))
        ->assertSessionHasErrors('doctor_signature_canvas_data');

    expect($prescription->fresh()->doctor_signature_canvas_path)->toBe($signature);
});

it('I5-05 refuses Super Admin signing for a doctor it is not (the rule lives in the service)', function () {
    $visit = priVisit($this);

    expect(fn () => app(RmePrescriptionService::class)->create($visit, priForm(), userInRole('Super Admin')))
        ->toThrow(ValidationException::class);
});

it('I5-06 lets the named doctor sign later on update', function () {
    $visit = priVisit($this);
    actingAs($this->author)->post(route('rme.visits.prescription.store', $visit), priForm(['doctor_signature_canvas_data' => null]));
    $prescription = RmePrescription::query()->where('clinic_visit_id', $visit->id)->firstOrFail();

    actingAs($this->namedDoctor)
        ->patch(route('rme.prescriptions.update', $prescription), priForm(['prescription_canvas_data' => null]))
        ->assertRedirect(route('rme.visits.prescription.show', $visit));

    expect($prescription->fresh()->doctor_signature_canvas_path)->not->toBeNull();
});

it('I5-07 offers the signature canvas only to the named doctor', function () {
    $visit = priVisit($this);

    actingAs($this->namedDoctor)->get(route('rme.visits.prescription.show', $visit))
        ->assertOk()->assertSee('id="signature-canvas"', false);

    actingAs($this->author)->get(route('rme.visits.prescription.show', $visit))
        ->assertOk()->assertDontSee('id="signature-canvas"', false);
});

/*
|--------------------------------------------------------------------------
| I6 — the dead RME-wide paginate path is removed (D3-NEW-6)
|--------------------------------------------------------------------------
*/

it('I6-01 no longer exposes the uncalled RME-wide MedicalRecordService::paginate()', function () {
    expect(method_exists(MedicalRecordService::class, 'paginate'))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| R — fixes for the independent review (session 3ec032fd, round 1)
|--------------------------------------------------------------------------
*/

it('R-01 (F3) re-decides the authority on the locked visit row on create, so a concurrent room move is not missed', function () {
    $visit = priVisit($this);
    $otherRoom = ClinicRoom::factory()->create(['branch_id' => $this->branch->id, 'status' => ClinicRoom::STATUS_ACTIVE]);

    // Another request moved the patient to another room after this one loaded the visit.
    ClinicVisit::query()->whereKey($visit->id)->update(['clinic_room_id' => $otherRoom->id]);

    expect(fn () => app(RmePrescriptionService::class)->create($visit, priForm(), $this->namedDoctor))
        ->toThrow(AuthorizationException::class);

    expect(RmePrescription::query()->where('clinic_visit_id', $visit->id)->exists())->toBeFalse();
});

it('R-02 (F3) re-decides the authority on the locked visit row on update', function () {
    $visit = priVisit($this);
    $prescription = priPrescription($visit)->load('clinicVisit');
    $otherRoom = ClinicRoom::factory()->create(['branch_id' => $this->branch->id, 'status' => ClinicRoom::STATUS_ACTIVE]);

    ClinicVisit::query()->whereKey($visit->id)->update(['clinic_room_id' => $otherRoom->id]);

    expect(fn () => app(RmePrescriptionService::class)->update($prescription, priForm(['doctor_signature_canvas_data' => null]), $this->namedDoctor))
        ->toThrow(AuthorizationException::class);

    expect($prescription->fresh()->allergy_note)->toBe('ALERGI ASLI');
});

it('R-03 (F4) keeps the pixel cap close to the canvases the application draws', function () {
    $decoder = app(PrescriptionCanvasDecoder::class);

    expect(fn () => $decoder->decode(priOversizedPng(1100, 1000), 'prescription_canvas_data'))
        ->toThrow(ValidationException::class, 'Ukuran gambar melebihi batas yang diizinkan.');

    // Positive controls: the prescription R/ canvas and the consent signature canvas.
    expect($decoder->decode(priOversizedPng(900, 480), 'prescription_canvas_data'))->toBeString()
        ->and($decoder->decode(priOversizedPng(700, 220), 'consenter_signature_data'))->toBeString();
});

it('R-04 (F5) bounds the signature by its own limit inside the decoder, not only in the request', function () {
    $visit = priVisit($this);

    expect(fn () => app(RmePrescriptionService::class)->create($visit, priForm([
        'doctor_signature_canvas_data' => 'data:image/png;base64,'.str_repeat('A', PrescriptionCanvasDecoder::MAX_SIGNATURE_PAYLOAD_LENGTH + 1),
    ]), $this->namedDoctor))->toThrow(ValidationException::class, 'Ukuran gambar melebihi batas yang diizinkan.');

    // A caller may only tighten the decoder bound, never loosen it.
    expect(fn () => app(PrescriptionCanvasDecoder::class)->decode(
        'data:image/png;base64,'.str_repeat('A', PrescriptionCanvasDecoder::MAX_PAYLOAD_LENGTH),
        'prescription_canvas_data',
        maxLength: PrescriptionCanvasDecoder::MAX_PAYLOAD_LENGTH * 10,
    ))->toThrow(ValidationException::class, 'Ukuran gambar melebihi batas yang diizinkan.');
});
