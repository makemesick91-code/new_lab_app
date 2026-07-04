<?php

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

uses(TestCase::class);

it('registers architecture dmo foundation command', function () {
    expect(Artisan::all())->toHaveKey('architecture:dmo-foundation');
});

it('runs and outputs valid JSON with required foundation keys', function () {
    $exitCode = Artisan::call('architecture:dmo-foundation', ['--json' => true]);
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(0)
        ->and($payload)->toHaveKeys([
            'generated_at', 'environment', 'metadata', 'summary', 'domains',
            'canonical_entities', 'canonical_metrics', 'ontology_relationships',
            'dimensions', 'lineage', 'governance_rules', 'sensitivity_classification',
            'dmo_backlog', 'privacy', 'readiness',
        ])
        ->and($payload['privacy']['row_level_data'])->toBeFalse()
        ->and($payload['privacy']['patient_names'])->toBeFalse()
        ->and($payload['privacy']['ktp_nik'])->toBeFalse();
});

it('includes key canonical entities in foundation output', function () {
    Artisan::call('architecture:dmo-foundation', ['--json' => true]);
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    $names = collect($payload['canonical_entities'])->pluck('canonical_name')->all();

    expect($names)->toContain('Patient')
        ->and($names)->toContain('Clinic Visit')
        ->and($names)->toContain('Medical Record')
        ->and($names)->toContain('RME Invoice')
        ->and($names)->toContain('Inventory Movement');
});

it('includes key canonical metrics in foundation output', function () {
    Artisan::call('architecture:dmo-foundation', ['--json' => true]);
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    $names = collect($payload['canonical_metrics'])->pluck('canonical_metric_name')->all();

    expect($names)->toContain('total_visits')
        ->and($names)->toContain('paid_amount')
        ->and($names)->toContain('remaining_receivable')
        ->and($names)->toContain('current_stock_qty');
});

it('includes ontology relationships for patient visit and inventory ledger', function () {
    Artisan::call('architecture:dmo-foundation', ['--json' => true]);
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    $rels = collect($payload['ontology_relationships']);

    expect($rels->contains(fn ($r) => $r['source_entity'] === 'Patient' && $r['target_entity'] === 'Clinic Visit'))->toBeTrue()
        ->and($rels->contains(fn ($r) => $r['source_entity'] === 'Clinic Visit' && $r['target_entity'] === 'Medical Record'))->toBeTrue()
        ->and($rels->contains(fn ($r) => $r['source_entity'] === 'RME Invoice' && $r['target_entity'] === 'RME Payment'))->toBeTrue()
        ->and($rels->contains(fn ($r) => $r['source_entity'] === 'Inventory Product' && $r['target_entity'] === 'Inventory Movement'))->toBeTrue();
});

it('classifies sensitivity without exposing PII PHI or raw row data', function () {
    Artisan::call('architecture:dmo-foundation', ['--json' => true]);
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    $output = Artisan::output();

    $classes = collect($payload['sensitivity_classification'])->pluck('class')->all();

    expect($classes)->toContain('PII')
        ->and($classes)->toContain('PHI')
        ->and($classes)->toContain('financial')
        ->and($classes)->toContain('telemetry')
        ->and($output)->not->toMatch('/\d{16}/')
        ->and($output)->not->toContain('Pasien Test');
});

it('writes output only under storage app architecture directory', function () {
    $relative = 'dmo1-test-'.uniqid().'.json';

    try {
        $exitCode = Artisan::call('architecture:dmo-foundation', [
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
    $exitCode = Artisan::call('architecture:dmo-foundation', [
        '--output' => '../outside.json',
    ]);

    expect($exitCode)->toBe(10);
});

it('filters by domain', function () {
    Artisan::call('architecture:dmo-foundation', [
        '--json' => true,
        '--domain' => 'inventory',
    ]);
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect(collect($payload['canonical_entities'])->every(fn ($e) => $e['domain'] === 'inventory'))->toBeTrue()
        ->and(collect($payload['canonical_metrics'])->every(fn ($m) => $m['domain'] === 'inventory'))->toBeTrue();
});

it('rejects invalid domain filter', function () {
    $exitCode = Artisan::call('architecture:dmo-foundation', [
        '--domain' => 'invalid_domain',
    ]);

    expect($exitCode)->toBe(1);
});

it('includes backlog dimensions and governance rules', function () {
    Artisan::call('architecture:dmo-foundation', ['--json' => true]);
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($payload['summary']['dimensions'])->toBeGreaterThan(5)
        ->and($payload['summary']['backlog_items'])->toBeGreaterThan(5)
        ->and($payload['governance_rules'])->toHaveCount(15)
        ->and($payload['readiness']['decision'])->toBeIn(['GO', 'WATCH']);
});

it('does not crash when optional modules are referenced', function () {
    $exitCode = Artisan::call('architecture:dmo-foundation', [
        '--json' => true,
        '--no-lineage' => true,
        '--no-backlog' => true,
    ]);
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(0)
        ->and($payload['lineage'])->toBe([])
        ->and($payload['dmo_backlog'])->toBe([]);
});
