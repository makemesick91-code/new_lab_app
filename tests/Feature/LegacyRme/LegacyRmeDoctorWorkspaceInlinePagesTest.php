<?php

/**
 * LEGACY-RME-DOCTOR-WORKSPACE-1A — published legacy archive pages INLINE in the
 * doctor's handwritten RME page sequence.
 *
 * THE PROBLEM 1 DID NOT SOLVE. WORKSPACE-1 put the archive in a rail at the top
 * of the workspace and opened it in a separate viewer. Reachable — but the
 * doctor still had to leave the "RME Tulisan Tangan Lengkap" page-navigation
 * experience to read history. The owner asked for one thing: swipe.
 *
 * WHAT 1A CHANGES. Native handwriting pages and legacy archive pages now form
 * ONE numbered sequence behind the same `?rm_page=` index, the same
 * Sebelumnya/Berikutnya controls and the same swipe zone. A 3-page archive PDF
 * is 3 pages of that sequence, not one "open document" step.
 *
 * WHAT IT MUST NOT CHANGE. This is presentation and navigation ONLY. A legacy
 * page is never turned into a ClinicVisit, a MedicalRecord or a handwriting
 * row; it is never editable; its bytes still stream only through the
 * policy-gated private routes; and a doctor still only reaches the archive of a
 * patient they actually treat.
 */

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\LegacyRme\Models\LegacyRmeRecord;
use App\Modules\LegacyRme\Models\LegacyRmeRecordPage;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\MedicalRecord\Services\PatientRmWorkspaceResolver;
use App\Modules\MedicalRecord\Services\RmeWorkspacePageSequencer;
use App\Modules\MedicalRecord\Support\RmeWorkspacePage;
use App\Modules\Patient\Models\Patient;
use App\Modules\RME\Models\PatientDoctorAssignment;
use App\Modules\RmeOnlineContext\Middleware\EnsureRmeOnlineContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

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

function lrwip1Patient(string $branchCode = 'TKM1'): Patient
{
    return legacyRmeArchivablePatient(['date_of_birth' => '1980-01-01'], $branchCode);
}

function lrwip1Visit(Patient $patient, string $date): ClinicVisit
{
    return ClinicVisit::factory()->create([
        'branch_id' => $patient->branch_id,
        'patient_id' => $patient->getKey(),
        'visit_date' => $date,
    ]);
}

/**
 * A native RM sheet. Every sheet contributes exactly ONE native page here: the
 * page-1 read-through slot every MedicalRecord always has.
 */
function lrwip1Sheet(ClinicVisit $visit): MedicalRecord
{
    return MedicalRecord::factory()->create([
        'clinic_visit_id' => $visit->getKey(),
        'branch_id' => $visit->branch_id,
        'patient_id' => $visit->patient_id,
        'doctor_id' => $visit->doctor_id,
    ]);
}

/** A published archive with $pages ACTUALLY RENDERED pages. */
function lrwip1Published(Patient $patient, string $date, int $pages = 3, array $overrides = []): LegacyRmeRecord
{
    $record = LegacyRmeRecord::factory()->create(array_merge([
        'patient_id' => $patient->getKey(),
        'origin_branch_id' => $patient->branch_id,
        'rme_date' => $date,
        'latest_rme_date' => null,
        'status' => LegacyRmeRecord::STATUS_PUBLISHED,
        'page_count' => $pages,
    ], $overrides));

    for ($page = 1; $page <= $pages; $page++) {
        LegacyRmeRecordPage::factory()->create([
            'rme_legacy_record_id' => $record->getKey(),
            'page_number' => $page,
            'background_path' => sprintf('rme-legacy/%d/page-%d.jpg', $record->getKey(), $page),
        ]);
    }

    return $record->refresh();
}

/** An RME workspace operator who may also read the archive. */
function lrwip1WorkspaceUser(Patient $patient): User
{
    $user = userWith([
        'manage_clinic_visits',
        'view_clinic_visits',
        'view_legacy_rme_archive',
    ]);
    $user->forceFill(['branch_id' => $patient->branch_id])->save();

    return $user->refresh();
}

/** A Doctor-role user, optionally with a real treating relationship. */
function lrwip1Doctor(Patient $patient, bool $treating, ?Branch $practiceBranch = null): User
{
    $branchId = $practiceBranch?->getKey() ?? $patient->branch_id;

    $user = User::factory()->create(['branch_id' => $branchId]);
    $user->assignRole('Doctor');

    $doctor = Doctor::factory()->create([
        'user_id' => $user->getKey(),
        'branch_id' => $branchId,
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

/** The sequence exactly as the controller builds it. */
function lrwip1Sequence(?User $user, Patient $patient, $nativeBook = []): Collection
{
    return app(RmeWorkspacePageSequencer::class)->sequenceFor(
        $user,
        (int) $patient->getKey(),
        $nativeBook,
    );
}

/** Every downstream table a read surface must never touch. */
function lrwip1DownstreamCounts(): array
{
    $counts = [];

    foreach ([
        'trx_clinic_visits',
        'trx_medical_records',
        'trx_medical_record_handwriting_pages',
        'trx_odontograms',
        'trx_rme_invoices',
        'trx_rme_payments',
        'trx_lab_orders',
        'trx_lab_case_candidates',
        'trx_satusehat_candidates',
        'trx_rme_legacy_records',
        'trx_rme_legacy_record_pages',
    ] as $table) {
        $counts[$table] = DB::table($table)->count();
    }

    return $counts;
}

/**
 * The standard shape used across the navigation tests:
 * 2 native pages, then archive A (3 pages, newer), then archive B (2 pages).
 *
 * Expected unified sequence:
 *   1,2 = native | 3,4,5 = A p1..p3 | 6,7 = B p1..p2
 */
function lrwip1Fixture(): array
{
    $patient = lrwip1Patient();
    $first = lrwip1Visit($patient, '2026-02-01');
    $second = lrwip1Visit($patient, '2026-03-01');
    lrwip1Sheet($first);
    lrwip1Sheet($second);

    return [
        'patient' => $patient,
        'visit' => $first,
        'archiveA' => lrwip1Published($patient, '2020-05-10', 3),
        'archiveB' => lrwip1Published($patient, '2019-04-02', 2),
        'user' => lrwip1WorkspaceUser($patient),
    ];
}

/*
|--------------------------------------------------------------------------
| The unified sequence
|--------------------------------------------------------------------------
*/

it('merges native pages and every archive PDF page into one workspace sequence', function () {
    $f = lrwip1Fixture();

    $this->actingAs($f['user']);
    $book = app(PatientRmWorkspaceResolver::class)
        ->orderedHandwritingBookForPatient((int) $f['patient']->getKey());

    $pages = lrwip1Sequence($f['user'], $f['patient'], $book);

    // 2 native + 3 (archive A) + 2 (archive B)
    expect($pages)->toHaveCount(7)
        ->and($pages->pluck('workspaceIndex')->all())->toBe([1, 2, 3, 4, 5, 6, 7])
        ->and($pages->pluck('type')->all())->toBe([
            RmeWorkspacePage::TYPE_NATIVE,
            RmeWorkspacePage::TYPE_NATIVE,
            RmeWorkspacePage::TYPE_LEGACY,
            RmeWorkspacePage::TYPE_LEGACY,
            RmeWorkspacePage::TYPE_LEGACY,
            RmeWorkspacePage::TYPE_LEGACY,
            RmeWorkspacePage::TYPE_LEGACY,
        ]);
});

it('maps every legacy workspace page to the right record and the right PDF page', function () {
    $f = lrwip1Fixture();
    $this->actingAs($f['user']);

    $pages = lrwip1Sequence($f['user'], $f['patient'], []);

    // Newest archive first, pages ascending inside each document.
    expect($pages->map(fn (RmeWorkspacePage $p) => [$p->legacyRecordId, $p->legacyPdfPage])->all())->toBe([
        [(int) $f['archiveA']->getKey(), 1],
        [(int) $f['archiveA']->getKey(), 2],
        [(int) $f['archiveA']->getKey(), 3],
        [(int) $f['archiveB']->getKey(), 1],
        [(int) $f['archiveB']->getKey(), 2],
    ]);

    // Per-document context is independent of the global workspace index.
    expect($pages->last()->legacyPageContext())->toBe('Halaman 2 dari 2')
        ->and($pages->first()->legacyPageContext())->toBe('Halaman 1 dari 3');
});

it('marks every legacy page read-only and every native page editable', function () {
    $f = lrwip1Fixture();
    $this->actingAs($f['user']);
    $book = app(PatientRmWorkspaceResolver::class)
        ->orderedHandwritingBookForPatient((int) $f['patient']->getKey());

    $pages = lrwip1Sequence($f['user'], $f['patient'], $book);

    foreach ($pages as $page) {
        expect($page->readonly)->toBe($page->isLegacy());
    }
});

it('keeps a multi-document archive from mixing up its pages', function () {
    $f = lrwip1Fixture();
    $this->actingAs($f['user']);

    $pages = lrwip1Sequence($f['user'], $f['patient'], []);

    $byRecord = $pages->groupBy('legacyRecordId');

    expect($byRecord->get((int) $f['archiveA']->getKey())->pluck('legacyPdfPage')->all())->toBe([1, 2, 3])
        ->and($byRecord->get((int) $f['archiveB']->getKey())->pluck('legacyPdfPage')->all())->toBe([1, 2]);

    foreach ($pages as $page) {
        expect($page->legacyPageImageUrl)->toContain('/rme/legacy-records/'.$page->legacyRecordId.'/pages/'.$page->legacyPdfPage);
    }
});

/*
|--------------------------------------------------------------------------
| Navigation through the SAME controls
|--------------------------------------------------------------------------
*/

it('counts the whole unified sequence in the global page navigation', function () {
    $f = lrwip1Fixture();

    $this->actingAs($f['user'])
        ->get(route('rme.visits.medical-record.show', $f['visit']))
        ->assertOk()
        ->assertSee('Halaman 1 dari 7');
});

it('steps from the last native page onto the first archive page with Berikutnya', function () {
    $f = lrwip1Fixture();

    // Page 2 is the last native page; page 3 is archive A page 1.
    $response = $this->actingAs($f['user'])
        ->get(route('rme.visits.medical-record.show', [$f['visit'], 'rm_page' => 2]));

    $response->assertOk()
        ->assertSee('Halaman 2 dari 7')
        ->assertSee('data-active-page-type="native"', false)
        ->assertSee('rm_page=3', false);

    $this->actingAs($f['user'])
        ->get(route('rme.visits.medical-record.show', [$f['visit'], 'rm_page' => 3]))
        ->assertOk()
        ->assertSee('Halaman 3 dari 7')
        ->assertSee('data-active-page-type="legacy"', false)
        ->assertSee('RME Legacy');
});

it('steps from one archive page to the next page of the same document', function () {
    $f = lrwip1Fixture();

    $response = $this->actingAs($f['user'])
        ->get(route('rme.visits.medical-record.show', [$f['visit'], 'rm_page' => 4]));

    $response->assertOk()
        ->assertSee('data-legacy-record-id="'.$f['archiveA']->getKey().'"', false)
        ->assertSee('data-legacy-pdf-page="2"', false)
        ->assertSee('PDF Halaman 2 dari 3');
});

it('steps across the document boundary onto the next archive', function () {
    $f = lrwip1Fixture();

    // 5 = archive A last page, 6 = archive B first page.
    $this->actingAs($f['user'])
        ->get(route('rme.visits.medical-record.show', [$f['visit'], 'rm_page' => 5]))
        ->assertOk()
        ->assertSee('data-legacy-record-id="'.$f['archiveA']->getKey().'"', false)
        ->assertSee('data-legacy-pdf-page="3"', false);

    $this->actingAs($f['user'])
        ->get(route('rme.visits.medical-record.show', [$f['visit'], 'rm_page' => 6]))
        ->assertOk()
        ->assertSee('data-legacy-record-id="'.$f['archiveB']->getKey().'"', false)
        ->assertSee('data-legacy-pdf-page="1"', false);
});

it('navigates backwards symmetrically', function () {
    $f = lrwip1Fixture();

    $response = $this->actingAs($f['user'])
        ->get(route('rme.visits.medical-record.show', [$f['visit'], 'rm_page' => 6]));

    // Previous from 6 must offer 5, and next must offer 7.
    $response->assertOk()
        ->assertSee('rm_page=5', false)
        ->assertSee('rm_page=7', false);

    $this->actingAs($f['user'])
        ->get(route('rme.visits.medical-record.show', [$f['visit'], 'rm_page' => 5]))
        ->assertOk()
        ->assertSee('data-legacy-pdf-page="3"', false);
});

it('exposes swipe navigation targets on an archive page', function () {
    $f = lrwip1Fixture();

    $this->actingAs($f['user'])
        ->get(route('rme.visits.medical-record.show', [$f['visit'], 'rm_page' => 4]))
        ->assertOk()
        ->assertSee('data-rm-swipe-zone', false)
        ->assertSee('data-prev-url', false)
        ->assertSee('data-next-url', false);
});

it('marks legacy page buttons distinguishably without relying on colour', function () {
    $f = lrwip1Fixture();

    $this->actingAs($f['user'])
        ->get(route('rme.visits.medical-record.show', $f['visit']))
        ->assertOk()
        ->assertSee('data-page-type="legacy"', false)
        ->assertSee('data-page-type="native"', false)
        ->assertSee('arsip RME lama, hanya baca', false)
        ->assertSee('>L3</a>', false);
});

it('clamps a crafted out-of-range rm_page to the sequence', function () {
    $f = lrwip1Fixture();

    $this->actingAs($f['user'])
        ->get(route('rme.visits.medical-record.show', [$f['visit'], 'rm_page' => 9999]))
        ->assertOk()
        ->assertSee('Halaman 7 dari 7');

    $this->actingAs($f['user'])
        ->get(route('rme.visits.medical-record.show', [$f['visit'], 'rm_page' => -5]))
        ->assertOk()
        ->assertSee('Halaman 1 dari 7');
});

/*
|--------------------------------------------------------------------------
| Read-only enforcement
|--------------------------------------------------------------------------
*/

it('never renders a writable canvas surface for an archive page', function () {
    $f = lrwip1Fixture();

    $legacy = $this->actingAs($f['user'])
        ->get(route('rme.visits.medical-record.show', [$f['visit'], 'rm_page' => 3]))
        ->assertOk();

    $html = $legacy->getContent();

    // The editable page figure (the click-to-write surface) is not rendered at
    // all while an archive page is active, so no pointer/stylus input can be
    // aimed at the evidence.
    expect($html)->not->toContain('class="rm-page-preview')
        ->and($html)->toContain('data-legacy-readonly-badge')
        ->and($html)->toContain('Hanya Baca');
});

it('offers no mutating action on an archive page', function () {
    $f = lrwip1Fixture();

    $html = $this->actingAs($f['user'])
        ->get(route('rme.visits.medical-record.show', [$f['visit'], 'rm_page' => 3]))
        ->assertOk()
        ->getContent();

    foreach (['/void', 'legacy-records/'.$f['archiveA']->getKey().'/update', 'method="POST" action="'.route('rme.legacy-records.source', $f['archiveA'])] as $forbidden) {
        expect($html)->not->toContain($forbidden);
    }
});

it('registers no mutating legacy route beyond the governance-protected void', function () {
    $mutating = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => str_contains((string) $route->getName(), 'legacy-records.'))
        ->reject(fn ($route) => $route->methods() === ['GET', 'HEAD'])
        ->map(fn ($route) => $route->getName())
        ->values()
        ->all();

    expect($mutating)->toBe(['rme.legacy-records.void']);
});

it('offers zoom and fullscreen reading controls on an archive page', function () {
    $f = lrwip1Fixture();

    $this->actingAs($f['user'])
        ->get(route('rme.visits.medical-record.show', [$f['visit'], 'rm_page' => 3]))
        ->assertOk()
        ->assertSee('data-legacy-zoom-in', false)
        ->assertSee('data-legacy-zoom-out', false)
        ->assertSee('data-legacy-zoom-reset', false)
        ->assertSee('data-legacy-fullscreen', false)
        // zoom genuinely drives the rendered width, it is not a decorative button
        ->assertSee("'width:' + (zoom * 100) + '%'", false);
});

/*
|--------------------------------------------------------------------------
| Native workflow must not regress
|--------------------------------------------------------------------------
*/

it('keeps the native page editable and the add-page action native-only', function () {
    $f = lrwip1Fixture();
    $canonicalSheet = MedicalRecord::where('clinic_visit_id', $f['visit']->getKey())->firstOrFail();
    $handwritingEndpoint = route('rme.visits.medical-record.handwriting.store', [$f['visit'], $canonicalSheet]);

    $this->actingAs($f['user'])
        ->get(route('rme.visits.medical-record.show', [$f['visit'], 'rm_page' => 1]))
        ->assertOk()
        ->assertSee('class="rm-page-preview', false)
        ->assertSee('id="rme-canvas"', false);

    // On an ARCHIVE page "+ Tambah Halaman RM" still exists and still targets
    // the canonical NATIVE handwriting endpoint. It can never append to, or
    // modify, archive evidence.
    $this->actingAs($f['user'])
        ->get(route('rme.visits.medical-record.show', [$f['visit'], 'rm_page' => 3]))
        ->assertOk()
        ->assertSee('id="add-rm-page-btn"', false)
        ->assertSee('data-form-action="'.e($handwritingEndpoint).'"', false);
});

it('warns before discarding unsaved handwriting instead of losing it silently', function () {
    $f = lrwip1Fixture();

    $this->actingAs($f['user'])
        ->get(route('rme.visits.medical-record.show', $f['visit']))
        ->assertOk()
        ->assertSee('beforeunload', false)
        ->assertSee('belum disimpan', false);
});

it('leaves a patient with no archive on exactly the native experience', function () {
    $patient = lrwip1Patient();
    $visit = lrwip1Visit($patient, '2026-02-01');
    lrwip1Sheet($visit);
    $user = lrwip1WorkspaceUser($patient);

    $response = $this->actingAs($user)
        ->get(route('rme.visits.medical-record.show', $visit))
        ->assertOk();

    $response->assertSee('Halaman 1 dari 1')
        ->assertSee('data-active-page-type="native"', false)
        ->assertDontSee('data-page-type="legacy"', false)
        ->assertDontSee('data-rme-legacy-inline-page', false);
});

it('shows archive pages inline for a migrated patient with no native sheet at all', function () {
    $patient = lrwip1Patient();
    $visit = lrwip1Visit($patient, '2026-02-01');
    $archive = lrwip1Published($patient, '2020-05-10', 2);
    $user = lrwip1WorkspaceUser($patient);

    expect(MedicalRecord::where('patient_id', $patient->getKey())->count())->toBe(0);

    $this->actingAs($user)
        ->get(route('rme.visits.medical-record.show', $visit))
        ->assertOk()
        ->assertSee('Halaman 1 dari 2')
        ->assertSee('data-legacy-record-id="'.$archive->getKey().'"', false)
        ->assertSee('data-rm-swipe-zone', false)
        ->assertSee('Hanya Baca');
});

/*
|--------------------------------------------------------------------------
| Authorization — WORKSPACE-1 boundaries must hold unchanged
|--------------------------------------------------------------------------
*/

it('gives a treating doctor the archive pages in the sequence', function () {
    $patient = lrwip1Patient();
    lrwip1Published($patient, '2020-05-10', 3);
    $doctor = lrwip1Doctor($patient, treating: true);

    expect(lrwip1Sequence($doctor, $patient)->count())->toBe(3);
});

it('gives a same-branch doctor with no treating relationship nothing', function () {
    $patient = lrwip1Patient();
    $archive = lrwip1Published($patient, '2020-05-10', 3);
    $doctor = lrwip1Doctor($patient, treating: false);

    expect(lrwip1Sequence($doctor, $patient))->toBeEmpty();

    $this->actingAs($doctor)
        ->withoutMiddleware(EnsureRmeOnlineContext::class)
        ->get(route('rme.legacy-records.pages.show', [$archive, 1]))
        ->assertForbidden();
});

it('gives a doctor practising in another branch nothing', function () {
    $patient = lrwip1Patient('TKM1');
    $archive = lrwip1Published($patient, '2020-05-10', 3);

    $otherBranch = legacyRmeBranch('LDK2', 'Cabang Landak');
    $doctor = lrwip1Doctor($patient, treating: false, practiceBranch: $otherBranch);

    expect(lrwip1Sequence($doctor, $patient))->toBeEmpty();

    $this->actingAs($doctor)
        ->withoutMiddleware(EnsureRmeOnlineContext::class)
        ->get(route('rme.legacy-records.pages.show', [$archive, 1]))
        ->assertNotFound();
});

it('refuses archive bytes to a guest', function () {
    $patient = lrwip1Patient();
    $archive = lrwip1Published($patient, '2020-05-10', 1);

    $this->get(route('rme.legacy-records.pages.show', [$archive, 1]))
        ->assertRedirect(route('login'));
});

it('never lets one patient sequence reach another patient archive', function () {
    $patientA = lrwip1Patient();
    $patientB = lrwip1Patient();
    $archiveB = lrwip1Published($patientB, '2020-05-10', 2);
    $user = lrwip1WorkspaceUser($patientA);

    $pages = lrwip1Sequence($user, $patientA);

    expect($pages)->toBeEmpty()
        ->and($pages->pluck('legacyRecordId')->all())->not->toContain((int) $archiveB->getKey());
});

it('keeps a non-published archive out of the sequence entirely', function () {
    $patient = lrwip1Patient();
    lrwip1Published($patient, '2020-05-10', 3, ['status' => LegacyRmeRecord::STATUS_VOID]);
    $user = lrwip1WorkspaceUser($patient);

    expect(lrwip1Sequence($user, $patient))->toBeEmpty();
});

/*
|--------------------------------------------------------------------------
| Degradation, performance, architecture
|--------------------------------------------------------------------------
*/

it('keeps the workspace usable when an archive has no rendered pages', function () {
    $patient = lrwip1Patient();
    $visit = lrwip1Visit($patient, '2026-02-01');
    lrwip1Sheet($visit);
    // Declared page_count, but the rasteriser never produced a single page.
    $archive = lrwip1Published($patient, '2020-05-10', 0, ['page_count' => 4]);
    $user = lrwip1WorkspaceUser($patient);

    $pages = lrwip1Sequence($user, $patient);

    // Exactly ONE fallback page — never four pages that could only 404.
    expect($pages)->toHaveCount(1)
        ->and($pages->first()->hasRenderedPage())->toBeFalse()
        ->and($pages->first()->legacySourceUrl)->toContain('/rme/legacy-records/'.$archive->getKey().'/source');

    $response = $this->actingAs($user)
        ->get(route('rme.visits.medical-record.show', [$visit, 'rm_page' => 2]))
        ->assertOk();

    // The native page stays reachable and no filesystem path is leaked.
    $response->assertSee('Halaman 2 dari 2')
        ->assertSee('data-legacy-page-fallback', false)
        ->assertDontSee('rme-legacy/')
        ->assertSee('rm_page=1', false);
});

it('fetches only the active archive page image, never every private PDF', function () {
    $f = lrwip1Fixture();

    $html = $this->actingAs($f['user'])
        ->get(route('rme.visits.medical-record.show', [$f['visit'], 'rm_page' => 3]))
        ->assertOk()
        ->getContent();

    $pageRequests = preg_match_all('#/rme/legacy-records/\d+/pages/\d+#', $html);

    // One image request for the ACTIVE page. The rail may also link documents,
    // but no second page's bytes are pulled into the page.
    expect($pageRequests)->toBe(1)
        ->and($html)->toContain('/rme/legacy-records/'.$f['archiveA']->getKey().'/pages/1');
});

it('reads the patient archive without an N+1 and without resolving it twice', function () {
    $f = lrwip1Fixture();

    DB::enableQueryLog();
    DB::flushQueryLog();

    $this->actingAs($f['user'])
        ->get(route('rme.visits.medical-record.show', $f['visit']))
        ->assertOk();

    $queries = collect(DB::getQueryLog())->pluck('query');
    DB::disableQueryLog();

    // The archive list is resolved ONCE for the whole request — the unified
    // sequence and the document rail share it instead of each asking again.
    $recordListQueries = $queries
        ->filter(fn (string $sql): bool => str_contains($sql, 'trx_rme_legacy_records')
            && str_contains($sql, 'patient_id')
            && ! str_contains($sql, 'count('))
        ->count();

    expect($recordListQueries)->toBe(1);

    // Rendered-page counts are ONE aggregate for all documents, never one
    // query per archived document.
    $pageCountQueries = $queries
        ->filter(fn (string $sql): bool => str_contains($sql, 'trx_rme_legacy_record_pages')
            && str_contains($sql, 'count('))
        ->count();

    expect($pageCountQueries)->toBeLessThanOrEqual(1);
});

it('delegates archive filtering to the canonical read service instead of re-deriving it', function () {
    $source = file_get_contents(app_path('Modules/MedicalRecord/Services/RmeWorkspacePageSequencer.php'));

    expect($source)->toContain('publishedRecordsFor')
        // No second definition of "which archive may this user see".
        ->and($source)->not->toContain('STATUS_PUBLISHED')
        ->and($source)->not->toContain('branchIdsFor')
        ->and($source)->not->toContain('doctorCanAccessPatient')
        ->and($source)->not->toContain('LegacyRmeRecord::query');
});

it('creates no clinical, billing, lab or SATUSEHAT record while reading the archive', function () {
    $f = lrwip1Fixture();

    $before = lrwip1DownstreamCounts();

    foreach ([1, 2, 3, 4, 5, 6, 7] as $page) {
        $this->actingAs($f['user'])
            ->get(route('rme.visits.medical-record.show', [$f['visit'], 'rm_page' => $page]))
            ->assertOk();
    }

    expect(lrwip1DownstreamCounts())->toBe($before);
});

it('never renders the patient KTP on an archive page', function () {
    $patient = lrwip1Patient();
    $patient->forceFill(['ktp_number' => '7371010101800001'])->save();
    $visit = lrwip1Visit($patient, '2026-02-01');
    lrwip1Sheet($visit);
    lrwip1Published($patient, '2020-05-10', 2);
    $user = lrwip1WorkspaceUser($patient);

    $this->actingAs($user)
        ->get(route('rme.visits.medical-record.show', [$visit, 'rm_page' => 2]))
        ->assertOk()
        ->assertDontSee('7371010101800001');
});
