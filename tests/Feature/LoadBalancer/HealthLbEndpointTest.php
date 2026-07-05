<?php

uses()->group('LoadBalancer', 'Lb1');

it('returns 200 with a minimal safe payload and no authentication required', function () {
    $response = $this->get('/health/lb');

    $response->assertOk()
        ->assertExactJson([
            'status' => 'ok',
            'service' => 'daengtisiams',
            'check' => 'lb',
        ]);
});

it('does not expose sensitive keys in the health payload', function () {
    $response = $this->get('/health/lb');

    $body = $response->getContent();

    expect($body)->not->toContain('APP_KEY')
        ->not->toContain('DB_')
        ->not->toContain('password')
        ->not->toContain('secret');
});

it('is registered by name and reachable via GET', function () {
    expect(route('health.lb'))->toContain('/health/lb');
});

it('runs the optional deep DB check and keeps the same minimal payload shape', function () {
    config(['load_balancer.health_deep_check' => true]);

    $response = $this->get('/health/lb');

    $response->assertOk()
        ->assertJson(['status' => 'ok', 'service' => 'daengtisiams', 'check' => 'lb']);
});
