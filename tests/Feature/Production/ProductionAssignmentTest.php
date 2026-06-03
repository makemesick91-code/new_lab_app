<?php

use App\Modules\LabOrder\Models\AuditLog;
use App\Modules\LabOrder\Models\LabOrder;
use App\Modules\LabOrder\Models\LabOrderStatusLog;
use App\Modules\Production\Models\LabOrderAssignment;
use App\Modules\Production\Models\ProductionStep;
use App\Modules\Technician\Models\Technician;

beforeEach(function () {
    seedAccessControl();
});

it('lets an authorized user assign a technician', function () {
    $order = receivedOrder();
    $technician = Technician::factory()->create();

    $this->actingAs(userWith(['assign_technicians']))
        ->post(route('production.assign', $order), ['technician_id' => $technician->id, 'notes' => 'go'])
        ->assertRedirect(route('production.show', $order));

    expect(LabOrderAssignment::where('lab_order_id', $order->id)->where('status', 'ASSIGNED')->exists())->toBeTrue();
});

it('changes the order status to ASSIGNED on assignment', function () {
    $order = receivedOrder();

    $this->actingAs(superAdmin())
        ->post(route('production.assign', $order), ['technician_id' => Technician::factory()->create()->id]);

    expect($order->refresh()->status)->toBe('ASSIGNED');
});

it('creates a status log on assignment', function () {
    $order = receivedOrder();

    $this->actingAs(superAdmin())
        ->post(route('production.assign', $order), ['technician_id' => Technician::factory()->create()->id]);

    expect(LabOrderStatusLog::where('lab_order_id', $order->id)->where('new_status', 'ASSIGNED')->exists())->toBeTrue();
});

it('creates an audit log on assignment', function () {
    $order = receivedOrder();

    $this->actingAs(superAdmin())
        ->post(route('production.assign', $order), ['technician_id' => Technician::factory()->create()->id]);

    expect(AuditLog::where('entity_id', $order->id)->where('action', 'ASSIGN_TECHNICIAN')->exists())->toBeTrue();
});

it('creates the default production steps on first assignment', function () {
    $order = receivedOrder();

    $this->actingAs(superAdmin())
        ->post(route('production.assign', $order), ['technician_id' => Technician::factory()->create()->id]);

    expect(ProductionStep::where('lab_order_id', $order->id)->count())->toBe(count(ProductionStep::DEFAULT_STEPS));
});

it('requires a technician id', function () {
    $order = receivedOrder();

    $this->actingAs(superAdmin())
        ->post(route('production.assign', $order), [])
        ->assertSessionHasErrors('technician_id');
});

it('forbids assigning a cancelled order', function () {
    $order = LabOrder::factory()->cancelled()->create();

    $this->actingAs(userWith(['assign_technicians']))
        ->post(route('production.assign', $order), ['technician_id' => Technician::factory()->create()->id])
        ->assertForbidden();
});

it('forbids assigning an order that is not RECEIVED', function () {
    $order = receivedOrder();
    assignOrder($order); // now ASSIGNED

    $this->actingAs(userWith(['assign_technicians']))
        ->post(route('production.assign', $order->refresh()), ['technician_id' => Technician::factory()->create()->id])
        ->assertForbidden();
});

it('denies assignment without permission', function () {
    $order = receivedOrder();

    $this->actingAs(userWith(['view_production']))
        ->post(route('production.assign', $order), ['technician_id' => Technician::factory()->create()->id])
        ->assertForbidden();
});

it('redirects guests to login', function () {
    $this->get(route('production.board'))->assertRedirect(route('login'));
});
