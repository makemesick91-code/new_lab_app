<?php

/**
 * LEGACY-RME-PDF-FIX-ROLL2-1 — legacy migration eligibility, multi-date
 * documents and RM-derived branch resolution.
 *
 * Three corrections are pinned here, each of which blocked or endangered a real
 * migration:
 *
 *  1. A patient with NO native RME is ELIGIBLE. It is the ordinary migration
 *     case, and a native encounter is never manufactured to unlock an import.
 *  2. A document is a date RANGE. The representative date is the EARLIEST one;
 *     the safety bound is checked against the LATEST one, so a document whose
 *     later entries overlap the native era cannot hide behind its oldest date.
 *  3. The archive's branch is DERIVED from the branch code in the patient's own
 *     Nomor RM. It decides row visibility, so it is never operator input and
 *     never falls back to anything.
 */

use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\LabOrder\Models\LabCaseCandidate;
use App\Modules\LabOrder\Models\LabOrder;
use App\Modules\LegacyRme\Interfaces\LegacyRmePdfInspectorInterface;
use App\Modules\LegacyRme\Interfaces\LegacyRmePdfRasterizerInterface;
use App\Modules\LegacyRme\Models\LegacyRmeImport;
use App\Modules\LegacyRme\Models\LegacyRmeRecord;
use App\Modules\LegacyRme\Services\LegacyRmeBranchResolver;
use App\Modules\LegacyRme\Services\LegacyRmeImportProcessingService;
use App\Modules\LegacyRme\Services\LegacyRmeImportService;
use App\Modules\LegacyRme\Services\LegacyRmePublishService;
use App\Modules\LegacyRme\Services\Pdf\FakeLegacyRmePdfInspector;
use App\Modules\LegacyRme\Services\Pdf\FakeLegacyRmePdfRasterizer;
use App\Modules\LegacyRme\Support\LegacyRmeAuditEvent;
use App\Modules\LegacyRme\Support\LegacyRmeBranchResolution;
use App\Modules\LegacyRme\Support\LegacyRmeImportStatus;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\Patient\Models\Patient;
use App\Modules\Patient\Services\PatientMedicalRecordNumberService;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use App\Modules\RmeInvoice\Models\RmePayment;
use App\Modules\Satusehat\Models\SatusehatCandidate;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    seedAccessControl();
    legacyRmeArchiveFlag(true);
    Storage::fake('legacy_rme_private');
    Bus::fake();
});

function lrmeFixResolver(): LegacyRmeBranchResolver
{
    return app(LegacyRmeBranchResolver::class);
}

function lrmeFixRmParser(): PatientMedicalRecordNumberService
{
    return app(PatientMedicalRecordNumberService::class);
}

/*
|--------------------------------------------------------------------------
| The canonical Nomor RM parser
|--------------------------------------------------------------------------
| It is the exact inverse of the composer that has produced this format since
| Sprint 23.8, and it lives beside it so the two cannot drift.
*/

it('parses the pilot Nomor RM into its components', function () {
    $parts = lrmeFixRmParser()->parse('DG-TKM1-2024-9985');

    expect($parts)->not->toBeNull()
        ->and($parts->prefix)->toBe('DG')
        ->and($parts->branchCode)->toBe('TKM1')
        ->and($parts->year)->toBe('2024')
        ->and($parts->sequence)->toBe('9985')
        ->and($parts->toString())->toBe('DG-TKM1-2024-9985');
});

it('round-trips every value the composer can produce', function (string $branchCode, string $year, string $sequence) {
    $composed = lrmeFixRmParser()->compose($branchCode, $year, $sequence);

    expect(lrmeFixRmParser()->parse($composed)?->toString())->toBe($composed);
})->with([
    ['TKM1', '2026', '0001'],
    ['LDK2', '2026', '25'],
    // Leading zeros are preserved verbatim by the composer, so the parser must
    // not normalise them away either.
    ['ATG3', '2024', '000007'],
    // The manual sequence may itself contain a hyphen.
    ['SUN4', '2025', '12-34'],
]);

it('returns null for anything that is not a canonical Nomor RM', function (?string $value) {
    expect(lrmeFixRmParser()->parse($value))->toBeNull();
})->with([
    [null],
    [''],
    ['   '],
    // The placeholder format some fixtures use — deliberately not accepted.
    ['MRN-ABCDEFGH'],
    ['XX-TKM1-2024-9985'],   // wrong prefix
    ['DG-TKM1-24-9985'],     // year not four digits
    ['DG-TKM1-2024'],        // missing sequence
    ['DG--2024-9985'],       // empty branch code
    ['DG-TKM 1-2024-9985'],  // separator inside the branch code
    ['DG-TKM1-2024-'],       // empty sequence
]);

/*
|--------------------------------------------------------------------------
| Branch resolution — derived from the Nomor RM, fail closed
|--------------------------------------------------------------------------
*/

it('resolves TKM1 on the Nomor RM to Cabang Telkomas', function () {
    $branch = legacyRmeBranch('TKM1', 'Cabang Telkomas');
    $patient = legacyRmeArchivablePatient([], 'TKM1');

    $resolution = lrmeFixResolver()->resolveForPatient($patient);

    expect($resolution->resolved)->toBeTrue()
        ->and($resolution->branchId)->toBe($branch->id)
        ->and($resolution->branchCode)->toBe('TKM1')
        ->and($resolution->branchName)->toBe('Cabang Telkomas');
});

it('fails closed for every unresolvable branch code', function (callable $mutate, string $expectedCode) {
    $patient = legacyRmeArchivablePatient([], 'TKM1');
    $mutate($patient);

    $resolution = lrmeFixResolver()->resolveForPatient($patient->refresh());

    expect($resolution->failed())->toBeTrue()
        ->and($resolution->code)->toBe($expectedCode)
        ->and($resolution->branchId)->toBeNull();
})->with([
    'no Nomor RM at all' => [
        fn (Patient $p) => $p->forceFill(['medical_record_number' => null])->save(),
        LegacyRmeBranchResolution::CODE_RM_MISSING,
    ],
    'malformed Nomor RM' => [
        fn (Patient $p) => $p->forceFill(['medical_record_number' => 'MRN-ABCDEFGH'])->save(),
        LegacyRmeBranchResolution::CODE_INVALID_RM_BRANCH_CODE,
    ],
    'branch code not in the registry' => [
        fn (Patient $p) => $p->forceFill(['medical_record_number' => 'DG-ZZZ9-2024-0001'])->save(),
        LegacyRmeBranchResolution::CODE_BRANCH_NOT_FOUND,
    ],
    'branch is inactive' => [
        function (Patient $p) {
            legacyRmeBranch('TKM1')->forceFill(['is_active' => false])->save();
        },
        LegacyRmeBranchResolution::CODE_BRANCH_INACTIVE,
    ],
    'branch is not RME enabled' => [
        function (Patient $p) {
            legacyRmeBranch('TKM1')->forceFill(['is_rme_enabled' => false])->save();
        },
        LegacyRmeBranchResolution::CODE_BRANCH_NOT_RME_ENABLED,
    ],
]);

// A branch code can only ever name one branch because `mst_branches.code` is
// UNIQUE — that database constraint is what makes RM-derived branch resolution
// unambiguous in the first place, so it is the thing worth pinning.
//
// The resolver's own ambiguity guard (CODE_BRANCH_AMBIGUOUS) sits behind this
// constraint as defence in depth and is deliberately unreachable while the
// constraint holds; it is asserted at the unit level below rather than through
// data the database refuses to create.
it('cannot produce two branches with the same code', function () {
    legacyRmeArchivablePatient([], 'TKM1');

    expect(fn () => Branch::factory()->create([
        'code' => 'TKM1',
        'is_active' => true,
        'is_rme_enabled' => true,
    ]))->toThrow(UniqueConstraintViolationException::class);
});

it('declares an ambiguity code for the defence-in-depth guard', function () {
    expect(LegacyRmeBranchResolution::CODES)
        ->toContain(LegacyRmeBranchResolution::CODE_BRANCH_AMBIGUOUS);

    $failure = LegacyRmeBranchResolution::failure(
        LegacyRmeBranchResolution::CODE_BRANCH_AMBIGUOUS,
        'ambiguous',
        'TKM1',
    );

    expect($failure->failed())->toBeTrue()
        ->and($failure->branchId)->toBeNull()
        ->and($failure->auditContext())->toBe([
            'branch_code' => 'TKM1',
            'rule_code' => LegacyRmeBranchResolution::CODE_BRANCH_AMBIGUOUS,
        ]);
});

it('never falls back to the acting user branch', function () {
    // The operator sits in LDK2; the patient's RM says TKM1. The old behaviour
    // would have anchored a blank branch to the operator's own.
    $patient = legacyRmeArchivablePatient([], 'TKM1');
    $patient->forceFill(['medical_record_number' => 'DG-ZZZ9-2024-0001'])->save();

    $user = userWith(['create_legacy_rme_imports']);
    $user->forceFill(['branch_id' => legacyRmeBranch('LDK2', 'Cabang Landak')->id])->save();

    $resolution = lrmeFixResolver()->resolveForPatient($patient->refresh(), $user);

    expect($resolution->failed())->toBeTrue()
        ->and($resolution->branchId)->toBeNull();
});

it('refuses a branch outside the actor scope but resolves it without an actor', function () {
    $patient = legacyRmeArchivablePatient([], 'TKM1');

    $user = userWith(['create_legacy_rme_imports']);
    $user->forceFill(['branch_id' => legacyRmeBranch('LDK2', 'Cabang Landak')->id])->save();

    expect(lrmeFixResolver()->resolveForPatient($patient, $user)->code)
        ->toBe(LegacyRmeBranchResolution::CODE_BRANCH_OUT_OF_SCOPE);

    // Without an actor the question is only "where does this document belong",
    // which is what publish-time revalidation asks.
    expect(lrmeFixResolver()->resolveForPatient($patient)->resolved)->toBeTrue();
});

it('rejects a submitted branch that contradicts the Nomor RM', function () {
    $patient = legacyRmeArchivablePatient([], 'TKM1');
    $other = legacyRmeBranch('LDK2', 'Cabang Landak');

    $resolution = lrmeFixResolver()->resolveForPatient($patient);
    $checked = lrmeFixResolver()->assertNoConflict($resolution, $other->id);

    expect($checked->failed())->toBeTrue()
        ->and($checked->code)->toBe(LegacyRmeBranchResolution::CODE_BRANCH_CONFLICT);

    // A matching id, or none at all, is accepted unchanged.
    expect(lrmeFixResolver()->assertNoConflict($resolution, $resolution->branchId)->resolved)->toBeTrue()
        ->and(lrmeFixResolver()->assertNoConflict($resolution, null)->resolved)->toBeTrue();
});

it('records a PII-free audit entry when a branch cannot be derived', function () {
    // The birth-date rule runs BEFORE branch resolution, and PatientFactory's
    // default date of birth is random (up to 5 years ago) — leaving it to chance
    // would sometimes fail the date rule first and never reach the branch check.
    $patient = legacyRmeArchivablePatient([
        'name' => 'Pasien Rahasia',
        'date_of_birth' => '1990-01-01',
    ], 'TKM1');
    $patient->forceFill(['medical_record_number' => 'DG-ZZZ9-2024-0001'])->save();

    expect(fn () => app(LegacyRmeImportService::class)->createFromUpload(
        $patient->refresh(),
        '2015-01-01',
        $patient->medical_record_number,
        null,
        legacyRmePdfUpload(),
        superAdmin(),
    ))->toThrow(ValidationException::class);

    $log = DB::table('sys_audit_logs')
        ->where('action', LegacyRmeAuditEvent::IMPORT_BRANCH_REJECTED)
        ->latest('id')
        ->first();

    expect($log)->not->toBeNull();

    $payload = json_encode($log);

    // Structure only: a rule code and a branch code, never the patient's name
    // or Nomor RM.
    expect($payload)->not->toContain('Pasien Rahasia')
        ->and($payload)->not->toContain('DG-ZZZ9-2024-0001');
});

/*
|--------------------------------------------------------------------------
| Migration eligibility — a patient with no native RME
|--------------------------------------------------------------------------
*/

it('imports for a patient with no native RME without creating any encounter', function () {
    $patient = legacyRmeArchivablePatient(['date_of_birth' => '1990-01-01'], 'TKM1');

    $import = app(LegacyRmeImportService::class)->createFromUpload(
        $patient,
        '2024-01-28',
        $patient->medical_record_number,
        null,
        legacyRmePdfUpload(),
        superAdmin(),
        '2024-08-31',
    );

    expect($import->status)->toBe(LegacyRmeImportStatus::QUEUED)
        ->and($import->origin_branch_id)->toBe(legacyRmeBranch('TKM1')->id)
        ->and($import->selected_rme_date?->toDateString())->toBe('2024-01-28')
        ->and($import->latest_rme_date?->toDateString())->toBe('2024-08-31')
        // The patient still has no native RME afterwards. Nothing was invented
        // to satisfy the date rule.
        ->and(ClinicVisit::where('patient_id', $patient->getKey())->count())->toBe(0)
        ->and(MedicalRecord::where('patient_id', $patient->getKey())->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Publish-time revalidation
|--------------------------------------------------------------------------
| The staging snapshot is evidence, never the authority: a native RME can
| appear between upload and publish.
*/

/** A fully rendered, reviewed import for a patient who has NO native RME. */
function lrmeFixReviewedNoNative(string $earliest = '2024-01-28', ?string $latest = '2024-08-31'): LegacyRmeImport
{
    app()->instance(LegacyRmePdfInspectorInterface::class, (new FakeLegacyRmePdfInspector)->withPages(2));
    app()->instance(LegacyRmePdfRasterizerInterface::class, (new FakeLegacyRmePdfRasterizer)->withPages(2));

    $patient = legacyRmeArchivablePatient(['date_of_birth' => '1990-01-01'], 'TKM1');

    $import = app(LegacyRmeImportService::class)->createFromUpload(
        $patient,
        $earliest,
        $patient->medical_record_number,
        null,
        legacyRmePdfUpload('arsip.pdf', 2),
        superAdmin(),
        $latest,
    );

    app(LegacyRmeImportProcessingService::class)->process($import->getKey());

    return app(LegacyRmePublishService::class)->review($import->refresh(), superAdmin())->refresh();
}

it('publishes a no-native import and carries the declared range onto the record', function () {
    $import = lrmeFixReviewedNoNative();

    $record = app(LegacyRmePublishService::class)->publish($import, [], superAdmin());

    expect($record->status)->toBe(LegacyRmeRecord::STATUS_PUBLISHED)
        // The representative date is the EARLIEST one.
        ->and($record->rme_date?->toDateString())->toBe('2024-01-28')
        ->and($record->latest_rme_date?->toDateString())->toBe('2024-08-31')
        ->and($record->origin_branch_id)->toBe(legacyRmeBranch('TKM1')->id);
});

// THE RACE THIS CORRECTIVE HAD TO COVER: the import was legitimately staged for
// a patient with no native RME, and a real encounter appeared before publish.
it('refuses to publish when a native RME appears that the declared range crosses', function () {
    $import = lrmeFixReviewedNoNative('2024-01-28', '2024-08-31');

    legacyRmeNativeVisit($import->patient, '2024-06-01');

    expect(fn () => app(LegacyRmePublishService::class)->publish($import->refresh(), [], superAdmin()))
        ->toThrow(ValidationException::class);

    expect(LegacyRmeRecord::count())->toBe(0)
        ->and($import->refresh()->status)->toBe(LegacyRmeImportStatus::REVIEWED);
});

it('still publishes when the new native RME is later than every declared date', function () {
    $import = lrmeFixReviewedNoNative('2024-01-28', '2024-08-31');

    legacyRmeNativeVisit($import->patient, '2025-02-01');

    $record = app(LegacyRmePublishService::class)->publish($import->refresh(), [], superAdmin());

    expect($record->status)->toBe(LegacyRmeRecord::STATUS_PUBLISHED);
});

// Only the representative date would have passed this check before the fix.
it('refuses to publish when only the latest declared date crosses the new native RME', function () {
    $import = lrmeFixReviewedNoNative('2024-01-28', '2024-08-31');

    // Later than the representative date, earlier than the latest one.
    legacyRmeNativeVisit($import->patient, '2024-03-01');

    expect(fn () => app(LegacyRmePublishService::class)->publish($import->refresh(), [], superAdmin()))
        ->toThrow(ValidationException::class);

    expect(LegacyRmeRecord::count())->toBe(0);
});

it('refuses to publish when the patient Nomor RM no longer resolves to the staged branch', function () {
    $import = lrmeFixReviewedNoNative();

    // The patient was moved to another branch after staging, so the staged
    // owner is stale — and the owner decides who can read the permanent record.
    $import->patient->forceFill(['medical_record_number' => 'DG-LDK2-2024-0001'])->save();
    legacyRmeBranch('LDK2', 'Cabang Landak');

    expect(fn () => app(LegacyRmePublishService::class)->publish($import->refresh(), [], superAdmin()))
        ->toThrow(ValidationException::class);

    expect(LegacyRmeRecord::count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Non-regression — a legacy archive is never a clinical transaction
|--------------------------------------------------------------------------
| Asserted for BOTH migration cases, because the no-native path is new.
*/

it('creates no clinical or financial transaction for either kind of patient', function (bool $withNativeRme) {
    app()->instance(LegacyRmePdfInspectorInterface::class, (new FakeLegacyRmePdfInspector)->withPages(1));
    app()->instance(LegacyRmePdfRasterizerInterface::class, (new FakeLegacyRmePdfRasterizer)->withPages(1));

    $patient = legacyRmeArchivablePatient(['date_of_birth' => '1990-01-01'], 'TKM1');

    if ($withNativeRme) {
        legacyRmeNativeVisit($patient, '2025-06-01');
    }

    $visitsBefore = ClinicVisit::count();
    $recordsBefore = MedicalRecord::count();

    $import = app(LegacyRmeImportService::class)->createFromUpload(
        $patient,
        '2024-01-28',
        $patient->medical_record_number,
        null,
        legacyRmePdfUpload('arsip.pdf', 1),
        superAdmin(),
        '2024-08-31',
    );

    app(LegacyRmeImportProcessingService::class)->process($import->getKey());
    $reviewed = app(LegacyRmePublishService::class)->review($import->refresh(), superAdmin());

    app(LegacyRmePublishService::class)->publish($reviewed->refresh(), [], superAdmin());

    expect(ClinicVisit::count())->toBe($visitsBefore)
        ->and(MedicalRecord::count())->toBe($recordsBefore)
        ->and(RmeInvoice::count())->toBe(0)
        ->and(RmePayment::count())->toBe(0)
        ->and(LabOrder::count())->toBe(0)
        ->and(LabCaseCandidate::count())->toBe(0)
        ->and(SatusehatCandidate::count())->toBe(0);
})->with([
    'patient with a native RME' => [true],
    'patient with no native RME' => [false],
]);

/*
|--------------------------------------------------------------------------
| Row visibility follows the derived branch
|--------------------------------------------------------------------------
*/

it('keeps an archive visible only to operators of the RM-derived branch', function () {
    $patient = legacyRmeArchivablePatient(['date_of_birth' => '1990-01-01'], 'TKM1');

    $import = app(LegacyRmeImportService::class)->createFromUpload(
        $patient,
        '2015-01-01',
        $patient->medical_record_number,
        null,
        legacyRmePdfUpload(),
        superAdmin(),
    );

    $ownBranchOperator = userWith(['view_legacy_rme_imports']);
    $ownBranchOperator->forceFill(['branch_id' => legacyRmeBranch('TKM1')->id])->save();

    $otherBranchOperator = userWith(['view_legacy_rme_imports']);
    $otherBranchOperator->forceFill(['branch_id' => legacyRmeBranch('LDK2', 'Cabang Landak')->id])->save();

    $this->actingAs($ownBranchOperator)
        ->get(route('settings.rme.legacy-imports.show', $import->getKey()))
        ->assertOk();

    $this->actingAs($otherBranchOperator)
        ->get(route('settings.rme.legacy-imports.show', $import->getKey()))
        ->assertNotFound();
});

/*
|--------------------------------------------------------------------------
| The HTTP boundary
|--------------------------------------------------------------------------
*/

it('accepts the declared range through the upload endpoint', function () {
    $patient = legacyRmeArchivablePatient(['date_of_birth' => '1990-01-01'], 'TKM1');

    $this->actingAs(superAdmin())
        ->post(route('settings.rme.legacy-imports.store'), [
            'patient_id' => $patient->getKey(),
            'selected_rme_date' => '2024-01-28',
            'latest_rme_date' => '2024-08-31',
            'document' => legacyRmePdfUpload(),
            'patient_confirmation' => '1',
            'date_confirmation' => '1',
            'source_rm_raw' => $patient->medical_record_number,
            'source_rm_confirmation' => '1',
        ])
        ->assertSessionHasNoErrors();

    $import = LegacyRmeImport::latest('id')->first();

    expect($import->selected_rme_date?->toDateString())->toBe('2024-01-28')
        ->and($import->latest_rme_date?->toDateString())->toBe('2024-08-31')
        ->and($import->origin_branch_id)->toBe(legacyRmeBranch('TKM1')->id);
});

it('rejects a forged origin branch submitted through the upload endpoint', function () {
    $patient = legacyRmeArchivablePatient(['date_of_birth' => '1990-01-01'], 'TKM1');
    $other = legacyRmeBranch('LDK2', 'Cabang Landak');

    $this->actingAs(superAdmin())
        ->post(route('settings.rme.legacy-imports.store'), [
            'patient_id' => $patient->getKey(),
            'selected_rme_date' => '2015-01-01',
            'origin_branch_id' => $other->id,
            'document' => legacyRmePdfUpload(),
            'patient_confirmation' => '1',
            'date_confirmation' => '1',
            'source_rm_raw' => $patient->medical_record_number,
            'source_rm_confirmation' => '1',
        ])
        ->assertSessionHasErrors('origin_branch_id');

    expect(LegacyRmeImport::count())->toBe(0);
});

it('rejects a reversed range through the upload endpoint', function () {
    $patient = legacyRmeArchivablePatient(['date_of_birth' => '1990-01-01'], 'TKM1');

    $this->actingAs(superAdmin())
        ->post(route('settings.rme.legacy-imports.store'), [
            'patient_id' => $patient->getKey(),
            'selected_rme_date' => '2024-08-31',
            'latest_rme_date' => '2024-01-28',
            'document' => legacyRmePdfUpload(),
            'patient_confirmation' => '1',
            'date_confirmation' => '1',
            'source_rm_raw' => $patient->medical_record_number,
            'source_rm_confirmation' => '1',
        ])
        ->assertSessionHasErrors();

    expect(LegacyRmeImport::count())->toBe(0);
});
