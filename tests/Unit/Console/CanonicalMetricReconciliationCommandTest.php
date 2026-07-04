<?php

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

uses(TestCase::class);

it('registers architecture canonical metric reconciliation command', function () {
    expect(Artisan::all())->toHaveKey('architecture:canonical-metric-reconciliation');
});

it('runs and outputs valid JSON with privacy flags', function () {
    $exitCode = Artisan::call('architecture:canonical-metric-reconciliation', ['--json' => true]);
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(0)
        ->and($payload)->toHaveKeys(['generated_at', 'environment', 'metadata', 'metrics', 'conflicts', 'gaps', 'privacy', 'summary'])
        ->and($payload['privacy']['row_level_data'])->toBeFalse()
        ->and($payload['privacy']['patient_names'])->toBeFalse()
        ->and($payload['privacy']['ktp_nik'])->toBeFalse()
        ->and($payload['privacy']['clinical_content'])->toBeFalse();
});

it('includes key canonical metrics', function () {
    Artisan::call('architecture:canonical-metric-reconciliation', ['--json' => true]);
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    $names = collect($payload['metrics'])->pluck('canonical_metric_name')->all();

    expect($names)->toContain('total_visits')
        ->and($names)->toContain('paid_amount')
        ->and($names)->toContain('remaining_receivable')
        ->and($names)->toContain('current_stock_qty')
        ->and($names)->toContain('low_stock_count');
});

it('classifies sensitivity without sample PII or PHI', function () {
    Artisan::call('architecture:canonical-metric-reconciliation', ['--json' => true]);
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    $output = Artisan::output();

    $paid = collect($payload['metrics'])->firstWhere('canonical_metric_name', 'paid_amount');
    $visit = collect($payload['metrics'])->firstWhere('canonical_metric_name', 'total_visits');

    expect($paid['sensitivity'])->toContain('financial')
        ->and($visit['sensitivity'])->toContain('PII')
        ->and($output)->not->toMatch('/\d{16}/')
        ->and($output)->not->toContain('Pasien Test');
});

it('writes output only under storage app architecture directory', function () {
    $relative = 'nsf5-test-'.uniqid().'.json';

    try {
        $exitCode = Artisan::call('architecture:canonical-metric-reconciliation', [
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
    $exitCode = Artisan::call('architecture:canonical-metric-reconciliation', [
        '--output' => '../outside.json',
    ]);

    expect($exitCode)->toBe(10);
});

it('filters by domain', function () {
    Artisan::call('architecture:canonical-metric-reconciliation', [
        '--json' => true,
        '--domain' => 'inventory',
    ]);
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect(collect($payload['metrics'])->every(fn ($m) => $m['domain'] === 'inventory'))->toBeTrue()
        ->and($payload['summary']['metrics'])->toBeGreaterThan(5);
});

it('rejects invalid domain filter', function () {
    $exitCode = Artisan::call('architecture:canonical-metric-reconciliation', [
        '--domain' => 'invalid_domain',
    ]);

    expect($exitCode)->toBe(1);
});

it('includes entity reference when requested', function () {
    Artisan::call('architecture:canonical-metric-reconciliation', [
        '--json' => true,
        '--domain' => 'cashier',
        '--include-entity-reference' => true,
    ]);
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    $receivable = collect($payload['metrics'])->firstWhere('canonical_metric_name', 'remaining_receivable');

    expect($receivable)->toHaveKey('entity_reference')
        ->and($receivable['entity_reference'])->not->toBeEmpty();
});

it('does not crash when optional modules are referenced', function () {
    $exitCode = Artisan::call('architecture:canonical-metric-reconciliation', [
        '--json' => true,
        '--no-consumers' => true,
    ]);

    expect($exitCode)->toBe(0);
});
