<?php

use App\Models\User;
use App\Modules\Technician\Models\Technician;

beforeEach(function () {
    seedAccessControl();
});

it('redirects guests from the production board to login', function () {
    $this->get(route('production.board'))->assertRedirect(route('login'));
});

it('denies the production board to users without permission', function () {
    $this->actingAs(userWith(['manage users']))
        ->get(route('production.board'))
        ->assertForbidden();
});

it('allows an authorized user to view the production board', function () {
    $this->actingAs(userWith(['view_production']))
        ->get(route('production.board'))
        ->assertOk()
        ->assertViewIs('production.board');
});

it('lets a Quality Control user view production but not assign', function () {
    $qc = User::factory()->create();
    $qc->assignRole('Quality Control');

    $this->actingAs($qc)->get(route('production.board'))->assertOk();

    $order = receivedOrder();
    $this->actingAs($qc)
        ->post(route('production.assign', $order), ['technician_id' => Technician::factory()->create()->id])
        ->assertForbidden();
});

it('lets a technician start their own assignment', function () {
    [$user, $technician] = technicianActor(['view_production', 'start_production_work']);
    $order = receivedOrder();
    assignOrder($order, $technician);

    $this->actingAs($user)
        ->post(route('production.start', $order->refresh()), [])
        ->assertRedirect();

    expect($order->refresh()->status)->toBe('IN_PRODUCTION');
});

it('prevents a technician from starting another technician assignment', function () {
    [$user] = technicianActor(['view_production', 'start_production_work']);
    $order = receivedOrder();
    assignOrder($order, Technician::factory()->create()); // assigned to someone else

    $this->actingAs($user)
        ->post(route('production.start', $order->refresh()), [])
        ->assertForbidden();
});

it('shows the production detail page to an authorized user', function () {
    $order = receivedOrder();
    assignOrder($order);

    $this->actingAs(userWith(['view_production']))
        ->get(route('production.show', $order->refresh()))
        ->assertOk()
        ->assertViewIs('production.show');
});

it('shows the work logs page to an authorized user', function () {
    [$order] = orderInProduction();

    $this->actingAs(userWith(['view_production']))
        ->get(route('production.work-logs.index', $order))
        ->assertOk()
        ->assertViewIs('production.work-logs');
});
