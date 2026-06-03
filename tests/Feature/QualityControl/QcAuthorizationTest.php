<?php

use App\Models\User;

beforeEach(function () {
    seedAccessControl();
});

it('redirects guests from QC routes to login', function () {
    $order = qcPendingOrder();

    $this->get(route('quality-control.queue'))->assertRedirect(route('login'));
    $this->get(route('quality-control.show', $order))->assertRedirect(route('login'));
    $this->post(route('quality-control.pass', $order), [])->assertRedirect(route('login'));
});

it('lets a Quality Control role user access the queue and pass QC', function () {
    $qc = User::factory()->create();
    $qc->assignRole('Quality Control');

    $this->actingAs($qc)->get(route('quality-control.queue'))->assertOk();

    $order = qcPendingOrder();
    $this->actingAs($qc)
        ->post(route('quality-control.pass', $order), [])
        ->assertRedirect(route('quality-control.show', $order));

    expect($order->refresh()->status)->toBe('QC_PASSED');
});

it('denies QC actions to users without QC permission', function () {
    $order = qcPendingOrder();
    $user = userWith(['manage users']);

    $this->actingAs($user)->get(route('quality-control.queue'))->assertForbidden();
    $this->actingAs($user)->post(route('quality-control.pass', $order), [])->assertForbidden();
    $this->actingAs($user)->post(route('quality-control.start', $order), [])->assertForbidden();
});

it('lets manage_quality_control bypass individual QC permissions', function () {
    $order = qcPendingOrder();

    $this->actingAs(userWith(['manage_quality_control']))
        ->post(route('quality-control.pass', $order), [])
        ->assertRedirect();

    expect($order->refresh()->status)->toBe('QC_PASSED');
});
