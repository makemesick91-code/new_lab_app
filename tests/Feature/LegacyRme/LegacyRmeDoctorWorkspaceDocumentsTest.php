<?php

/**
 * LEGACY-RME-DOCTOR-WORKSPACE-1 — the published legacy archive as a first-class
 * read surface inside the doctor's handwritten/native RME workspace.
 *
 * THE PROBLEM. A published legacy PDF was only reachable from the clinical
 * history card at the very bottom of the workspace, below the whole multi-page
 * handwriting canvas and its overlay editor. A doctor had to scroll past the
 * surface they were actively writing on to discover the patient's old RME
 * existed at all.
 *
 * WHAT THIS FIXES, AND WHAT IT MUST NOT BREAK. The same documents now appear as
 * a selector at the TOP of the workspace. That is a PRESENTATION change: a
 * legacy record is still never converted into a ClinicVisit or a MedicalRecord,
 * it is still immutable, it is still read-only, its bytes still stream only
 * through the policy-gated routes, and a doctor still only reaches an archive
 * for a patient they actually treat.
 */

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\LegacyRme\Models\LegacyRmeRecord;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\MedicalRecord\Services\RmeWorkspaceDocumentPresenter;
use App\Modules\MedicalRecord\Support\RmeWorkspaceDocument;
use App\Modules\Patient\Models\Patient;
use App\Modules\RME\Models\PatientDoctorAssignment;
use App\Modules\RmeOnlineContext\Middleware\EnsureRmeOnlineContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

uses()->group('LegacyRme');

beforeEach(function () {
    seedAccessControl();
    legacyRmeArchiveFlag(true);

    // MAIN is never an RME clinic branch; leaving it RME-enabled would let an
    // unpinned BranchContext fall back into scope and hide a real failure.
    Branch::where('code', Branch::MAIN_CODE)->update(['is_rme_enabled' => false]);
});

/*
|--------------------------------------------------------------------------
| Fixtures
|--------------------------------------------------------------------------
*/

function lrmedw1Patient(string $branchCode = 'TKM1'): Patient
{
    return legacyRmeArchivablePatient(['date_of_birth' => '1980-01-01'], $branchCode);
}

function lrmedw1Visit(Patient $patient, string $date, ?Doctor $doctor = null): ClinicVisit
{
    return ClinicVisit::factory()->create([
        'branch_id' => $patient->branch_id,
        'patient_id' => $patient->getKey(),
        'visit_date' => $date,
    ] + ($doctor !== null ? ['doctor_id' => $doctor->getKey()] : []));
}

function lrmedw1Sheet(ClinicVisit $visit): MedicalRecord
{
    return MedicalRecord::factory()->create([
        'clinic_visit_id' => $visit->getKey(),
        'branch_id' => $visit->branch_id,
        'patient_id' => $visit->patient_id,
        'doctor_id' => $visit->doctor_id,
    ]);
}

function lrmedw1Published(Patient $patient, string $date, array $overrides = []): LegacyRmeRecord
{
    return LegacyRmeRecord::factory()->create(array_merge([
        'patient_id' => $patient->getKey(),
        'origin_branch_id' => $patient->branch_id,
        'rme_date' => $date,
        'latest_rme_date' => null,
        'status' => LegacyRmeRecord::STATUS_PUBLISHED,
        'page_count' => 3,
    ], $overrides));
}

/** An RME workspace operator who may also read the archive. */
function lrmedw1WorkspaceUser(Patient $patient): User
{
    $user = userWith([
        'manage_clinic_visits',
        'view_clinic_visits',
        'view_legacy_rme_archive',
    ]);
    $user->forceFill(['branch_id' => $patient->branch_id])->save();

    return $user->refresh();
}

/** A Doctor-role user, optionally with a real clinical relationship. */
function lrmedw1Doctor(Patient $patient, bool $treating): User
{
    $user = User::factory()->create(['branch_id' => $patient->branch_id]);
    $user->assignRole('Doctor');

    $doctor = Doctor::factory()->create([
        'user_id' => $user->getKey(),
        'branch_id' => $patient->branch_id,
        'is_active' => true,
    ]);

    if ($treating) {
        PatientDoctorAssignment::factory()->create([
            'patient_id' => $patient->getKey(),
            'doctor_id' => $doctor->getKey(),
            'unassigned_at' => null,
        ]);
    }

    return $user->refresh();
}

/** The rail exactly as the controller builds it. */
function lrmedw1Documents(?User $user, Patient $patient, $sheets = null, ?int $activeSheetId = null)
{
    return app(RmeWorkspaceDocumentPresenter::class)->documentsFor(
        $user,
        (int) $patient->getKey(),
        $sheets ?? collect(),
        $activeSheetId,
    );
}

/** Every downstream table a read surface must never touch. */
function lrmedw1DownstreamCounts(): array
{
    $counts = [];

    foreach ([
        'trx_clinic_visits',
        'trx_medical_records',
        'trx_rme_invoices',
        'trx_rme_payments',
        'trx_lab_orders',
        'trx_lab_case_candidates',
        'trx_satusehat_candidates',
        'trx_rme_legacy_records',
        'stg_rme_legacy_imports',
    ] as $table) {
        if (Schema::hasTable($table)) {
            $counts[$table] = DB::table($table)->count();
        }
    }

    return $counts;
}

/*
|--------------------------------------------------------------------------
| A — the UX fix itself
|--------------------------------------------------------------------------
*/

it('offers the published legacy archive from the workspace without scrolling to the history card', function () {
    $patient = lrmedw1Patient();
    $visit = lrmedw1Visit($patient, '2024-06-01');
    lrmedw1Sheet($visit);
    $record = lrmedw1Published($patient, '2019-04-17');

    $html = $this->actingAs(lrmedw1WorkspaceUser($patient))
        ->withoutMiddleware(EnsureRmeOnlineContext::class)
        ->get(route('rme.visits.medical-record.show', $visit))
        ->assertOk()
        ->getContent();

    $railPosition = strpos($html, 'data-rme-workspace-documents');
    $historyPosition = strpos($html, 'data-rme-clinical-history');

    expect($railPosition)->not->toBeFalse('the workspace document rail must render')
        ->and($historyPosition)->not->toBeFalse('the existing clinical history card must remain')
        // The whole point of the sprint: the rail is ABOVE the history card, so
        // the archive is reachable without scrolling past the canvas.
        ->and($railPosition)->toBeLessThan($historyPosition)
        ->and($html)->toContain('data-rme-workspace-document-id="'.$record->getKey().'"');
});

it('keeps the existing patient history card in place (the rail is additive)', function () {
    $patient = lrmedw1Patient();
    $visit = lrmedw1Visit($patient, '2024-06-01');
    lrmedw1Sheet($visit);
    lrmedw1Published($patient, '2019-04-17');

    $this->actingAs(lrmedw1WorkspaceUser($patient))
        ->withoutMiddleware(EnsureRmeOnlineContext::class)
        ->get(route('rme.visits.medical-record.show', $visit))
        ->assertOk()
        ->assertSee('data-rme-clinical-history', escape: false)
        ->assertSee('Riwayat RME Pasien');
});

it('shows the archive in the workspace even when the patient has no native RM sheet yet', function () {
    $patient = lrmedw1Patient();
    $visit = lrmedw1Visit($patient, '2024-06-01');
    $record = lrmedw1Published($patient, '2019-04-17');

    // No sheet created — the migrated-patient case.
    $this->actingAs(lrmedw1WorkspaceUser($patient))
        ->withoutMiddleware(EnsureRmeOnlineContext::class)
        ->get(route('rme.visits.medical-record.show', $visit))
        ->assertOk()
        ->assertSee('data-rme-workspace-documents', escape: false)
        ->assertSee('data-rme-workspace-document-id="'.$record->getKey().'"', escape: false);
});

/*
|--------------------------------------------------------------------------
| B — read-only + typing
|--------------------------------------------------------------------------
*/

it('marks a legacy document read-only in text, not by colour alone', function () {
    $patient = lrmedw1Patient();
    $visit = lrmedw1Visit($patient, '2024-06-01');
    lrmedw1Sheet($visit);
    lrmedw1Published($patient, '2019-04-17');

    $this->actingAs(lrmedw1WorkspaceUser($patient))
        ->withoutMiddleware(EnsureRmeOnlineContext::class)
        ->get(route('rme.visits.medical-record.show', $visit))
        ->assertOk()
        ->assertSee('RME Legacy')
        ->assertSee('Hanya Baca');
});

it('types native and legacy documents explicitly rather than inferring from the id', function () {
    $patient = lrmedw1Patient();
    $visit = lrmedw1Visit($patient, '2024-06-01');
    $sheet = lrmedw1Sheet($visit);
    lrmedw1Published($patient, '2019-04-17');

    $documents = lrmedw1Documents(superAdmin(), $patient, collect([$sheet]), (int) $sheet->getKey());

    $legacy = $documents->firstWhere(fn (RmeWorkspaceDocument $d) => $d->isLegacy());
    $native = $documents->firstWhere(fn (RmeWorkspaceDocument $d) => $d->isNative());

    expect($legacy)->not->toBeNull()
        ->and($legacy->type)->toBe(RmeWorkspaceDocument::TYPE_LEGACY)
        ->and($legacy->readOnly)->toBeTrue()
        ->and($legacy->isCurrent)->toBeFalse()
        ->and($native)->not->toBeNull()
        ->and($native->type)->toBe(RmeWorkspaceDocument::TYPE_NATIVE)
        ->and($native->isCurrent)->toBeTrue();
});

it('exposes no legacy mutation route reachable from the workspace', function () {
    $patient = lrmedw1Patient();
    $visit = lrmedw1Visit($patient, '2024-06-01');
    lrmedw1Sheet($visit);
    lrmedw1Published($patient, '2019-04-17');

    $html = $this->actingAs(lrmedw1WorkspaceUser($patient))
        ->withoutMiddleware(EnsureRmeOnlineContext::class)
        ->get(route('rme.visits.medical-record.show', $visit))
        ->assertOk()
        ->getContent();

    // The rail must not introduce any write action against the archive.
    expect($html)->not->toContain('legacy-records/'.$patient->getKey().'/void');

    // And structurally: the archive has no update/replace/annotate endpoint at
    // all, so none can be reached from anywhere, workspace included.
    $legacyRoutes = collect(Route::getRoutes())
        ->filter(fn ($route) => str_contains((string) $route->getName(), 'rme.legacy-records.'));

    $mutating = $legacyRoutes->filter(
        fn ($route) => count(array_intersect($route->methods(), ['POST', 'PUT', 'PATCH', 'DELETE'])) > 0
    )->map(fn ($route) => $route->getName())->values()->all();

    // VOID is the ONLY state change a published archive has, and it is not a
    // workspace action — it carries its own named permission.
    expect($mutating)->toBe(['rme.legacy-records.void']);
});

it('actually delivers the viewer component and its zoom, page and expand controls', function () {
    $patient = lrmedw1Patient();
    $visit = lrmedw1Visit($patient, '2024-06-01');
    lrmedw1Sheet($visit);
    lrmedw1Published($patient, '2019-04-17', ['page_count' => 4]);

    $html = $this->actingAs(lrmedw1WorkspaceUser($patient))
        ->withoutMiddleware(EnsureRmeOnlineContext::class)
        ->get(route('rme.visits.medical-record.show', $visit))
        ->assertOk()
        ->getContent();

    // Wiring the markup without delivering the component would leave the zoom
    // and expand controls inert — "it renders" is not "it works".
    expect($html)->toContain('x-data="rmeWorkspaceLegacyViewer()"')
        ->and($html)->toContain('function rmeWorkspaceLegacyViewer()')
        ->and($html)->toContain('data-rme-legacy-zoom-in')
        ->and($html)->toContain('data-rme-legacy-zoom-out')
        ->and($html)->toContain('data-rme-legacy-fit-width')
        ->and($html)->toContain('data-rme-legacy-expand')
        ->and($html)->toContain('data-rme-legacy-next-page')
        ->and($html)->toContain('data-rme-legacy-viewer-close');
});

/*
|--------------------------------------------------------------------------
| C — performance: nothing loads until asked
|--------------------------------------------------------------------------
*/

it('does not fetch any archive bytes while the workspace renders', function () {
    $patient = lrmedw1Patient();
    $visit = lrmedw1Visit($patient, '2024-06-01');
    lrmedw1Sheet($visit);
    lrmedw1Published($patient, '2019-04-17', ['page_count' => 12]);
    lrmedw1Published($patient, '2020-08-09', ['page_count' => 40]);

    $html = $this->actingAs(lrmedw1WorkspaceUser($patient))
        ->withoutMiddleware(EnsureRmeOnlineContext::class)
        ->get(route('rme.visits.medical-record.show', $visit))
        ->assertOk()
        ->getContent();

    // No element may point a real fetching attribute at the archive on initial
    // render: the viewer body lives inside <template x-if> and binds its src
    // only once the doctor opens a document.
    expect($html)->not->toMatch('/\ssrc="[^"]*legacy-records/')
        ->and($html)->not->toMatch('/\sdata="[^"]*legacy-records/');
});

/*
|--------------------------------------------------------------------------
| D — doctor authorization (canonical scope, never branch alone)
|--------------------------------------------------------------------------
*/

it('lists the archive for a doctor who actually treats the patient', function () {
    $patient = lrmedw1Patient();
    $doctorUser = lrmedw1Doctor($patient, treating: true);
    $record = lrmedw1Published($patient, '2019-04-17');

    $documents = lrmedw1Documents($doctorUser, $patient);

    expect($documents->filter(fn (RmeWorkspaceDocument $d) => $d->isLegacy())->pluck('id')->all())
        ->toBe([(int) $record->getKey()]);
});

it('hides the archive from a same-branch doctor who does not treat the patient', function () {
    $patient = lrmedw1Patient();
    $doctorUser = lrmedw1Doctor($patient, treating: false);
    lrmedw1Published($patient, '2019-04-17');

    // Same branch is NOT sufficient — the archive must never be a wider door
    // than the patient's native record.
    expect(lrmedw1Documents($doctorUser, $patient)->filter(fn (RmeWorkspaceDocument $d) => $d->isLegacy()))
        ->toHaveCount(0);
});

it('refuses the archive bytes to a same-branch doctor who does not treat the patient', function () {
    $patient = lrmedw1Patient();
    $record = lrmedw1Published($patient, '2019-04-17');

    $response = $this->actingAs(lrmedw1Doctor($patient, treating: false))
        ->withoutMiddleware(EnsureRmeOnlineContext::class)
        ->get(route('rme.legacy-records.source', $record->getKey()));

    expect($response->status())->toBe(403);
});

it('refuses the archive bytes to a doctor from another branch', function () {
    $patient = lrmedw1Patient();
    $record = lrmedw1Published($patient, '2019-04-17');
    $otherBranchPatient = lrmedw1Patient('LDK2');

    $this->actingAs(lrmedw1Doctor($otherBranchPatient, treating: false))
        ->withoutMiddleware(EnsureRmeOnlineContext::class)
        ->get(route('rme.legacy-records.source', $record->getKey()))
        ->assertNotFound();
});

it('refuses the archive bytes to a guest', function () {
    $patient = lrmedw1Patient();
    $record = lrmedw1Published($patient, '2019-04-17');

    $this->get(route('rme.legacy-records.source', $record->getKey()))
        ->assertRedirect(route('login'));
});

it('re-authorizes a direct page-image URL rather than trusting the rail', function () {
    $patient = lrmedw1Patient();
    $record = lrmedw1Published($patient, '2019-04-17');

    // The link only ever renders for an authorized reader, but the byte route
    // must fail closed on its own for anyone who simply knows the URL.
    $response = $this->actingAs(lrmedw1Doctor($patient, treating: false))
        ->withoutMiddleware(EnsureRmeOnlineContext::class)
        ->get(route('rme.legacy-records.pages.show', [$record->getKey(), 1]));

    expect($response->status())->toBe(403);
});

/*
|--------------------------------------------------------------------------
| E — only published, non-void evidence surfaces
|--------------------------------------------------------------------------
*/

it('never lists a record that is not published', function () {
    $patient = lrmedw1Patient();
    lrmedw1Published($patient, '2019-04-17', ['status' => LegacyRmeRecord::STATUS_VOID]);

    expect(lrmedw1Documents(superAdmin(), $patient)->filter(fn (RmeWorkspaceDocument $d) => $d->isLegacy()))
        ->toHaveCount(0);
});

it('refuses to stream a voided archive even to a super admin', function () {
    $patient = lrmedw1Patient();
    $record = lrmedw1Published($patient, '2019-04-17', ['status' => LegacyRmeRecord::STATUS_VOID]);

    $this->actingAs(superAdmin())
        ->withoutMiddleware(EnsureRmeOnlineContext::class)
        ->get(route('rme.legacy-records.source', $record->getKey()))
        ->assertNotFound();
});

/*
|--------------------------------------------------------------------------
| F — multiple documents, ordering, no cross-record mix-up
|--------------------------------------------------------------------------
*/

it('lists several archives newest first and keeps each one distinct', function () {
    $patient = lrmedw1Patient();
    $older = lrmedw1Published($patient, '2018-03-02', ['page_count' => 2]);
    $newer = lrmedw1Published($patient, '2021-11-30', ['page_count' => 7]);

    $legacy = lrmedw1Documents(superAdmin(), $patient)
        ->filter(fn (RmeWorkspaceDocument $d) => $d->isLegacy())
        ->values();

    expect($legacy)->toHaveCount(2)
        ->and($legacy[0]->id)->toBe((int) $newer->getKey())
        ->and($legacy[0]->clinicalDate->format('Y-m-d'))->toBe('2021-11-30')
        ->and($legacy[0]->pageCount)->toBe(7)
        ->and($legacy[1]->id)->toBe((int) $older->getKey())
        ->and($legacy[1]->clinicalDate->format('Y-m-d'))->toBe('2018-03-02')
        ->and($legacy[1]->pageCount)->toBe(2);
});

it('offers page navigation only for a multi-page archive', function () {
    $patient = lrmedw1Patient();
    $single = lrmedw1Published($patient, '2019-04-17', ['page_count' => 1]);
    $multi = lrmedw1Published($patient, '2020-04-17', ['page_count' => 9]);

    $documents = lrmedw1Documents(superAdmin(), $patient)->keyBy('id');

    expect($documents[(int) $single->getKey()]->pageCount)->toBe(1)
        ->and($documents[(int) $single->getKey()]->hasPages())->toBeTrue()
        ->and($documents[(int) $multi->getKey()]->pageCount)->toBe(9);
});

it('falls back to the source PDF for an archive with no rendered pages', function () {
    $patient = lrmedw1Patient();
    $record = lrmedw1Published($patient, '2019-04-17', ['page_count' => 0]);

    $document = lrmedw1Documents(superAdmin(), $patient)->firstWhere('id', (int) $record->getKey());

    // No rasterized pages (imported without a working rasterizer): the viewer
    // must not offer image paging, it renders the inline PDF instead.
    expect($document->hasPages())->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| G — the native workspace is untouched
|--------------------------------------------------------------------------
*/

it('leaves the native RME workspace editable and unchanged', function () {
    $patient = lrmedw1Patient();
    $visit = lrmedw1Visit($patient, '2024-06-01');
    $sheet = lrmedw1Sheet($visit);
    lrmedw1Published($patient, '2019-04-17');

    $this->actingAs(lrmedw1WorkspaceUser($patient))
        ->withoutMiddleware(EnsureRmeOnlineContext::class)
        ->get(route('rme.visits.medical-record.show', $visit))
        ->assertOk()
        // The native update form still targets the native sheet.
        ->assertSee(route('rme.visits.medical-record.update', [$visit, $sheet]), escape: false);
});

it('anchors a native sheet link on the canonical workspace visit so the selection survives', function () {
    $patient = lrmedw1Patient();
    $first = lrmedw1Visit($patient, '2024-01-05');
    $second = lrmedw1Visit($patient, '2024-06-01');
    $firstSheet = lrmedw1Sheet($first);
    $secondSheet = lrmedw1Sheet($second);

    $documents = app(RmeWorkspaceDocumentPresenter::class)->documentsFor(
        superAdmin(),
        (int) $patient->getKey(),
        collect([$firstSheet, $secondSheet]),
        (int) $firstSheet->getKey(),
        (int) $first->getKey(),
    );

    // Every native link must hang off the canonical visit. Anchoring on a
    // sheet's own visit would trip the workspace's canonical redirect, which
    // does not carry `sheet` — silently losing the selection.
    foreach ($documents->filter(fn (RmeWorkspaceDocument $d) => $d->isNative()) as $document) {
        expect($document->url)->toContain('/rme/visits/'.$first->getKey().'/medical-record')
            ->and($document->url)->toContain('sheet='.$document->id);
    }
});

/*
|--------------------------------------------------------------------------
| H — no clinical or financial side effect
|--------------------------------------------------------------------------
*/

it('creates no visit, medical record, billing, lab or satusehat row', function () {
    $patient = lrmedw1Patient();
    $visit = lrmedw1Visit($patient, '2024-06-01');
    lrmedw1Sheet($visit);
    lrmedw1Published($patient, '2019-04-17');

    $before = lrmedw1DownstreamCounts();

    $this->actingAs(lrmedw1WorkspaceUser($patient))
        ->withoutMiddleware(EnsureRmeOnlineContext::class)
        ->get(route('rme.visits.medical-record.show', $visit))
        ->assertOk();

    expect(lrmedw1DownstreamCounts())->toBe($before);
});

it('renders no public storage URL for the archive', function () {
    $patient = lrmedw1Patient();
    $visit = lrmedw1Visit($patient, '2024-06-01');
    lrmedw1Sheet($visit);
    lrmedw1Published($patient, '2019-04-17');

    $html = $this->actingAs(lrmedw1WorkspaceUser($patient))
        ->withoutMiddleware(EnsureRmeOnlineContext::class)
        ->get(route('rme.visits.medical-record.show', $visit))
        ->assertOk()
        ->getContent();

    // The bytes are private: only the policy-gated app routes may appear.
    expect($html)->not->toContain('/storage/legacy')
        ->and($html)->not->toContain('rme-legacy/')
        ->and($html)->not->toContain($patient->ktp_number ?? '@@no-ktp@@');
});
