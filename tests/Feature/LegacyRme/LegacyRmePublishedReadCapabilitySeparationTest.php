<?php

/**
 * LEGACY-RME-PDF-HISTORY-1A — published clinical read vs migration capability.
 *
 * THE PRODUCT DECISION THIS FILE PINS DOWN.
 *
 * Legacy MIGRATION (upload, processing, retry, review, publish, void, branch
 * admission) and PUBLISHED CLINICAL READ are two different capabilities and are
 * governed separately:
 *
 *   migration capability OFF + branch admission EMPTY
 *   + an already-PUBLISHED legacy record
 *   + a properly authorized clinical reader
 *   = READ ALLOWED
 *
 * A doctor must still be able to read the patient's legitimate archived history
 * when that patient comes in for a new visit, WITHOUT the owner re-opening the
 * ability to import new documents. Turning ingestion off is an emergency stop
 * for MUTATIONS; it is not a statement that evidence already published stopped
 * being part of the patient's medical history.
 *
 * READ AVAILABILITY IS NOT PUBLIC AVAILABILITY. Every read here still passes
 * authentication, a named read permission, server-resolved branch scope, the
 * treating-doctor relationship (DoctorPatientScopeService), the PUBLISHED status
 * check, and the private-disk policy gate. Those are exercised below in the
 * negative direction too.
 */

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\ClinicVisit\Services\ClinicVisitService;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\LegacyRme\Models\LegacyRmeImport;
use App\Modules\LegacyRme\Models\LegacyRmeRecord;
use App\Modules\LegacyRme\Services\LegacyRmeBranchAdmissionService;
use App\Modules\LegacyRme\Services\LegacyRmeImportService;
use App\Modules\LegacyRme\Services\LegacyRmePatientHistoryService;
use App\Modules\LegacyRme\Support\LegacyRmeAdmissionDecision;
use App\Modules\LegacyRme\Support\LegacyRmeFeatureGuard;
use App\Modules\LegacyRme\Support\LegacyRmeImportStatus;
use App\Modules\LegacyRme\Support\LegacyRmeTimelineEntry;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\Patient\Models\Patient;
use App\Modules\RME\Models\PatientDoctorAssignment;
use App\Modules\RmeOnlineContext\Middleware\EnsureRmeOnlineContext;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

uses()->group('LegacyRme');

beforeEach(function () {
    seedAccessControl();

    // Every test in this file runs with the MIGRATION capability switched OFF —
    // that is the whole point. Tests that need it on say so explicitly.
    legacyRmeArchiveFlag(false);

    // MAIN is never an RME clinic branch; leaving it RME-enabled would let an
    // unpinned BranchContext fall back into scope and mask a real failure.
    Branch::where('code', Branch::MAIN_CODE)->update(['is_rme_enabled' => false]);
});

/*
|--------------------------------------------------------------------------
| Fixtures
|--------------------------------------------------------------------------
*/

function h1aPatient(string $branchCode = 'TKM1'): Patient
{
    return legacyRmeArchivablePatient(['date_of_birth' => '1980-01-01'], $branchCode);
}

function h1aVisit(Patient $patient, string $date): ClinicVisit
{
    return ClinicVisit::factory()->create([
        'branch_id' => $patient->branch_id,
        'patient_id' => $patient->getKey(),
        'visit_date' => $date,
    ]);
}

function h1aSheet(ClinicVisit $visit): MedicalRecord
{
    return MedicalRecord::factory()->create([
        'clinic_visit_id' => $visit->getKey(),
        'branch_id' => $visit->branch_id,
        'patient_id' => $visit->patient_id,
        'doctor_id' => $visit->doctor_id,
    ]);
}

/** A published archive owned by the patient's own branch, so branch scope is really exercised. */
function h1aPublished(Patient $patient, string $date, array $overrides = []): LegacyRmeRecord
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

/** An RME workspace operator who may read the archive. */
function h1aReader(Patient $patient): User
{
    $user = userWith(['manage_clinic_visits', 'view_clinic_visits', 'view_legacy_rme_archive']);
    $user->forceFill(['branch_id' => $patient->branch_id])->save();

    return $user->refresh();
}

/**
 * A Doctor-role user, optionally with a real clinical relationship to the patient.
 *
 * Sprint 66.0's EnsureRmeOnlineContext middleware redirects any Doctor-role user
 * who has not selected an online context — orthogonal to this sprint, so the
 * HTTP cases below bypass it in order to exercise the legacy read boundary
 * itself rather than the online-context redirect.
 */
function h1aDoctor(Patient $patient, bool $treating): User
{
    $user = User::factory()->create(['branch_id' => $patient->branch_id]);
    $user->assignRole('Doctor');
    $user->givePermissionTo('view_legacy_rme_archive');

    // HISTORY-1B — the doctor's legacy branch scope is their assigned practice
    // set, so the master (not just the user row) has to name the patient's
    // branch for this fixture to describe a doctor who really works there.
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

/** The workspace history exactly as the controller builds it. */
function h1aHistory(?User $user, Patient $patient, ?int $currentVisitId = null)
{
    return app(LegacyRmePatientHistoryService::class)->patientClinicalHistoryFor(
        $user,
        (int) $patient->getKey(),
        app(ClinicVisitService::class)->patientVisitHistory((int) $patient->getKey()),
        $currentVisitId,
    );
}

function h1aLegacyRows($history)
{
    return $history->where('kind', LegacyRmeTimelineEntry::KIND_LEGACY);
}

/** Row counts for the tables a legacy read must never touch. */
function h1aClinicalCounts(): array
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
| A + B — the headline acceptance criterion
|--------------------------------------------------------------------------
*/

it('shows a published archive to an authorized reader while migration is off', function () {
    $patient = h1aPatient();
    $visit = h1aVisit($patient, '2024-06-01');
    h1aSheet($visit);
    h1aPublished($patient, '2019-04-17');
    $reader = h1aReader($patient);

    expect(app(LegacyRmeFeatureGuard::class)->migrationEnabled())->toBeFalse();

    expect(h1aLegacyRows(h1aHistory($reader, $patient)))->toHaveCount(1);
});

it('keeps the archive readable when migration is off AND no branch is admitted', function () {
    $patient = h1aPatient();
    $visit = h1aVisit($patient, '2024-06-01');
    h1aSheet($visit);
    $record = h1aPublished($patient, '2019-04-17');
    $reader = h1aReader($patient);

    // The exact production posture: capability off, admission closed.
    legacyRmeAdmittedBranches([]);

    expect(h1aLegacyRows(h1aHistory($reader, $patient)))->toHaveCount(1);

    $this->actingAs($reader)
        ->get(route('rme.legacy-records.show', $record->getKey()))
        ->assertOk();
});

/*
|--------------------------------------------------------------------------
| C + D — migration stays blocked in every direction
|--------------------------------------------------------------------------
*/

it('refuses a new upload while migration is off', function () {
    $patient = h1aPatient();
    h1aVisit($patient, '2024-06-01');

    expect(fn () => app(LegacyRmeImportService::class)->createFromUpload(
        $patient,
        '2019-04-17',
        $patient->medical_record_number,
        null,
        legacyRmePdfUpload(),
        superAdmin(),
    ))->toThrow(ValidationException::class);

    expect(LegacyRmeImport::count())->toBe(0);
});

it('refuses every legacy mutation route while migration is off', function (string $routeName) {
    $patient = h1aPatient();

    $import = LegacyRmeImport::factory()->create([
        'patient_id' => $patient->getKey(),
        'status' => LegacyRmeImportStatus::READY_FOR_REVIEW,
    ]);

    $this->actingAs(superAdmin())
        ->post(route($routeName, $import->getKey()))
        ->assertNotFound();
})->with([
    'retry' => ['settings.rme.legacy-imports.retry'],
    'cancel' => ['settings.rme.legacy-imports.cancel'],
    'review' => ['settings.rme.legacy-imports.review'],
    'publish' => ['settings.rme.legacy-imports.publish'],
]);

it('refuses a void while migration is off, because voiding changes the archive', function () {
    $patient = h1aPatient();
    $record = h1aPublished($patient, '2019-04-17');

    $this->actingAs(superAdmin())
        ->post(route('rme.legacy-records.void', $record->getKey()), [
            'void_reason' => 'Percobaan pembatalan saat kapabilitas migrasi mati.',
        ])
        ->assertNotFound();

    expect(LegacyRmeRecord::query()->find($record->getKey())?->isPublished())->toBeTrue();
});

it('refuses the migration workspace itself while migration is off', function (string $routeName) {
    $this->actingAs(superAdmin())->get(route($routeName))->assertNotFound();
})->with([
    'index' => ['settings.rme.legacy-imports.index'],
    'create' => ['settings.rme.legacy-imports.create'],
]);

it('denies branch admission while migration is off', function () {
    h1aPatient();

    $decision = app(LegacyRmeBranchAdmissionService::class)->decideForBranchCode('TKM1');

    expect($decision->admitted)->toBeFalse()
        ->and($decision->code)->toBe(LegacyRmeAdmissionDecision::CODE_FEATURE_DISABLED);
});

/*
|--------------------------------------------------------------------------
| E — published evidence survives the capability being off
|--------------------------------------------------------------------------
*/

it('leaves published evidence completely intact while migration is off', function () {
    $patient = h1aPatient();
    $record = h1aPublished($patient, '2019-04-17');

    $before = LegacyRmeRecord::query()->find($record->getKey())->only([
        'status', 'source_pdf_path', 'source_pdf_sha256', 'page_count', 'rme_date', 'published_at',
    ]);

    $reader = h1aReader($patient);
    $this->actingAs($reader)->get(route('rme.legacy-records.show', $record->getKey()))->assertOk();

    $after = LegacyRmeRecord::query()->find($record->getKey())->only([
        'status', 'source_pdf_path', 'source_pdf_sha256', 'page_count', 'rme_date', 'published_at',
    ]);

    expect($after)->toEqual($before);
});

/*
|--------------------------------------------------------------------------
| F + G + H — authorization is still the whole boundary
|--------------------------------------------------------------------------
*/

it('hides the archive from a same-branch doctor who is not treating the patient', function () {
    $patient = h1aPatient();
    h1aVisit($patient, '2024-06-01');
    $record = h1aPublished($patient, '2019-04-17');

    $stranger = h1aDoctor($patient, treating: false);

    expect(h1aLegacyRows(h1aHistory($stranger, $patient)))->toBeEmpty();

    // 403, not 404, and that is the canonical distinction this module already
    // draws (LEGACY-RME-PDF-1C/1D/ROLL-2): 404 means "outside your branch
    // scope", which is the cross-branch anti-enumeration boundary; 403 means
    // "inside your branch but you are not authorized", which is the
    // treating-doctor gate. Denied either way, and unchanged by HISTORY-1A.
    $this->withoutMiddleware(EnsureRmeOnlineContext::class)
        ->actingAs($stranger)
        ->get(route('rme.legacy-records.show', $record->getKey()))
        ->assertForbidden();
});

it('shows the archive to the treating doctor of that same patient', function () {
    $patient = h1aPatient();
    h1aVisit($patient, '2024-06-01');
    $record = h1aPublished($patient, '2019-04-17');

    $treating = h1aDoctor($patient, treating: true);

    expect(h1aLegacyRows(h1aHistory($treating, $patient)))->toHaveCount(1);

    $this->withoutMiddleware(EnsureRmeOnlineContext::class)
        ->actingAs($treating)
        ->get(route('rme.legacy-records.show', $record->getKey()))
        ->assertOk();
});

it('refuses a reader holding no legacy read permission', function () {
    $patient = h1aPatient();
    $record = h1aPublished($patient, '2019-04-17');

    $user = userWith(['view_clinic_visits']);
    $user->forceFill(['branch_id' => $patient->branch_id])->save();

    expect(h1aLegacyRows(h1aHistory($user->refresh(), $patient)))->toBeEmpty();

    $this->actingAs($user)
        ->get(route('rme.legacy-records.show', $record->getKey()))
        ->assertForbidden();
});

it('refuses a guest and sends them to the login screen', function () {
    $patient = h1aPatient();
    $record = h1aPublished($patient, '2019-04-17');

    $this->get(route('rme.legacy-records.show', $record->getKey()))
        ->assertRedirect(route('login'));

    expect(h1aLegacyRows(h1aHistory(null, $patient)))->toBeEmpty();
});

it('hides an archive that belongs to another branch behind a 404', function () {
    $mine = h1aPatient('TKM1');
    $theirs = h1aPatient('LDK2');

    $foreign = h1aPublished($theirs, '2019-04-17');
    $reader = h1aReader($mine);

    $this->actingAs($reader)
        ->get(route('rme.legacy-records.show', $foreign->getKey()))
        ->assertNotFound();
});

it('refuses a direct id attack on another patient archive', function () {
    $patientA = h1aPatient();
    $patientB = h1aPatient();

    h1aVisit($patientA, '2024-06-01');
    $recordB = h1aPublished($patientB, '2019-04-17');

    $doctorForA = h1aDoctor($patientA, treating: true);

    // Authorized for patient A; asking for patient B's archive by raw id.
    // Both patients sit in the SAME branch here, which is the harder case:
    // branch scope alone would have let this through, and it is the
    // treating-doctor gate that refuses it (403). The cross-branch variant is
    // covered separately and answers 404.
    $this->withoutMiddleware(EnsureRmeOnlineContext::class)
        ->actingAs($doctorForA)
        ->get(route('rme.legacy-records.show', $recordB->getKey()))
        ->assertForbidden();

    expect(h1aLegacyRows(h1aHistory($doctorForA, $patientB)))->toBeEmpty();
});

/*
|--------------------------------------------------------------------------
| I + J — only PUBLISHED is ordinary clinical history
|--------------------------------------------------------------------------
*/

it('keeps a VOIDed archive out of the active clinical history while preserving it', function () {
    $patient = h1aPatient();
    h1aVisit($patient, '2024-06-01');
    $record = h1aPublished($patient, '2019-04-17', [
        'status' => LegacyRmeRecord::STATUS_VOID,
        'voided_at' => now(),
        'void_reason' => 'Salah pasien.',
    ]);
    $reader = h1aReader($patient);

    expect(h1aLegacyRows(h1aHistory($reader, $patient)))->toBeEmpty();

    // Retracted, not erased.
    expect(LegacyRmeRecord::query()->find($record->getKey()))->not->toBeNull();

    // And its bytes no longer stream. Super Admin is the actor here because the
    // single global Gate::before grants them every ability — so this proves the
    // NON-policy guard (assertStreamable) refuses a voided record, which is the
    // stronger claim. An ordinary reader is already refused by the policy.
    $this->actingAs(superAdmin())
        ->get(route('rme.legacy-records.source', $record->getKey()))
        ->assertNotFound();

    $this->actingAs($reader)
        ->get(route('rme.legacy-records.source', $record->getKey()))
        ->assertForbidden();
});

it('never shows a non-published import as clinical history', function (string $status) {
    $patient = h1aPatient();
    h1aVisit($patient, '2024-06-01');

    LegacyRmeImport::factory()->create([
        'patient_id' => $patient->getKey(),
        'status' => $status,
    ]);

    expect(h1aLegacyRows(h1aHistory(h1aReader($patient), $patient)))->toBeEmpty();
})->with([
    LegacyRmeImportStatus::UPLOADED,
    LegacyRmeImportStatus::PROCESSING,
    LegacyRmeImportStatus::READY_FOR_REVIEW,
    LegacyRmeImportStatus::FAILED,
]);

/*
|--------------------------------------------------------------------------
| K + L + M — native, unified and legacy-only histories
|--------------------------------------------------------------------------
*/

it('leaves a native-only patient history exactly as it was', function () {
    $patient = h1aPatient();
    $visit = h1aVisit($patient, '2024-06-01');
    h1aSheet($visit);
    $reader = h1aReader($patient);

    $history = h1aHistory($reader, $patient, (int) $visit->getKey());

    expect(h1aLegacyRows($history))->toBeEmpty()
        ->and($history)->toHaveCount(1);
});

it('merges native and legacy into one newest-first history while migration is off', function () {
    $patient = h1aPatient();
    $visit = h1aVisit($patient, '2024-06-01');
    h1aSheet($visit);
    h1aPublished($patient, '2019-04-17');
    $reader = h1aReader($patient);

    $history = h1aHistory($reader, $patient, (int) $visit->getKey());

    expect($history)->toHaveCount(2)
        ->and($history->first()->isLegacy())->toBeFalse()
        ->and($history->first()->date->format('Y-m-d'))->toBe('2024-06-01')
        ->and($history->last()->isLegacy())->toBeTrue()
        ->and($history->last()->date->format('Y-m-d'))->toBe('2019-04-17');
});

it('reads the archive of a patient whose only clinical history is legacy', function () {
    // No native visit at all — the archive is everything this patient has.
    $patient = h1aPatient();
    $record = h1aPublished($patient, '2019-04-17');
    $reader = h1aReader($patient);

    expect(h1aLegacyRows(h1aHistory($reader, $patient)))->toHaveCount(1);

    $this->actingAs($reader)
        ->get(route('rme.legacy-records.show', $record->getKey()))
        ->assertOk();

    // Reading it never manufactures a native encounter to justify itself.
    expect(ClinicVisit::where('patient_id', $patient->getKey())->count())->toBe(0)
        ->and(MedicalRecord::where('patient_id', $patient->getKey())->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| N — multi-date semantics are unchanged
|--------------------------------------------------------------------------
*/

it('reports a truthful date range only when the archive really spans one', function () {
    $patient = h1aPatient();
    h1aPublished($patient, '2018-01-02', ['latest_rme_date' => '2019-11-30']);
    $reader = h1aReader($patient);

    $entry = h1aLegacyRows(h1aHistory($reader, $patient))->first();

    expect($entry->date->format('Y-m-d'))->toBe('2018-01-02')
        ->and($entry->endDate?->format('Y-m-d'))->toBe('2019-11-30')
        ->and($entry->hasDateRange())->toBeTrue();
});

it('reports a single date as a single date', function () {
    $patient = h1aPatient();
    h1aPublished($patient, '2018-01-02', ['latest_rme_date' => null]);
    $reader = h1aReader($patient);

    $entry = h1aLegacyRows(h1aHistory($reader, $patient))->first();

    expect($entry->date->format('Y-m-d'))->toBe('2018-01-02')
        ->and($entry->endDate)->toBeNull()
        ->and($entry->hasDateRange())->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| O — authorized print and export follow the same separation
|--------------------------------------------------------------------------
*/

it('refuses print and export to an unauthorized reader while migration is off', function (string $routeName) {
    $patient = h1aPatient();
    $record = h1aPublished($patient, '2019-04-17');

    $stranger = h1aDoctor($patient, treating: false);

    // Same canonical distinction as the viewer: same branch, no treating
    // relationship, so the policy refuses with 403. Print and export are never
    // a weaker door than the viewer they print.
    $this->withoutMiddleware(EnsureRmeOnlineContext::class)
        ->actingAs($stranger)
        ->get(route($routeName, $record->getKey()))
        ->assertForbidden();
})->with([
    'print' => ['rme.legacy-records.print'],
    'export' => ['rme.legacy-records.export'],
]);

/*
|--------------------------------------------------------------------------
| P — reading produces no clinical side effect whatsoever
|--------------------------------------------------------------------------
*/

it('creates no visit, record, billing, lab or SATUSEHAT row when the archive is read', function () {
    $patient = h1aPatient();
    $visit = h1aVisit($patient, '2024-06-01');
    h1aSheet($visit);
    $record = h1aPublished($patient, '2019-04-17');
    $reader = h1aReader($patient);

    $before = h1aClinicalCounts();

    h1aHistory($reader, $patient, (int) $visit->getKey());
    $this->actingAs($reader)->get(route('rme.legacy-records.show', $record->getKey()))->assertOk();

    expect(h1aClinicalCounts())->toEqual($before);
});

/*
|--------------------------------------------------------------------------
| The guard's own contract
|--------------------------------------------------------------------------
*/

it('reports migration capability truthfully in both directions', function () {
    $guard = app(LegacyRmeFeatureGuard::class);

    expect($guard->migrationEnabled())->toBeFalse()
        ->and($guard->enabled())->toBeFalse();

    expect(fn () => $guard->assertMigrationEnabled())->toThrow(ValidationException::class);

    legacyRmeArchiveFlag(true);

    expect($guard->migrationEnabled())->toBeTrue()
        ->and($guard->enabled())->toBeTrue();
});

it('reports published read availability separately from migration capability', function () {
    $exit = Artisan::call('legacy-rme:wave-status', ['--json' => true]);
    $report = json_decode(Artisan::output(), true);

    expect($exit)->toBe(0)
        ->and($report)->toBeArray()
        // The operator must be able to read "migration is off" WITHOUT reading
        // it as "the archive is gone", so the two are reported side by side.
        ->and($report['migration_capability_enabled'])->toBeFalse()
        ->and($report['published_clinical_read_available'])->toBeTrue();
});
