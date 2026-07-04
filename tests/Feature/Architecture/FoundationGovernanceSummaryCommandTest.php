<?php

use App\Services\Architecture\FoundationGovernanceSummaryService;
use Illuminate\Support\Facades\Artisan;

uses()->group('Architecture', 'Fg1', 'FoundationGovernance');

it('registers architecture foundation governance summary command', function () {
    expect(Artisan::all())->toHaveKey('architecture:foundation-governance-summary');
});

it('foundation summary json includes fg1 sections and dq chain', function () {
    $exitCode = Artisan::call('architecture:foundation-governance-summary', ['--json' => true]);
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(0)
        ->and($payload)->toHaveKeys([
            'summary',
            'watch_causes',
            'dq_chain',
            'fg1_checks',
            'evidence_docs',
            'combined',
            'nsf_governance',
            'dmo_governance',
        ])
        ->and($payload['summary'])->toHaveKeys([
            'nsf_decision',
            'nsf_effective_decision',
            'dmo_decision',
            'dmo_effective_decision',
            'dq_decision',
            'combined_decision',
            'combined_reason',
        ])
        ->and($payload['dq_chain']['items'])->toHaveCount(4);
});

it('foundation summary includes dq chain go after dq evidence baseline', function () {
    $summary = app(FoundationGovernanceSummaryService::class)->collect();

    expect($summary['dq_chain']['decision'])->toBe('GO')
        ->and($summary['summary']['dq1_decision'])->toBe('GO')
        ->and($summary['summary']['dq2_decision'])->toBe('GO')
        ->and($summary['summary']['dq3_decision'])->toBe('GO')
        ->and($summary['summary']['dq31_decision'])->toBe('GO');
});

it('foundation summary reports nsf go after ci evidence gate automation', function () {
    $summary = app(FoundationGovernanceSummaryService::class)->collect();

    expect($summary['summary']['nsf_decision'])->toBe('GO')
        ->and($summary['summary']['nsf_effective_decision'])->toBe('GO')
        ->and($summary['ci_evidence_gates']['workflow_exists'])->toBeTrue()
        ->and($summary['watch_causes']['nsf'])->toBeEmpty();
});

it('foundation summary reports dmo go after deferred metric closure', function () {
    $summary = app(FoundationGovernanceSummaryService::class)->collect();

    expect($summary['summary']['dmo_decision'])->toBe('GO')
        ->and($summary['watch_causes']['dmo'])->toBeEmpty();
});

it('foundation summary combined go when only non blocking watch remains', function () {
    $summary = app(FoundationGovernanceSummaryService::class)->collect();

    expect($summary['summary']['combined_decision'])->toBe('GO')
        ->and($summary['summary']['combined_blocking_watch_count'])->toBe(0)
        ->and($summary['summary']['nsf_blocking_warnings'])->toBe(0)
        ->and($summary['summary']['dmo_blocking_warnings'])->toBe(0)
        ->and($summary['summary']['nsf_effective_decision'])->toBe('GO')
        ->and($summary['summary']['dmo_effective_decision'])->toBe('GO');
});

it('foundation summary deferred backlog items are non blocking when present', function () {
    $summary = app(FoundationGovernanceSummaryService::class)->collect();

    $deferred = collect($summary['watch_causes']['dmo'])
        ->merge($summary['watch_causes']['nsf'])
        ->where('classification', 'deferred_backlog');

    expect($deferred->every(fn (array $item) => $item['blocking'] === false))->toBeTrue();
});

it('foundation summary text output names watch causes', function () {
    Artisan::call('architecture:foundation-governance-summary');
    $output = Artisan::output();

    expect($output)->toContain('Foundation Governance Summary (FG-1)')
        ->and($output)->toContain('NSF:')
        ->and($output)->toContain('DMO:')
        ->and($output)->toContain('DQ:')
        ->and($output)->toContain('Combined:')
        ->and($output)->toMatch('/NSF: GO/');
});

it('foundation summary fg1 checks include dq chain check', function () {
    $summary = app(FoundationGovernanceSummaryService::class)->collect();

    $dqCheck = collect($summary['fg1_checks'])->firstWhere('check_id', 'FG1-DQ-001');

    expect($dqCheck)->not->toBeNull()
        ->and($dqCheck['status'])->toBe('passed');
});

it('foundation summary lists evidence doc paths', function () {
    $summary = app(FoundationGovernanceSummaryService::class)->collect();

    expect($summary['evidence_docs'])->not->toBeEmpty();

    $dq1 = collect($summary['evidence_docs'])->firstWhere('key', 'dq-1');
    expect($dq1)->not->toBeNull()
        ->and($dq1['path'])->toContain('dq-1');
});

it('dq1 audit remains go in clean fixture', function () {
    Artisan::call('data-quality:dq1-audit', ['--json' => true]);
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($payload['summary']['decision'])->toBe('GO');
});
