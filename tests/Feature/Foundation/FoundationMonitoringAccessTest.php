<?php

use App\Models\User;
use App\Modules\RmeOnlineContext\Middleware\EnsureRmeOnlineContext;

uses()->group('Foundation', 'FoundationMonitoring', 'EnterpriseFoundation');

beforeEach(function () {
    seedAccessControl();
});

it('lets the Super Admin open the foundation monitoring page', function () {
    $this->actingAs(superAdmin())
        ->get(route('foundation.monitoring.index'))
        ->assertOk()
        ->assertSee('Monitoring');
});

it('lets a user holding only view_developer_console open monitoring', function () {
    $this->actingAs(userWith(['view_developer_console']))
        ->get(route('foundation.monitoring.index'))
        ->assertOk();
});

it('forbids a user without the console permission', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('foundation.monitoring.index'))
        ->assertForbidden();
});

it('redirects guests to login', function () {
    $this->get(route('foundation.monitoring.index'))
        ->assertRedirect(route('login'));
});

it('does not grant monitoring access to Doctor, Kepala Cabang, Admin Klinik, Kasir, or Perawat', function () {
    foreach (['Doctor', 'Kepala Cabang', 'Admin Klinik', 'Kasir', 'Perawat', 'Owner'] as $role) {
        expect(userInRole($role)->can('view_developer_console'))
            ->toBeFalse("{$role} must not access foundation monitoring");
    }
});

it('forbids a Doctor at the authorization layer (403, not just a redirect)', function () {
    $this->withoutMiddleware(EnsureRmeOnlineContext::class)
        ->actingAs(userInRole('Doctor'))
        ->get(route('foundation.monitoring.index'))
        ->assertForbidden();
});
