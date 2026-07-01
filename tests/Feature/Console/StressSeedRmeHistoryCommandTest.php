<?php

use App\Modules\Branch\Models\Branch;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Sprint 68.7 — RME History Stress Seeder Foundation.
 *
 * These tests run in APP_ENV=testing, one of the allowed stress environments.
 */
function seedRmeHistoryStressFoundation(string $branchCode = 'TST'): Branch
{
    $now = now();

    $branch = Branch::factory()->create([
        'code' => $branchCode,
        'name' => 'Stress Test Branch',
        'is_rme_enabled' => true,
    ]);

    DB::table('mst_clinic_rooms')->insert([
        'branch_id' => $branch->id,
        'code' => 'TST-RM-01',
        'name' => 'Stress Ruang Periksa 01',
        'type' => 'doctor',
        'status' => 'active',
        'description' => 'Dummy room for stress testing only.',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $adminId = DB::table('users')->insertGetId([
        'name' => 'Stress Admin Klinik 001',
        'email' => 'stress.admin001@daengtisia.test',
        'password' => Hash::make('Password123!'),
        'email_verified_at' => $now,
        'phone' => '080000000001',
        'is_active' => true,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $cashierId = DB::table('users')->insertGetId([
        'name' => 'Stress Kasir 001',
        'email' => 'stress.cashier001@daengtisia.test',
        'password' => Hash::make('Password123!'),
        'email_verified_at' => $now,
        'phone' => '080000000002',
        'is_active' => true,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $doctorUserId = DB::table('users')->insertGetId([
        'name' => 'Stress Doctor 001',
        'email' => 'stress.doctor001@daengtisia.test',
        'password' => Hash::make('Password123!'),
        'email_verified_at' => $now,
        'phone' => '080000000003',
        'is_active' => true,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $doctorId = DB::table('mst_doctors')->insertGetId([
        'clinic_id' => null,
        'code' => 'TST-DOC-001',
        'name' => 'Stress Doctor 001',
        'phone' => '080000000003',
        'email' => 'stress.doctor001@daengtisia.test',
        'is_active' => true,
        'user_id' => $doctorUserId,
        'branch_id' => $branch->id,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    DB::table('mst_doctor_branches')->insert([
        'doctor_id' => $doctorId,
        'branch_id' => $branch->id,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $categoryId = DB::table('mst_treatment_categories')->insertGetId([
        'code' => 'TST-KONS',
        'name' => 'Stress Konsultasi',
        'description' => 'Dummy category for stress testing only.',
        'sort_order' => 1,
        'is_active' => true,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $treatmentId = DB::table('mst_treatments')->insertGetId([
        'treatment_category_id' => $categoryId,
        'code' => 'TST-CHECKUP',
        'name' => 'Stress Pemeriksaan Gigi',
        'description' => 'Dummy treatment for stress testing only.',
        'default_duration_minutes' => 30,
        'requires_doctor' => true,
        'requires_room' => true,
        'requires_lab' => false,
        'is_active' => true,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    DB::table('mst_tariffs')->insert([
        'branch_id' => $branch->id,
        'treatment_id' => $treatmentId,
        'price' => 75000,
        'effective_date' => '2026-01-01',
        'is_active' => true,
        'notes' => 'Dummy tariff for stress testing only.',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    DB::table('mst_payment_methods')->insert([
        'code' => 'TST-CASH',
        'name' => 'Stress Cash',
        'type' => 'cash',
        'description' => 'Dummy payment method for stress testing only.',
        'sort_order' => 1,
        'is_active' => true,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return $branch;
}

function seedRmeHistoryStressPatients(Branch $branch, int $count = 10): void
{
    $now = now();

    for ($i = 1; $i <= $count; $i++) {
        $manualRm = str_pad((string) $i, 8, '0', STR_PAD_LEFT);

        DB::table('mst_patients')->insert([
            'clinic_id' => null,
            'doctor_id' => null,
            'medical_record_number' => "DG-{$branch->code}-2026-{$manualRm}",
            'name' => 'Stress Patient '.$manualRm,
            'gender' => $i % 2 === 0 ? 'female' : 'male',
            'date_of_birth' => now()->subYears(25)->toDateString(),
            'phone' => '08'.str_pad((string) (7000000000 + $i), 10, '0', STR_PAD_LEFT),
            'address' => 'Alamat Dummy Stress Test No. '.$i,
            'is_active' => true,
            'ktp_number' => '99'.str_pad((string) $i, 14, '0', STR_PAD_LEFT),
            'whatsapp_number' => '08'.str_pad((string) (8000000000 + $i), 10, '0', STR_PAD_LEFT),
            'email' => 'stress.patient'.$manualRm.'@daengtisia.test',
            'occupation' => 'Stress Test Dummy',
            'branch_id' => $branch->id,
            'registered_at' => now()->subDays($i)->toDateString(),
            'manual_rm_number' => $manualRm,
            'import_batch_id' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}

it('blocks rme history seeding in pilot and production environments', function () {
    seedRmeHistoryStressFoundation();
    seedRmeHistoryStressPatients(Branch::where('code', 'TST')->first(), 5);

    foreach (['pilot', 'production'] as $env) {
        app()['env'] = $env;

        $this->artisan('stress:seed-rme-history', ['--target' => 3, '--chunk-size' => 2])
            ->expectsOutputToContain('only runs in local, stress, or testing')
            ->assertExitCode(1);
    }

    app()['env'] = 'testing';

    expect(DB::table('trx_clinic_visits')->count())->toBe(0);
});

it('clamps an oversized chunk size below the PostgreSQL parameter limit', function () {
    $branch = seedRmeHistoryStressFoundation();
    seedRmeHistoryStressPatients($branch, 5);

    $this->artisan('stress:seed-rme-history', ['--target' => 2, '--chunk-size' => 999999])
        ->expectsOutputToContain('Effective chunk size    : 2000')
        ->expectsOutputToContain('clamped to safe chunk [2000]')
        ->assertExitCode(0);
});

it('seeds rme history with a small chunk size', function () {
    $branch = seedRmeHistoryStressFoundation();
    seedRmeHistoryStressPatients($branch, 10);

    $this->artisan('stress:seed-rme-history', [
        '--target' => 5,
        '--chunk-size' => 2,
        '--patients' => 5,
    ])->assertExitCode(0);

    expect(DB::table('trx_clinic_visits')->where('visit_number', 'like', 'TST-VIS-2026-%')->count())->toBe(5);
    expect(DB::table('trx_medical_records')->count())->toBe(5);
    expect(DB::table('trx_rme_invoices')->count())->toBe(5);
    expect(DB::table('trx_rme_payments')->count())->toBeGreaterThan(0);
});

it('is idempotent on rerun', function () {
    $branch = seedRmeHistoryStressFoundation();
    seedRmeHistoryStressPatients($branch, 10);

    $this->artisan('stress:seed-rme-history', ['--target' => 5, '--chunk-size' => 2, '--patients' => 5])->assertExitCode(0);
    $this->artisan('stress:seed-rme-history', ['--target' => 5, '--chunk-size' => 2, '--patients' => 5])->assertExitCode(0);

    expect(DB::table('trx_clinic_visits')->where('visit_number', 'like', 'TST-VIS-2026-%')->count())->toBe(5);
});

it('validates the plan without writing rows in dry-run mode', function () {
    $branch = seedRmeHistoryStressFoundation();
    seedRmeHistoryStressPatients($branch, 5);

    $this->artisan('stress:seed-rme-history', [
        '--target' => 4,
        '--chunk-size' => 999999,
        '--dry-run' => true,
    ])
        ->expectsOutputToContain('Effective chunk size    : 2000')
        ->expectsOutputToContain('[dry-run] Plan validated. No rows written.')
        ->assertExitCode(0);

    expect(DB::table('trx_clinic_visits')->count())->toBe(0);
});

it('fails clearly when foundation data is missing', function () {
    Branch::factory()->create(['code' => 'TST', 'is_rme_enabled' => true]);
    seedRmeHistoryStressPatients(Branch::where('code', 'TST')->first(), 3);

    $this->artisan('stress:seed-rme-history', ['--target' => 3, '--chunk-size' => 2])
        ->expectsOutputToContain('Required stress users not found')
        ->assertExitCode(1);
});

it('normalizes invoice ratios that do not sum to 100', function () {
    $branch = seedRmeHistoryStressFoundation();
    seedRmeHistoryStressPatients($branch, 10);

    $this->artisan('stress:seed-rme-history', [
        '--target' => 10,
        '--chunk-size' => 5,
        '--patients' => 10,
        '--paid-ratio' => 40,
        '--partial-ratio' => 30,
        '--unpaid-ratio' => 20,
    ])
        ->expectsOutputToContain('Invoice ratios sum to 90; normalizing to 100.')
        ->assertExitCode(0);

    expect(DB::table('trx_rme_invoices')->count())->toBe(10);
});

it('requires force for very large targets', function () {
    seedRmeHistoryStressFoundation();

    $this->artisan('stress:seed-rme-history', ['--target' => 100001])
        ->expectsOutputToContain('Re-run with --force')
        ->assertExitCode(1);
});
