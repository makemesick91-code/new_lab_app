<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('guards rme receivables performance index migration for non-pgsql drivers', function () {
    $path = database_path('migrations/2026_06_30_153729_add_rme_receivables_performance_indexes.php');
    $content = file_get_contents($path);

    expect($content)
        ->toContain("getDriverName() !== 'pgsql'")
        ->and(substr_count($content, "getDriverName() !== 'pgsql'"))->toBeGreaterThanOrEqual(2);
});

it('runs sqlite in-memory migrations including rme receivables index migration', function () {
    expect(DB::connection()->getDriverName())->toBe('sqlite')
        ->and(Schema::hasTable('trx_rme_payments'))->toBeTrue()
        ->and(Schema::hasTable('trx_rme_invoices'))->toBeTrue();
});

it('runs performance slow query audit on sqlite without migration errors', function () {
    $exitCode = Artisan::call('performance:slow-query-audit', [
        '--json' => true,
        '--skip-benchmarks' => true,
    ]);

    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(0)
        ->and($payload['privacy']['patient_names'])->toBeFalse()
        ->and($payload['privacy']['ktp_nik'])->toBeFalse();
});
