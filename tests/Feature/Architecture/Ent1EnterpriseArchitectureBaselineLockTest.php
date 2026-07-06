<?php

use App\Services\Architecture\FoundationRoadmapService;
use App\Services\Foundation\FoundationRoadmapGovernanceService;
use Illuminate\Support\Facades\Artisan;

uses()->group('Architecture', 'FoundationRoadmap', 'Roadmap', 'FoundationGovernance', 'EnterpriseFoundation');

it('ships the enterprise architecture baseline lock doc with the mandatory architecture terms', function () {
    $path = base_path('docs/architecture/enterprise-architecture-baseline-lock.md');

    expect(file_exists($path))->toBeTrue();

    $doc = file_get_contents($path);

    expect($doc)->toContain('HTTP → Controller → FormRequest → Service → RepositoryInterface → Repository → Model')
        ->and($doc)->toContain('BranchContext')
        ->and($doc)->toContain('DB::transaction')
        ->and($doc)->toContain('Policy/Gate/RBAC 3-layer rule')
        ->and($doc)->toContain('Additive migration rule')
        ->and($doc)->toContain('No mutable inventory stock rule')
        ->and($doc)->toContain('No PII leakage rule')
        ->and($doc)->toContain('No React/Vue rule')
        ->and($doc)->toContain('No logic in Blade rule')
        ->and($doc)->toContain('No deploy shortcut rule')
        ->and($doc)->toContain('No feature scope outside freeze areas')
        ->and($doc)->toContain('ENT1-R001')
        ->and($doc)->toContain('ENT1-R014');
});

it('keeps the ENT-1 governance docs free of release-evidence forbidden literals', function () {
    $paths = [
        base_path('docs/architecture/enterprise-architecture-baseline-lock.md'),
        base_path('docs/architecture/enterprise-foundation-freeze-rules.md'),
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

it('references the ENT-1 architecture baseline from the enterprise freeze rules doc', function () {
    $doc = file_get_contents(base_path('docs/architecture/enterprise-foundation-freeze-rules.md'));

    expect($doc)->toContain('Enterprise Architecture Baseline Lock')
        ->and($doc)->toContain('enterprise-architecture-baseline-lock.md');
});

it('documents the ENT-1 sprint evidence in CLAUDE.md', function () {
    $doc = file_get_contents(base_path('CLAUDE.md'));

    expect($doc)->toContain('ENT-1 — Enterprise Architecture Baseline Lock')
        ->and($doc)->toContain('enterprise-architecture-baseline-lock.md');
});

it('registers the ENT-1 baseline pointer in the roadmap rules', function () {
    expect(config('foundation_roadmap.rules.enterprise_architecture_baseline_locked'))->toBeTrue()
        ->and(config('foundation_roadmap.rules.enterprise_architecture_baseline_doc'))
        ->toBe('docs/architecture/enterprise-architecture-baseline-lock.md');
});

it('records the ENT-0 GO evidence in the canonical roadmap', function () {
    $ent0 = collect(config('foundation_roadmap.approved_sequence'))->firstWhere('id', 'ENT-0');

    expect($ent0)->not->toBeNull()
        ->and($ent0['status'])->toBe('completed')
        ->and($ent0['go_tag'])->toBe('ent-0-enterprise-foundation-freeze-rules-roadmap-reconciliation-go')
        ->and($ent0['go_commit'])->toBe('8b0fa2d3670a8ce6632a9bd76ad7e4d49b512868');
});

it('registers ENT-1 completed with its GO tag and baseline doc pointer', function () {
    $ent1 = collect(config('foundation_roadmap.approved_sequence'))->firstWhere('id', 'ENT-1');

    expect($ent1)->not->toBeNull()
        ->and($ent1['status'])->toBe('completed')
        ->and($ent1['category'])->toBe('architecture')
        ->and($ent1['go_tag'])->toBe('ent-1-enterprise-architecture-baseline-lock-go')
        ->and($ent1['baseline_doc'])->toBe('docs/architecture/enterprise-architecture-baseline-lock.md');
});

it('moves next recommended sprint past ENT-1 without staleness', function () {
    $report = app(FoundationRoadmapService::class)->collect();
    $governance = app(FoundationRoadmapGovernanceService::class)->collect();

    expect($report['next_recommended_sprint'])->toBe('ENT-7')
        ->and($governance['stale_next_detected'])->toBeFalse()
        ->and($governance['decision'])->toBe('GO');
});

it('keeps ENT-7..ENT-16 planned until they earn their own GO evidence', function () {
    $sequence = collect(config('foundation_roadmap.approved_sequence'));

    foreach (range(7, 16) as $n) {
        $entry = $sequence->firstWhere('id', "ENT-{$n}");
        expect($entry)->not->toBeNull()
            ->and($entry['status'])->toBe('planned', "ENT-{$n} must stay planned until it has its own evidence + GO tag");
    }
});

it('foundation:roadmap-check --strict stays green after the ENT-1 baseline lock', function () {
    $exitCode = Artisan::call('foundation:roadmap-check', ['--strict' => true]);

    expect($exitCode)->toBe(0);
});
