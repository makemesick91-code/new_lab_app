<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| DMS memo M1.1–M1.3, PR2 of 5 (rule 241 §2–§4) — both PTM signatures gate
| WRITING: the RME and "Selesai Pemeriksaan" (the odontogram is pinned in
| RmeOdontogramTwoSignatureGateTest).
|--------------------------------------------------------------------------
|
| Owner decisions of 2026-11-17 (binding): the PERSETUJUAN TINDAKAN MEDIS is
| EXECUTED only when the patient signed (front office) AND the treating doctor
| countersigned (rule 241 §1). Every clinical WRITE waits for both; "Mulai
| Pemeriksaan" stays before consent; READING permitted history stays open; the
| supervised void reopens the gate; payment never consults consent; prescriptions
| stay outside the gate. The Doctor role no longer captures the patient signature.
|
| Gate state is built ONLY through the services (sign, countersign, void), never
| through a fixture, so a fixture cannot hide a gate gap.
|
| Selected by the existing `RmeVisitConsent` critical-gate token in both runner
| variants and declared in critical_gate_mandatory_suites (CG-19). Helper prefix
| `rtsg` is unique because the critical gate loads every suite into one process.
*/

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicRoom\Models\ClinicRoom;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\ClinicVisit\Services\ClinicVisitService;
use App\Modules\Consent\Models\RmeVisitConsent;
use App\Modules\Consent\Services\RmeVisitConsentService;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\MedicalRecord\Models\ClinicalDiagnosis;
use App\Modules\MedicalRecord\Models\DiagnosisRolloutSetting;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\MedicalRecord\Models\MedicalRecordDiagnosis;
use App\Modules\MedicalRecord\Models\MedicalRecordHandwriting;
use App\Modules\MedicalRecord\Services\DiagnosisRolloutService;
use App\Modules\MedicalRecord\Services\MedicalRecordDiagnosisService;
use App\Modules\MedicalRecord\Services\MedicalRecordService;
use App\Modules\Patient\Models\Patient;
use App\Modules\PerformedTreatment\Support\RmePerformedTreatmentRefusal;
use App\Modules\Prescription\Models\RmePrescription;
use App\Support\Storage\ClinicalEvidenceStorage;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    seedAccessControl();
    Storage::fake('local');
    Storage::fake(ClinicalEvidenceStorage::diskName());

    $this->branch = Branch::factory()->create(['code' => 'TSG1', 'is_active' => true, 'is_rme_enabled' => true]);
    $this->room = ClinicRoom::factory()->create(['branch_id' => $this->branch->id, 'status' => ClinicRoom::STATUS_ACTIVE]);
    $this->doctorRecord = Doctor::factory()->withAllowedBranches([$this->branch])->create(['name' => 'drg. Dua Tanda Tangan']);
    $this->doctor = rmeMakeDoctorOnline($this->doctorRecord, $this->branch, $this->room)->fresh();

    // The front office captures the patient's signature (owner default 1).
    $this->frontOffice = tap(User::factory()->create(), fn (User $u) => rmeMakeFrontOfficeActive($u, $this->branch))->fresh();
    $this->supervisor = userWith(['view_clinic_visits', 'view_rme_consents', 'void_rme_consents']);
});

function rtsgVisit(string $status = ClinicVisit::STATUS_IN_PROGRESS): ClinicVisit
{
    $patient = Patient::factory()->create(['branch_id' => test()->branch->id, 'name' => 'Pasien Gerbang Dua']);

    return ClinicVisit::factory()->create([
        'branch_id' => test()->branch->id,
        'patient_id' => $patient->id,
        'doctor_id' => test()->doctorRecord->id,
        'clinic_room_id' => test()->room->id,
        'status' => $status,
    ])->refresh();
}

function rtsgPatientSigns(ClinicVisit $visit): RmeVisitConsent
{
    return app(RmeVisitConsentService::class)->sign($visit, test()->frontOffice, [
        'template_code' => 'PERSETUJUAN_TINDAKAN_MEDIS',
        'consenter_relationship' => 'self',
        'medical_action' => 'Penambalan gigi 36',
        'treatment_summary' => 'Penambalan',
        'documentation_consent' => '0',
        'consenter_signature' => validPodSignatureData(),
    ]);
}

function rtsgDoctorSigns(RmeVisitConsent $consent): void
{
    app(RmeVisitConsentService::class)->countersign($consent->fresh(), test()->doctor, ['doctor_signature' => validPodSignatureData()]);
}

function rtsgRecord(ClinicVisit $visit, bool $withHandwriting = false): MedicalRecord
{
    $record = MedicalRecord::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $visit->branch_id,
        'patient_id' => $visit->patient_id,
        'doctor_id' => $visit->doctor_id,
    ]);

    if ($withHandwriting) {
        MedicalRecordHandwriting::factory()->create(['medical_record_id' => $record->id, 'clinic_visit_id' => $visit->id]);
    }

    return $record;
}

/** A distinct, non-blank 10x10 RGBA page drawing. */
function rtsgDrawing(int $seed): string
{
    $raw = '';
    for ($y = 0; $y < 10; $y++) {
        $line = str_repeat("\xff\xff\xff\x00", 10);
        if ($y === $seed % 10) {
            $line = substr_replace($line, "\x00\x00\x00\xff", ($seed % 10) * 4, 4);
        }
        $raw .= "\0".$line;
    }
    $chunk = fn (string $type, string $data): string => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));

    return 'data:image/png;base64,'.base64_encode("\x89PNG\r\n\x1a\n"
        .$chunk('IHDR', pack('NNCCCCC', 10, 10, 8, 6, 0, 0, 0))
        .$chunk('IDAT', gzcompress($raw))
        .$chunk('IEND', ''));
}

/** True when $write is refused by the consent gate with the given message (substring). */
function rtsgRefused(callable $write, string $needle): bool
{
    try {
        $write();
    } catch (ValidationException $e) {
        return str_contains(implode(' ', Arr::flatten($e->errors())), $needle);
    }

    return false;
}

/*
| The predicate
*/

it('TSG-01 calls a consent EXECUTED only with both signatures, and a void reopens the gate', function () {
    $visit = rtsgVisit();
    $service = app(RmeVisitConsentService::class);

    expect($service->ptmStateFor($visit))->toBe(RmeVisitConsentService::PTM_AWAITING_PATIENT)
        ->and($service->hasExecutedConsent($visit))->toBeFalse();

    $consent = rtsgPatientSigns($visit);

    expect($service->ptmStateFor($visit))->toBe(RmeVisitConsentService::PTM_AWAITING_DOCTOR)
        ->and($service->hasValidConsent($visit))->toBeTrue()
        ->and($service->hasExecutedConsent($visit))->toBeFalse();

    rtsgDoctorSigns($consent);

    expect($service->ptmStateFor($visit))->toBe(RmeVisitConsentService::PTM_EXECUTED)
        ->and($service->hasExecutedConsent($visit))->toBeTrue();

    $service->void($consent->fresh(), $this->supervisor, 'Tindakan yang tertulis keliru.');

    expect($service->ptmStateFor($visit))->toBe(RmeVisitConsentService::PTM_AWAITING_PATIENT)
        ->and($service->hasExecutedConsent($visit))->toBeFalse();
});

/*
| RME authoring — the single authority and every writer
*/

it('TSG-02 refuses RME authoring with only the patient signature, outside and inside a transaction', function () {
    $visit = rtsgVisit();
    $consent = rtsgPatientSigns($visit);
    $service = app(RmeVisitConsentService::class);

    expect(rtsgRefused(fn () => $service->assertRmeAuthoringAllowedForPatient((int) $visit->patient_id, $this->doctor), 'belum ditandatangani dokter'))->toBeTrue()
        ->and(rtsgRefused(fn () => DB::transaction(fn () => $service->assertRmeAuthoringAllowedForPatient((int) $visit->patient_id, $this->doctor)), 'belum ditandatangani dokter'))->toBeTrue()
        ->and($service->canAuthorRmeForPatient((int) $visit->patient_id, $this->doctor))->toBeFalse();

    rtsgDoctorSigns($consent);

    expect((int) DB::transaction(fn () => $service->assertRmeAuthoringAllowedForPatient((int) $visit->patient_id, $this->doctor))->id)->toBe((int) $visit->id)
        ->and($service->canAuthorRmeForPatient((int) $visit->patient_id, $this->doctor))->toBeTrue();
});

it('TSG-03 refuses every RME writer with only the patient signature and allows it after the countersignature', function () {
    $visit = rtsgVisit();
    $consent = rtsgPatientSigns($visit);
    $this->actingAs($this->doctor);

    $records = app(MedicalRecordService::class);
    $diagnoses = app(MedicalRecordDiagnosisService::class);
    $first = ClinicalDiagnosis::factory()->create();
    DiagnosisRolloutSetting::query()->create([
        'branch_id' => $this->branch->id,
        'mode' => DiagnosisRolloutSetting::MODE_PILOT_ENFORCED,
        'reason' => 'Pilot cabang untuk uji gerbang dua tanda tangan.',
        'configured_by' => $this->supervisor->id,
    ]);

    // 1. a new sheet
    expect(rtsgRefused(fn () => $records->createDraft($visit, $this->doctor->id), 'belum ditandatangani dokter'))->toBeTrue('createDraft');
    expect(MedicalRecord::query()->where('clinic_visit_id', $visit->id)->exists())->toBeFalse();

    // A sheet that exists already (written before the gate, e.g. at deploy).
    $record = rtsgRecord($visit, withHandwriting: true);

    // 2. sheet update, 3. finalize
    expect(rtsgRefused(fn () => $records->updateDraft($record, ['notes' => 'catatan']), 'belum ditandatangani dokter'))->toBeTrue('updateDraft')
        ->and(rtsgRefused(fn () => $records->finalize($record->fresh()), 'belum ditandatangani dokter'))->toBeTrue('finalize');

    // 4. structured diagnosis, 5. the diagnosis override
    expect(rtsgRefused(fn () => $diagnoses->record($record, ['clinical_diagnosis_id' => $first->id, 'diagnosis_role' => MedicalRecordDiagnosis::ROLE_PRIMARY], $this->doctor), 'belum ditandatangani dokter'))->toBeTrue('diagnosis')
        ->and(rtsgRefused(fn () => app(DiagnosisRolloutService::class)->grantOverride($record, 'Darurat: pasien tidak sadar.', $this->doctor), 'belum ditandatangani dokter'))->toBeTrue('override');

    // 6. a handwriting page over HTTP
    $this->actingAs($this->doctor)
        ->post(route('rme.visits.medical-record.handwriting.store', [$visit, $record]), ['handwriting_data' => rtsgDrawing(1), 'page_number' => 1])
        ->assertSessionHasErrors('consent');

    // 7. performed treatments (rule 219) take the same authority
    expect(fn () => app(RmeVisitConsentService::class)->assertPerformedTreatmentAuthoringAllowed($visit, $this->doctor))
        ->toThrow(ValidationException::class, RmePerformedTreatmentRefusal::message(RmePerformedTreatmentRefusal::CONSENT_REQUIRED));

    expect(MedicalRecordDiagnosis::query()->count())->toBe(0)
        ->and($record->fresh()->status)->toBe(MedicalRecord::STATUS_DRAFT);

    rtsgDoctorSigns($consent);

    $records->updateDraft($record->fresh(), ['notes' => 'catatan']);
    $diagnoses->record($record->fresh(), ['clinical_diagnosis_id' => $first->id, 'diagnosis_role' => MedicalRecordDiagnosis::ROLE_PRIMARY], $this->doctor);
    app(DiagnosisRolloutService::class)->grantOverride($record->fresh(), 'Darurat: pasien tidak sadar.', $this->doctor);
    $this->actingAs($this->doctor)
        ->post(route('rme.visits.medical-record.handwriting.store', [$visit, $record]), ['handwriting_data' => rtsgDrawing(2), 'page_number' => 1])
        ->assertSessionHasNoErrors();
    $records->finalize($record->fresh());

    expect(app(RmeVisitConsentService::class)->assertPerformedTreatmentAuthoringAllowed($visit, $this->doctor)->id)->toBe($visit->id)
        ->and($record->fresh()->notes)->toBe('catatan')
        ->and($record->fresh()->status)->toBe(MedicalRecord::STATUS_FINAL)
        ->and(MedicalRecordDiagnosis::query()->where('medical_record_id', $record->id)->count())->toBe(1);
});

it('TSG-04 creates the first sheet over HTTP only after both signatures', function () {
    $visit = rtsgVisit();
    $consent = rtsgPatientSigns($visit);

    $this->actingAs($this->doctor)
        ->post(route('rme.visits.medical-record.store', $visit))
        ->assertSessionHasErrors('consent');

    expect(MedicalRecord::query()->where('clinic_visit_id', $visit->id)->exists())->toBeFalse();

    rtsgDoctorSigns($consent);

    $this->actingAs($this->doctor)
        ->post(route('rme.visits.medical-record.store', $visit))
        ->assertSessionHasNoErrors();

    expect(MedicalRecord::query()->where('clinic_visit_id', $visit->id)->exists())->toBeTrue();
});

it('TSG-05 refuses "Selesai Pemeriksaan" with only the patient signature, in the service and over HTTP', function () {
    $visit = rtsgVisit();
    $consent = rtsgPatientSigns($visit);

    $this->actingAs($this->doctor)
        ->post(route('rme.visits.transition', $visit), ['status' => ClinicVisit::STATUS_CASHIER_PENDING])
        ->assertSessionHasErrors(['status']);

    expect($visit->fresh()->status)->toBe(ClinicVisit::STATUS_IN_PROGRESS)
        ->and(rtsgRefused(
            fn () => app(ClinicVisitService::class)->transitionStatus($visit->fresh(), ClinicVisit::STATUS_CASHIER_PENDING, $this->doctor),
            'belum ditandatangani dokter',
        ))->toBeTrue();

    rtsgDoctorSigns($consent);

    $this->actingAs($this->doctor)
        ->post(route('rme.visits.transition', $visit), ['status' => ClinicVisit::STATUS_CASHIER_PENDING])
        ->assertSessionHasNoErrors();

    expect($visit->fresh()->status)->toBe(ClinicVisit::STATUS_CASHIER_PENDING);
});

it('TSG-06 keeps "Mulai Pemeriksaan" BEFORE consent', function () {
    $visit = rtsgVisit(ClinicVisit::STATUS_WAITING);

    $this->actingAs($this->doctor)
        ->post(route('rme.visits.transition', $visit), ['status' => ClinicVisit::STATUS_IN_PROGRESS])
        ->assertSessionHasNoErrors();

    expect($visit->fresh()->status)->toBe(ClinicVisit::STATUS_IN_PROGRESS)
        ->and(RmeVisitConsent::query()->where('clinic_visit_id', $visit->id)->exists())->toBeFalse();
});

it('TSG-07 binds Super Admin: no write before both signatures', function () {
    $visit = rtsgVisit();
    $consent = rtsgPatientSigns($visit);
    $admin = userInRole('Super Admin')->fresh();
    $service = app(RmeVisitConsentService::class);

    expect(rtsgRefused(fn () => DB::transaction(fn () => $service->assertRmeAuthoringAllowedForPatient((int) $visit->patient_id, $admin)), 'belum ditandatangani dokter'))->toBeTrue()
        ->and(rtsgRefused(fn () => app(ClinicVisitService::class)->transitionStatus($visit->fresh(), ClinicVisit::STATUS_CASHIER_PENDING, $admin), 'belum ditandatangani dokter'))->toBeTrue();

    // Super Admin cannot complete the step for the doctor either (rule 241 §1).
    expect(fn () => $service->countersign($consent->fresh(), $admin, ['doctor_signature' => validPodSignatureData()]))
        ->toThrow(AuthorizationException::class);

    rtsgDoctorSigns($consent);

    expect((int) DB::transaction(fn () => $service->assertRmeAuthoringAllowedForPatient((int) $visit->patient_id, $admin))->id)->toBe((int) $visit->id);
});

it('TSG-08 reopens the gate after a supervised void until both sign the new consent', function () {
    $visit = rtsgVisit();
    $consent = rtsgPatientSigns($visit);
    rtsgDoctorSigns($consent);
    $service = app(RmeVisitConsentService::class);
    $write = fn () => DB::transaction(fn () => $service->assertRmeAuthoringAllowedForPatient((int) $visit->patient_id, $this->doctor));

    expect((int) $write()->id)->toBe((int) $visit->id);

    $service->void($consent->fresh(), $this->supervisor, 'Tindakan yang tertulis keliru.');
    expect(rtsgRefused($write, 'Persetujuan Tindakan Medis belum ditandatangani'))->toBeTrue();

    $renewed = rtsgPatientSigns($visit->fresh());
    expect(rtsgRefused($write, 'belum ditandatangani dokter'))->toBeTrue();

    rtsgDoctorSigns($renewed);
    expect((int) $write()->id)->toBe((int) $visit->id);
});

it('TSG-09 re-decides the countersignature under the visit lock taken first (PostgreSQL)', function () {
    $visit = rtsgVisit();
    rtsgDoctorSigns(rtsgPatientSigns($visit));

    $log = [];
    Event::listen(TransactionBeginning::class, function () use (&$log): void {
        $log[] = 'BEGIN';
    });
    DB::listen(function (QueryExecuted $q) use (&$log): void {
        $log[] = strtolower($q->sql);
    });

    DB::transaction(fn () => app(RmeVisitConsentService::class)->assertRmeAuthoringAllowedForPatient((int) $visit->patient_id, $this->doctor));

    $lock = null;
    foreach ($log as $i => $sql) {
        if ($sql !== 'BEGIN' && str_contains($sql, '"trx_clinic_visits"') && str_contains($sql, 'for no key update')) {
            $lock = $i;
            break;
        }
    }

    expect($lock)->not->toBeNull();

    $readAfterLock = false;
    foreach ($log as $i => $sql) {
        if ($i > $lock && str_contains($sql, 'from "trx_rme_visit_consent_doctor_signatures"')) {
            $readAfterLock = true;
        }
    }

    expect($readAfterLock)->toBeTrue();
})->skip(fn () => DB::connection()->getDriverName() !== 'pgsql', 'Row locks are only emitted on PostgreSQL.');

/*
| Reads, prescriptions and payment stay outside the gate
*/

it('TSG-10 keeps every read open while the consent awaits the doctor', function () {
    $visit = rtsgVisit();
    rtsgPatientSigns($visit);
    rtsgRecord($visit, withHandwriting: true);

    $this->actingAs($this->doctor)->get(route('rme.visits.show', $visit))->assertOk();
    $this->actingAs($this->doctor)->get(route('rme.visits.medical-record.show', $visit))->assertOk();
    $this->actingAs($this->doctor)->get(route('rme.visits.odontogram.show', $visit))->assertOk();
});

it('TSG-11 leaves prescriptions outside the two-signature gate (rule 109 §2c, rule 226)', function () {
    $visit = rtsgVisit();
    rtsgPatientSigns($visit);

    $this->actingAs($this->doctor)
        ->post(route('rme.visits.prescription.store', $visit), [
            'prescription_date' => clinicalToday()->toDateString(),
            'patient_age_snapshot' => '40',
            'allergy_note' => 'Tidak ada',
            'pregnant_or_breastfeeding' => 'Tidak',
            'renal_function_issue' => 'Tidak',
            'prescription_canvas_data' => validPodSignatureData(),
            'doctor_signature_canvas_data' => validPodSignatureData(),
        ])
        ->assertSessionHasNoErrors();

    expect(RmePrescription::query()->where('clinic_visit_id', $visit->id)->exists())->toBeTrue();
});

it('TSG-12 keeps payment free of any consent check (AIR-RMC-11)', function () {
    foreach ([
        'app/Modules/RmeInvoice/Services/RmePaymentService.php',
        'app/Modules/RmeInvoice/Requests/CreateRmePaymentRequest.php',
    ] as $file) {
        $source = (string) file_get_contents(base_path($file));

        expect($source)->not->toContain('use App\\Modules\\Consent\\')
            ->and($source)->not->toContain('hasExecutedConsent(')
            ->and($source)->not->toContain('hasValidConsent(')
            ->and($source)->not->toContain('RmeVisitConsentService::class');
    }
});

/*
| Who captures the patient signature (owner default 1)
*/

it('TSG-13 takes the patient-signature capture away from the Doctor role', function () {
    $holders = Role::query()->whereHas('permissions', fn ($q) => $q->where('name', 'manage_rme_consents'))
        ->orderBy('name')->pluck('name')->all();

    expect($holders)->not->toContain('Doctor')
        ->and($holders)->toContain('Front Office')
        ->and($holders)->toContain('Kasir')
        ->and($holders)->toContain('Admin Klinik')
        ->and($holders)->toContain('Supervisor RME');

    $visit = rtsgVisit();

    $this->actingAs($this->doctor)->get(route('rme.visits.consent.create', $visit))->assertForbidden();
    $this->actingAs($this->frontOffice)->get(route('rme.visits.consent.create', $visit))->assertOk();
});

it('TSG-14 offers no doctor canvas on the front-office capture form', function () {
    $visit = rtsgVisit();

    $this->actingAs($this->frontOffice)
        ->get(route('rme.visits.consent.create', $visit))
        ->assertOk()
        ->assertDontSee('doctor-signature-canvas', false)
        ->assertDontSee('name="doctor_signature"', false)
        ->assertSee('consenter-signature-canvas', false);
});

/*
| The UI states (rule 135 mirror; presentation decided in controllers)
*/

it('TSG-15 shows the PTM state on the visit page: awaiting patient, awaiting doctor, complete', function () {
    $visit = rtsgVisit();

    $this->actingAs($this->doctor)->get(route('rme.visits.show', $visit))
        ->assertOk()
        ->assertSee('data-ptm-state="awaiting_patient"', false);

    $consent = rtsgPatientSigns($visit);

    $this->actingAs($this->doctor)->get(route('rme.visits.show', $visit))
        ->assertOk()
        ->assertSee('data-ptm-state="awaiting_doctor"', false)
        ->assertSee('Menunggu tanda tangan dokter')
        ->assertSee(route('rme.consents.countersign.create', $consent), false)
        // "Selesai Pemeriksaan" is not offered while the doctor's signature is missing.
        ->assertDontSee('value="'.ClinicVisit::STATUS_CASHIER_PENDING.'"', false);

    rtsgDoctorSigns($consent);

    $this->actingAs($this->doctor)->get(route('rme.visits.show', $visit))
        ->assertOk()
        ->assertSee('data-ptm-state="executed"', false)
        ->assertSee('value="'.ClinicVisit::STATUS_CASHIER_PENDING.'"', false);
});

it('TSG-16 shows an honest "menunggu tanda tangan dokter" on the RME workspace instead of a submittable create', function () {
    $visit = rtsgVisit();
    $consent = rtsgPatientSigns($visit);

    $html = $this->actingAs($this->doctor)->get(route('rme.visits.medical-record.show', $visit))->assertOk()->getContent();

    expect($html)->toContain('data-ptm-state="awaiting_doctor"')
        ->and($html)->toContain('menunggu tanda tangan dokter')
        ->and($html)->toContain(route('rme.consents.countersign.create', $consent))
        ->and($html)->not->toContain('action="'.route('rme.visits.medical-record.store', $visit).'"');

    rtsgDoctorSigns($consent);

    $after = $this->actingAs($this->doctor)->get(route('rme.visits.medical-record.show', $visit))->assertOk()->getContent();

    expect($after)->not->toContain('data-ptm-state="awaiting_doctor"');
});

it('TSG-17 shows the PTM state on the front-office queue and on Ruang Perawatan', function () {
    $awaitingPatient = rtsgVisit();
    $awaitingDoctor = rtsgVisit();
    $consent = rtsgPatientSigns($awaitingDoctor);
    $executed = rtsgVisit();
    rtsgDoctorSigns(rtsgPatientSigns($executed));

    $queue = $this->actingAs($this->frontOffice)->get(route('rme.patient-queue.index'))->assertOk()->getContent();

    expect(substr_count($queue, 'data-ptm-state="awaiting_patient"'))->toBe(1)
        ->and(substr_count($queue, 'data-ptm-state="awaiting_doctor"'))->toBe(1)
        ->and(substr_count($queue, 'data-ptm-state="executed"'))->toBe(1);

    $worklist = $this->actingAs($this->doctor)->get(route('rme.treatment-room-worklist.index'))->assertOk()->getContent();

    expect(substr_count($worklist, 'data-ptm-state="awaiting_doctor"'))->toBe(1)
        ->and($worklist)->toContain(route('rme.consents.countersign.create', $consent))
        ->and(substr_count($worklist, 'data-testid="worklist-countersign-ptm"'))->toBe(1);
});

it('TSG-18 tells a doctor who captured the patient signature that a supervised void is needed', function () {
    $visit = rtsgVisit();
    // A doctor account that still holds the capture permission at runtime
    // (granted before the Doctor role lost it).
    $this->doctor->givePermissionTo('manage_rme_consents');
    $consent = app(RmeVisitConsentService::class)->sign($visit, $this->doctor->fresh(), [
        'template_code' => 'PERSETUJUAN_TINDAKAN_MEDIS',
        'consenter_relationship' => 'self',
        'medical_action' => 'Penambalan gigi 36',
        'documentation_consent' => '1',
        'consenter_signature' => validPodSignatureData(),
    ]);

    $worklist = $this->actingAs($this->doctor->fresh())->get(route('rme.treatment-room-worklist.index'))->assertOk()->getContent();

    expect($worklist)->not->toContain('data-testid="worklist-countersign-ptm"')
        ->and($worklist)->toContain('data-testid="worklist-countersign-self-captured"');

    $this->actingAs($this->doctor->fresh())->get(route('rme.consents.show', $consent))
        ->assertOk()
        ->assertSee('data-testid="consent-countersign-self-captured"', false);
});

/*
| Compatibility: an encounter open at deploy
*/

it('TSG-19 treats an in-progress visit with only a patient consent as awaiting the doctor, keeping what is written', function () {
    // The state a visit can be in when PR2 is deployed: a patient consent and
    // RM content written under the old one-signature gate.
    $visit = rtsgVisit();
    rtsgPatientSigns($visit);
    $record = rtsgRecord($visit, withHandwriting: true);
    $record->forceFill(['notes' => 'ditulis sebelum gerbang'])->save();

    expect(app(RmeVisitConsentService::class)->ptmStateFor($visit))->toBe(RmeVisitConsentService::PTM_AWAITING_DOCTOR)
        ->and($record->fresh()->notes)->toBe('ditulis sebelum gerbang');

    // Reading it stays open; writing more waits for the doctor's signature.
    $this->actingAs($this->doctor)->get(route('rme.visits.medical-record.show', $visit))
        ->assertOk()
        ->assertSee('data-ptm-state="awaiting_doctor"', false);

    expect(rtsgRefused(fn () => app(MedicalRecordService::class)->updateDraft($record->fresh(), ['notes' => 'tambahan']), 'belum ditandatangani dokter'))->toBeTrue()
        ->and($record->fresh()->notes)->toBe('ditulis sebelum gerbang');
});
