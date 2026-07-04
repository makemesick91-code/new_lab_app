<?php

use App\Services\Architecture\FoundationGovernanceSummaryService;
use Illuminate\Support\Facades\Artisan;

uses()->group('Architecture', 'Nsf8', 'FoundationGovernance');

it('nsf r009 is skipped transparently without include observability', function () {
    Artisan::call('architecture:nsf-governance-check', ['--json' => true]);
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    $r009 = collect($payload['rules'])->firstWhere('rule_id', 'NSF-R009');

    if (config('database.default') === 'pgsql') {
        expect($r009['status'])->toBe('skipped')
            ->and($r009['message'])->toContain('--include-observability');
    } else {
        expect($r009['status'])->toBe('not_applicable');
    }

    expect($payload['summary']['decision'])->toBe('GO');
});

it('nsf r009 is not applicable on non pgsql drivers with include observability', function () {
    Artisan::call('architecture:nsf-governance-check', [
        '--json' => true,
        '--include-observability' => true,
    ]);
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    $r009 = collect($payload['rules'])->firstWhere('rule_id', 'NSF-R009');

    if (config('database.default') !== 'pgsql') {
        expect($r009['status'])->toBe('not_applicable');
    }
});

it('nsf r009 includes pg stat database evidence when observability enabled on pgsql', function () {
    if (config('database.default') !== 'pgsql') {
        expect(true)->toBeTrue();

        return;
    }

    Artisan::call('architecture:nsf-governance-check', [
        '--json' => true,
        '--include-observability' => true,
    ]);
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    $r009 = collect($payload['rules'])->firstWhere('rule_id', 'NSF-R009');
    $observability = $payload['observability'] ?? [];

    expect($observability)->toHaveKey('pg_stat_database')
        ->and(in_array($r009['status'], ['passed', 'warning'], true))->toBeTrue()
        ->and($r009['message'])->toContain('pg_stat');
});

it('foundation summary nsf raw go when observability passes on current driver', function () {
    $summary = app(FoundationGovernanceSummaryService::class)->collect();

    expect($summary['summary']['nsf_decision'])->toBe('GO')
        ->and($summary['summary']['nsf_effective_decision'])->toBe('GO');
});

it('foundation summary combined go with dq dmo and nsf after nsf8', function () {
    $summary = app(FoundationGovernanceSummaryService::class)->collect();

    expect($summary['summary']['combined_decision'])->toBe('GO')
        ->and($summary['summary']['dmo_decision'])->toBe('GO')
        ->and($summary['dq_chain']['decision'])->toBe('GO')
        ->and($summary['summary']['combined_blocking_watch_count'])->toBe(0);
});

it('nsf r011 and r012 remain passed after nsf8', function () {
    Artisan::call('architecture:nsf-governance-check', ['--json' => true]);
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    $r011 = collect($payload['rules'])->firstWhere('rule_id', 'NSF-R011');
    $r012 = collect($payload['rules'])->firstWhere('rule_id', 'NSF-R012');

    expect($r011['status'])->toBe('passed')
        ->and($r012['status'])->toBe('passed');
});

it('deploy gate docs and scripts mention include observability', function () {
    $deployScript = file_get_contents(base_path('scripts/deploy-vps.sh'));
    $deployDocs = file_get_contents(base_path('docs/architecture/nsf-governance-deploy-gates.md'));
    $nsf8Docs = file_get_contents(base_path('docs/architecture/nsf-8-node20-observability-raw-go-closure.md'));

    expect($deployScript)->toContain('--include-observability')
        ->and($deployDocs)->toContain('--include-observability')
        ->and($nsf8Docs)->toContain('Node 20+')
        ->and($nsf8Docs)->toContain('pg_stat_database');
});

it('package json requires node 20 or newer', function () {
    expect(config('foundation_governance.sprint'))->toBe('NSF-8')
        ->and(file_get_contents(base_path('package.json')))->toContain('">=20"');
});
