<?php

use App\Modules\LabService\Models\LabService;

beforeEach(function () {
    seedAccessControl();
});

it('lists lab services for an authorized user', function () {
    LabService::factory()->count(3)->create();

    $this->actingAs(userWith(['manage lab services']))
        ->get(route('settings.lab-services.index'))
        ->assertOk()
        ->assertViewIs('settings.lab-services.index');
});

it('creates a lab service (happy path)', function () {
    $this->actingAs(userWith(['manage lab services']))
        ->post(route('settings.lab-services.store'), [
            'code' => 'SVC-001',
            'name' => 'Crown',
            'turnaround_days' => 5,
            'price' => 1500000,
            'is_active' => 1,
        ])
        ->assertRedirect(route('settings.lab-services.index'));

    expect(LabService::where('code', 'SVC-001')->exists())->toBeTrue();
});

it('requires code, name and numeric price', function () {
    $this->actingAs(superAdmin())
        ->post(route('settings.lab-services.store'), ['code' => '', 'name' => '', 'price' => 'abc'])
        ->assertSessionHasErrors(['code', 'name', 'price']);
});

it('rejects a duplicate lab service code', function () {
    LabService::factory()->create(['code' => 'SDUP']);

    $this->actingAs(superAdmin())
        ->post(route('settings.lab-services.store'), ['code' => 'SDUP', 'name' => 'X', 'price' => 100])
        ->assertSessionHasErrors('code');
});

it('updates a lab service', function () {
    $service = LabService::factory()->create(['name' => 'Old']);

    $this->actingAs(superAdmin())
        ->put(route('settings.lab-services.update', $service), [
            'code' => $service->code,
            'name' => 'Updated Service',
            'price' => 200000,
        ])
        ->assertRedirect(route('settings.lab-services.index'));

    expect($service->refresh()->name)->toBe('Updated Service');
});

it('activates and deactivates a lab service', function () {
    $service = LabService::factory()->inactive()->create();
    $admin = superAdmin();

    $this->actingAs($admin)->patch(route('settings.lab-services.activate', $service))->assertRedirect();
    expect($service->refresh()->is_active)->toBeTrue();

    $this->actingAs($admin)->patch(route('settings.lab-services.deactivate', $service))->assertRedirect();
    expect($service->refresh()->is_active)->toBeFalse();
});

it('soft deletes a lab service', function () {
    $service = LabService::factory()->create();

    $this->actingAs(superAdmin())
        ->delete(route('settings.lab-services.destroy', $service))
        ->assertRedirect();

    expect(LabService::find($service->id))->toBeNull();
    expect(LabService::withTrashed()->find($service->id))->not->toBeNull();
});

it('denies lab service access without permission', function () {
    $this->actingAs(userWith(['manage clinics']))
        ->get(route('settings.lab-services.index'))
        ->assertForbidden();
});
