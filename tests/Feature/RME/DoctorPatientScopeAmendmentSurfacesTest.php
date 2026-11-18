<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| DMS-RME-COMPREHENSIVE-CLOSURE-1 memo M2.1/M2.2 — the doctor encounter scope
| on the surfaces M3.3 (rule 240) and the PTM (rule 241) added (rule 243 §2.8,
| §2.11; integration of the encounter-scope series onto M3.3 + RM + PTM)
|--------------------------------------------------------------------------
| With `rme.doctor_encounter_scope` ON, for a scoped Doctor:
|  - the BOOK's page sequence, its page image routes, a version page's locked
|    pages and the RME print list a book page only when its authoring encounter
|    (rule 240 §4.4's `authored_clinic_visit_id`) is a visit the doctor may
|    read; an unstamped page (written before rule 240) stays patient-level;
|  - a book anchored to a visit the doctor may not read keeps only its pages:
|    its version history and its amendments answer 404 — also to the doctor who
|    requested the amendment — and the workspace shows no "Amandemen" block;
|  - the PTM banner never describes another doctor's encounter.
| With the flag OFF every surface is exactly as before (parity per surface).
|
| Selected by the existing `DoctorPatientScope` critical-gate token in both
| runner variants; declared in critical_gate_mandatory_suites. Helper prefix
| `dpam` is unique (the critical gate loads every suite into one process).
*/

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\Clinic\Models\Clinic;
use App\Modules\ClinicRoom\Models\ClinicRoom;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Consent\Models\RmeVisitConsent;
use App\Modules\Consent\Services\RmeVisitConsentService;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\MedicalRecord\Models\MedicalRecordHandwritingPage;
use App\Modules\MedicalRecord\Services\PatientRmWorkspaceResolver;
use App\Modules\MedicalRecord\Support\RmeWorkspacePage;
use App\Modules\Patient\Models\Patient;
use App\Modules\RmeAmendment\Models\RmeAmendment;
use App\Modules\RmeAmendment\Services\RmeAmendmentService;
use App\Modules\RmeAmendment\Services\RmeRecordVersionService;
use App\Support\Storage\ClinicalEvidenceStorage;
use Carbon\Carbon;
use Database\Seeders\BranchSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

const DPAM_FLAG = 'rme.doctor_encounter_scope';

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-11-17 02:00:00', 'UTC')); // 10:00 WITA

    test()->seed(BranchSeeder::class);
    seedAccessControl();
    Storage::fake(ClinicalEvidenceStorage::diskName());
    Storage::fake('public');

    test()->dpamBranch = Branch::factory()->create(['code' => 'AM01', 'is_rme_enabled' => true]);
    $roomA = ClinicRoom::factory()->create(['branch_id' => test()->dpamBranch->id, 'status' => ClinicRoom::STATUS_ACTIVE]);
    $roomB = ClinicRoom::factory()->create(['branch_id' => test()->dpamBranch->id, 'status' => ClinicRoom::STATUS_ACTIVE]);
    test()->dpamRoom = $roomA;
    $clinic = Clinic::factory()->create();

    // One doctor per room; B's visits are created in A's room so that, from A's
    // side, the rule 138 room scope admits them and only the encounter scope decides.
    foreach (['A' => $roomA, 'B' => $roomB] as $name => $room) {
        $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'branch_id' => test()->dpamBranch->id, 'name' => 'drg. Dokter '.$name.' Amandemen']);
        $user = User::factory()->create()->assignRole('Doctor');
        $doctor->update(['user_id' => $user->id]);
        $doctor->branches()->sync([(int) test()->dpamBranch->id]);
        rmeMakeDoctorOnline($doctor, test()->dpamBranch, $room, $user);
        $user->givePermissionTo(['view_clinic_visits', 'manage_clinic_visits']);

        test()->{'dpamDoctor'.$name} = $doctor->refresh();
        test()->{'dpamUser'.$name} = $user->refresh();
    }

    test()->dpamPatient = Patient::factory()->create(['branch_id' => test()->dpamBranch->id]);
});

afterEach(function () {
    Carbon::setTestNow();
});

function dpamFlag(bool $on): void
{
    $flags = config('feature_flags.flags', []);
    $flags[DPAM_FLAG]['default'] = $on;
    $flags[DPAM_FLAG]['env_value'] = null;
    config()->set('feature_flags.flags', $flags);
}

function dpamVisit(Doctor $doctor, string $status, array $overrides = []): ClinicVisit
{
    return ClinicVisit::factory()->create(array_merge([
        'branch_id' => test()->dpamBranch->id,
        'patient_id' => test()->dpamPatient->id,
        'doctor_id' => $doctor->id,
        'clinic_room_id' => test()->dpamRoom->id,
        'status' => $status,
        'visit_date' => '2026-11-17',
    ], $overrides))->refresh();
}

function dpamSheet(ClinicVisit $visit, ?MedicalRecord $book = null): MedicalRecord
{
    return MedicalRecord::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $visit->branch_id,
        'patient_id' => $visit->patient_id,
        'doctor_id' => $visit->doctor_id,
        'canonical_visit_id' => $book?->clinic_visit_id ?? $visit->id,
    ])->refresh();
}

/** A stored page of $sheet, written by $author (null: before rule 240, unstamped). */
function dpamPage(MedicalRecord $sheet, int $number, ?ClinicVisit $author): MedicalRecordHandwritingPage
{
    $path = sprintf('rme/handwriting/dpam-%d-%d.png', $sheet->id, $number);
    Storage::disk(ClinicalEvidenceStorage::diskName())->put($path, 'dpam-png-bytes');

    return MedicalRecordHandwritingPage::query()->create([
        'medical_record_id' => $sheet->id,
        'clinic_visit_id' => $sheet->clinic_visit_id,
        'branch_id' => $sheet->branch_id,
        'doctor_id' => $sheet->doctor_id,
        'page_number' => $number,
        'handwriting_path' => $path,
        'handwriting_hash' => str_repeat('d', 64),
        'saved_at' => now(),
        'authored_clinic_visit_id' => $author?->id,
    ]);
}

function dpamFinal(MedicalRecord $sheet, User $finalizer): MedicalRecord
{
    $sheet->forceFill([
        'status' => MedicalRecord::STATUS_FINAL,
        'finalized_at' => now()->subHour(),
        'finalized_by' => $finalizer->id,
    ])->save();

    return $sheet->fresh();
}

/** GET the workspace as $viewer from $visit's RM URL, following the canonical redirect. */
function dpamOpen(User $viewer, ClinicVisit $visit): TestResponse
{
    return test()->followingRedirects()
        ->actingAs($viewer)
        ->get(route('rme.visits.medical-record.show', $visit));
}

/** @return list<int> the book's storage page numbers the workspace sequence lists */
function dpamBookPages(TestResponse $response, MedicalRecord $book): array
{
    return collect($response->viewData('workspacePages'))
        ->filter(fn (RmeWorkspacePage $page) => $page->isNative() && (int) $page->nativePage['medical_record_id'] === (int) $book->id)
        ->map(fn (RmeWorkspacePage $page) => (int) $page->nativePage['storage_page_number'])
        ->values()->all();
}

/**
 * The book on B's completed 2025 visit (readable in history mode), A's own
 * in-service visit today (history mode for A), and B's live PRE-EXAM encounter
 * today, which A may not read. Book pages: 2 written by B's live encounter, 3
 * by A's, 4 before rule 240 (no stamp).
 */
function dpamPageFixture(): array
{
    $bookVisit = dpamVisit(test()->dpamDoctorB, ClinicVisit::STATUS_COMPLETED, [
        'completed_at' => '2025-01-01 03:00:00',
        'visit_date' => '2025-01-01',
    ]);
    $book = dpamSheet($bookVisit);
    $aVisit = dpamVisit(test()->dpamDoctorA, ClinicVisit::STATUS_CASHIER_PENDING);
    $bLive = dpamVisit(test()->dpamDoctorB, ClinicVisit::STATUS_IN_PROGRESS);

    return [
        'bookVisit' => $bookVisit,
        'book' => $book,
        'aVisit' => $aVisit,
        'bLive' => $bLive,
        'bPage' => dpamPage($book, 2, $bLive),
        'aPage' => dpamPage($book, 3, $aVisit),
        'oldPage' => dpamPage($book, 4, null),
    ];
}

/*
|--------------------------------------------------------------------------
| 1 — the book's pages follow their author stamp (rule 243 §2.8)
|--------------------------------------------------------------------------
*/

it('lists and serves a book page only when the doctor may read the encounter that wrote it', function (bool $on) {
    dpamFlag($on);
    $f = dpamPageFixture();
    $a = test()->dpamUserA;

    $response = dpamOpen($a, $f['aVisit'])->assertOk();
    $resolver = app(PatientRmWorkspaceResolver::class);
    $sequence = $resolver->orderedHandwritingBookForPatient((int) test()->dpamPatient->id, $a)
        ->where('medical_record_id', $f['book']->id)->pluck('storage_page_number')->map(fn ($n) => (int) $n)->all();

    expect(dpamBookPages($response, $f['book']))->toBe($on ? [1, 3, 4] : [1, 2, 3, 4])
        ->and($sequence)->toBe($on ? [1, 3, 4] : [1, 2, 3, 4])
        // No viewer: a server fact, never filtered.
        ->and($resolver->orderedHandwritingBookForPatient((int) test()->dpamPatient->id)->where('medical_record_id', $f['book']->id)->count())->toBe(4);

    // The per-record twin: the page image route serves exactly what is listed.
    $this->actingAs($a)->get(route('rme.handwriting-pages.image', $f['bPage']))->assertStatus($on ? 404 : 200);
    $this->actingAs($a)->get(route('rme.handwriting-pages.image', $f['aPage']))->assertOk();
    $this->actingAs($a)->get(route('rme.handwriting-pages.image', $f['oldPage']))->assertOk();

    // The writer of the page still reads it, with the flag ON too.
    $this->actingAs(test()->dpamUserB)->get(route('rme.handwriting-pages.image', $f['bPage']))->assertOk();
})->with(['flag ON' => true, 'flag OFF' => false]);

it('keeps the exempt roles reading every book page with the flag ON', function () {
    dpamFlag(true);
    $f = dpamPageFixture();
    $supervisor = rmeClinicalAuthor();

    $sequence = app(PatientRmWorkspaceResolver::class)->orderedHandwritingBookForPatient((int) test()->dpamPatient->id, $supervisor)
        ->where('medical_record_id', $f['book']->id)->pluck('storage_page_number')->map(fn ($n) => (int) $n)->all();

    expect($sequence)->toBe([1, 2, 3, 4]);
});

it('prints and lists in a version only the book pages the doctor may read', function (bool $on) {
    dpamFlag($on);
    $f = dpamPageFixture();
    $a = test()->dpamUserA;
    $book = dpamFinal($f['book'], test()->dpamUserB);
    DB::transaction(fn () => app(RmeRecordVersionService::class)->recordFinal($book, (int) test()->dpamUserB->id));

    $printed = app(RmeAmendmentService::class)->sheetPagesFor($a, $book, false)['rmPagesForReader']
        ->pluck('page_number')->map(fn ($n) => (int) $n)->values()->all();
    $versioned = collect(app(RmeAmendmentService::class)->versionDetailFor($a, $book, 1)['pages'])
        ->pluck('page_number')->all();

    expect($printed)->toBe($on ? [3, 4] : [2, 3, 4])
        ->and($versioned)->toBe($on ? [3, 4] : [2, 3, 4]);

    $this->actingAs($a)->get(route('rme.amendments.versions.sheet.show', [$book, 1]))
        ->assertOk()
        ->assertSee(route('rme.handwriting-pages.image', $f['aPage']), false)
        ->{$on ? 'assertDontSee' : 'assertSee'}(route('rme.handwriting-pages.image', $f['bPage']), false);
})->with(['flag ON' => true, 'flag OFF' => false]);

/*
|--------------------------------------------------------------------------
| 2 — a book on an anchor the doctor may not read: no version, no amendment
|--------------------------------------------------------------------------
*/

/**
 * The patient's canonical anchor is B's live PRE-EXAM visit, holding the FINAL
 * book; A finalized it (co-authoring, flag OFF) and so may request its
 * amendment. A's own visit today is at the cashier (history mode for A).
 */
function dpamAnchorFixture(): array
{
    $anchor = dpamVisit(test()->dpamDoctorB, ClinicVisit::STATUS_IN_PROGRESS);
    $book = dpamFinal(dpamSheet($anchor), test()->dpamUserA);
    $aVisit = dpamVisit(test()->dpamDoctorA, ClinicVisit::STATUS_CASHIER_PENDING);

    dpamFlag(false);
    $amendment = app(RmeAmendmentService::class)->request(
        $book,
        test()->dpamUserA,
        'dpam diagnosis kerja salah ketik',
        ['fields' => ['assessment' => 'dpam pulpitis ireversibel']],
    );

    return compact('anchor', 'book', 'aVisit', 'amendment');
}

it('answers 404 for the versions and the amendments of a book whose anchor the doctor may not read', function (bool $on) {
    $f = dpamAnchorFixture();
    dpamFlag($on);
    $a = test()->dpamUserA;
    $service = app(RmeAmendmentService::class);

    // The book itself stays readable patient-level (its pages); its content does not.
    expect(Gate::forUser($a)->allows('view', $f['book']))->toBeTrue()
        ->and($service->maySeeSubject($a, $f['book']))->toBe(! $on)
        ->and($service->mayView($a, $f['amendment']))->toBe(! $on);

    $expected = $on ? 404 : 200;
    $this->actingAs($a)->get(route('rme.amendments.versions.sheet', $f['book']))->assertStatus($expected);
    $this->actingAs($a)->get(route('rme.amendments.versions.sheet.show', [$f['book'], 1]))->assertStatus($expected);
    $this->actingAs($a)->get(route('rme.amendments.show', $f['amendment']))->assertStatus($expected);

    if ($on) {
        $this->actingAs($a)->post(route('rme.amendments.cancel', $f['amendment']))->assertNotFound();
        expect(RmeAmendment::query()->find($f['amendment']->id)->status)->toBe(RmeAmendment::STATUS_PENDING);
    }

    // The workspace keeps the book's pages but no "Amandemen" block.
    $workspace = dpamOpen($a, $f['aVisit'])->assertOk();
    expect($workspace->viewData('anchorContentReadable'))->toBe(! $on)
        ->and($workspace->viewData('amendmentPanel'))->{$on ? 'toBeNull' : 'not'}->toBeNull();
})->with(['flag ON' => true, 'flag OFF' => false]);

it('keeps the doctor of record and the approver on the amendment with the flag ON', function () {
    $f = dpamAnchorFixture();
    dpamFlag(true);

    // B's own visit: B reads the book's content and its versions.
    expect(app(RmeAmendmentService::class)->maySeeSubject(test()->dpamUserB, $f['book']))->toBeTrue();
    $this->actingAs(test()->dpamUserB)->get(route('rme.amendments.versions.sheet', $f['book']))->assertOk();

    // Supervisor RME is exempt from the encounter scope.
    $supervisor = userInRole('Supervisor RME');
    expect(app(RmeAmendmentService::class)->mayView($supervisor, $f['amendment']))->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| 3 — the PTM banner never describes another doctor's encounter (rule 241 §3)
|--------------------------------------------------------------------------
*/

it('never describes another doctor\'s live encounter in the PTM banner', function (bool $on) {
    dpamFlag($on);
    $f = dpamPageFixture();
    $a = test()->dpamUserA;
    // B's live encounter: the patient signed, B has not countersigned yet.
    RmeVisitConsent::factory()->create([
        'clinic_visit_id' => $f['bLive']->id,
        'branch_id' => $f['bLive']->branch_id,
        'patient_id' => $f['bLive']->patient_id,
        'doctor_id' => $f['bLive']->doctor_id,
    ]);

    $response = dpamOpen($a, $f['aVisit'])->assertOk();

    expect($response->viewData('activeEncounterPtmState'))->toBe($on ? null : RmeVisitConsentService::PTM_AWAITING_DOCTOR)
        ->and($response->viewData('activeEncounter')?->id)->toBe($on ? null : $f['bLive']->id);

    $on
        ? $response->assertDontSee($f['bLive']->visit_number)->assertDontSee('data-ptm-state', false)->assertSee('Belum ada pemeriksaan Anda yang berjalan')
        : $response->assertSee('data-ptm-state="awaiting_doctor"', false)->assertSee($f['bLive']->visit_number);
})->with(['flag ON' => true, 'flag OFF' => false]);
