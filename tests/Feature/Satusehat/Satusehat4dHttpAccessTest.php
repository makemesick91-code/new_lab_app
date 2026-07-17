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

it('lets Supervisor RME open every 4D read surface', function () {
    $user = s4dUser('Supervisor RME');

    foreach ([
        'satusehat.multi-branch.index',
        'satusehat.executive.index',
        'satusehat.waves.index',
        'satusehat.uat.index',
        'satusehat.change-control.index',
    ] as $route) {
        $this->actingAs($user)->get(route($route))->assertOk();
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

it('gives Owner read-only access but denies wave management (403)', function () {
    $owner = s4dUser('Owner');

    $this->actingAs($owner)->get(route('satusehat.multi-branch.index'))->assertOk();
    $this->actingAs($owner)->get(route('satusehat.executive.index'))->assertOk();
    // Owner has no manage_satusehat_rollout_waves → cannot create a wave.
    $this->actingAs($owner)->post(route('satusehat.waves.store'), ['name' => 'X'])->assertForbidden();
    // Owner cannot open the UAT recording surface.
    $this->actingAs($owner)->get(route('satusehat.uat.index'))->assertForbidden();
});

it('gives Admin Klinik matrix read but denies executive + wave management', function () {
    $ak = s4dUser('Admin Klinik');

    $this->actingAs($ak)->get(route('satusehat.multi-branch.index'))->assertOk();
    $this->actingAs($ak)->get(route('satusehat.executive.index'))->assertForbidden();
    $this->actingAs($ak)->post(route('satusehat.waves.store'), ['name' => 'X'])->assertForbidden();
});

it('creates a wave via HTTP as Supervisor RME without network', function () {
    $user = s4dUser('Supervisor RME');

    $this->actingAs($user)
        ->post(route('satusehat.waves.store'), ['name' => 'HTTP Wave', 'sequence' => 1])
        ->assertRedirect();

    $this->assertDatabaseHas('mst_satusehat_rollout_waves', ['name' => 'HTTP Wave', 'status' => 'draft']);
    Http::assertNothingSent();
});
