<?php

use App\Services\Architecture\FoundationRoadmapService;
use App\Services\Foundation\FoundationRoadmapGovernanceService;
use Illuminate\Support\Facades\Artisan;

uses()->group('Architecture', 'FoundationRoadmap', 'Roadmap', 'FoundationGovernance', 'EnterpriseFoundation');

it('ships the reporting materialized summary contract doc with all RPTSUM rules', function () {
    $path = base_path('docs/architecture/reporting-materialized-summary-contract.md');

    expect(file_exists($path))->toBeTrue();

    $doc = file_get_contents($path);

    foreach (range(1, 16) as $n) {
        $ruleId = sprintf('RPTSUM-R%03d', $n);
        expect($doc)->toContain($ruleId);
    }

    expect($doc)->toContain('rpt_*')
        ->and($doc)->toContain('sys_*')
        ->and($doc)->toContain('stg_*')
        ->and($doc)->toContain('queued job')
        ->and($doc)->toContain('scheduled command')
        ->and($doc)->toContain('manual rebuild')
        ->and($doc)->toContain('real-time')
        ->and($doc)->toContain('hourly')
        ->and($doc)->toContain('daily')
        ->and($doc)->toContain('source of truth')
        ->and($doc)->toContain('idempotent')
        ->and($doc)->toContain('BranchContext')
        ->and($doc)->toContain('permission-gated')
        ->and($doc)->toContain('RPT-1')
        ->and($doc)->toContain('DBPERF')
        ->and($doc)->toContain('ENT-4')
        ->and($doc)->toContain('ENT-5')
        ->and($doc)->toContain('ENT-7');
});

it('keeps the ledger-derived stock rule and the PII masking rule in the summary contract', function () {
    $doc = file_get_contents(base_path('docs/architecture/reporting-materialized-summary-contract.md'));

    expect($doc)->toContain('trx_inventory_movements')
        ->and($doc)->toContain('never become a mutable stock column')
        ->and($doc)->toContain('mask PII')
        ->and($doc)->toContain('KTP/NIK');
});

it('ships the reporting summary candidate inventory covering the named report domains', function () {
    $path = base_path('docs/architecture/reporting-summary-candidate-inventory.md');

    expect(file_exists($path))->toBeTrue();

    $doc = file_get_contents($path);

    expect($doc)->toContain('Owner dashboard KPI')
        ->and($doc)->toContain('RME patient report')
        ->and($doc)->toContain('RME payment report')
        ->and($doc)->toContain('RME receivable aging')
        ->and($doc)->toContain('Inventory current stock')
        ->and($doc)->toContain('Inventory stock card')
        ->and($doc)->toContain('Inventory valuation')
        ->and($doc)->toContain('low-stock / expiry alerts')
        ->and($doc)->toContain('Lab order / candidate reporting')
        ->and($doc)->toContain('Branch-level analytics')
        ->and($doc)->toContain('national executive analytics')
        ->and($doc)->toContain('verified')
        ->and($doc)->toContain('TODO');
});

it('keeps the ENT-3 governance docs free of release-evidence forbidden literals', function () {
    $paths = [
        base_path('docs/architecture/reporting-materialized-summary-contract.md'),
        base_path('docs/architecture/reporting-summary-candidate-inventory.md'),
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

it('references the ENT-3 reporting summary contract from the enterprise freeze rules doc', function () {
    $doc = file_get_contents(base_path('docs/architecture/enterprise-foundation-freeze-rules.md'));

    expect($doc)->toContain('Reporting Materialized Summary Expansion')
        ->and($doc)->toContain('reporting-materialized-summary-contract.md')
        ->and($doc)->toContain('reporting-summary-candidate-inventory.md');
});

it('references the ENT-3 summary expansion from the database performance contract', function () {
    $doc = file_get_contents(base_path('docs/architecture/database-performance-contract.md'));

    expect($doc)->toContain('reporting-materialized-summary-contract.md')
        ->and($doc)->toContain('RPTSUM-R001..RPTSUM-R016');
});

it('ships the cursor mirror rule for the reporting materialized summary contract', function () {
    $path = base_path('.cursor/rules/52-reporting-materialized-summary.mdc');

    expect(file_exists($path))->toBeTrue();

    $doc = file_get_contents($path);

    expect($doc)->toContain('reporting-materialized-summary-contract.md')
        ->and($doc)->toContain('RPTSUM-R001..RPTSUM-R016');
});

it('documents the ENT-3 sprint evidence in CLAUDE.md', function () {
    $doc = file_get_contents(base_path('CLAUDE.md'));

    expect($doc)->toContain('ENT-3 — Reporting Materialized Summary Expansion')
        ->and($doc)->toContain('reporting-materialized-summary-contract.md');
});

it('registers the ENT-3 contract pointer in the roadmap rules', function () {
    expect(config('foundation_roadmap.rules.reporting_materialized_summary_contract_locked'))->toBeTrue()
        ->and(config('foundation_roadmap.rules.reporting_materialized_summary_contract_doc'))
        ->toBe('docs/architecture/reporting-materialized-summary-contract.md');
});

it('records the ENT-2 GO and deploy evidence in the canonical roadmap', function () {
    $ent2 = collect(config('foundation_roadmap.approved_sequence'))->firstWhere('id', 'ENT-2');

    expect($ent2)->not->toBeNull()
        ->and($ent2['status'])->toBe('completed')
        ->and($ent2['go_tag'])->toBe('ent-2-database-performance-contract-go')
        ->and($ent2['go_commit'])->toBe('89e641d31b43820873d44db196a53da56cc3175b')
        ->and($ent2['deploy_evidence_commit'])->toBe('22a411d');
});

it('registers ENT-3 completed with its GO tag and contract doc pointers', function () {
    $ent3 = collect(config('foundation_roadmap.approved_sequence'))->firstWhere('id', 'ENT-3');

    expect($ent3)->not->toBeNull()
        ->and($ent3['status'])->toBe('completed')
        ->and($ent3['category'])->toBe('reporting')
        ->and($ent3['go_tag'])->toBe('ent-3-reporting-materialized-summary-expansion-go')
        ->and($ent3['contract_doc'])->toBe('docs/architecture/reporting-materialized-summary-contract.md')
        ->and($ent3['candidate_inventory_doc'])->toBe('docs/architecture/reporting-summary-candidate-inventory.md')
        ->and($ent3['related_shipped_foundations'])->toContain('RPT-1');
});

it('moves next recommended sprint to ENT-9 after the cache policy lock', function () {
    $report = app(FoundationRoadmapService::class)->collect();
    $governance = app(FoundationRoadmapGovernanceService::class)->collect();

    expect($report['next_recommended_sprint'])->toBe('ENT-9')
        ->and($governance['stale_next_detected'])->toBeFalse()
        ->and($governance['decision'])->toBe('GO');
});

it('keeps ENT-9..ENT-16 planned until they earn their own GO evidence', function () {
    $sequence = collect(config('foundation_roadmap.approved_sequence'));

    foreach (range(9, 16) as $n) {
        $entry = $sequence->firstWhere('id', "ENT-{$n}");
        expect($entry)->not->toBeNull()
            ->and($entry['status'])->toBe('planned', "ENT-{$n} must stay planned until it has its own evidence + GO tag");
    }
});

it('adds no physical summary migration in ENT-3', function () {
    $migrations = glob(database_path('migrations/2026_07_*'));

    foreach ($migrations as $migration) {
        expect(str_contains(basename($migration), 'rpt_'))->toBeFalse(
            'ENT-3 must not ship a physical rpt_* summary migration: '.basename($migration)
        );
    }
});

it('foundation:roadmap-check --strict stays green after the ENT-3 contract lock', function () {
    $exitCode = Artisan::call('foundation:roadmap-check', ['--strict' => true]);

    expect($exitCode)->toBe(0);
});
