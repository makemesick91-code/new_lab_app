<?php

use App\Modules\Branch\Models\Branch;
use App\Modules\Delivery\Models\Delivery;
use App\Modules\Invoice\Models\Invoice;
use App\Modules\Invoice\Models\Payment;
use App\Modules\LabOrder\Models\LabOrder;

it('relates a lab order to its branch', function () {
    $branch = Branch::factory()->create();
    $order = LabOrder::factory()->create(['branch_id' => $branch->id]);

    expect($order->branch)->toBeInstanceOf(Branch::class)
        ->and($order->branch->id)->toBe($branch->id);
});

it('relates a delivery to its branch', function () {
    $branch = Branch::factory()->create();
    $delivery = Delivery::factory()->create(['branch_id' => $branch->id]);

    expect($delivery->branch)->toBeInstanceOf(Branch::class)
        ->and($delivery->branch->id)->toBe($branch->id);
});

it('relates an invoice to its branch', function () {
    $branch = Branch::factory()->create();
    $invoice = Invoice::factory()->create(['branch_id' => $branch->id]);

    expect($invoice->branch)->toBeInstanceOf(Branch::class)
        ->and($invoice->branch->id)->toBe($branch->id);
});

it('relates a payment to its branch', function () {
    $branch = Branch::factory()->create();
    $payment = Payment::factory()->create(['branch_id' => $branch->id]);

    expect($payment->branch)->toBeInstanceOf(Branch::class)
        ->and($payment->branch->id)->toBe($branch->id);
});

it('leaves branch null when none is assigned (no enforcement yet)', function () {
    $order = LabOrder::factory()->create();

    expect($order->branch_id)->toBeNull()
        ->and($order->branch)->toBeNull();
});

it('exposes the inverse has-many relations from a branch', function () {
    $branch = Branch::factory()->create();

    LabOrder::factory()->create(['branch_id' => $branch->id]);
    Delivery::factory()->create(['branch_id' => $branch->id]);
    Invoice::factory()->create(['branch_id' => $branch->id]);
    Payment::factory()->create(['branch_id' => $branch->id]);

    expect($branch->labOrders)->toHaveCount(1)
        ->and($branch->deliveries)->toHaveCount(1)
        ->and($branch->invoices)->toHaveCount(1)
        ->and($branch->payments)->toHaveCount(1);
});
