<?php

uses()->group('Foundation', 'EnterpriseFoundation', 'HealthCheck');

it('serves a minimal liveness payload without authentication', function () {
    $response = $this->get('/health/live');

    $response->assertOk()
        ->assertExactJson([
            'status' => 'ok',
            'service' => 'daengtisiams',
            'check' => 'live',
        ]);
});

it('serves a readiness payload with per-component states only', function () {
    $response = $this->get('/health/ready');

    $response->assertOk();

    $json = $response->json();

    expect($json['service'])->toBe('daengtisiams')
        ->and($json['check'])->toBe('ready')
        ->and($json['status'])->toBeIn(['ok', 'degraded', 'down'])
        ->and($json)->toHaveKey('components')
        ->and($json['components'])->toHaveKey('database')
        ->and($json['components']['database'])->toBeIn(['ok', 'degraded', 'down']);
});

it('does not expose sensitive keys or long digit runs in health payloads', function () {
    foreach (['/health/live', '/health/ready'] as $path) {
        $body = $this->get($path)->getContent();

        expect($body)->not->toContain('APP_KEY')
            ->not->toContain('DB_')
            ->not->toContain('password')
            ->not->toContain('secret')
            ->not->toContain('token');

        expect(preg_match('/\d{16}/', $body))->toBe(0);
    }
});

it('registers the health endpoints by name as GET routes', function () {
    expect(route('health.live'))->toContain('/health/live')
        ->and(route('health.ready'))->toContain('/health/ready');

    $liveRoute = app('router')->getRoutes()->getByName('health.live');
    $readyRoute = app('router')->getRoutes()->getByName('health.ready');

    expect(array_diff($liveRoute->methods(), ['GET', 'HEAD']))->toBe([])
        ->and(array_diff($readyRoute->methods(), ['GET', 'HEAD']))->toBe([]);
});

it('keeps the LB-1 /health/lb endpoint intact alongside the ENT-8 endpoints', function () {
    $this->get('/health/lb')->assertOk()->assertJson(['check' => 'lb']);
    $this->get('/health/live')->assertOk()->assertJson(['check' => 'live']);
});
