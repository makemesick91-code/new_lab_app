<?php

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

uses(TestCase::class);

it('registers architecture owner kpi registry command', function () {
    expect(Artisan::all())->toHaveKey('architecture:owner-kpi-registry');
});

it('runs and outputs valid JSON with required owner kpi keys', function () {
    $exitCode = Artisan::call('architecture:owner-kpi-registry', ['--json' => true]);
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(0)
        ->and($payload)->toHaveKeys([
            'generated_at', 'environment', 'metadata', 'summary', 'owner_kpis',
            'alias_map', 'blocked', 'needs_review', 'governance', 'privacy',
        ])
        ->and($payload['privacy']['row_level_data'])->toBeFalse()
        ->and($payload['privacy']['patient_names'])->toBeFalse()
        ->and($payload['metadata']['dmo_m005_status'])->toBe('closed');
});

it('includes canonical owner kpi names', function () {
    Artisan::call('architecture:owner-kpi-registry', ['--json' => true]);
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    $names = collect($payload['owner_kpis'])->pluck('canonical_kpi_name')->all();

    expect($names)->toContain('owner_total_revenue')
        ->and($names)->toContain('owner_receivable_total')
        ->and($names)->toContain('owner_visit_count')
        ->and($names)->toContain('owner_patient_count')
        ->and($names)->toContain('owner_inventory_value')
        ->and($names)->toContain('owner_low_stock_count')
        ->and($names)->toContain('owner_follow_up_count')
        ->and($names)->toContain('owner_lab_order_count')
        ->and($names)->toContain('owner_runtime_query_risk');
});

it('maps duplicate aliases to alias_of canonical kpi', function () {
    Artisan::call('architecture:owner-kpi-registry', ['--json' => true]);
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    $map = collect($payload['alias_map'])->keyBy('alias');

    expect($map['total_revenue']['alias_of'])->toBe('owner_total_revenue')
        ->and($map['active_receivable']['alias_of'])->toBe('owner_receivable_total')
        ->and($map['low_stock_items']['alias_of'])->toBe('owner_low_stock_count')
        ->and($map['follow_up_due']['alias_of'])->toBe('owner_follow_up_count')
        ->and($payload['summary']['duplicates_resolved'])->toBeGreaterThan(9);
});

it('documents blocked metrics net_revenue and pod_count', function () {
    Artisan::call('architecture:owner-kpi-registry', ['--json' => true]);
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    $blocked = collect($payload['blocked'])->pluck('metric')->all();

    expect($blocked)->toContain('net_revenue')
        ->and($blocked)->toContain('pod_count');
});

it('writes output only under storage app architecture directory', function () {
    $relative = 'dmo2-owner-kpi-test-'.uniqid().'.json';

    try {
        $exitCode = Artisan::call('architecture:owner-kpi-registry', [
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

it('does not expose patient names ktp or clinical content in owner kpi output', function () {
    Artisan::call('architecture:owner-kpi-registry', ['--json' => true]);
    $output = Artisan::output();

    expect($output)->not->toMatch('/\d{16}/')
        ->and($output)->not->toContain('Pasien Test');
});
