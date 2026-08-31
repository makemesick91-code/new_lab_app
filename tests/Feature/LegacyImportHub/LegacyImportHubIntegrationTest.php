<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| FEATURE-LEGACY-IMPORT-HUB-1 — the ceiling reaches all three importers.
|--------------------------------------------------------------------------
|
| The quota suite proves the counter is correct in isolation. This one proves
| each importer is actually WIRED to it — that a real intake charges a slot, a
| refused intake charges nothing, and a retry of an already-accepted record is
| not charged twice.
|
| A counter nobody calls is the failure mode these tests exist to catch, and it
| is invisible to a unit test of the counter.
*/

use App\Modules\LegacyImport\Models\LegacyImportDailyQuota;
use App\Modules\LegacyImport\Support\LegacyImportType;
use App\Modules\LegacyOdontogram\Models\LegacyOdontogramImport;
use App\Modules\LegacyOdontogram\Services\LegacyOdontogramImportService;
use App\Modules\LegacyRme\Models\LegacyRmeImport;
use App\Modules\LegacyRme\Services\LegacyRmeImportService;
use App\Modules\Patient\Models\LegacyPatientImportBatch;
use App\Modules\Patient\Models\LegacyPatientImportRow;
use App\Modules\Patient\Models\Patient;
use App\Modules\Patient\Services\LegacyPatientImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

require_once __DIR__.'/helpers.php';
require_once __DIR__.'/../LegacyOdontogram/helpers.php';

beforeEach(function () {
    seedAccessControl();
    Bus::fake();
});

/*
|--------------------------------------------------------------------------
| Legacy RME
|--------------------------------------------------------------------------
*/

describe('legacy RME', function () {
    beforeEach(function () {
        Storage::fake('legacy_rme_private');
        legacyRmeArchiveFlag(true);
        legacyRmeAdmittedBranches(['TLK1']);
        legacyRmeMigrationWave(['TLK1']);
    });

    it('charges one slot for an accepted document', function () {
        $patient = legacyRmeArchivablePatient();
        legacyRmeNativeVisit($patient, '2022-03-10');

        app(LegacyRmeImportService::class)->createFromUpload(
            $patient,
            '2019-04-02',
            $patient->medical_record_number,
            null,
            legacyRmePdfUpload(),
            superAdmin(),
        );

        expect(LegacyRmeImport::query()->count())->toBe(1);

        // Charged to the branch DERIVED from the Nomor RM, which is the only
        // branch authority on this path.
        expect(lihQuota()->consumedToday(LegacyImportType::LEGACY_RME, (int) $patient->branch_id))->toBe(1);
    });

    it('refuses the document when the ceiling is reached, and stages nothing', function () {
        $patient = legacyRmeArchivablePatient();
        legacyRmeNativeVisit($patient, '2022-03-10');

        lihLimit(LegacyImportType::LEGACY_RME, 1);
        lihConsume(LegacyImportType::LEGACY_RME, (int) $patient->branch_id, 1);

        expect(fn () => app(LegacyRmeImportService::class)->createFromUpload(
            $patient,
            '2019-04-02',
            $patient->medical_record_number,
            null,
            legacyRmePdfUpload(),
            superAdmin(),
        ))->toThrow(ValidationException::class);

        // No staging row, no bytes on the private disk, and the counter did not
        // move: a refused intake costs nothing.
        expect(LegacyRmeImport::query()->count())->toBe(0);
        expect(Storage::disk('legacy_rme_private')->allFiles())->toBe([]);
        expect(lihQuota()->consumedToday(LegacyImportType::LEGACY_RME, (int) $patient->branch_id))->toBe(1);
    });

    it('does not charge a rejected upload', function () {
        $patient = legacyRmeArchivablePatient();
        legacyRmeNativeVisit($patient, '2022-03-10');

        // A legacy date AFTER the native cutoff is refused by the date rules,
        // long before anything is staged.
        try {
            app(LegacyRmeImportService::class)->createFromUpload(
                $patient,
                '2023-01-01',
                $patient->medical_record_number,
                null,
                legacyRmePdfUpload(),
                superAdmin(),
            );
        } catch (ValidationException) {
            // expected
        }

        expect(LegacyRmeImport::query()->count())->toBe(0);
        expect(lihQuota()->consumedToday(LegacyImportType::LEGACY_RME, (int) $patient->branch_id))->toBe(0);
    });

    it('does not charge a retry of an already-accepted document', function () {
        $patient = legacyRmeArchivablePatient();
        legacyRmeNativeVisit($patient, '2022-03-10');

        $import = app(LegacyRmeImportService::class)->createFromUpload(
            $patient,
            '2019-04-02',
            $patient->medical_record_number,
            null,
            legacyRmePdfUpload(),
            superAdmin(),
        );

        expect(lihQuota()->consumedToday(LegacyImportType::LEGACY_RME, (int) $patient->branch_id))->toBe(1);

        // A retry re-queues render work for a document that was already accepted
        // and already charged. Charging it again would mean a branch that hit a
        // transient Poppler failure silently lost quota it never used.
        app(LegacyRmeImportService::class)->queue($import, superAdmin(), true);

        expect(lihQuota()->consumedToday(LegacyImportType::LEGACY_RME, (int) $patient->branch_id))->toBe(1);
    });
});

/*
|--------------------------------------------------------------------------
| Legacy Odontogram
|--------------------------------------------------------------------------
*/

describe('legacy odontogram', function () {
    beforeEach(function () {
        Storage::fake('legacy_odontogram_private');
        lodoFlag(true);
    });

    it('charges one slot for an accepted chart', function () {
        $patient = lodoPatient();
        lodoNativeOdontogram($patient, '2022-03-10');

        app(LegacyOdontogramImportService::class)->createFromUpload(
            $patient,
            '2019-04-02',
            lodoPdfUpload(),
            lodoOperator(),
        );

        expect(LegacyOdontogramImport::query()->count())->toBe(1);
        expect(lihQuota()->consumedToday(LegacyImportType::LEGACY_ODONTOGRAM, (int) $patient->branch_id))->toBe(1);
    });

    it('refuses the chart when the ceiling is reached, and stages nothing', function () {
        $patient = lodoPatient();
        lodoNativeOdontogram($patient, '2022-03-10');

        lihLimit(LegacyImportType::LEGACY_ODONTOGRAM, 1);
        lihConsume(LegacyImportType::LEGACY_ODONTOGRAM, (int) $patient->branch_id, 1);

        expect(fn () => app(LegacyOdontogramImportService::class)->createFromUpload(
            $patient,
            '2019-04-02',
            lodoPdfUpload(),
            lodoOperator(),
        ))->toThrow(ValidationException::class);

        expect(LegacyOdontogramImport::query()->count())->toBe(0);
        expect(Storage::disk('legacy_odontogram_private')->allFiles())->toBe([]);
        expect(lihQuota()->consumedToday(LegacyImportType::LEGACY_ODONTOGRAM, (int) $patient->branch_id))->toBe(1);
    });

    it('does not spend a legacy RME slot', function () {
        $patient = lodoPatient();
        lodoNativeOdontogram($patient, '2022-03-10');

        app(LegacyOdontogramImportService::class)->createFromUpload(
            $patient,
            '2019-04-02',
            lodoPdfUpload(),
            lodoOperator(),
        );

        // The two archives are separate capabilities with separate ceilings.
        // Sharing a counter would make one capability's workload throttle the
        // other's.
        expect(lihQuota()->consumedToday(LegacyImportType::LEGACY_RME, (int) $patient->branch_id))->toBe(0);
    });
});

/*
|--------------------------------------------------------------------------
| Legacy Patient
|--------------------------------------------------------------------------
*/

describe('legacy patient', function () {
    /**
     * A validated batch whose rows are ready to commit into `mst_patients`.
     *
     * Built directly rather than through a CSV upload: this suite is about what
     * COMMIT charges, and the parser has its own suite.
     */
    function lihPatientBatch(int $rows, int $branchId, string $branchCode = 'TLK1'): LegacyPatientImportBatch
    {
        $batch = LegacyPatientImportBatch::query()->create([
            'uuid' => (string) Str::uuid(),
            'original_filename' => 'legacy.csv',
            'status' => LegacyPatientImportBatch::STATUS_VALIDATED,
            'total_rows' => $rows,
            'uploaded_by' => null,
        ]);

        for ($i = 1; $i <= $rows; $i++) {
            LegacyPatientImportRow::query()->create([
                'batch_id' => $batch->id,
                'row_number' => $i,
                'status' => LegacyPatientImportRow::STATUS_VALID,
                'raw_payload' => ['nama' => 'Pasien Legacy '.$i],
                'normalized_payload' => [
                    'name' => 'Pasien Legacy '.$i,
                    'medical_record_number' => sprintf('DG-%s-2024-%05d', $branchCode, $i),
                    'branch_id' => $branchId,
                    'gender' => 'male',
                    'date_of_birth' => '1990-01-01',
                ],
                'matched_branch_id' => $branchId,
            ]);
        }

        return $batch->refresh();
    }

    it('charges one slot per committed patient row', function () {
        $branch = lihBranch();
        $batch = lihPatientBatch(3, (int) $branch->id);

        app(LegacyPatientImportService::class)->commit($batch, null);

        expect(Patient::query()->count())->toBe(3);
        expect(lihQuota()->consumedToday(LegacyImportType::LEGACY_PATIENT, (int) $branch->id))->toBe(3);
    });

    it('charges nothing for staging alone', function () {
        $branch = lihBranch();
        lihPatientBatch(3, (int) $branch->id);

        // Staging writes no patient and can be discarded outright, so billing an
        // operator for it would charge them for work they never committed.
        expect(lihQuota()->consumedToday(LegacyImportType::LEGACY_PATIENT, (int) $branch->id))->toBe(0);
    });

    it('refuses the whole commit when the batch would exceed the ceiling', function () {
        $branch = lihBranch();

        lihLimit(LegacyImportType::LEGACY_PATIENT, 4);
        lihConsume(LegacyImportType::LEGACY_PATIENT, (int) $branch->id, 2);

        $batch = lihPatientBatch(3, (int) $branch->id);

        expect(fn () => app(LegacyPatientImportService::class)->commit($batch, null))
            ->toThrow(ValidationException::class);

        // Nothing landed: the reservation shares the commit transaction, so a
        // refusal takes every insert with it.
        expect(Patient::query()->count())->toBe(0);
        expect(lihQuota()->consumedToday(LegacyImportType::LEGACY_PATIENT, (int) $branch->id))->toBe(2);
        expect($batch->refresh()->status)->toBe(LegacyPatientImportBatch::STATUS_VALIDATED);
    });

    it('does not charge a re-commit of an already committed batch', function () {
        $branch = lihBranch();
        $batch = lihPatientBatch(2, (int) $branch->id);

        app(LegacyPatientImportService::class)->commit($batch, null);
        expect(lihQuota()->consumedToday(LegacyImportType::LEGACY_PATIENT, (int) $branch->id))->toBe(2);

        // Idempotent: a committed batch is a no-op, so a retried request cannot
        // be billed twice.
        app(LegacyPatientImportService::class)->commit($batch->refresh(), null);

        expect(Patient::query()->count())->toBe(2);
        expect(lihQuota()->consumedToday(LegacyImportType::LEGACY_PATIENT, (int) $branch->id))->toBe(2);
    });

    it('charges each row to its own branch', function () {
        $a = lihBranch('TLK1', 'Cabang Telkomas');
        $b = lihBranch('LDK2', 'Cabang Landak');

        $batch = lihPatientBatch(2, (int) $a->id);

        // Repoint the second row at another branch, the way a CSV with two
        // `Cabang` values does.
        $second = LegacyPatientImportRow::query()->where('batch_id', $batch->id)->orderByDesc('row_number')->firstOrFail();
        $payload = $second->normalized_payload;
        $payload['branch_id'] = (int) $b->id;
        $payload['medical_record_number'] = 'DG-LDK2-2024-00002';
        $second->update(['normalized_payload' => $payload, 'matched_branch_id' => (int) $b->id]);

        app(LegacyPatientImportService::class)->commit($batch->refresh(), null);

        expect(lihQuota()->consumedToday(LegacyImportType::LEGACY_PATIENT, (int) $a->id))->toBe(1);
        expect(lihQuota()->consumedToday(LegacyImportType::LEGACY_PATIENT, (int) $b->id))->toBe(1);
    });

    it('does not charge a row the RM re-check skips', function () {
        $branch = lihBranch();
        $batch = lihPatientBatch(2, (int) $branch->id);

        // Someone else registered this RM between preview and commit. The row is
        // skipped rather than overwritten, and a skipped row is not an accepted
        // record.
        Patient::factory()->create([
            'branch_id' => $branch->id,
            'medical_record_number' => 'DG-TLK1-2024-00001',
        ]);

        app(LegacyPatientImportService::class)->commit($batch, null);

        expect(lihQuota()->consumedToday(LegacyImportType::LEGACY_PATIENT, (int) $branch->id))->toBe(1);
    });
});

/*
|--------------------------------------------------------------------------
| The ledger itself
|--------------------------------------------------------------------------
*/

it('keeps one bucket per import type, branch and clinical day', function () {
    $branch = lihBranch();

    lihConsume(LegacyImportType::LEGACY_RME, (int) $branch->id, 2);
    lihConsume(LegacyImportType::LEGACY_PATIENT, (int) $branch->id, 2);

    // Two types, one branch, one day => exactly two buckets. A third would mean
    // two counters for the same identity, each below the ceiling.
    expect(LegacyImportDailyQuota::query()->count())->toBe(2);
});
