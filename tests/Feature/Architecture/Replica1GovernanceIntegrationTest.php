<?php

use App\Services\Architecture\FoundationGovernanceSummaryService;
use App\Services\Foundation\DatabaseReplicaGovernanceService;

uses()->group('Replica', 'DatabaseScale', 'Governance', 'FoundationGovernance');

it('foundation summary includes database replica governance with REPLICA rules', function () {
    $summary = app(FoundationGovernanceSummaryService::class)->collect();

    expect($summary)->toHaveKey('database_replica_governance')
        ->and($summary['database_replica_governance']['decision'])->toBe('GO')
        ->and($summary['database_replica_governance']['readiness_status'])->toBe('single_primary_ready');

    $ruleIds = collect($summary['database_replica_governance']['rules'])->pluck('id')->all();

    expect($ruleIds)->toBe([
        'REPLICA-R001', 'REPLICA-R002', 'REPLICA-R003', 'REPLICA-R004',
        'REPLICA-R005', 'REPLICA-R006', 'REPLICA-R007', 'REPLICA-R008',
        'REPLICA-R009', 'REPLICA-R010', 'REPLICA-R011', 'REPLICA-R012',
    ]);
});

it('keeps storage stateless and load balancer governance rules visible', function () {
    $summary = app(FoundationGovernanceSummaryService::class)->collect();

    $storageRuleIds = collect($summary['storage_governance']['rules'])->pluck('id')->all();
    $statelessRuleIds = collect($summary['stateless_governance']['rules'])->pluck('id')->all();
    $lbRuleIds = collect($summary['lb_governance']['rules'])->pluck('id')->all();

    expect($storageRuleIds)->toBe(['STORAGE-R001', 'STORAGE-R002', 'STORAGE-R003', 'STORAGE-R004', 'STORAGE-R005'])
        ->and($statelessRuleIds)->toBe([
            'STATELESS-R001', 'STATELESS-R002', 'STATELESS-R003', 'STATELESS-R004',
            'STATELESS-R005', 'STATELESS-R006', 'STATELESS-R007', 'STATELESS-R008',
        ])
        ->and($lbRuleIds)->toBe([
            'LB-R001', 'LB-R002', 'LB-R003', 'LB-R004', 'LB-R005',
            'LB-R006', 'LB-R007', 'LB-R008', 'LB-R009', 'LB-R010',
        ]);
});

it('foundation-governance-summary stays GO in single-primary mode', function () {
    $summary = app(FoundationGovernanceSummaryService::class)->collect();

    expect($summary['combined']['decision'])->toBe('GO')
        ->and($summary['database_replica_governance']['decision'])->toBe('GO');
});

it('database replica governance watches strict no-go without exposing secrets', function () {
    config([
        'database_scale.replica.enabled' => true,
        'database_scale.replica.expected' => true,
        'database_scale.replica.strict' => true,
        'database.connections.pgsql_read.host' => null,
        'database.connections.pgsql_read.database' => null,
        'database.connections.pgsql_read.username' => null,
        'database.connections.pgsql_read.password' => '',
    ]);

    $result = app(DatabaseReplicaGovernanceService::class)->collect();

    expect($result['decision'])->toBe('WATCH')
        ->and(json_encode($result))->not->toContain('DB_PASSWORD')
        ->and(json_encode($result))->not->toContain('DB_READ_PASSWORD=');
});

it('ships replica docs and env keys', function () {
    $env = file_get_contents(base_path('.env.example'));

    expect(file_exists(base_path('docs/architecture/read-replica-readiness.md')))->toBeTrue()
        ->and(file_exists(base_path('docs/architecture/database-scale-governance-rules.md')))->toBeTrue()
        ->and($env)->toContain('DB_REPLICA_ENABLED=false')
        ->and($env)->toContain('DB_REPLICA_CONNECTION=pgsql_read')
        ->and($env)->toContain('DB_READ_PASSWORD=');
});
