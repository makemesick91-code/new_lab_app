<?php

/**
 * SECURITY-F1-CLOSE-PUBLIC-REGISTRATION-1 — there is no public self-registration.
 *
 * The Breeze scaffolding left `GET/POST /register` open: anyone on the internet
 * could create an account and was logged in immediately. The account held no
 * role, so the protection rested entirely on every authenticated route
 * authorizing its own action. Accounts are now created only by an administrator
 * (Settings → Users, `manage users`).
 *
 * The last test is the permanent guard for decision D1: whatever creates an
 * account in future, an authenticated user without a role reaches only their own
 * profile and notifications.
 */

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use Illuminate\Support\Facades\Route;

it('has no registration route', function () {
    expect(Route::has('register'))->toBeFalse();

    foreach (Route::getRoutes() as $route) {
        expect($route->uri())->not->toBe('register');
    }
});

it('refuses to render a registration screen', function () {
    $this->get('/register')->assertNotFound();
});

it('refuses to create an account from a public request', function () {
    $before = User::query()->count();

    $this->post('/register', [
        'name' => 'Self Registered',
        'email' => 'self-registered@example.invalid',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertNotFound();

    $this->assertGuest();
    expect(User::query()->count())->toBe($before)
        ->and(User::query()->where('email', 'self-registered@example.invalid')->exists())->toBeFalse();
});

it('keeps login and password reset available', function () {
    $this->get('/login')->assertOk();
    $this->get('/forgot-password')->assertOk();
});

it('lets an authenticated user without a role reach only their own pages', function () {
    // Production always has the head-office branch; branch-aware form requests
    // need it to resolve before authorization is reached.
    Branch::factory()->main()->create();
    $user = User::factory()->create();

    $selfScoped = ['profile', 'notifications', 'confirm-password'];
    $reached = [];
    $failed = [];
    $checked = 0;

    foreach (Route::getRoutes() as $route) {
        if (! in_array('GET', $route->methods(), true) || str_contains($route->uri(), '{')) {
            continue;
        }
        if (! in_array('auth', $route->gatherMiddleware(), true)) {
            continue;
        }

        $checked++;
        $status = $this->actingAs($user)->get('/'.$route->uri())->getStatusCode();

        if ($status >= 500) {
            $failed[] = $route->uri().' '.$status;
        } elseif ($status >= 200 && $status < 300) {
            $reached[] = $route->uri();
        }
    }

    sort($reached);
    sort($selfScoped);

    expect($checked)->toBeGreaterThan(100)
        ->and($failed)->toBe([])
        ->and($reached)->toBe($selfScoped);
});
