<?php

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\LabOrder\Models\LabOrder;
use App\Modules\LabOrder\Models\LabPickupTask;
use App\Modules\LabOrder\Models\LabWorkflowEvidence;
use App\Modules\LabOrder\Workflow\LabWorkflowState;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    seedAccessControl();
    Storage::fake('local');
    $this->branch = Branch::factory()->main()->create();
});

/** A WAITING_PICKUP V2 order with its PENDING pickup task. */
function pendingPickupTask(?int $branchId = null): LabPickupTask
{
    $branchId = $branchId ?? Branch::query()->where('code', 'MAIN')->value('id');

    $order = LabOrder::factory()->create([
        'workflow_version' => LabOrder::WORKFLOW_V2,
        'status' => LabWorkflowState::WAITING_PICKUP,
        'branch_id' => $branchId,
    ]);

    return LabPickupTask::factory()->create([
        'lab_order_id' => $order->id,
        'branch_id' => $branchId,
        'status' => LabPickupTask::STATUS_PENDING,
    ]);
}

function courier(): User
{
    return userWith(['manage_lab_pickups']);
}

// ---------------------------------------------------------------------------
// Queue access
// ---------------------------------------------------------------------------

it('shows the pickup queue to couriers and lab staff', function () {
    $task = pendingPickupTask();

    $this->actingAs(courier())
        ->get(route('lab-pickup-tasks.index'))
        ->assertOk()
        ->assertSee($task->labOrder->order_number);

    $this->actingAs(userWith(['manage_lab_orders']))
        ->get(route('lab-pickup-tasks.index'))
        ->assertOk();
});

it('denies the pickup queue without the pickup/lab permission', function () {
    $this->actingAs(userWith(['view_clinic_visits']))
        ->get(route('lab-pickup-tasks.index'))
        ->assertForbidden();
});

// ---------------------------------------------------------------------------
// Accept (claim)
// ---------------------------------------------------------------------------

it('lets a courier claim a pending task (order -> PICKUP_ACCEPTED)', function () {
    $task = pendingPickupTask();
    $kurir = courier();

    $this->actingAs($kurir)
        ->post(route('lab-pickup-tasks.accept', $task))
        ->assertRedirect();

    $task->refresh();
    expect($task->status)->toBe(LabPickupTask::STATUS_ACCEPTED);
    expect($task->courier_id)->toBe($kurir->id);
    expect($task->labOrder->status)->toBe(LabWorkflowState::PICKUP_ACCEPTED);
});

it('prevents a second courier from claiming an already-claimed task', function () {
    $task = pendingPickupTask();
    $first = courier();
    $second = courier();

    $this->actingAs($first)->post(route('lab-pickup-tasks.accept', $task));
    $this->actingAs($second)
        ->post(route('lab-pickup-tasks.accept', $task))
        ->assertSessionHasErrors();

    expect($task->refresh()->courier_id)->toBe($first->id);
});

it('treats a re-accept by the same courier as an idempotent no-op', function () {
    $task = pendingPickupTask();
    $kurir = courier();

    $this->actingAs($kurir)->post(route('lab-pickup-tasks.accept', $task));
    $this->actingAs($kurir)
        ->post(route('lab-pickup-tasks.accept', $task))
        ->assertSessionDoesntHaveErrors();

    expect($task->refresh()->courier_id)->toBe($kurir->id);
    expect($task->status)->toBe(LabPickupTask::STATUS_ACCEPTED);
});

// ---------------------------------------------------------------------------
// Picked up (photo mandatory) + transit
// ---------------------------------------------------------------------------

it('requires the pickup photo before confirming pickup', function () {
    $task = pendingPickupTask();
    $kurir = courier();
    $this->actingAs($kurir)->post(route('lab-pickup-tasks.accept', $task));

    $this->actingAs($kurir)
        ->post(route('lab-pickup-tasks.picked-up', $task), ['notes' => 'tanpa foto'])
        ->assertSessionHasErrors('pickup_photo');

    expect($task->refresh()->status)->toBe(LabPickupTask::STATUS_ACCEPTED);
});

it('confirms pickup with photo evidence and moves to PICKED_UP', function () {
    $task = pendingPickupTask();
    $kurir = courier();
    $this->actingAs($kurir)->post(route('lab-pickup-tasks.accept', $task));

    $this->actingAs($kurir)
        ->post(route('lab-pickup-tasks.picked-up', $task), [
            'pickup_photo' => fakeEvidencePhoto('pickup.png'),
            'notes' => 'model lengkap',
        ])
        ->assertRedirect();

    $task->refresh();
    expect($task->status)->toBe(LabPickupTask::STATUS_PICKED_UP);
    expect($task->labOrder->status)->toBe(LabWorkflowState::PICKED_UP);
    expect(LabWorkflowEvidence::where('lab_order_id', $task->lab_order_id)
        ->where('type', LabWorkflowEvidence::TYPE_PICKUP_PHOTO)->exists())->toBeTrue();
});

it('blocks pickup confirmation by a courier who does not own the task', function () {
    $task = pendingPickupTask();
    $owner = courier();
    $this->actingAs($owner)->post(route('lab-pickup-tasks.accept', $task));

    $this->actingAs(courier())
        ->post(route('lab-pickup-tasks.picked-up', $task), [
            'pickup_photo' => fakeEvidencePhoto('pickup.png'),
        ])
        ->assertForbidden();
});

it('cannot skip straight to transit before pickup confirmation', function () {
    $task = pendingPickupTask();
    $kurir = courier();
    $this->actingAs($kurir)->post(route('lab-pickup-tasks.accept', $task));

    $this->actingAs($kurir)
        ->post(route('lab-pickup-tasks.start-transit', $task))
        ->assertSessionHasErrors();

    expect($task->refresh()->status)->toBe(LabPickupTask::STATUS_ACCEPTED);
});

it('starts transit to the lab after pickup', function () {
    $task = pendingPickupTask();
    $kurir = courier();
    $this->actingAs($kurir)->post(route('lab-pickup-tasks.accept', $task));
    $this->actingAs($kurir)->post(route('lab-pickup-tasks.picked-up', $task), [
        'pickup_photo' => fakeEvidencePhoto('pickup.png'),
    ]);

    $this->actingAs($kurir)
        ->post(route('lab-pickup-tasks.start-transit', $task))
        ->assertRedirect();

    $task->refresh();
    expect($task->status)->toBe(LabPickupTask::STATUS_IN_TRANSIT);
    expect($task->labOrder->status)->toBe(LabWorkflowState::IN_TRANSIT_TO_LAB);
});

// ---------------------------------------------------------------------------
// Receive at lab
// ---------------------------------------------------------------------------

it('forbids the courier from confirming lab receipt themselves', function () {
    $task = pendingPickupTask();
    $kurir = courier();
    $this->actingAs($kurir)->post(route('lab-pickup-tasks.accept', $task));
    $this->actingAs($kurir)->post(route('lab-pickup-tasks.picked-up', $task), [
        'pickup_photo' => fakeEvidencePhoto('pickup.png'),
    ]);
    $this->actingAs($kurir)->post(route('lab-pickup-tasks.start-transit', $task));

    $this->actingAs($kurir)
        ->post(route('lab-pickup-tasks.receive', $task))
        ->assertForbidden();

    expect($task->refresh()->status)->toBe(LabPickupTask::STATUS_IN_TRANSIT);
});

it('lets lab staff confirm receipt (order -> RECEIVED_AT_LAB) idempotently', function () {
    $task = pendingPickupTask();
    $kurir = courier();
    $this->actingAs($kurir)->post(route('lab-pickup-tasks.accept', $task));
    $this->actingAs($kurir)->post(route('lab-pickup-tasks.picked-up', $task), [
        'pickup_photo' => fakeEvidencePhoto('pickup.png'),
    ]);
    $this->actingAs($kurir)->post(route('lab-pickup-tasks.start-transit', $task));

    $adminLab = userWith(['manage_lab_orders', 'manage_lab_pickups']);
    $this->actingAs($adminLab)
        ->post(route('lab-pickup-tasks.receive', $task), ['discrepancy_note' => 'gips sedikit gompal'])
        ->assertRedirect();

    $task->refresh();
    expect($task->status)->toBe(LabPickupTask::STATUS_RECEIVED);
    expect($task->received_by)->toBe($adminLab->id);
    expect($task->discrepancy_note)->toBe('gips sedikit gompal');
    expect($task->labOrder->status)->toBe(LabWorkflowState::RECEIVED_AT_LAB);

    // Double-post is an idempotent no-op.
    $this->actingAs($adminLab)
        ->post(route('lab-pickup-tasks.receive', $task))
        ->assertSessionDoesntHaveErrors();
    expect($task->refresh()->status)->toBe(LabPickupTask::STATUS_RECEIVED);
});

it('cannot receive a model that is not yet in transit', function () {
    $task = pendingPickupTask();

    $this->actingAs(userWith(['manage_lab_orders', 'manage_lab_pickups']))
        ->post(route('lab-pickup-tasks.receive', $task))
        ->assertSessionHasErrors();

    expect($task->refresh()->status)->toBe(LabPickupTask::STATUS_PENDING);
});
