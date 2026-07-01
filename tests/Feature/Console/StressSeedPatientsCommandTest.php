<?php

use App\Modules\Branch\Models\Branch;
use Illuminate\Support\Facades\DB;

/**
 * Sprint 68.5 — Stress Seeder Stabilization.
 *
 * These tests run in APP_ENV=testing, one of the allowed stress environments.
 */
function makeStressBranch(string $code = 'TST'): Branch
{
    return Branch::factory()->create(['code' => $code]);
}

it('blocks stress seeding in pilot and production environments', function () {
    makeStressBranch();

    foreach (['pilot', 'production'] as $env) {
        app()['env'] = $env;

        $this->artisan('stress:seed-patients', ['--target' => 3, '--chunk-size' => 2])
            ->expectsOutputToContain('only runs in local, stress, or testing')
            ->assertExitCode(1);
    }

    app()['env'] = 'testing';

    expect(DB::table('mst_patients')->count())->toBe(0);
});

it('clamps an oversized chunk size below the PostgreSQL parameter limit', function () {
    makeStressBranch();

    // 19 columns → floor(60000 / 19) = 3157 safe rows per insert.
    $this->artisan('stress:seed-patients', ['--target' => 2, '--chunk-size' => 999999])
        ->expectsOutputToContain('Effective chunk size  : 3157')
        ->expectsOutputToContain('clamped to safe chunk [3157]')
        ->assertExitCode(0);
});

it('seeds patients with a small chunk size', function () {
    makeStressBranch();

    $this->artisan('stress:seed-patients', ['--target' => 5, '--chunk-size' => 2])
        ->assertExitCode(0);

    expect(DB::table('mst_patients')->where('medical_record_number', 'like', 'DG-TST-2026-%')->count())->toBe(5);
});

it('is idempotent on rerun (skips existing patients)', function () {
    makeStressBranch();

    $this->artisan('stress:seed-patients', ['--target' => 5, '--chunk-size' => 2])->assertExitCode(0);
    $this->artisan('stress:seed-patients', ['--target' => 5, '--chunk-size' => 2])->assertExitCode(0);

    expect(DB::table('mst_patients')->where('medical_record_number', 'like', 'DG-TST-2026-%')->count())->toBe(5);
});

it('validates the plan without writing rows in dry-run mode', function () {
    makeStressBranch();

    $this->artisan('stress:seed-patients', ['--target' => 4, '--chunk-size' => 999999, '--dry-run' => true])
        ->expectsOutputToContain('Effective chunk size  : 3157')
        ->expectsOutputToContain('[dry-run] Plan validated. No rows written.')
        ->assertExitCode(0);

    expect(DB::table('mst_patients')->count())->toBe(0);
});

it('fails clearly when the stress branch is missing', function () {
    $this->artisan('stress:seed-patients', ['--target' => 3, '--chunk-size' => 2])
        ->expectsOutputToContain('not found')
        ->assertExitCode(1);
});
