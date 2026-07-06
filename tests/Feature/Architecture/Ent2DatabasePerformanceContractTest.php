<?php

use App\Services\Architecture\FoundationRoadmapService;
use App\Services\Foundation\FoundationRoadmapGovernanceService;
use Illuminate\Support\Facades\Artisan;

uses()->group('Architecture', 'FoundationRoadmap', 'Roadmap', 'FoundationGovernance', 'EnterpriseFoundation');

it('ships the database performance contract doc with all DBPERF rules', function () {
    $path = base_path('docs/architecture/database-performance-contract.md');

    expect(file_exists($path))->toBeTrue();

    $doc = file_get_contents($path);

    foreach (range(1, 14) as $n) {
        $ruleId = sprintf('DBPERF-R%03d', $n);
        expect($doc)->toContain($ruleId);
    }

    expect($doc)->toContain('filter / sort / pagination')
        ->and($doc)->toContain('BranchContext')
        ->and($doc)->toContain('read-only')
        ->and($doc)->toContain('permission-gated')
        ->and($doc)->toContain('materialized')
        ->and($doc)->toContain('No unbounded list rule')
        ->and($doc)->toContain('N+1')
        ->and($doc)->toContain('full table scan')
        ->and($doc)->toContain('additive, production-safe migrations')
        ->and($doc)->toContain('ledger-derived')
        ->and($doc)->toContain('DBPERF-1')
        ->and($doc)->toContain('DBPERF-2')
        ->and($doc)->toContain('ENT-3')
        ->and($doc)->toContain('ENT-4');
});

it('ships the database performance hotspot inventory covering the named hotspot domains', function () {
    $path = base_path('docs/architecture/database-performance-hotspot-inventory.md');

    expect(file_exists($path))->toBeTrue();

    $doc = file_get_contents($path);

    expect($doc)->toContain('RME visit list')
        ->and($doc)->toContain('RME patient search')
        ->and($doc)->toContain('cashier invoices / receivables')
        ->and($doc)->toContain('Owner dashboard KPI')
        ->and($doc)->toContain('Inventory current stock')
        ->and($doc)->toContain('Lab orders')
        ->and($doc)->toContain('Reports / export')
        ->and($doc)->toContain('verified')
        ->and($doc)->toContain('TODO');
});

it('keeps the ENT-2 governance docs free of release-evidence forbidden literals', function () {
    $paths = [
        base_path('docs/architecture/database-performance-contract.md'),
        base_path('docs/architecture/database-performance-hotspot-inventory.md'),
    ];

    foreach ($paths as $path) {
        $doc = file_get_contents($path);

        foreach (config('release_evidence.forbidden_patterns', []) as $pattern) {
            expect(str_contains($doc, $pattern))->toBeFalse(
                basename($path)." must not contain forbidden release-evidence literal: {$pattern}"
            );
        }

        foreach (config('release_evidence.forbidden_regex', []) as $regex) {
            expect(preg_match($regex, $doc))->toBe(0);
        }
    }
});

it('references the ENT-2 database performance contract from the enterprise freeze rules doc', function () {
    $doc = file_get_contents(base_path('docs/architecture/enterprise-foundation-freeze-rules.md'));

    expect($doc)->toContain('Database Performance Contract')
        ->and($doc)->toContain('database-performance-contract.md');
});

it('ships the cursor mirror rule for the database performance contract', function () {
    $path = base_path('.cursor/rules/51-database-performance-contract.mdc');

    expect(file_exists($path))->toBeTrue();

    $doc = file_get_contents($path);

    expect($doc)->toContain('database-performance-contract.md')
        ->and($doc)->toContain('DBPERF-R001..DBPERF-R014');
});

it('documents the ENT-2 sprint evidence in CLAUDE.md', function () {
    $doc = file_get_contents(base_path('CLAUDE.md'));

    expect($doc)->toContain('ENT-2 — Database Performance Contract')
        ->and($doc)->toContain('database-performance-contract.md');
});

it('registers the ENT-2 contract pointer in the roadmap rules', function () {
    expect(config('foundation_roadmap.rules.database_performance_contract_locked'))->toBeTrue()
        ->and(config('foundation_roadmap.rules.database_performance_contract_doc'))
        ->toBe('docs/architecture/database-performance-contract.md');
});

it('records the ENT-1 GO and deploy evidence in the canonical roadmap', function () {
    $ent1 = collect(config('foundation_roadmap.approved_sequence'))->firstWhere('id', 'ENT-1');

    expect($ent1)->not->toBeNull()
        ->and($ent1['status'])->toBe('completed')
        ->and($ent1['go_tag'])->toBe('ent-1-enterprise-architecture-baseline-lock-go')
        ->and($ent1['go_commit'])->toBe('9069bce8d9f63c7a575a251c997cc2c8d44a83bd')
        ->and($ent1['deploy_evidence_commit'])->toBe('62c4ff0');
});

it('registers ENT-2 completed with its GO tag and contract doc pointers', function () {
    $ent2 = collect(config('foundation_roadmap.approved_sequence'))->firstWhere('id', 'ENT-2');

    expect($ent2)->not->toBeNull()
        ->and($ent2['status'])->toBe('completed')
        ->and($ent2['category'])->toBe('db_performance')
        ->and($ent2['go_tag'])->toBe('ent-2-database-performance-contract-go')
        ->and($ent2['contract_doc'])->toBe('docs/architecture/database-performance-contract.md')
        ->and($ent2['hotspot_inventory_doc'])->toBe('docs/architecture/database-performance-hotspot-inventory.md')
        ->and($ent2['related_shipped_foundations'])->toContain('DBPERF-1')
        ->and($ent2['related_shipped_foundations'])->toContain('DBPERF-2');
});

it('moves next recommended sprint to ENT-9 after the cache policy lock', function () {
    $report = app(FoundationRoadmapService::class)->collect();
    $governance = app(FoundationRoadmapGovernanceService::class)->collect();

    expect($report['next_recommended_sprint'])->toBe('ENT-13')
        ->and($governance['stale_next_detected'])->toBeFalse()
        ->and($governance['decision'])->toBe('GO');
});

it('keeps ENT-9..ENT-16 planned until they earn their own GO evidence', function () {
    $sequence = collect(config('foundation_roadmap.approved_sequence'));

    foreach (range(13, 16) as $n) {
        $entry = $sequence->firstWhere('id', "ENT-{$n}");
        expect($entry)->not->toBeNull()
            ->and($entry['status'])->toBe('planned', "ENT-{$n} must stay planned until it has its own evidence + GO tag");
    }
});

it('foundation:roadmap-check --strict stays green after the ENT-2 contract lock', function () {
    $exitCode = Artisan::call('foundation:roadmap-check', ['--strict' => true]);

    expect($exitCode)->toBe(0);
});
