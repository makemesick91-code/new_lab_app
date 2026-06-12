<?php

use App\Models\User;

beforeEach(function () {
    seedAccessControl();
});

it('assigns the Owner role to an existing user via the pilot command', function () {
    $user = User::factory()->create(['email' => 'pilot-owner@example.test']);

    expect($user->hasRole('Owner'))->toBeFalse();

    $this->artisan('pilot:assign-owner', ['email' => 'pilot-owner@example.test'])
        ->assertSuccessful();

    expect($user->fresh()->hasRole('Owner'))->toBeTrue();
});

it('is idempotent when the user already has the Owner role', function () {
    $user = User::factory()->create(['email' => 'already-owner@example.test'])->assignRole('Owner');

    $this->artisan('pilot:assign-owner', ['email' => 'already-owner@example.test'])
        ->assertSuccessful();

    expect($user->fresh()->roles()->where('name', 'Owner')->count())->toBe(1);
});

it('fails safely when the target user does not exist', function () {
    $this->artisan('pilot:assign-owner', ['email' => 'missing@example.test'])
        ->assertFailed();
});

it('lets an Owner reach the Owner Dashboard KPI content', function () {
    $this->actingAs(userInRole('Owner'))
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Dashboard Owner');
});

it('lets a Super Admin reach the dashboard route', function () {
    $this->actingAs(superAdmin())
        ->get(route('dashboard'))
        ->assertOk();
});

it('does not show Owner Dashboard KPI content to operational roles', function () {
    $this->actingAs(userInRole('Courier'))
        ->get(route('dashboard'))
        ->assertOk()
        ->assertDontSee('Dashboard Owner');
});

it('redirects unauthenticated users away from the dashboard', function () {
    $this->get(route('dashboard'))->assertRedirect(route('login'));
});
