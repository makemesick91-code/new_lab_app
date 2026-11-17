<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| DMS-RME-COMPREHENSIVE-CLOSURE-1 memo M2.1/M2.2 — PR A1b (rule 243 §2.5, §2.6)
|--------------------------------------------------------------------------
| With `rme.doctor_encounter_scope` ON:
|  - the sheet rule of A1 (a non-book sheet is written only by the doctor of its
|    own visit, while in service) is re-asserted INSIDE the services that write
|    it — MedicalRecordService (updateDraft, finalize), MedicalRecordDiagnosisService
|    (record, makePrimary, remove) and DiagnosisRolloutService (grantOverride) —
|    so a direct call or a bypassed policy is refused with the same messages;
|  - the visit page's "Riwayat Kunjungan Pasien", the workspace history and the
|    legacy timeline built from them list only what the doctor may read, and the
|    doctor-access panel shows a doctor viewer nothing assignment-based;
|  - the prior-visit picker answers 404 for a patient out of scope.
| With the flag OFF everything is as before (parity tests below).
|
| Selected by the existing `DoctorPatientScope` critical-gate token in both
| runner variants; declared in critical_gate_mandatory_suites. Helper prefix
| `dpsh` is unique (the critical gate loads every suite into one process).
*/

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\Clinic\Models\Clinic;
use App\Modules\ClinicRoom\Models\ClinicRoom;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\MedicalRecord\Models\ClinicalDiagnosis;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\MedicalRecord\Models\MedicalRecordDiagnosis;
use App\Modules\MedicalRecord\Models\MedicalRecordHandwritingPage;
use App\Modules\MedicalRecord\Services\DiagnosisRolloutService;
use App\Modules\MedicalRecord\Services\MedicalRecordDiagnosisService;
use App\Modules\MedicalRecord\Services\MedicalRecordService;
use App\Modules\Patient\Models\Patient;
use App\Modules\RME\Models\PatientDoctorAssignment;
use App\Support\Storage\ClinicalEvidenceStorage;
use Carbon\Carbon;
use Database\Seeders\BranchSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

const DPSH_FLAG = 'rme.doctor_encounter_scope';

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-11-17 02:00:00', 'UTC')); // 10:00 WITA

    test()->seed(BranchSeeder::class);
    seedAccessControl();
    Storage::fake(ClinicalEvidenceStorage::diskName());
    Storage::fake('public');

    test()->dpshBranch = Branch::factory()->create(['code' => 'SH01', 'is_rme_enabled' => true]);
    $roomA = ClinicRoom::factory()->create(['branch_id' => test()->dpshBranch->id, 'status' => ClinicRoom::STATUS_ACTIVE]);
    $roomB = ClinicRoom::factory()->create(['branch_id' => test()->dpshBranch->id, 'status' => ClinicRoom::STATUS_ACTIVE]);
    test()->dpshRoom = $roomA;
    $clinic = Clinic::factory()->create();

    // One doctor per room; B's visits are created in A's room so that, from A's
    // side, the rule 138 room scope admits them and only the encounter scope decides.
    foreach (['A' => $roomA, 'B' => $roomB] as $name => $room) {
        $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'branch_id' => test()->dpshBranch->id, 'name' => 'drg. Dokter '.$name.' Uji']);
        $user = User::factory()->create()->assignRole('Doctor');
        $doctor->update(['user_id' => $user->id]);
        $doctor->branches()->sync([(int) test()->dpshBranch->id]);
        rmeMakeDoctorOnline($doctor, test()->dpshBranch, $room, $user);
        $user->givePermissionTo(['view_clinic_visits', 'manage_clinic_visits']);

        test()->{'dpshDoctor'.$name} = $doctor->refresh();
        test()->{'dpshUser'.$name} = $user->refresh();
    }

    test()->dpshPatient = Patient::factory()->create(['branch_id' => test()->dpshBranch->id]);
});

afterEach(function () {
    Carbon::setTestNow();
});

function dpshFlag(bool $on): void
{
    $flags = config('feature_flags.flags', []);
    $flags[DPSH_FLAG]['default'] = $on;
    $flags[DPSH_FLAG]['env_value'] = null;
    config()->set('feature_flags.flags', $flags);
}

function dpshVisit(Doctor $doctor, string $status, array $overrides = []): ClinicVisit
{
    return ClinicVisit::factory()->create(array_merge([
        'branch_id' => test()->dpshBranch->id,
        'patient_id' => test()->dpshPatient->id,
        'doctor_id' => $doctor->id,
        'clinic_room_id' => test()->dpshRoom->id,
        'status' => $status,
        'visit_date' => '2026-11-17',
    ], $overrides))->refresh();
}

function dpshCompleted(Doctor $doctor, string $completedAtUtc): ClinicVisit
{
    return dpshVisit($doctor, ClinicVisit::STATUS_COMPLETED, [
        'completed_at' => $completedAtUtc,
        'visit_date' => substr($completedAtUtc, 0, 10),
    ]);
}

function dpshSheet(ClinicVisit $visit, ?MedicalRecord $book = null): MedicalRecord
{
    return MedicalRecord::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $visit->branch_id,
        'patient_id' => $visit->patient_id,
        'doctor_id' => $visit->doctor_id,
        'canonical_visit_id' => $book?->clinic_visit_id ?? $visit->id,
    ])->refresh();
}

/** The book on B's first visit, B's sheet last month, A's live consented encounter with its own sheet. */
function dpshFixture(): array
{
    $bookVisit = dpshCompleted(test()->dpshDoctorB, '2025-01-01 03:00:00');
    $book = dpshSheet($bookVisit);
    $bVisit = dpshCompleted(test()->dpshDoctorB, '2026-10-01 03:00:00');
    $bSheet = dpshSheet($bVisit, $book);
    $aVisit = dpshVisit(test()->dpshDoctorA, ClinicVisit::STATUS_IN_PROGRESS);
    rmeSignedConsentFor($aVisit);
    $aSheet = dpshSheet($aVisit, $book);

    return compact('bookVisit', 'book', 'bVisit', 'bSheet', 'aVisit', 'aSheet');
}

function dpshDiagnosis(MedicalRecord $sheet, string $role = MedicalRecordDiagnosis::ROLE_SECONDARY): MedicalRecordDiagnosis
{
    return MedicalRecordDiagnosis::query()->create([
        'medical_record_id' => $sheet->id,
        'clinic_visit_id' => $sheet->clinic_visit_id,
        'branch_id' => $sheet->branch_id,
        'clinical_diagnosis_id' => ClinicalDiagnosis::factory()->create()->id,
        'diagnosis_role' => $role,
        'diagnosed_by' => test()->dpshUserB->id,
        'diagnosed_at' => now(),
        'created_by' => test()->dpshUserB->id,
    ]);
}

/** Run $write and return the AuthorizationException message it threw, or null. */
function dpshRefusal(Closure $write): ?string
{
    try {
        $write();
    } catch (AuthorizationException $refused) {
        return $refused->getMessage();
    }

    return null;
}

/*
|--------------------------------------------------------------------------
| 1 — the sheet rule binds inside the services (policy bypassed)
|--------------------------------------------------------------------------
*/

it('refuses a direct service write on another doctor\'s sheet, past any policy', function (string $write) {
    dpshFlag(true);
    $f = dpshFixture();
    $a = test()->dpshUserA;
    $this->actingAs($a);
    Gate::before(fn () => true);
    $diagnosis = dpshDiagnosis($f['bSheet']);

    $message = dpshRefusal(match ($write) {
        'updateDraft' => fn () => app(MedicalRecordService::class)->updateDraft($f['bSheet'], ['notes' => 'oleh dokter A'], $a),
        'finalize' => fn () => app(MedicalRecordService::class)->finalize($f['bSheet'], $a),
        'record diagnosis' => fn () => app(MedicalRecordDiagnosisService::class)->record($f['bSheet'], [
            'clinical_diagnosis_id' => ClinicalDiagnosis::factory()->create()->id,
            'diagnosis_role' => MedicalRecordDiagnosis::ROLE_SECONDARY,
        ], $a),
        'make primary' => fn () => app(MedicalRecordDiagnosisService::class)->makePrimary($f['bSheet'], $diagnosis, $a),
        'remove diagnosis' => fn () => app(MedicalRecordDiagnosisService::class)->remove($f['bSheet'], $diagnosis, $a),
        'grant override' => fn () => app(DiagnosisRolloutService::class)->grantOverride($f['bSheet'], 'Darurat: pasien kesakitan berat.', $a),
    });

    expect($message)->toContain('ditangani dokter lain')
        ->and($f['bSheet']->fresh()->notes)->toBeNull()
        ->and($f['bSheet']->fresh()->status)->not->toBe(MedicalRecord::STATUS_FINAL)
        ->and(MedicalRecordDiagnosis::query()->where('medical_record_id', $f['bSheet']->id)->count())->toBe(1)
        ->and($diagnosis->fresh())->not->toBeNull()
        ->and($diagnosis->fresh()->diagnosis_role)->toBe(MedicalRecordDiagnosis::ROLE_SECONDARY);
})->with(['updateDraft', 'finalize', 'record diagnosis', 'make primary', 'remove diagnosis', 'grant override']);

it('refuses a direct service write on the doctor\'s own sheet out of service', function () {
    dpshFlag(true);
    $f = dpshFixture();
    $a = test()->dpshUserA;
    $this->actingAs($a);
    $aOld = dpshSheet(dpshCompleted(test()->dpshDoctorA, '2025-06-01 03:00:00'), $f['book']);

    $message = dpshRefusal(fn () => app(MedicalRecordService::class)->updateDraft($aOld, ['notes' => 'ubah lembar lama'], $a));

    expect($message)->toContain('sudah selesai dilayani')
        ->and($aOld->fresh()->notes)->toBeNull();
});

it('still lets the doctor write their own in-service sheet through the services', function () {
    dpshFlag(true);
    $f = dpshFixture();
    $a = test()->dpshUserA;
    $this->actingAs($a);

    app(MedicalRecordService::class)->updateDraft($f['aSheet'], ['notes' => 'pemeriksaan hari ini'], $a);
    $diagnosis = app(MedicalRecordDiagnosisService::class)->record($f['aSheet'], [
        'clinical_diagnosis_id' => ClinicalDiagnosis::factory()->create()->id,
        'diagnosis_role' => MedicalRecordDiagnosis::ROLE_PRIMARY,
    ], $a);

    expect($f['aSheet']->fresh()->notes)->toBe('pemeriksaan hari ini')
        ->and($diagnosis->medical_record_id)->toBe($f['aSheet']->id);
});

it('keeps co-authoring another doctor\'s sheet through the services with the flag OFF', function () {
    dpshFlag(false);
    $f = dpshFixture();
    $a = test()->dpshUserA;
    $this->actingAs($a);

    app(MedicalRecordService::class)->updateDraft($f['bSheet'], ['notes' => 'ko-penulisan'], $a);

    expect($f['bSheet']->fresh()->notes)->toBe('ko-penulisan');
});

/*
|--------------------------------------------------------------------------
| 2 — histories, the timeline and the access panel follow the scope
|--------------------------------------------------------------------------
*/

it('lists on the visit page only the visits the doctor may read, and no access panel', function () {
    dpshFlag(true);
    $a = test()->dpshUserA;
    $own = dpshCompleted(test()->dpshDoctorA, '2025-06-01 03:00:00'); // OWN mode: no service today
    $bVisit = dpshCompleted(test()->dpshDoctorB, '2026-10-01 03:00:00');
    PatientDoctorAssignment::factory()->create([
        'patient_id' => test()->dpshPatient->id,
        'doctor_id' => test()->dpshDoctorB->id,
        'unassigned_at' => null,
    ]);

    $html = $this->actingAs($a)->get(route('rme.visits.show', $own))->assertOk()->getContent();

    expect($html)->toContain($own->visit_number)
        ->and($html)->not->toContain($bVisit->visit_number)
        ->and($html)->not->toContain(test()->dpshDoctorB->name);
});

it('keeps the full history and the access panel on the visit page with the flag OFF', function () {
    dpshFlag(false);
    $a = test()->dpshUserA;
    $own = dpshCompleted(test()->dpshDoctorA, '2025-06-01 03:00:00');
    $bVisit = dpshCompleted(test()->dpshDoctorB, '2026-10-01 03:00:00');

    $html = $this->actingAs($a)->get(route('rme.visits.show', $own))->assertOk()->getContent();

    expect($html)->toContain($bVisit->visit_number)
        ->and($html)->toContain(test()->dpshDoctorB->name);
});

it('keeps another doctor\'s pre-exam visit out of the workspace history inside service', function () {
    dpshFlag(true);
    $f = dpshFixture();
    $a = test()->dpshUserA;
    $bWaiting = dpshVisit(test()->dpshDoctorB, ClinicVisit::STATUS_WAITING, ['visit_date' => '2026-11-17']);

    $html = $this->followingRedirects()
        ->actingAs($a)
        ->get(route('rme.visits.medical-record.show', $f['aVisit']))
        ->assertOk()
        ->getContent();

    expect($html)->toContain($f['bVisit']->visit_number)
        ->and($html)->not->toContain($bWaiting->visit_number);
});

it('answers the prior-visit picker with 404 for a patient out of scope, 403 with the flag OFF', function () {
    $stranger = Patient::factory()->create(['branch_id' => test()->dpshBranch->id]);
    dpshVisit(test()->dpshDoctorB, ClinicVisit::STATUS_COMPLETED, ['patient_id' => $stranger->id, 'completed_at' => '2026-10-01 03:00:00']);
    $a = test()->dpshUserA;

    dpshFlag(true);
    $this->actingAs($a)->getJson(route('rme.visits.patient-options', ['patient_id' => $stranger->id]))->assertNotFound();

    dpshFlag(false);
    $this->actingAs($a)->getJson(route('rme.visits.patient-options', ['patient_id' => $stranger->id]))->assertForbidden();
});

/*
|--------------------------------------------------------------------------
| 3 — review of A1b: the workspace controls, the book's typed fields, the
|     in-place page save, the picker, the explicit actor, exempt roles
|--------------------------------------------------------------------------
*/

function dpshPage(MedicalRecord $sheet, int $number, Doctor $author): MedicalRecordHandwritingPage
{
    return MedicalRecordHandwritingPage::query()->create([
        'medical_record_id' => $sheet->id,
        'clinic_visit_id' => $sheet->clinic_visit_id,
        'branch_id' => $sheet->branch_id,
        'doctor_id' => $author->id,
        'page_number' => $number,
        'handwriting_path' => sprintf('rme/handwriting/dpsh-%d-%d.png', $sheet->id, $number),
        'handwriting_hash' => str_repeat('f', 64),
        'saved_at' => now(),
    ]);
}

it('renders the book editor and the add-sheet control for the doctor\'s own consented encounter', function (bool $on) {
    dpshFlag($on);
    $a = test()->dpshUserA;
    $bookVisit = dpshCompleted(test()->dpshDoctorB, '2025-01-01 03:00:00');
    dpshSheet($bookVisit); // the book, anchored to doctor B's first visit
    $aVisit = dpshVisit(test()->dpshDoctorA, ClinicVisit::STATUS_IN_PROGRESS);
    rmeSignedConsentFor($aVisit);

    $html = $this->followingRedirects()
        ->actingAs($a)
        ->get(route('rme.visits.medical-record.show', $aVisit))
        ->assertOk()
        ->getContent();

    expect($html)->toContain('id="handwriting-form"')
        ->and($html)->toContain(route('rme.visits.medical-record.store', $aVisit));
})->with(['flag ON' => [true], 'flag OFF' => [false]]);

it('holds the book\'s typed fields and diagnoses to its anchor visit in the services', function () {
    dpshFlag(true);
    $f = dpshFixture();
    $a = test()->dpshUserA;
    $this->actingAs($a);

    $update = dpshRefusal(fn () => app(MedicalRecordService::class)->updateDraft($f['book'], ['notes' => 'pada buku'], $a));
    $diagnose = dpshRefusal(fn () => app(MedicalRecordDiagnosisService::class)->record($f['book'], [
        'clinical_diagnosis_id' => ClinicalDiagnosis::factory()->create()->id,
        'diagnosis_role' => MedicalRecordDiagnosis::ROLE_PRIMARY,
    ], $a));

    expect($update)->toContain('ditangani dokter lain')
        ->and($diagnose)->toContain('ditangani dokter lain')
        ->and($f['book']->fresh()->notes)->toBeNull();
});

it('refuses to start the book with typed fields on another doctor\'s visit, and starts it without them', function () {
    dpshFlag(true);
    $a = test()->dpshUserA;
    $this->actingAs($a);
    $bFirst = dpshCompleted(test()->dpshDoctorB, '2025-01-01 03:00:00'); // canonical visit, no sheet yet
    $aVisit = dpshVisit(test()->dpshDoctorA, ClinicVisit::STATUS_IN_PROGRESS);
    rmeSignedConsentFor($aVisit);

    $refused = dpshRefusal(fn () => app(MedicalRecordService::class)->startBook($aVisit, $a, ['subjective' => 'keluhan']));

    expect($refused)->toContain('ditangani dokter lain')
        ->and(MedicalRecord::query()->where('patient_id', test()->dpshPatient->id)->exists())->toBeFalse();

    [$book, $created] = app(MedicalRecordService::class)->startBook($aVisit, $a, []);

    expect($created)->toBeTrue()
        ->and((int) $book->clinic_visit_id)->toBe((int) $bFirst->id)
        ->and($book->subjective)->toBeNull();
});

it('refuses an in-place page save on another doctor\'s sheet even past the policy', function () {
    dpshFlag(true);
    $f = dpshFixture();
    $a = test()->dpshUserA;
    dpshPage($f['bSheet'], 2, test()->dpshDoctorB);
    dpshPage($f['bSheet'], 3, test()->dpshDoctorB);
    Gate::before(fn () => true);

    $this->actingAs($a)
        ->post(route('rme.visits.medical-record.handwriting.store', [$f['bVisit'], $f['bSheet']]), [
            'handwriting_data' => validPodSignatureData(),
            'page_number' => 2,
        ])
        ->assertForbidden();

    expect(MedicalRecordHandwritingPage::query()->where('medical_record_id', $f['bSheet']->id)->where('page_number', 2)->value('handwriting_path'))
        ->toBe(sprintf('rme/handwriting/dpsh-%d-2.png', $f['bSheet']->id));
});

it('filters the prior-visit picker to what the doctor may read', function () {
    dpshFlag(true);
    $a = test()->dpshUserA;
    $own = dpshVisit(test()->dpshDoctorA, ClinicVisit::STATUS_IN_PROGRESS);
    $bHistory = dpshCompleted(test()->dpshDoctorB, '2026-10-01 03:00:00');
    $bWaiting = dpshVisit(test()->dpshDoctorB, ClinicVisit::STATUS_WAITING);

    $ids = collect($this->actingAs($a)
        ->getJson(route('rme.visits.patient-options', ['patient_id' => test()->dpshPatient->id]))
        ->assertOk()
        ->json('visits'))->pluck('id')->all();

    expect($ids)->toContain($own->id)
        ->and($ids)->toContain($bHistory->id)
        ->and($ids)->not->toContain($bWaiting->id);
});

it('records the explicit actor as the finalizer', function () {
    dpshFlag(true);
    $f = dpshFixture();
    $a = test()->dpshUserA;
    dpshPage($f['aSheet'], 2, test()->dpshDoctorA);

    $this->actingAs(test()->dpshUserB); // the session is someone else
    $finalized = app(MedicalRecordService::class)->finalize($f['aSheet'], $a);

    expect($finalized->status)->toBe(MedicalRecord::STATUS_FINAL)
        ->and((int) $finalized->finalized_by)->toBe((int) $a->id);
});

it('keeps Supervisor RME exempt from the sheet rule in the services', function () {
    dpshFlag(true);
    $f = dpshFixture();
    $supervisor = rmeClinicalAuthor();
    $this->actingAs($supervisor);

    app(MedicalRecordService::class)->updateDraft($f['bSheet'], ['notes' => 'oleh supervisor'], $supervisor);

    expect($f['bSheet']->fresh()->notes)->toBe('oleh supervisor');
});
