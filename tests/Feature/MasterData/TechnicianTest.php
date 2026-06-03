<?php

use App\Models\User;
use App\Modules\Technician\Models\Technician;

beforeEach(function () {
    seedAccessControl();
});

it('lists technicians for an authorized user', function () {
    Technician::factory()->count(3)->create();

    $this->actingAs(userWith(['manage technicians']))
        ->get(route('settings.technicians.index'))
        ->assertOk()
        ->assertViewIs('settings.technicians.index');
});

it('creates a technician (happy path)', function () {
    $this->actingAs(userWith(['manage technicians']))
        ->post(route('settings.technicians.store'), [
            'code' => 'TECH-001',
            'name' => 'Tech One',
            'specialization' => 'Crown & Bridge',
            'is_active' => 1,
        ])
        ->assertRedirect(route('settings.technicians.index'));

    expect(Technician::where('code', 'TECH-001')->exists())->toBeTrue();
});

it('can link a technician to a user account', function () {
    $user = User::factory()->create();

    $this->actingAs(superAdmin())
        ->post(route('settings.technicians.store'), [
            'user_id' => $user->id,
            'code' => 'TECH-USR',
            'name' => 'Linked Tech',
        ])
        ->assertRedirect();

    expect(Technician::where('code', 'TECH-USR')->first()->user_id)->toBe($user->id);
});

it('requires code and name', function () {
    $this->actingAs(superAdmin())
        ->post(route('settings.technicians.store'), ['code' => '', 'name' => ''])
        ->assertSessionHasErrors(['code', 'name']);
});

it('rejects a duplicate technician code', function () {
    Technician::factory()->create(['code' => 'TDUP']);

    $this->actingAs(superAdmin())
        ->post(route('settings.technicians.store'), ['code' => 'TDUP', 'name' => 'X'])
        ->assertSessionHasErrors('code');
});

it('updates a technician', function () {
    $technician = Technician::factory()->create(['name' => 'Old']);

    $this->actingAs(superAdmin())
        ->put(route('settings.technicians.update', $technician), [
            'code' => $technician->code,
            'name' => 'Updated Technician',
        ])
        ->assertRedirect(route('settings.technicians.index'));

    expect($technician->refresh()->name)->toBe('Updated Technician');
});

it('activates and deactivates a technician', function () {
    $technician = Technician::factory()->inactive()->create();
    $admin = superAdmin();

    $this->actingAs($admin)->patch(route('settings.technicians.activate', $technician))->assertRedirect();
    expect($technician->refresh()->is_active)->toBeTrue();

    $this->actingAs($admin)->patch(route('settings.technicians.deactivate', $technician))->assertRedirect();
    expect($technician->refresh()->is_active)->toBeFalse();
});

it('soft deletes a technician', function () {
    $technician = Technician::factory()->create();

    $this->actingAs(superAdmin())
        ->delete(route('settings.technicians.destroy', $technician))
        ->assertRedirect();

    expect(Technician::find($technician->id))->toBeNull();
    expect(Technician::withTrashed()->find($technician->id))->not->toBeNull();
});

it('denies technician access without permission', function () {
    $this->actingAs(userWith(['manage clinics']))
        ->get(route('settings.technicians.index'))
        ->assertForbidden();
});
