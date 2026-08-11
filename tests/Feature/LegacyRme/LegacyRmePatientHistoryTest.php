<?php

/**
 * LEGACY-RME-PDF-1C — the published archive inside the patient's RME history.
 *
 * A legacy record joins the patient's medical history WITHOUT becoming a
 * ClinicVisit or a native MedicalRecord: the two stay separate domain entities
 * and are merged only for presentation, ordered by the CLINICAL date.
 *
 * Only PUBLISHED records ever appear. Staged, failed, cancelled and VOIDed rows
 * are work in progress or retracted evidence and must never look like part of
 * the patient's history.
 */

use App\Modules\Branch\Models\Branch;
use App\Modules\LegacyRme\Models\LegacyRmeImport;
use App\Modules\LegacyRme\Models\LegacyRmeRecord;
use App\Modules\LegacyRme\Services\LegacyRmePatientHistoryService;
use App\Modules\LegacyRme\Support\LegacyRmeImportStatus;
use App\Modules\LegacyRme\Support\LegacyRmeTimelineEntry;
use App\Modules\Patient\Models\Patient;

beforeEach(function () {
    seedAccessControl();
    legacyRmeArchiveFlag(true);
});

function lrme1cHistoryPatient(): Patient
{
    return legacyRmeArchivablePatient(['date_of_birth' => '1990-01-01']);
}

function lrme1cPublishedRecord(Patient $patient, string $date, array $overrides = []): LegacyRmeRecord
{
    return LegacyRmeRecord::factory()->create(array_merge([
        'patient_id' => $patient->getKey(),
        'origin_branch_id' => null,
        'rme_date' => $date,
        'status' => LegacyRmeRecord::STATUS_PUBLISHED,
        'page_count' => 2,
    ], $overrides));
}

it('places legacy archive entries before native entries by clinical date', function () {
    $patient = lrme1cHistoryPatient();

    lrme1cPublishedRecord($patient, '2020-05-01');
    lrme1cPublishedRecord($patient, '2021-11-14');

    $native = collect([
        legacyRmeNativeVisit($patient, '2022-03-10'),
        legacyRmeNativeVisit($patient, '2022-08-02'),
    ]);

    $timeline = app(LegacyRmePatientHistoryService::class)
        ->timelineFor(superAdmin(), (int) $patient->getKey(), $native);

    expect($timeline)->toHaveCount(4);

    expect($timeline->map(fn (LegacyRmeTimelineEntry $e) => $e->kind.' '.$e->date->format('Y-m-d'))->all())
        ->toBe([
            'LEGACY 2020-05-01',
            'LEGACY 2021-11-14',
            'NATIVE 2022-03-10',
            'NATIVE 2022-08-02',
        ]);
});

it('orders by the historical date, not by when the archive was uploaded', function () {
    $patient = lrme1cHistoryPatient();

    // Created LAST but clinically the OLDEST — it must sort first.
    $native = collect([legacyRmeNativeVisit($patient, '2022-03-10')]);
    lrme1cPublishedRecord($patient, '2015-01-02');

    $timeline = app(LegacyRmePatientHistoryService::class)
        ->timelineFor(superAdmin(), (int) $patient->getKey(), $native);

    expect($timeline->first()->isLegacy())->toBeTrue()
        ->and($timeline->first()->date->format('Y-m-d'))->toBe('2015-01-02');
});

it('shows only published records — never a staged import', function () {
    $patient = lrme1cHistoryPatient();

    foreach ([
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

    $records = app(LegacyRmePatientHistoryService::class)
        ->publishedRecordsFor(superAdmin(), (int) $patient->getKey());

    expect($records)->toBeEmpty();
});

it('excludes a voided record from the patient history', function () {
    $patient = lrme1cHistoryPatient();

    lrme1cPublishedRecord($patient, '2020-05-01');
    lrme1cPublishedRecord($patient, '2019-01-01', [
        'status' => LegacyRmeRecord::STATUS_VOID,
        'voided_at' => now(),
        'void_reason' => 'Salah pasien.',
    ]);

    $records = app(LegacyRmePatientHistoryService::class)
        ->publishedRecordsFor(superAdmin(), (int) $patient->getKey());

    expect($records)->toHaveCount(1)
        ->and($records->first()->rme_date->format('Y-m-d'))->toBe('2020-05-01');
});

it('never leaks another patient archive into this patient history', function () {
    $patient = lrme1cHistoryPatient();
    $other = lrme1cHistoryPatient();

    lrme1cPublishedRecord($patient, '2020-05-01');
    lrme1cPublishedRecord($other, '2018-02-02');

    $records = app(LegacyRmePatientHistoryService::class)
        ->publishedRecordsFor(superAdmin(), (int) $patient->getKey());

    expect($records)->toHaveCount(1)
        ->and($records->first()->patient_id)->toBe($patient->getKey());
});

it('returns nothing while the feature flag is off', function () {
    $patient = lrme1cHistoryPatient();
    lrme1cPublishedRecord($patient, '2020-05-01');

    legacyRmeArchiveFlag(false);

    $service = app(LegacyRmePatientHistoryService::class);

    expect($service->publishedRecordsFor(superAdmin(), (int) $patient->getKey()))->toBeEmpty()
        ->and($service->timelineFor(superAdmin(), (int) $patient->getKey(), collect()))->toBeEmpty();
});

it('returns nothing for a user without the archive permission', function () {
    $patient = lrme1cHistoryPatient();
    lrme1cPublishedRecord($patient, '2020-05-01');

    $records = app(LegacyRmePatientHistoryService::class)
        ->publishedRecordsFor(userWith(['view_clinic_visits']), (int) $patient->getKey());

    expect($records)->toBeEmpty();
});

it('returns nothing for a guest', function () {
    $patient = lrme1cHistoryPatient();
    lrme1cPublishedRecord($patient, '2020-05-01');

    expect(app(LegacyRmePatientHistoryService::class)
        ->publishedRecordsFor(null, (int) $patient->getKey()))->toBeEmpty();
});

it('keeps a branch-scoped operator away from another branch archive', function () {
    $patient = lrme1cHistoryPatient();

    $ownBranch = Branch::factory()->create(['is_active' => true, 'is_rme_enabled' => true]);
    $otherBranch = Branch::factory()->create(['is_active' => true, 'is_rme_enabled' => true]);

    lrme1cPublishedRecord($patient, '2020-05-01', ['origin_branch_id' => $otherBranch->getKey()]);

    // Holds the read permission but none of the governance-tier permissions,
    // and is explicitly pinned to a DIFFERENT branch — so the scope resolves to
    // their own branch only, never the archive's.
    $operator = userWith(['view_legacy_rme_imports']);
    $operator->forceFill(['branch_id' => $ownBranch->getKey()])->save();

    $records = app(LegacyRmePatientHistoryService::class)
        ->publishedRecordsFor($operator->refresh(), (int) $patient->getKey());

    expect($records)->toBeEmpty();
});

it('lets a branch-scoped operator see their own branch archive', function () {
    $patient = lrme1cHistoryPatient();
    $ownBranch = Branch::factory()->create(['is_active' => true, 'is_rme_enabled' => true]);

    lrme1cPublishedRecord($patient, '2020-05-01', ['origin_branch_id' => $ownBranch->getKey()]);

    $operator = userWith(['view_legacy_rme_imports']);
    $operator->forceFill(['branch_id' => $ownBranch->getKey()])->save();

    expect(app(LegacyRmePatientHistoryService::class)
        ->publishedRecordsFor($operator->refresh(), (int) $patient->getKey()))->toHaveCount(1);
});

it('produces no merged timeline when the patient has no legacy archive', function () {
    $patient = lrme1cHistoryPatient();
    $native = collect([legacyRmeNativeVisit($patient, '2022-03-10')]);

    // Nothing to merge — the workspace already shows the native visit history,
    // so the merged card must not restate it.
    expect(app(LegacyRmePatientHistoryService::class)
        ->timelineFor(superAdmin(), (int) $patient->getKey(), $native))->toBeEmpty();
});

it('marks legacy and native entries as distinct kinds', function () {
    $patient = lrme1cHistoryPatient();
    lrme1cPublishedRecord($patient, '2020-05-01');
    $native = collect([legacyRmeNativeVisit($patient, '2022-03-10')]);

    $timeline = app(LegacyRmePatientHistoryService::class)
        ->timelineFor(superAdmin(), (int) $patient->getKey(), $native);

    $legacy = $timeline->firstWhere(fn (LegacyRmeTimelineEntry $e) => $e->isLegacy());
    $nativeEntry = $timeline->firstWhere(fn (LegacyRmeTimelineEntry $e) => ! $e->isLegacy());

    expect($legacy->kind)->toBe(LegacyRmeTimelineEntry::KIND_LEGACY)
        ->and($legacy->label)->toContain('Arsip')
        ->and($nativeEntry->kind)->toBe(LegacyRmeTimelineEntry::KIND_NATIVE)
        ->and($legacy->url)->toContain('legacy-records');
});
