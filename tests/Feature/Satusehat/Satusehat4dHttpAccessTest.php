<?php

use App\Models\User;
use App\Modules\RmeOnlineContext\Middleware\EnsureRmeOnlineContext;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/helpers.php';

beforeEach(function () {
    config()->set('satusehat.enabled', false);
    config()->set('satusehat.send_enabled', false);
    config()->set('satusehat.environment', 'sandbox');
    Http::preventStrayRequests();
    seedAccessControl();
    $this->withoutMiddleware(EnsureRmeOnlineContext::class);
});

function s4dUser(string $role): User
{
    $u = User::factory()->create();
    $u->assignRole($role);

    return $u;
}

it('denies Supervisor RME every 4D read surface now that the module is Super Admin only', function () {
    $user = s4dUser('Supervisor RME');
    $surfaces = [
        'satusehat.multi-branch.index',
        'satusehat.executive.index',
        'satusehat.waves.index',
        'satusehat.uat.index',
        'satusehat.change-control.index',
    ];

    // FIX-08: `can:satusehat.access` guards the whole satusehat.* group.
    foreach ($surfaces as $route) {
        $this->actingAs($user)->get(route($route))->assertForbidden();
    }

    // The seeded 4D permissions are untouched — this is a route-layer
    // restriction, not a permission revocation.
    expect($user->can('view_satusehat_multi_branch_readiness'))->toBeTrue()
        ->and($user->can('view_satusehat_executive_readiness'))->toBeTrue()
        ->and($user->can('manage_satusehat_rollout_waves'))->toBeTrue()
        ->and($user->can('record_satusehat_uat_signoff'))->toBeTrue()
        ->and($user->can('manage_satusehat_change_control'))->toBeTrue();

    // Every surface still renders for the only role that may open them.
    foreach ($surfaces as $route) {
        $this->actingAs(superAdmin())->get(route($route))->assertOk();
    }

    Http::assertNothingSent();
});

it('denies Kasir and Admin Lab every 4D surface (403)', function () {
    foreach (['Kasir', 'Admin Lab'] as $role) {
        $user = s4dUser($role);
        $this->actingAs($user)->get(route('satusehat.multi-branch.index'))->assertForbidden();
        $this->actingAs($user)->get(route('satusehat.executive.index'))->assertForbidden();
        $this->actingAs($user)->get(route('satusehat.waves.index'))->assertForbidden();
    }
});

it('denies Owner every 4D surface (403) while its read-only permission set is unchanged', function () {
    $owner = s4dUser('Owner');

    // FIX-08: Owner previously had read-only 4D access; the module is now Super
    // Admin only, so even the read surfaces are denied.
    $this->actingAs($owner)->get(route('satusehat.multi-branch.index'))->assertForbidden();
    $this->actingAs($owner)->get(route('satusehat.executive.index'))->assertForbidden();
    $this->actingAs($owner)->post(route('satusehat.waves.store'), ['name' => 'X'])->assertForbidden();
    $this->actingAs($owner)->get(route('satusehat.uat.index'))->assertForbidden();

    // The read-only-vs-management permission split is unchanged and still
    // enforced by the routes' own `permission:` middleware.
    expect($owner->can('view_satusehat_multi_branch_readiness'))->toBeTrue()
        ->and($owner->can('view_satusehat_executive_readiness'))->toBeTrue()
        ->and($owner->can('manage_satusehat_rollout_waves'))->toBeFalse()
        ->and($owner->can('record_satusehat_uat_signoff'))->toBeFalse();

    // No wave was created by the denied POST.
    $this->assertDatabaseMissing('mst_satusehat_rollout_waves', ['name' => 'X']);
});

it('denies Admin Klinik every 4D surface (403) while its matrix-only permission set is unchanged', function () {
    $ak = s4dUser('Admin Klinik');

    // FIX-08: Admin Klinik previously had matrix read access; the module is now
    // Super Admin only, so the matrix is denied as well.
    $this->actingAs($ak)->get(route('satusehat.multi-branch.index'))->assertForbidden();
    $this->actingAs($ak)->get(route('satusehat.executive.index'))->assertForbidden();
    $this->actingAs($ak)->post(route('satusehat.waves.store'), ['name' => 'X'])->assertForbidden();

    // The matrix-only permission split is unchanged and still enforced by the
    // routes' own `permission:` middleware.
    expect($ak->can('view_satusehat_multi_branch_readiness'))->toBeTrue()
        ->and($ak->can('view_satusehat_executive_readiness'))->toBeFalse()
        ->and($ak->can('manage_satusehat_rollout_waves'))->toBeFalse();

    $this->assertDatabaseMissing('mst_satusehat_rollout_waves', ['name' => 'X']);
});

it('creates a wave via HTTP as Super Admin without network', function () {
    // FIX-08: Super Admin only. The wave-creation behaviour itself is unchanged.
    $user = superAdmin();

    $this->actingAs($user)
        ->post(route('satusehat.waves.store'), ['name' => 'HTTP Wave', 'sequence' => 1])
        ->assertRedirect();

    $this->assertDatabaseHas('mst_satusehat_rollout_waves', ['name' => 'HTTP Wave', 'status' => 'draft']);
    Http::assertNothingSent();
});
