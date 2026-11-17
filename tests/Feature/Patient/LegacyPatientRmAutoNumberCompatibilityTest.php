<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| DMS-RME-COMPREHENSIVE-CLOSURE-1 / memo M4.1 (rule 244 §2, PR-2) — the legacy
| patient CSV import beside the automatic Nomor RM
|--------------------------------------------------------------------------
|
| Legacy compatibility is kept: a legacy row still stores its typed number
| verbatim (DG-{KODE}-{TAHUN}-{NOMOR} from its branch, timestamp and manual
| number), and existing numbers are never rewritten. What changed is the
| uniqueness question: staging, revalidation and the commit now ask whether the
| number is taken in ANY spelling — the historical alias code (SUN4/SPN4,
| TKM1/TLK1), any zero padding, any letter case, a soft-deleted patient or an
| active merge alias — and the commit asks it under the branch-year counter lock,
| so an imported number can never collide with an automatic one
| (`DG-SUN4-2026-0007` against `DG-SPN4-2026-7`).
|
| Selected by the existing `LegacyPatient` critical-gate token in both runner
| variants and declared in critical_gate_mandatory_suites (CG-19). Helper prefix
| `lpac` is unique because the critical gate loads every suite into one process.
*/

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\Clinic\Models\Clinic;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\Patient\Exceptions\LegacyPatientImportBlockedException;
use App\Modules\Patient\Models\LegacyPatientImportBatch;
use App\Modules\Patient\Models\LegacyPatientImportRow;
use App\Modules\Patient\Models\Patient;
use App\Modules\Patient\Models\PatientRmSequence;
use App\Modules\Patient\Services\LegacyPatientImportService;
use App\Modules\Patient\Services\PatientRmNumberAllocator;
use App\Modules\Patient\Services\PatientService;
use App\Modules\PatientMerge\Models\PatientRmAlias;
use Database\Seeders\BranchSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function (): void {
    test()->seed(BranchSeeder::class);
    seedAccessControl();
    Storage::fake('local');
    pinTestClock('2026-06-13 01:00:00'); // 09:00 WITA, clinical year 2026

    $this->sunu = Branch::factory()->create([
        'code' => 'SPN4', 'name' => 'Cabang Sunu', 'is_active' => true, 'is_rme_enabled' => true,
    ]);
    $this->service = app(LegacyPatientImportService::class);
});

afterEach(fn () => freeTestClock());

/** Build a legacy CSV from canonical-key rows (missing keys -> blank cell). */
function lpacCsv(array $rows): UploadedFile
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

    $path = tempArtifactFile('lpac');
    file_put_contents($path, $content);

    return new UploadedFile($path, 'legacy.csv', 'text/csv', null, true);
}

function lpacRow(array $overrides = []): array
{
    return array_merge([
        'branch' => 'SPN4',
        'manual_rm_number' => '0001',
        'timestamp' => '2026-02-10',
        'name' => 'Pasien Lama '.Str::random(6),
        'gender' => 'Perempuan',
    ], $overrides);
}

function lpacStage(array $rows): LegacyPatientImportBatch
{
    return test()->service->parseAndStage(lpacCsv($rows), null);
}

/** @return list<string> */
function lpacErrors(LegacyPatientImportBatch $batch): array
{
    return LegacyPatientImportRow::query()
        ->where('batch_id', $batch->id)
        ->orderBy('row_number')
        ->get()
        ->flatMap(fn (LegacyPatientImportRow $row): array => $row->errors ?? [])
        ->values()
        ->all();
}

function lpacRegister(Branch $branch, string $name): Patient
{
    $clinic = Clinic::factory()->create();

    return app(PatientService::class)->create([
        'clinic_id' => $clinic->id,
        'doctor_id' => Doctor::factory()->create(['clinic_id' => $clinic->id])->id,
        'branch_id' => $branch->id,
        'name' => $name,
        'is_active' => true,
    ]);
}

/** An active merge alias holding `$alias` (the number is on no patient row). */
function lpacAlias(Branch $branch, string $alias): void
{
    $source = Patient::factory()->create(['branch_id' => $branch->id, 'medical_record_number' => 'DG-'.$branch->code.'-2019-0001']);
    $canonical = Patient::factory()->create(['branch_id' => $branch->id, 'medical_record_number' => 'DG-'.$branch->code.'-2019-0002']);
    $caseId = DB::table('trx_patient_merge_cases')->insertGetId([
        'uuid' => (string) Str::uuid(),
        'case_number' => 'LPAC-MERGE-'.$branch->id,
        'patient_a_id' => $source->id,
        'patient_b_id' => $canonical->id,
        'requested_by' => User::factory()->create()->id,
        'requested_at' => now(),
        'request_reason' => 'Legacy import alias fixture',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    PatientRmAlias::query()->create([
        'alias_medical_record_number' => $alias,
        'source_patient_id' => $source->id,
        'canonical_patient_id' => $canonical->id,
        'merge_case_id' => $caseId,
        'merged_at' => now(),
    ]);
}

// =============================================================================
// Legacy compatibility kept
// =============================================================================

it('LP-01 stores an imported legacy number verbatim and leaves existing patients untouched', function (): void {
    $existing = Patient::factory()->create(['branch_id' => $this->sunu->id, 'medical_record_number' => 'DG-SUN4-2025-0003']);

    $batch = $this->service->commit(lpacStage([lpacRow(['manual_rm_number' => '0042', 'name' => 'Impor Verbatim'])]), null);

    expect($batch->committed_rows)->toBe(1)
        ->and(Patient::query()->where('name', 'Impor Verbatim')->value('medical_record_number'))->toBe('DG-SPN4-2026-0042')
        ->and($existing->refresh()->medical_record_number)->toBe('DG-SUN4-2025-0003');
});

it('LP-02 lets the next automatic number step over an imported one: never reissued', function (): void {
    PatientRmSequence::query()->create([
        'branch_id' => $this->sunu->id, 'year' => 2026, 'last_number' => 41,
        'seed_source' => PatientRmSequence::SOURCE_COMMAND, 'seeded_at' => now(),
    ]);

    $this->service->commit(lpacStage([lpacRow(['manual_rm_number' => '42'])]), null);

    expect(lpacRegister($this->sunu, 'Otomatis Setelah Impor')->medical_record_number)->toBe('DG-SPN4-2026-0043');
});

// =============================================================================
// One uniqueness question, in every spelling
// =============================================================================

it('LP-03 refuses at staging a number already issued under the historical alias code or another padding', function (): void {
    Patient::factory()->create(['branch_id' => $this->sunu->id, 'medical_record_number' => 'DG-SUN4-2026-0007']);
    Patient::factory()->create(['branch_id' => $this->sunu->id, 'medical_record_number' => 'DG-SPN4-2026-12']);

    $batch = lpacStage([
        lpacRow(['manual_rm_number' => '7']),
        lpacRow(['manual_rm_number' => '000012']),
        lpacRow(['manual_rm_number' => '13']),
    ]);

    expect($batch->error_rows)->toBe(2)
        ->and($batch->valid_rows + $batch->warning_rows)->toBe(1)
        ->and(implode(' ', lpacErrors($batch)))->toContain('sudah digunakan');
});

it('LP-04 refuses a number held in another letter case, by a soft-deleted patient or by an active merge alias', function (): void {
    Patient::factory()->create(['branch_id' => $this->sunu->id, 'medical_record_number' => 'dg-spn4-2026-0021']);
    Patient::factory()->create(['branch_id' => $this->sunu->id, 'medical_record_number' => 'DG-SPN4-2026-0022'])->delete();
    lpacAlias($this->sunu, 'DG-SUN4-2026-23');

    $batch = lpacStage([
        lpacRow(['manual_rm_number' => '21']),
        lpacRow(['manual_rm_number' => '22']),
        lpacRow(['manual_rm_number' => '0023']),
    ]);

    expect($batch->error_rows)->toBe(3);
});

it('LP-05 treats two spellings of one number inside the same file as a duplicate', function (): void {
    $batch = lpacStage([
        lpacRow(['manual_rm_number' => '7']),
        lpacRow(['manual_rm_number' => '0007']),
    ]);

    expect($batch->error_rows)->toBe(1)
        ->and(implode(' ', lpacErrors($batch)))->toContain('duplikat di dalam file');
});

it('LP-06 refuses the whole batch at confirmation when an automatic registration took the number in another spelling', function (): void {
    $batch = lpacStage([
        lpacRow(['manual_rm_number' => '1', 'name' => 'Impor A']),
        lpacRow(['manual_rm_number' => '0002', 'name' => 'Impor B']),
    ]);
    expect($batch->error_rows)->toBe(0);

    // Between preview and confirmation the allocator issues DG-SPN4-2026-0001.
    expect(lpacRegister($this->sunu, 'Otomatis Dulu')->medical_record_number)->toBe('DG-SPN4-2026-0001');

    expect(fn () => $this->service->commit($batch, null))->toThrow(LegacyPatientImportBlockedException::class);

    expect(Patient::query()->whereIn('name', ['Impor A', 'Impor B'])->exists())->toBeFalse()
        ->and($batch->refresh()->error_rows)->toBe(1);
});

it('LP-07 claims an existing number only under its branch-year counter lock, in every spelling', function (): void {
    Patient::factory()->create(['branch_id' => $this->sunu->id, 'medical_record_number' => 'DG-SUN4-2026-31']);
    $allocator = app(PatientRmNumberAllocator::class);

    [$taken, $free] = DB::transaction(fn (): array => [
        $allocator->claimExisting($this->sunu, 'DG-SPN4-2026-0031'),
        $allocator->claimExisting($this->sunu, 'DG-SPN4-2026-0032'),
    ]);

    expect($taken)->toBeFalse()
        ->and($free)->toBeTrue()
        // The claim locked (and so lazily created) the branch-year counter row.
        ->and(PatientRmSequence::query()->where('branch_id', $this->sunu->id)->where('year', 2026)->exists())->toBeTrue()
        ->and(fn () => DB::transaction(fn () => $allocator->claimExisting($this->sunu, 'DG-TLK1-2026-0033')))->toThrow(InvalidArgumentException::class);
});

it('LP-08 never touches another branch-year: an import into a past year leaves the current counter alone', function (): void {
    $this->service->commit(lpacStage([lpacRow(['manual_rm_number' => '0500', 'timestamp' => '2024-08-01'])]), null);

    expect(Patient::query()->where('medical_record_number', 'DG-SPN4-2024-0500')->exists())->toBeTrue()
        ->and(PatientRmSequence::query()->where('branch_id', $this->sunu->id)->where('year', 2026)->exists())->toBeFalse()
        ->and(lpacRegister($this->sunu, 'Tahun Berjalan')->medical_record_number)->toBe('DG-SPN4-2026-0001');
});

// =============================================================================
// Review fixes (PR-2 review, 2026-11-17)
// =============================================================================

it('LP-09 refuses at staging a manual number zero-padded past nine digits, or longer than eighteen digits (F1)', function (string $manual): void {
    $batch = lpacStage([lpacRow(['manual_rm_number' => $manual, 'name' => 'Pasien Padding'])]);

    expect($batch->error_rows)->toBe(1)
        ->and(Patient::query()->where('name', 'Pasien Padding')->exists())->toBeFalse();
})->with([
    '10 digits, value 6' => ['0000000006'],
    '12 digits, value 6' => ['000000000006'],
    '19 digits' => ['1000000000000000006'],
    '19 digits, value 6' => [str_repeat('0', 18).'6'],
]);

it('LP-10 keeps a wide number that can never be issued (value past eight digits) importable', function (): void {
    $batch = lpacStage([lpacRow(['manual_rm_number' => '1234567890', 'name' => 'Pasien Nomor Panjang'])]);

    expect($batch->error_rows)->toBe(0);
});

it('LP-11 agrees with isTaken() for numbers padded past nine digits (F1)', function (string $padding): void {
    Patient::factory()->create(['branch_id' => $this->sunu->id, 'medical_record_number' => 'DG-SUN4-2026-'.$padding.'7']);
    $allocator = app(PatientRmNumberAllocator::class);

    expect($allocator->isTaken('DG-SPN4-2026-0007'))->toBeTrue()
        ->and($allocator->takenAmong(['DG-SPN4-2026-0007']))->toBe(['DG-SPN4-2026-0007' => true]);

    $batch = lpacStage([lpacRow(['manual_rm_number' => '7'])]);

    expect($batch->error_rows)->toBe(1)
        ->and($this->service->revalidate($batch, null)->error_rows)->toBe(1);
})->with([
    '10 digits' => [str_repeat('0', 9)],
    '12 digits' => [str_repeat('0', 11)],
    '19 digits' => [str_repeat('0', 18)],
]);

it('LP-12 continues after the highest: a committed import raises the counter (owner default 3)', function (): void {
    PatientRmSequence::query()->create([
        'branch_id' => $this->sunu->id, 'year' => 2026, 'last_number' => 10,
        'seed_source' => PatientRmSequence::SOURCE_COMMAND, 'seeded_at' => now(),
    ]);

    $this->service->commit(lpacStage([lpacRow(['manual_rm_number' => '0050', 'name' => 'Impor Tinggi'])]), null);

    expect((int) PatientRmSequence::query()->where('branch_id', $this->sunu->id)->where('year', 2026)->value('last_number'))->toBe(50)
        ->and(lpacRegister($this->sunu, 'Otomatis Setelah Impor Tinggi')->medical_record_number)->toBe('DG-SPN4-2026-0051');
});

it('LP-13 never raises the counter for a number the allocator could never issue (nine digits and up)', function (): void {
    PatientRmSequence::query()->create([
        'branch_id' => $this->sunu->id, 'year' => 2026, 'last_number' => 10,
        'seed_source' => PatientRmSequence::SOURCE_COMMAND, 'seeded_at' => now(),
    ]);

    $this->service->commit(lpacStage([lpacRow(['manual_rm_number' => '123456789', 'name' => 'Impor Raksasa'])]), null);

    expect(Patient::query()->where('name', 'Impor Raksasa')->value('medical_record_number'))->toBe('DG-SPN4-2026-123456789')
        ->and((int) PatientRmSequence::query()->where('branch_id', $this->sunu->id)->where('year', 2026)->value('last_number'))->toBe(10)
        ->and(lpacRegister($this->sunu, 'Otomatis Sesudah Raksasa')->medical_record_number)->toBe('DG-SPN4-2026-0011');
});
