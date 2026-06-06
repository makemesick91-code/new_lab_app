<?php

use App\Models\User;

beforeEach(function () {
    seedAccessControl();
});

it('lists users for an authorized user', function () {
    User::factory()->count(3)->create();

    $this->actingAs(userWith(['manage users']))
        ->get(route('settings.users.index'))
        ->assertOk()
        ->assertViewIs('settings.users.index')
        ->assertSee('Manajemen Pengguna')
        ->assertSee('Pengaturan')
        ->assertDontSee('>Settings<', false);
});

it('searches users by name', function () {
    User::factory()->create(['name' => 'Findable Tester', 'email' => 'findable@example.com']);
    User::factory()->create(['name' => 'Someone Else', 'email' => 'other@example.com']);

    $this->actingAs(superAdmin())
        ->get(route('settings.users.index', ['search' => 'findable']))
        ->assertOk()
        ->assertSee('Findable Tester')
        ->assertDontSee('Someone Else');
});

it('creates a user with a role (happy path)', function () {
    $this->actingAs(userWith(['manage users']))
        ->post(route('settings.users.store'), [
            'name' => 'New Technician',
            'email' => 'tech@example.com',
            'password' => 'password123',
            'role' => 'Technician',
            'is_active' => 1,
        ])
        ->assertRedirect(route('settings.users.index'));

    $user = User::where('email', 'tech@example.com')->first();
    expect($user)->not->toBeNull();
    expect($user->hasRole('Technician'))->toBeTrue();
    expect($user->is_active)->toBeTrue();
});

it('validates required fields and password length on create', function () {
    $this->actingAs(userWith(['manage users']))
        ->post(route('settings.users.store'), [
            'name' => '',
            'email' => 'not-an-email',
            'password' => 'short',
        ])
        ->assertSessionHasErrors(['name', 'email', 'password']);
});

it('rejects a duplicate email on create', function () {
    User::factory()->create(['email' => 'dupe@example.com']);

    $this->actingAs(superAdmin())
        ->post(route('settings.users.store'), [
            'name' => 'Dup',
            'email' => 'dupe@example.com',
            'password' => 'password123',
        ])
        ->assertSessionHasErrors('email');
});

it('updates a user and keeps email unique ignoring itself', function () {
    $user = User::factory()->create(['name' => 'Old Name', 'email' => 'keep@example.com']);

    $this->actingAs(superAdmin())
        ->put(route('settings.users.update', $user), [
            'name' => 'Updated Name',
            'email' => 'keep@example.com', // same email must pass
            'role' => 'Finance',
        ])
        ->assertRedirect(route('settings.users.index'));

    $user->refresh();
    expect($user->name)->toBe('Updated Name');
    expect($user->hasRole('Finance'))->toBeTrue();
});

it('fails update when email belongs to another user', function () {
    $other = User::factory()->create(['email' => 'taken@example.com']);
    $user = User::factory()->create(['email' => 'mine@example.com']);

    $this->actingAs(superAdmin())
        ->put(route('settings.users.update', $user), [
            'name' => 'Whoever',
            'email' => 'taken@example.com',
        ])
        ->assertSessionHasErrors('email');
});

it('activates and deactivates a user', function () {
    $user = User::factory()->inactive()->create();
    $admin = superAdmin();

    $this->actingAs($admin)
        ->patch(route('settings.users.activate', $user))
        ->assertRedirect();
    expect($user->refresh()->is_active)->toBeTrue();

    $this->actingAs($admin)
        ->patch(route('settings.users.deactivate', $user))
        ->assertRedirect();
    expect($user->refresh()->is_active)->toBeFalse();
});

it('soft deletes a user', function () {
    $user = User::factory()->create();

    $this->actingAs(superAdmin())
        ->delete(route('settings.users.destroy', $user))
        ->assertRedirect(route('settings.users.index'));

    expect(User::find($user->id))->toBeNull();
    expect(User::withTrashed()->find($user->id))->not->toBeNull();
});

it('denies access without the manage users permission', function () {
    $this->actingAs(userWith(['manage roles'])) // wrong permission
        ->get(route('settings.users.index'))
        ->assertForbidden();
});

it('redirects guests to login', function () {
    $this->get(route('settings.users.index'))->assertRedirect(route('login'));
});
