<?php

use App\Modules\LabOrder\Models\LabOrder;

beforeEach(function () {
    seedAccessControl();
});

it('allows an authorized user to access the delivery queue', function () {
    LabOrder::factory()->create(['status' => LabOrder::STATUS_QC_PASSED]);

    $this->actingAs(userWith(['view_delivery']))
        ->get(route('deliveries.index'))
        ->assertOk()
        ->assertSee('Delivery Queue');
});

it('redirects guests from the delivery queue', function () {
    $this->get(route('deliveries.index'))
        ->assertRedirect(route('login'));
});

it('denies queue access without delivery permission', function () {
    $this->actingAs(userWith(['view_lab_orders']))
        ->get(route('deliveries.index'))
        ->assertForbidden();
});
