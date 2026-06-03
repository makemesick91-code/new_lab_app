<?php

use App\Modules\LabOrder\Models\LabOrder;

beforeEach(function () {
    seedAccessControl();
});

dataset('exports', [
    'orders' => ['reports.orders.export', 'view_order_report'],
    'production' => ['reports.production.export', 'view_production_report'],
    'qc' => ['reports.qc.export', 'view_qc_report'],
    'delivery' => ['reports.delivery.export', 'view_delivery_report'],
    'invoices' => ['reports.invoices.export', 'view_invoice_report'],
    'payments' => ['reports.payments.export', 'view_payment_report'],
    'outstanding' => ['reports.outstanding.export', 'view_invoice_report'],
    'revenue' => ['reports.revenue.export', 'view_invoice_report'],
]);

it('streams a CSV download for each export', function (string $route, string $permission) {
    $response = $this->actingAs(userWith([$permission, 'export_report']))->get(route($route));

    $response->assertOk();
    expect($response->headers->get('content-disposition'))->toContain('attachment');
})->with('exports');

it('denies export without the export_report permission', function (string $route, string $permission) {
    $this->actingAs(userWith([$permission]))
        ->get(route($route))
        ->assertForbidden();
})->with('exports');

it('denies export without the report permission', function () {
    // Has export_report but not the order report permission.
    $this->actingAs(userWith(['export_report', 'view_payment_report']))
        ->get(route('reports.orders.export'))
        ->assertForbidden();
});

it('respects filters in the export output', function () {
    $received = LabOrder::factory()->create(['status' => 'RECEIVED']);
    $cancelled = LabOrder::factory()->create(['status' => 'CANCELLED']);

    $response = $this->actingAs(userWith(['view_order_report', 'export_report']))
        ->get(route('reports.orders.export', ['status' => 'RECEIVED']));

    $response->assertOk();
    $content = $response->streamedContent();
    expect($content)->toContain($received->order_number);
    expect($content)->not->toContain($cancelled->order_number);
});

it('includes the CSV header row', function () {
    $response = $this->actingAs(superAdmin())->get(route('reports.orders.export'));

    expect($response->streamedContent())->toContain('Order Number');
});
