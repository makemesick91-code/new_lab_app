<?php

use App\Services\Foundation\AutomatedSmokeService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

uses()->group('Foundation', 'AutomatedSmoke');

it('automated smoke config exists', function () {
    $config = config('automated_smoke');

    expect($config)->toBeArray()
        ->and($config['expected_route_names'])->not->toBeEmpty()
        ->and($config['required_writable_paths'])->not->toBeEmpty()
        ->and($config['required_governance_commands'])->not->toBeEmpty();
});

it('registers the automated smoke command', function () {
    expect(Artisan::all())->toHaveKey('release:automated-smoke');
});

it('release:automated-smoke returns GO locally without a base url', function () {
    $exitCode = Artisan::call('release:automated-smoke');
    $report = app(AutomatedSmokeService::class)->run();

    expect($exitCode)->toBe(0)
        ->and($report['summary']['decision'])->toBe('GO')
        ->and($report['mode'])->toBe('command_readiness_only');
});

it('release:automated-smoke --json has no PII and no secrets', function () {
    $exitCode = Artisan::call('release:automated-smoke', ['--json' => true]);
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(0)
        ->and($payload['privacy']['pii'])->toBeFalse()
        ->and($payload['privacy']['row_level_data'])->toBeFalse();

    $encoded = strtolower((string) json_encode($payload));
    foreach (['password', 'secret', 'api_key', 'nik', 'ktp'] as $needle) {
        expect($encoded)->not->toContain($needle);
    }
});

it('automated smoke fails when an expected named route is missing', function () {
    config(['automated_smoke.expected_route_names' => ['this-route-name-does-not-exist']]);

    $report = app(AutomatedSmokeService::class)->run();

    expect($report['summary']['decision'])->toBe('FAIL');
});

it('automated smoke rejects a simulated 500 base url response', function () {
    Http::fake([
        '*' => Http::response('Internal Server Error', 500),
    ]);

    $report = app(AutomatedSmokeService::class)->run('http://127.0.0.1');

    $httpCheck = collect($report['checks'])->firstWhere('check_id', 'SMOKE-HTTP-HEALTH');

    expect($httpCheck['status'])->toBe('failed')
        ->and($report['summary']['decision'])->toBe('FAIL');
});

it('automated smoke accepts a simulated 302 login redirect base url response', function () {
    Http::fake([
        '*' => Http::response('', 302),
    ]);

    $report = app(AutomatedSmokeService::class)->run('http://127.0.0.1');

    $httpCheck = collect($report['checks'])->firstWhere('check_id', 'SMOKE-HTTP-HEALTH');

    expect($httpCheck['status'])->toBe('passed')
        ->and($report['summary']['decision'])->toBe('GO');
});

it('automated smoke never mutates patient inventory payment lab or rme records', function () {
    // The service performs no DB writes; assert query log stays free of INSERT/UPDATE/DELETE.
    DB::enableQueryLog();

    app(AutomatedSmokeService::class)->run();

    $mutating = collect(DB::getQueryLog())
        ->filter(fn (array $q) => preg_match('/^\s*(insert|update|delete)/i', (string) $q['query']));

    expect($mutating)->toBeEmpty();

    DB::disableQueryLog();
});
