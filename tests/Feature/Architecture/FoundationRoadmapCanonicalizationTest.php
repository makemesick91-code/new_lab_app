<?php

use App\Services\Architecture\FoundationGovernanceSummaryService;
use App\Services\Architecture\FoundationRoadmapService;
use App\Services\Foundation\FoundationRoadmapGovernanceService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;

uses()->group('Architecture', 'FoundationRoadmap', 'Roadmap', 'FoundationGovernance');

it('registers the foundation:roadmap-check command', function () {
    expect(Artisan::all())->toHaveKey('foundation:roadmap-check');
});

it('foundation:roadmap-check exits 0 and reports GO', function () {
    $exitCode = Artisan::call('foundation:roadmap-check');

    expect($exitCode)->toBe(0);
});

it('foundation:roadmap-check --json outputs valid JSON with required keys', function () {
    Artisan::call('foundation:roadmap-check', ['--json' => true]);
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($payload)->toHaveKeys([
        'status', 'decision', 'completed_count', 'completed_sprints', 'current_sprint',
        'next_recommended_sprint', 'stale_next_detected', 'missing_metadata',
        'governance_sections_expected', 'governance_sections_known', 'warnings', 'recommendations',
    ]);
});

it('foundation:roadmap-check --strict passes for the canonical roadmap', function () {
    $exitCode = Artisan::call('foundation:roadmap-check', ['--strict' => true]);

    expect($exitCode)->toBe(0);
});

it('canonical roadmap includes every completed foundation sprint', function () {
    Artisan::call('foundation:roadmap-check', ['--json' => true]);
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($payload['completed_sprints'])->toContain(
        'STORAGE-1', 'STATELESS-1', 'LB-1', 'REPLICA-1',
        'CACHE-1-REDIS-READINESS', 'OBS-1', 'OBS-2', 'ROADMAP-1-CANONICALIZATION',
    );
});

it('next recommended sprint is not any completed sprint', function () {
    Artisan::call('foundation:roadmap-check', ['--json' => true]);
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($payload['completed_sprints'])->not->toContain($payload['next_recommended_sprint'])
        ->and($payload['next_recommended_sprint'])->toBe('ENT-14')
        ->and($payload['stale_next_detected'])->toBeFalse();
});

it('detects a stale next sprint when the upstream report points next at an already-completed id', function () {
    $fakeRoadmap = new class extends FoundationRoadmapService
    {
        public function collect(): array
        {
            return ['summary' => ['decision' => 'GO'], 'next_recommended_sprint' => 'OBS-2', 'approved_sequence' => []];
        }
    };

    app()->instance(FoundationRoadmapService::class, $fakeRoadmap);

    $governance = app(FoundationRoadmapGovernanceService::class)->collect();

    expect($governance['stale_next_detected'])->toBeTrue()
        ->and($governance['decision'])->toBe('WATCH');
});

it('detects missing GO-tag metadata for a completed, governance-tracked entry in strict mode', function () {
    $sequence = config('foundation_roadmap.approved_sequence');
    $mutated = array_map(function (array $item) {
        if ($item['id'] === 'OBS-2') {
            unset($item['go_tag']);
        }

        return $item;
    }, $sequence);

    Config::set('foundation_roadmap.approved_sequence', $mutated);

    $exitCode = Artisan::call('foundation:roadmap-check', ['--strict' => true]);
    $payload = json_decode(json_encode(app(FoundationRoadmapGovernanceService::class)->collect()), true);

    expect($exitCode)->not->toBe(0)
        ->and($payload['missing_metadata'])->toContain('OBS-2');
});

it('old CACHE-1 and CACHE-1-REDIS-READINESS are distinct, disambiguated entries', function () {
    $sequence = collect(config('foundation_roadmap.approved_sequence'));
    $oldCache = $sequence->firstWhere('id', 'CACHE-1');
    $newCache = $sequence->firstWhere('id', 'CACHE-1-REDIS-READINESS');

    expect($oldCache)->not->toBeNull()
        ->and($newCache)->not->toBeNull()
        ->and($oldCache['disambiguation_note'] ?? null)->not->toBeNull()
        ->and($newCache['disambiguates'] ?? null)->toBe('CACHE-1');
});

it('ROADMAP-1-CANONICALIZATION entry is distinct from the source-locked roadmap_id', function () {
    $entry = collect(config('foundation_roadmap.approved_sequence'))->firstWhere('id', 'ROADMAP-1-CANONICALIZATION');

    expect($entry)->not->toBeNull()
        ->and($entry['disambiguates'] ?? null)->toBe('ROADMAP-1')
        ->and(config('foundation_roadmap.roadmap_id'))->toBe('ROADMAP-1');
});

it('governance summary publishes ROADMAP-R001..R010', function () {
    $summary = app(FoundationGovernanceSummaryService::class)->collect();

    expect($summary)->toHaveKey('roadmap_governance')
        ->and($summary['roadmap_governance']['decision'])->toBe('GO');

    $ids = collect($summary['roadmap_governance']['rules'])->pluck('id')->all();
    foreach (range(1, 10) as $n) {
        expect($ids)->toContain(sprintf('ROADMAP-R%03d', $n));
    }
});

it('foundation governance summary keeps every sibling foundation governance section GO', function () {
    $summary = app(FoundationGovernanceSummaryService::class)->collect();

    foreach ([
        'storage_governance', 'stateless_governance', 'lb_governance',
        'database_replica_governance', 'cache_redis_governance', 'cache_governance',
        'observability_governance', 'observability_pipeline_governance', 'roadmap_governance',
    ] as $section) {
        expect($summary[$section]['decision'])->toBe('GO');
    }

    expect($summary['nsf_governance']['summary']['decision'] ?? null)->toBe('GO');
});
