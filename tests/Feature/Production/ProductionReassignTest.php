<?php

use App\Modules\LabOrder\Models\AuditLog;
use App\Modules\Production\Models\LabOrderAssignment;
use App\Modules\Technician\Models\Technician;

beforeEach(function () {
    seedAccessControl();
});

it('lets an authorized user reassign a technician', function () {
    $order = receivedOrder();
    $original = assignOrder($order);
    $newTech = Technician::factory()->create();

    $this->actingAs(userWith(['reassign_technicians']))
        ->post(route('production.reassign', $order->refresh()), ['technician_id' => $newTech->id, 'reason' => 'unavailable'])
        ->assertRedirect(route('production.show', $order));

    expect(LabOrderAssignment::find($original->id)->status)->toBe('REASSIGNED');
});

it('creates a new ASSIGNED assignment for the new technician', function () {
    $order = receivedOrder();
    assignOrder($order);
    $newTech = Technician::factory()->create();

    $this->actingAs(superAdmin())
        ->post(route('production.reassign', $order->refresh()), ['technician_id' => $newTech->id, 'reason' => 'unavailable']);

    expect(LabOrderAssignment::where('lab_order_id', $order->id)
        ->where('technician_id', $newTech->id)
        ->where('status', 'ASSIGNED')
        ->exists())->toBeTrue();
});

it('requires a reason to reassign', function () {
    $order = receivedOrder();
    assignOrder($order);

    $this->actingAs(superAdmin())
        ->post(route('production.reassign', $order->refresh()), ['technician_id' => Technician::factory()->create()->id])
        ->assertSessionHasErrors('reason');
});

it('creates an audit log on reassignment', function () {
    $order = receivedOrder();
    assignOrder($order);

    $this->actingAs(superAdmin())
        ->post(route('production.reassign', $order->refresh()), ['technician_id' => Technician::factory()->create()->id, 'reason' => 'unavailable']);

    expect(AuditLog::where('entity_id', $order->id)->where('action', 'REASSIGN_TECHNICIAN')->exists())->toBeTrue();
});

it('rejects reassigning to the same technician', function () {
    $order = receivedOrder();
    $assignment = assignOrder($order);

    $this->actingAs(superAdmin())
        ->post(route('production.reassign', $order->refresh()), ['technician_id' => $assignment->technician_id, 'reason' => 'same tech'])
        ->assertSessionHasErrors('technician_id');
});

it('forbids reassigning a RECEIVED order', function () {
    $order = receivedOrder();

    $this->actingAs(userWith(['reassign_technicians']))
        ->post(route('production.reassign', $order), ['technician_id' => Technician::factory()->create()->id, 'reason' => 'whatever'])
        ->assertForbidden();
});

it('denies reassignment without permission', function () {
    $order = receivedOrder();
    assignOrder($order);

    $this->actingAs(userWith(['view_production']))
        ->post(route('production.reassign', $order->refresh()), ['technician_id' => Technician::factory()->create()->id, 'reason' => 'unavailable'])
        ->assertForbidden();
});
