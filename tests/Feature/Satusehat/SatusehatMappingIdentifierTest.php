<?php

use App\Modules\RmeOnlineContext\Middleware\EnsureRmeOnlineContext;
use App\Modules\Satusehat\Models\SatusehatCodeMapping;
use App\Modules\Satusehat\Models\SatusehatEntityIdentifier;
use App\Modules\Satusehat\Services\SatusehatIdentifierService;
use App\Modules\Satusehat\Services\SatusehatMappingService;
use Illuminate\Validation\ValidationException;

beforeEach(fn () => seedAccessControl());

function ssMapping(): SatusehatMappingService
{
    return app(SatusehatMappingService::class);
}

function ssMappingData(array $overrides = []): array
{
    return array_merge([
        'environment' => 'sandbox',
        'local_entity_type' => 'treatment',
        'local_entity_id' => 10,
        'target_resource_type' => 'Procedure',
        'target_code' => 'CODE-A',
    ], $overrides);
}

it('versions mappings and keeps exactly one active per key', function () {
    $manager = userWith(['manage_satusehat_mappings']);

    $v1 = ssMapping()->createDraft(ssMappingData(['target_code' => 'A']), $manager);
    ssMapping()->activate($v1, $manager);
    expect($v1->fresh()->status)->toBe(SatusehatCodeMapping::STATUS_ACTIVE);

    $v2 = ssMapping()->createDraft(ssMappingData(['target_code' => 'B']), $manager);
    expect($v2->version)->toBe(2);
    ssMapping()->activate($v2, $manager);

    expect($v1->fresh()->status)->toBe(SatusehatCodeMapping::STATUS_DEPRECATED)
        ->and($v2->fresh()->status)->toBe(SatusehatCodeMapping::STATUS_ACTIVE)
        ->and(SatusehatCodeMapping::where('local_entity_type', 'treatment')
            ->where('local_entity_id', 10)
            ->where('status', SatusehatCodeMapping::STATUS_ACTIVE)
            ->count())->toBe(1);
});

it('refuses to edit an active mapping in place', function () {
    $manager = userWith(['manage_satusehat_mappings']);
    $mapping = ssMapping()->createDraft(ssMappingData(), $manager);
    ssMapping()->activate($mapping, $manager);

    expect(fn () => ssMapping()->updateDraft($mapping->fresh(), ['target_code' => 'X'], $manager))
        ->toThrow(ValidationException::class);
});

it('keeps a single active identifier per local entity and environment', function () {
    $user = userWith(['manage_satusehat_settings']);
    $service = app(SatusehatIdentifierService::class);

    $service->upsert([
        'environment' => 'sandbox', 'entity_type' => 'Patient',
        'local_entity_type' => 'patient', 'local_entity_id' => 1, 'remote_identifier' => 'ihs-1',
    ], $user);
    $service->upsert([
        'environment' => 'sandbox', 'entity_type' => 'Patient',
        'local_entity_type' => 'patient', 'local_entity_id' => 1, 'remote_identifier' => 'ihs-2',
    ], $user);

    $active = SatusehatEntityIdentifier::where('environment', 'sandbox')
        ->where('entity_type', 'Patient')->where('local_entity_id', 1)
        ->where('status', 'active')->get();

    expect($active)->toHaveCount(1)
        ->and($active->first()->remote_identifier)->toBe('ihs-2');
});

it('never mixes sandbox and production identifiers', function () {
    $user = userWith(['manage_satusehat_settings']);
    $service = app(SatusehatIdentifierService::class);

    $service->upsert(['environment' => 'sandbox', 'entity_type' => 'Patient', 'local_entity_type' => 'patient', 'local_entity_id' => 1, 'remote_identifier' => 'sbx-1'], $user);
    $service->upsert(['environment' => 'production', 'entity_type' => 'Patient', 'local_entity_type' => 'patient', 'local_entity_id' => 1, 'remote_identifier' => 'prod-1'], $user);

    expect(SatusehatEntityIdentifier::where('status', 'active')->count())->toBe(2);
});

it('requires manage_satusehat_mappings to open the mapping page', function () {
    $user = userWith(['view_satusehat_submissions']);

    $this->actingAs($user)
        ->withoutMiddleware(EnsureRmeOnlineContext::class)
        ->get(route('satusehat.mappings.index'))
        ->assertForbidden();
});

it('rejects an invalid identifier format', function () {
    $user = userWith(['manage_satusehat_settings']);

    $this->actingAs($user)
        ->withoutMiddleware(EnsureRmeOnlineContext::class)
        ->from(route('satusehat.identifiers.index'))
        ->post(route('satusehat.identifiers.store'), [
            'environment' => 'sandbox', 'entity_type' => 'Patient',
            'local_entity_type' => 'patient', 'local_entity_id' => 1,
            'remote_identifier' => 'bad id with spaces',
        ])
        ->assertSessionHasErrors('remote_identifier');
});
