<?php

use App\Modules\Clinic\Models\Clinic;

beforeEach(function () {
    seedAccessControl();
});

it('redirects legacy clinic index to Cabang RME master', function () {
    $this->actingAs(userWith(['manage clinics']))
        ->get(route('settings.clinics.index'))
        ->assertRedirect(route('settings.branches.index'));
});

it('redirects legacy clinic create to Cabang RME master', function () {
    $this->actingAs(userWith(['manage clinics']))
        ->get(route('settings.clinics.create'))
        ->assertRedirect(route('settings.branches.index'));
});

it('blocks creating a legacy clinic', function () {
    $this->actingAs(userWith(['manage clinics']))
        ->post(route('settings.clinics.store'), [
            'code' => 'CLN-001',
            'name' => 'New Clinic',
            'city' => 'Jakarta',
            'is_active' => 1,
        ])
        ->assertRedirect(route('settings.branches.index'))
        ->assertSessionHas('error');

    expect(Clinic::where('code', 'CLN-001')->exists())->toBeFalse();
});

it('blocks updating a legacy clinic', function () {
    $clinic = Clinic::factory()->create(['code' => 'KEEP-1', 'name' => 'Old']);

    $this->actingAs(superAdmin())
        ->put(route('settings.clinics.update', $clinic), ['code' => 'KEEP-1', 'name' => 'Renamed'])
        ->assertRedirect(route('settings.branches.index'))
        ->assertSessionHas('error');

    expect($clinic->refresh()->name)->toBe('Old');
});

it('blocks activating a legacy clinic', function () {
    $clinic = Clinic::factory()->inactive()->create();
    $admin = superAdmin();

    $this->actingAs($admin)->patch(route('settings.clinics.activate', $clinic))
        ->assertRedirect(route('settings.branches.index'))
        ->assertSessionHas('error');

    expect($clinic->refresh()->is_active)->toBeFalse();
});

it('blocks soft deleting a legacy clinic via UI', function () {
    $clinic = Clinic::factory()->create();

    $this->actingAs(superAdmin())
        ->delete(route('settings.clinics.destroy', $clinic))
        ->assertRedirect(route('settings.branches.index'))
        ->assertSessionHas('error');

    expect(Clinic::find($clinic->id))->not->toBeNull();
});

it('denies clinic access without permission', function () {
    $this->actingAs(userWith(['manage doctors']))
        ->get(route('settings.clinics.index'))
        ->assertForbidden();
});

it('redirects guests to login', function () {
    $this->get(route('settings.clinics.index'))->assertRedirect(route('login'));
});
