<?php

use App\Models\User;
use App\Modules\Delivery\Models\Delivery;
use App\Modules\Delivery\Services\DeliveryService;
use App\Modules\LabOrder\Models\AuditLog;
use App\Modules\LabOrder\Models\LabOrder;
use App\Modules\LabOrder\Models\LabOrderStatusLog;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    seedAccessControl();
    Storage::fake('public');
    Storage::fake('clinical_evidence');
});

function sprint6DeliveryFor(?User $courier = null): Delivery
{
    $courier = $courier ?? userWith(['view_delivery', 'start_delivery', 'mark_delivered', 'upload_pod']);
    $order = LabOrder::factory()->create(['status' => LabOrder::STATUS_QC_PASSED]);

    return app(DeliveryService::class)->create($order, $courier->id, 'test delivery', superAdmin());
}

it('starts a delivery and logs the status transition', function () {
    $courier = userWith(['view_delivery', 'start_delivery']);
    $delivery = sprint6DeliveryFor($courier);

    $this->actingAs($courier)
        ->post(route('deliveries.start', $delivery), ['notes' => 'out'])
        ->assertRedirect(route('deliveries.show', $delivery));

    expect($delivery->refresh()->status)->toBe(Delivery::STATUS_IN_DELIVERY)
        ->and($delivery->labOrder->refresh()->status)->toBe(LabOrder::STATUS_IN_DELIVERY)
        ->and(LabOrderStatusLog::where('lab_order_id', $delivery->lab_order_id)->where('new_status', LabOrder::STATUS_IN_DELIVERY)->exists())->toBeTrue()
        ->and(AuditLog::where('action', AuditLog::ACTION_START_DELIVERY)->exists())->toBeTrue();
});

it('rejects start delivery without assigned courier', function () {
    $delivery = sprint6DeliveryFor();
    $delivery->update(['courier_id' => null]);

    $this->actingAs(superAdmin())
        ->post(route('deliveries.start', $delivery))
        ->assertSessionHasErrors('courier_id');
});

it('uploads POD with canvas signature data', function () {
    $courier = userWith(['view_delivery', 'start_delivery', 'upload_pod']);
    $delivery = sprint6DeliveryFor($courier);

    $this->actingAs($courier)->post(route('deliveries.start', $delivery));

    $this->actingAs($courier)
        ->post(route('deliveries.pod', $delivery), podPayload())
        ->assertRedirect(route('deliveries.show', $delivery));

    expect($delivery->refresh()->receiver_name)->toBe('Budi Santoso')
        ->and($delivery->receiver_signature_data)->toBe(validPodSignatureData())
        ->and(AuditLog::where('action', AuditLog::ACTION_UPLOAD_POD)->exists())->toBeTrue();
});

it('rejects POD upload without required receiver data and signature', function () {
    $delivery = sprint6DeliveryFor();

    $this->actingAs(superAdmin())
        ->post(route('deliveries.pod', $delivery), [])
        ->assertSessionHasErrors(['receiver_name', 'receiver_signature_data', 'received_at']);
});

it('rejects POD upload without recipient signature data', function () {
    $courier = userWith(['view_delivery', 'start_delivery', 'upload_pod']);
    $delivery = sprint6DeliveryFor($courier);
    $this->actingAs($courier)->post(route('deliveries.start', $delivery));

    $this->actingAs($courier)
        ->post(route('deliveries.pod', $delivery), podPayload(['receiver_signature_data' => '']))
        ->assertSessionHasErrors('receiver_signature_data');
});

it('stores canvas signature data in the database', function () {
    $courier = userWith(['view_delivery', 'start_delivery', 'upload_pod']);
    $delivery = sprint6DeliveryFor($courier);
    $this->actingAs($courier)->post(route('deliveries.start', $delivery));

    $signature = validPodSignatureData();
    $this->actingAs($courier)->post(route('deliveries.pod', $delivery), podPayload([
        'receiver_signature_data' => $signature,
    ]));

    expect($delivery->refresh()->receiver_signature_data)->toBe($signature);
});

it('shows canvas signature on delivery detail after POD is saved', function () {
    $courier = userWith(['view_delivery', 'start_delivery', 'upload_pod']);
    $delivery = sprint6DeliveryFor($courier);
    $this->actingAs($courier)->post(route('deliveries.start', $delivery));
    $this->actingAs($courier)->post(route('deliveries.pod', $delivery), podPayload());

    $this->actingAs($courier)
        ->get(route('deliveries.show', $delivery))
        ->assertOk()
        ->assertSee('Tanda Tangan Penerima')
        ->assertSee(validPodSignatureData(), false);
});

it('marks delivered with POD and stores canvas signature', function () {
    $courier = userWith(['view_delivery', 'start_delivery', 'mark_delivered', 'upload_pod']);
    $delivery = sprint6DeliveryFor($courier);
    $this->actingAs($courier)->post(route('deliveries.start', $delivery));

    $this->actingAs($courier)
        ->post(route('deliveries.mark-delivered', $delivery), podPayload())
        ->assertRedirect(route('deliveries.show', $delivery));

    expect($delivery->refresh()->status)->toBe(Delivery::STATUS_DELIVERED)
        ->and($delivery->labOrder->refresh()->status)->toBe(LabOrder::STATUS_DELIVERED)
        ->and($delivery->receiver_signature_data)->toBe(validPodSignatureData())
        ->and(AuditLog::where('action', AuditLog::ACTION_MARK_DELIVERED)->exists())->toBeTrue();
});

it('completes a delivered order with POD', function () {
    $courier = userWith(['view_delivery', 'start_delivery', 'mark_delivered', 'upload_pod']);
    $delivery = sprint6DeliveryFor($courier);
    $this->actingAs($courier)->post(route('deliveries.start', $delivery));
    $this->actingAs($courier)->post(route('deliveries.mark-delivered', $delivery), podPayload());

    $this->actingAs(superAdmin())
        ->post(route('deliveries.complete', $delivery->refresh()))
        ->assertRedirect(route('deliveries.show', $delivery));

    expect($delivery->refresh()->status)->toBe(Delivery::STATUS_COMPLETED)
        ->and($delivery->labOrder->refresh()->status)->toBe(LabOrder::STATUS_COMPLETED)
        ->and(LabOrderStatusLog::where('lab_order_id', $delivery->lab_order_id)->where('new_status', LabOrder::STATUS_COMPLETED)->exists())->toBeTrue()
        ->and(AuditLog::where('action', AuditLog::ACTION_COMPLETE_DELIVERY)->exists())->toBeTrue();
});

it('denies delivery actions for users without permission', function () {
    $delivery = sprint6DeliveryFor();

    $this->actingAs(userWith(['view_delivery']))
        ->post(route('deliveries.start', $delivery))
        ->assertForbidden();
});

it('shows legacy delivery detail without canvas signature without crashing', function () {
    $delivery = Delivery::factory()->delivered()->create();

    $this->actingAs(superAdmin())
        ->get(route('deliveries.show', $delivery))
        ->assertOk()
        ->assertSee('Panel POD')
        ->assertSee('Tanda Tangan Penerima');
});
