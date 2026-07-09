<?php

/**
 * HOTFIX-FIX-PRE-68-45-DOCTOR-PERFORMANCE-403 — access audit command.
 *
 * `rme:doctor-performance-access-audit` is read-only and privacy-safe. It
 * detects unlinked doctor accounts and Kepala Cabang permission leakage, and
 * fails (exit 2) under `--strict` when any anomaly exists.
 */

use App\Modules\Doctor\Models\Doctor;
use Database\Seeders\BranchSeeder;
use Illuminate\Support\Facades\Artisan;

beforeEach(function () {
    test()->seed(BranchSeeder::class);
    seedAccessControl();
});

it('registers the doctor performance access audit command', function () {
    expect(Artisan::all())->toHaveKey('rme:doctor-performance-access-audit');
});

it('reports OK with no anomalies for a correctly linked doctor', function () {
    $doctorUser = userInRole('Doctor');
    Doctor::factory()->create(['name' => 'drg. Linked', 'user_id' => $doctorUser->id]);

    $exitCode = Artisan::call('rme:doctor-performance-access-audit', ['--json' => true]);
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(0)
        ->and($payload['summary']['decision'])->toBe('OK')
        ->and($payload['summary']['anomalies'])->toBe(0)
        ->and($payload['permissions_exist']['view_doctor_performance_report'])->toBeTrue()
        ->and($payload['permissions_exist']['view_own_doctor_performance_report'])->toBeTrue();
});

it('detects doctor role users that are not linked to a doctor record', function () {
    $unlinked = userInRole('Doctor'); // Doctor role, no mst_doctors.user_id link

    Artisan::call('rme:doctor-performance-access-audit', ['--json' => true]);
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    $ids = collect($payload['findings']['doctor_role_unlinked'])->pluck('user_id')->all();

    expect($payload['summary']['doctor_role_unlinked'])->toBeGreaterThanOrEqual(1)
        ->and($ids)->toContain($unlinked->id)
        ->and($payload['summary']['own_permission_unlinked'])->toBeGreaterThanOrEqual(1);
});

it('detects doctor records without a user_id', function () {
    Doctor::factory()->create(['name' => 'drg. Tanpa Akun', 'user_id' => null]);

    Artisan::call('rme:doctor-performance-access-audit', ['--json' => true]);
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($payload['summary']['doctors_without_user'])->toBeGreaterThanOrEqual(1);
});

it('detects kepala cabang permission leakage', function () {
    $kepala = userInRole('Kepala Cabang');
    $kepala->givePermissionTo('view_doctor_performance_report'); // simulate a leak

    Artisan::call('rme:doctor-performance-access-audit', ['--json' => true]);
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    $ids = collect($payload['findings']['kepala_cabang_permission_leak'])->pluck('user_id')->all();

    expect($payload['summary']['kepala_cabang_permission_leak'])->toBeGreaterThanOrEqual(1)
        ->and($ids)->toContain($kepala->id);
});

it('does not flag a correctly configured kepala cabang as a leak', function () {
    userInRole('Kepala Cabang'); // seeded role has NO doctor-report permission

    Artisan::call('rme:doctor-performance-access-audit', ['--json' => true]);
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($payload['summary']['kepala_cabang_permission_leak'])->toBe(0)
        ->and($payload['summary']['kepala_cabang_role_permission_leak'])->toBeFalse();
});

it('fails under --strict when anomalies exist', function () {
    userInRole('Doctor'); // unlinked doctor → anomaly

    $exitCode = Artisan::call('rme:doctor-performance-access-audit', ['--strict' => true]);

    expect($exitCode)->toBe(2);
});

it('passes under --strict when there are no anomalies', function () {
    $doctorUser = userInRole('Doctor');
    Doctor::factory()->create(['name' => 'drg. Ok', 'user_id' => $doctorUser->id]);

    $exitCode = Artisan::call('rme:doctor-performance-access-audit', ['--strict' => true]);

    expect($exitCode)->toBe(0);
});

it('produces privacy-safe json output with no 16-digit identifiers', function () {
    $doctorUser = userInRole('Doctor');
    Doctor::factory()->create(['name' => 'drg. Privacy', 'user_id' => $doctorUser->id]);

    Artisan::call('rme:doctor-performance-access-audit', ['--json' => true]);
    $output = Artisan::output();
    $payload = json_decode($output, true, flags: JSON_THROW_ON_ERROR);

    expect($payload['privacy']['privacy_safe'])->toBeTrue()
        ->and($payload['privacy']['renders_ktp_nik'])->toBeFalse()
        ->and($output)->not->toMatch('/\d{16}/');
});
