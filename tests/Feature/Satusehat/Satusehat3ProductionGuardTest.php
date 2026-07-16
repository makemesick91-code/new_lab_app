<?php

use App\Modules\Satusehat\Services\SatusehatProductionReadinessService;
use App\Modules\Satusehat\Support\SatusehatProductionActivationGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);
require_once __DIR__.'/helpers.php';

beforeEach(fn () => Http::preventStrayRequests());

it('production activation is blocked with the default SATUSEHAT-3 posture', function () {
    $result = app(SatusehatProductionActivationGuard::class)->evaluate();

    expect($result['allowed'])->toBeFalse()
        ->and($result['blockers'])->not->toBeEmpty();
});

it('production stays blocked even if every credential/flag is set but sandbox is unverified', function () {
    // Simulate a fully-configured production runtime EXCEPT the sandbox GO gate.
    config()->set('satusehat.enabled', true);
    config()->set('satusehat.send_enabled', true);
    config()->set('satusehat.environment', 'production');
    config()->set('satusehat.production_enabled', true);
    config()->set('satusehat.production_approved', true);
    config()->set('satusehat.production_approval_reference', 'REF-123');
    config()->set('satusehat.client_id', 'x');
    config()->set('satusehat.client_secret', 'y');
    config()->set('satusehat.organization_id', 'ORG');
    config()->set('satusehat.location_id', 'LOC');
    config()->set('satusehat.sandbox_verified', false); // the WATCH gate

    $result = app(SatusehatProductionActivationGuard::class)->evaluate();

    expect($result['allowed'])->toBeFalse();
    $codes = collect($result['checks'])->firstWhere('key', 'satusehat2_go');
    expect($codes['passed'])->toBeFalse();
});

it('the production guard command exits success while blocked (expected posture)', function () {
    $this->artisan('satusehat:production-guard-check')->assertSuccessful();
});

it('the production readiness command never claims production is allowed', function () {
    $report = app(SatusehatProductionReadinessService::class)->report();

    expect($report['production_allowed'])->toBeFalse()
        ->and($report['summary']['blocked_external'])->toBeGreaterThan(0);
});
