<?php

use App\Console\Commands\PilotPerformanceSnapshotCommand;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);

it('registers pilot performance snapshot command in artisan list', function () {
    $commands = Artisan::all();

    expect($commands)->toHaveKey('pilot:performance-snapshot');
});

it('blocks production environment without force flag', function () {
    $originalEnv = app()->environment();
    $originalConfig = config('app.env');

    app()->instance('env', 'production');
    config(['app.env' => 'production']);

    try {
        $exitCode = Artisan::call('pilot:performance-snapshot', ['--no-http' => true, '--no-db' => true]);

        expect($exitCode)->toBe(10)
            ->and(Artisan::output())->toContain('production');
    } finally {
        app()->instance('env', $originalEnv);
        config(['app.env' => $originalConfig]);
    }
});

it('outputs valid JSON with overall_status', function () {
    $exitCode = Artisan::call('pilot:performance-snapshot', [
        '--json' => true,
        '--no-http' => true,
        '--no-db' => true,
    ]);

    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(0)
        ->and($payload)->toHaveKeys(['checked_at', 'environment', 'overall_status', 'sections'])
        ->and($payload['overall_status'])->toBeString();
});

it('outputs markdown heading without secrets or PII markers', function () {
    Artisan::call('pilot:performance-snapshot', [
        '--markdown' => true,
        '--no-http' => true,
        '--no-db' => true,
    ]);

    $output = Artisan::output();

    expect($output)->toContain('# Pilot Performance Snapshot')
        ->and($output)->toContain('Overall status')
        ->and($output)->not->toContain('DB_PASSWORD')
        ->and($output)->not->toContain('.env')
        ->and($output)->not->toContain('KTP')
        ->and($output)->not->toContain('NIK');
});

it('skips database section with no-db flag', function () {
    Artisan::call('pilot:performance-snapshot', [
        '--json' => true,
        '--no-db' => true,
        '--no-http' => true,
    ]);

    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($payload['skipped_checks'])->toContain('database')
        ->and($payload['sections']['database']['metrics']['skipped'])->toBeTrue();
});

it('skips http section with no-http flag', function () {
    Artisan::call('pilot:performance-snapshot', [
        '--json' => true,
        '--no-http' => true,
        '--no-db' => true,
    ]);

    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($payload['skipped_checks'])->toContain('http')
        ->and($payload['sections']['http']['metrics']['skipped'])->toBeTrue();
});

it('does not leak secrets or patient-identifying fields in json output', function () {
    Artisan::call('pilot:performance-snapshot', [
        '--json' => true,
        '--no-http' => true,
        '--no-db' => true,
    ]);

    $output = Artisan::output();

    expect($output)->not->toContain('DB_PASSWORD')
        ->and($output)->not->toContain('.env')
        ->and($output)->not->toContain('KTP')
        ->and($output)->not->toContain('NIK')
        ->and($output)->not->toContain('patient_name')
        ->and($output)->not->toContain('phone')
        ->and($output)->not->toContain('email');
});

it('returns non-zero exit for watch when fail-on-watch is set', function () {
    Http::fake([
        '*' => Http::response('', 500),
    ]);

    config(['app.url' => 'http://snapshot-test.test']);

    $exitCode = Artisan::call('pilot:performance-snapshot', [
        '--fail-on-watch' => true,
        '--no-db' => true,
    ]);

    expect($exitCode)->toBeGreaterThanOrEqual(1);
});

it('defines expected command options', function () {
    $command = new PilotPerformanceSnapshotCommand;

    expect($command->getDefinition()->getOption('json')->getDefault())->toBeFalse()
        ->and($command->getDefinition()->getOption('markdown')->getDefault())->toBeFalse()
        ->and($command->getDefinition()->getOption('no-db')->getDefault())->toBeFalse()
        ->and($command->getDefinition()->getOption('no-http')->getDefault())->toBeFalse()
        ->and($command->getDefinition()->getOption('fail-on-watch')->getDefault())->toBeFalse();
});
