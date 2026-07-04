<?php

use App\Services\Architecture\FoundationGovernanceSummaryService;
use Illuminate\Support\Facades\Artisan;

uses()->group('Architecture', 'Nsf7', 'FoundationGovernance');

it('classifies nsf r011 as automated ci gate in foundation config', function () {
    expect(config('foundation_governance.rule_classifications.NSF-R011'))->toBe('automated_ci_gate');
});

it('classifies nsf r012 as automated ci gate in foundation config', function () {
    expect(config('foundation_governance.rule_classifications.NSF-R012'))->toBe('automated_ci_gate');
});

it('closes nsf m001 and m002 deferred backlog in foundation config', function () {
    expect(config('foundation_governance.deferred_backlog'))->toBeEmpty()
        ->and(config('foundation_governance.resolved_ci_gates'))->toHaveKeys(['NSF-M001', 'NSF-M002'])
        ->and(config('nsf.deferred_warnings'))->toBeEmpty();
});

it('foundation summary json includes ci evidence gates with workflow path', function () {
    $exitCode = Artisan::call('architecture:foundation-governance-summary', ['--json' => true]);
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(0)
        ->and($payload)->toHaveKeys(['ci_evidence_gates', 'resolved_ci_gates'])
        ->and($payload['ci_evidence_gates']['workflow'])->toBe('.github/workflows/foundation-evidence-gates.yml')
        ->and($payload['ci_evidence_gates']['workflow_exists'])->toBeTrue()
        ->and($payload['ci_evidence_gates']['script_exists'])->toBeTrue()
        ->and($payload['ci_evidence_gates']['gates'])->toHaveKeys(['NSF-R011', 'NSF-R012']);
});

it('foundation summary nsf r011 and r012 are passed not evidence only warnings', function () {
    $summary = app(FoundationGovernanceSummaryService::class)->collect();

    $r011 = collect($summary['watch_causes']['nsf'])->firstWhere('rule_id', 'NSF-R011');
    $r012 = collect($summary['watch_causes']['nsf'])->firstWhere('rule_id', 'NSF-R012');

    expect($summary['summary']['nsf_decision'])->toBe('GO')
        ->and($summary['summary']['nsf_effective_decision'])->toBe('GO')
        ->and($r011)->toBeNull()
        ->and($r012)->toBeNull();
});

it('foundation summary combined go with dq and dmo go after nsf7 ci gates', function () {
    $summary = app(FoundationGovernanceSummaryService::class)->collect();

    expect($summary['summary']['combined_decision'])->toBe('GO')
        ->and($summary['summary']['dmo_decision'])->toBe('GO')
        ->and($summary['dq_chain']['decision'])->toBe('GO')
        ->and($summary['summary']['combined_blocking_watch_count'])->toBe(0);
});

it('foundation summary fg1 ci check passes when workflow exists', function () {
    $summary = app(FoundationGovernanceSummaryService::class)->collect();
    $ciCheck = collect($summary['fg1_checks'])->firstWhere('check_id', 'FG1-CI-001');

    expect($ciCheck)->not->toBeNull()
        ->and($ciCheck['status'])->toBe('passed');
});

it('nsf governance check reports r011 and r012 passed with ci workflow', function () {
    Artisan::call('architecture:nsf-governance-check', ['--json' => true]);
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    $r011 = collect($payload['rules'])->firstWhere('rule_id', 'NSF-R011');
    $r012 = collect($payload['rules'])->firstWhere('rule_id', 'NSF-R012');

    expect($r011['status'])->toBe('passed')
        ->and($r012['status'])->toBe('passed')
        ->and(collect($payload['rules'])->pluck('rule_id'))->not->toContain('NSF-M001', 'NSF-M002');
});

it('foundation evidence gates script exists and is executable', function () {
    $script = base_path('scripts/ci/foundation-evidence-gates.sh');

    expect(is_file($script))->toBeTrue()
        ->and(is_executable($script))->toBeTrue();
});

it('foundation evidence gates workflow file exists', function () {
    expect(is_file(base_path('.github/workflows/foundation-evidence-gates.yml')))->toBeTrue();
});
