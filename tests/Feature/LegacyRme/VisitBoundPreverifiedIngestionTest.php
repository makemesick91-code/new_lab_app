<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\LegacyRme\Interfaces\LegacyRmePdfInspectorInterface;
use App\Modules\LegacyRme\Interfaces\LegacyRmePdfRasterizerInterface;
use App\Modules\LegacyRme\Models\LegacyRmeImport;
use App\Modules\LegacyRme\Models\LegacyRmeRecord;
use App\Modules\LegacyRme\Services\LegacyRmeDateRuleService;
use App\Modules\LegacyRme\Services\LegacyRmeImportProcessingService;
use App\Modules\LegacyRme\Services\LegacyRmeImportService;
use App\Modules\LegacyRme\Services\LegacyRmePublishService;
use App\Modules\LegacyRme\Services\Pdf\FakeLegacyRmePdfInspector;
use App\Modules\LegacyRme\Services\Pdf\FakeLegacyRmePdfRasterizer;
use App\Modules\LegacyRme\Support\LegacyRmeImportStatus;
use App\Modules\LegacyRme\Support\SeparatePublisherGuard;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\Patient\Models\Patient;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use App\Support\Legacy\LegacyVerificationMode;
use App\Support\Legacy\LegacyVisitAttestation;
use App\Support\Legacy\LegacyVisitBindingRefusal;
use App\Support\Legacy\LegacyVisitBindingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;

/**
 * REVISION-LEGACY-VISIT-BOUND-PREVERIFIED-INGESTION-1.
 *
 * THE ONE SENTENCE THIS SUITE DEFENDS:
 *
 *   VISIT-PREVERIFIED MEANS *DATE* PREVERIFIED. It does NOT mean separation of
 *   duties is bypassed, and it does NOT mean the uploader self-publishes.
 *
 * Every test below is either (a) proof that the duplicate DATE verification is
 * really gone, or (b) proof that nothing else was loosened to achieve it. The
 * second group matters more: the easy way to "remove the second review" is to
 * quietly disarm LEGACY-RME-SOD-1, and these tests exist so that doing so
 * turns the suite red instead of shipping.
 *
 * WHAT IS DELIBERATELY *NOT* RE-TESTED HERE. Duplicate detection, file safety,
 * branch derivation, admission, quota and rendering already have their own
 * suites and are unchanged by this sprint. Repeating them would suggest this
 * path reimplemented them, which is exactly the thing it must not do.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    seedAccessControl();
    legacyRmeArchiveFlag(true);
    Storage::fake('legacy_rme_private');
    Bus::fake();

    // ROLL-3/ROLL-4: ingestion needs an admitted branch under an approved
    // wave. Supplying only the permission would be testing a denial by
    // accident — this sprint changes none of that layer, so the fixture must
    // satisfy it exactly as production does.
    legacyRmeMigrationWave(['TLK1']);
    legacyRmeAdmitBranch('TLK1');
});

/** A distinct source PDF per call — an identical checksum is refused by design. */
function vbpUpload(int $pages = 2): UploadedFile
{
    static $variant = 5000;
    $variant++;

    return UploadedFile::fake()->createWithContent(
        'arsip.pdf',
        legacyRmePdfBytes($pages, 595.276 + $variant, 841.89),
    );
}

function vbpFakeRenderers(int $pages = 2): void
{
    app()->instance(LegacyRmePdfInspectorInterface::class, (new FakeLegacyRmePdfInspector)->withPages($pages));
    app()->instance(LegacyRmePdfRasterizerInterface::class, (new FakeLegacyRmePdfRasterizer)->withPages($pages));
}

/** An operator holding exactly the front-desk cohort's rights. */
function vbpOperator(): User
{
    $user = userWith([
        'create_legacy_rme_imports',
        'view_legacy_rme_imports',
        'verify_legacy_dates_at_ingestion',
        'view_clinic_visits',
        'manage_clinic_visits',
    ]);

    // A front-desk account is pinned to one branch, exactly like the real
    // Sunu/Telkomas operators. Without this the workspace scope correctly
    // refuses, and the test would be measuring the fixture, not the rule.
    $user->forceFill(['branch_id' => legacyRmeBranch('TLK1')->id])->save();

    return legacyRmeOperator($user);
}

/** A REAL, live visit for this patient, at the given date. */
function vbpVisit(Patient $patient, string $visitDate = '2024-06-10'): ClinicVisit
{
    // The visit belongs to the same branch the operator works in — the
    // ordinary case. Cross-branch refusal has its own test below.
    return ClinicVisit::factory()->create([
        'patient_id' => $patient->id,
        'branch_id' => legacyRmeBranch('TLK1')->id,
        'visit_date' => $visitDate,
        'status' => ClinicVisit::STATUS_IN_PROGRESS,
    ]);
}

function vbpAttestation(ClinicVisit $visit, User $actor): LegacyVisitAttestation
{
    return app(LegacyVisitBindingService::class)->resolve(
        (int) $visit->getKey(),
        $actor,
        dateAttested: true,
    );
}

/**
 * The canonical happy path: a real visit, one date verification, a staged row.
 */
function vbpPreverifiedImport(
    User $uploader,
    string $legacyEarliest = '2020-05-01',
    ?string $legacyLatest = '2020-09-01',
    string $visitDate = '2024-06-10',
    ?Patient $patient = null,
): LegacyRmeImport {
    vbpFakeRenderers();

    $patient ??= legacyRmeArchivablePatient(['date_of_birth' => '1990-01-01']);
    $visit = vbpVisit($patient, $visitDate);

    return app(LegacyRmeImportService::class)->createFromUpload(
        $patient,
        $legacyEarliest,
        $patient->medical_record_number,
        null,
        vbpUpload(),
        $uploader,
        $legacyLatest,
        vbpAttestation($visit, $uploader),
    );
}

/*
|--------------------------------------------------------------------------
| 1–2. The date is verified ONCE, and the attestation is bound to the file
|--------------------------------------------------------------------------
*/

it('records the date attestation once, bound to the visit and the source hash', function () {
    $operator = vbpOperator();
    $import = vbpPreverifiedImport($operator)->refresh();

    expect($import->verification_mode)->toBe(LegacyVerificationMode::VISIT_PREVERIFIED)
        ->and($import->isVisitPreverified())->toBeTrue()
        ->and($import->hasCompleteVisitAttestation())->toBeTrue()
        ->and((int) $import->verified_by)->toBe((int) $operator->getKey())
        ->and($import->verified_at)->not->toBeNull()
        ->and($import->verified_selected_date->toDateString())->toBe('2020-05-01')
        ->and($import->verified_latest_date->toDateString())->toBe('2020-09-01')
        ->and($import->verification_visit_date->toDateString())->toBe('2024-06-10')
        // THE BINDING: the human's statement names the exact bytes.
        ->and($import->verified_source_sha256)->toBe($import->source_pdf_sha256);
});

it('leaves the attestation columns null on the ordinary backlog path', function () {
    // The backlog importer is untouched. NULL is the true statement
    // "this did not come through a visit", not missing data.
    vbpFakeRenderers();
    $patient = legacyRmeArchivablePatient();

    $import = app(LegacyRmeImportService::class)->createFromUpload(
        $patient,
        '2020-05-01',
        $patient->medical_record_number,
        null,
        vbpUpload(),
        vbpOperator(),
    )->refresh();

    expect($import->verification_mode)->toBeNull()
        ->and($import->isVisitPreverified())->toBeFalse()
        ->and($import->verified_by)->toBeNull()
        ->and($import->verified_source_sha256)->toBeNull();
});

/*
|--------------------------------------------------------------------------
| 6–8. SOD IS NOT BYPASSED — the heart of the sprint
|--------------------------------------------------------------------------
*/

it('refuses to let the attesting uploader review or publish their own document', function () {
    $operator = vbpOperator();
    $import = vbpPreverifiedImport($operator);

    app(LegacyRmeImportProcessingService::class)->process($import->getKey());
    $import->refresh();

    // The very account that verified the dates may not certify the document.
    expect(fn () => app(LegacyRmePublishService::class)->review($import, $operator))
        ->toThrow(ValidationException::class);
});

it('lets a SEPARATE checker finalize the preverified document', function () {
    $operator = vbpOperator();
    $import = vbpPreverifiedImport($operator);

    app(LegacyRmeImportProcessingService::class)->process($import->getKey());

    $checker = superAdmin();

    $reviewed = app(LegacyRmePublishService::class)->review($import->refresh(), $checker);
    expect($reviewed->status)->toBe(LegacyRmeImportStatus::REVIEWED);

    $record = app(LegacyRmePublishService::class)->publish($reviewed->refresh(), [], $checker);

    expect($record)->toBeInstanceOf(LegacyRmeRecord::class)
        // The published record carries the date the human attested.
        ->and($record->rme_date->toDateString())->toBe('2020-05-01');
});

it('fails loudly if separation of duties were ever disarmed for this path', function () {
    // MUTATION CANARY. If someone "simplifies" the preverified path by turning
    // the SOD guard off, this test — not production — is what breaks.
    expect(config(SeparatePublisherGuard::CONFIG_KEY))->toBeTrue()
        ->and(app(SeparatePublisherGuard::class)->enabled())->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| 9–10. No permission widening
|--------------------------------------------------------------------------
*/

it('gives the date-attestation cohort no publish and no void authority', function () {
    $operator = vbpOperator();

    expect($operator->can('verify_legacy_dates_at_ingestion'))->toBeTrue()
        ->and($operator->can('create_legacy_rme_imports'))->toBeTrue()
        // The three that must NOT come with it.
        ->and($operator->can('review_legacy_rme_imports'))->toBeFalse()
        ->and($operator->can('publish_legacy_rme_imports'))->toBeFalse()
        ->and($operator->can('void_legacy_rme_imports'))->toBeFalse();
});

it('grants the new permission to exactly the two front-desk roles', function () {
    // Least privilege: the cohort that may attest dates is exactly the cohort
    // that could already file a legacy document. No role gained filing rights.
    $granted = Permission::where('name', 'verify_legacy_dates_at_ingestion')
        ->firstOrFail()
        ->roles
        ->pluck('name')
        // Super Admin holds every permission by construction (the '*' grant)
        // and is additionally bypassed by Gate::before, so listing it here
        // would measure the seeder's wildcard, not this sprint's decision.
        ->reject(fn (string $role) => $role === 'Super Admin')
        ->sort()
        ->values()
        ->all();

    expect($granted)->toBe(['Admin Klinik', 'Front Office']);
});

/*
|--------------------------------------------------------------------------
| 12. The strict visit-date boundary
|--------------------------------------------------------------------------
*/

it('refuses a document dated ON the visit date', function () {
    // Same-day is NOT self-evidently historical, so it goes through the
    // standard review path instead. `<` not `<=`, deliberately.
    expect(fn () => vbpPreverifiedImport(vbpOperator(), '2024-06-10', '2024-06-10', '2024-06-10'))
        ->toThrow(ValidationException::class);
});

it('refuses a document dated AFTER the visit date', function () {
    expect(fn () => vbpPreverifiedImport(vbpOperator(), '2024-07-01', '2024-07-01', '2024-06-10'))
        ->toThrow(ValidationException::class);
});

it('accepts a document whose whole range is strictly before the visit date', function () {
    $import = vbpPreverifiedImport(vbpOperator(), '2020-05-01', '2024-06-09', '2024-06-10');

    expect($import->exists)->toBeTrue();
});

it('refuses when only the LATEST date crosses the visit boundary', function () {
    // Validating only the representative date would let a mixed chronology
    // hide behind its oldest entry.
    expect(fn () => vbpPreverifiedImport(vbpOperator(), '2020-01-01', '2024-06-10', '2024-06-10'))
        ->toThrow(ValidationException::class);
});

it('names the visit boundary with its own stable rule code', function () {
    $patient = legacyRmeArchivablePatient(['date_of_birth' => '1990-01-01']);

    $result = app(LegacyRmeDateRuleService::class)->evaluate(
        $patient,
        '2024-06-10',
        '2024-06-10',
        '2024-06-10',
    );

    expect($result->failed())->toBeTrue()
        ->and($result->code)->toBe(LegacyRmeDateRuleService::CODE_LEGACY_DATE_NOT_BEFORE_VISIT);
});

/*
|--------------------------------------------------------------------------
| 13. The native-RME boundary still applies, and the stricter one wins
|--------------------------------------------------------------------------
*/

it('still enforces the native RME boundary on the visit-bound path', function () {
    vbpFakeRenderers();

    $patient = legacyRmeArchivablePatient(['date_of_birth' => '1990-01-01']);
    // Native RME long before the visit — it, not the visit, is the tighter bound.
    legacyRmeNativeVisit($patient, '2021-03-10');
    $visit = vbpVisit($patient, '2024-06-10');

    expect(fn () => app(LegacyRmeImportService::class)->createFromUpload(
        $patient,
        '2022-01-01',
        $patient->medical_record_number,
        null,
        vbpUpload(),
        vbpOperator(),
        '2022-02-01',
        vbpAttestation($visit, vbpOperator()),
    ))->toThrow(ValidationException::class);
});

/*
|--------------------------------------------------------------------------
| 3–5, 11. Read-only downstream dates and the correction path
|--------------------------------------------------------------------------
*/

it('shows the checker the attested dates as read-only evidence, with no date input', function () {
    $operator = vbpOperator();
    $import = vbpPreverifiedImport($operator);
    app(LegacyRmeImportProcessingService::class)->process($import->getKey());

    $checker = superAdmin();

    $html = $this->actingAs($checker)
        ->get(route('settings.rme.legacy-imports.show', $import))
        ->assertOk()
        ->getContent();

    expect($html)
        ->toContain('Telah diverifikasi Admin Klinik saat upload')
        ->toContain('01-05-2020')
        ->toContain($operator->name);

    // THE POINT: no editable date control anywhere on the checker's screen.
    expect($html)
        ->not->toContain('name="selected_rme_date"')
        ->not->toContain('name="latest_rme_date"')
        ->not->toContain('name="verified_selected_date"');
});

it('does not render the preverified panel for a backlog document', function () {
    vbpFakeRenderers();
    $patient = legacyRmeArchivablePatient();

    $import = app(LegacyRmeImportService::class)->createFromUpload(
        $patient,
        '2020-05-01',
        $patient->medical_record_number,
        null,
        vbpUpload(),
        vbpOperator(),
    );
    app(LegacyRmeImportProcessingService::class)->process($import->getKey());

    $html = $this->actingAs(superAdmin())
        ->get(route('settings.rme.legacy-imports.show', $import))
        ->assertOk()
        ->getContent();

    // An empty evidence panel would imply an attestation that never happened.
    expect($html)->not->toContain('Telah diverifikasi Admin Klinik saat upload');
});

/*
|--------------------------------------------------------------------------
| 14. Final revalidation — the attestation must still be true at publish
|--------------------------------------------------------------------------
*/

it('refuses to publish when the attested source file no longer matches', function () {
    $operator = vbpOperator();
    $import = vbpPreverifiedImport($operator);
    app(LegacyRmeImportProcessingService::class)->process($import->getKey());

    $reviewed = app(LegacyRmePublishService::class)->review($import->refresh(), superAdmin());

    // The human certified different bytes than the ones now stored.
    $reviewed->forceFill(['verified_source_sha256' => str_repeat('a', 64)])->save();

    expect(fn () => app(LegacyRmePublishService::class)->publish($reviewed->refresh(), [], superAdmin()))
        ->toThrow(ValidationException::class);
});

it('refuses to publish when the bound visit has been cancelled', function () {
    $operator = vbpOperator();
    $import = vbpPreverifiedImport($operator);
    app(LegacyRmeImportProcessingService::class)->process($import->getKey());

    $reviewed = app(LegacyRmePublishService::class)->review($import->refresh(), superAdmin());

    ClinicVisit::whereKey($reviewed->verification_visit_id)
        ->update(['status' => ClinicVisit::STATUS_CANCELLED]);

    expect(fn () => app(LegacyRmePublishService::class)->publish($reviewed->refresh(), [], superAdmin()))
        ->toThrow(ValidationException::class);
});

it('refuses to publish when the visit date moved after the attestation', function () {
    // It does NOT silently adopt the new ceiling: the human attested against
    // the old one, and re-pointing their statement would make it a lie.
    $operator = vbpOperator();
    $import = vbpPreverifiedImport($operator);
    app(LegacyRmeImportProcessingService::class)->process($import->getKey());

    $reviewed = app(LegacyRmePublishService::class)->review($import->refresh(), superAdmin());

    ClinicVisit::whereKey($reviewed->verification_visit_id)
        ->update(['visit_date' => '2024-08-01']);

    expect(fn () => app(LegacyRmePublishService::class)->publish($reviewed->refresh(), [], superAdmin()))
        ->toThrow(ValidationException::class);
});

it('refuses to publish an incomplete attestation rather than repairing it', function () {
    $operator = vbpOperator();
    $import = vbpPreverifiedImport($operator);
    app(LegacyRmeImportProcessingService::class)->process($import->getKey());

    $reviewed = app(LegacyRmePublishService::class)->review($import->refresh(), superAdmin());

    $reviewed->forceFill(['verified_by' => null])->save();

    expect(fn () => app(LegacyRmePublishService::class)->publish($reviewed->refresh(), [], superAdmin()))
        ->toThrow(ValidationException::class);
});

/*
|--------------------------------------------------------------------------
| 15–17. Binding integrity: no forged visit, no forged patient, no fake visit
|--------------------------------------------------------------------------
*/

it('refuses a visit outside the operator\'s working branch, revealing nothing', function () {
    // THE REAL BOUNDARY, modelled the way production actually runs. A Front
    // Office account works inside an ACTIVE ONLINE CONTEXT pinned to one
    // branch; `users.branch_id` alone does not narrow RmeWorkingBranchScope,
    // which is why this fixture starts a real session instead of setting a
    // column and hoping.
    //
    // This sprint adds no branch rule of its own: LegacyVisitBindingService
    // delegates to ClinicVisitPolicy + RmeWorkingBranchScope, so the
    // visit-bound path is exactly as tight as the canonical visit boundary —
    // never tighter, and never looser. The assertion below pins that
    // agreement, so a future loosening of either shows up here.
    $tlk1 = legacyRmeBranch('TLK1');
    $ldk2 = legacyRmeBranch('LDK2', 'Cabang Landak');

    $operator = userWith([
        'create_legacy_rme_imports',
        'view_legacy_rme_imports',
        'verify_legacy_dates_at_ingestion',
        'view_clinic_visits',
        'manage_clinic_visits',
    ]);
    $operator->forceFill(['branch_id' => $tlk1->id])->save();
    rmeMakeFrontOfficeActive($operator, $tlk1);
    legacyRmeOperator($operator);

    $foreignVisit = ClinicVisit::factory()->create([
        'patient_id' => legacyRmeArchivablePatient([], 'LDK2')->id,
        'branch_id' => $ldk2->id,
        'visit_date' => '2024-06-10',
        'status' => ClinicVisit::STATUS_IN_PROGRESS,
    ]);

    // The canonical policy refuses it...
    expect(Gate::forUser($operator)->allows('view', $foreignVisit))
        ->toBeFalse();

    // ...and so does the binding, with the non-enumerating code.
    try {
        app(LegacyVisitBindingService::class)->resolve((int) $foreignVisit->getKey(), $operator, true);
        $this->fail('a visit outside the working branch must not resolve');
    } catch (LegacyVisitBindingRefusal $refusal) {
        // One code for missing / deleted / not-yours: no enumeration oracle.
        expect($refusal->refusalCode)->toBe(LegacyVisitBindingRefusal::VISIT_NOT_ACCESSIBLE);
    }
});

it('refuses a nonexistent visit id', function () {
    expect(fn () => app(LegacyVisitBindingService::class)->resolve(999999, vbpOperator(), true))
        ->toThrow(LegacyVisitBindingRefusal::class);
});

it('requires a visit — the preverified path has no visitless variant', function () {
    try {
        app(LegacyVisitBindingService::class)->resolve(null, vbpOperator(), true);
        $this->fail('a null visit must be refused');
    } catch (LegacyVisitBindingRefusal $refusal) {
        expect($refusal->refusalCode)->toBe(LegacyVisitBindingRefusal::VISIT_REQUIRED);
    }
});

it('refuses a cancelled visit as an attestation anchor', function () {
    $operator = vbpOperator();
    $patient = legacyRmeArchivablePatient();
    $visit = vbpVisit($patient);
    $visit->forceFill(['status' => ClinicVisit::STATUS_CANCELLED])->save();

    try {
        app(LegacyVisitBindingService::class)->resolve((int) $visit->getKey(), $operator, true);
        $this->fail('a cancelled visit must be refused');
    } catch (LegacyVisitBindingRefusal $refusal) {
        expect($refusal->refusalCode)->toBe(LegacyVisitBindingRefusal::VISIT_INVALID);
    }
});

it('refuses a soft-deleted visit', function () {
    $operator = vbpOperator();
    $visit = vbpVisit(legacyRmeArchivablePatient());
    $visit->delete();

    expect(fn () => app(LegacyVisitBindingService::class)->resolve((int) $visit->getKey(), $operator, true))
        ->toThrow(LegacyVisitBindingRefusal::class);
});

it('refuses a submitted patient that disagrees with the visit', function () {
    $operator = vbpOperator();
    $visit = vbpVisit(legacyRmeArchivablePatient());
    $other = legacyRmeArchivablePatient();

    try {
        app(LegacyVisitBindingService::class)->resolve(
            (int) $visit->getKey(),
            $operator,
            true,
            (int) $other->getKey(),
        );
        $this->fail('a mismatched patient must be refused');
    } catch (LegacyVisitBindingRefusal $refusal) {
        expect($refusal->refusalCode)->toBe(LegacyVisitBindingRefusal::VISIT_PATIENT_MISMATCH);
    }
});

it('refuses an upload whose attestation names a different patient', function () {
    // Defence in depth: even a caller that assembled patient and attestation
    // from different sources is refused at the intake service.
    vbpFakeRenderers();

    $operator = vbpOperator();
    $patientA = legacyRmeArchivablePatient();
    $patientB = legacyRmeArchivablePatient();
    $visitA = vbpVisit($patientA);

    expect(fn () => app(LegacyRmeImportService::class)->createFromUpload(
        $patientB,
        '2020-05-01',
        $patientB->medical_record_number,
        null,
        vbpUpload(),
        $operator,
        null,
        vbpAttestation($visitA, $operator),
    ))->toThrow(ValidationException::class);
});

it('refuses an upload with no date attestation', function () {
    try {
        app(LegacyVisitBindingService::class)->resolve(
            (int) vbpVisit(legacyRmeArchivablePatient())->getKey(),
            vbpOperator(),
            dateAttested: false,
        );
        $this->fail('an unattested upload must be refused');
    } catch (LegacyVisitBindingRefusal $refusal) {
        expect($refusal->refusalCode)->toBe(LegacyVisitBindingRefusal::ATTESTATION_REQUIRED);
    }
});

it('never creates a visit while ingesting a legacy document', function () {
    // Point-of-visit migration evidences an encounter that already happened;
    // a path able to manufacture that encounter would be worthless.
    $before = ClinicVisit::count();

    $operator = vbpOperator();
    $patient = legacyRmeArchivablePatient(['date_of_birth' => '1990-01-01']);
    $visit = vbpVisit($patient);

    $afterFixture = ClinicVisit::count();
    expect($afterFixture)->toBe($before + 1);

    vbpFakeRenderers();
    app(LegacyRmeImportService::class)->createFromUpload(
        $patient,
        '2020-05-01',
        $patient->medical_record_number,
        null,
        vbpUpload(),
        $operator,
        null,
        vbpAttestation($visit, $operator),
    );

    // The ingestion itself added nothing.
    expect(ClinicVisit::count())->toBe($afterFixture);
});

/*
|--------------------------------------------------------------------------
| 16, 18. Branch authority, and no native clinical side effects
|--------------------------------------------------------------------------
*/

it('keeps the archive branch RM-derived rather than adopting the visit branch', function () {
    // FIX-ROLL2-1 is untouched: origin_branch_id is the column row visibility
    // and the policies key off, so the visit must not silently redefine it.
    $operator = vbpOperator();
    $patient = legacyRmeArchivablePatient([], 'TLK1');
    $visit = vbpVisit($patient);

    $import = vbpPreverifiedImport($operator, patient: $patient)->refresh();

    expect((int) $import->origin_branch_id)->toBe((int) legacyRmeBranch('TLK1')->id);
});

it('creates no native clinical or billing record', function () {
    $before = [
        'medical_records' => MedicalRecord::count(),
        'invoices' => RmeInvoice::count(),
    ];

    $operator = vbpOperator();
    $import = vbpPreverifiedImport($operator);
    app(LegacyRmeImportProcessingService::class)->process($import->getKey());

    $checker = superAdmin();
    $reviewed = app(LegacyRmePublishService::class)->review($import->refresh(), $checker);
    app(LegacyRmePublishService::class)->publish($reviewed->refresh(), [], $checker);

    expect(MedicalRecord::count())->toBe($before['medical_records'])
        ->and(RmeInvoice::count())->toBe($before['invoices']);
});
