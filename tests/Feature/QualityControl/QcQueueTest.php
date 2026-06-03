<?php

use App\Modules\LabOrder\Models\LabOrder;

beforeEach(function () {
    seedAccessControl();
});

it('lets an authorized user access the QC queue', function () {
    $this->actingAs(userWith(['view_quality_control']))
        ->get(route('quality-control.queue'))
        ->assertOk()
        ->assertViewIs('quality-control.queue');
});

it('lists only QC_PENDING orders in the queue', function () {
    $pending = LabOrder::factory()->create(['status' => 'QC_PENDING']);
    $received = LabOrder::factory()->create(['status' => 'RECEIVED']);

    $this->actingAs(superAdmin())
        ->get(route('quality-control.queue'))
        ->assertOk()
        ->assertSee($pending->order_number)
        ->assertDontSee($received->order_number);
});

it('denies the QC queue to users without permission', function () {
    $this->actingAs(userWith(['manage users']))
        ->get(route('quality-control.queue'))
        ->assertForbidden();
});

it('redirects guests to login', function () {
    $this->get(route('quality-control.queue'))->assertRedirect(route('login'));
});
