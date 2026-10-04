<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\LegacyImport\Completeness\Interfaces\LegacyPatientArchiveCompletenessRepositoryInterface;
use App\Modules\LegacyImport\Completeness\Services\LegacyPatientArchiveCompletenessService;
use App\Modules\LegacyImport\Completeness\Support\LegacyArchiveCompleteness;
use App\Modules\LegacyImport\Completeness\Support\LegacyArchiveDocumentState;
use App\Modules\LegacyImport\Completeness\Support\LegacyCompletenessFilter;
use App\Modules\LegacyImport\Services\LegacySingleActiveDocumentService;
use App\Modules\LegacyImport\Support\LegacyImportType;
use App\Modules\LegacyOdontogram\Models\LegacyOdontogramImport;
use App\Modules\LegacyOdontogram\Models\LegacyOdontogramRecord;
use App\Modules\LegacyOdontogram\Support\LegacyOdontogramImportStatus;
use App\Modules\LegacyOdontogram\Support\LegacyOdontogramRecordStatus;
use App\Modules\LegacyRme\Models\LegacyRmeImport;
use App\Modules\LegacyRme\Models\LegacyRmeRecord;
use App\Modules\LegacyRme\Support\LegacyRmeImportStatus;
use App\Modules\Patient\Models\LegacyPatientImportBatch;
use App\Modules\Patient\Models\Patient;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| FEATURE-LEGACY-PATIENT-DOCUMENT-COMPLETENESS-1 — provenance + lifecycle
|--------------------------------------------------------------------------
|
| The two properties this page lives or dies by:
|
|   1. ONLY legacy-IMPORTED patients appear. A natively registered patient is
|      out of scope by construction, however old their Nomor RM looks and
|      whether or not they have a native RME.
|
|   2. COMPLETENESS IS DERIVED FROM THE REAL STATE MACHINES, in the same
|      record-before-staging order the occupancy guard uses, so the page never
|      reports "Belum Ada" for a slot the server considers occupied and never
|      sends an operator to an upload that will be refused.
|
| The occupancy agreement is asserted against the REAL
| LegacySingleActiveDocumentService rather than against a restatement of its
| rules — a copy of those rules in a test would pass while production drifted.
*/

beforeEach(function (): void {
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);

    $this->branch = lcoBranch('TLK1', 'Cabang Telkomas');
    $this->service = app(LegacyPatientArchiveCompletenessService::class);
    $this->governor = lcoGovernor();
});

/** A patient created by the canonical legacy patient import. */
function lcoLegacyPatient(Branch $branch, array $attributes = []): Patient
{
    $batch = LegacyPatientImportBatch::query()->create([
        'uuid' => (string) Str::uuid(),
        'original_filename' => 'legacy-patients.csv',
        'status' => LegacyPatientImportBatch::STATUS_COMMITTED,
    ]);

    return Patient::factory()->create(array_merge([
        'branch_id' => $branch->id,
        'import_batch_id' => $batch->id,
        'date_of_birth' => '1990-01-01',
    ], $attributes));
}

/** A patient registered through the ordinary application flow. */
function lcoNativePatient(Branch $branch, array $attributes = []): Patient
{
    return Patient::factory()->create(array_merge([
        'branch_id' => $branch->id,
        'import_batch_id' => null,
        'date_of_birth' => '1990-01-01',
    ], $attributes));
}

function lcoBranch(string $code, string $name): Branch
{
    return Branch::query()->firstOrCreate(
        ['code' => $code],
        ['name' => $name, 'is_active' => true, 'is_rme_enabled' => true],
    );
}

/** The governance tier: Supervisor RME holds review+publish on both archives. */
function lcoGovernor(): User
{
    $user = User::factory()->create();
    $user->assignRole('Supervisor RME');

    return $user->fresh();
}

function lcoRows(User $user, ?string $filter = null, ?string $search = null, ?int $branchId = null): array
{
    $service = app(LegacyPatientArchiveCompletenessService::class);
    $query = $service->resolveQuery($user, $filter, $search, $branchId);

    return $service->rows($query)->items();
}

function lcoRowFor(User $user, Patient $patient, ?string $filter = null): ?object
{
    foreach (lcoRows($user, $filter) as $row) {
        if ($row->patientId === $patient->id) {
            return $row;
        }
    }

    return null;
}

/* ---------------------------------------------------------------- provenance */

it('lists a legacy-imported patient that is missing both documents', function (): void {
    $patient = lcoLegacyPatient($this->branch);

    $row = lcoRowFor($this->governor, $patient);

    expect($row)->not->toBeNull()
        ->and($row->rmeState)->toBe(LegacyArchiveDocumentState::MISSING)
        ->and($row->odontogramState)->toBe(LegacyArchiveDocumentState::MISSING)
        ->and($row->completeness)->toBe(LegacyArchiveCompleteness::INCOMPLETE);
});

it('lists a legacy-imported patient that has only a published RME', function (): void {
    $patient = lcoLegacyPatient($this->branch);
    LegacyRmeRecord::factory()->create(['patient_id' => $patient->id, 'origin_branch_id' => $this->branch->id]);

    $row = lcoRowFor($this->governor, $patient);

    expect($row)->not->toBeNull()
        ->and($row->rmeState)->toBe(LegacyArchiveDocumentState::PUBLISHED)
        ->and($row->odontogramState)->toBe(LegacyArchiveDocumentState::MISSING)
        ->and($row->completeness)->toBe(LegacyArchiveCompleteness::INCOMPLETE);
});

it('lists a legacy-imported patient that has only a published odontogram', function (): void {
    $patient = lcoLegacyPatient($this->branch);
    LegacyOdontogramRecord::factory()->create(['patient_id' => $patient->id, 'branch_id' => $this->branch->id]);

    $row = lcoRowFor($this->governor, $patient);

    expect($row)->not->toBeNull()
        ->and($row->rmeState)->toBe(LegacyArchiveDocumentState::MISSING)
        ->and($row->odontogramState)->toBe(LegacyArchiveDocumentState::PUBLISHED)
        ->and($row->completeness)->toBe(LegacyArchiveCompleteness::INCOMPLETE);
});

it('excludes a legacy patient whose both documents are published from the default view', function (): void {
    $patient = lcoLegacyPatient($this->branch);
    LegacyRmeRecord::factory()->create(['patient_id' => $patient->id, 'origin_branch_id' => $this->branch->id]);
    LegacyOdontogramRecord::factory()->create(['patient_id' => $patient->id, 'branch_id' => $this->branch->id]);

    expect(lcoRowFor($this->governor, $patient))->toBeNull();

    // Still reachable under the explicit audit filter, and reported COMPLETE.
    $row = lcoRowFor($this->governor, $patient, LegacyCompletenessFilter::COMPLETE);

    expect($row)->not->toBeNull()
        ->and($row->completeness)->toBe(LegacyArchiveCompleteness::COMPLETE);
});

it('never lists a natively registered patient that is missing both documents', function (): void {
    $native = lcoNativePatient($this->branch);

    expect(lcoRowFor($this->governor, $native))->toBeNull();

    // Nor under ANY filter, including the audit view — exclusion is structural,
    // not a property of the default filter.
    foreach (LegacyCompletenessFilter::ALL as $filter) {
        expect(lcoRowFor($this->governor, $native, $filter))->toBeNull();
    }
});

it('never lists a native patient merely because their nomor RM looks old', function (): void {
    $native = lcoNativePatient($this->branch, [
        'medical_record_number' => 'DG-TLK1-2014-0001',
        'registered_at' => '2014-03-01',
    ]);

    expect(lcoRowFor($this->governor, $native))->toBeNull();
});

it('never lists a native patient merely because they have no native RME', function (): void {
    // A native patient with no clinic visit and no medical record at all. The
    // absence of native RME is NOT a provenance signal and must not be read as
    // one.
    $native = lcoNativePatient($this->branch);

    $visitCount = DB::table('trx_clinic_visits')
        ->where('patient_id', $native->id)
        ->count();

    expect($visitCount)->toBe(0)
        ->and(lcoRowFor($this->governor, $native))->toBeNull();
});

it('never lists a native patient that happens to carry a legacy archive', function (): void {
    // Defence in depth: provenance is decided by import_batch_id, never by the
    // presence of a legacy document. A native patient with an archive is a data
    // state this page must not claim as its own.
    $native = lcoNativePatient($this->branch);
    LegacyRmeRecord::factory()->create(['patient_id' => $native->id, 'origin_branch_id' => $this->branch->id]);

    expect(lcoRowFor($this->governor, $native))->toBeNull();
});

it('excludes a soft-deleted legacy patient', function (): void {
    $patient = lcoLegacyPatient($this->branch);
    $patient->delete();

    expect(lcoRowFor($this->governor, $patient))->toBeNull();
});

it('still lists an inactive legacy patient', function (): void {
    // "Inactive" is an operational flag about the patient, not a statement that
    // their historical archive no longer needs filing.
    $patient = lcoLegacyPatient($this->branch, ['is_active' => false]);

    expect(lcoRowFor($this->governor, $patient))->not->toBeNull();
});

/* ------------------------------------------------------------- RME lifecycle */

it('reports every live RME staging status as in progress, never as missing', function (string $status): void {
    $patient = lcoLegacyPatient($this->branch);
    LegacyRmeImport::factory()->create([
        'patient_id' => $patient->id,
        'origin_branch_id' => $this->branch->id,
        'status' => $status,
    ]);

    $row = lcoRowFor($this->governor, $patient);

    expect($row)->not->toBeNull()
        ->and($row->rmeState)->toBe(LegacyArchiveDocumentState::IN_PROGRESS)
        ->and($row->rmeRawStatus)->toBe($status)
        ->and($row->completeness)->toBe(LegacyArchiveCompleteness::IN_PROGRESS);
})->with([
    LegacyRmeImportStatus::DRAFT,
    LegacyRmeImportStatus::UPLOADED,
    LegacyRmeImportStatus::QUEUED,
    LegacyRmeImportStatus::PROCESSING,
    LegacyRmeImportStatus::READY_FOR_REVIEW,
    LegacyRmeImportStatus::REVIEWED,
    // FAILED transitions to QUEUED: retryable, still owns the slot.
    LegacyRmeImportStatus::FAILED,
]);

it('reports a cancelled RME import as missing', function (): void {
    $patient = lcoLegacyPatient($this->branch);
    LegacyRmeImport::factory()->create([
        'patient_id' => $patient->id,
        'origin_branch_id' => $this->branch->id,
        'status' => LegacyRmeImportStatus::CANCELLED,
    ]);

    $row = lcoRowFor($this->governor, $patient);

    expect($row->rmeState)->toBe(LegacyArchiveDocumentState::MISSING);
});

it('reports the RME document as published even when its staging row is historical', function (): void {
    $patient = lcoLegacyPatient($this->branch);
    $import = LegacyRmeImport::factory()->create([
        'patient_id' => $patient->id,
        'origin_branch_id' => $this->branch->id,
        'status' => LegacyRmeImportStatus::PUBLISHED,
    ]);
    LegacyRmeRecord::factory()->create([
        'patient_id' => $patient->id,
        'origin_branch_id' => $this->branch->id,
        'source_import_id' => $import->id,
    ]);

    // The patient is still listed by DEFAULT, because the odontogram is absent
    // and the verdict is per PATIENT while the state is per DOCUMENT. Those two
    // are deliberately different questions.
    $row = lcoRowFor($this->governor, $patient);

    expect($row)->not->toBeNull()
        ->and($row->rmeState)->toBe(LegacyArchiveDocumentState::PUBLISHED)
        ->and($row->completeness)->toBe(LegacyArchiveCompleteness::INCOMPLETE);
});

it('reports a voided RME record with no replacement as void, not as complete', function (): void {
    $patient = lcoLegacyPatient($this->branch);
    LegacyRmeImport::factory()->create([
        'patient_id' => $patient->id,
        'origin_branch_id' => $this->branch->id,
        'status' => LegacyRmeImportStatus::PUBLISHED,
    ]);
    LegacyRmeRecord::factory()->voided()->create([
        'patient_id' => $patient->id,
        'origin_branch_id' => $this->branch->id,
    ]);

    $row = lcoRowFor($this->governor, $patient);

    expect($row)->not->toBeNull()
        ->and($row->rmeState)->toBe(LegacyArchiveDocumentState::VOID)
        ->and($row->completeness)->toBe(LegacyArchiveCompleteness::INCOMPLETE);
});

it('reports a void record superseded by a newer published one as published', function (): void {
    // The documented correction path: VOID with a reason, then a fresh import.
    // Once the replacement publishes, the RME document is whole again — the
    // stale void must not keep the archive looking broken forever.
    $patient = lcoLegacyPatient($this->branch);
    LegacyRmeRecord::factory()->voided()->create(['patient_id' => $patient->id, 'origin_branch_id' => $this->branch->id]);
    LegacyRmeRecord::factory()->create(['patient_id' => $patient->id, 'origin_branch_id' => $this->branch->id]);

    $row = lcoRowFor($this->governor, $patient);

    expect($row)->not->toBeNull()
        ->and($row->rmeState)->toBe(LegacyArchiveDocumentState::PUBLISHED);
});

it('reports the patient as complete once BOTH documents survive a void and republish', function (): void {
    $patient = lcoLegacyPatient($this->branch);
    LegacyRmeRecord::factory()->voided()->create(['patient_id' => $patient->id, 'origin_branch_id' => $this->branch->id]);
    LegacyRmeRecord::factory()->create(['patient_id' => $patient->id, 'origin_branch_id' => $this->branch->id]);
    LegacyOdontogramRecord::factory()->create([
        'patient_id' => $patient->id,
        'branch_id' => $this->branch->id,
        'status' => LegacyOdontogramRecordStatus::VOID,
    ]);
    LegacyOdontogramRecord::factory()->create(['patient_id' => $patient->id, 'branch_id' => $this->branch->id]);

    expect(lcoRowFor($this->governor, $patient))->toBeNull();

    $row = lcoRowFor($this->governor, $patient, LegacyCompletenessFilter::COMPLETE);

    expect($row)->not->toBeNull()
        ->and($row->completeness)->toBe(LegacyArchiveCompleteness::COMPLETE);
});

it('reports a correction import in flight over a voided record as in progress', function (): void {
    $patient = lcoLegacyPatient($this->branch);
    LegacyRmeRecord::factory()->voided()->create(['patient_id' => $patient->id, 'origin_branch_id' => $this->branch->id]);
    LegacyRmeImport::factory()->create([
        'patient_id' => $patient->id,
        'origin_branch_id' => $this->branch->id,
        'status' => LegacyRmeImportStatus::PROCESSING,
    ]);

    $row = lcoRowFor($this->governor, $patient);

    // IN_PROGRESS outranks VOID: the slot is taken, so the next action is to
    // finish the running import, not to start another one.
    expect($row->rmeState)->toBe(LegacyArchiveDocumentState::IN_PROGRESS);
});

it('lets a published archive outrank an anomalous in-flight import', function (): void {
    // THE RECORD IS CONSULTED BEFORE THE STAGING TABLE, and this is the state
    // that makes the order observable. A published record alongside a live
    // staging row should not happen — the occupancy guard refuses a new import
    // while one is published — but a staging row outliving its record is
    // exactly the shape the record-first order exists to handle, and the
    // archive is the truth about whether the patient HAS a document.
    //
    // Reporting IN_PROGRESS here would tell an operator to wait for a document
    // they already hold.
    $patient = lcoLegacyPatient($this->branch);
    LegacyRmeRecord::factory()->create(['patient_id' => $patient->id, 'origin_branch_id' => $this->branch->id]);
    LegacyRmeImport::factory()->create([
        'patient_id' => $patient->id,
        'origin_branch_id' => $this->branch->id,
        'status' => LegacyRmeImportStatus::PROCESSING,
    ]);

    $row = lcoRowFor($this->governor, $patient);

    expect($row)->not->toBeNull()
        ->and($row->rmeState)->toBe(LegacyArchiveDocumentState::PUBLISHED);
});

it('counts a soft-deleted staging row as still occupying the slot', function (): void {
    // A soft delete NEVER releases a patient's document slot — only an audited
    // CANCEL or VOID does. If this page filtered trashed rows out it would show
    // "Belum Ada" for a slot the server still refuses to re-fill.
    $patient = lcoLegacyPatient($this->branch);
    $import = LegacyRmeImport::factory()->create([
        'patient_id' => $patient->id,
        'origin_branch_id' => $this->branch->id,
        'status' => LegacyRmeImportStatus::PROCESSING,
    ]);
    $import->delete();

    $row = lcoRowFor($this->governor, $patient);

    expect($row->rmeState)->toBe(LegacyArchiveDocumentState::IN_PROGRESS);
});

/* ------------------------------------------------------ odontogram lifecycle */

it('reports every live odontogram staging status as in progress', function (string $status): void {
    $patient = lcoLegacyPatient($this->branch);
    LegacyOdontogramImport::factory()->create([
        'patient_id' => $patient->id,
        'origin_branch_id' => $this->branch->id,
        'status' => $status,
    ]);

    $row = lcoRowFor($this->governor, $patient);

    expect($row->odontogramState)->toBe(LegacyArchiveDocumentState::IN_PROGRESS)
        ->and($row->odontogramRawStatus)->toBe($status);
})->with([
    LegacyOdontogramImportStatus::DRAFT,
    LegacyOdontogramImportStatus::QUEUED,
    LegacyOdontogramImportStatus::PROCESSING,
    LegacyOdontogramImportStatus::READY_FOR_REVIEW,
    LegacyOdontogramImportStatus::REVIEWED,
    LegacyOdontogramImportStatus::FAILED,
]);

it('reports a voided odontogram record with no replacement as void', function (): void {
    $patient = lcoLegacyPatient($this->branch);
    // The odontogram record factory has no void state, so the status comes from
    // that module's OWN record vocabulary rather than borrowing the RME one.
    LegacyOdontogramRecord::factory()->create([
        'patient_id' => $patient->id,
        'branch_id' => $this->branch->id,
        'status' => LegacyOdontogramRecordStatus::VOID,
    ]);

    expect(lcoRowFor($this->governor, $patient)->odontogramState)
        ->toBe(LegacyArchiveDocumentState::VOID);
});

it('keeps the two document slots independent', function (): void {
    // A published RME never substitutes for a missing odontogram. There is no
    // patient-wide "has any legacy document" shortcut anywhere, and this is the
    // assertion that keeps it that way.
    $patient = lcoLegacyPatient($this->branch);
    LegacyRmeRecord::factory()->create(['patient_id' => $patient->id, 'origin_branch_id' => $this->branch->id]);
    LegacyOdontogramImport::factory()->create([
        'patient_id' => $patient->id,
        'origin_branch_id' => $this->branch->id,
        'status' => LegacyOdontogramImportStatus::READY_FOR_REVIEW,
    ]);

    $row = lcoRowFor($this->governor, $patient);

    expect($row->rmeState)->toBe(LegacyArchiveDocumentState::PUBLISHED)
        ->and($row->odontogramState)->toBe(LegacyArchiveDocumentState::IN_PROGRESS)
        ->and($row->completeness)->toBe(LegacyArchiveCompleteness::IN_PROGRESS);
});

/* ------------------------------------- agreement with the real occupancy guard */

it('agrees with the real single-active-document guard on every lifecycle shape', function (
    ?string $importStatus,
    bool $publishedRecord,
    bool $voidRecord,
): void {
    $patient = lcoLegacyPatient($this->branch);

    if ($importStatus !== null) {
        LegacyRmeImport::factory()->create([
            'patient_id' => $patient->id,
            'origin_branch_id' => $this->branch->id,
            'status' => $importStatus,
        ]);
    }

    if ($voidRecord) {
        LegacyRmeRecord::factory()->voided()->create(['patient_id' => $patient->id, 'origin_branch_id' => $this->branch->id]);
    }

    if ($publishedRecord) {
        LegacyRmeRecord::factory()->create(['patient_id' => $patient->id, 'origin_branch_id' => $this->branch->id]);
    }

    // The REAL guard, not a restatement of its rules.
    $occupancy = app(LegacySingleActiveDocumentService::class)
        ->occupancyFor(LegacyImportType::LEGACY_RME, $patient->id);

    $row = lcoRowFor($this->governor, $patient, LegacyCompletenessFilter::COMPLETE)
        ?? lcoRowFor($this->governor, $patient);

    expect($row)->not->toBeNull();

    // "The page says an upload is the next step" must be exactly "the guard
    // would accept one". If these ever diverge, the page is inviting a refused
    // upload or hiding an available one.
    expect(LegacyArchiveDocumentState::needsUpload($row->rmeState))->toBe(! $occupancy->occupied);
})->with([
    'nothing at all' => [null, false, false],
    'draft import' => [LegacyRmeImportStatus::DRAFT, false, false],
    'processing import' => [LegacyRmeImportStatus::PROCESSING, false, false],
    'ready for review' => [LegacyRmeImportStatus::READY_FOR_REVIEW, false, false],
    'reviewed' => [LegacyRmeImportStatus::REVIEWED, false, false],
    'failed import' => [LegacyRmeImportStatus::FAILED, false, false],
    'cancelled import' => [LegacyRmeImportStatus::CANCELLED, false, false],
    'published record' => [LegacyRmeImportStatus::PUBLISHED, true, false],
    'void record only' => [LegacyRmeImportStatus::PUBLISHED, false, true],
    'void then republished' => [LegacyRmeImportStatus::PUBLISHED, true, true],
    'void with correction in flight' => [LegacyRmeImportStatus::PROCESSING, false, true],
]);

/* ------------------------------------------------------------------- filters */

it('filters to patients that need an RME upload', function (): void {
    $needsRme = lcoLegacyPatient($this->branch);
    $hasRme = lcoLegacyPatient($this->branch);
    LegacyRmeRecord::factory()->create(['patient_id' => $hasRme->id, 'origin_branch_id' => $this->branch->id]);
    $rmeInFlight = lcoLegacyPatient($this->branch);
    LegacyRmeImport::factory()->create([
        'patient_id' => $rmeInFlight->id,
        'origin_branch_id' => $this->branch->id,
        'status' => LegacyRmeImportStatus::PROCESSING,
    ]);

    $ids = array_map(
        static fn (object $row): int => $row->patientId,
        lcoRows($this->governor, LegacyCompletenessFilter::MISSING_RME),
    );

    expect($ids)->toContain($needsRme->id)
        ->and($ids)->not->toContain($hasRme->id)
        // An in-flight import does NOT need an upload — including it here is
        // what would invite the refused duplicate.
        ->and($ids)->not->toContain($rmeInFlight->id);
});

it('includes a voided record under the needs-an-upload filter', function (): void {
    $patient = lcoLegacyPatient($this->branch);
    LegacyRmeRecord::factory()->voided()->create(['patient_id' => $patient->id, 'origin_branch_id' => $this->branch->id]);

    $ids = array_map(
        static fn (object $row): int => $row->patientId,
        lcoRows($this->governor, LegacyCompletenessFilter::MISSING_RME),
    );

    // The slot is free, so an upload IS the next step; the status column is
    // what tells the operator it is a correction rather than a first filing.
    expect($ids)->toContain($patient->id);
});

it('filters to patients missing both documents', function (): void {
    $both = lcoLegacyPatient($this->branch);
    $onlyOdontogram = lcoLegacyPatient($this->branch);
    LegacyOdontogramRecord::factory()->create(['patient_id' => $onlyOdontogram->id, 'branch_id' => $this->branch->id]);

    $ids = array_map(
        static fn (object $row): int => $row->patientId,
        lcoRows($this->governor, LegacyCompletenessFilter::MISSING_BOTH),
    );

    expect($ids)->toContain($both->id)->and($ids)->not->toContain($onlyOdontogram->id);
});

it('filters to patients with a live lifecycle', function (): void {
    $inFlight = lcoLegacyPatient($this->branch);
    LegacyOdontogramImport::factory()->create([
        'patient_id' => $inFlight->id,
        'origin_branch_id' => $this->branch->id,
        'status' => LegacyOdontogramImportStatus::READY_FOR_REVIEW,
    ]);
    $idle = lcoLegacyPatient($this->branch);

    $ids = array_map(
        static fn (object $row): int => $row->patientId,
        lcoRows($this->governor, LegacyCompletenessFilter::IN_PROGRESS),
    );

    expect($ids)->toContain($inFlight->id)->and($ids)->not->toContain($idle->id);
});

it('keeps in-progress patients inside the default incomplete view', function (): void {
    $inFlight = lcoLegacyPatient($this->branch);
    LegacyRmeImport::factory()->create([
        'patient_id' => $inFlight->id,
        'origin_branch_id' => $this->branch->id,
        'status' => LegacyRmeImportStatus::PROCESSING,
    ]);

    // In-flight work is not finished work: it belongs in "Semua Belum Lengkap".
    expect(lcoRowFor($this->governor, $inFlight))->not->toBeNull();
});

it('falls back to the default filter for an unrecognised value', function (): void {
    $patient = lcoLegacyPatient($this->branch);

    $query = $this->service->resolveQuery($this->governor, 'not-a-filter');

    expect($query->filter)->toBe(LegacyCompletenessFilter::DEFAULT)
        ->and(lcoRowFor($this->governor, $patient))->not->toBeNull();
});

/* -------------------------------------------------------------------- search */

it('searches by nomor RM and by name', function (): void {
    $target = lcoLegacyPatient($this->branch, [
        'medical_record_number' => 'DG-TLK1-2024-7788',
        'name' => 'Siti Aminah',
    ]);
    $other = lcoLegacyPatient($this->branch, [
        'medical_record_number' => 'DG-TLK1-2024-9999',
        'name' => 'Budi Santoso',
    ]);

    $byRm = array_map(static fn (object $r): int => $r->patientId, lcoRows($this->governor, null, '7788'));
    $byName = array_map(static fn (object $r): int => $r->patientId, lcoRows($this->governor, null, 'aminah'));

    expect($byRm)->toContain($target->id)->and($byRm)->not->toContain($other->id)
        ->and($byName)->toContain($target->id)->and($byName)->not->toContain($other->id);
});

it('treats LIKE metacharacters as literal text in search', function (): void {
    $withPercent = lcoLegacyPatient($this->branch, ['name' => 'Budi 100% Sehat']);
    $withUnderscore = lcoLegacyPatient($this->branch, ['name' => 'Ani_Wijaya']);
    $plain = lcoLegacyPatient($this->branch, ['name' => 'Tidak Relevan']);

    $percent = array_map(static fn (object $r): int => $r->patientId, lcoRows($this->governor, null, '%'));
    $underscore = array_map(static fn (object $r): int => $r->patientId, lcoRows($this->governor, null, '_'));

    // `%` matches the name that actually contains a percent sign, and NOT the
    // one that does not — an unescaped wildcard would have matched all three.
    expect($percent)->toContain($withPercent->id)
        ->and($percent)->not->toContain($plain->id)
        ->and($percent)->not->toContain($withUnderscore->id);

    // Same for `_`, which as a wildcard matches any single character and would
    // otherwise have matched every name here.
    expect($underscore)->toContain($withUnderscore->id)
        ->and($underscore)->not->toContain($plain->id)
        ->and($underscore)->not->toContain($withPercent->id);
});

it('runs search without a database error on the configured driver', function (): void {
    // Regression guard for the PDO placeholder defect: a backslash inside the
    // single-quoted ESCAPE literal makes pdo_pgsql miscount placeholders and
    // every search returns SQLSTATE[HY093]. The escape character is `!`, so
    // this must simply work — on whichever driver the suite is running.
    lcoLegacyPatient($this->branch, ['name' => 'Pencarian Aman']);

    expect(lcoRows($this->governor, null, "a_b%c!d'e"))->toBeArray();
});

/* ------------------------------------------------------------------ counters */

it('counts the actor scope rather than the current page', function (): void {
    $missingBoth = lcoLegacyPatient($this->branch);

    $hasRme = lcoLegacyPatient($this->branch);
    LegacyRmeRecord::factory()->create(['patient_id' => $hasRme->id, 'origin_branch_id' => $this->branch->id]);

    $complete = lcoLegacyPatient($this->branch);
    LegacyRmeRecord::factory()->create(['patient_id' => $complete->id, 'origin_branch_id' => $this->branch->id]);
    LegacyOdontogramRecord::factory()->create(['patient_id' => $complete->id, 'branch_id' => $this->branch->id]);

    $inFlight = lcoLegacyPatient($this->branch);
    LegacyRmeImport::factory()->create([
        'patient_id' => $inFlight->id,
        'origin_branch_id' => $this->branch->id,
        'status' => LegacyRmeImportStatus::QUEUED,
    ]);

    $query = $this->service->resolveQuery($this->governor);
    $summary = $this->service->summary($query);

    expect($summary['total'])->toBe(4)
        ->and($summary['complete'])->toBe(1)
        ->and($summary['incomplete'])->toBe(3)
        // missing RME: missingBoth + inFlight? No — inFlight is NOT missing.
        ->and($summary['missing_rme'])->toBe(1)
        ->and($summary['missing_odontogram'])->toBe(3)
        ->and($summary['missing_both'])->toBe(1)
        ->and($summary['in_progress'])->toBe(1);
});

it('never counts a native patient in the summary', function (): void {
    lcoLegacyPatient($this->branch);
    lcoNativePatient($this->branch);
    lcoNativePatient($this->branch);

    $summary = $this->service->summary($this->service->resolveQuery($this->governor));

    expect($summary['total'])->toBe(1);
});

/* ----------------------------------------------------- vocabulary invariants */

it('derives slot-occupying states as exactly the non-terminal ones', function (): void {
    // The page reads each module's OWN SLOT_OCCUPYING list. This pins that the
    // list is still ALL minus TERMINAL in both modules, so a future status
    // added to one machine cannot silently stop occupying a slot here.
    expect(array_values(array_diff(LegacyRmeImportStatus::ALL, LegacyRmeImportStatus::TERMINAL)))
        ->toBe(array_values(LegacyRmeImportStatus::SLOT_OCCUPYING))
        ->and(array_values(array_diff(LegacyOdontogramImportStatus::ALL, LegacyOdontogramImportStatus::TERMINAL)))
        ->toBe(array_values(LegacyOdontogramImportStatus::SLOT_OCCUPYING));
});

it('keeps the two staging vocabularies in agreement so one label map is honest', function (): void {
    // LegacyArchiveStatusLabel maps a single staging vocabulary onto both
    // archives. That is only truthful while the two machines agree; if either
    // gains a status the other lacks, this fails and the map must be split
    // rather than silently falling back to a generic label for one archive.
    expect(LegacyOdontogramImportStatus::ALL)->toBe(LegacyRmeImportStatus::ALL);
});

it('exposes no write method on the completeness read boundary', function (): void {
    $methods = array_map(
        static fn (ReflectionMethod $m): string => $m->getName(),
        (new ReflectionClass(LegacyPatientArchiveCompletenessRepositoryInterface::class))->getMethods(),
    );

    foreach (['create', 'update', 'delete', 'destroy', 'save', 'upsert', 'insert', 'transition', 'publish', 'void', 'cancel'] as $forbidden) {
        expect($methods)->not->toContain($forbidden);
    }
});
