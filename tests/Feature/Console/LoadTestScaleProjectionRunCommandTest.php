<?php

use Illuminate\Support\Facades\Artisan;

uses()->group('Console', 'LoadTestScaleProjection', 'EnterpriseFoundation');

it('runs the guarded projection runner in the testing environment', function () {
    expect(Artisan::call('loadtest:scale-projection-run', ['--dry-run' => true]))->toBe(0);
});

it('emits a non-sensitive projection-labeled dry-run pack with the required shape', function () {
    Artisan::call('loadtest:scale-projection-run', ['--dry-run' => true, '--json' => true]);
    $output = Artisan::output();

    $pack = json_decode($output, true);

    expect($pack)->toBeArray()
        ->and($pack['sprint'])->toBe('ENT-14')
        ->and($pack['mode'])->toBe('dry_run')
        ->and($pack['projection_basis'])->toBe('modeled')
        ->and($pack['disclaimer'])->toContain('modeled')
        ->and($pack['baseline_inputs']['branch_count'])->toBe(5)
        ->and($pack['privacy']['privacy_safe'])->toBeTrue()
        ->and($pack['projections'])->not->toBeEmpty()
        ->and(preg_match('/\d{13,}/', $output))->toBe(0);

    foreach ($pack['projections'] as $projection) {
        expect($projection)->toHaveKeys(['tier', 'branch_count', 'scale_factor', 'basis'])
            ->and($projection['basis'])->toBe('estimated');
    }

    $branchCounts = array_column($pack['projections'], 'branch_count');
    expect($branchCounts)->toContain(20);
});

it('computes modeled projections without hitting the database', function () {
    expect(Artisan::call('loadtest:scale-projection-run', ['--json' => true]))->toBe(0);
    $pack = json_decode(Artisan::output(), true);

    expect($pack['mode'])->toBe('projected')
        ->and($pack['projections'])->not->toBeEmpty()
        ->and($pack['model_inputs']['mitigation_foundations'])->toContain('LB-1')
        ->and($pack['model_inputs']['mitigation_foundations'])->toContain('REPLICA-1');

    // National tier naive single-node p95 must exceed the baseline band, proving
    // the projection surfaces the scale-out need rather than claiming free scale.
    $national = collect($pack['projections'])->firstWhere('tier', 'national_50');
    expect($national['projected_p95_naive_single_node_ms'])->toBeGreaterThan(300);
});

it('refuses to run outside the allowed non-production environments', function () {
    config(['load_test_scale_projection.allowed_environments' => ['stress']]);

    expect(Artisan::call('loadtest:scale-projection-run'))->toBe(1);
});
