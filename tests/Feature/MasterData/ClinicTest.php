<?php

use App\Modules\Clinic\Models\Clinic;

beforeEach(function () {
    seedAccessControl();
});

it('lists clinics for an authorized user', function () {
    Clinic::factory()->count(3)->create();

    $this->actingAs(userWith(['manage clinics']))
        ->get(route('settings.clinics.index'))
        ->assertOk()
        ->assertViewIs('settings.clinics.index');
});

it('searches clinics by name', function () {
    Clinic::factory()->create(['name' => 'Bright Smile Clinic']);
    Clinic::factory()->create(['name' => 'Other Place']);

    $this->actingAs(superAdmin())
        ->get(route('settings.clinics.index', ['search' => 'bright']))
        ->assertOk()
        ->assertSee('Bright Smile Clinic')
        ->assertDontSee('Other Place');
});

it('creates a clinic (happy path)', function () {
    $this->actingAs(userWith(['manage clinics']))
        ->post(route('settings.clinics.store'), [
            'code' => 'CLN-001',
            'name' => 'New Clinic',
            'city' => 'Jakarta',
            'is_active' => 1,
        ])
        ->assertRedirect(route('settings.clinics.index'));

    expect(Clinic::where('code', 'CLN-001')->exists())->toBeTrue();
});

it('validates required code and name', function () {
    $this->actingAs(superAdmin())
        ->post(route('settings.clinics.store'), ['code' => '', 'name' => ''])
        ->assertSessionHasErrors(['code', 'name']);
});

it('rejects a duplicate clinic code', function () {
    Clinic::factory()->create(['code' => 'DUP-1']);

    $this->actingAs(superAdmin())
        ->post(route('settings.clinics.store'), ['code' => 'DUP-1', 'name' => 'X'])
        ->assertSessionHasErrors('code');
});

it('updates a clinic keeping its own code', function () {
    $clinic = Clinic::factory()->create(['code' => 'KEEP-1', 'name' => 'Old']);

    $this->actingAs(superAdmin())
        ->put(route('settings.clinics.update', $clinic), ['code' => 'KEEP-1', 'name' => 'Renamed'])
        ->assertRedirect(route('settings.clinics.index'));

    expect($clinic->refresh()->name)->toBe('Renamed');
});

it('activates and deactivates a clinic', function () {
    $clinic = Clinic::factory()->inactive()->create();
    $admin = superAdmin();

    $this->actingAs($admin)->patch(route('settings.clinics.activate', $clinic))->assertRedirect();
    expect($clinic->refresh()->is_active)->toBeTrue();

    $this->actingAs($admin)->patch(route('settings.clinics.deactivate', $clinic))->assertRedirect();
    expect($clinic->refresh()->is_active)->toBeFalse();
});

it('soft deletes a clinic', function () {
    $clinic = Clinic::factory()->create();

    $this->actingAs(superAdmin())
        ->delete(route('settings.clinics.destroy', $clinic))
        ->assertRedirect(route('settings.clinics.index'));

    expect(Clinic::find($clinic->id))->toBeNull();
    expect(Clinic::withTrashed()->find($clinic->id))->not->toBeNull();
});

it('denies clinic access without permission', function () {
    $this->actingAs(userWith(['manage doctors']))
        ->get(route('settings.clinics.index'))
        ->assertForbidden();
});

it('redirects guests to login', function () {
    $this->get(route('settings.clinics.index'))->assertRedirect(route('login'));
});
