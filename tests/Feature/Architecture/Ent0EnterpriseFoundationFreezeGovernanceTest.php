<?php

use App\Services\Architecture\FoundationRoadmapService;
use App\Services\Foundation\FoundationRoadmapGovernanceService;
use Illuminate\Support\Facades\Artisan;

uses()->group('Architecture', 'FoundationRoadmap', 'Roadmap', 'FoundationGovernance', 'EnterpriseFoundation');

it('ships the enterprise foundation freeze rules doc without release-evidence forbidden literals', function () {
    $path = base_path('docs/architecture/enterprise-foundation-freeze-rules.md');

    expect(file_exists($path))->toBeTrue();

    $doc = file_get_contents($path);
    $forbidden = config('release_evidence.forbidden_patterns', []);

    foreach ($forbidden as $pattern) {
        expect(str_contains($doc, $pattern))->toBeFalse(
            "Freeze rules doc must not contain forbidden release-evidence literal: {$pattern}"
        );
    }

    foreach (config('release_evidence.forbidden_regex', []) as $regex) {
        expect(preg_match($regex, $doc))->toBe(0);
    }

    expect($doc)->toContain('ENT-16')
        ->and($doc)->toContain('enterprise-foundation-go');
});

it('registers ENT-0 and ENT-1 completed and ENT-2..ENT-16 planned in the canonical roadmap', function () {
    $sequence = collect(config('foundation_roadmap.approved_sequence'));

    $ent0 = $sequence->firstWhere('id', 'ENT-0');
    expect($ent0)->not->toBeNull()
        ->and($ent0['status'])->toBe('completed')
        ->and($ent0['category'])->toBe('governance');

    foreach (range(2, 16) as $n) {
        $entry = $sequence->firstWhere('id', "ENT-{$n}");
        expect($entry)->not->toBeNull()
            ->and($entry['status'])->toBe('planned', "ENT-{$n} must stay planned until it has its own evidence + GO tag");
    }
});

it('next recommended sprint is ENT-2 and is not stale', function () {
    $report = app(FoundationRoadmapService::class)->collect();
    $governance = app(FoundationRoadmapGovernanceService::class)->collect();

    expect($report['next_recommended_sprint'])->toBe('ENT-2')
        ->and($governance['stale_next_detected'])->toBeFalse()
        ->and($governance['decision'])->toBe('GO');
});

it('roadmap validator stays GO with the ENT sequence registered and RC-1 last', function () {
    $report = app(FoundationRoadmapService::class)->collect();
    $ordered = collect($report['approved_sequence']);

    expect($report['summary']['decision'])->toBe('GO')
        ->and($report['rc_locked_after_expansion'])->toBeTrue()
        ->and($ordered->last()['id'])->toBe('RC-1');
});

it('maps ENT overlaps to shipped foundations as dependencies, not as completions', function () {
    $sequence = collect(config('foundation_roadmap.approved_sequence'));

    $expectations = [
        'ENT-2' => ['DBPERF-1', 'DBPERF-2'],
        'ENT-3' => ['RPT-1'],
        'ENT-4' => ['CACHE-1', 'CACHE-1-REDIS-READINESS'],
        'ENT-5' => ['QUEUE-1'],
        'ENT-7' => ['OBS-1', 'OBS-2'],
        'ENT-8' => ['OBS-1', 'OBS-2', 'LB-1'],
        'ENT-10' => ['NSF-9', 'NSF-10'],
    ];

    foreach ($expectations as $id => $shipped) {
        $entry = $sequence->firstWhere('id', $id);
        foreach ($shipped as $foundation) {
            expect($entry['related_shipped_foundations'])->toContain($foundation);
        }
    }

    // ENT-4 and ENT-8 carry disambiguation notes for their overlapping siblings.
    expect($sequence->firstWhere('id', 'ENT-4')['disambiguation_note'] ?? null)->not->toBeNull()
        ->and($sequence->firstWhere('id', 'ENT-8')['disambiguation_note'] ?? null)->not->toBeNull();
});

it('keeps MON-1, PART-1, SEARCH-1, NDA-1 registered after the ENT sequence and RC-1 depending on ENT-16', function () {
    $sequence = collect(config('foundation_roadmap.approved_sequence'));

    $ent16Priority = (int) $sequence->firstWhere('id', 'ENT-16')['priority'];
    foreach (['MON-1', 'PART-1', 'SEARCH-1', 'NDA-1', 'RC-1'] as $id) {
        expect((int) $sequence->firstWhere('id', $id)['priority'])->toBeGreaterThan($ent16Priority);
    }

    expect($sequence->firstWhere('id', 'RC-1')['depends_on'])->toContain('ENT-16')
        ->and($sequence->firstWhere('id', 'ENT-16')['depends_on'])->toContain('ENT-15');
});

it('enterprise freeze flag and doc pointer are registered in roadmap rules', function () {
    expect(config('foundation_roadmap.rules.enterprise_foundation_freeze_active'))->toBeTrue()
        ->and(config('foundation_roadmap.rules.enterprise_freeze_rules_doc'))
        ->toBe('docs/architecture/enterprise-foundation-freeze-rules.md');
});

it('foundation:roadmap-check --strict stays green after the ENT reconciliation', function () {
    $exitCode = Artisan::call('foundation:roadmap-check', ['--strict' => true]);

    expect($exitCode)->toBe(0);
});
