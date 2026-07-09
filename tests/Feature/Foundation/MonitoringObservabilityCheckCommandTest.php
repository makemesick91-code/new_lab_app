<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Console\Output\BufferedOutput;

uses()->group('Foundation', 'FoundationMonitoring', 'EnterpriseFoundation');

beforeEach(function () {
    seedAccessControl();
});

it('runs the default lightweight check and exits 0', function () {
    $this->artisan('foundation:monitoring-observability-check')
        ->assertExitCode(0)
        ->expectsOutputToContain('MON-1');
});

it('emits parseable JSON with a decision and signals and no app secrets', function () {
    $this->artisan('foundation:monitoring-observability-check --json')->assertExitCode(0);

    $json = json_decode(monitoringCommandJson(), true);

    expect($json)->toBeArray()
        ->and($json)->toHaveKeys(['decision', 'unsafe', 'summary', 'signals'])
        ->and($json['decision'])->toBeIn(['GO', 'WATCH', 'FAIL', 'UNKNOWN']);

    $raw = monitoringCommandJson();
    expect($raw)->not->toContain((string) config('app.key'));

    $dbPassword = (string) config('database.connections.'.config('database.default').'.password');
    if ($dbPassword !== '') {
        expect($raw)->not->toContain($dbPassword);
    }
});

it('exits 0 on a WATCH/UNKNOWN state even with --strict (WATCH is not unsafe)', function () {
    // Default local/testing state is at worst WATCH — never an unsafe FAIL.
    $this->artisan('foundation:monitoring-observability-check --strict')->assertExitCode(0);
});

it('exits non-zero on --strict when a real unsafe FAIL is present', function () {
    config(['app.debug' => true, 'foundation_monitoring.production_like_environments' => ['testing']]);

    $this->artisan('foundation:monitoring-observability-check --strict')->assertExitCode(1);
});

it('reports existing audit command status with --include-audits without replacing them', function () {
    $this->artisan('foundation:monitoring-observability-check --include-audits --json')->assertExitCode(0);

    $json = json_decode(monitoringCommandJson(['--include-audits' => true, '--json' => true]), true);

    $audit = collect($json['signals'])->firstWhere('key', 'audit_doctor_performance_access');

    expect($audit['details']['executed'])->toBeTrue()
        ->and($audit['details'])->toHaveKey('exit_code')
        ->and($audit['details']['command'])->toBe('rme:doctor-performance-access-audit');
});

it('handles a missing failed_jobs table gracefully in the command', function () {
    Schema::dropIfExists('failed_jobs');

    $this->artisan('foundation:monitoring-observability-check')->assertExitCode(0);
});

it('surfaces a storage/cache writable signal', function () {
    $json = json_decode(monitoringCommandJson(), true);

    $signal = collect($json['signals'])->firstWhere('key', 'storage_cache_writable');

    expect($signal)->not->toBeNull()
        ->and($signal['status'])->toBeIn(['GO', 'FAIL']);
});

it('registers no expensive command signal as auto-run', function () {
    foreach ((array) config('foundation_monitoring.signals') as $key => $meta) {
        if (($meta['type'] ?? null) === 'command') {
            expect($meta['auto_run'] ?? true)->toBeFalse("signal {$key} must not auto-run on a web request");
        }
    }
});

/**
 * Run the command in-process and return its captured stdout.
 */
function monitoringCommandJson(array $extra = []): string
{
    $output = new BufferedOutput;
    $params = array_merge(['--json' => true], $extra);

    Artisan::call('foundation:monitoring-observability-check', $params, $output);

    return $output->fetch();
}
