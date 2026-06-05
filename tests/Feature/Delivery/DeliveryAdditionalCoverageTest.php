<?php

use App\Models\User;
use App\Modules\Delivery\Models\Delivery;
use App\Modules\Delivery\Services\DeliveryService;
use App\Modules\LabOrder\Models\Attachment;
use App\Modules\LabOrder\Models\LabOrder;
use Database\Seeders\PermissionSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    seedAccessControl();
    Storage::fake('public');
});

function additionalDelivery(?User $courier = null): Delivery
{
    $courier = $courier ?? userWith(['view_delivery', 'start_delivery', 'mark_delivered', 'upload_pod']);
    $order = LabOrder::factory()->create(['status' => LabOrder::STATUS_QC_PASSED]);

    return app(DeliveryService::class)->create($order, $courier->id, 'additional test', superAdmin());
}

function startAdditionalDelivery(Delivery $delivery, User $courier): Delivery
{
    test()->actingAs($courier)->post(route('deliveries.start', $delivery));

    return $delivery->refresh();
}

it('shows QC_PASSED orders in the ready to prepare section', function () {
    $order = LabOrder::factory()->create(['status' => LabOrder::STATUS_QC_PASSED]);

    $this->actingAs(userWith(['view_delivery', 'create_delivery']))
        ->get(route('deliveries.index'))
        ->assertOk()
        ->assertSee($order->order_number);
});

it('shows existing delivery records in the queue', function () {
    $delivery = additionalDelivery();

    $this->actingAs(userWith(['view_delivery']))
        ->get(route('deliveries.index'))
        ->assertOk()
        ->assertSee($delivery->delivery_number);
});

it('filters deliveries by status', function () {
    $ready = additionalDelivery();
    $courier = userWith(['view_delivery', 'start_delivery']);
    $inDelivery = startAdditionalDelivery(additionalDelivery($courier), $courier);

    $this->actingAs(userWith(['view_delivery']))
        ->get(route('deliveries.index', ['status' => Delivery::STATUS_IN_DELIVERY]))
        ->assertOk()
        ->assertSee($inDelivery->delivery_number)
        ->assertDontSee($ready->delivery_number);
});

it('filters deliveries by courier', function () {
    $firstCourier = userWith(['view_delivery']);
    $secondCourier = userWith(['view_delivery']);
    $first = additionalDelivery($firstCourier);
    $second = additionalDelivery($secondCourier);

    $this->actingAs(userWith(['view_delivery']))
        ->get(route('deliveries.index', ['courier_id' => $firstCourier->id]))
        ->assertOk()
        ->assertSee($first->delivery_number)
        ->assertDontSee($second->delivery_number);
});

it('shows the delivery detail panels', function () {
    $delivery = additionalDelivery();

    $this->actingAs(superAdmin())
        ->get(route('deliveries.show', $delivery))
        ->assertOk()
        ->assertSee('Penugasan Kurir')
        ->assertSee('Panel POD')
        ->assertSee('Panel Bukti')
        ->assertSee('Riwayat Audit');
});

it('generates a delivery number with the DLV format', function () {
    $delivery = additionalDelivery();

    expect($delivery->delivery_number)->toMatch('/^DLV-\d{4}-\d{6}$/');
});

it('allows creating delivery without assigning courier immediately', function () {
    $order = LabOrder::factory()->create(['status' => LabOrder::STATUS_QC_PASSED]);

    $this->actingAs(userWith(['create_delivery']))
        ->post(route('deliveries.store'), ['lab_order_id' => $order->id])
        ->assertRedirect();

    expect(Delivery::first()->courier_id)->toBeNull();
});

it('blocks courier assignment after delivered', function () {
    $delivery = additionalDelivery();
    $delivery->update(['status' => Delivery::STATUS_DELIVERED]);

    $this->actingAs(userWith(['assign_courier']))
        ->post(route('deliveries.assign-courier', $delivery), ['courier_id' => userWith(['view_delivery'])->id])
        ->assertForbidden();
});

it('requires notes for courier reassignment', function () {
    $delivery = additionalDelivery();

    $this->actingAs(userWith(['assign_courier']))
        ->post(route('deliveries.reassign-courier', $delivery), ['courier_id' => userWith(['view_delivery'])->id])
        ->assertSessionHasErrors('notes');
});

it('denies start delivery to a courier who is not assigned', function () {
    $delivery = additionalDelivery(userWith(['view_delivery']));
    $otherCourier = userWith(['view_delivery', 'start_delivery']);

    $this->actingAs($otherCourier)
        ->post(route('deliveries.start', $delivery))
        ->assertForbidden();
});

it('allows management to start an assigned delivery', function () {
    $delivery = additionalDelivery(userWith(['view_delivery']));

    $this->actingAs(userWith(['manage_delivery']))
        ->post(route('deliveries.start', $delivery))
        ->assertRedirect(route('deliveries.show', $delivery));

    expect($delivery->refresh()->status)->toBe(Delivery::STATUS_IN_DELIVERY);
});

it('rejects mark delivered without signature', function () {
    $courier = userWith(['view_delivery', 'start_delivery', 'mark_delivered']);
    $delivery = startAdditionalDelivery(additionalDelivery($courier), $courier);

    $this->actingAs($courier)
        ->post(route('deliveries.mark-delivered', $delivery), [
            'receiver_name' => 'Budi',
            'received_at' => now()->format('Y-m-d H:i:s'),
            'receiver_photo' => UploadedFile::fake()->create('receiver.png', 10, 'image/png'),
        ])
        ->assertSessionHasErrors('signature');
});

it('rejects mark delivered without receiver photo', function () {
    $courier = userWith(['view_delivery', 'start_delivery', 'mark_delivered']);
    $delivery = startAdditionalDelivery(additionalDelivery($courier), $courier);

    $this->actingAs($courier)
        ->post(route('deliveries.mark-delivered', $delivery), [
            'receiver_name' => 'Budi',
            'received_at' => now()->format('Y-m-d H:i:s'),
            'signature' => UploadedFile::fake()->create('signature.png', 10, 'image/png'),
        ])
        ->assertSessionHasErrors('receiver_photo');
});

it('rejects mark delivered without receiver name', function () {
    $courier = userWith(['view_delivery', 'start_delivery', 'mark_delivered']);
    $delivery = startAdditionalDelivery(additionalDelivery($courier), $courier);

    $this->actingAs($courier)
        ->post(route('deliveries.mark-delivered', $delivery), [
            'received_at' => now()->format('Y-m-d H:i:s'),
            'signature' => UploadedFile::fake()->create('signature.png', 10, 'image/png'),
            'receiver_photo' => UploadedFile::fake()->create('receiver.png', 10, 'image/png'),
        ])
        ->assertSessionHasErrors('receiver_name');
});

it('blocks completion when POD is incomplete', function () {
    $delivery = additionalDelivery();
    $delivery->update(['status' => Delivery::STATUS_DELIVERED]);
    $delivery->labOrder->update(['status' => LabOrder::STATUS_DELIVERED]);

    $this->actingAs(superAdmin())
        ->post(route('deliveries.complete', $delivery))
        ->assertSessionHasErrors('pod');
});

it('blocks duplicate completion', function () {
    $delivery = Delivery::factory()->completed()->create();
    $delivery->labOrder->update(['status' => LabOrder::STATUS_COMPLETED]);

    $this->actingAs(superAdmin())
        ->post(route('deliveries.complete', $delivery))
        ->assertSessionHasErrors('status');
});

it('updates receiver signature and photo paths after POD upload', function () {
    $courier = userWith(['view_delivery', 'start_delivery', 'upload_pod']);
    $delivery = startAdditionalDelivery(additionalDelivery($courier), $courier);

    $this->actingAs($courier)->post(route('deliveries.pod', $delivery), [
        'receiver_name' => 'Budi',
        'received_at' => now()->format('Y-m-d H:i:s'),
        'signature' => UploadedFile::fake()->create('signature.png', 10, 'image/png'),
        'receiver_photo' => UploadedFile::fake()->create('receiver.png', 10, 'image/png'),
    ]);

    expect($delivery->refresh()->receiver_signature_path)->not->toBeNull()
        ->and($delivery->receiver_photo_path)->not->toBeNull();
});

it('denies delivery detail to an unrelated courier', function () {
    $delivery = additionalDelivery(userWith(['view_delivery']));

    $this->actingAs(userWith(['view_delivery']))
        ->get(route('deliveries.show', $delivery))
        ->assertForbidden();
});

it('allows assigned courier to view delivery detail', function () {
    $courier = userWith(['view_delivery']);
    $delivery = additionalDelivery($courier);

    $this->actingAs($courier)
        ->get(route('deliveries.show', $delivery))
        ->assertOk();
});

it('allows the Quality Control role to view the delivery queue', function () {
    $qc = User::factory()->create()->assignRole('Quality Control');

    $this->actingAs($qc)
        ->get(route('deliveries.index'))
        ->assertOk();
});

it('allows the Technician role to view the delivery queue', function () {
    $technician = User::factory()->create()->assignRole('Technician');

    $this->actingAs($technician)
        ->get(route('deliveries.index'))
        ->assertOk();
});

it('seeds all Sprint 6 delivery permissions', function () {
    expect(PermissionSeeder::PERMISSIONS)->toContain(
        'manage_delivery',
        'view_delivery',
        'create_delivery',
        'assign_courier',
        'start_delivery',
        'mark_delivered',
        'complete_delivery',
        'upload_pod',
    );
});

it('grants Admin Lab all delivery permissions', function () {
    $role = Role::findByName('Admin Lab');

    expect($role->hasPermissionTo('manage_delivery'))->toBeTrue()
        ->and($role->hasPermissionTo('complete_delivery'))->toBeTrue()
        ->and($role->hasPermissionTo('upload_pod'))->toBeTrue();
});

it('grants Courier operational delivery permissions only', function () {
    $role = Role::findByName('Courier');

    expect($role->hasPermissionTo('view_delivery'))->toBeTrue()
        ->and($role->hasPermissionTo('start_delivery'))->toBeTrue()
        ->and($role->hasPermissionTo('mark_delivered'))->toBeTrue()
        ->and($role->hasPermissionTo('upload_pod'))->toBeTrue()
        ->and($role->hasPermissionTo('manage_delivery'))->toBeFalse();
});

it('stores delivery attachments with the delivery entity type', function () {
    $courier = userWith(['view_delivery', 'start_delivery', 'upload_pod']);
    $delivery = startAdditionalDelivery(additionalDelivery($courier), $courier);

    $this->actingAs($courier)->post(route('deliveries.pod', $delivery), [
        'receiver_name' => 'Budi',
        'received_at' => now()->format('Y-m-d H:i:s'),
        'signature' => UploadedFile::fake()->create('signature.png', 10, 'image/png'),
        'receiver_photo' => UploadedFile::fake()->create('receiver.png', 10, 'image/png'),
    ]);

    expect(Attachment::where('entity_type', Delivery::ENTITY_TYPE)->count())->toBe(2);
});
