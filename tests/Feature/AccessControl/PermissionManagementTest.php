<?php

beforeEach(function () {
    seedAccessControl();
});

it('lists permissions for an authorized user', function () {
    $this->actingAs(userWith(['manage permissions']))
        ->get(route('settings.permissions.index'))
        ->assertOk()
        ->assertViewIs('settings.permissions.index')
        ->assertSee('manage users');
});

it('filters permissions by search', function () {
    $this->actingAs(superAdmin())
        ->get(route('settings.permissions.index', ['search' => 'invoices']))
        ->assertOk()
        ->assertSee('manage invoices')
        ->assertDontSee('manage deliveries');
});

it('denies permission listing without the manage permissions permission', function () {
    $this->actingAs(userWith(['manage users']))
        ->get(route('settings.permissions.index'))
        ->assertForbidden();
});

it('grants the Super Admin access to every settings area via gate bypass', function () {
    $admin = superAdmin();

    $this->actingAs($admin)->get(route('settings.users.index'))->assertOk();
    $this->actingAs($admin)->get(route('settings.roles.index'))->assertOk();
    $this->actingAs($admin)->get(route('settings.permissions.index'))->assertOk();
});
