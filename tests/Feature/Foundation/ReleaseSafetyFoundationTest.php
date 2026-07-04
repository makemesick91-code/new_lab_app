<?php

use App\Services\Foundation\ReleaseSafetyService;
use Illuminate\Support\Facades\Artisan;

uses()->group('Foundation', 'ReleaseSafety');

it('release safety config exists', function () {
    $config = config('release_safety');

    expect($config)->toBeArray()
        ->and($config['required_pre_deploy_gates'])->not->toBeEmpty()
        ->and($config['required_deploy_evidence'])->not->toBeEmpty()
        ->and($config['rollback_checklist'])->not->toBeEmpty()
        ->and($config['safety_rules'])->not->toBeEmpty();
});

it('required deploy gates include DQ DMO NSF ROADMAP and foundation summary', function () {
    $gates = implode(' | ', config('release_safety.required_pre_deploy_gates'));

    expect($gates)->toContain('data-quality:dq1-audit')
        ->toContain('inventory:batch-governance-audit')
        ->toContain('inventory:source-document-batch-audit')
        ->toContain('architecture:dmo-governance-check')
        ->toContain('architecture:nsf-governance-check')
        ->toContain('architecture:foundation-roadmap-check')
        ->toContain('architecture:foundation-governance-summary');
});

it('registers the release safety check command', function () {
    expect(Artisan::all())->toHaveKey('foundation:release-safety-check');
});

it('release safety check returns GO or WATCH, never FAIL, in a clean local environment', function () {
    $exitCode = Artisan::call('foundation:release-safety-check');
    $report = app(ReleaseSafetyService::class)->collect();

    expect($exitCode)->toBe(0)
        ->and($report['summary']['decision'])->toBeIn(['GO', 'WATCH']);
});

it('release safety JSON is valid and structured', function () {
    $exitCode = Artisan::call('foundation:release-safety-check', ['--json' => true]);
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(0)
        ->and($payload)->toHaveKeys([
            'required_pre_deploy_gates',
            'required_deploy_evidence',
            'rollback_checklist',
            'safety_rules',
            'checks',
            'summary',
        ]);
});

it('release safety check fails when a required gate command is unregistered', function () {
    config(['release_safety.required_pre_deploy_gates' => ['nonexistent:bogus-command --fail-on=error']]);

    $report = app(ReleaseSafetyService::class)->collect();

    expect($report['summary']['decision'])->toBe('FAIL');
});

it('release safety check fails when config is missing', function () {
    config(['release_safety' => []]);

    $report = app(ReleaseSafetyService::class)->collect();

    expect($report['summary']['decision'])->toBe('FAIL')
        ->and($report['config_exists'])->toBeFalse();
});
