<?php

use App\Services\Architecture\FoundationGovernanceSummaryService;
use App\Services\Architecture\FoundationRoadmapService;
use Illuminate\Support\Facades\Artisan;

uses()->group('Architecture', 'FoundationRoadmap', 'FoundationGovernance');

it('foundation roadmap config exists and is source locked', function () {
    $config = config('foundation_roadmap');

    expect($config)->toBeArray()
        ->and($config['roadmap_id'])->toBe('ROADMAP-1')
        ->and($config['source_locked'])->toBeTrue()
        ->and($config['approved_sequence'])->not->toBeEmpty();
});

it('registers the foundation roadmap check command', function () {
    expect(Artisan::all())->toHaveKey('architecture:foundation-roadmap-check');
});

it('command architecture:foundation-roadmap-check returns GO', function () {
    $exitCode = Artisan::call('architecture:foundation-roadmap-check');

    expect($exitCode)->toBe(0)
        ->and(app(FoundationRoadmapService::class)->collect()['summary']['decision'])->toBe('GO');
});

it('command json includes the approved sequence', function () {
    $exitCode = Artisan::call('architecture:foundation-roadmap-check', ['--json' => true]);
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(0)
        ->and($payload)->toHaveKeys([
            'approved_sequence',
            'next_recommended_sprint',
            'total_planned_sprints',
            'rc_locked_after_expansion',
            'summary',
        ])
        ->and($payload['approved_sequence'])->not->toBeEmpty()
        ->and($payload['summary']['decision'])->toBe('GO');
});

it('all roadmap items have required fields', function () {
    $report = app(FoundationRoadmapService::class)->collect();
    $required = ['id', 'title', 'priority', 'status', 'depends_on', 'objective', 'go_criteria', 'out_of_scope'];

    foreach (config('foundation_roadmap.approved_sequence') as $item) {
        foreach ($required as $field) {
            expect($item[$field] ?? null)->not->toBeNull()
                ->and($item[$field])->not->toBe('')
                ->and($item[$field])->not->toBe([]);
        }
    }

    $fieldsCheck = collect($report['checks'])->firstWhere('check_id', 'ROADMAP-ITEM-FIELDS');
    expect($fieldsCheck['status'])->toBe('passed');
});

it('RC-1 is last after all planned foundation expansion items', function () {
    $report = app(FoundationRoadmapService::class)->collect();
    $sequence = $report['approved_sequence'];
    $last = end($sequence);

    expect($last['id'])->toBe('RC-1')
        ->and($last['is_release_candidate'])->toBeTrue()
        ->and($report['rc_locked_after_expansion'])->toBeTrue();

    $rcLastCheck = collect($report['checks'])->firstWhere('check_id', 'ROADMAP-RC-LAST');
    expect($rcLastCheck['status'])->toBe('passed');
});

it('PART-1 is design-only and not production', function () {
    $part = collect(config('foundation_roadmap.approved_sequence'))->firstWhere('id', 'PART-1');

    expect($part['production_forbidden'])->toBeTrue()
        ->and($part['requires_design_first'])->toBeTrue()
        ->and($part['may_touch_production_infra'])->toBeFalse();

    $check = collect(app(FoundationRoadmapService::class)->collect()['checks'])
        ->firstWhere('check_id', 'ROADMAP-PART1-DESIGN-ONLY');
    expect($check['status'])->toBe('passed');
});

it('REPLICA-1 is readiness-only unless explicitly approved', function () {
    $replica = collect(config('foundation_roadmap.approved_sequence'))->firstWhere('id', 'REPLICA-1');

    expect($replica['readiness_only'])->toBeTrue()
        ->and($replica['may_touch_production_infra'])->toBeFalse();

    $check = collect(app(FoundationRoadmapService::class)->collect()['checks'])
        ->firstWhere('check_id', 'ROADMAP-REPLICA1-READINESS');
    expect($check['status'])->toBe('passed');
});

it('LB-1 depends on STATELESS-1', function () {
    $lb = collect(config('foundation_roadmap.approved_sequence'))->firstWhere('id', 'LB-1');

    expect($lb['depends_on'])->toContain('STATELESS-1');

    $check = collect(app(FoundationRoadmapService::class)->collect()['checks'])
        ->firstWhere('check_id', 'ROADMAP-LB1-DEPENDS-STATELESS');
    expect($check['status'])->toBe('passed');
});

it('CACHE-1 requires invalidation governance', function () {
    $cache = collect(config('foundation_roadmap.approved_sequence'))->firstWhere('id', 'CACHE-1');

    expect($cache['requires_invalidation_rules'])->toBeTrue();

    $check = collect(app(FoundationRoadmapService::class)->collect()['checks'])
        ->firstWhere('check_id', 'ROADMAP-CACHE-INVALIDATION');
    expect($check['status'])->toBe('passed');
});

it('QUEUE-1 requires idempotency and outbox governance', function () {
    $queue = collect(config('foundation_roadmap.approved_sequence'))->firstWhere('id', 'QUEUE-1');

    expect($queue['requires_idempotency_rules'])->toBeTrue()
        ->and($queue['requires_outbox_rules'])->toBeTrue();

    $check = collect(app(FoundationRoadmapService::class)->collect()['checks'])
        ->firstWhere('check_id', 'ROADMAP-QUEUE-IDEMPOTENCY-OUTBOX');
    expect($check['status'])->toBe('passed');
});

it('foundation governance summary includes roadmap status', function () {
    $summary = app(FoundationGovernanceSummaryService::class)->collect();

    expect($summary)->toHaveKey('roadmap')
        ->and($summary['roadmap']['decision'])->toBe('GO')
        ->and($summary['roadmap']['effective_decision'])->toBe('GO')
        ->and($summary['roadmap']['source_locked'])->toBeTrue()
        ->and($summary['summary']['roadmap_decision'])->toBe('GO')
        ->and($summary['summary']['roadmap_active_track'])->toBe('foundation_expansion');
});

it('next recommended sprint is STATELESS-1 after STORAGE-1 completion', function () {
    $report = app(FoundationRoadmapService::class)->collect();

    expect($report['next_recommended_sprint'])->toBe('STATELESS-1')
        ->and(collect($report['approved_sequence'])->firstWhere('id', 'QUEUE-1')['status'])->toBe('completed')
        ->and(collect($report['approved_sequence'])->firstWhere('id', 'DBPERF-1')['status'])->toBe('completed')
        ->and(collect($report['approved_sequence'])->firstWhere('id', 'DBPERF-2')['status'])->toBe('completed')
        ->and(collect($report['approved_sequence'])->firstWhere('id', 'RPT-1')['status'])->toBe('completed')
        ->and(collect($report['approved_sequence'])->firstWhere('id', 'STORAGE-1')['status'])->toBe('completed')
        ->and($report['active_track'])->toBe('foundation_expansion');
});

it('DBPERF-2 depends on DBPERF-1 and RPT-1 depends on DBPERF-2', function () {
    $sequence = collect(config('foundation_roadmap.approved_sequence'));

    expect($sequence->firstWhere('id', 'DBPERF-2')['depends_on'])->toContain('DBPERF-1')
        ->and($sequence->firstWhere('id', 'RPT-1')['depends_on'])->toContain('DBPERF-2');
});
