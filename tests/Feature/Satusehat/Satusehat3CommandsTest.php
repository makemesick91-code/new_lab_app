<?php

use Database\Seeders\SatusehatDentalMappingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);
require_once __DIR__.'/helpers.php';

beforeEach(fn () => Http::preventStrayRequests());

it('satusehat:dental-profile-audit runs and emits JSON without network', function () {
    $this->artisan('satusehat:dental-profile-audit --json')->assertSuccessful();
    Http::assertNothingSent();
});

it('satusehat:dental-profile-audit --strict fails on WATCH (draft-only)', function () {
    $this->seed(SatusehatDentalMappingSeeder::class);
    // WATCH (all draft) → strict fails.
    $this->artisan('satusehat:dental-profile-audit --strict')->assertFailed();
});

it('satusehat:production-readiness reports without enabling anything', function () {
    $this->artisan('satusehat:production-readiness --json')->assertSuccessful();
    expect(config('satusehat.enabled'))->toBeFalse();
});

it('satusehat:production-guard-check succeeds while production is blocked', function () {
    $this->artisan('satusehat:production-guard-check')->assertSuccessful();
});

it('satusehat:dental-readiness evaluates a visit read-only', function () {
    $ctx = ssMakeVisit();
    ssOdontogram($ctx, ['48' => 'caries']);

    $this->artisan('satusehat:dental-readiness '.$ctx['visit']->id.' --json')->assertSuccessful();
    Http::assertNothingSent();
});

it('satusehat:dental-preview builds locally with no network', function () {
    $ctx = ssMakeVisit();
    ssOdontogram($ctx, ['48' => 'caries']);

    $this->artisan('satusehat:dental-preview '.$ctx['visit']->id.' --json')->assertSuccessful();
    Http::assertNothingSent();
});
