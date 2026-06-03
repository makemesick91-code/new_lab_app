<?php

use App\Modules\Delivery\Models\Delivery;
use App\Modules\LabOrder\Models\AuditLog;
use App\Modules\LabOrder\Models\LabOrder;
use App\Modules\LabOrder\Models\LabOrderStatusLog;

beforeEach(function () {
    seedAccessControl();
});

it('creates a delivery from a QC_PASSED order', function () {
    $order = LabOrder::factory()->create(['status' => LabOrder::STATUS_QC_PASSED]);
    $courier = userWith(['view_delivery']);

    $this->actingAs(userWith(['create_delivery']))
        ->post(route('deliveries.store'), [
            'lab_order_id' => $order->id,
            'courier_id' => $courier->id,
            'delivery_notes' => 'front desk',
        ])
        ->assertRedirect();

    $delivery = Delivery::first();

    expect($delivery)->not->toBeNull()
        ->and($delivery->status)->toBe(Delivery::STATUS_READY_FOR_DELIVERY)
        ->and($order->refresh()->status)->toBe(LabOrder::STATUS_READY_FOR_DELIVERY);
});

it('requires QC_PASSED before creating a delivery', function () {
    $order = LabOrder::factory()->create(['status' => LabOrder::STATUS_RECEIVED]);

    $this->actingAs(userWith(['create_delivery']))
        ->post(route('deliveries.store'), ['lab_order_id' => $order->id])
        ->assertSessionHasErrors('lab_order_id');

    expect(Delivery::count())->toBe(0);
});

it('creates status and audit logs when delivery is created', function () {
    $order = LabOrder::factory()->create(['status' => LabOrder::STATUS_QC_PASSED]);

    $this->actingAs(superAdmin())
        ->post(route('deliveries.store'), ['lab_order_id' => $order->id]);

    expect(LabOrderStatusLog::where('lab_order_id', $order->id)->where('new_status', LabOrder::STATUS_READY_FOR_DELIVERY)->exists())->toBeTrue()
        ->and(AuditLog::where('action', AuditLog::ACTION_CREATE_DELIVERY)->exists())->toBeTrue()
        ->and(AuditLog::where('action', AuditLog::ACTION_STATUS_CHANGE)->where('entity_id', $order->id)->exists())->toBeTrue();
});

it('assigns and reassigns a courier', function () {
    $delivery = Delivery::factory()->create();
    $firstCourier = userWith(['view_delivery']);
    $secondCourier = userWith(['view_delivery']);

    $this->actingAs(userWith(['assign_courier']))
        ->post(route('deliveries.assign-courier', $delivery), ['courier_id' => $firstCourier->id])
        ->assertRedirect(route('deliveries.show', $delivery));

    expect($delivery->refresh()->courier_id)->toBe($firstCourier->id);

    $this->actingAs(userWith(['assign_courier']))
        ->post(route('deliveries.reassign-courier', $delivery), ['courier_id' => $secondCourier->id, 'notes' => 'courier unavailable'])
        ->assertRedirect(route('deliveries.show', $delivery));

    expect($delivery->refresh()->courier_id)->toBe($secondCourier->id)
        ->and(AuditLog::where('action', AuditLog::ACTION_REASSIGN_COURIER)->exists())->toBeTrue();
});
