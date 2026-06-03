<?php

use App\Modules\LabOrder\Models\AuditLog;
use App\Modules\LabOrder\Models\LabOrder;
use App\Modules\LabOrder\Models\LabOrderStatusLog;
use App\Modules\LabOrder\Services\LabOrderService;

beforeEach(function () {
    seedAccessControl();
});

it('lists lab orders for an authorized user', function () {
    $this->actingAs(userWith(['view_lab_orders']))
        ->get(route('lab-orders.index'))
        ->assertOk()
        ->assertViewIs('lab-orders.index');
});

it('renders the create page for an authorized user', function () {
    $this->actingAs(userWith(['create_lab_orders']))
        ->get(route('lab-orders.create'))
        ->assertOk()
        ->assertViewIs('lab-orders.create');
});

it('creates a lab order (happy path)', function () {
    $this->actingAs(userWith(['create_lab_orders']))
        ->post(route('lab-orders.store'), labOrderPayload())
        ->assertRedirect();

    expect(LabOrder::count())->toBe(1);
});

it('auto generates a unique order number in ADL-YYYY-XXXXXX format', function () {
    $admin = superAdmin();
    $this->actingAs($admin)->post(route('lab-orders.store'), labOrderPayload());
    $this->actingAs($admin)->post(route('lab-orders.store'), labOrderPayload());

    $numbers = LabOrder::orderBy('id')->pluck('order_number');

    $year = now()->format('Y');
    expect($numbers[0])->toMatch('/^ADL-'.$year.'-\d{6}$/');
    expect($numbers[1])->toMatch('/^ADL-'.$year.'-\d{6}$/');
    expect($numbers[0])->not->toBe($numbers[1]);
});

it('starts a new order with status RECEIVED', function () {
    $this->actingAs(superAdmin())->post(route('lab-orders.store'), labOrderPayload());

    expect(LabOrder::first()->status)->toBe('RECEIVED');
});

it('creates a status log on create', function () {
    $this->actingAs(superAdmin())->post(route('lab-orders.store'), labOrderPayload());
    $order = LabOrder::first();

    $log = LabOrderStatusLog::where('lab_order_id', $order->id)->first();
    expect($log)->not->toBeNull();
    expect($log->old_status)->toBeNull();
    expect($log->new_status)->toBe('RECEIVED');
});

it('creates an audit log on create', function () {
    $this->actingAs(superAdmin())->post(route('lab-orders.store'), labOrderPayload());
    $order = LabOrder::first();

    expect(AuditLog::where('entity_type', 'trx_lab_orders')
        ->where('entity_id', $order->id)
        ->where('action', 'CREATE')
        ->exists())->toBeTrue();
});

it('stores the computed subtotal for each item', function () {
    $payload = labOrderPayload();
    $payload['items'] = [['lab_service_id' => $payload['items'][0]['lab_service_id'], 'quantity' => 3, 'unit_price' => 250000]];

    $this->actingAs(superAdmin())->post(route('lab-orders.store'), $payload);

    expect((float) LabOrder::first()->items->first()->subtotal)->toBe(750000.0);
});

it('requires at least one item', function () {
    $this->actingAs(superAdmin())
        ->post(route('lab-orders.store'), labOrderPayload(['items' => []]))
        ->assertSessionHasErrors('items');
});

it('requires clinic, doctor, patient and due date', function () {
    $this->actingAs(superAdmin())
        ->post(route('lab-orders.store'), labOrderPayload([
            'clinic_id' => null, 'doctor_id' => null, 'patient_id' => null, 'due_date' => null,
        ]))
        ->assertSessionHasErrors(['clinic_id', 'doctor_id', 'patient_id', 'due_date']);
});

it('validates item quantity and unit price', function () {
    $payload = labOrderPayload();
    $payload['items'] = [['lab_service_id' => $payload['items'][0]['lab_service_id'], 'quantity' => 0, 'unit_price' => -5]];

    $this->actingAs(superAdmin())
        ->post(route('lab-orders.store'), $payload)
        ->assertSessionHasErrors(['items.0.quantity', 'items.0.unit_price']);
});

it('updates a lab order', function () {
    $service = app(LabOrderService::class);
    $payload = labOrderPayload();
    $order = $service->create($payload, superAdmin());

    $update = $payload;
    $update['notes'] = 'Updated notes';

    $this->actingAs(superAdmin())
        ->put(route('lab-orders.update', $order), $update)
        ->assertRedirect(route('lab-orders.show', $order));

    expect($order->refresh()->notes)->toBe('Updated notes');
    expect(AuditLog::where('entity_id', $order->id)->where('action', 'UPDATE')->exists())->toBeTrue();
});

it('shows a lab order detail page', function () {
    $order = app(LabOrderService::class)->create(labOrderPayload(), superAdmin());

    $this->actingAs(userWith(['view_lab_orders']))
        ->get(route('lab-orders.show', $order))
        ->assertOk()
        ->assertSee($order->order_number);
});

it('renders the edit page for an editable order', function () {
    $order = app(LabOrderService::class)->create(labOrderPayload(), superAdmin());

    $this->actingAs(userWith(['update_lab_orders']))
        ->get(route('lab-orders.edit', $order))
        ->assertOk();
});

it('forbids editing a cancelled order', function () {
    $order = LabOrder::factory()->cancelled()->create();

    $this->actingAs(userWith(['update_lab_orders']))
        ->get(route('lab-orders.edit', $order))
        ->assertForbidden();
});

it('soft deletes a lab order via the service (no hard delete)', function () {
    $order = app(LabOrderService::class)->create(labOrderPayload(), superAdmin());

    app(LabOrderService::class)->delete($order);

    expect(LabOrder::find($order->id))->toBeNull();
    expect(LabOrder::withTrashed()->find($order->id))->not->toBeNull();
});

it('denies create without the create permission', function () {
    $this->actingAs(userWith(['view_lab_orders']))
        ->get(route('lab-orders.create'))
        ->assertForbidden();
});

it('denies list access without any lab order permission', function () {
    $this->actingAs(userWith(['manage users']))
        ->get(route('lab-orders.index'))
        ->assertForbidden();
});

it('redirects guests to login', function () {
    $this->get(route('lab-orders.index'))->assertRedirect(route('login'));
});
