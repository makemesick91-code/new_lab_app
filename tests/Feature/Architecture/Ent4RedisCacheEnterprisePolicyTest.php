<?php

use App\Services\Architecture\FoundationRoadmapService;
use App\Services\Foundation\FoundationRoadmapGovernanceService;
use Illuminate\Support\Facades\Artisan;

uses()->group('Architecture', 'FoundationRoadmap', 'Roadmap', 'FoundationGovernance', 'EnterpriseFoundation');

it('ships the redis cache enterprise policy doc with all CACHE rules', function () {
    $path = base_path('docs/architecture/redis-cache-enterprise-policy.md');

    expect(file_exists($path))->toBeTrue();

    $doc = file_get_contents($path);

    foreach (range(1, 18) as $n) {
        $ruleId = sprintf('CACHE-R%03d', $n);
        expect($doc)->toContain($ruleId);
    }

    expect($doc)->toContain('dms:{env}:{domain}:{scope}:{identifier}:{version}')
        ->and($doc)->toContain('Redis is the preferred shared cache/session backend')
        ->and($doc)->toContain('Cache is an accelerator only')
        ->and($doc)->toContain('Branch-owned cache keys')
        ->and($doc)->toContain('Cross-branch cached analytics')
        ->and($doc)->toContain('PII')
        ->and($doc)->toContain('full KTP/NIK')
        ->and($doc)->toContain('session secrets')
        ->and($doc)->toContain('TTL must be explicit')
        ->and($doc)->toContain('Cache invalidation must not rely on UI actions only')
        ->and($doc)->toContain('Inventory stock remains ledger-derived')
        ->and($doc)->toContain('trx_inventory_movements')
        ->and($doc)->toContain('Critical writes must not fail solely because non-critical cache invalidation failed');
});

it('ships the TTL matrix covering required cache domains', function () {
    $path = base_path('docs/architecture/cache-ttl-matrix.md');

    expect(file_exists($path))->toBeTrue();

    $doc = file_get_contents($path);

    expect($doc)->toContain('Owner dashboard KPI')
        ->and($doc)->toContain('RME patient lookup metadata')
        ->and($doc)->toContain('RME reports')
        ->and($doc)->toContain('RME receivable aging')
        ->and($doc)->toContain('Inventory current stock derived read')
        ->and($doc)->toContain('Inventory valuation/reporting')
        ->and($doc)->toContain('Lab order/candidate counts')
        ->and($doc)->toContain('Master data: branches')
        ->and($doc)->toContain('Feature flags/governance metadata')
        ->and($doc)->toContain('Health/readiness checks')
        ->and($doc)->toContain('Forbidden sensitive payloads')
        ->and($doc)->toContain('Default TTL')
        ->and($doc)->toContain('Max TTL')
        ->and($doc)->toContain('Source of truth')
        ->and($doc)->toContain('prohibited');
});

it('ships the invalidation matrix covering required write events', function () {
    $path = base_path('docs/architecture/cache-invalidation-matrix.md');

    expect(file_exists($path))->toBeTrue();

    $doc = file_get_contents($path);

    expect($doc)->toContain('Patient created/updated')
        ->and($doc)->toContain('Clinic visit created/status changed')
        ->and($doc)->toContain('Medical record finalized/updated')
        ->and($doc)->toContain('Invoice/payment created/updated')
        ->and($doc)->toContain('Follow-up created')
        ->and($doc)->toContain('Lab candidate generated/converted')
        ->and($doc)->toContain('Movement created')
        ->and($doc)->toContain('Transfer shipped/received')
        ->and($doc)->toContain('Stock opname finalized')
        ->and($doc)->toContain('Product, batch, unit, tariff, payment method, room changed')
        ->and($doc)->toContain('Report summary refreshed')
        ->and($doc)->toContain('Fallback if Redis unavailable')
        ->and($doc)->toContain('Future queue/outbox tie-in');
});

it('ships the redis readiness runbook without forcing runtime driver changes', function () {
    $path = base_path('docs/architecture/redis-readiness-runbook.md');

    expect(file_exists($path))->toBeTrue();

    $doc = file_get_contents($path);

    expect($doc)->toContain('ENT-4 does not enable Redis in production')
        ->and($doc)->toContain('config/cache.php')
        ->and($doc)->toContain('config/database.php')
        ->and($doc)->toContain('config/session.php')
        ->and($doc)->toContain('config/queue.php')
        ->and($doc)->toContain('php artisan cache:redis-readiness-check')
        ->and($doc)->toContain('php artisan foundation:cache-governance-check --json')
        ->and($doc)->toContain('redis-cli ping')
        ->and($doc)->toContain('Do not run `FLUSHDB`, `FLUSHALL`')
        ->and($doc)->toContain('Rollback to safe mode')
        ->and($doc)->toContain('pilot readiness warning');
});

it('keeps the ENT-4 governance docs free of release-evidence forbidden literals', function () {
    $paths = [
        base_path('docs/architecture/redis-cache-enterprise-policy.md'),
        base_path('docs/architecture/cache-ttl-matrix.md'),
        base_path('docs/architecture/cache-invalidation-matrix.md'),
        base_path('docs/architecture/redis-readiness-runbook.md'),
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

it('references ENT-4 policy from durable governance docs and cursor rules', function () {
    $freeze = file_get_contents(base_path('docs/architecture/enterprise-foundation-freeze-rules.md'));
    $dbperf = file_get_contents(base_path('docs/architecture/database-performance-contract.md'));
    $reporting = file_get_contents(base_path('docs/architecture/reporting-materialized-summary-contract.md'));
    $baseline = file_get_contents(base_path('docs/architecture/enterprise-architecture-baseline-lock.md'));
    $cursor = file_get_contents(base_path('.cursor/rules/53-redis-cache-enterprise-policy.mdc'));

    expect($freeze)->toContain('redis-cache-enterprise-policy.md')
        ->and($freeze)->toContain('cache-ttl-matrix.md')
        ->and($freeze)->toContain('cache-invalidation-matrix.md')
        ->and($freeze)->toContain('redis-readiness-runbook.md')
        ->and($dbperf)->toContain('redis-cache-enterprise-policy.md')
        ->and($reporting)->toContain('cache-ttl-matrix.md')
        ->and($baseline)->toContain('redis-cache-enterprise-policy.md')
        ->and($cursor)->toContain('dms:{env}:{domain}:{scope}:{identifier}:{version}');
});

it('documents the ENT-4 sprint evidence in CLAUDE.md', function () {
    $doc = file_get_contents(base_path('CLAUDE.md'));

    expect($doc)->toContain('ENT-4 — Redis Cache Enterprise Policy')
        ->and($doc)->toContain('redis-cache-enterprise-policy.md')
        ->and($doc)->toContain('cache-ttl-matrix.md')
        ->and($doc)->toContain('cache-invalidation-matrix.md')
        ->and($doc)->toContain('redis-readiness-runbook.md');
});

it('registers the ENT-4 policy pointers in roadmap rules', function () {
    expect(config('foundation_roadmap.rules.redis_cache_enterprise_policy_locked'))->toBeTrue()
        ->and(config('foundation_roadmap.rules.redis_cache_enterprise_policy_doc'))->toBe('docs/architecture/redis-cache-enterprise-policy.md')
        ->and(config('foundation_roadmap.rules.cache_ttl_matrix_doc'))->toBe('docs/architecture/cache-ttl-matrix.md')
        ->and(config('foundation_roadmap.rules.cache_invalidation_matrix_doc'))->toBe('docs/architecture/cache-invalidation-matrix.md')
        ->and(config('foundation_roadmap.rules.redis_readiness_runbook_doc'))->toBe('docs/architecture/redis-readiness-runbook.md');
});

it('records ENT-3 deploy evidence and registers ENT-4 completed with doc pointers', function () {
    $sequence = collect(config('foundation_roadmap.approved_sequence'));
    $ent3 = $sequence->firstWhere('id', 'ENT-3');
    $ent4 = $sequence->firstWhere('id', 'ENT-4');

    expect($ent3)->not->toBeNull()
        ->and($ent3['go_tag'])->toBe('ent-3-reporting-materialized-summary-expansion-go')
        ->and($ent3['go_commit'])->toBe('359f884a566c601a48f6ab6461c71a5efa9839ed')
        ->and($ent3['deploy_evidence_commit'])->toBe('3c98165')
        ->and($ent4)->not->toBeNull()
        ->and($ent4['status'])->toBe('completed')
        ->and($ent4['category'])->toBe('cache')
        ->and($ent4['policy_doc'])->toBe('docs/architecture/redis-cache-enterprise-policy.md')
        ->and($ent4['ttl_matrix_doc'])->toBe('docs/architecture/cache-ttl-matrix.md')
        ->and($ent4['invalidation_matrix_doc'])->toBe('docs/architecture/cache-invalidation-matrix.md')
        ->and($ent4['readiness_runbook_doc'])->toBe('docs/architecture/redis-readiness-runbook.md')
        ->and($ent4['go_tag'])->toBe('ent-4-redis-cache-enterprise-policy-go')
        ->and($ent4['related_shipped_foundations'])->toContain('CACHE-1')
        ->and($ent4['related_shipped_foundations'])->toContain('CACHE-1-REDIS-READINESS');
});

it('moves next recommended sprint to ENT-9 without staleness', function () {
    $report = app(FoundationRoadmapService::class)->collect();
    $governance = app(FoundationRoadmapGovernanceService::class)->collect();

    expect($report['next_recommended_sprint'])->toBe('ENT-14')
        ->and($governance['stale_next_detected'])->toBeFalse()
        ->and($governance['decision'])->toBe('GO');
});

it('keeps ENT-9..ENT-16 planned until they earn their own GO evidence', function () {
    $sequence = collect(config('foundation_roadmap.approved_sequence'));

    foreach (range(14, 16) as $n) {
        $entry = $sequence->firstWhere('id', "ENT-{$n}");
        expect($entry)->not->toBeNull()
            ->and($entry['status'])->toBe('planned', "ENT-{$n} must stay planned until it has its own evidence + GO tag");
    }
});

it('foundation:roadmap-check --strict stays green after the ENT-4 policy lock', function () {
    $exitCode = Artisan::call('foundation:roadmap-check', ['--strict' => true]);

    expect($exitCode)->toBe(0);
});
