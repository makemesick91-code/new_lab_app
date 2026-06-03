<?php

use Spatie\Permission\Models\Role;

beforeEach(function () {
    seedAccessControl();
});

it('lists roles for an authorized user', function () {
    $this->actingAs(userWith(['manage roles']))
        ->get(route('settings.roles.index'))
        ->assertOk()
        ->assertViewIs('settings.roles.index')
        ->assertSee('Super Admin');
});

it('creates a role with permissions (happy path)', function () {
    $this->actingAs(userWith(['manage roles']))
        ->post(route('settings.roles.store'), [
            'name' => 'Receptionist',
            'permissions' => ['view dashboard'],
        ])
        ->assertRedirect(route('settings.roles.index'));

    $role = Role::where('name', 'Receptionist')->first();
    expect($role)->not->toBeNull();
    expect($role->hasPermissionTo('view dashboard'))->toBeTrue();
});

it('validates required and unique role name on create', function () {
    $this->actingAs(superAdmin())
        ->post(route('settings.roles.store'), ['name' => ''])
        ->assertSessionHasErrors('name');

    $this->actingAs(superAdmin())
        ->post(route('settings.roles.store'), ['name' => 'Finance']) // already seeded
        ->assertSessionHasErrors('name');
});

it('updates a role and syncs permissions (assignment)', function () {
    $role = Role::create(['name' => 'Temp Role', 'guard_name' => 'web']);

    $this->actingAs(superAdmin())
        ->put(route('settings.roles.update', $role), [
            'name' => 'Temp Role Renamed',
            'permissions' => ['view dashboard', 'manage invoices'],
        ])
        ->assertRedirect(route('settings.roles.index'));

    $role->refresh();
    expect($role->name)->toBe('Temp Role Renamed');
    expect($role->permissions->pluck('name')->sort()->values()->all())
        ->toBe(['manage invoices', 'view dashboard']);
});

it('removes all permissions when none submitted on update', function () {
    $role = Role::create(['name' => 'Has Perms', 'guard_name' => 'web']);
    $role->givePermissionTo('view dashboard');

    $this->actingAs(superAdmin())
        ->put(route('settings.roles.update', $role), ['name' => 'Has Perms'])
        ->assertRedirect();

    expect($role->refresh()->permissions)->toHaveCount(0);
});

it('deletes a non-protected role', function () {
    $role = Role::create(['name' => 'Disposable', 'guard_name' => 'web']);

    $this->actingAs(superAdmin())
        ->delete(route('settings.roles.destroy', $role))
        ->assertRedirect(route('settings.roles.index'));

    expect(Role::where('name', 'Disposable')->exists())->toBeFalse();
});

it('prevents deleting the Super Admin role', function () {
    $role = Role::findByName('Super Admin');

    $this->actingAs(superAdmin())
        ->delete(route('settings.roles.destroy', $role))
        ->assertSessionHasErrors('role');

    expect(Role::where('name', 'Super Admin')->exists())->toBeTrue();
});

it('denies role management without permission', function () {
    $this->actingAs(userWith(['manage users']))
        ->get(route('settings.roles.index'))
        ->assertForbidden();
});
