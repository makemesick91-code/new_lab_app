<?php

use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\Odontogram\Models\Odontogram;
use App\Modules\Patient\Models\Patient;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use App\Modules\RmeInvoice\Models\RmePayment;
use App\Modules\Tariff\Models\Tariff;
use App\Modules\Treatment\Models\Treatment;
use App\Modules\TreatmentCategory\Models\TreatmentCategory;
use App\Support\PilotImport\PilotBackupImportService;
use App\Support\PilotImport\PostgresCopyDumpReader;
use Database\Seeders\BranchSeeder;
use Illuminate\Support\Facades\Artisan;

beforeEach(function () {
    $this->seed(BranchSeeder::class);
    $this->fixture = base_path('tests/Fixtures/pilot_backup_sample.sql');
    $this->reader = app(PostgresCopyDumpReader::class);
    $this->importService = app(PilotBackupImportService::class);
});

function pilotExtract(string $fixture): array
{
    return app(PostgresCopyDumpReader::class)->read($fixture);
}

it('parser only reads whitelisted copy tables', function () {
    $extracted = pilotExtract($this->fixture);

    expect($extracted['whitelisted_row_counts']['mst_branches'])->toBe(1)
        ->and($extracted['whitelisted_row_counts']['mst_doctors'])->toBe(2)
        ->and($extracted['whitelisted_row_counts']['mst_patients'])->toBe(2)
        ->and($extracted['whitelisted_row_counts']['mst_lab_services'])->toBe(2)
        ->and($extracted['tables']['mst_branches'][0]['code'])->toBe('PILOT-BR')
        ->and($extracted['tables']['mst_doctors'][0]['code'])->toBe('DOC-PILOT01');
});

it('protected tables are ignored by parser', function () {
    $extracted = pilotExtract($this->fixture);

    expect($extracted['skipped_tables'])->toHaveKeys(['roles', 'trx_payments'])
        ->and($extracted['skipped_tables']['roles'])->toBe(1)
        ->and($extracted['skipped_tables']['trx_payments'])->toBe(1)
        ->and($extracted['tables'])->not->toHaveKey('roles')
        ->and($extracted['tables'])->not->toHaveKey('trx_payments');
});

it('command rejects missing file', function () {
    Artisan::call('rme:import-pilot-backup', [
        '--file' => 'storage/app/imports/does-not-exist.sql',
    ]);

    expect(Artisan::output())->toContain('Backup file not found');
});

it('dry-run does not write data', function () {
    $before = [
        'branches' => Branch::count(),
        'doctors' => Doctor::count(),
        'patients' => Patient::count(),
        'treatments' => Treatment::count(),
        'tariffs' => Tariff::count(),
    ];

    Artisan::call('rme:import-pilot-backup', [
        '--file' => $this->fixture,
        '--dry-run' => true,
    ]);

    expect(Artisan::output())->toContain('DRY RUN')
        ->and(Branch::count())->toBe($before['branches'])
        ->and(Doctor::count())->toBe($before['doctors'])
        ->and(Patient::count())->toBe($before['patients'])
        ->and(Treatment::count())->toBe($before['treatments'])
        ->and(Tariff::count())->toBe($before['tariffs']);
});

it('branch import uses updateOrCreate and avoids duplicate', function () {
    Branch::factory()->create(['code' => 'PILOT-BR', 'name' => 'Existing Branch']);

    $extracted = pilotExtract($this->fixture);
    $this->importService->import($extracted, dryRun: false, only: 'branches');

    expect(Branch::query()->where('code', 'PILOT-BR')->count())->toBe(1);
});

it('doctor import avoids duplicate', function () {
    $extracted = pilotExtract($this->fixture);

    $this->importService->import($extracted, dryRun: false, only: 'doctors');
    $this->importService->import($extracted, dryRun: false, only: 'doctors');

    expect(Doctor::query()->where('code', 'DOC-PILOT01')->count())->toBe(1)
        ->and(Doctor::query()->where('code', 'DOC-PILOT02')->count())->toBe(1);
});

it('patient import avoids duplicate', function () {
    $extracted = pilotExtract($this->fixture);

    $this->importService->import($extracted, dryRun: false, only: 'doctors,patients');
    $this->importService->import($extracted, dryRun: false, only: 'doctors,patients');

    expect(Patient::query()->where('medical_record_number', 'MRN-PILOT001')->count())->toBe(1)
        ->and(Patient::query()->where('medical_record_number', 'MRN-PILOT002')->count())->toBe(1);
});

it('lab services create treatments categories and tariffs', function () {
    $extracted = pilotExtract($this->fixture);

    $this->importService->import($extracted, dryRun: false, only: 'treatments');

    $scaling = Treatment::query()->where('code', 'SVC-PILOT01')->first();
    $crown = Treatment::query()->where('code', 'SVC-PILOT02')->first();

    expect($scaling)->not->toBeNull()
        ->and($crown)->not->toBeNull()
        ->and($scaling->requires_lab)->toBeFalse()
        ->and($crown->requires_lab)->toBeTrue()
        ->and(TreatmentCategory::query()->where('name', 'General')->exists())->toBeTrue()
        ->and(TreatmentCategory::query()->where('name', 'Fixed')->exists())->toBeTrue()
        ->and(Tariff::query()->where('treatment_id', $scaling->id)->exists())->toBeTrue()
        ->and(Tariff::query()->where('treatment_id', $crown->id)->exists())->toBeTrue();
});

it('import does not create clinic visits medical records odontograms or rme billing', function () {
    $extracted = pilotExtract($this->fixture);

    $this->importService->import($extracted, dryRun: false);

    expect(ClinicVisit::count())->toBe(0)
        ->and(MedicalRecord::count())->toBe(0)
        ->and(Odontogram::count())->toBe(0)
        ->and(RmeInvoice::count())->toBe(0)
        ->and(RmePayment::count())->toBe(0);
});

it('re-running import is idempotent', function () {
    $extracted = pilotExtract($this->fixture);

    $this->importService->import($extracted, dryRun: false);
    $countsAfterFirst = [
        'branches' => Branch::query()->where('code', 'PILOT-BR')->count(),
        'doctors' => Doctor::count(),
        'patients' => Patient::count(),
        'treatments' => Treatment::count(),
        'tariffs' => Tariff::count(),
    ];

    $this->importService->import($extracted, dryRun: false);

    expect(Branch::query()->where('code', 'PILOT-BR')->count())->toBe($countsAfterFirst['branches'])
        ->and(Doctor::count())->toBe($countsAfterFirst['doctors'])
        ->and(Patient::count())->toBe($countsAfterFirst['patients'])
        ->and(Treatment::count())->toBe($countsAfterFirst['treatments'])
        ->and(Tariff::count())->toBe($countsAfterFirst['tariffs']);
});

it('limit option restricts imported rows', function () {
    $extracted = pilotExtract($this->fixture);

    $result = $this->importService->import(
        $extracted,
        dryRun: false,
        only: 'doctors',
        limit: 1,
    );

    expect(Doctor::count())->toBe(1)
        ->and($result->imported['mst_doctors'] ?? 0)->toBe(1);
});
