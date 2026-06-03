<?php

use App\Modules\LabOrder\Models\AuditLog;
use App\Modules\LabOrder\Models\LabOrder;
use App\Modules\LabOrder\Models\LabOrderStatusLog;
use App\Modules\LabOrder\Services\LabOrderService;

beforeEach(function () {
    seedAccessControl();
});

function makeOrder(): LabOrder
{
    return app(LabOrderService::class)->create(labOrderPayload(), superAdmin());
}

it('cancels a lab order with a reason', function () {
    $order = makeOrder();

    $this->actingAs(userWith(['cancel_lab_orders']))
        ->post(route('lab-orders.cancel', $order), ['reason' => 'Patient cancelled the case'])
        ->assertRedirect(route('lab-orders.show', $order));

    expect($order->refresh()->status)->toBe('CANCELLED');
});

it('requires a reason to cancel', function () {
    $order = makeOrder();

    $this->actingAs(userWith(['cancel_lab_orders']))
        ->post(route('lab-orders.cancel', $order), ['reason' => ''])
        ->assertSessionHasErrors('reason');
});

it('requires the reason to be at least 5 characters', function () {
    $order = makeOrder();

    $this->actingAs(userWith(['cancel_lab_orders']))
        ->post(route('lab-orders.cancel', $order), ['reason' => 'no'])
        ->assertSessionHasErrors('reason');
});

it('creates a status log on cancel', function () {
    $order = makeOrder();

    $this->actingAs(userWith(['cancel_lab_orders']))
        ->post(route('lab-orders.cancel', $order), ['reason' => 'No longer needed']);

    $log = LabOrderStatusLog::where('lab_order_id', $order->id)->where('new_status', 'CANCELLED')->first();
    expect($log)->not->toBeNull();
    expect($log->old_status)->toBe('RECEIVED');
});

it('creates an audit log on cancel', function () {
    $order = makeOrder();

    $this->actingAs(userWith(['cancel_lab_orders']))
        ->post(route('lab-orders.cancel', $order), ['reason' => 'No longer needed']);

    expect(AuditLog::where('entity_id', $order->id)->where('action', 'CANCEL')->exists())->toBeTrue();
});

it('forbids cancelling an already cancelled order', function () {
    $order = LabOrder::factory()->cancelled()->create();

    $this->actingAs(userWith(['cancel_lab_orders']))
        ->post(route('lab-orders.cancel', $order), ['reason' => 'Trying again'])
        ->assertForbidden();
});

it('denies cancel without the cancel permission', function () {
    $order = makeOrder();

    $this->actingAs(userWith(['view_lab_orders']))
        ->post(route('lab-orders.cancel', $order), ['reason' => 'Should be blocked'])
        ->assertForbidden();
});
