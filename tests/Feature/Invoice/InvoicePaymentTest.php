<?php

use App\Models\User;
use App\Modules\Clinic\Models\Clinic;
use App\Modules\Invoice\Models\Invoice;
use App\Modules\Invoice\Models\Payment;
use App\Modules\Invoice\Services\InvoiceService;
use App\Modules\Invoice\Services\InvoiceWorkflowService;
use App\Modules\LabOrder\Models\AuditLog;
use App\Modules\LabOrder\Models\LabOrder;
use App\Modules\LabService\Models\LabService;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    seedAccessControl();
});

function completedInvoiceOrder(?Clinic $clinic = null, float $amount = 1000000): LabOrder
{
    $clinic = $clinic ?? Clinic::factory()->create();
    $order = LabOrder::factory()->create([
        'clinic_id' => $clinic->id,
        'status' => LabOrder::STATUS_COMPLETED,
    ]);

    $order->items()->create([
        'lab_service_id' => LabService::factory()->create()->id,
        'quantity' => 1,
        'unit_price' => $amount,
        'subtotal' => $amount,
        'notes' => 'Invoice test item',
    ]);

    return $order->refresh();
}

function draftInvoiceForOrder(LabOrder $order): Invoice
{
    return app(InvoiceService::class)->create([
        'clinic_id' => $order->clinic_id,
        'invoice_date' => now()->toDateString(),
        'due_date' => now()->addDays(14)->toDateString(),
        'lab_order_ids' => [$order->id],
    ], superAdmin());
}

function issuedInvoiceForOrder(LabOrder $order): Invoice
{
    $invoice = draftInvoiceForOrder($order);

    return app(InvoiceWorkflowService::class)->issue($invoice, 'issue from test', superAdmin());
}

it('shows invoice list to authorized users', function () {
    $this->actingAs(userWith(['view_invoice']))
        ->get(route('invoices.index'))
        ->assertOk()
        ->assertSee('Invoices');
});

it('creates an invoice from completed lab orders', function () {
    $clinic = Clinic::factory()->create();
    $first = completedInvoiceOrder($clinic, 1200000);
    $second = completedInvoiceOrder($clinic, 800000);

    $this->actingAs(userWith(['create_invoice', 'view_invoice']))
        ->post(route('invoices.store'), [
            'clinic_id' => $clinic->id,
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(14)->toDateString(),
            'lab_order_ids' => [$first->id, $second->id],
        ])
        ->assertRedirect();

    $invoice = Invoice::with('items')->first();

    expect($invoice)->not->toBeNull()
        ->and($invoice->items)->toHaveCount(2)
        ->and((float) $invoice->total_amount)->toBe(2000000.0)
        ->and($invoice->status)->toBe(Invoice::STATUS_DRAFT)
        ->and(AuditLog::where('action', AuditLog::ACTION_INVOICE_CREATED)->exists())->toBeTrue();
});

it('rejects non-completed lab orders and mixed clinics', function () {
    $clinic = Clinic::factory()->create();
    $completed = completedInvoiceOrder($clinic);
    $received = LabOrder::factory()->create(['clinic_id' => $clinic->id, 'status' => LabOrder::STATUS_RECEIVED]);

    $this->actingAs(userWith(['create_invoice']))
        ->post(route('invoices.store'), [
            'clinic_id' => $clinic->id,
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(14)->toDateString(),
            'lab_order_ids' => [$completed->id, $received->id],
        ])
        ->assertSessionHasErrors('lab_order_ids');

    $otherClinicOrder = completedInvoiceOrder();

    $this->actingAs(userWith(['create_invoice']))
        ->post(route('invoices.store'), [
            'clinic_id' => $clinic->id,
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(14)->toDateString(),
            'lab_order_ids' => [$completed->id, $otherClinicOrder->id],
        ])
        ->assertSessionHasErrors('clinic_id');
});

it('prevents duplicate active invoicing for one lab order', function () {
    $order = completedInvoiceOrder();
    draftInvoiceForOrder($order);

    $this->actingAs(userWith(['create_invoice']))
        ->post(route('invoices.store'), [
            'clinic_id' => $order->clinic_id,
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(14)->toDateString(),
            'lab_order_ids' => [$order->id],
        ])
        ->assertSessionHasErrors('lab_order_ids');
});

it('issues and voids unpaid invoices', function () {
    $invoice = draftInvoiceForOrder(completedInvoiceOrder());

    $this->actingAs(userWith(['issue_invoice', 'view_invoice']))
        ->post(route('invoices.issue', $invoice), ['notes' => 'ready'])
        ->assertRedirect(route('invoices.show', $invoice));

    expect($invoice->refresh()->status)->toBe(Invoice::STATUS_ISSUED)
        ->and(AuditLog::where('action', AuditLog::ACTION_INVOICE_ISSUED)->exists())->toBeTrue();

    $this->actingAs(userWith(['void_invoice', 'view_invoice']))
        ->post(route('invoices.void', $invoice->refresh()), ['notes' => 'cancel billing'])
        ->assertRedirect(route('invoices.show', $invoice));

    expect($invoice->refresh()->status)->toBe(Invoice::STATUS_VOID)
        ->and(AuditLog::where('action', AuditLog::ACTION_INVOICE_VOIDED)->exists())->toBeTrue();
});

it('records partial and full payments and blocks overpayment', function () {
    $invoice = issuedInvoiceForOrder(completedInvoiceOrder(amount: 1000000));
    $finance = userWith(['create_payment', 'view_invoice']);

    $this->actingAs($finance)
        ->post(route('invoices.payments.store', $invoice), [
            'payment_date' => now()->toDateString(),
            'payment_method' => Payment::METHOD_CASH,
            'amount' => 400000,
        ])
        ->assertRedirect(route('invoices.show', $invoice));

    expect($invoice->refresh()->status)->toBe(Invoice::STATUS_PARTIALLY_PAID)
        ->and((float) $invoice->paid_amount)->toBe(400000.0)
        ->and((float) $invoice->outstanding_amount)->toBe(600000.0);

    $this->actingAs($finance)
        ->post(route('invoices.payments.store', $invoice), [
            'payment_date' => now()->toDateString(),
            'payment_method' => Payment::METHOD_QRIS,
            'amount' => 700000,
        ])
        ->assertSessionHasErrors('amount');

    $this->actingAs($finance)
        ->post(route('invoices.payments.store', $invoice), [
            'payment_date' => now()->toDateString(),
            'payment_method' => Payment::METHOD_BANK_TRANSFER,
            'amount' => 600000,
        ])
        ->assertRedirect(route('invoices.show', $invoice));

    expect($invoice->refresh()->status)->toBe(Invoice::STATUS_PAID)
        ->and((float) $invoice->outstanding_amount)->toBe(0.0)
        ->and(AuditLog::where('action', AuditLog::ACTION_PAYMENT_RECORDED)->exists())->toBeTrue()
        ->and(AuditLog::where('action', AuditLog::ACTION_INVOICE_PAID)->exists())->toBeTrue();
});

it('blocks payment on void invoice and void after payment exists', function () {
    $invoice = issuedInvoiceForOrder(completedInvoiceOrder());
    app(InvoiceWorkflowService::class)->void($invoice, 'void before payment', superAdmin());

    $this->actingAs(userWith(['create_payment']))
        ->post(route('invoices.payments.store', $invoice->refresh()), [
            'payment_date' => now()->toDateString(),
            'payment_method' => Payment::METHOD_CARD,
            'amount' => 100000,
        ])
        ->assertSessionHasErrors('invoice_id');

    $paidInvoice = issuedInvoiceForOrder(completedInvoiceOrder(amount: 1000000));

    $this->actingAs(userWith(['create_payment']))
        ->post(route('invoices.payments.store', $paidInvoice), [
            'payment_date' => now()->toDateString(),
            'payment_method' => Payment::METHOD_CASH,
            'amount' => 100000,
        ]);

    $this->actingAs(userWith(['void_invoice']))
        ->post(route('invoices.void', $paidInvoice->refresh()), ['notes' => 'try void'])
        ->assertSessionHasErrors('status');
});

it('enforces invoice and payment authorization rules', function () {
    $invoice = issuedInvoiceForOrder(completedInvoiceOrder());

    $this->actingAs(userWith([]))
        ->get(route('invoices.index'))
        ->assertForbidden();

    $finance = User::factory()->create()->assignRole('Finance');

    $this->actingAs($finance)
        ->get(route('invoices.index'))
        ->assertOk();

    $courier = User::factory()->create()->assignRole('Courier');

    $this->actingAs($courier)
        ->get(route('invoices.index'))
        ->assertForbidden();

    $this->actingAs(userWith(['view_invoice']))
        ->post(route('invoices.payments.store', $invoice), [
            'payment_date' => now()->toDateString(),
            'payment_method' => Payment::METHOD_CASH,
            'amount' => 100000,
        ])
        ->assertForbidden();
});

it('seeds sprint 7 permissions and assigns finance permissions', function () {
    expect(Permission::where('name', 'manage_invoice')->exists())->toBeTrue()
        ->and(Permission::where('name', 'create_payment')->exists())->toBeTrue()
        ->and(Role::findByName('Finance')->hasPermissionTo('manage_invoice'))->toBeTrue()
        ->and(Role::findByName('Finance')->hasPermissionTo('create_payment'))->toBeTrue()
        ->and(Role::findByName('Courier')->hasPermissionTo('view_invoice'))->toBeFalse();
});
