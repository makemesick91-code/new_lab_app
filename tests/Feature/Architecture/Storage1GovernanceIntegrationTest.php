<?php

use App\Services\Architecture\FoundationGovernanceSummaryService;
use App\Services\Architecture\FoundationRoadmapService;
use App\Services\Foundation\StorageGovernanceService;

uses()->group('Architecture', 'Storage1', 'FoundationGovernance');

it('foundation summary includes STORAGE_GOVERNANCE section with the STORAGE-R rules', function () {
    config(['object_storage.enabled' => false]);

    $summary = app(FoundationGovernanceSummaryService::class)->collect();

    expect($summary)->toHaveKey('storage_governance')
        ->and($summary['storage_governance']['decision'])->toBe('GO')
        ->and($summary['storage_governance']['object_storage_enabled'])->toBeFalse();

    $ruleIds = collect($summary['storage_governance']['rules'])->pluck('id')->all();

    expect($ruleIds)->toBe(['STORAGE-R001', 'STORAGE-R002', 'STORAGE-R003', 'STORAGE-R004', 'STORAGE-R005', 'STORAGE-R006']);
});

it('storage governance watches when object storage is enabled but misconfigured', function () {
    config([
        'object_storage.enabled' => true,
        'object_storage.required_env' => ['OBJECT_STORAGE_BUCKET_MISSING_FOR_TEST'],
    ]);

    $result = app(StorageGovernanceService::class)->collect();

    expect($result['decision'])->toBe('WATCH')
        ->and($result['readiness_status'])->toBe('misconfigured');
});

it('roadmap marks STORAGE-1 completed (next recommended sprint is now ENT-1 after ENT-0 reconciliation)', function () {
    $report = app(FoundationRoadmapService::class)->collect();
    $storage1 = collect($report['approved_sequence'])->firstWhere('id', 'STORAGE-1');

    $rawStorage1 = collect(config('foundation_roadmap.approved_sequence'))->firstWhere('id', 'STORAGE-1');

    expect($storage1['status'])->toBe('completed')
        ->and($rawStorage1['requires_rollback_plan'])->toBeTrue()
        ->and($report['next_recommended_sprint'])->toBe('MON-1');
});

it('.env.example ships object storage keys without real secret values', function () {
    $env = file_get_contents(base_path('.env.example'));

    expect($env)->toContain('OBJECT_STORAGE_ENABLED=false')
        ->and($env)->toContain('OBJECT_STORAGE_ACCESS_KEY_ID=')
        ->and($env)->toContain('OBJECT_STORAGE_SECRET_ACCESS_KEY=')
        ->and($env)->not->toMatch('/OBJECT_STORAGE_SECRET_ACCESS_KEY=.+/');
});

it('storage readiness and governance docs exist', function () {
    expect(file_exists(base_path('docs/architecture/storage-object-readiness.md')))->toBeTrue()
        ->and(file_exists(base_path('docs/architecture/storage-governance-rules.md')))->toBeTrue();
});
