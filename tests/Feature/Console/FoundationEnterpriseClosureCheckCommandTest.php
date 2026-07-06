<?php

use Illuminate\Support\Facades\Artisan;

uses()->group('Console', 'FoundationGovernance', 'EnterpriseFoundationClosure', 'ClosureGoNoGo');

it('runs the ENT-16 closure command and reports a GO decision', function () {
    $exit = Artisan::call('foundation:enterprise-closure-check');
    $output = Artisan::output();

    expect($exit)->toBe(0)
        ->and($output)->toContain('Enterprise Foundation Closure')
        ->and($output)->toContain('Decision: GO')
        ->and($output)->toContain('Closure criteria met: 13 / 13')
        ->and($output)->toContain('Next recommended sprint: MON-1');
});

it('emits a non-sensitive JSON closure report', function () {
    Artisan::call('foundation:enterprise-closure-check', ['--json' => true]);
    $json = Artisan::output();

    $report = json_decode($json, true);

    expect($report)->toBeArray()
        ->and($report['decision'])->toBe('GO')
        ->and($report['closure_decision'])->toBe('GO')
        ->and($report['final_closure_tag'])->toBe('enterprise-foundation-go')
        ->and($report['closure_criteria_met'])->toBe(13)
        ->and($report['privacy']['privacy_safe'])->toBeTrue();

    foreach (config('release_evidence.forbidden_patterns', []) as $pattern) {
        expect(str_contains($json, $pattern))->toBeFalse(
            "closure JSON must not contain forbidden literal: {$pattern}"
        );
    }
});

it('exits non-zero under strict when closure is NO-GO', function () {
    config(['release_safety.required_pre_deploy_gates' => []]);

    $exit = Artisan::call('foundation:enterprise-closure-check', ['--strict' => true]);

    expect($exit)->toBe(1);
});
