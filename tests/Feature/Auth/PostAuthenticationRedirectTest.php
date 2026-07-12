<?php

use App\Models\User;
use App\Services\Auth\PostAuthenticationRedirectService;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * FIX-LOGIN-REDIRECT-RUNTIME-PERMISSIONS.
 *
 * Post-authentication landing is permission/role-aware and a stored
 * `url.intended` may only win when it is internal, well-formed, and authorized.
 */
function seedRedirectRolesAndPermissions(): void
{
    foreach (['view dashboard', 'view_owner_dashboard', 'view_lab_orders'] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }

    Role::findOrCreate('Super Admin', 'web');

    $adminLab = Role::findOrCreate('Admin Lab', 'web');
    $adminLab->givePermissionTo('view_lab_orders'); // Lab-only: NO dashboard permission.

    $owner = Role::findOrCreate('Owner', 'web');
    $owner->givePermissionTo('view_owner_dashboard');
}

function loginResponse(User $user)
{
    return test()->post('/login', ['email' => $user->email, 'password' => 'password']);
}

beforeEach(function () {
    seedRedirectRolesAndPermissions();
});

test('admin lab lands on the canonical lab workspace, never the forbidden dashboard', function () {
    $user = User::factory()->create();
    $user->assignRole('Admin Lab');

    $response = loginResponse($user);

    $this->assertAuthenticated();
    $response->assertRedirect('/lab/v2-orders');
    expect($response->headers->get('Location'))->not->toContain('/dashboard');
});

test('admin lab stale intended dashboard is rejected in favor of the lab workspace', function () {
    $user = User::factory()->create();
    $user->assignRole('Admin Lab');

    session()->put('url.intended', url('/dashboard'));

    $response = loginResponse($user);

    $this->assertAuthenticated();
    $response->assertRedirect('/lab/v2-orders');
});

test('admin lab authorized intended lab route is honored', function () {
    $user = User::factory()->create();
    $user->assignRole('Admin Lab');

    session()->put('url.intended', url('/lab/v2-orders'));

    $response = loginResponse($user);

    $response->assertRedirect('/lab/v2-orders');
});

test('super admin lands on the dashboard', function () {
    $user = User::factory()->create();
    $user->assignRole('Super Admin');

    $response = loginResponse($user);

    $this->assertAuthenticated();
    $response->assertRedirect(route('dashboard', absolute: false));
});

test('super admin intended dashboard is honored', function () {
    $user = User::factory()->create();
    $user->assignRole('Super Admin');

    session()->put('url.intended', url('/dashboard'));

    $response = loginResponse($user);

    $response->assertRedirect(route('dashboard', absolute: false));
});

test('owner with dashboard permission keeps the dashboard default', function () {
    $user = User::factory()->create();
    $user->assignRole('Owner');

    $response = loginResponse($user);

    $response->assertRedirect(route('dashboard', absolute: false));
});

test('authorized intended profile url is preserved for any user', function () {
    $user = User::factory()->create();
    $user->assignRole('Owner');

    session()->put('url.intended', url('/profile'));

    $response = loginResponse($user);

    $response->assertRedirect('/profile');
});

test('unauthorized intended lab route is rejected for a non-lab user', function () {
    $user = User::factory()->create();
    $user->assignRole('Owner'); // no view_lab_orders

    session()->put('url.intended', url('/lab/v2-orders'));

    $response = loginResponse($user);

    // Owner cannot access the lab route → dropped → role-aware default.
    $response->assertRedirect(route('dashboard', absolute: false));
});

test('external, protocol-relative, and dangerous intended urls are rejected', function (string $intended) {
    $user = User::factory()->create();
    $user->assignRole('Owner');

    session()->put('url.intended', $intended);

    $response = loginResponse($user);

    expect($response->headers->get('Location'))
        ->not->toContain('evil.example')
        ->and($response->headers->get('Location'))
        ->toBe(url(route('dashboard', absolute: false)));
})->with([
    'external http' => 'http://evil.example/dashboard',
    'protocol relative' => '//evil.example/x',
    'javascript' => 'javascript:alert(1)',
    'data uri' => 'data:text/html,<script>1</script>',
    'malformed' => 'ht!tp://:::/bad',
]);

test('unknown internal route intended url is rejected', function () {
    $user = User::factory()->create();
    $user->assignRole('Owner');

    session()->put('url.intended', url('/this-route-does-not-exist-xyz'));

    $response = loginResponse($user);

    $response->assertRedirect(route('dashboard', absolute: false));
});

test('service default landing is dashboard for a permissionless plain user', function () {
    $user = User::factory()->create();

    $service = app(PostAuthenticationRedirectService::class);

    expect($service->defaultLandingPath($user))->toBe(route('dashboard', absolute: false));
});

test('service authorization probe reflects route permission middleware', function () {
    $adminLab = User::factory()->create();
    $adminLab->assignRole('Admin Lab');

    $owner = User::factory()->create();
    $owner->assignRole('Owner');

    $service = app(PostAuthenticationRedirectService::class);

    expect($service->userMayAccessLocalPath($adminLab, '/lab/v2-orders'))->toBeTrue()
        ->and($service->userMayAccessLocalPath($adminLab, '/dashboard'))->toBeFalse()
        ->and($service->userMayAccessLocalPath($owner, '/dashboard'))->toBeTrue()
        ->and($service->userMayAccessLocalPath($owner, '/lab/v2-orders'))->toBeFalse();
});
