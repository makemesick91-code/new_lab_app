<?php

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\LabOrder\Models\LabDeliveryTask;
use App\Modules\LabOrder\Models\LabOrder;
use App\Modules\LabOrder\Models\LabWorkflowEvidence;
use App\Modules\LabOrder\Services\LabWorkflowStateMachine;
use App\Modules\LabOrder\Workflow\LabWorkflowState;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    seedAccessControl();
    Storage::fake('local');
    $this->branch = Branch::factory()->main()->create();
});

/** Minimal real 1x1 PNG as a signature-canvas data URL (no GD needed). */
function fakeSignatureDataUrl(): string
{
    return 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';
}

/** A V2 order at MODEL_DONE for the active MAIN branch. */
function modelDoneOrder(): LabOrder
{
    return LabOrder::factory()->create([
        'workflow_version' => LabOrder::WORKFLOW_V2,
        'status' => LabWorkflowState::MODEL_DONE,
        'branch_id' => Branch::query()->where('code', 'MAIN')->value('id'),
    ]);
}

function deliveryCourier(): User
{
    return userWith(['view_delivery', 'start_delivery', 'mark_delivered']);
}

/** Create the delivery task via the lab-side endpoint and return it. */
function createdDeliveryTask(LabOrder $order): LabDeliveryTask
{
    test()->actingAs(userWith(['manage_lab_orders', 'create_delivery']))
        ->post(route('lab-v2-orders.delivery-task.store', $order));

    return LabDeliveryTask::where('lab_order_id', $order->id)->firstOrFail();
}

/** Drive a task to ARRIVED with complete pre-transit proofs. */
function arrivedTask(LabOrder $order, User $kurir): LabDeliveryTask
{
    $task = createdDeliveryTask($order);
    test()->actingAs($kurir)->post(route('lab-delivery-tasks.accept', $task));
    test()->actingAs($kurir)->post(route('lab-delivery-tasks.handover', $task), [
        'handover_photo' => fakeEvidencePhoto('handover.png'),
        'courier_signature' => fakeSignatureDataUrl(),
    ]);
    test()->actingAs($kurir)->post(route('lab-delivery-tasks.start-transit', $task));
    test()->actingAs($kurir)->post(route('lab-delivery-tasks.arrived', $task));

    return $task->refresh();
}

// ---------------------------------------------------------------------------
// Task creation + acceptance
// ---------------------------------------------------------------------------

it('creates the delivery task only after MODEL_DONE, idempotently', function () {
    $order = modelDoneOrder();
    $task = createdDeliveryTask($order);

    expect($order->refresh()->status)->toBe(LabWorkflowState::COURIER_NOTIFIED);
    expect($task->status)->toBe(LabDeliveryTask::STATUS_PENDING);
    expect($task->branch_id)->toBe($order->branch_id);

    // Re-posting does not duplicate.
    test()->actingAs(userWith(['manage_lab_orders', 'create_delivery']))
        ->post(route('lab-v2-orders.delivery-task.store', $order));
    expect(LabDeliveryTask::where('lab_order_id', $order->id)->count())->toBe(1);
});

it('refuses a delivery task while production is not done', function () {
    $order = LabOrder::factory()->create([
        'workflow_version' => LabOrder::WORKFLOW_V2,
        'status' => LabWorkflowState::QC_PENDING,
        'branch_id' => $this->branch->id,
    ]);

    $this->actingAs(userWith(['manage_lab_orders', 'create_delivery']))
        ->post(route('lab-v2-orders.delivery-task.store', $order))
        ->assertSessionHasErrors();

    expect(LabDeliveryTask::count())->toBe(0);
});

it('lets a courier claim the delivery; a second courier is rejected', function () {
    $order = modelDoneOrder();
    $task = createdDeliveryTask($order);
    $first = deliveryCourier();
    $second = deliveryCourier();

    $this->actingAs($first)->post(route('lab-delivery-tasks.accept', $task))->assertRedirect();
    $this->actingAs($second)->post(route('lab-delivery-tasks.accept', $task))->assertSessionHasErrors();

    $task->refresh();
    expect($task->courier_id)->toBe($first->id);
    expect($task->labOrder->status)->toBe(LabWorkflowState::LAB_HANDOVER_PENDING);
});

// ---------------------------------------------------------------------------
// OWNER RULE — pre-transit: photo + courier signature both mandatory
// ---------------------------------------------------------------------------

it('rejects transit while the handover proof has not been submitted at all', function () {
    $order = modelDoneOrder();
    $task = createdDeliveryTask($order);
    $kurir = deliveryCourier();
    $this->actingAs($kurir)->post(route('lab-delivery-tasks.accept', $task));

    $this->actingAs($kurir)
        ->post(route('lab-delivery-tasks.start-transit', $task))
        ->assertSessionHasErrors();

    expect($task->refresh()->status)->toBe(LabDeliveryTask::STATUS_ACCEPTED);
    expect($task->labOrder->status)->toBe(LabWorkflowState::LAB_HANDOVER_PENDING);
});

it('rejects a handover with only the photo (no courier signature)', function () {
    $order = modelDoneOrder();
    $task = createdDeliveryTask($order);
    $kurir = deliveryCourier();
    $this->actingAs($kurir)->post(route('lab-delivery-tasks.accept', $task));

    $this->actingAs($kurir)
        ->post(route('lab-delivery-tasks.handover', $task), [
            'handover_photo' => fakeEvidencePhoto('handover.png'),
        ])
        ->assertSessionHasErrors('courier_signature');

    expect($task->refresh()->status)->toBe(LabDeliveryTask::STATUS_ACCEPTED);
    expect(LabWorkflowEvidence::where('lab_order_id', $order->id)->count())->toBe(0);
});

it('rejects a handover with only the signature (no photo)', function () {
    $order = modelDoneOrder();
    $task = createdDeliveryTask($order);
    $kurir = deliveryCourier();
    $this->actingAs($kurir)->post(route('lab-delivery-tasks.accept', $task));

    $this->actingAs($kurir)
        ->post(route('lab-delivery-tasks.handover', $task), [
            'courier_signature' => fakeSignatureDataUrl(),
        ])
        ->assertSessionHasErrors('handover_photo');

    expect($task->refresh()->status)->toBe(LabDeliveryTask::STATUS_ACCEPTED);
});

it('rejects a non-PNG signature payload', function () {
    $order = modelDoneOrder();
    $task = createdDeliveryTask($order);
    $kurir = deliveryCourier();
    $this->actingAs($kurir)->post(route('lab-delivery-tasks.accept', $task));

    $this->actingAs($kurir)
        ->post(route('lab-delivery-tasks.handover', $task), [
            'handover_photo' => fakeEvidencePhoto('handover.png'),
            'courier_signature' => 'data:image/png;base64,'.base64_encode('<?php echo "not a png"; ?>'),
        ])
        ->assertSessionHasErrors();

    expect($task->refresh()->status)->toBe(LabDeliveryTask::STATUS_ACCEPTED);
    expect(LabWorkflowEvidence::where('lab_order_id', $order->id)
        ->where('type', LabWorkflowEvidence::TYPE_COURIER_SIGNATURE)->count())->toBe(0);
});

it('allows transit once BOTH handover photo and courier signature exist', function () {
    $order = modelDoneOrder();
    $task = createdDeliveryTask($order);
    $kurir = deliveryCourier();
    $this->actingAs($kurir)->post(route('lab-delivery-tasks.accept', $task));

    $this->actingAs($kurir)
        ->post(route('lab-delivery-tasks.handover', $task), [
            'handover_photo' => fakeEvidencePhoto('handover.png'),
            'courier_signature' => fakeSignatureDataUrl(),
        ])
        ->assertRedirect();

    $task->refresh();
    expect($task->status)->toBe(LabDeliveryTask::STATUS_READY_FOR_TRANSIT);
    expect($task->labOrder->status)->toBe(LabWorkflowState::READY_FOR_TRANSIT_TO_BRANCH);

    foreach ([LabWorkflowEvidence::TYPE_PRE_DELIVERY_HANDOVER_PHOTO, LabWorkflowEvidence::TYPE_COURIER_SIGNATURE] as $type) {
        expect(LabWorkflowEvidence::where('lab_order_id', $order->id)->where('type', $type)->exists())->toBeTrue();
    }

    $this->actingAs($kurir)
        ->post(route('lab-delivery-tasks.start-transit', $task))
        ->assertRedirect();
    expect($task->refresh()->labOrder->status)->toBe(LabWorkflowState::IN_TRANSIT_TO_BRANCH);
});

it('blocks a non-owner courier from submitting handover proof', function () {
    $order = modelDoneOrder();
    $task = createdDeliveryTask($order);
    $owner = deliveryCourier();
    $this->actingAs($owner)->post(route('lab-delivery-tasks.accept', $task));

    $this->actingAs(deliveryCourier())
        ->post(route('lab-delivery-tasks.handover', $task), [
            'handover_photo' => fakeEvidencePhoto('handover.png'),
            'courier_signature' => fakeSignatureDataUrl(),
        ])
        ->assertForbidden();
});

// ---------------------------------------------------------------------------
// OWNER RULE — DELIVERED: recipient signature + name + location photo mandatory
// ---------------------------------------------------------------------------

it('rejects completion without the recipient signature', function () {
    $kurir = deliveryCourier();
    $task = arrivedTask(modelDoneOrder(), $kurir);

    $this->actingAs($kurir)
        ->post(route('lab-delivery-tasks.complete', $task), [
            'recipient_name' => 'Perawat Ani',
            'location_photo' => fakeEvidencePhoto('lokasi.png'),
        ])
        ->assertSessionHasErrors('recipient_signature');

    expect($task->refresh()->status)->toBe(LabDeliveryTask::STATUS_ARRIVED);
});

it('rejects completion without the recipient name', function () {
    $kurir = deliveryCourier();
    $task = arrivedTask(modelDoneOrder(), $kurir);

    $this->actingAs($kurir)
        ->post(route('lab-delivery-tasks.complete', $task), [
            'recipient_signature' => fakeSignatureDataUrl(),
            'location_photo' => fakeEvidencePhoto('lokasi.png'),
        ])
        ->assertSessionHasErrors('recipient_name');

    expect($task->refresh()->status)->toBe(LabDeliveryTask::STATUS_ARRIVED);
});

it('rejects completion without the location/proof photo', function () {
    $kurir = deliveryCourier();
    $task = arrivedTask(modelDoneOrder(), $kurir);

    $this->actingAs($kurir)
        ->post(route('lab-delivery-tasks.complete', $task), [
            'recipient_name' => 'Perawat Ani',
            'recipient_signature' => fakeSignatureDataUrl(),
        ])
        ->assertSessionHasErrors('location_photo');

    expect($task->refresh()->status)->toBe(LabDeliveryTask::STATUS_ARRIVED);
});

it('completes DELIVERED with the full mandatory proof set, idempotently', function () {
    $kurir = deliveryCourier();
    $order = modelDoneOrder();
    $task = arrivedTask($order, $kurir);

    $this->actingAs($kurir)
        ->post(route('lab-delivery-tasks.complete', $task), [
            'recipient_name' => 'Perawat Ani',
            'recipient_role' => 'Perawat',
            'recipient_signature' => fakeSignatureDataUrl(),
            'location_photo' => fakeEvidencePhoto('lokasi.png'),
            'notes' => 'Diterima lengkap',
        ])
        ->assertRedirect();

    $task->refresh();
    expect($task->status)->toBe(LabDeliveryTask::STATUS_DELIVERED);
    expect($task->recipient_name)->toBe('Perawat Ani');
    expect($task->labOrder->status)->toBe(LabWorkflowState::DELIVERED);

    foreach ([LabWorkflowEvidence::TYPE_RECIPIENT_SIGNATURE, LabWorkflowEvidence::TYPE_DELIVERY_LOCATION_PHOTO] as $type) {
        expect(LabWorkflowEvidence::where('lab_order_id', $order->id)->where('type', $type)->count())->toBe(1);
    }

    // Double completion: safe no-op, no duplicate evidence.
    $this->actingAs($kurir)
        ->post(route('lab-delivery-tasks.complete', $task), [
            'recipient_name' => 'Perawat Ani',
            'recipient_signature' => fakeSignatureDataUrl(),
            'location_photo' => fakeEvidencePhoto('lokasi2.png'),
        ])
        ->assertSessionDoesntHaveErrors();
    expect(LabWorkflowEvidence::where('lab_order_id', $order->id)
        ->where('type', LabWorkflowEvidence::TYPE_RECIPIENT_SIGNATURE)->count())->toBe(1);

    // Terminal state: nothing can move a DELIVERED order.
    expect(fn () => app(LabWorkflowStateMachine::class)
        ->transition($task->labOrder, LabWorkflowState::DELIVERY_PENDING, superAdmin()))
        ->toThrow(ValidationException::class);
});

it('cannot complete before arriving at the branch', function () {
    $order = modelDoneOrder();
    $task = createdDeliveryTask($order);
    $kurir = deliveryCourier();
    $this->actingAs($kurir)->post(route('lab-delivery-tasks.accept', $task));

    $this->actingAs($kurir)
        ->post(route('lab-delivery-tasks.complete', $task), [
            'recipient_name' => 'Perawat Ani',
            'recipient_signature' => fakeSignatureDataUrl(),
            'location_photo' => fakeEvidencePhoto('lokasi.png'),
        ])
        ->assertSessionHasErrors();

    expect($task->refresh()->status)->toBe(LabDeliveryTask::STATUS_ACCEPTED);
});

it('denies the delivery queue without a delivery permission', function () {
    $this->actingAs(userWith(['view_clinic_visits']))
        ->get(route('lab-delivery-tasks.index'))
        ->assertForbidden();

    $this->actingAs(deliveryCourier())
        ->get(route('lab-delivery-tasks.index'))
        ->assertOk();
});
