<?php

use App\Modules\Satusehat\Models\SatusehatSubmissionBatch as Batch;
use App\Modules\Satusehat\Models\SatusehatSubmissionItem as Item;
use App\Modules\Satusehat\Services\SatusehatSubmissionStateMachine as SM;
use App\Modules\Satusehat\Support\SatusehatFhirValidator;
use App\Modules\Satusehat\Support\SatusehatHostGuard;
use App\Modules\Satusehat\Support\SatusehatOutcome;
use App\Modules\Satusehat\Support\SatusehatRetryClassifier;

require_once __DIR__.'/helpers.php';

beforeEach(function () {
    config()->set('satusehat.environment', 'sandbox');
    config()->set('satusehat.sandbox_only', true);
    config()->set('satusehat.allowed_hosts', [
        'sandbox' => ['api-satusehat-stg.dto.kemkes.go.id'],
        'production' => ['api-satusehat.kemkes.go.id'],
    ]);
});

// ---- Host guard (SSRF / sandbox isolation) ----

it('allows an https sandbox host', function () {
    expect(SatusehatHostGuard::assertAllowedBaseUrl('https://api-satusehat-stg.dto.kemkes.go.id/fhir-r4/v1'))
        ->toBe('api-satusehat-stg.dto.kemkes.go.id');
});

it('rejects a non-https base url', function () {
    SatusehatHostGuard::assertAllowedBaseUrl('http://api-satusehat-stg.dto.kemkes.go.id/fhir-r4/v1');
})->throws(RuntimeException::class);

it('rejects an arbitrary/attacker host', function () {
    SatusehatHostGuard::assertAllowedBaseUrl('https://evil.example.com/fhir-r4/v1');
})->throws(RuntimeException::class);

it('rejects a production host while sandbox-only', function () {
    SatusehatHostGuard::assertAllowedBaseUrl('https://api-satusehat.kemkes.go.id/fhir-r4/v1');
})->throws(RuntimeException::class);

it('rejects credentials embedded in the url', function () {
    SatusehatHostGuard::assertAllowedBaseUrl('https://user:pass@api-satusehat-stg.dto.kemkes.go.id/');
})->throws(RuntimeException::class);

it('rejects the production environment entirely when sandbox-only', function () {
    config()->set('satusehat.environment', 'production');
    SatusehatHostGuard::assertAllowedBaseUrl('https://api-satusehat.kemkes.go.id/fhir-r4/v1');
})->throws(RuntimeException::class);

// ---- Retry classifier ----

it('classifies statuses to outcomes', function () {
    expect(SatusehatRetryClassifier::classifyStatus(201))->toBe(SatusehatOutcome::SUCCESS)
        ->and(SatusehatRetryClassifier::classifyStatus(429))->toBe(SatusehatOutcome::RETRYABLE)
        ->and(SatusehatRetryClassifier::classifyStatus(503))->toBe(SatusehatOutcome::RETRYABLE)
        ->and(SatusehatRetryClassifier::classifyStatus(400))->toBe(SatusehatOutcome::PERMANENT)
        ->and(SatusehatRetryClassifier::classifyStatus(422))->toBe(SatusehatOutcome::PERMANENT)
        ->and(SatusehatRetryClassifier::classifyStatus(403))->toBe(SatusehatOutcome::PERMANENT)
        ->and(SatusehatRetryClassifier::classifyStatus(500))->toBe(SatusehatOutcome::UNKNOWN);
});

it('classifies a transport failure after possible send as UNKNOWN, before send as RETRYABLE', function () {
    expect(SatusehatRetryClassifier::classifyTransportFailure(true))->toBe(SatusehatOutcome::UNKNOWN)
        ->and(SatusehatRetryClassifier::classifyTransportFailure(false))->toBe(SatusehatOutcome::RETRYABLE);
});

// ---- State machine ----

it('permits legal item transitions and rejects illegal ones', function () {
    expect(SM::itemCanTransition(Item::STATUS_PROCESSING, Item::STATUS_SUCCEEDED))->toBeTrue()
        ->and(SM::itemCanTransition(Item::STATUS_PROCESSING, Item::STATUS_UNKNOWN_OUTCOME))->toBeTrue()
        ->and(SM::itemCanTransition(Item::STATUS_SUCCEEDED, Item::STATUS_PROCESSING))->toBeFalse()
        ->and(SM::itemCanTransition(Item::STATUS_UNKNOWN_OUTCOME, Item::STATUS_QUEUED))->toBeFalse()
        ->and(SM::itemCanTransition(Item::STATUS_QUEUED, Item::STATUS_QUEUED))->toBeTrue(); // idempotent
});

it('permits legal batch transitions and rejects illegal ones', function () {
    expect(SM::batchCanTransition(Batch::STATUS_PROCESSING, Batch::STATUS_PARTIAL))->toBeTrue()
        ->and(SM::batchCanTransition(Batch::STATUS_SUCCEEDED, Batch::STATUS_PROCESSING))->toBeFalse();
});

// ---- FHIR validator ----

it('flags forbidden/out-of-scope keys and a missing subject', function () {
    $issues = app(SatusehatFhirValidator::class)->validate([
        'resourceType' => 'Encounter',
        'status' => 'finished',
        'odontogram' => ['tooth' => 1],
        'subject' => ['reference' => 'NotAPatient/1'],
    ]);

    expect($issues)->toContain('Payload memuat field terlarang: odontogram.');
    expect(collect($issues)->contains(fn ($i) => str_contains($i, 'subject')))->toBeTrue();
});

it('accepts a well-formed Encounter', function () {
    $issues = app(SatusehatFhirValidator::class)->validate([
        'resourceType' => 'Encounter',
        'status' => 'finished',
        'subject' => ['reference' => 'Patient/ihs-1'],
        'participant' => [['individual' => ['reference' => 'Practitioner/ihs-2']]],
        'serviceProvider' => ['reference' => 'Organization/ORG-1'],
        'period' => ['start' => '2026-07-16T01:00:00+00:00'],
    ]);

    expect($issues)->toBe([]);
});
