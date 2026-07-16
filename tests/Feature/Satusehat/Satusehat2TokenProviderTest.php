<?php

use App\Modules\Satusehat\Exceptions\SatusehatTokenException;
use App\Modules\Satusehat\Gateways\OAuthClientCredentialsSatusehatTokenProvider;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/helpers.php';

beforeEach(function () {
    Cache::flush();
    ssConfigureSandbox();
});

function ssTokenProvider(): OAuthClientCredentialsSatusehatTokenProvider
{
    return new OAuthClientCredentialsSatusehatTokenProvider(app(HttpFactory::class), 'sandbox');
}

it('acquires a token via client_credentials and returns it', function () {
    Http::fake(['*/oauth2/*' => Http::response(['access_token' => 'THE-TOKEN', 'expires_in' => '3600'], 200)]);

    expect(ssTokenProvider()->token())->toBe('THE-TOKEN');

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'grant_type=client_credentials')
            && $request['client_id'] === 'test-client-id'
            && $request['client_secret'] === 'test-client-secret';
    });
});

it('caches the token and does not re-request within its validity', function () {
    Http::fake(['*/oauth2/*' => Http::response(['access_token' => 'CACHED', 'expires_in' => '3600'], 200)]);

    $provider = ssTokenProvider();
    $provider->token();
    $provider->token();
    $provider->token();

    Http::assertSentCount(1);
});

it('rejects a production OAuth host (never talks to production)', function () {
    config()->set('satusehat.environment', 'production');
    config()->set('satusehat.oauth_base_url', 'https://api-satusehat.kemkes.go.id/oauth2/v1');
    Http::fake();

    ssTokenProvider()->token();
})->throws(SatusehatTokenException::class);

it('throws on a malformed token response', function () {
    Http::fake(['*/oauth2/*' => Http::response(['no_token' => true], 200)]);

    ssTokenProvider()->token();
})->throws(SatusehatTokenException::class);

it('throws on an http error and never leaks the request', function () {
    Http::fake(['*/oauth2/*' => Http::response(['error' => 'invalid_client'], 401)]);

    try {
        ssTokenProvider()->token();
        $this->fail('expected exception');
    } catch (SatusehatTokenException $e) {
        expect($e->getMessage())->not->toContain('test-client-secret');
    }
});

it('exposes a value-free token status (never the token itself)', function () {
    Http::fake(['*/oauth2/*' => Http::response(['access_token' => 'SECRET-TOKEN-VALUE', 'expires_in' => '3600'], 200)]);

    $provider = ssTokenProvider();
    $provider->token();
    $status = $provider->status();

    expect($status['token_status'])->toBe('available')
        ->and(json_encode($status))->not->toContain('SECRET-TOKEN-VALUE');
});
