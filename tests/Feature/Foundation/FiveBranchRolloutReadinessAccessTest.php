<?php

use App\Models\User;
use App\Modules\RmeOnlineContext\Middleware\EnsureRmeOnlineContext;

uses()->group('Foundation', 'RolloutReadiness', 'RollFive');

beforeEach(function () {
    seedAccessControl();
});

it('lets the Super Admin open the rollout readiness page', function () {
    $this->actingAs(superAdmin())
        ->get(route('foundation.rollout.five-branch-readiness'))
        ->assertOk()
        ->assertSee('Rollout');
});

it('lets a user holding only view_developer_console open the page', function () {
    $this->actingAs(userWith(['view_developer_console']))
        ->get(route('foundation.rollout.five-branch-readiness'))
        ->assertOk();
});

it('forbids a user without the console permission', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('foundation.rollout.five-branch-readiness'))
        ->assertForbidden();
});

it('redirects guests to login', function () {
    $this->get(route('foundation.rollout.five-branch-readiness'))
        ->assertRedirect(route('login'));
});

it('does not grant rollout readiness access to operational roles', function () {
    foreach (['Doctor', 'Kepala Cabang', 'Admin Klinik', 'Kasir', 'Perawat', 'Owner', 'Admin Warehouse'] as $role) {
        expect(userInRole($role)->can('view_developer_console'))
            ->toBeFalse("{$role} must not access rollout readiness");
    }
});

it('forbids a Doctor at the authorization layer (403, not just a redirect)', function () {
    $this->withoutMiddleware(EnsureRmeOnlineContext::class)
        ->actingAs(userInRole('Doctor'))
        ->get(route('foundation.rollout.five-branch-readiness'))
        ->assertForbidden();
});

it('forbids a Kepala Cabang at the authorization layer', function () {
    $this->withoutMiddleware(EnsureRmeOnlineContext::class)
        ->actingAs(userInRole('Kepala Cabang'))
        ->get(route('foundation.rollout.five-branch-readiness'))
        ->assertForbidden();
});
