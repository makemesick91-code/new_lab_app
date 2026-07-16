<?php

use App\Modules\Satusehat\Exceptions\SatusehatGatewayException;
use App\Modules\Satusehat\Gateways\HttpSatusehatGateway;
use App\Modules\Satusehat\Gateways\SatusehatTokenProviderInterface;
use App\Modules\Satusehat\Support\SatusehatOutcome;
use App\Support\DeveloperConsole\SensitiveValueMasker;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/helpers.php';

beforeEach(function () {
    Cache::flush();
    ssConfigureSandbox();
});

function ssStubToken(): object
{
    return new class implements SatusehatTokenProviderInterface
    {
        public int $forgotten = 0;

        public function token(): string
        {
            return 'TEST-TOKEN';
        }

        public function forget(): void
        {
            $this->forgotten++;
        }

        public function status(): array
        {
            return ['token_status' => 'available', 'expires_in_bucket' => 'gte_30m', 'environment' => 'sandbox'];
        }
    };
}

function ssGateway(?object $token = null): HttpSatusehatGateway
{
    return new HttpSatusehatGateway('sandbox', $token ?? ssStubToken(), app(HttpFactory::class), app(SensitiveValueMasker::class));
}

it('creates a resource and returns the remote id + version', function () {
    Http::fake(['*/fhir-r4/*' => Http::response(['resourceType' => 'Encounter', 'id' => 'enc-123', 'meta' => ['versionId' => '1']], 201)]);

    $res = ssGateway()->createResource('Encounter', ['resourceType' => 'Encounter'], 'corr-1');

    expect($res->isSuccess())->toBeTrue()
        ->and($res->remoteResourceId)->toBe('enc-123')
        ->and($res->remoteVersionId)->toBe('1');

    Http::assertSent(fn ($r) => $r->hasHeader('Authorization', 'Bearer TEST-TOKEN')
        && $r->hasHeader('X-Correlation-ID', 'corr-1'));
});

it('maps 429 to retryable with Retry-After', function () {
    Http::fake(['*/fhir-r4/*' => Http::response('', 429, ['Retry-After' => '42'])]);

    $res = ssGateway()->createResource('Encounter', [], 'c');

    expect($res->outcome)->toBe(SatusehatOutcome::RETRYABLE)
        ->and($res->retryAfterSeconds)->toBe(42);
});

it('maps 422 to permanent and sanitizes the OperationOutcome', function () {
    Http::fake(['*/fhir-r4/*' => Http::response([
        'resourceType' => 'OperationOutcome',
        'issue' => [['severity' => 'error', 'code' => 'invalid', 'diagnostics' => 'NIK 3273010101010001 invalid']],
    ], 422)]);

    $res = ssGateway()->createResource('Encounter', [], 'c');

    expect($res->outcome)->toBe(SatusehatOutcome::PERMANENT)
        ->and($res->issues[0]['code'])->toBe('invalid')
        // The 16-digit NIK must be masked out of the stored diagnostics.
        ->and($res->issues[0]['diagnostics'])->not->toContain('3273010101010001');
});

it('maps 500 to unknown (reconciliation, never a blind re-POST)', function () {
    Http::fake(['*/fhir-r4/*' => Http::response('', 500)]);

    expect(ssGateway()->createResource('Encounter', [], 'c')->outcome)->toBe(SatusehatOutcome::UNKNOWN);
});

it('treats a transport failure on a write as UNKNOWN and on a GET as RETRYABLE', function () {
    Http::fake(['*/fhir-r4/*' => fn () => throw new ConnectionException('timeout')]);

    expect(ssGateway()->createResource('Encounter', [], 'c')->outcome)->toBe(SatusehatOutcome::UNKNOWN)
        ->and(ssGateway()->getResource('Encounter', 'x', 'c')->outcome)->toBe(SatusehatOutcome::RETRYABLE);
});

it('refreshes the token once on 401, then fails permanently', function () {
    Http::fake(['*/fhir-r4/*' => Http::response('', 401)]);
    $token = ssStubToken();

    $res = ssGateway($token)->createResource('Encounter', [], 'c');

    expect($res->outcome)->toBe(SatusehatOutcome::PERMANENT)
        ->and($token->forgotten)->toBe(1);   // token evicted exactly once
    Http::assertSentCount(2);                 // original + one retry
});

it('rejects an oversized response before decoding', function () {
    config()->set('satusehat.max_response_bytes', 50);
    Http::fake(['*/fhir-r4/*' => Http::response(str_repeat('x', 5000), 200)]);

    expect(ssGateway()->createResource('Encounter', [], 'c')->outcome)->toBe(SatusehatOutcome::PERMANENT);
});

it('refuses to send when the kill switch is off', function () {
    config()->set('satusehat.send_enabled', false);

    ssGateway()->createResource('Encounter', [], 'c');
})->throws(SatusehatGatewayException::class);

it('opens the circuit breaker after repeated hard failures', function () {
    config()->set('satusehat.circuit_breaker.threshold', 2);
    Http::fake(['*/fhir-r4/*' => Http::response('', 500)]);

    ssGateway()->createResource('Encounter', [], 'c');
    ssGateway()->createResource('Encounter', [], 'c');

    // Breaker now open → the next call is refused before any HTTP request.
    expect(fn () => ssGateway()->createResource('Encounter', [], 'c'))
        ->toThrow(SatusehatGatewayException::class);
});
