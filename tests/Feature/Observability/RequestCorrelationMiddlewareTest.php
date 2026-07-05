<?php

use App\Http\Middleware\AttachRequestCorrelationContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

uses()->group('Observability', 'Obs1');

it('generates a request id and correlation id when inbound headers are missing', function () {
    $middleware = new AttachRequestCorrelationContext;
    $request = Request::create('/foo', 'GET');

    $response = $middleware->handle($request, fn ($req) => response('ok'));

    expect($request->attributes->get('request_id'))->not->toBeEmpty()
        ->and($request->attributes->get('correlation_id'))->not->toBeEmpty()
        ->and($response->headers->get('X-Request-ID'))->toBe($request->attributes->get('request_id'));
});

it('rejects an invalid inbound request id and generates a safe replacement', function () {
    config(['observability.request_id.trust_inbound' => true]);

    $invalid = 'invalid id with spaces '.str_repeat('x', 100);
    $middleware = new AttachRequestCorrelationContext;
    $request = Request::create('/foo', 'GET', server: ['HTTP_X_REQUEST_ID' => $invalid]);

    $response = $middleware->handle($request, fn ($req) => response('ok'));

    expect($response->headers->get('X-Request-ID'))->not->toBe($invalid);
});

it('honors a trusted, valid inbound request id only when the trust flag is enabled', function () {
    config(['observability.request_id.trust_inbound' => true]);

    $valid = 'client-supplied-Id.123:ok';
    $middleware = new AttachRequestCorrelationContext;
    $request = Request::create('/foo', 'GET', server: ['HTTP_X_REQUEST_ID' => $valid]);

    $response = $middleware->handle($request, fn ($req) => response('ok'));

    expect($response->headers->get('X-Request-ID'))->toBe($valid);
});

it('does not trust an inbound request id by default even if the value is valid', function () {
    $valid = 'client-supplied-id';
    $middleware = new AttachRequestCorrelationContext;
    $request = Request::create('/foo', 'GET', server: ['HTTP_X_REQUEST_ID' => $valid]);

    $response = $middleware->handle($request, fn ($req) => response('ok'));

    expect($response->headers->get('X-Request-ID'))->not->toBe($valid);
});

it('attaches request/correlation id log context without request payload or PII keys', function () {
    Log::spy();

    $middleware = new AttachRequestCorrelationContext;
    $request = Request::create('/foo', 'POST', ['password' => 'secret123', 'ktp_number' => '1234567890123456']);

    $middleware->handle($request, fn ($req) => response('ok'));

    Log::shouldHaveReceived('withContext')->withArgs(function (array $context) {
        return array_key_exists('request_id', $context)
            && array_key_exists('correlation_id', $context)
            && ! array_key_exists('password', $context)
            && ! array_key_exists('ktp_number', $context)
            && ! array_key_exists('payload', $context);
    })->once();
});

it('health endpoint response still includes the safe request id header', function () {
    $response = $this->get('/health/lb');

    $response->assertOk();
    expect($response->headers->get('X-Request-ID'))->not->toBeEmpty();
});

it('an overlong/invalid X-Request-ID against a real route does not break the request', function () {
    $response = $this->withHeaders([
        'X-Request-ID' => 'invalid id with spaces and very long invalid value '.str_repeat('y', 200),
    ])->get('/health/lb');

    $response->assertOk();
    expect($response->headers->get('X-Request-ID'))->not->toContain(' ');
});
