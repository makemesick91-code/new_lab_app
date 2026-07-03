<?php

use App\Services\Monitoring\RuntimeQueryObservabilityService;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

uses(TestCase::class);

it('registers performance runtime query observability command', function () {
    expect(Artisan::all())->toHaveKey('performance:runtime-query-observability');
});

it('runs safely on non-pgsql without crashing', function () {
    if (config('database.default') === 'pgsql') {
        $this->markTestSkipped('This test targets non-pgsql fallback behavior.');
    }

    $exitCode = Artisan::call('performance:runtime-query-observability', ['--json' => true]);
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(0)
        ->and($payload)->toHaveKeys(['captured_at', 'pg_stat_statements', 'privacy', 'queries'])
        ->and($payload['pg_stat_statements']['available'])->toBeFalse();
});

it('outputs valid JSON with privacy flags on pgsql or sqlite', function () {
    $exitCode = Artisan::call('performance:runtime-query-observability', ['--json' => true]);
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(0)
        ->and($payload)->toHaveKeys(['captured_at', 'environment', 'metadata', 'privacy', 'pg_stat_statements', 'queries'])
        ->and($payload['privacy']['patient_names'])->toBeFalse()
        ->and($payload['privacy']['ktp_nik'])->toBeFalse()
        ->and($payload['privacy']['raw_bindings'])->toBeFalse();
});

it('writes output only under storage app performance directory', function () {
    $relative = 'nsf3-test-'.uniqid().'.json';

    try {
        $exitCode = Artisan::call('performance:runtime-query-observability', [
            '--json' => true,
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
    $exitCode = Artisan::call('performance:runtime-query-observability', [
        '--output' => '../outside.json',
    ]);

    expect($exitCode)->toBe(10)
        ->and(Artisan::output())->toContain('storage/app/performance');
});

it('rejects invalid sort option', function () {
    $exitCode = Artisan::call('performance:runtime-query-observability', [
        '--sort' => 'invalid_sort',
    ]);

    expect($exitCode)->toBe(1)
        ->and(Artisan::output())->toContain('Invalid sort');
});

it('rejects limit above maximum', function () {
    $exitCode = Artisan::call('performance:runtime-query-observability', [
        '--limit' => 500,
    ]);

    expect($exitCode)->toBe(1)
        ->and(Artisan::output())->toContain('Limit must be between');
});

it('sanitizer redacts quoted strings emails phones and numeric literals', function () {
    $service = app(RuntimeQueryObservabilityService::class);

    $raw = "SELECT name, ktp_number FROM mst_patients WHERE phone = '081234567890' AND email = 'patient@example.com' AND id = 12345";
    $sanitized = $service->sanitizeQueryText($raw);

    expect($sanitized)->not->toContain('081234567890')
        ->and($sanitized)->not->toContain('patient@example.com')
        ->and($sanitized)->not->toContain('12345')
        ->and($sanitized)->toContain('?');
});

it('does not expose sensitive sample text in JSON output', function () {
    Artisan::call('performance:runtime-query-observability', ['--json' => true]);

    $output = Artisan::output();

    expect($output)->not->toContain('ktp_number')
        ->and($output)->not->toContain('handwriting_path')
        ->and($output)->not->toMatch('/"name"\s*:\s*"[A-Z]/');
});

it('blocks production environment without force flag', function () {
    $originalEnv = app()->environment();
    config(['app.env' => 'production']);
    app()->instance('env', 'production');

    try {
        $exitCode = Artisan::call('performance:runtime-query-observability');

        expect($exitCode)->toBe(10)
            ->and(Artisan::output())->toContain('production');
    } finally {
        app()->instance('env', $originalEnv);
        config(['app.env' => $originalEnv]);
    }
});

it('reports pg_stat availability on pgsql', function () {
    if (config('database.default') !== 'pgsql') {
        $this->markTestSkipped('Requires pgsql.');
    }

    Artisan::call('performance:runtime-query-observability', ['--json' => true]);
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($payload['pg_stat_statements'])->toHaveKeys([
        'available', 'extension_installed', 'preloaded', 'shared_preload_libraries',
    ]);
})->group('pgsql');

it('includes query rows with sanitized summaries when pg_stat is available', function () {
    if (config('database.default') !== 'pgsql') {
        $this->markTestSkipped('Requires pgsql.');
    }

    Artisan::call('performance:runtime-query-observability', ['--limit' => 5, '--json' => true]);
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    if (! ($payload['pg_stat_statements']['available'] ?? false)) {
        $this->markTestSkipped('pg_stat_statements not available in this environment.');
    }

    expect($payload['queries'])->toBeArray();

    if ($payload['queries'] !== []) {
        expect($payload['queries'][0])->toHaveKeys([
            'calls', 'total_time_ms', 'mean_time_ms', 'query_summary', 'module_guess', 'risk_hint',
        ]);
    }
})->group('pgsql');
