<?php

use App\Modules\Branch\Models\Branch;
use App\Services\Foundation\FiveBranchRolloutReadinessService;

uses()->group('Foundation', 'RolloutReadiness', 'RollFive', 'RestoreDrill');

beforeEach(function () {
    seedAccessControl();
});

function stageClearanceService(): FiveBranchRolloutReadinessService
{
    return app(FiveBranchRolloutReadinessService::class);
}

function writeStageEvidence(array $overrides = []): string
{
    $base = [
        'schema_version' => 1,
        'drill_id' => 'roll-5-1a-stage',
        'environment' => 'staging',
        'source_backup_path' => '/var/backups/deploy/source.sql',
        'source_backup_size_bytes' => 2000,
        'restore_target' => 'daengtisiams_restore_drill_stage',
        'production_overwrite' => false,
        'completed_at' => gmdate('Y-m-d\TH:i:s\Z'),
        'verification' => [
            'db_connectivity' => 'GO', 'migration_consistency' => 'GO', 'app_boot' => 'GO',
            'health_routes' => 'GO', 'sample_readonly_queries' => 'GO', 'pii_redaction_confirmed' => true,
        ],
        'decision' => 'GO',
    ];
    $dir = storage_path('app/readiness/restore-drills');
    if (! is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    $path = $dir.'/stage-'.uniqid().'.json';
    file_put_contents($path, json_encode(array_replace($base, $overrides)));
    config()->set('rollout_readiness.paths.restore_drill_evidence', [$path]);

    return $path;
}

afterEach(function () {
    foreach (glob(storage_path('app/readiness/restore-drills/stage-*.json')) ?: [] as $f) {
        @unlink($f);
    }
});

it('clears Stage-1 to GO independently of Stage-3 via the pure stage decision', function () {
    $service = stageClearanceService();

    // Base readiness GO + Stage-1 branch gate GO => Stage-1 GO...
    expect($service->decideStage('GO', 'GO'))->toBe('GO')
        // ...while Stage-3's branch gate WATCH keeps Stage-3 WATCH on the same base.
        ->and($service->decideStage('GO', 'WATCH'))->toBe('WATCH');
});

it('never lets a WATCH base clear any stage to GO', function () {
    expect(stageClearanceService()->decideStage('WATCH', 'GO'))->toBe('WATCH');
});

it('surfaces restore_drill_evidence GO in collect() when valid staging evidence exists', function () {
    writeStageEvidence();

    $report = stageClearanceService()->collect();
    $drill = collect($report['signals'])->firstWhere('key', 'restore_drill_evidence');

    expect($drill['status'])->toBe('GO')
        ->and($drill['unsafe'])->toBeFalse();
});

it('keeps every stage gated by restore evidence when the evidence is missing', function () {
    config()->set('rollout_readiness.paths.restore_drill_evidence', [
        'storage/app/readiness/restore-drills/none-'.uniqid().'.json',
    ]);

    $report = stageClearanceService()->collect();
    $stage1 = collect($report['stages'])->firstWhere('key', 'stage_1');

    // No restore evidence => restore signal WATCH => base WATCH => stage WATCH.
    expect($stage1['base_status'])->toBe('WATCH')
        ->and($stage1['status'])->toBe('WATCH');
});

it('keeps Stage-3 WATCH on branch count when only 3 of 5 branches are RME-enabled', function () {
    Branch::factory()->count(3)->create(['is_rme_enabled' => true]);

    $report = stageClearanceService()->collect();

    $stage2 = collect($report['stages'])->firstWhere('key', 'stage_2');
    $stage3 = collect($report['stages'])->firstWhere('key', 'stage_3');

    expect($stage2['branch_status'])->toBe('GO')   // needs 3, have 3
        ->and($stage3['branch_status'])->toBe('WATCH'); // needs 5, have 3
});

it('makes Stage-1 branch gate GO with one RME branch while Stage-3 branch gate stays WATCH', function () {
    Branch::factory()->count(1)->create(['is_rme_enabled' => true]);

    $report = stageClearanceService()->collect();

    $stage1 = collect($report['stages'])->firstWhere('key', 'stage_1');
    $stage3 = collect($report['stages'])->firstWhere('key', 'stage_3');

    expect($stage1['branch_status'])->toBe('GO')
        ->and($stage3['branch_status'])->toBe('WATCH');
});
