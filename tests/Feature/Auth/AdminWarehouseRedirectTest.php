<?php

use App\Models\User;
use Spatie\Permission\Models\Role;

/**
 * Sprint 58.1 — Admin Warehouse login redirect hotfix.
 *
 * Admin Warehouse users land on the Inventory Executive Dashboard after login;
 * all other roles keep the default dashboard redirect.
 */
test('admin warehouse user is redirected to inventory executive dashboard after login', function () {
    Role::findOrCreate('Admin Warehouse', 'web');

    $user = User::factory()->create();
    $user->assignRole('Admin Warehouse');

    $response = $this->post('/login', [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect(route('inventory.executive-dashboard', absolute: false));
    $response->assertRedirect('/inventory/executive-dashboard');
});

test('admin warehouse user without intended url does not land on the general dashboard', function () {
    Role::findOrCreate('Admin Warehouse', 'web');

    $user = User::factory()->create();
    $user->assignRole('Admin Warehouse');

    $response = $this->post('/login', [
        'email' => $user->email,
        'password' => 'password',
    ]);

    expect($response->headers->get('Location'))
        ->not->toBe(route('dashboard', absolute: false));
});

test('non-warehouse user keeps the default dashboard redirect after login', function () {
    Role::findOrCreate('Owner', 'web');

    $user = User::factory()->create();
    $user->assignRole('Owner');

    $response = $this->post('/login', [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect(route('dashboard', absolute: false));
});

test('user with no role keeps the default dashboard redirect after login', function () {
    $user = User::factory()->create();

    $response = $this->post('/login', [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect(route('dashboard', absolute: false));
});

test('intended protected url still takes precedence over role-based default for admin warehouse', function () {
    Role::findOrCreate('Admin Warehouse', 'web');

    $user = User::factory()->create();
    $user->assignRole('Admin Warehouse');

    // Simulate a previously-stored intended destination.
    session()->put('url.intended', '/profile');

    $response = $this->post('/login', [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect('/profile');
});
