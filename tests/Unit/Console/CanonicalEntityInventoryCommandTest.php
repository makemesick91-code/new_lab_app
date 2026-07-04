<?php

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

uses(TestCase::class);

it('registers architecture canonical entity inventory command', function () {
    expect(Artisan::all())->toHaveKey('architecture:canonical-entity-inventory');
});

it('runs and outputs valid JSON with privacy flags', function () {
    $exitCode = Artisan::call('architecture:canonical-entity-inventory', ['--json' => true]);
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(0)
        ->and($payload)->toHaveKeys(['generated_at', 'environment', 'metadata', 'entities', 'workflows', 'gaps', 'privacy', 'summary'])
        ->and($payload['privacy']['row_level_data'])->toBeFalse()
        ->and($payload['privacy']['patient_names'])->toBeFalse()
        ->and($payload['privacy']['ktp_nik'])->toBeFalse()
        ->and($payload['privacy']['clinical_content'])->toBeFalse();
});

it('includes key canonical entities', function () {
    Artisan::call('architecture:canonical-entity-inventory', ['--json' => true]);
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    $names = collect($payload['entities'])->pluck('canonical_name')->all();

    expect($names)->toContain('Patient')
        ->and($names)->toContain('Clinic Visit')
        ->and($names)->toContain('Medical Record')
        ->and($names)->toContain('RME Invoice')
        ->and($names)->toContain('Inventory Movement');
});

it('classifies sensitive domains without sample PII', function () {
    Artisan::call('architecture:canonical-entity-inventory', ['--json' => true]);
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    $output = Artisan::output();

    $patient = collect($payload['entities'])->firstWhere('canonical_name', 'Patient');

    expect($patient['sensitivity'])->toContain('PII')
        ->and($output)->not->toMatch('/\d{16}/');
});

it('writes output only under storage app architecture directory', function () {
    $relative = 'nsf4-test-'.uniqid().'.json';

    try {
        $exitCode = Artisan::call('architecture:canonical-entity-inventory', [
            '--json' => true,
            '--output' => $relative,
        ]);

        $fullPath = storage_path('app/architecture/'.$relative);

        expect($exitCode)->toBe(0)
            ->and(file_exists($fullPath))->toBeTrue();
    } finally {
        if (isset($fullPath) && file_exists($fullPath)) {
            unlink($fullPath);
        }
    }
});

it('rejects output paths outside storage app architecture', function () {
    $exitCode = Artisan::call('architecture:canonical-entity-inventory', [
        '--output' => '../outside.json',
    ]);

    expect($exitCode)->toBe(10);
});

it('filters by domain', function () {
    Artisan::call('architecture:canonical-entity-inventory', [
        '--json' => true,
        '--domain' => 'inventory',
    ]);
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect(collect($payload['entities'])->every(fn ($e) => $e['domain'] === 'inventory'))->toBeTrue()
        ->and($payload['summary']['entity_count'])->toBeGreaterThan(5);
});

it('rejects invalid domain filter', function () {
    $exitCode = Artisan::call('architecture:canonical-entity-inventory', [
        '--domain' => 'invalid_domain',
    ]);

    expect($exitCode)->toBe(1);
});

it('does not crash when optional tables are checked', function () {
    $exitCode = Artisan::call('architecture:canonical-entity-inventory', [
        '--json' => true,
        '--no-schema' => true,
    ]);

    expect($exitCode)->toBe(0);
});
