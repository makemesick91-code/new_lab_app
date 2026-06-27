<?php

/**
 * Sprint 62.3 — Legacy RME Patient Batch Import.
 *
 * Staging + preview + commit workflow. Verifies: nothing touches mst_patients
 * until commit; RM composed via the locked service; hard duplicate (RM/KTP, DB
 * incl. trashed + in-file) blocks; soft duplicate warns; KTP always masked;
 * commit creates patients only (no visit/RM); commit lock re-check skips a race
 * duplicate; idempotent re-commit; rollback soft-deletes (guarded by downstream
 * visits); authorization via `manage patients`.
 */

use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Patient\Models\LegacyPatientImportBatch;
use App\Modules\Patient\Models\LegacyPatientImportRow;
use App\Modules\Patient\Models\Patient;
use App\Modules\Patient\Services\LegacyPatientImportService;
use Database\Seeders\BranchSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    test()->seed(BranchSeeder::class);
    seedAccessControl();
    Storage::fake('local');

    $this->branch = Branch::factory()->create([
        'code' => 'TKM1', 'name' => 'Cabang Telkomas', 'is_active' => true, 'is_rme_enabled' => true,
    ]);
    $this->service = app(LegacyPatientImportService::class);
});

/** Build a fake legacy CSV from canonical-key rows (missing keys → blank). */
function legacyCsv(array $rows): UploadedFile
{
    $keys = array_map(fn ($c) => $c['key'], LegacyPatientImportService::COLUMNS);
    $header = array_map(fn ($c) => $c['label'], LegacyPatientImportService::COLUMNS);

    $handle = fopen('php://temp', 'r+');
    fputcsv($handle, $header);
    foreach ($rows as $row) {
        $line = [];
        foreach ($keys as $k) {
            $line[] = $row[$k] ?? '';
        }
        fputcsv($handle, $line);
    }
    rewind($handle);
    $content = stream_get_contents($handle);
    fclose($handle);

    $path = tempnam(sys_get_temp_dir(), 'leg').'.csv';
    file_put_contents($path, $content);

    return new UploadedFile($path, 'legacy.csv', 'text/csv', null, true);
}

function validRow(array $overrides = []): array
{
    return array_merge([
        'branch' => 'TKM1',
        'manual_rm_number' => '0001',
        'timestamp' => '2024-01-15',
        'name' => 'Budi Santoso',
        'gender' => 'Laki-laki',
        'date_of_birth' => '1990-05-20',
    ], $overrides);
}

function stage(UploadedFile $file): LegacyPatientImportBatch
{
    return test()->service->parseAndStage($file, null);
}

// --- Upload / parse -----------------------------------------------------------

it('lets a patient manager upload a valid CSV and stages it without touching mst_patients', function () {
    $this->actingAs(userWith(['manage patients']))
        ->post(route('settings.patients.import.store'), ['csv_file' => legacyCsv([validRow()])])
        ->assertRedirect();

    expect(LegacyPatientImportBatch::count())->toBe(1)
        ->and(LegacyPatientImportRow::count())->toBe(1)
        ->and(Patient::count())->toBe(0);
});

it('forbids a user without manage patients from every import action', function () {
    $user = userWith(['view_clinic_visits']);
    $batch = stage(legacyCsv([validRow()]));

    $this->actingAs($user)->get(route('settings.patients.import.index'))->assertForbidden();
    $this->actingAs($user)->post(route('settings.patients.import.store'), ['csv_file' => legacyCsv([validRow()])])->assertForbidden();
    $this->actingAs($user)->get(route('settings.patients.import.show', $batch))->assertForbidden();
    $this->actingAs($user)->post(route('settings.patients.import.commit', $batch))->assertForbidden();
    $this->actingAs($user)->post(route('settings.patients.import.rollback', $batch))->assertForbidden();
});

it('rejects a CSV with a mismatched header', function () {
    $path = tempnam(sys_get_temp_dir(), 'bad').'.csv';
    file_put_contents($path, "foo,bar\n1,2\n");
    $file = new UploadedFile($path, 'bad.csv', 'text/csv', null, true);

    $this->actingAs(userWith(['manage patients']))
        ->post(route('settings.patients.import.store'), ['csv_file' => $file])
        ->assertSessionHasErrors('csv_file');

    expect(LegacyPatientImportBatch::count())->toBe(0);
});

it('maps gender labels and preserves leading zeros in the manual RM', function () {
    $batch = stage(legacyCsv([
        validRow(['manual_rm_number' => '0007', 'gender' => 'P', 'name' => 'Siti']),
        validRow(['manual_rm_number' => '0008', 'gender' => 'X', 'name' => 'Joko']),
    ]));

    $female = LegacyPatientImportRow::where('patient_name', 'Siti')->first();
    $unknown = LegacyPatientImportRow::where('patient_name', 'Joko')->first();

    expect($female->gender)->toBe('Female')
        ->and($female->generated_medical_record_number)->toBe('DG-TKM1-2024-0007')
        ->and($unknown->gender)->toBe('Other')
        ->and($unknown->status)->toBe('warning');
});

// --- Validation ---------------------------------------------------------------

it('errors rows missing name, branch, manual RM, or timestamp', function () {
    $batch = stage(legacyCsv([
        validRow(['name' => '']),
        validRow(['branch' => '', 'manual_rm_number' => '0002']),
        validRow(['manual_rm_number' => '', 'name' => 'NoRm']),
        validRow(['timestamp' => '', 'manual_rm_number' => '0003', 'name' => 'NoTs']),
    ]));

    expect($batch->error_rows)->toBe(4)
        ->and($batch->valid_rows)->toBe(0);
});

it('errors a MAIN, disabled, or unknown branch', function () {
    Branch::factory()->create(['code' => 'OFF1', 'is_active' => false, 'is_rme_enabled' => true]);

    $batch = stage(legacyCsv([
        validRow(['branch' => 'MAIN', 'manual_rm_number' => '0010', 'name' => 'A']),
        validRow(['branch' => 'OFF1', 'manual_rm_number' => '0011', 'name' => 'B']),
        validRow(['branch' => 'ZZZ', 'manual_rm_number' => '0012', 'name' => 'C']),
    ]));

    expect($batch->error_rows)->toBe(3);
});

it('errors invalid email and future date of birth', function () {
    $batch = stage(legacyCsv([
        validRow(['email' => 'not-an-email', 'manual_rm_number' => '0020', 'name' => 'A']),
        validRow(['date_of_birth' => '2099-01-01', 'manual_rm_number' => '0021', 'name' => 'B']),
    ]));

    expect($batch->error_rows)->toBe(2);
});

it('warns on an unresolved doctor but keeps the row committable with doctor_id null', function () {
    $batch = stage(legacyCsv([
        validRow(['doctor' => 'drg. Tidak Terdaftar', 'manual_rm_number' => '0030', 'name' => 'A']),
    ]));

    $row = LegacyPatientImportRow::first();
    expect($row->status)->toBe('warning')
        ->and($row->matched_doctor_id)->toBeNull();

    $this->service->commit($batch->refresh(), null);
    expect(Patient::first()->doctor_id)->toBeNull();
});

// --- RM generation ------------------------------------------------------------

it('composes the RM as DG-CODE-YEAR-MANUAL with the year from the timestamp', function () {
    $batch = stage(legacyCsv([validRow(['timestamp' => '2024-03-09', 'manual_rm_number' => '0042'])]));

    expect(LegacyPatientImportRow::first()->generated_medical_record_number)
        ->toBe('DG-TKM1-2024-0042');
});

// --- Duplicates ---------------------------------------------------------------

it('blocks a composed RM that already exists including soft-deleted patients', function () {
    $existing = Patient::factory()->create(['medical_record_number' => 'DG-TKM1-2024-0001']);
    $existing->delete();

    $batch = stage(legacyCsv([validRow()]));
    expect($batch->error_rows)->toBe(1);
});

it('blocks a KTP that already exists in mst_patients', function () {
    Patient::factory()->create(['ktp_number' => '3201010101010001']);

    $batch = stage(legacyCsv([validRow(['ktp_number' => '3201010101010001', 'manual_rm_number' => '0050'])]));
    expect($batch->error_rows)->toBe(1);
});

it('blocks in-file duplicate RM and KTP', function () {
    $batch = stage(legacyCsv([
        validRow(['manual_rm_number' => '0060', 'name' => 'A']),
        validRow(['manual_rm_number' => '0060', 'name' => 'B']),
        validRow(['manual_rm_number' => '0061', 'ktp_number' => '3201010101019999', 'name' => 'C']),
        validRow(['manual_rm_number' => '0062', 'ktp_number' => '3201010101019999', 'name' => 'D']),
    ]));

    expect($batch->error_rows)->toBe(2)
        ->and($batch->valid_rows)->toBe(2);
});

it('warns (not blocks) on a soft duplicate name + date of birth', function () {
    Patient::factory()->create(['name' => 'Budi Santoso', 'date_of_birth' => '1990-05-20', 'is_active' => true]);

    $batch = stage(legacyCsv([validRow()]));
    $row = LegacyPatientImportRow::first();

    expect($row->status)->toBe('warning')
        ->and($batch->warning_rows)->toBe(1);
});

// --- Preview / privacy --------------------------------------------------------

it('renders KTP masked in preview and never the full number', function () {
    $batch = stage(legacyCsv([validRow(['ktp_number' => '3201010101012345'])]));

    $this->actingAs(userWith(['manage patients']))
        ->get(route('settings.patients.import.show', $batch))
        ->assertOk()
        ->assertSee('****2345')
        ->assertDontSee('3201010101012345');
});

it('labels advisory columns as staged only in preview', function () {
    $batch = stage(legacyCsv([validRow(['initial_treatment' => 'Scaling', 'chief_complaint' => 'Ngilu'])]));

    $this->actingAs(userWith(['manage patients']))
        ->get(route('settings.patients.import.show', $batch))
        ->assertSee('belum masuk RME');
});

// --- Commit -------------------------------------------------------------------

it('commit creates patients only, with import_batch_id and composed RM, and no visit/RM', function () {
    $batch = stage(legacyCsv([validRow()]));

    $this->actingAs(userWith(['manage patients']))
        ->post(route('settings.patients.import.commit', $batch))
        ->assertRedirect();

    $patient = Patient::first();
    expect(Patient::count())->toBe(1)
        ->and($patient->import_batch_id)->toBe($batch->id)
        ->and($patient->medical_record_number)->toBe('DG-TKM1-2024-0001')
        ->and($patient->is_active)->toBeTrue()
        ->and(ClinicVisit::count())->toBe(0);

    expect($batch->refresh()->status)->toBe('committed')
        ->and($batch->committed_rows)->toBe(1)
        ->and(LegacyPatientImportRow::first()->committed_patient_id)->toBe($patient->id);
});

it('commit skips error rows', function () {
    $batch = stage(legacyCsv([
        validRow(['manual_rm_number' => '0070', 'name' => 'Good']),
        validRow(['name' => '', 'manual_rm_number' => '0071']),
    ]));

    $this->service->commit($batch, null);
    expect(Patient::count())->toBe(1);
});

it('skips a row that became a duplicate between preview and commit (lock re-check)', function () {
    $batch = stage(legacyCsv([validRow()]));

    // Simulate a concurrent registration of the same RM after preview.
    Patient::factory()->create(['medical_record_number' => 'DG-TKM1-2024-0001']);

    $this->service->commit($batch, null);

    expect(Patient::where('medical_record_number', 'DG-TKM1-2024-0001')->count())->toBe(1)
        ->and(LegacyPatientImportRow::first()->status)->toBe('skipped');
});

it('is idempotent: re-committing a committed batch does not duplicate patients', function () {
    $batch = stage(legacyCsv([validRow()]));

    $this->service->commit($batch, null);
    $this->service->commit($batch->refresh(), null);

    expect(Patient::count())->toBe(1);
});

// --- Rollback -----------------------------------------------------------------

it('rollback soft-deletes the batch patients and frees the RM as trashed', function () {
    $batch = stage(legacyCsv([validRow()]));
    $this->service->commit($batch, null);

    $this->service->rollback($batch->refresh(), null);

    expect(Patient::count())->toBe(0)
        ->and(Patient::withTrashed()->count())->toBe(1)
        ->and($batch->refresh()->status)->toBe('rolled_back')
        ->and(LegacyPatientImportRow::first()->status)->toBe('rolled_back');
});

it('refuses rollback when an imported patient already has a downstream visit', function () {
    $batch = stage(legacyCsv([validRow()]));
    $this->service->commit($batch, null);
    $patient = Patient::first();

    ClinicVisit::factory()->create(['patient_id' => $patient->id]);

    expect(fn () => $this->service->rollback($batch->refresh(), null))
        ->toThrow(RuntimeException::class);

    expect(Patient::count())->toBe(1);
});

it('discards a pre-commit batch without touching mst_patients', function () {
    $batch = stage(legacyCsv([validRow()]));

    $this->service->discard($batch);

    expect(LegacyPatientImportBatch::count())->toBe(0)
        ->and(Patient::count())->toBe(0);
});

// --- Report -------------------------------------------------------------------

it('streams an error report with masked KTP and one line per message', function () {
    $batch = stage(legacyCsv([
        validRow(['name' => '', 'ktp_number' => '3201010101015678', 'manual_rm_number' => '0080']),
    ]));

    $response = $this->actingAs(userWith(['manage patients']))
        ->get(route('settings.patients.import.errors', $batch));
    $response->assertOk();

    $csv = $response->streamedContent();
    expect($csv)->toContain('****5678')
        ->and($csv)->not->toContain('3201010101015678')
        ->and($csv)->toContain('Nama Pasien wajib diisi.');
});

it('keeps accurate batch counters', function () {
    $batch = stage(legacyCsv([
        validRow(['manual_rm_number' => '0090', 'name' => 'A']),
        validRow(['manual_rm_number' => '0091', 'doctor' => 'Unknown', 'name' => 'B']),
        validRow(['manual_rm_number' => '', 'name' => 'C']),
    ]));

    expect($batch->total_rows)->toBe(3)
        ->and($batch->valid_rows)->toBe(1)
        ->and($batch->warning_rows)->toBe(1)
        ->and($batch->error_rows)->toBe(1);
});
