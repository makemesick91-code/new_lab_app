<?php

use App\Modules\Clinic\Models\Clinic;
use App\Modules\Delivery\Models\Delivery;
use App\Modules\Invoice\Models\Invoice;
use App\Modules\Invoice\Models\Payment;
use App\Modules\LabOrder\Models\LabOrder;

beforeEach(function () {
    seedAccessControl();
});

it('filters the order report by status', function () {
    $received = LabOrder::factory()->create(['status' => 'RECEIVED']);
    $cancelled = LabOrder::factory()->create(['status' => 'CANCELLED']);

    $this->actingAs(superAdmin())
        ->get(route('reports.orders', ['status' => 'RECEIVED']))
        ->assertOk()
        ->assertSee($received->order_number)
        ->assertDontSee($cancelled->order_number);
});

it('filters the invoice report by clinic', function () {
    $clinicA = Clinic::factory()->create();
    $clinicB = Clinic::factory()->create();
    $a = Invoice::factory()->create(['status' => 'ISSUED', 'clinic_id' => $clinicA->id]);
    $b = Invoice::factory()->create(['status' => 'ISSUED', 'clinic_id' => $clinicB->id]);

    $this->actingAs(superAdmin())
        ->get(route('reports.invoices', ['clinic_id' => $clinicA->id]))
        ->assertOk()
        ->assertSee($a->invoice_number)
        ->assertDontSee($b->invoice_number);
});

it('shows the payment report filtered by method', function () {
    $cash = Payment::factory()->create(['payment_method' => 'CASH']);
    $qris = Payment::factory()->create(['payment_method' => 'QRIS']);

    $this->actingAs(superAdmin())
        ->get(route('reports.payments', ['payment_method' => 'CASH']))
        ->assertOk()
        ->assertSee($cash->payment_number)
        ->assertDontSee($qris->payment_number);
});

it('shows the qc report using QC tables', function () {
    $order = qcPendingOrder();
    startQcReview($order);

    $this->actingAs(superAdmin())
        ->get(route('reports.qc'))
        ->assertOk()
        ->assertSee($order->order_number);
});

it('shows the delivery report using delivery tables', function () {
    $delivery = Delivery::factory()->create();

    $this->actingAs(superAdmin())
        ->get(route('reports.delivery'))
        ->assertOk()
        ->assertSee($delivery->delivery_number);
});

it('shows outstanding invoices on the outstanding report', function () {
    $invoice = Invoice::factory()->create(['status' => 'ISSUED', 'total_amount' => 1000, 'paid_amount' => 0, 'outstanding_amount' => 1000]);

    $this->actingAs(superAdmin())
        ->get(route('reports.outstanding'))
        ->assertOk()
        ->assertSee($invoice->invoice_number);
});

it('accepts production report filters', function () {
    $this->actingAs(superAdmin())
        ->get(route('reports.production', ['status' => 'ASSIGNED']))
        ->assertOk();
});

it('rejects an invalid order status filter', function () {
    $this->actingAs(superAdmin())
        ->get(route('reports.orders', ['status' => 'NOT_A_STATUS']))
        ->assertSessionHasErrors('status');
});
