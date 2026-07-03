<?php

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

uses(TestCase::class);

it('registers performance slow query audit command in artisan list', function () {
    expect(Artisan::all())->toHaveKey('performance:slow-query-audit');
});

it('blocks production environment without force flag', function () {
    $originalEnv = app()->environment();
    config(['app.env' => 'production']);
    app()->instance('env', 'production');

    try {
        $exitCode = Artisan::call('performance:slow-query-audit', ['--skip-benchmarks' => true]);

        expect($exitCode)->toBe(10)
            ->and(Artisan::output())->toContain('production');
    } finally {
        app()->instance('env', $originalEnv);
        config(['app.env' => $originalEnv]);
    }
});

it('outputs valid JSON with audited_at and privacy flags', function () {
    $exitCode = Artisan::call('performance:slow-query-audit', [
        '--json' => true,
        '--skip-benchmarks' => true,
    ]);

    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(0)
        ->and($payload)->toHaveKeys(['audited_at', 'environment', 'metadata', 'privacy', 'table_inventory'])
        ->and($payload['privacy']['patient_names'])->toBeFalse()
        ->and($payload['privacy']['ktp_nik'])->toBeFalse();
});

it('does not leak sensitive patient content in JSON output', function () {
    Artisan::call('performance:slow-query-audit', [
        '--json' => true,
        '--skip-benchmarks' => true,
    ]);

    $output = Artisan::output();
    $payload = json_decode($output, true, flags: JSON_THROW_ON_ERROR);

    expect($payload['privacy']['patient_names'])->toBeFalse()
        ->and($payload['privacy']['ktp_nik'])->toBeFalse();

    expect($output)->not->toMatch('/"name"\s*:\s*"[A-Z]/')
        ->and($output)->not->toContain('ktp_number')
        ->and($output)->not->toContain('handwriting_path');
});

it('writes output only under storage app performance directory', function () {
    $relative = 'nsf1-test-'.uniqid().'.json';

    try {
        $exitCode = Artisan::call('performance:slow-query-audit', [
            '--json' => true,
            '--skip-benchmarks' => true,
            '--output' => $relative,
        ]);

        $fullPath = storage_path('app/performance/'.$relative);

        expect($exitCode)->toBe(0)
            ->and(file_exists($fullPath))->toBeTrue();
    } finally {
        if (isset($fullPath) && file_exists($fullPath)) {
            unlink($fullPath);
        }
    }
});

it('rejects output paths outside storage app performance', function () {
    $exitCode = Artisan::call('performance:slow-query-audit', [
        '--skip-benchmarks' => true,
        '--output' => '../outside.json',
    ]);

    expect($exitCode)->toBe(10)
        ->and(Artisan::output())->toContain('storage/app/performance');
});

it('includes benchmark rows when benchmarks are enabled on pgsql', function () {
    if (config('database.default') !== 'pgsql') {
        $this->markTestSkipped('Benchmark audit requires pgsql.');
    }

    Artisan::call('performance:slow-query-audit', ['--json' => true]);
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($payload['benchmarks'])->toBeArray();

    if ($payload['benchmarks'] !== [] && ! isset($payload['benchmarks']['skipped'])) {
        expect($payload['benchmarks'][0])->toHaveKeys(['id', 'module', 'runtime_ms', 'status']);
    }
})->group('pgsql');
