<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| DMS-RME-COMPREHENSIVE-CLOSURE-1 memo M2.1/M2.2 — PR A3a (rule 243 §2.11)
|--------------------------------------------------------------------------
| The patient's RM WORKSPACE under `rme.doctor_encounter_scope` (flag ON):
|  - its canonical address is the patient's workspace, not the encounter of
|    the visit it names: a doctor opens it in read mode `history` (everything
|    the per-record checks admit) or `own` (the after-service workspace: the
|    doctor's own visits and sheets only); `none` answers 404;
|  - the sheet navigation, the document rail and the handwriting book page
|    sequence list exactly the sheets MedicalRecordPolicy::view admits — the
|    book patient-level (history), every other sheet by its own visit;
|  - the consent banner never names another doctor's encounter;
|  - the book's ANCHOR: a doctor inside an own in-service encounter starts the
|    book on the patient's canonical visit even when that visit is another
|    doctor's pre-exam visit (`startBook` = the `appendPage` rule), and the
|    workspace never names an anchor the doctor may not read. The R7-1 book
|    selector is unchanged for every role and with the flag OFF.
| With the flag OFF every surface is exactly as before (parity tests below).
|
| Selected by the existing `DoctorPatientScope` critical-gate token in both
| runner variants; declared in critical_gate_mandatory_suites. Helper prefix
| `dpsw` is unique (the critical gate loads every suite into one process).
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
use App\Modules\MedicalRecord\Services\MedicalRecordSheetWriteScope;
use App\Modules\MedicalRecord\Services\PatientRmWorkspaceResolver;
use App\Modules\MedicalRecord\Support\RmeWorkspaceDocument;
use App\Modules\MedicalRecord\Support\RmeWorkspacePage;
use App\Modules\Patient\Models\Patient;
use App\Modules\RME\Services\DoctorPatientScopeService;
use App\Support\Storage\ClinicalEvidenceStorage;
use Carbon\Carbon;
use Database\Seeders\BranchSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

const DPSW_FLAG = 'rme.doctor_encounter_scope';

/** The consent-banner text a scoped doctor sees when no encounter of theirs is running. */
const DPSW_OWN_BANNER = 'Belum ada pemeriksaan Anda yang berjalan';

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-11-17 02:00:00', 'UTC')); // 10:00 WITA

    test()->seed(BranchSeeder::class);
    seedAccessControl();
    Storage::fake(ClinicalEvidenceStorage::diskName());
    Storage::fake('public');

    test()->dpswBranch = Branch::factory()->create(['code' => 'SW01', 'is_rme_enabled' => true]);
    $roomA = ClinicRoom::factory()->create(['branch_id' => test()->dpswBranch->id, 'status' => ClinicRoom::STATUS_ACTIVE]);
    $roomB = ClinicRoom::factory()->create(['branch_id' => test()->dpswBranch->id, 'status' => ClinicRoom::STATUS_ACTIVE]);
    test()->dpswRoom = $roomA;
    $clinic = Clinic::factory()->create();

    // One doctor per room; B's visits are created in A's room so that, from A's
    // side, the rule 138 room scope admits them and only the encounter scope decides.
    foreach (['A' => $roomA, 'B' => $roomB] as $name => $room) {
        $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'branch_id' => test()->dpswBranch->id, 'name' => 'drg. Dokter '.$name.' Ruang Kerja']);
        $user = User::factory()->create()->assignRole('Doctor');
        $doctor->update(['user_id' => $user->id]);
        $doctor->branches()->sync([(int) test()->dpswBranch->id]);
        rmeMakeDoctorOnline($doctor, test()->dpswBranch, $room, $user);
        $user->givePermissionTo(['view_clinic_visits', 'manage_clinic_visits']);

        test()->{'dpswDoctor'.$name} = $doctor->refresh();
        test()->{'dpswUser'.$name} = $user->refresh();
    }

    test()->dpswPatient = Patient::factory()->create(['branch_id' => test()->dpswBranch->id]);
});

afterEach(function () {
    Carbon::setTestNow();
});

function dpswFlag(bool $on): void
{
    $flags = config('feature_flags.flags', []);
    $flags[DPSW_FLAG]['default'] = $on;
    $flags[DPSW_FLAG]['env_value'] = null;
    config()->set('feature_flags.flags', $flags);
}

function dpswVisit(Doctor $doctor, string $status, array $overrides = []): ClinicVisit
{
    return ClinicVisit::factory()->create(array_merge([
        'branch_id' => test()->dpswBranch->id,
        'patient_id' => test()->dpswPatient->id,
        'doctor_id' => $doctor->id,
        'clinic_room_id' => test()->dpswRoom->id,
        'status' => $status,
        'visit_date' => '2026-11-17',
    ], $overrides))->refresh();
}

function dpswCompleted(Doctor $doctor, string $completedAtUtc): ClinicVisit
{
    return dpswVisit($doctor, ClinicVisit::STATUS_COMPLETED, [
        'completed_at' => $completedAtUtc,
        'visit_date' => substr($completedAtUtc, 0, 10),
    ]);
}

/** A sheet on $visit; anchored to $book's visit unless it IS the book. */
function dpswSheet(ClinicVisit $visit, ?MedicalRecord $book = null): MedicalRecord
{
    return MedicalRecord::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $visit->branch_id,
        'patient_id' => $visit->patient_id,
        'doctor_id' => $visit->doctor_id,
        'canonical_visit_id' => $book?->clinic_visit_id ?? $visit->id,
    ])->refresh();
}

function dpswPage(MedicalRecord $sheet, int $number): MedicalRecordHandwritingPage
{
    return MedicalRecordHandwritingPage::query()->create([
        'medical_record_id' => $sheet->id,
        'clinic_visit_id' => $sheet->clinic_visit_id,
        'branch_id' => $sheet->branch_id,
        'doctor_id' => $sheet->doctor_id,
        'page_number' => $number,
        'handwriting_path' => sprintf('rme/handwriting/dpsw-%d-%d.png', $sheet->id, $number),
        'handwriting_hash' => str_repeat('e', 64),
        'saved_at' => now(),
    ]);
}

/** GET the workspace as $viewer from $visit's RM URL, following the canonical redirect. */
function dpswOpen(User $viewer, ClinicVisit $visit, array $query = []): TestResponse
{
    return test()->followingRedirects()
        ->actingAs($viewer)
        ->get(route('rme.visits.medical-record.show', array_merge([$visit], $query)));
}

/** @return list<int> the sheet ids the sheet navigation lists */
function dpswSheetIds(TestResponse $response): array
{
    return collect($response->viewData('sheets'))->map(fn (MedicalRecord $s) => (int) $s->id)->sort()->values()->all();
}

/** @return list<int> the native records the page sequence draws pages from */
function dpswPageRecordIds(TestResponse $response): array
{
    return collect($response->viewData('workspacePages'))
        ->filter(fn (RmeWorkspacePage $page) => $page->isNative())
        ->map(fn (RmeWorkspacePage $page) => (int) $page->nativePage['medical_record_id'])
        ->unique()->sort()->values()->all();
}

/** @return list<int> the native sheets the document rail lists */
function dpswRailIds(TestResponse $response): array
{
    return collect($response->viewData('workspaceDocuments'))
        ->filter(fn (RmeWorkspaceDocument $document) => $document->isNative())
        ->map(fn (RmeWorkspaceDocument $document) => (int) $document->id)
        ->sort()->values()->all();
}

/**
 * The book on B's first visit (2025), B's sheet last month, B's sheet on a
 * PRE-EXAM visit today ($bStatus), and A's own sheet on A's in-service visit
 * ($aStatus). Every sheet carries a page 2.
 */
function dpswFixture(string $bStatus = ClinicVisit::STATUS_WAITING, string $aStatus = ClinicVisit::STATUS_CASHIER_PENDING): array
{
    $bookVisit = dpswCompleted(test()->dpswDoctorB, '2025-01-01 03:00:00');
    $book = dpswSheet($bookVisit);
    $bOld = dpswCompleted(test()->dpswDoctorB, '2026-10-01 03:00:00');
    $bOldSheet = dpswSheet($bOld, $book);
    $bLive = dpswVisit(test()->dpswDoctorB, $bStatus);
    $bLiveSheet = dpswSheet($bLive, $book);
    $aVisit = dpswVisit(test()->dpswDoctorA, $aStatus);
    $aSheet = dpswSheet($aVisit, $book);

    foreach ([$book, $bOldSheet, $bLiveSheet, $aSheet] as $sheet) {
        dpswPage($sheet, 2);
    }

    return compact('bookVisit', 'book', 'bOld', 'bOldSheet', 'bLive', 'bLiveSheet', 'aVisit', 'aSheet');
}

/** Sorted ids of the given sheets. */
function dpswIds(MedicalRecord ...$sheets): array
{
    return collect($sheets)->map(fn (MedicalRecord $s) => (int) $s->id)->sort()->values()->all();
}

/*
|--------------------------------------------------------------------------
| 1 — history mode: sheet navigation, rail and book pages mirror the records
|--------------------------------------------------------------------------
*/

it('lists in the workspace only the sheets and pages the doctor may open (history mode)', function (string $bStatus) {
    dpswFlag(true);
    $f = dpswFixture($bStatus);
    $a = test()->dpswUserA;

    expect(app(DoctorPatientScopeService::class)->readModeFor($a, (int) test()->dpswPatient->id))
        ->toBe(DoctorPatientScopeService::READ_HISTORY);

    $response = dpswOpen($a, $f['aVisit'])->assertOk();
    $readable = dpswIds($f['book'], $f['bOldSheet'], $f['aSheet']);

    expect(dpswSheetIds($response))->toBe($readable)
        ->and(dpswRailIds($response))->toBe($readable)
        ->and(dpswPageRecordIds($response))->toBe($readable)
        ->and($response->getContent())->not->toContain($f['bLive']->visit_number)
        ->and($response->getContent())->toContain(e($f['bOld']->visit_number));
})->with([ClinicVisit::STATUS_REGISTERED, ClinicVisit::STATUS_WAITING, ClinicVisit::STATUS_IN_PROGRESS]);

it('never selects or names a hidden sheet or source visit asked for in the URL', function () {
    dpswFlag(true);
    $f = dpswFixture();
    $a = test()->dpswUserA;

    // The canonical address itself: a visit's RM URL redirects there and drops both parameters.
    $response = dpswOpen($a, $f['bookVisit'], ['sheet' => $f['bLiveSheet']->id, 'source_visit_id' => $f['bLive']->id])->assertOk();

    expect((int) $response->viewData('activeSheet')->id)->not->toBe((int) $f['bLiveSheet']->id)
        ->and($response->viewData('sourceVisit'))->toBeNull()
        ->and($response->getContent())->not->toContain($f['bLive']->visit_number);
});

it('lists a sheet exactly when the record policy lets the doctor read it', function (string $mode) {
    dpswFlag(true);
    $f = dpswFixture(ClinicVisit::STATUS_IN_PROGRESS, $mode === 'history' ? ClinicVisit::STATUS_WAITING : ClinicVisit::STATUS_CANCELLED);
    $a = test()->dpswUserA;
    $own = dpswCompleted(test()->dpswDoctorA, '2025-06-01 03:00:00');
    $ownSheet = dpswSheet($own, $f['book']);

    $all = app(PatientRmWorkspaceResolver::class)->sheetsForPatient((int) test()->dpswPatient->id);
    $listed = app(MedicalRecordSheetWriteScope::class)->readableSheets($a, $all)->pluck('id')->map(fn ($id) => (int) $id)->all();

    foreach ($all as $sheet) {
        expect(in_array((int) $sheet->id, $listed, true))->toBe(Gate::forUser($a)->allows('view', $sheet), "sheet {$sheet->id}");
    }

    expect(in_array((int) $ownSheet->id, $listed, true))->toBeTrue()
        ->and(in_array((int) $f['book']->id, $listed, true))->toBe($mode === 'history');
})->with(['history', 'own']);

it('focuses the saved page in the doctor\'s own page sequence', function () {
    dpswFlag(true);
    // B's stale WAITING visit with a sheet sorts BEFORE A's sheet (same day,
    // lower id) and is hidden from A, so the focus index must skip its pages.
    $f = dpswFixture(ClinicVisit::STATUS_WAITING, ClinicVisit::STATUS_IN_PROGRESS);
    rmeSignedConsentFor($f['aVisit']);
    $a = test()->dpswUserA;
    // Rule 240 §4.4 — only the live encounter overwrites a page, and only one it
    // wrote itself: A's page 2 carries A's encounter as its author stamp.
    MedicalRecordHandwritingPage::query()
        ->where('medical_record_id', $f['aSheet']->id)
        ->where('page_number', 2)
        ->update(['authored_clinic_visit_id' => $f['aVisit']->id]);

    $this->actingAs($a)
        ->post(route('rme.visits.medical-record.handwriting.store', [$f['aVisit'], $f['aSheet']]), [
            'handwriting_data' => validPodSignatureData(),
            'page_number' => 2,
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $focus = (int) session('focus_rm_page');
    $response = dpswOpen($a, $f['aVisit'])->assertOk();
    $page = collect($response->viewData('workspacePages'))->first(fn (RmeWorkspacePage $p) => $p->workspaceIndex === $focus);

    expect($page)->not->toBeNull()
        ->and((int) $page->nativePage['medical_record_id'])->toBe((int) $f['aSheet']->id)
        ->and((int) $page->nativePage['storage_page_number'])->toBe(2);
});

/*
|--------------------------------------------------------------------------
| 2 — own mode: the after-service workspace, own sheets only
|--------------------------------------------------------------------------
*/

it('opens the after-service workspace in own mode with the doctor\'s own sheets only', function () {
    dpswFlag(true);
    $a = test()->dpswUserA;
    $bookVisit = dpswCompleted(test()->dpswDoctorB, '2025-01-01 03:00:00'); // canonical anchor, B's
    $book = dpswSheet($bookVisit);
    $own = dpswCompleted(test()->dpswDoctorA, '2025-06-01 03:00:00');
    $ownSheet = dpswSheet($own, $book);
    $bOld = dpswCompleted(test()->dpswDoctorB, '2026-10-01 03:00:00');
    $bOldSheet = dpswSheet($bOld, $book);
    foreach ([$book, $ownSheet, $bOldSheet] as $sheet) {
        dpswPage($sheet, 2);
    }

    expect(app(DoctorPatientScopeService::class)->readModeFor($a, (int) test()->dpswPatient->id))
        ->toBe(DoctorPatientScopeService::READ_OWN);

    $response = dpswOpen($a, $own)->assertOk();
    $html = $response->getContent();

    expect(dpswSheetIds($response))->toBe([(int) $ownSheet->id])
        ->and(dpswRailIds($response))->toBe([(int) $ownSheet->id])
        ->and(dpswPageRecordIds($response))->toBe([(int) $ownSheet->id])
        ->and($html)->toContain($own->visit_number)
        ->and($html)->not->toContain($bookVisit->visit_number)
        ->and($html)->not->toContain($bOld->visit_number)
        ->and($html)->not->toContain(test()->dpswDoctorB->name)
        ->and($html)->not->toContain('id="add-rm-page-btn"')
        ->and($html)->not->toContain('data-rm-editable');

    // The book cannot be selected in own mode either.
    $byUrl = dpswOpen($a, $own, ['sheet' => $book->id])->assertOk();
    expect((int) $byUrl->viewData('activeSheet')->id)->toBe((int) $ownSheet->id);
});

it('shows a doctor in own mode with no own sheet an empty workspace that names nobody', function () {
    dpswFlag(true);
    $a = test()->dpswUserA;
    $bookVisit = dpswCompleted(test()->dpswDoctorB, '2025-01-01 03:00:00');
    dpswSheet($bookVisit);
    $own = dpswCompleted(test()->dpswDoctorA, '2025-06-01 03:00:00');

    $response = dpswOpen($a, $own)->assertOk()->assertViewIs('rme.visits.medical-record.empty');

    expect($response->viewData('canEdit'))->toBeFalse()
        ->and($response->getContent())->not->toContain($bookVisit->visit_number)
        ->and($response->getContent())->not->toContain('Buat Halaman RM Pertama');
});

it('answers 404 to a doctor in read mode none, and 403 with the flag OFF', function () {
    $bookVisit = dpswCompleted(test()->dpswDoctorB, '2025-01-01 03:00:00');
    dpswSheet($bookVisit);
    $a = test()->dpswUserA;

    dpswFlag(true);
    $this->actingAs($a)->get(route('rme.visits.medical-record.show', $bookVisit))->assertNotFound();

    dpswFlag(false);
    $this->actingAs($a)->get(route('rme.visits.medical-record.show', $bookVisit))->assertForbidden();
});

/*
|--------------------------------------------------------------------------
| 3 — the consent banner never names another doctor's encounter
|--------------------------------------------------------------------------
*/

it('never names another doctor\'s live encounter in the consent banner', function (string $mode) {
    dpswFlag(true);
    $a = test()->dpswUserA;
    $bookVisit = dpswCompleted(test()->dpswDoctorB, '2025-01-01 03:00:00');
    $book = dpswSheet($bookVisit);
    $entry = $mode === 'history'
        ? dpswVisit(test()->dpswDoctorA, ClinicVisit::STATUS_CASHIER_PENDING)
        : dpswCompleted(test()->dpswDoctorA, '2025-06-01 03:00:00');
    dpswSheet($entry, $book);
    $bLive = dpswVisit(test()->dpswDoctorB, ClinicVisit::STATUS_IN_PROGRESS); // the patient's active encounter

    $response = dpswOpen($a, $entry)->assertOk();

    expect($response->viewData('activeEncounter'))->toBeNull()
        ->and($response->getContent())->toContain(DPSW_OWN_BANNER)
        ->and($response->getContent())->not->toContain($bLive->visit_number);
})->with(['history', 'own']);

it('keeps the empty-state banner from naming another doctor\'s live encounter', function () {
    dpswFlag(true);
    $a = test()->dpswUserA;
    $aVisit = dpswVisit(test()->dpswDoctorA, ClinicVisit::STATUS_CASHIER_PENDING); // canonical anchor, history mode
    $bLive = dpswVisit(test()->dpswDoctorB, ClinicVisit::STATUS_IN_PROGRESS);

    $response = dpswOpen($a, $aVisit)->assertOk()->assertViewIs('rme.visits.medical-record.empty');

    expect($response->viewData('activeEncounter'))->toBeNull()
        ->and($response->getContent())->not->toContain($bLive->visit_number);
});

it('names the doctor\'s own live encounter in the banner', function () {
    dpswFlag(true);
    $a = test()->dpswUserA;
    $bookVisit = dpswCompleted(test()->dpswDoctorB, '2025-01-01 03:00:00');
    $book = dpswSheet($bookVisit);
    $aLive = dpswVisit(test()->dpswDoctorA, ClinicVisit::STATUS_IN_PROGRESS); // not consented yet
    dpswSheet($aLive, $book);

    $response = dpswOpen($a, $aLive)->assertOk();

    expect((int) $response->viewData('activeEncounter')?->id)->toBe((int) $aLive->id)
        ->and($response->getContent())->toContain('Persetujuan Tindakan Medis belum ditandatangani')
        ->and($response->getContent())->toContain('Kunjungan #'.$aLive->visit_number);
});

/*
|--------------------------------------------------------------------------
| 4 — the book ANCHOR (§2.8): another doctor's pre-exam visit
|--------------------------------------------------------------------------
*/

/** B's stale WAITING visit is the patient's canonical anchor; A is in a live, consented encounter. */
function dpswAnchorFixture(): array
{
    $anchor = dpswVisit(test()->dpswDoctorB, ClinicVisit::STATUS_WAITING, ['visit_date' => '2026-11-10']);
    $aVisit = dpswVisit(test()->dpswDoctorA, ClinicVisit::STATUS_IN_PROGRESS);
    rmeSignedConsentFor($aVisit);

    expect((int) app(PatientRmWorkspaceResolver::class)->resolveCanonicalWorkspaceVisit((int) test()->dpswPatient->id)->id)
        ->toBe((int) $anchor->id);

    return compact('anchor', 'aVisit');
}

it('lets a doctor in an own in-service encounter start the book on an anchor they may not read, without naming it', function () {
    dpswFlag(true);
    $f = dpswAnchorFixture();
    $a = test()->dpswUserA;

    expect(Gate::forUser($a)->inspect('view', $f['anchor'])->status())->toBe(404);

    $empty = dpswOpen($a, $f['aVisit'])->assertOk()->assertViewIs('rme.visits.medical-record.empty');

    expect($empty->viewData('canEdit'))->toBeTrue()
        ->and($empty->getContent())->toContain('Buat Halaman RM Pertama')
        ->and($empty->getContent())->not->toContain($f['anchor']->visit_number);

    $this->actingAs($a)
        ->post(route('rme.visits.medical-record.store', $f['anchor']), ['source_visit_id' => $f['aVisit']->id])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    // R7-1 unchanged: the book sits on the canonical visit.
    $book = MedicalRecord::query()->where('patient_id', test()->dpswPatient->id)->sole();
    expect((int) $book->clinic_visit_id)->toBe((int) $f['anchor']->id)
        ->and((int) $book->recorded_by)->toBe((int) $a->id);

    $this->actingAs($a)
        ->post(route('rme.visits.medical-record.handwriting.store', [$f['anchor'], $book]), [
            'handwriting_data' => validPodSignatureData(),
            'page_number' => 2,
            'source_visit_id' => $f['aVisit']->id,
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $html = dpswOpen($a, $f['aVisit'])->assertOk()->assertViewIs('rme.visits.medical-record.show')->getContent();

    expect(MedicalRecordHandwritingPage::query()->where('medical_record_id', $book->id)->where('page_number', 2)->exists())->toBeTrue()
        ->and($html)->not->toContain($f['anchor']->visit_number)
        ->and($html)->not->toContain(test()->dpswDoctorB->name)
        ->and($html)->toContain('id="add-rm-page-btn"');
});

it('keeps the book\'s typed fields with its anchor\'s doctor when a doctor starts it there', function () {
    dpswFlag(true);
    $f = dpswAnchorFixture();
    $a = test()->dpswUserA;

    $this->actingAs($a)
        ->post(route('rme.visits.medical-record.store', $f['anchor']), ['subjective' => 'keluhan', 'source_visit_id' => $f['aVisit']->id])
        ->assertForbidden();

    expect(MedicalRecord::query()->where('patient_id', test()->dpswPatient->id)->exists())->toBeFalse();
});

it('starts the book through both doors on one rule, the appendPage rule', function (string $mode) {
    dpswFlag(true);
    $anchor = dpswVisit(test()->dpswDoctorB, ClinicVisit::STATUS_WAITING, ['visit_date' => '2026-11-10']);
    $a = test()->dpswUserA;

    match ($mode) {
        'history' => dpswVisit(test()->dpswDoctorA, ClinicVisit::STATUS_IN_PROGRESS),
        'own' => dpswCompleted(test()->dpswDoctorA, '2025-06-01 03:00:00'),
        'none' => null,
    };

    $start = Gate::forUser($a)->inspect('startBook', [MedicalRecord::class, $anchor]);
    // The handwriting door asks `appendPage` on the draft it would create there.
    $draft = new MedicalRecord(['patient_id' => test()->dpswPatient->id, 'branch_id' => $anchor->branch_id, 'clinic_visit_id' => $anchor->id]);
    $append = Gate::forUser($a)->inspect('appendPage', $draft);

    expect($start->allowed())->toBe($mode === 'history')
        ->and($start->allowed())->toBe($append->allowed())
        ->and($start->status())->toBe($append->status());
})->with(['history', 'own', 'none']);

it('keeps starting the book on `create` of the anchor with the flag OFF and for exempt accounts', function () {
    $anchor = dpswVisit(test()->dpswDoctorB, ClinicVisit::STATUS_WAITING, ['visit_date' => '2026-11-10']);
    dpswVisit(test()->dpswDoctorA, ClinicVisit::STATUS_IN_PROGRESS);
    $supervisor = rmeClinicalAuthor();

    foreach ([false, true] as $on) {
        dpswFlag($on);

        foreach ([test()->dpswUserA, $supervisor] as $user) {
            if ($on && $user->is(test()->dpswUserA)) {
                continue; // the flag-ON doctor is the case above
            }

            $start = Gate::forUser($user)->inspect('startBook', [MedicalRecord::class, $anchor]);
            $create = Gate::forUser($user)->inspect('create', [MedicalRecord::class, $anchor]);

            expect($start->allowed())->toBe($create->allowed())
                ->and($start->status())->toBe($create->status());
        }
    }
});

it('offers no first page to a doctor who sees no sheet while the patient has some', function () {
    dpswFlag(true);
    $a = test()->dpswUserA;
    // No book: B's waiting sheet is anchored to B's first visit, which holds no sheet.
    $first = dpswCompleted(test()->dpswDoctorB, '2025-01-01 03:00:00');
    $bWaiting = dpswVisit(test()->dpswDoctorB, ClinicVisit::STATUS_WAITING);
    MedicalRecord::factory()->create([
        'clinic_visit_id' => $bWaiting->id,
        'branch_id' => $bWaiting->branch_id,
        'patient_id' => $bWaiting->patient_id,
        'doctor_id' => $bWaiting->doctor_id,
        'canonical_visit_id' => $first->id,
    ]);
    $aVisit = dpswVisit(test()->dpswDoctorA, ClinicVisit::STATUS_CASHIER_PENDING);

    expect(app(PatientRmWorkspaceResolver::class)->canonicalMedicalRecord((int) test()->dpswPatient->id))->toBeNull();

    $response = dpswOpen($a, $aVisit)->assertOk()->assertViewIs('rme.visits.medical-record.empty');

    // store() would take the "Tambah Lembar RM" path for this patient, never startBook.
    expect($response->viewData('canEdit'))->toBeFalse()
        ->and($response->getContent())->not->toContain($bWaiting->visit_number);
});

it('names the workspace\'s "Kontrol dari" parent only when the doctor may open it', function (bool $on) {
    dpswFlag($on);
    $a = test()->dpswUserA;
    $parent = dpswCompleted(test()->dpswDoctorB, '2025-01-01 03:00:00');
    $book = dpswSheet($parent);
    $own = dpswVisit(test()->dpswDoctorA, ClinicVisit::STATUS_COMPLETED, [
        'completed_at' => '2025-06-01 03:00:00',
        'visit_date' => '2025-06-01',
        'visit_type' => ClinicVisit::VISIT_TYPE_CONTROL,
        'follow_up_of_visit_id' => $parent->id,
    ]);
    $ownSheet = dpswSheet($own, $book);

    // OWN mode with the flag ON: the parent is B's visit, which A may not open.
    $response = dpswOpen($a, $own)->assertOk();

    expect((int) $response->viewData('activeSheet')->id)->toBe((int) $ownSheet->id)
        ->and(str_contains($response->getContent(), 'Kontrol dari:'))->toBe(! $on)
        ->and(str_contains($response->getContent(), $parent->visit_number))->toBe(! $on);
})->with(['flag ON' => [true], 'flag OFF' => [false]]);

it('opens the workspace at an anchor in another doctor\'s room: the anchor is storage, not the encounter', function (bool $on) {
    dpswFlag($on);
    $a = test()->dpswUserA;
    // B's stale waiting visit sits in B's OWN room, as it would in the clinic.
    $roomB = ClinicRoom::query()->whereKeyNot(test()->dpswRoom->id)->where('branch_id', test()->dpswBranch->id)->firstOrFail();
    $anchor = dpswVisit(test()->dpswDoctorB, ClinicVisit::STATUS_WAITING, ['visit_date' => '2026-11-10', 'clinic_room_id' => $roomB->id]);
    $aVisit = dpswVisit(test()->dpswDoctorA, ClinicVisit::STATUS_IN_PROGRESS);
    rmeSignedConsentFor($aVisit);

    $response = $this->followingRedirects()->actingAs($a)->get(route('rme.visits.medical-record.show', $aVisit));

    if (! $on) {
        // Unchanged: the rule 138 room scope refuses the anchor as a visit.
        $response->assertForbidden();

        return;
    }

    $response->assertOk()->assertViewIs('rme.visits.medical-record.empty');
    expect($response->getContent())->not->toContain($anchor->visit_number);

    // Its own pages keep the room scope.
    $this->actingAs($a)->get(route('rme.visits.show', $anchor))->assertForbidden();
})->with(['flag ON' => [true], 'flag OFF' => [false]]);

/*
|--------------------------------------------------------------------------
| 5 — exempt accounts and flag OFF parity
|--------------------------------------------------------------------------
*/

it('keeps Supervisor RME exempt: every sheet and the live encounter stay listed', function () {
    dpswFlag(true);
    $f = dpswFixture(ClinicVisit::STATUS_IN_PROGRESS);
    $supervisor = rmeClinicalAuthor();

    $response = dpswOpen($supervisor, $f['aVisit'])->assertOk();

    expect(dpswSheetIds($response))->toBe(dpswIds($f['book'], $f['bOldSheet'], $f['bLiveSheet'], $f['aSheet']))
        ->and(dpswPageRecordIds($response))->toBe(dpswIds($f['book'], $f['bOldSheet'], $f['bLiveSheet'], $f['aSheet']))
        ->and((int) $response->viewData('activeEncounter')?->id)->toBe((int) $f['bLive']->id);
});

it('keeps every workspace surface exactly as before with the flag OFF', function () {
    dpswFlag(false);
    $f = dpswFixture(ClinicVisit::STATUS_IN_PROGRESS);
    $a = test()->dpswUserA;
    $everything = dpswIds($f['book'], $f['bOldSheet'], $f['bLiveSheet'], $f['aSheet']);

    $response = dpswOpen($a, $f['bookVisit'], ['source_visit_id' => $f['bLive']->id])->assertOk();

    expect(dpswSheetIds($response))->toBe($everything)
        ->and(dpswRailIds($response))->toBe($everything)
        ->and(dpswPageRecordIds($response))->toBe($everything)
        ->and((int) $response->viewData('activeEncounter')?->id)->toBe((int) $f['bLive']->id)
        ->and((int) $response->viewData('sourceVisit')?->id)->toBe((int) $f['bLive']->id)
        ->and($response->getContent())->toContain('Kunjungan #'.$f['bLive']->visit_number)
        ->and($response->getContent())->not->toContain(DPSW_OWN_BANNER);
});

it('keeps the workspace whole for a doctor out of service with the flag OFF', function () {
    dpswFlag(false);
    $a = test()->dpswUserA;
    $bookVisit = dpswCompleted(test()->dpswDoctorB, '2025-01-01 03:00:00');
    $book = dpswSheet($bookVisit);
    $own = dpswCompleted(test()->dpswDoctorA, '2025-06-01 03:00:00');
    $ownSheet = dpswSheet($own, $book);

    $response = dpswOpen($a, $own)->assertOk();

    expect(dpswSheetIds($response))->toBe(dpswIds($book, $ownSheet))
        ->and($response->getContent())->toContain(e($bookVisit->visit_number));
});

it('keeps an anchor of another doctor\'s pre-exam visit named and creatable with the flag OFF', function () {
    dpswFlag(false);
    $f = dpswAnchorFixture();
    $a = test()->dpswUserA;

    $empty = dpswOpen($a, $f['aVisit'])->assertOk()->assertViewIs('rme.visits.medical-record.empty');

    expect($empty->viewData('canEdit'))->toBeTrue()
        ->and($empty->getContent())->toContain(e($f['anchor']->visit_number));
});

/*
|--------------------------------------------------------------------------
| 6 — query budget: the scope adds no per-sheet query
|--------------------------------------------------------------------------
*/

/** Queries one workspace GET costs, split into the scope's own probes. */
function dpswMeasure(User $viewer, ClinicVisit $visit): array
{
    $counts = ['total' => 0, 'identity' => 0, 'readMode' => 0, 'book' => 0];

    DB::listen(function (QueryExecuted $query) use (&$counts): void {
        $counts['total']++;
        $sql = $query->sql;

        if (str_contains($sql, '"mst_doctors"') && str_contains($sql, '"user_id" = ?')) {
            $counts['identity']++;
        }

        if (str_contains($sql, '"trx_clinic_visits"') && str_contains($sql, '"doctor_id" = ?') && str_contains($sql, '"patient_id" = ?')) {
            $counts['readMode']++;
        }

        if (str_contains($sql, '"canonical_visit_id"')) {
            $counts['book']++;
        }
    });

    dpswOpen($viewer, $visit)->assertOk();

    return $counts;
}

it('resolves identity, read mode and the book a bounded number of times, whatever the sheet count', function () {
    dpswFlag(true);
    $f = dpswFixture(ClinicVisit::STATUS_WAITING);
    $a = test()->dpswUserA;

    $small = dpswMeasure($a, $f['aVisit']);

    // Six more sheets: three readable (B, completed) and three hidden (B, pre-exam).
    foreach (range(1, 3) as $i) {
        dpswSheet(dpswCompleted(test()->dpswDoctorB, sprintf('2026-0%d-01 03:00:00', $i)), $f['book']);
        dpswSheet(dpswVisit(test()->dpswDoctorB, ClinicVisit::STATUS_REGISTERED), $f['book']);
    }
    app()->forgetScopedInstances();

    $large = dpswMeasure($a, $f['aVisit']);

    expect($large['identity'])->toBe($small['identity'])
        ->and($large['readMode'])->toBe($small['readMode'])
        ->and($large['book'])->toBe($small['book'])
        ->and($large['identity'])->toBeLessThanOrEqual(3);
});

/*
|--------------------------------------------------------------------------
| 7 — A3a review: anchor-bound content, roomless anchors, working branch,
|     bookless budget
|--------------------------------------------------------------------------
*/

/**
 * HIGH-1 probe: the patient's anchor is doctor B's LIVE (in_progress) visit,
 * holding the book with B's typed SOAP, a diagnosis and B as recorder and
 * finalizer; A is in history mode through an own registered visit today.
 */
function dpswLiveAnchorBook(bool $otherRoom): array
{
    $roomB = ClinicRoom::query()->whereKeyNot(test()->dpswRoom->id)->where('branch_id', test()->dpswBranch->id)->firstOrFail();
    $anchor = dpswVisit(test()->dpswDoctorB, ClinicVisit::STATUS_IN_PROGRESS, $otherRoom ? ['clinic_room_id' => $roomB->id] : []);
    $book = dpswSheet($anchor);
    $book->forceFill([
        'subjective' => 'Keluhan rahasia milik dokter B',
        'status' => MedicalRecord::STATUS_FINAL,
        'recorded_by' => test()->dpswUserB->id,
        'finalized_by' => test()->dpswUserB->id,
        'finalized_at' => now(),
    ])->save();
    $diagnosis = ClinicalDiagnosis::factory()->create(['display' => 'Diagnosis rahasia dokter B']);
    MedicalRecordDiagnosis::query()->create([
        'medical_record_id' => $book->id,
        'clinic_visit_id' => $anchor->id,
        'branch_id' => $anchor->branch_id,
        'clinical_diagnosis_id' => $diagnosis->id,
        'diagnosis_role' => MedicalRecordDiagnosis::ROLE_PRIMARY,
        'diagnosed_by' => test()->dpswUserB->id,
        'diagnosed_at' => now(),
        'created_by' => test()->dpswUserB->id,
    ]);
    dpswPage($book, 2);
    $aVisit = dpswVisit(test()->dpswDoctorA, ClinicVisit::STATUS_REGISTERED);

    return compact('anchor', 'book', 'aVisit');
}

it('withholds the book\'s anchor-bound content when the doctor may not read the anchor (flag ON)', function (bool $otherRoom) {
    dpswFlag(true);
    $f = dpswLiveAnchorBook($otherRoom);
    $a = test()->dpswUserA;

    expect(Gate::forUser($a)->allows('viewAnchorContent', $f['book']))->toBeFalse();

    $response = dpswOpen($a, $f['aVisit'])->assertOk()->assertViewIs('rme.visits.medical-record.show');
    $html = $response->getContent();

    // Only the patient-level handwriting pages remain.
    expect(dpswPageRecordIds($response))->toBe([(int) $f['book']->id])
        ->and($response->viewData('diagnosisEnforcement'))->toBeNull()
        ->and($html)->not->toContain('Keluhan rahasia milik dokter B')
        ->and($html)->not->toContain('Data SOAP (Legacy)')
        ->and($html)->not->toContain('Diagnosis rahasia dokter B')
        ->and($html)->not->toContain('Rekam Medis ini telah difinalkan')
        ->and($html)->not->toContain('Difinalisasi oleh')
        ->and($html)->not->toContain('Dicatat oleh')
        ->and($html)->not->toContain(test()->dpswUserB->name)
        ->and($html)->not->toContain($f['anchor']->visit_number)
        ->and(preg_match('/>\s*Final\s*</', $html))->toBe(0); // the status badge
})->with(['same room' => [false], 'other room' => [true]]);

it('keeps the book\'s anchor-bound content exactly as before with the flag OFF', function (bool $otherRoom) {
    dpswFlag(false);
    $f = dpswLiveAnchorBook($otherRoom);
    $a = test()->dpswUserA;

    $response = dpswOpen($a, $f['aVisit']);

    if ($otherRoom) {
        // Rule 138: another room's active visit, as the workspace address.
        $response->assertForbidden();

        return;
    }

    $html = $response->assertOk()->getContent();

    expect($html)->toContain('Keluhan rahasia milik dokter B')
        ->and($html)->toContain('Diagnosis rahasia dokter B')
        ->and($html)->toContain('Rekam Medis ini telah difinalkan')
        ->and($html)->toContain('Difinalisasi oleh')
        ->and(preg_match('/>\s*Final\s*</', $html))->toBe(1);
})->with(['same room' => [false], 'other room' => [true]]);

it('shows the anchor-bound content of a book whose anchor the doctor may read', function () {
    dpswFlag(true);
    $f = dpswFixture(); // the book on B's COMPLETED first visit; A in history mode
    $f['book']->forceFill(['subjective' => 'Catatan buku yang boleh dibaca'])->save();

    $book = dpswOpen(test()->dpswUserA, $f['bookVisit'], ['sheet' => $f['book']->id])->assertOk();

    expect(Gate::forUser(test()->dpswUserA)->allows('viewAnchorContent', $f['book']))->toBeTrue()
        ->and((int) $book->viewData('activeSheet')->id)->toBe((int) $f['book']->id)
        ->and($book->getContent())->toContain('Catatan buku yang boleh dibaca')
        ->and($book->getContent())->toContain(e($f['bookVisit']->visit_number));
});

it('lets a roomless pre-exam anchor of another doctor neither bounce the workspace nor block the book (flag ON)', function (bool $on) {
    dpswFlag($on);
    $a = test()->dpswUserA;
    $anchor = dpswVisit(test()->dpswDoctorB, ClinicVisit::STATUS_REGISTERED, ['visit_date' => '2026-11-10', 'clinic_room_id' => null]);
    $aVisit = dpswVisit(test()->dpswDoctorA, ClinicVisit::STATUS_IN_PROGRESS);
    rmeSignedConsentFor($aVisit);
    $address = route('rme.visits.medical-record.show', [$anchor, 'source_visit_id' => $aVisit->id]);

    $show = $this->actingAs($a)->get($address);
    $store = $this->actingAs($a)->post(route('rme.visits.medical-record.store', $anchor), ['source_visit_id' => $aVisit->id]);
    $created = MedicalRecord::query()->where('patient_id', test()->dpswPatient->id)->first();

    if (! $on) {
        // Unchanged (and not a regression): the room gate bounces on the anchor.
        $show->assertRedirect(route('rme.visits.show', $anchor));
        $store->assertRedirect(route('rme.visits.show', $anchor));
        expect($created)->toBeNull();

        return;
    }

    $show->assertOk()->assertViewIs('rme.visits.medical-record.empty');
    $store->assertRedirect($address)->assertSessionHasNoErrors();

    expect($show->getContent())->not->toContain($anchor->visit_number)
        ->and($created)->not->toBeNull()
        ->and((int) $created->clinic_visit_id)->toBe((int) $anchor->id);
})->with(['flag ON' => [true], 'flag OFF' => [false]]);

it('keeps the room gate on the doctor\'s own roomless visit at the workspace address', function () {
    dpswFlag(true);
    $a = test()->dpswUserA;
    $anchor = dpswVisit(test()->dpswDoctorB, ClinicVisit::STATUS_REGISTERED, ['visit_date' => '2026-11-10', 'clinic_room_id' => null]);
    $own = dpswVisit(test()->dpswDoctorA, ClinicVisit::STATUS_REGISTERED, ['clinic_room_id' => null]);

    $this->actingAs($a)
        ->get(route('rme.visits.medical-record.show', [$anchor, 'source_visit_id' => $own->id]))
        ->assertRedirect(route('rme.visits.show', $own));

    // A new patient's first visit IS the doctor's own encounter: gated as before.
    $this->actingAs($a)
        ->get(route('rme.visits.medical-record.show', $own))
        ->assertRedirect(route('rme.visits.show', $own));
});

it('keeps a context-bound dual-role doctor to the working branch at the workspace address', function (bool $on) {
    dpswFlag($on);
    $other = Branch::factory()->create(['code' => 'SW02', 'is_rme_enabled' => true]);
    $anchor = dpswVisit(test()->dpswDoctorB, ClinicVisit::STATUS_COMPLETED, [
        'branch_id' => $other->id,
        'completed_at' => '2025-01-01 03:00:00',
        'visit_date' => '2025-01-01',
    ]);
    dpswSheet($anchor);
    $aVisit = dpswVisit(test()->dpswDoctorA, ClinicVisit::STATUS_CASHIER_PENDING);
    $a = test()->dpswUserA;

    // A doctor alone reads the patient's book across RME branches (history).
    dpswOpen($a, $aVisit)->assertOk();

    // A Doctor who is also Perawat works in one branch (context-bound).
    $a->assignRole('Perawat');
    $a = $a->fresh();
    dpswOpen($a, $aVisit)->assertForbidden();
})->with(['flag ON' => [true], 'flag OFF' => [false]]);

it('resolves the book a bounded number of times for a patient without a book', function () {
    dpswFlag(true);
    $a = test()->dpswUserA;
    // No book: every sheet is anchored to B's first visit, which holds no sheet.
    $first = dpswCompleted(test()->dpswDoctorB, '2025-01-01 03:00:00');
    $bookless = function (ClinicVisit $visit) use ($first): void {
        MedicalRecord::factory()->create([
            'clinic_visit_id' => $visit->id,
            'branch_id' => $visit->branch_id,
            'patient_id' => $visit->patient_id,
            'doctor_id' => $visit->doctor_id,
            'canonical_visit_id' => $first->id,
        ]);
    };
    $aVisit = dpswVisit(test()->dpswDoctorA, ClinicVisit::STATUS_CASHIER_PENDING);
    $bookless($aVisit);
    $bookless(dpswCompleted(test()->dpswDoctorB, '2026-01-01 03:00:00'));

    expect(app(PatientRmWorkspaceResolver::class)->canonicalMedicalRecord((int) test()->dpswPatient->id))->toBeNull();

    $small = dpswMeasure($a, $aVisit);

    foreach (range(2, 7) as $month) {
        $bookless(dpswCompleted(test()->dpswDoctorB, sprintf('2026-%02d-01 03:00:00', $month)));
    }
    app()->forgetScopedInstances();

    $large = dpswMeasure($a, $aVisit);

    expect($large['book'])->toBe($small['book'])
        ->and($large['readMode'])->toBe($small['readMode']);
});
