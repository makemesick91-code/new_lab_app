<?php

/**
 * LEGACY-RME-PDF-1D — the clinical read surface: a doctor's read-only viewer,
 * the printable view and the PDF export.
 *
 * The doctor tier is deliberately a DIFFERENT permission from the intake tier.
 * These tests prove it grants reading and nothing else, that it stays pinned to
 * the doctor's own branch, and that print/export are never a weaker door to the
 * private disk than the viewer they sit behind.
 */

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\LegacyRme\Interfaces\LegacyRmePdfInspectorInterface;
use App\Modules\LegacyRme\Interfaces\LegacyRmePdfRasterizerInterface;
use App\Modules\LegacyRme\Models\LegacyRmeRecord;
use App\Modules\LegacyRme\Policies\LegacyRmeRecordPolicy;
use App\Modules\LegacyRme\Services\LegacyRmeImportProcessingService;
use App\Modules\LegacyRme\Services\LegacyRmeImportService;
use App\Modules\LegacyRme\Services\LegacyRmePatientHistoryService;
use App\Modules\LegacyRme\Services\LegacyRmePublishService;
use App\Modules\LegacyRme\Services\LegacyRmeVoidService;
use App\Modules\LegacyRme\Services\Pdf\FakeLegacyRmePdfInspector;
use App\Modules\LegacyRme\Services\Pdf\FakeLegacyRmePdfRasterizer;
use App\Modules\LegacyRme\Support\LegacyRmeAuditEvent;
use App\Modules\LegacyRme\Support\LegacyRmeWorkspaceScope;
use App\Modules\Patient\Models\Patient;
use App\Modules\RME\Models\PatientDoctorAssignment;
use App\Modules\RmeOnlineContext\Middleware\EnsureRmeOnlineContext;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

uses()->group('LegacyRme');

/*
 * dompdf decodes the embedded PNG pages through GD, so a PDF is only actually
 * rendered where GD is installed. CI (`extensions: … gd, exif`) and the
 * production host both have it; a bare local CLI often does not. Only the two
 * tests that genuinely render are guarded — every authorization, branch-scope,
 * void and feature-flag assertion around export still runs everywhere, because
 * those all refuse before dompdf is ever reached.
 */
const LRME1D_NO_GD = 'requires the GD extension (dompdf image decoding)';

beforeEach(function () {
    seedAccessControl();
    legacyRmeArchiveFlag(true);
    Storage::fake('legacy_rme_private');
    Bus::fake();
});

function lrme1dReadPublished(int $pages = 2): LegacyRmeRecord
{
    app()->instance(LegacyRmePdfInspectorInterface::class, (new FakeLegacyRmePdfInspector)->withPages($pages));
    app()->instance(LegacyRmePdfRasterizerInterface::class, (new FakeLegacyRmePdfRasterizer)->withPages($pages));

    $patient = legacyRmeArchivablePatient(['date_of_birth' => '1990-01-01']);
    legacyRmeNativeVisit($patient, '2022-03-10');

    $import = app(LegacyRmeImportService::class)->createFromUpload(
        $patient, '2020-05-01', null, legacyRmePdfUpload('arsip.pdf', $pages), superAdmin(),
    );

    app(LegacyRmeImportProcessingService::class)->process($import->getKey());

    return app(LegacyRmePublishService::class)->publish(
        app(LegacyRmePublishService::class)->review($import->refresh(), superAdmin())->refresh(),
        [],
        superAdmin(),
    );
}

/**
 * An RME-enabled branch. The scope only ever returns branches that are BOTH
 * active and RME-enabled, so a test that forgets this resolves to an empty
 * scope and silently proves nothing.
 */
function lrme1dRmeBranch(): Branch
{
    return Branch::factory()->create(['is_active' => true, 'is_rme_enabled' => true]);
}

/** Pin a published archive to a branch so branch scope is actually exercised. */
function lrme1dPublishedInBranch(Branch $branch, int $pages = 2): LegacyRmeRecord
{
    $record = lrme1dReadPublished($pages);
    $record->forceFill(['origin_branch_id' => $branch->getKey()])->save();

    return $record->refresh();
}

/** A doctor who may READ the archive, pinned to one branch. */
function lrme1dDoctor(?int $branchId): User
{
    $doctor = User::factory()->create(['branch_id' => $branchId]);
    $doctor->assignRole('Doctor');

    return $doctor->refresh();
}

/**
 * LEGACY-RME-PDF-ROLL-2 — a doctor USER linked to a real doctor record, plus an
 * active assignment to the record's patient.
 *
 * Branch alone no longer authorizes a clinical read: a legacy archive must
 * never be more visible to a doctor than the patient's native record, and
 * native access requires a real clinical relationship. Establishing one is
 * therefore part of the positive fixture, not incidental setup.
 */
function lrme1dTreatingDoctor(?int $branchId, LegacyRmeRecord $record): User
{
    $user = lrme1dDoctor($branchId);

    $doctor = Doctor::factory()->create([
        'user_id' => $user->getKey(),
        'is_active' => true,
    ]);

    PatientDoctorAssignment::factory()->create([
        'patient_id' => $record->patient_id,
        'doctor_id' => $doctor->getKey(),
        'unassigned_at' => null,
    ]);

    return $user->refresh();
}

/*
|--------------------------------------------------------------------------
| The permission is real, separate, and least-privilege
|--------------------------------------------------------------------------
*/

it('grants the doctor role archive READ and nothing else', function () {
    $doctor = lrme1dDoctor(null);

    expect($doctor->can('view_legacy_rme_archive'))->toBeTrue()
        ->and($doctor->can('view_legacy_rme_imports'))->toBeFalse()
        ->and($doctor->can('create_legacy_rme_imports'))->toBeFalse()
        ->and($doctor->can('review_legacy_rme_imports'))->toBeFalse()
        ->and($doctor->can('publish_legacy_rme_imports'))->toBeFalse()
        ->and($doctor->can('void_legacy_rme_imports'))->toBeFalse();
});

it('keeps the clinical read permission out of the governance tier', function () {
    // Governance tier means "sees every RME branch". A doctor must never be
    // widened past their own branch just because they can read an archive.
    expect(LegacyRmeWorkspaceScope::GOVERNANCE_PERMISSIONS)
        ->not->toContain('view_legacy_rme_archive')
        ->and(LegacyRmeRecordPolicy::READ_PERMISSIONS)
        ->toContain('view_legacy_rme_archive', 'view_legacy_rme_imports');
});

/*
|--------------------------------------------------------------------------
| The doctor viewer
|--------------------------------------------------------------------------
*/

it('lets a doctor read a published archive from their own branch', function () {
    $branch = lrme1dRmeBranch();
    $record = lrme1dPublishedInBranch($branch);
    $doctor = lrme1dTreatingDoctor((int) $branch->getKey(), $record);

    $this->actingAs($doctor)
        ->withoutMiddleware(EnsureRmeOnlineContext::class)
        ->get(route('rme.legacy-records.show', $record->getKey()))
        ->assertOk()
        ->assertSee('Arsip RME Lama');
});

it('hides an archive from another branch behind a 404, never a 403', function () {
    $record = lrme1dPublishedInBranch(lrme1dRmeBranch());
    $doctor = lrme1dDoctor((int) lrme1dRmeBranch()->getKey());

    $this->actingAs($doctor)
        ->withoutMiddleware(EnsureRmeOnlineContext::class)
        ->get(route('rme.legacy-records.show', $record->getKey()))
        ->assertNotFound();
});

it('never widens a clinical reader past a single branch', function () {
    // The security property is non-widening, not emptiness: BranchContext has a
    // documented fallback chain (online context → users.branch_id → relation →
    // MAIN/first active), so an unpinned doctor still resolves SOME branch. What
    // must never happen is the governance-tier behaviour of seeing them all.
    $branchA = lrme1dRmeBranch();
    $branchB = lrme1dRmeBranch();
    $record = lrme1dPublishedInBranch($branchB);

    $doctor = lrme1dDoctor((int) $branchA->getKey());
    $scope = app(LegacyRmeWorkspaceScope::class);

    expect(count($scope->branchIdsFor($doctor)))->toBeLessThanOrEqual(1)
        ->and($scope->branchIdsFor($doctor))->not->toContain($branchB->getKey())
        // Rows with no provenance stay governance-only.
        ->and($scope->includesUnscopedRowsFor($doctor))->toBeFalse();

    $this->actingAs($doctor)
        ->withoutMiddleware(EnsureRmeOnlineContext::class)
        ->get(route('rme.legacy-records.show', $record->getKey()))
        ->assertNotFound();
});

it('hides an archive with no branch provenance from a clinical reader', function () {
    $branch = lrme1dRmeBranch();
    $record = lrme1dPublishedInBranch($branch);
    $record->forceFill(['origin_branch_id' => null])->save();

    $this->actingAs(lrme1dDoctor((int) $branch->getKey()))
        ->withoutMiddleware(EnsureRmeOnlineContext::class)
        ->get(route('rme.legacy-records.show', $record->getKey()))
        ->assertNotFound();
});

it('refuses every write action from a doctor', function () {
    $branch = lrme1dRmeBranch();
    $record = lrme1dPublishedInBranch($branch);
    $doctor = lrme1dTreatingDoctor((int) $branch->getKey(), $record);

    $this->actingAs($doctor)
        ->withoutMiddleware(EnsureRmeOnlineContext::class)
        ->post(route('rme.legacy-records.void', $record->getKey()), ['void_reason' => 'Alasan yang cukup panjang untuk lolos validasi.'])
        ->assertForbidden();

    expect($record->refresh()->isPublished())->toBeTrue();
});

it('shows a doctor the archive in the patient history', function () {
    $branch = lrme1dRmeBranch();
    $record = lrme1dPublishedInBranch($branch);
    $doctor = lrme1dTreatingDoctor((int) $branch->getKey(), $record);

    expect(app(LegacyRmePatientHistoryService::class)->publishedRecordsFor($doctor, (int) $record->patient_id))
        ->toHaveCount(1);
});

it('shows a doctor from another branch nothing in the patient history', function () {
    $record = lrme1dPublishedInBranch(lrme1dRmeBranch());
    $other = lrme1dRmeBranch();

    expect(app(LegacyRmePatientHistoryService::class)
        ->publishedRecordsFor(lrme1dDoctor((int) $other->getKey()), (int) $record->patient_id))
        ->toBeEmpty();
});

/*
|--------------------------------------------------------------------------
| Print
|--------------------------------------------------------------------------
*/

it('renders a print view that is unmistakably a legacy archive', function () {
    $record = lrme1dReadPublished();

    $this->actingAs(superAdmin())
        ->get(route('rme.legacy-records.print', $record->getKey()))
        ->assertOk()
        ->assertSee('ARSIP RME LAMA')
        ->assertSee('HANYA BACA', false)
        ->assertSee('window.print()', false);
});

it('never renders a storage path or a KTP in the print view', function () {
    $record = lrme1dReadPublished();
    $patient = Patient::query()->find($record->patient_id);
    $patient->forceFill(['ktp_number' => '7371010101010001'])->save();

    $body = $this->actingAs(superAdmin())
        ->get(route('rme.legacy-records.print', $record->getKey()))
        ->assertOk()
        ->getContent();

    expect($body)->not->toContain('7371010101010001')
        ->and($body)->not->toContain($record->source_pdf_path)
        ->and($body)->not->toContain('legacy_rme_private')
        ->and($body)->not->toContain(storage_path());
});

it('lets a doctor print their own branch archive', function () {
    $branch = lrme1dRmeBranch();
    $record = lrme1dPublishedInBranch($branch);
    $doctor = lrme1dTreatingDoctor((int) $branch->getKey(), $record);

    $this->actingAs($doctor)
        ->withoutMiddleware(EnsureRmeOnlineContext::class)
        ->get(route('rme.legacy-records.print', $record->getKey()))
        ->assertOk();
});

it('refuses to print an archive from another branch', function () {
    $record = lrme1dPublishedInBranch(lrme1dRmeBranch());
    $other = lrme1dRmeBranch();

    $this->actingAs(lrme1dDoctor((int) $other->getKey()))
        ->withoutMiddleware(EnsureRmeOnlineContext::class)
        ->get(route('rme.legacy-records.print', $record->getKey()))
        ->assertNotFound();
});

it('refuses to print a voided archive', function () {
    $record = lrme1dReadPublished();
    app(LegacyRmeVoidService::class)->void($record, 'Salah pasien, ditarik kembali.', superAdmin());

    $this->actingAs(superAdmin())
        ->get(route('rme.legacy-records.print', $record->getKey()))
        ->assertNotFound();
});

it('audits a print', function () {
    $record = lrme1dReadPublished();

    $this->actingAs(superAdmin())->get(route('rme.legacy-records.print', $record->getKey()))->assertOk();

    expect(DB::table('sys_audit_logs')->where('action', LegacyRmeAuditEvent::RECORD_PRINTED)->count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| PDF export
|--------------------------------------------------------------------------
*/

it('exports a real PDF marked as a legacy archive', function () {
    $record = lrme1dReadPublished();

    $response = $this->actingAs(superAdmin())
        ->get(route('rme.legacy-records.export', $record->getKey()))
        ->assertOk();

    expect($response->headers->get('content-type'))->toContain('application/pdf')
        ->and($response->headers->get('content-disposition'))->toContain('attachment')
        // A generic filename: no patient name, no medical-record number.
        ->and($response->headers->get('content-disposition'))->toContain('arsip-rme-lama-')
        // dompdf's download() returns a plain Response, not a StreamedResponse.
        ->and(substr($response->getContent(), 0, 4))->toBe('%PDF');
})->skip(fn () => ! extension_loaded('gd'), LRME1D_NO_GD);

it('never references an identity field or a storage path in the print and export templates', function (string $view) {
    // A static scan so this holds even where GD is missing and the PDF is never
    // rendered: the template itself must not be able to emit a KTP/NIK or a
    // storage path, whatever data it is handed.
    $source = file_get_contents(resource_path('views/rme/legacy-records/'.$view.'.blade.php'));

    expect($source)->not->toContain('ktp_number')
        ->and($source)->not->toContain('->ktp')
        ->and($source)->not->toContain('->nik')
        ->and($source)->not->toContain('identity_number')
        ->and($source)->not->toContain('source_pdf_path')
        ->and($source)->not->toContain('background_path')
        ->and($source)->not->toContain('storage_path');
})->with(['print', 'export-pdf']);

it('refuses to export an archive from another branch', function () {
    $record = lrme1dPublishedInBranch(lrme1dRmeBranch());
    $other = lrme1dRmeBranch();

    $this->actingAs(lrme1dDoctor((int) $other->getKey()))
        ->withoutMiddleware(EnsureRmeOnlineContext::class)
        ->get(route('rme.legacy-records.export', $record->getKey()))
        ->assertNotFound();
});

it('refuses to export a voided archive', function () {
    $record = lrme1dReadPublished();
    app(LegacyRmeVoidService::class)->void($record, 'Salah pasien, ditarik kembali.', superAdmin());

    $this->actingAs(superAdmin())
        ->get(route('rme.legacy-records.export', $record->getKey()))
        ->assertNotFound();
});

it('audits an export', function () {
    $record = lrme1dReadPublished();

    $this->actingAs(superAdmin())->get(route('rme.legacy-records.export', $record->getKey()))->assertOk();

    expect(DB::table('sys_audit_logs')->where('action', LegacyRmeAuditEvent::RECORD_EXPORTED)->count())->toBe(1);
})->skip(fn () => ! extension_loaded('gd'), LRME1D_NO_GD);

/*
|--------------------------------------------------------------------------
| Feature flag and guests
|--------------------------------------------------------------------------
*/

// LEGACY-RME-PDF-HISTORY-1A — this test previously asserted a 404 on all three
// routes while the flag was off. That was the defect being corrected: the flag
// is the MIGRATION switch, and an already-published archive is the patient's
// real clinical history, which an authorized reader must keep being able to
// read (and print, and export) without ingestion being re-opened.
it('still serves show, print and export while the migration capability is off', function (string $routeName) {
    $record = lrme1dReadPublished();
    legacyRmeArchiveFlag(false);

    $this->actingAs(superAdmin())->get(route($routeName, $record->getKey()))->assertOk();
})->with([
    'print' => ['rme.legacy-records.print'],
    'export' => ['rme.legacy-records.export'],
    'show' => ['rme.legacy-records.show'],
])->skip(fn () => ! extension_loaded('gd'), LRME1D_NO_GD);

it('sends a guest to the login screen rather than answering 403', function (string $routeName) {
    $record = lrme1dReadPublished();

    $this->get(route($routeName, $record->getKey()))->assertRedirect(route('login'));
})->with([
    'print' => ['rme.legacy-records.print'],
    'export' => ['rme.legacy-records.export'],
    'show' => ['rme.legacy-records.show'],
]);

it('refuses print and export from a user with no legacy permission at all', function (string $routeName) {
    $record = lrme1dReadPublished();

    $this->actingAs(User::factory()->create())
        ->get(route($routeName, $record->getKey()))
        ->assertForbidden();
})->with([
    'print' => ['rme.legacy-records.print'],
    'export' => ['rme.legacy-records.export'],
    'show' => ['rme.legacy-records.show'],
]);

/*
|--------------------------------------------------------------------------
| LEGACY-RME-PDF-ROLL-2 pilot finding — Legacy must never widen clinical
| visibility compared with native RME.
|--------------------------------------------------------------------------
| Found in production: a same-branch doctor with NO clinical relationship to
| the patient was refused the native record but could still open the patient's
| legacy archive. Branch scope alone was the whole boundary, and branch is
| wider than a doctor's native reach.
*/

// CASE A — same branch, no doctor-patient relationship → DENIED.
it('refuses a same-branch doctor who is not treating the patient', function () {
    $branch = lrme1dRmeBranch();
    $record = lrme1dPublishedInBranch($branch);
    $doctor = lrme1dDoctor((int) $branch->getKey());

    Doctor::factory()->create(['user_id' => $doctor->getKey(), 'is_active' => true]);

    expect($doctor->can('view', $record))->toBeFalse()
        ->and($doctor->can('viewFile', $record))->toBeFalse();

    // In-branch but not treating is a 403: the branch-scoped repository finds
    // the row, and the policy is what refuses it. A wrong-branch record is a
    // 404 instead (case B) because the scope never surfaces it at all.
    $this->actingAs($doctor)
        ->withoutMiddleware(EnsureRmeOnlineContext::class)
        ->get(route('rme.legacy-records.show', $record))
        ->assertForbidden();
});

// CASE B — wrong branch → DENIED (unchanged, still enforced).
it('refuses a treating doctor whose branch does not own the archive', function () {
    $branch = lrme1dRmeBranch();
    $other = lrme1dRmeBranch();
    $record = lrme1dPublishedInBranch($branch);
    $doctor = lrme1dTreatingDoctor((int) $other->getKey(), $record);

    expect($doctor->can('view', $record))->toBeFalse();

    $this->actingAs($doctor)
        ->withoutMiddleware(EnsureRmeOnlineContext::class)
        ->get(route('rme.legacy-records.show', $record))
        ->assertNotFound();
});

// CASE C — real clinical relationship + right branch → ALLOWED, read-only.
it('allows a treating doctor in the owning branch to read the archive', function () {
    $branch = lrme1dRmeBranch();
    $record = lrme1dPublishedInBranch($branch);
    $doctor = lrme1dTreatingDoctor((int) $branch->getKey(), $record);

    expect($doctor->can('view', $record))->toBeTrue();

    $this->actingAs($doctor)
        ->withoutMiddleware(EnsureRmeOnlineContext::class)
        ->get(route('rme.legacy-records.show', $record))
        ->assertOk();
});

// A visit with that doctor is the other canonical relationship native RME uses.
it('accepts a visit with that doctor as the clinical relationship', function () {
    $branch = lrme1dRmeBranch();
    $record = lrme1dPublishedInBranch($branch);
    $user = lrme1dDoctor((int) $branch->getKey());
    $doctor = Doctor::factory()->create(['user_id' => $user->getKey(), 'is_active' => true]);

    expect($user->can('view', $record))->toBeFalse();

    ClinicVisit::factory()->create([
        'patient_id' => $record->patient_id,
        'doctor_id' => $doctor->getKey(),
    ]);

    expect($user->refresh()->can('view', $record))->toBeTrue();
});

// CASE D — an authorized reader is still only a reader.
it('keeps an authorized treating doctor read-only', function () {
    $branch = lrme1dRmeBranch();
    $record = lrme1dPublishedInBranch($branch);
    $doctor = lrme1dTreatingDoctor((int) $branch->getKey(), $record);

    expect($doctor->can('view', $record))->toBeTrue()
        ->and($doctor->can('void', $record))->toBeFalse()
        ->and($doctor->can('update', $record))->toBeFalse()
        ->and($doctor->can('delete', $record))->toBeFalse()
        ->and($doctor->can('viewAny', LegacyRmeImport::class))->toBeFalse()
        ->and($doctor->can('create', LegacyRmeImport::class))->toBeFalse()
        ->and($doctor->can('publish_legacy_rme_imports'))->toBeFalse()
        ->and($doctor->can('void_legacy_rme_imports'))->toBeFalse();
});

// CASE E — role/Gate::before must not widen a doctor. The governance tier is
// unaffected, which is what keeps import/review/publish/VOID working.
it('does not widen a doctor through role or Gate::before, and leaves governance intact', function () {
    $branch = lrme1dRmeBranch();
    $record = lrme1dPublishedInBranch($branch);
    $doctor = lrme1dDoctor((int) $branch->getKey());

    expect($doctor->can('view', $record))->toBeFalse();

    // Super Admin keeps full access: the clinical scope applies to doctors only.
    expect(superAdmin()->can('view', $record))->toBeTrue();
});

// The timeline must not be a weaker door than the viewer policy.
it('hides the archive from the patient history of a non-treating doctor', function () {
    $branch = lrme1dRmeBranch();
    $record = lrme1dPublishedInBranch($branch);
    $service = app(LegacyRmePatientHistoryService::class);

    $stranger = lrme1dDoctor((int) $branch->getKey());
    Doctor::factory()->create(['user_id' => $stranger->getKey(), 'is_active' => true]);

    expect($service->publishedRecordsFor($stranger, (int) $record->patient_id))->toBeEmpty();

    $treating = lrme1dTreatingDoctor((int) $branch->getKey(), $record);

    expect($service->publishedRecordsFor($treating, (int) $record->patient_id))->toHaveCount(1);
});
