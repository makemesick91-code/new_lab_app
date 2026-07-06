<?php

use Illuminate\Support\Facades\Artisan;

uses()->group('Console', 'EnterpriseDocumentation', 'EnterpriseRunbook');

it('renders the enterprise runbook summary as JSON', function () {
    $exit = Artisan::call('docs:enterprise-runbook-summary', ['--json' => true]);
    $output = Artisan::output();

    expect($exit)->toBe(0);

    $report = json_decode($output, true);

    expect($report)->toBeArray()
        ->and($report['sprint'])->toBe('ENT-15')
        ->and($report['all_files_present'])->toBeTrue()
        ->and($report['missing_topics'])->toBe([])
        ->and($report['runbook_count'])->toBeGreaterThanOrEqual(5)
        ->and($report['runbooks'])->not->toBeEmpty();

    foreach ($report['runbooks'] as $runbook) {
        expect($runbook['present'])->toBeTrue();
    }
});

it('renders the enterprise runbook summary in console mode', function () {
    $exit = Artisan::call('docs:enterprise-runbook-summary');

    expect($exit)->toBe(0)
        ->and(Artisan::output())->toContain('Enterprise Runbook Registry');
});
