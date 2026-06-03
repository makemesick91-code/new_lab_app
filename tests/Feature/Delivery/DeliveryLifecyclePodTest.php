<?php

use App\Modules\Delivery\Models\Delivery;
use App\Modules\Delivery\Services\DeliveryService;
use App\Modules\LabOrder\Models\Attachment;
use App\Modules\LabOrder\Models\AuditLog;
use App\Modules\LabOrder\Models\LabOrder;
use App\Modules\LabOrder\Models\LabOrderStatusLog;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    seedAccessControl();
    Storage::fake('public');
});

function sprint6DeliveryFor(?\App\Models\User $courier = null): Delivery
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

it('uploads POD evidence to sys_attachments', function () {
    $courier = userWith(['view_delivery', 'start_delivery', 'upload_pod']);
    $delivery = sprint6DeliveryFor($courier);

    $this->actingAs($courier)->post(route('deliveries.start', $delivery));

    $this->actingAs($courier)
        ->post(route('deliveries.pod', $delivery), [
            'receiver_name' => 'Budi Santoso',
            'received_at' => now()->format('Y-m-d H:i:s'),
            'signature' => UploadedFile::fake()->create('signature.png', 10, 'image/png'),
            'receiver_photo' => UploadedFile::fake()->create('receiver.png', 10, 'image/png'),
        ])
        ->assertRedirect(route('deliveries.show', $delivery));

    expect($delivery->refresh()->receiver_name)->toBe('Budi Santoso')
        ->and(Attachment::where('entity_type', Delivery::ENTITY_TYPE)->where('category', 'POD_SIGNATURE')->exists())->toBeTrue()
        ->and(Attachment::where('entity_type', Delivery::ENTITY_TYPE)->where('category', 'POD_RECEIVER_PHOTO')->exists())->toBeTrue()
        ->and(AuditLog::where('action', AuditLog::ACTION_UPLOAD_POD)->exists())->toBeTrue();
});

it('rejects POD upload without required receiver data and files', function () {
    $delivery = sprint6DeliveryFor();

    $this->actingAs(superAdmin())
        ->post(route('deliveries.pod', $delivery), [])
        ->assertSessionHasErrors(['receiver_name', 'signature', 'receiver_photo', 'received_at']);
});

it('marks delivered with POD and creates evidence categories', function () {
    $courier = userWith(['view_delivery', 'start_delivery', 'mark_delivered', 'upload_pod']);
    $delivery = sprint6DeliveryFor($courier);
    $this->actingAs($courier)->post(route('deliveries.start', $delivery));

    $this->actingAs($courier)
        ->post(route('deliveries.mark-delivered', $delivery), [
            'receiver_name' => 'Budi Santoso',
            'received_at' => now()->format('Y-m-d H:i:s'),
            'signature' => UploadedFile::fake()->create('signature.png', 10, 'image/png'),
            'receiver_photo' => UploadedFile::fake()->create('receiver.png', 10, 'image/png'),
        ])
        ->assertRedirect(route('deliveries.show', $delivery));

    expect($delivery->refresh()->status)->toBe(Delivery::STATUS_DELIVERED)
        ->and($delivery->labOrder->refresh()->status)->toBe(LabOrder::STATUS_DELIVERED)
        ->and(Attachment::where('category', 'POD_SIGNATURE')->exists())->toBeTrue()
        ->and(Attachment::where('category', 'POD_RECEIVER_PHOTO')->exists())->toBeTrue()
        ->and(AuditLog::where('action', AuditLog::ACTION_MARK_DELIVERED)->exists())->toBeTrue();
});

it('completes a delivered order with POD', function () {
    $courier = userWith(['view_delivery', 'start_delivery', 'mark_delivered', 'upload_pod']);
    $delivery = sprint6DeliveryFor($courier);
    $this->actingAs($courier)->post(route('deliveries.start', $delivery));
    $this->actingAs($courier)->post(route('deliveries.mark-delivered', $delivery), [
        'receiver_name' => 'Budi Santoso',
        'received_at' => now()->format('Y-m-d H:i:s'),
        'signature' => UploadedFile::fake()->create('signature.png', 10, 'image/png'),
        'receiver_photo' => UploadedFile::fake()->create('receiver.png', 10, 'image/png'),
    ]);

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
