<?php

use App\Models\User;
use App\Modules\Satusehat\Models\SatusehatCodeMapping;
use App\Modules\Satusehat\Services\SatusehatDentalProfileAuditService;
use App\Modules\Satusehat\Services\SatusehatMappingService;
use Database\Seeders\SatusehatDentalMappingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);
require_once __DIR__.'/helpers.php';

beforeEach(fn () => Http::preventStrayRequests());

function ssDentalDraft(array $overrides = []): SatusehatCodeMapping
{
    return SatusehatCodeMapping::create(array_merge([
        'environment' => 'sandbox',
        'local_entity_type' => 'odontogram_tooth_condition',
        'local_code' => 'caries',
        'target_resource_type' => 'Observation',
        'terminology_system' => 'http://snomed.info/sct',
        'target_code' => '80967001',
        'target_display' => 'Dental caries',
        'profile_family' => 'dental',
        'status' => SatusehatCodeMapping::STATUS_DRAFT,
        'version' => 1,
        'effective_date' => now()->toDateString(),
    ], $overrides));
}

it('refuses to activate a dental mapping without official provenance', function () {
    $mapping = ssDentalDraft();
    $actor = User::factory()->create();

    expect(fn () => app(SatusehatMappingService::class)->activate($mapping, $actor))
        ->toThrow(ValidationException::class);

    expect($mapping->refresh()->status)->toBe(SatusehatCodeMapping::STATUS_DRAFT);
});

it('activates a dental mapping once verified against an official source', function () {
    $mapping = ssDentalDraft();
    $actor = User::factory()->create();
    $service = app(SatusehatMappingService::class);

    $service->verify($mapping, [
        'official_source' => 'https://satusehat.kemkes.go.id/platform/docs/id/terminology/lampiran-terminologi/rawat-jalan-gigi/',
        'official_source_version' => 'v1.5',
    ], $actor);

    $service->activate($mapping->refresh(), $actor);

    expect($mapping->refresh()->status)->toBe(SatusehatCodeMapping::STATUS_ACTIVE)
        ->and($mapping->verified_at)->not->toBeNull();
});

it('the seeded dental mappings are all DRAFT and none are auto-activated', function () {
    $this->seed(SatusehatDentalMappingSeeder::class);

    $family = SatusehatCodeMapping::where('profile_family', 'dental');
    expect($family->count())->toBeGreaterThan(50)
        ->and((clone $family)->where('status', SatusehatCodeMapping::STATUS_ACTIVE)->count())->toBe(0)
        ->and((clone $family)->where('status', SatusehatCodeMapping::STATUS_DRAFT)->count())->toBe($family->count());
});

it('re-running the dental seeder is idempotent', function () {
    $this->seed(SatusehatDentalMappingSeeder::class);
    $first = SatusehatCodeMapping::where('profile_family', 'dental')->count();
    $this->seed(SatusehatDentalMappingSeeder::class);
    $second = SatusehatCodeMapping::where('profile_family', 'dental')->count();

    expect($second)->toBe($first);
});

it('dental profile audit reports WATCH when only DRAFT mappings exist', function () {
    $this->seed(SatusehatDentalMappingSeeder::class);

    $report = app(SatusehatDentalProfileAuditService::class)->audit('sandbox');

    expect($report['decision'])->toBe('WATCH')
        ->and($report['errors'])->toBe([]);
});
