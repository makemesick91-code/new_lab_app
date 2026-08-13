<?php

/**
 * LEGACY-RME-PDF-HISTORY-1 — patient-centric clinical history integration.
 *
 * A doctor opening the RME workspace from a new visit must see the patient's
 * WHOLE clinical story: the current RME, the earlier native RME, and the
 * PUBLISHED legacy archive, on one timeline.
 *
 * The two sources are merged for READING only. A legacy record never becomes a
 * ClinicVisit or a native MedicalRecord, it produces no invoice, payment, lab
 * or SATUSEHAT row, and it never widens a doctor's clinical visibility beyond
 * their native access.
 */

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\ClinicVisit\Services\ClinicVisitService;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\LegacyRme\Models\LegacyRmeImport;
use App\Modules\LegacyRme\Models\LegacyRmeRecord;
use App\Modules\LegacyRme\Services\LegacyRmePatientHistoryService;
use App\Modules\LegacyRme\Support\LegacyRmeImportStatus;
use App\Modules\LegacyRme\Support\LegacyRmeTimelineEntry;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\Patient\Models\Patient;
use App\Modules\RME\Models\PatientDoctorAssignment;
use App\Modules\RmeOnlineContext\Middleware\EnsureRmeOnlineContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses()->group('LegacyRme');

beforeEach(function () {
    seedAccessControl();
    legacyRmeArchiveFlag(true);

    // MAIN is never an RME clinic branch, and leaving it RME-enabled would let
    // an unpinned BranchContext fall back into scope and hide a real failure.
    Branch::where('code', Branch::MAIN_CODE)->update(['is_rme_enabled' => false]);
});

/*
|--------------------------------------------------------------------------
| Fixtures
|--------------------------------------------------------------------------
*/

function lrmeh1Patient(string $branchCode = 'TKM1'): Patient
{
    return legacyRmeArchivablePatient(['date_of_birth' => '1980-01-01'], $branchCode);
}

/** A native visit pinned to the patient's own RME branch, with a room. */
function lrmeh1Visit(Patient $patient, string $date, ?Doctor $doctor = null): ClinicVisit
{
    return ClinicVisit::factory()->create([
        'branch_id' => $patient->branch_id,
        'patient_id' => $patient->getKey(),
        'visit_date' => $date,
    ] + ($doctor !== null ? ['doctor_id' => $doctor->getKey()] : []));
}

function lrmeh1Sheet(ClinicVisit $visit): MedicalRecord
{
    return MedicalRecord::factory()->create([
        'clinic_visit_id' => $visit->getKey(),
        'branch_id' => $visit->branch_id,
        'patient_id' => $visit->patient_id,
        'doctor_id' => $visit->doctor_id,
    ]);
}

/**
 * A published legacy archive owned by the patient's branch, so branch scope is
 * actually exercised rather than accidentally satisfied by an unscoped row.
 */
function lrmeh1Published(Patient $patient, string $date, array $overrides = []): LegacyRmeRecord
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

/** An operator who works the RME workspace and may read the archive. */
function lrmeh1WorkspaceUser(Patient $patient): User
{
    $user = userWith(['manage_clinic_visits', 'view_clinic_visits', 'view_legacy_rme_archive']);
    $user->forceFill(['branch_id' => $patient->branch_id])->save();

    return $user->refresh();
}

/** A Doctor-role user, optionally with a real clinical relationship. */
function lrmeh1Doctor(Patient $patient, bool $treating): User
{
    $user = User::factory()->create(['branch_id' => $patient->branch_id]);
    $user->assignRole('Doctor');

    // HISTORY-1B — the doctor master states the practice branch; the doctor's
    // legacy branch scope now comes from that assignment, not from the user row.
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
function lrmeh1History(?User $user, Patient $patient, ?int $currentVisitId = null)
{
    return app(LegacyRmePatientHistoryService::class)->patientClinicalHistoryFor(
        $user,
        (int) $patient->getKey(),
        app(ClinicVisitService::class)
            ->patientVisitHistory((int) $patient->getKey()),
        $currentVisitId,
    );
}

/** Every downstream clinical/financial table a history read must never touch. */
function lrmeh1DownstreamCounts(): array
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
    ] as $table) {
        if (Schema::hasTable($table)) {
            $counts[$table] = DB::table($table)->count();
        }
    }

    return $counts;
}

/*
|--------------------------------------------------------------------------
| A — native only
|--------------------------------------------------------------------------
*/

it('shows the native history for a patient with no legacy archive', function () {
    $patient = lrmeh1Patient();
    lrmeh1Visit($patient, '2024-02-01');
    lrmeh1Visit($patient, '2024-06-01');

    $history = lrmeh1History(superAdmin(), $patient);

    expect($history)->toHaveCount(2)
        ->and($history->every(fn (LegacyRmeTimelineEntry $e) => ! $e->isLegacy()))->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| B — legacy only (no native RME at all)
|--------------------------------------------------------------------------
*/

it('shows the legacy archive for a patient who has no native RME', function () {
    $patient = lrmeh1Patient();
    lrmeh1Published($patient, '2019-04-17');

    $history = lrmeh1History(superAdmin(), $patient);

    expect($history)->toHaveCount(1)
        ->and($history->first()->isLegacy())->toBeTrue()
        ->and($history->first()->date->format('Y-m-d'))->toBe('2019-04-17');
});

/*
|--------------------------------------------------------------------------
| C + D + P — unified, deterministic, newest first
|--------------------------------------------------------------------------
*/

it('merges native and legacy into one newest-first history', function () {
    $patient = lrmeh1Patient();

    lrmeh1Published($patient, '2018-03-02');
    lrmeh1Published($patient, '2019-07-21');
    lrmeh1Visit($patient, '2024-02-01');
    lrmeh1Visit($patient, '2024-06-01');

    $history = lrmeh1History(superAdmin(), $patient);

    expect($history->map(fn (LegacyRmeTimelineEntry $e) => $e->kind.' '.$e->date->format('Y-m-d'))->all())
        ->toBe([
            'NATIVE 2024-06-01',
            'NATIVE 2024-02-01',
            'LEGACY 2019-07-21',
            'LEGACY 2018-03-02',
        ]);
});

it('orders a same-day legacy/native collision deterministically', function () {
    $patient = lrmeh1Patient();

    lrmeh1Published($patient, '2024-02-01');
    lrmeh1Visit($patient, '2024-02-01');

    $first = lrmeh1History(superAdmin(), $patient)->map(fn ($e) => $e->kind)->all();
    $second = lrmeh1History(superAdmin(), $patient)->map(fn ($e) => $e->kind)->all();

    // Native sorts above legacy on an equal date, and repeated reads agree —
    // the order never falls back to the database's row order.
    expect($first)->toBe(['NATIVE', 'LEGACY'])->and($second)->toBe($first);
});

/*
|--------------------------------------------------------------------------
| E — multi-date archive
|--------------------------------------------------------------------------
*/

it('renders a multi-date archive as an earliest-to-latest range', function () {
    $patient = lrmeh1Patient();
    lrmeh1Published($patient, '2024-01-28', ['latest_rme_date' => '2024-08-31']);

    $entry = lrmeh1History(superAdmin(), $patient)->first();

    expect($entry->hasDateRange())->toBeTrue()
        ->and($entry->date->format('Y-m-d'))->toBe('2024-01-28')
        ->and($entry->endDate->format('Y-m-d'))->toBe('2024-08-31')
        ->and($entry->dateLabel())->toBe('28/01/2024 – 31/08/2024');
});

it('never renders a single-date or inverted archive as a range', function () {
    $patient = lrmeh1Patient();

    $same = lrmeh1Published($patient, '2024-01-28', ['latest_rme_date' => '2024-01-28']);
    $inverted = lrmeh1Published($patient, '2024-03-01', ['latest_rme_date' => '2023-01-01']);

    $history = lrmeh1History(superAdmin(), $patient)->keyBy(fn ($e) => $e->sourceId);

    expect($history[$same->getKey()]->hasDateRange())->toBeFalse()
        ->and($history[$same->getKey()]->dateLabel())->toBe('28/01/2024')
        ->and($history[$inverted->getKey()]->hasDateRange())->toBeFalse()
        ->and($history[$inverted->getKey()]->dateLabel())->toBe('01/03/2024');
});

/*
|--------------------------------------------------------------------------
| F — the current encounter is marked
|--------------------------------------------------------------------------
*/

it('marks the current visit and marks nothing else', function () {
    $patient = lrmeh1Patient();
    $older = lrmeh1Visit($patient, '2024-02-01');
    $current = lrmeh1Visit($patient, '2024-06-01');
    lrmeh1Published($patient, '2019-01-01');

    $history = lrmeh1History(superAdmin(), $patient, (int) $current->getKey());

    expect($history->filter(fn ($e) => $e->isCurrent)->count())->toBe(1)
        ->and($history->first(fn ($e) => $e->isCurrent)->sourceId)->toBe((int) $current->getKey())
        ->and($history->first(fn ($e) => $e->sourceId === (int) $older->getKey() && ! $e->isLegacy())->isCurrent)
        ->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| G + N — only PUBLISHED is clinical history; VOID is retracted, not erased
|--------------------------------------------------------------------------
*/

it('keeps every non-published legacy state out of the doctor history', function () {
    $patient = lrmeh1Patient();
    lrmeh1Published($patient, '2019-01-01');

    foreach ([
        LegacyRmeImportStatus::UPLOADED,
        LegacyRmeImportStatus::PROCESSING,
        LegacyRmeImportStatus::READY_FOR_REVIEW,
        LegacyRmeImportStatus::REVIEWED,
        LegacyRmeImportStatus::FAILED,
        LegacyRmeImportStatus::CANCELLED,
    ] as $status) {
        LegacyRmeImport::factory()->create([
            'patient_id' => $patient->getKey(),
            'status' => $status,
        ]);
    }

    expect(lrmeh1History(superAdmin(), $patient)->where('kind', LegacyRmeTimelineEntry::KIND_LEGACY))
        ->toHaveCount(1);
});

it('drops a voided archive from the history while preserving the evidence', function () {
    $patient = lrmeh1Patient();
    $voided = lrmeh1Published($patient, '2019-01-01', [
        'status' => LegacyRmeRecord::STATUS_VOID,
        'void_reason' => 'Dokumen milik pasien lain.',
        'voided_at' => now(),
    ]);

    expect(lrmeh1History(superAdmin(), $patient))->toBeEmpty();

    // Retracted, never erased: the row and its reason stay auditable.
    $stored = LegacyRmeRecord::query()->find($voided->getKey());
    expect($stored)->not->toBeNull()
        ->and($stored->isVoided())->toBeTrue()
        ->and($stored->void_reason)->toBe('Dokumen milik pasien lain.');
});

/*
|--------------------------------------------------------------------------
| O — patient isolation
|--------------------------------------------------------------------------
*/

it('never leaks one patient archive into another patient history', function () {
    $patientA = lrmeh1Patient();
    $patientB = lrmeh1Patient();

    lrmeh1Published($patientA, '2019-01-01');

    expect(lrmeh1History(superAdmin(), $patientB))->toBeEmpty()
        ->and(lrmeh1History(superAdmin(), $patientA))->toHaveCount(1);
});

/*
|--------------------------------------------------------------------------
| H + I + J — DoctorPatientScope, not branch membership
|--------------------------------------------------------------------------
*/

it('shows the archive to a doctor who actually treats the patient', function () {
    $patient = lrmeh1Patient();
    lrmeh1Published($patient, '2019-01-01');

    expect(lrmeh1History(lrmeh1Doctor($patient, treating: true), $patient))->toHaveCount(1);
});

it('hides the archive from a same-branch doctor with no clinical relationship', function () {
    $patient = lrmeh1Patient();
    lrmeh1Published($patient, '2019-01-01');

    $stranger = lrmeh1Doctor($patient, treating: false);

    // Same branch, real archive permission — and still nothing, because a
    // legacy archive must never be a wider door than the native record.
    expect($stranger->can('view_legacy_rme_archive'))->toBeTrue()
        ->and((int) $stranger->branch_id)->toBe((int) $patient->branch_id)
        ->and(lrmeh1History($stranger, $patient))->toBeEmpty();
});

it('hides the archive from a doctor in another branch', function () {
    $patient = lrmeh1Patient('TKM1');
    lrmeh1Published($patient, '2019-01-01');

    $otherBranchPatient = lrmeh1Patient('LDK2');

    expect(lrmeh1History(lrmeh1Doctor($otherBranchPatient, treating: false), $patient))->toBeEmpty();
});

/*
|--------------------------------------------------------------------------
| K — the direct viewer is never a weaker door than the history
|--------------------------------------------------------------------------
*/

it('refuses a direct archive URL to a same-branch doctor who does not treat the patient', function () {
    $patient = lrmeh1Patient();
    $record = lrmeh1Published($patient, '2019-01-01');

    $response = $this->actingAs(lrmeh1Doctor($patient, treating: false))
        ->withoutMiddleware(EnsureRmeOnlineContext::class)
        ->get(route('rme.legacy-records.show', $record->getKey()));

    expect($response->status())->toBe(403);
    $response->assertDontSee($patient->name);
});

it('refuses a direct archive URL to a doctor from another branch', function () {
    $patient = lrmeh1Patient('TKM1');
    $record = lrmeh1Published($patient, '2019-01-01');
    $otherBranchPatient = lrmeh1Patient('LDK2');

    $this->actingAs(lrmeh1Doctor($otherBranchPatient, treating: false))
        ->withoutMiddleware(EnsureRmeOnlineContext::class)
        ->get(route('rme.legacy-records.show', $record->getKey()))
        ->assertNotFound();
});

it('refuses a direct archive URL to a user with no archive permission at all', function () {
    $patient = lrmeh1Patient();
    $record = lrmeh1Published($patient, '2019-01-01');

    $this->actingAs(userWith(['view_clinic_visits']))
        ->get(route('rme.legacy-records.show', $record->getKey()))
        ->assertForbidden();
});

/*
|--------------------------------------------------------------------------
| The workspace itself — the primary product requirement
|--------------------------------------------------------------------------
*/

it('shows the unified history inside the patient RME workspace', function () {
    $patient = lrmeh1Patient();
    $visit = lrmeh1Visit($patient, '2024-06-01');
    lrmeh1Sheet($visit);
    lrmeh1Published($patient, '2019-04-17');

    $this->actingAs(lrmeh1WorkspaceUser($patient))
        ->get(route('rme.visits.medical-record.show', $visit))
        ->assertOk()
        ->assertSee('Riwayat RME Pasien')
        ->assertSee('ARSIP RME LAMA')
        ->assertSee('RME SISTEM')
        ->assertSee('Kunjungan Saat Ini')
        ->assertSee('17/04/2019');
});

it('shows the archive in the workspace empty state when the patient has no RM sheet', function () {
    $patient = lrmeh1Patient();
    $visit = lrmeh1Visit($patient, '2024-06-01');
    lrmeh1Published($patient, '2019-04-17');

    $this->actingAs(lrmeh1WorkspaceUser($patient))
        ->get(route('rme.visits.medical-record.show', $visit))
        ->assertOk()
        ->assertSee('Riwayat RME Pasien')
        ->assertSee('ARSIP RME LAMA');
});

it('renders the workspace history read-only, with no conversion action', function () {
    $patient = lrmeh1Patient();
    $visit = lrmeh1Visit($patient, '2024-06-01');
    lrmeh1Sheet($visit);
    lrmeh1Published($patient, '2019-04-17');

    $response = $this->actingAs(lrmeh1WorkspaceUser($patient))
        ->get(route('rme.visits.medical-record.show', $visit))
        ->assertOk();

    foreach ([
        route('rme.legacy-records.void', 1),
        'Konversi',
        'Jadikan RME',
        'Hapus Arsip',
    ] as $forbidden) {
        $response->assertDontSee($forbidden, false);
    }
});

/*
|--------------------------------------------------------------------------
| M — reading history creates nothing
|--------------------------------------------------------------------------
*/

it('creates no visit, record, invoice, payment, lab or SATUSEHAT row when history is read', function () {
    $patient = lrmeh1Patient();
    $visit = lrmeh1Visit($patient, '2024-06-01');
    lrmeh1Sheet($visit);
    lrmeh1Published($patient, '2019-04-17');
    $user = lrmeh1WorkspaceUser($patient);

    $before = lrmeh1DownstreamCounts();

    $this->actingAs($user)
        ->get(route('rme.visits.medical-record.show', $visit))
        ->assertOk();

    $this->actingAs($user)
        ->get(route('rme.visits.show', $visit))
        ->assertOk();

    lrmeh1History($user, $patient, (int) $visit->getKey());

    expect(lrmeh1DownstreamCounts())->toBe($before);
});

/*
|--------------------------------------------------------------------------
| L — capability state vs already-published evidence
|--------------------------------------------------------------------------
*/

// LEGACY-RME-PDF-HISTORY-1A — this test previously asserted that switching the
// capability off HID the archive everywhere. That was the defect this sprint
// corrects, and it is now the acceptance criterion in the opposite direction:
// the flag governs MIGRATION, and already-published evidence is the patient's
// real medical history, so the doctor treating them keeps reading it at the
// next visit without ingestion being re-opened.
it('keeps the archive readable while the legacy migration capability is off', function () {
    $patient = lrmeh1Patient();
    $visit = lrmeh1Visit($patient, '2024-06-01');
    lrmeh1Sheet($visit);
    $record = lrmeh1Published($patient, '2019-04-17');
    $user = lrmeh1WorkspaceUser($patient);

    legacyRmeArchiveFlag(false);

    // The workspace still shows the unified history, legacy row included.
    $this->actingAs($user)
        ->get(route('rme.visits.medical-record.show', $visit))
        ->assertOk()
        ->assertSee('ARSIP RME LAMA');

    // And the private viewer still opens for this authorized reader.
    $this->actingAs($user)
        ->get(route('rme.legacy-records.show', $record->getKey()))
        ->assertOk();

    expect(lrmeh1History($user, $patient)->where('kind', LegacyRmeTimelineEntry::KIND_LEGACY))->toHaveCount(1);

    // The published evidence itself is untouched.
    expect(LegacyRmeRecord::query()->find($record->getKey())?->isPublished())->toBeTrue();
});

it('keeps published evidence readable when no branch is admitted for migration', function () {
    $patient = lrmeh1Patient();
    $visit = lrmeh1Visit($patient, '2024-06-01');
    lrmeh1Sheet($visit);
    lrmeh1Published($patient, '2019-04-17');
    $user = lrmeh1WorkspaceUser($patient);

    // Branch ADMISSION governs INGESTION (which branch may migrate documents).
    // It is not a read gate: an archive already published stays part of the
    // patient's clinical history after the migration wave closes.
    legacyRmeAdmittedBranches([]);

    expect(lrmeh1History($user, $patient)->where('kind', LegacyRmeTimelineEntry::KIND_LEGACY))
        ->toHaveCount(1);

    $this->actingAs($user)
        ->get(route('rme.visits.medical-record.show', $visit))
        ->assertOk()
        ->assertSee('ARSIP RME LAMA');
});

/*
|--------------------------------------------------------------------------
| Q — privacy
|--------------------------------------------------------------------------
*/

it('never renders KTP/NIK or a storage path in the workspace history', function () {
    $patient = lrmeh1Patient();
    $patient->forceFill(['ktp_number' => '7371010101800001'])->save();

    $visit = lrmeh1Visit($patient, '2024-06-01');
    lrmeh1Sheet($visit);
    $record = lrmeh1Published($patient, '2019-04-17');

    $response = $this->actingAs(lrmeh1WorkspaceUser($patient))
        ->get(route('rme.visits.medical-record.show', $visit))
        ->assertOk();

    $response->assertDontSee('7371010101800001')
        ->assertDontSee($record->source_pdf_path, false)
        ->assertDontSee($record->source_pdf_sha256, false);
});

/*
|--------------------------------------------------------------------------
| The ascending visit-page card keeps its own contract
|--------------------------------------------------------------------------
*/

it('leaves the visit-page timeline ascending and legacy-gated', function () {
    $patient = lrmeh1Patient();
    lrmeh1Visit($patient, '2024-06-01');

    $service = app(LegacyRmePatientHistoryService::class);
    $native = app(ClinicVisitService::class)
        ->patientVisitHistory((int) $patient->getKey());

    // No archive → the visit page renders no merged card at all.
    expect($service->timelineFor(superAdmin(), (int) $patient->getKey(), $native))->toBeEmpty();

    lrmeh1Published($patient, '2019-04-17');

    expect($service->timelineFor(superAdmin(), (int) $patient->getKey(), $native)
        ->map(fn (LegacyRmeTimelineEntry $e) => $e->kind)->all())
        ->toBe(['LEGACY', 'NATIVE']);
});
