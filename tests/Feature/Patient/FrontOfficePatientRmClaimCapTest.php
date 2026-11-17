<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| DMS-RME-COMPREHENSIVE-CLOSURE-1 / memo M4.1 (rule 244 §2.6) — the Nomor RM
| claim cap (owner decision 2026-11-17, "RM CLAIM CAP: refuse jump > +1000")
|--------------------------------------------------------------------------
|
| A typed number (old paper card, legacy CSV commit, pilot backup import) for
| the CURRENT clinical year — or a later one, whose counter becomes current —
| may raise that branch-year counter by at most +1000 and never above 9999. A
| claim that would exceed either, or a number beyond the format capacity (a
| sequence value of nine digits and up, or zero) in ANY year, is REFUSED with
| an Indonesian message that sends the case to Supervisor RME; nothing is
| accepted automatically and no counter moves. A past-year claim only ever
| touches that year's counter. Numbers are still never reused: the allocator's
| skip and the spelling-aware "taken" check are unchanged. The race between two
| claims is in ClinicVisitRegistrationConcurrencyPatientRmSequenceTest
| (PostgreSQL).
|
| Selected by the existing `FrontOffice` critical-gate token in both runner
| variants and declared in critical_gate_mandatory_suites (CG-19). Helper prefix
| `frcc` is unique because the critical gate loads every suite into one process.
*/

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\Clinic\Models\Clinic;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\Patient\Exceptions\LegacyPatientImportBlockedException;
use App\Modules\Patient\Exceptions\PatientRmNumberUnavailableException;
use App\Modules\Patient\Models\LegacyPatientImportBatch;
use App\Modules\Patient\Models\Patient;
use App\Modules\Patient\Models\PatientRmSequence;
use App\Modules\Patient\Services\LegacyPatientImportService;
use App\Modules\Patient\Services\PatientRmNumberAllocator;
use App\Modules\Patient\Services\PatientService;
use App\Support\PilotImport\PilotBackupImportService;
use Database\Seeders\BranchSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

beforeEach(function (): void {
    test()->seed(BranchSeeder::class);
    seedAccessControl();
    Storage::fake('local');
    pinTestClock('2026-06-13 01:00:00'); // 09:00 WITA, clinical year 2026

    Branch::query()->where('code', Branch::MAIN_CODE)->update(['is_rme_enabled' => false]);

    $this->clinic = Clinic::factory()->create();
    $this->doctor = Doctor::factory()->create(['clinic_id' => $this->clinic->id]);
    $this->sunu = Branch::factory()->create(['code' => 'SPN4', 'name' => 'Cabang Sunu', 'is_active' => true, 'is_rme_enabled' => true]);
    $this->supervisor = User::factory()->create();
    $this->supervisor->assignRole('Supervisor RME');
});

afterEach(fn () => freeTestClock());

function frccCounter(int $year, int $last): void
{
    PatientRmSequence::query()->create([
        'branch_id' => test()->sunu->id, 'year' => $year, 'last_number' => $last,
        'seed_source' => PatientRmSequence::SOURCE_COMMAND, 'seeded_at' => now(),
    ]);
}

function frccCounterValue(int $year): ?int
{
    $value = PatientRmSequence::query()->where('branch_id', test()->sunu->id)->where('year', $year)->value('last_number');

    return $value === null ? null : (int) $value;
}

/** A Supervisor RME paper-card registration through the real service. */
function frccPaperCard(string $year, string $sequence, string $name): Patient
{
    return app(PatientService::class)->createWithPaperCardNumber(
        ['clinic_id' => test()->clinic->id, 'doctor_id' => test()->doctor->id, 'branch_id' => test()->sunu->id, 'name' => $name, 'is_active' => 1],
        test()->supervisor,
        (int) $year,
        $sequence,
        'KARTU_LAMA_PASIEN_LAMA',
        null,
    );
}

/** The refusal a paper-card registration answers with, or null when it was accepted. */
function frccPaperCardRefusal(string $year, string $sequence, string $name): ?string
{
    try {
        frccPaperCard($year, $sequence, $name);
    } catch (ValidationException $e) {
        return implode(' ', $e->errors()['paper_card_sequence'] ?? ['(another field) '.json_encode($e->errors())]);
    }

    return null;
}

function frccAutomatic(string $name): Patient
{
    return app(PatientService::class)->create([
        'clinic_id' => test()->clinic->id,
        'doctor_id' => test()->doctor->id,
        'branch_id' => test()->sunu->id,
        'name' => $name,
        'is_active' => true,
    ]);
}

/** claimExisting() inside its own transaction; the refusal, when there is one. */
function frccClaimRefusal(string $number): ?PatientRmNumberUnavailableException
{
    try {
        DB::transaction(fn () => app(PatientRmNumberAllocator::class)->claimExisting(test()->sunu, $number));
    } catch (PatientRmNumberUnavailableException $e) {
        return $e;
    }

    return null;
}

function frccStage(array $rows): LegacyPatientImportBatch
{
    $keys = array_map(fn ($c) => $c['key'], LegacyPatientImportService::COLUMNS);
    $header = array_map(fn ($c) => $c['label'], LegacyPatientImportService::COLUMNS);

    $handle = fopen('php://temp', 'r+');
    fputcsv($handle, $header);
    foreach ($rows as $row) {
        $row += ['branch' => 'SPN4', 'timestamp' => '2026-02-10', 'gender' => 'Perempuan'];
        fputcsv($handle, array_map(fn ($k) => $row[$k] ?? '', $keys));
    }
    rewind($handle);
    $path = tempArtifactFile('frcc');
    file_put_contents($path, stream_get_contents($handle));
    fclose($handle);

    return app(LegacyPatientImportService::class)
        ->parseAndStage(new UploadedFile($path, 'legacy.csv', 'text/csv', null, true), null);
}

// =============================================================================
// The cap on the current clinical year
// =============================================================================

it('CC-01 accepts a current-year claim exactly +1000 above the counter and continues after it', function (): void {
    frccCounter(2026, 10);

    expect(frccPaperCard('2026', '1010', 'Kartu Batas Atas')->medical_record_number)->toBe('DG-SPN4-2026-1010')
        ->and(frccCounterValue(2026))->toBe(1010)
        ->and(frccAutomatic('Otomatis Sesudah Batas')->medical_record_number)->toBe('DG-SPN4-2026-1011');
});

it('CC-02 refuses a current-year claim more than +1000 above the counter, escalating to Supervisor RME without the number', function (): void {
    frccCounter(2026, 10);

    $message = frccPaperCardRefusal('2026', '1011', 'Kartu Lompat Jauh');

    expect($message)->not->toBeNull()
        ->and($message)->toContain('Supervisor RME')
        ->and($message)->toContain('1000')
        ->and($message)->not->toContain('1011')
        ->and(Patient::query()->where('name', 'Kartu Lompat Jauh')->exists())->toBeFalse()
        // The counter stays, and automatic registration carries on from it.
        ->and(frccCounterValue(2026))->toBe(10)
        ->and(frccAutomatic('Otomatis Sesudah Tolak')->medical_record_number)->toBe('DG-SPN4-2026-0011');
});

it('CC-03 answers the refusal over HTTP as a 422 on the sequence field, writing nothing', function (): void {
    frccCounter(2026, 10);

    $this->actingAs($this->supervisor)
        ->post(route('settings.patients.store'), [
            'clinic_id' => $this->clinic->id,
            'doctor_id' => $this->doctor->id,
            'branch_id' => $this->sunu->id,
            'name' => 'Kartu Lompat Http',
            'gender' => 'Female',
            'is_active' => 1,
            'paper_card_year' => '2026',
            'paper_card_sequence' => '5000',
            'paper_card_reason_code' => 'KARTU_LAMA_PASIEN_LAMA',
        ])
        ->assertSessionHasErrors('paper_card_sequence');

    expect(Patient::query()->where('name', 'Kartu Lompat Http')->exists())->toBeFalse()
        ->and(frccCounterValue(2026))->toBe(10);
});

it('CC-04 never raises the current-year counter above 9999; automatic numbering continues past it (counter exhaustion)', function (): void {
    frccCounter(2026, 9500);

    // +500 and +900 are inside the jump cap, but above the four-digit range.
    expect(frccPaperCardRefusal('2026', '10000', 'Kartu Lima Digit'))->toContain('Supervisor RME')
        ->and(frccPaperCardRefusal('2026', '10400', 'Kartu Lima Digit Lagi'))->toContain('Supervisor RME')
        ->and(frccCounterValue(2026))->toBe(9500);

    // The last four-digit number is still claimable…
    expect(frccPaperCard('2026', '9999', 'Kartu Terakhir')->medical_record_number)->toBe('DG-SPN4-2026-9999')
        ->and(frccCounterValue(2026))->toBe(9999);

    // …and the automatic number widens past it, as it always has.
    expect(frccAutomatic('Otomatis Lima Digit')->medical_record_number)->toBe('DG-SPN4-2026-10000');
});

it('CC-05 refuses an eight-digit current-year claim, even on the first claim of the year', function (): void {
    expect(frccPaperCardRefusal('2026', '12345678', 'Kartu Delapan Digit'))->toContain('Supervisor RME')
        ->and(frccPaperCardRefusal('2026', '1001', 'Kartu Pertama Lompat'))->toContain('Supervisor RME')
        ->and(Patient::query()->count())->toBe(0)
        ->and(frccCounterValue(2026))->toBeNull();

    // A first claim within +1000 of the lazily seeded counter (0) is accepted.
    expect(frccPaperCard('2026', '1000', 'Kartu Pertama')->medical_record_number)->toBe('DG-SPN4-2026-1000')
        ->and(frccCounterValue(2026))->toBe(1000);
});

it('CC-06 treats a claim at or below the counter as no jump: accepted, the counter stays', function (): void {
    frccCounter(2026, 5000);

    expect(frccPaperCard('2026', '0020', 'Kartu Di Bawah')->medical_record_number)->toBe('DG-SPN4-2026-0020')
        ->and(frccCounterValue(2026))->toBe(5000);
});

it('CC-07 refuses a claim for a later year outright, whatever its value (review A4)', function (string $number): void {
    $refusal = frccClaimRefusal($number);

    expect(PatientRmNumberAllocator::isClaimRefusal($refusal ?? new RuntimeException))->toBeTrue()
        ->and($refusal?->getMessage())->toContain('Supervisor RME')
        ->and(frccCounterValue(2027))->toBeNull();
})->with([
    'within the jump' => ['DG-SPN4-2027-0900'],
    'past the jump' => ['DG-SPN4-2027-5000'],
    'not all digits' => ['DG-SPN4-2027-12A'],
]);

// =============================================================================
// Past years and the format capacity
// =============================================================================

it('CC-08 lets a past-year eight-digit claim through, moving only that year\'s counter', function (): void {
    frccCounter(2026, 7);

    expect(frccPaperCard('2020', '12345678', 'Kartu Lama Delapan Digit')->medical_record_number)->toBe('DG-SPN4-2020-12345678')
        ->and(frccCounterValue(2020))->toBe(12345678)
        ->and(frccCounterValue(2026))->toBe(7)
        ->and(frccAutomatic('Otomatis Tahun Berjalan')->medical_record_number)->toBe('DG-SPN4-2026-0008');
});

it('CC-09 refuses a number beyond the format capacity in any year, before any counter is touched', function (string $number): void {
    $refusal = frccClaimRefusal($number);

    expect($refusal)->not->toBeNull()
        ->and($refusal->getMessage())->toContain('Supervisor RME')
        ->and($refusal->getMessage())->not->toContain('123456789')
        ->and(PatientRmSequence::query()->count())->toBe(0);
})->with([
    'past year, nine digits' => ['DG-SPN4-2020-123456789'],
    'current year, nine digits' => ['DG-SPN4-2026-123456789'],
    'past year, zero' => ['DG-SPN4-2020-0000'],
    'past year, eighteen digits' => ['DG-SPN4-2020-123456789012345678'],
]);

// =============================================================================
// The legacy CSV commit and the pilot backup importer claim the same way
// =============================================================================

it('CC-10 refuses the whole legacy CSV batch when one current-year row jumps past the cap, naming the row', function (): void {
    frccCounter(2026, 10);
    $batch = frccStage([
        ['manual_rm_number' => '0500', 'name' => 'Impor Dalam Batas'],
        ['manual_rm_number' => '2000', 'name' => 'Impor Lompat Jauh'],
    ]);
    expect($batch->error_rows)->toBe(0);

    $refusal = null;
    try {
        app(LegacyPatientImportService::class)->commit($batch, legacyPatientCommitterId());
    } catch (LegacyPatientImportBlockedException $e) {
        $refusal = $e;
    }

    expect($refusal)->not->toBeNull()
        ->and($refusal->getMessage())->toContain('Baris 3') // row 1 is the header
        ->and($refusal->getMessage())->toContain('Supervisor RME')
        ->and($refusal->getMessage())->not->toContain('2000')
        ->and(Patient::query()->whereIn('name', ['Impor Dalam Batas', 'Impor Lompat Jauh'])->exists())->toBeFalse()
        ->and(frccCounterValue(2026))->toBe(10)
        ->and($batch->refresh()->isCommitted())->toBeFalse();
});

it('CC-11 caps the WHOLE CSV batch at +1000 from the counter it started from: stepping 1000, 2000 is refused at the row past it (owner "Per batch")', function (): void {
    frccCounter(2026, 10);
    $batch = frccStage([
        ['manual_rm_number' => '1000', 'name' => 'Impor Langkah Satu'],
        ['manual_rm_number' => '2000', 'name' => 'Impor Langkah Dua'],
        ['manual_rm_number' => '2500', 'name' => 'Impor Langkah Tiga'],
    ]);

    $refusal = null;
    try {
        app(LegacyPatientImportService::class)->commit($batch, legacyPatientCommitterId());
    } catch (LegacyPatientImportBlockedException $e) {
        $refusal = $e;
    }

    // Row 3 is +1990 from the batch's starting counter (10), although only +1000
    // from the counter the previous row left.
    expect($refusal)->not->toBeNull()
        ->and($refusal->getMessage())->toContain('Baris 3')
        ->and($refusal->getMessage())->toContain('Supervisor RME')
        ->and($refusal->getMessage())->not->toContain('2000')
        ->and(Patient::query()->where('name', 'like', 'Impor Langkah %')->exists())->toBeFalse()
        ->and(frccCounterValue(2026))->toBe(10);
});

it('CC-15 commits a CSV batch whose total raise stays within +1000 of its starting counter', function (): void {
    frccCounter(2026, 10);
    $batch = frccStage([
        ['manual_rm_number' => '0500', 'name' => 'Impor Total Satu'],
        ['manual_rm_number' => '1010', 'name' => 'Impor Total Dua'],
    ]);

    expect(app(LegacyPatientImportService::class)->commit($batch, legacyPatientCommitterId())->committed_rows)->toBe(2)
        ->and(frccCounterValue(2026))->toBe(1010);
});

it('CC-16 caps the WHOLE pilot run at +1000 from its starting counter, skipping the row past it (per-row skip)', function (): void {
    frccCounter(2026, 10);
    Doctor::factory()->create(['code' => 'FRCC-DOC']);

    $result = app(PilotBackupImportService::class)->import(['tables' => [
        'mst_doctors' => [['id' => '9', 'code' => 'FRCC-DOC', 'name' => 'Dokter Pilot']],
        'mst_patients' => [
            ['medical_record_number' => 'DG-SPN4-2026-0900', 'name' => 'Pilot Run Satu', 'doctor_id' => '9'],
            // +600 from the counter row one left, +1490 from the run's start.
            ['medical_record_number' => 'DG-SPN4-2026-1500', 'name' => 'Pilot Run Dua', 'doctor_id' => '9'],
            ['medical_record_number' => 'DG-SPN4-2026-1010', 'name' => 'Pilot Run Tiga', 'doctor_id' => '9'],
        ],
    ], 'skipped_tables' => [], 'whitelisted_row_counts' => []], dryRun: false, only: 'patients');

    expect(Patient::query()->where('name', 'Pilot Run Satu')->exists())->toBeTrue()
        ->and(Patient::query()->where('name', 'Pilot Run Dua')->exists())->toBeFalse()
        ->and(Patient::query()->where('name', 'Pilot Run Tiga')->exists())->toBeTrue()
        ->and($result->skipped['mst_patients'] ?? 0)->toBe(1)
        ->and(implode(' ', $result->messages))->toContain('Supervisor RME')
        ->and(implode(' ', $result->messages))->not->toContain('1500')
        ->and(frccCounterValue(2026))->toBe(1010);
});

it('CC-16b never creates a counter row for a refused later year during a live pilot run (re-review F3)', function (): void {
    frccCounter(2026, 10);
    Doctor::factory()->create(['code' => 'FRCC-DOC']);

    $result = app(PilotBackupImportService::class)->import(['tables' => [
        'mst_doctors' => [['id' => '9', 'code' => 'FRCC-DOC', 'name' => 'Dokter Pilot']],
        'mst_patients' => [
            ['medical_record_number' => 'DG-SPN4-2027-0005', 'name' => 'Pilot Tahun Depan', 'doctor_id' => '9'],
            ['medical_record_number' => 'DG-SPN4-2026-0020', 'name' => 'Pilot Tahun Ini', 'doctor_id' => '9'],
        ],
    ], 'skipped_tables' => [], 'whitelisted_row_counts' => []], dryRun: false, only: 'patients');

    expect(Patient::query()->where('name', 'Pilot Tahun Depan')->exists())->toBeFalse()
        ->and(Patient::query()->where('name', 'Pilot Tahun Ini')->exists())->toBeTrue()
        ->and($result->skipped['mst_patients'] ?? 0)->toBe(1)
        ->and(frccCounterValue(2027))->toBeNull()
        ->and(frccCounterValue(2026))->toBe(20);
});

it('CC-17 refuses a current-year sequence that is not all digits, and stays lenient for a past year (review A3)', function (): void {
    $current = frccClaimRefusal('DG-SPN4-2026-12A');

    expect(PatientRmNumberAllocator::isClaimRefusal($current ?? new RuntimeException))->toBeTrue()
        ->and(frccClaimRefusal('DG-SPN4-2020-12A'))->toBeNull()
        ->and(frccCounterValue(2026))->toBeNull();
});

it('CC-18 refuses at staging a CSV row whose registration year has not started yet (review A4)', function (): void {
    $batch = frccStage([['manual_rm_number' => '0007', 'name' => 'Impor Tahun Depan', 'timestamp' => '2027-01-05']]);

    expect($batch->error_rows)->toBe(1);
});

it('CC-19 keeps the paper card per claim: two cards each within +1000 of the counter are both accepted', function (): void {
    frccCounter(2026, 10);

    expect(frccPaperCard('2026', '1010', 'Kartu Per Klaim Satu')->medical_record_number)->toBe('DG-SPN4-2026-1010')
        ->and(frccPaperCard('2026', '2010', 'Kartu Per Klaim Dua')->medical_record_number)->toBe('DG-SPN4-2026-2010')
        ->and(frccCounterValue(2026))->toBe(2010);
});

it('CC-12 makes the pilot backup importer skip a current-year number past the cap, escalating without the number', function (): void {
    frccCounter(2026, 10);
    Doctor::factory()->create(['code' => 'FRCC-DOC']);

    $extracted = ['tables' => [
        'mst_doctors' => [['id' => '9', 'code' => 'FRCC-DOC', 'name' => 'Dokter Pilot']],
        'mst_patients' => [
            ['medical_record_number' => 'DG-SPN4-2026-5000', 'name' => 'Pilot Lompat', 'doctor_id' => '9'],
            ['medical_record_number' => 'DG-SPN4-2026-0500', 'name' => 'Pilot Wajar', 'doctor_id' => '9'],
            ['medical_record_number' => 'DG-SPN4-2019-123456789', 'name' => 'Pilot Kapasitas', 'doctor_id' => '9'],
        ],
    ], 'skipped_tables' => [], 'whitelisted_row_counts' => []];

    $result = app(PilotBackupImportService::class)->import($extracted, dryRun: false, only: 'patients');
    $messages = implode(' ', $result->messages);

    expect(Patient::query()->where('name', 'Pilot Lompat')->exists())->toBeFalse()
        ->and(Patient::query()->where('name', 'Pilot Kapasitas')->exists())->toBeFalse()
        ->and(Patient::query()->where('name', 'Pilot Wajar')->value('medical_record_number'))->toBe('DG-SPN4-2026-0500')
        ->and(frccCounterValue(2026))->toBe(500)
        ->and($messages)->toContain('Supervisor RME')
        ->and($messages)->not->toContain('DG-SPN4')
        ->and($messages)->not->toContain('5000');
});

it('CC-13 never reuses a number: a refused claim does not free or skip anything the allocator issues', function (): void {
    frccCounter(2026, 3);
    $first = frccAutomatic('Otomatis Satu');

    expect(frccPaperCardRefusal('2026', '9000', 'Kartu Tolak'))->toContain('Supervisor RME')
        // The issued number stays taken in every spelling.
        ->and(frccPaperCardRefusal('2026', '4', 'Kartu Ulang'))->not->toBeNull()
        ->and(frccAutomatic('Otomatis Dua')->medical_record_number)->toBe('DG-SPN4-2026-0005')
        ->and($first->medical_record_number)->toBe('DG-SPN4-2026-0004');
});

it('CC-14 refuses a number beyond the format capacity on every pilot import path: the dry run and a code with no branch', function (): void {
    Doctor::factory()->create(['code' => 'FRCC-DOC']);
    $extracted = ['tables' => [
        'mst_doctors' => [['id' => '9', 'code' => 'FRCC-DOC', 'name' => 'Dokter Pilot']],
        'mst_patients' => [
            ['medical_record_number' => 'DG-SPN4-2019-123456789', 'name' => 'Pilot Kapasitas Kering', 'doctor_id' => '9'],
            ['medical_record_number' => 'DG-ZQX9-2019-0000', 'name' => 'Pilot Tanpa Cabang Nol', 'doctor_id' => '9'],
        ],
    ], 'skipped_tables' => [], 'whitelisted_row_counts' => []];

    $dryRun = app(PilotBackupImportService::class)->import($extracted, dryRun: true, only: 'patients');
    $live = app(PilotBackupImportService::class)->import(['tables' => ['mst_patients' => [$extracted['tables']['mst_patients'][1]]] + $extracted['tables'], 'skipped_tables' => [], 'whitelisted_row_counts' => []], dryRun: false, only: 'patients');

    expect($dryRun->skipped['mst_patients'] ?? 0)->toBe(2)
        ->and(implode(' ', $dryRun->messages))->toContain('Supervisor RME')
        ->and($live->skipped['mst_patients'] ?? 0)->toBe(1)
        ->and(Patient::query()->where('name', 'like', 'Pilot % Nol')->exists())->toBeFalse()
        ->and(PatientRmSequence::query()->count())->toBe(0);
});
