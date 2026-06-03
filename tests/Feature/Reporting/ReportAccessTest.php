<?php

beforeEach(function () {
    seedAccessControl();
});

dataset('reports', [
    'orders' => ['reports.orders', 'view_order_report'],
    'production' => ['reports.production', 'view_production_report'],
    'qc' => ['reports.qc', 'view_qc_report'],
    'delivery' => ['reports.delivery', 'view_delivery_report'],
    'invoices' => ['reports.invoices', 'view_invoice_report'],
    'payments' => ['reports.payments', 'view_payment_report'],
    'outstanding' => ['reports.outstanding', 'view_invoice_report'],
    'revenue' => ['reports.revenue', 'view_invoice_report'],
]);

it('allows an authorized user to view each report', function (string $route, string $permission) {
    $this->actingAs(userWith([$permission]))
        ->get(route($route))
        ->assertOk();
})->with('reports');

it('denies each report without the required permission', function (string $route) {
    $this->actingAs(userWith(['manage users']))
        ->get(route($route))
        ->assertForbidden();
})->with('reports');

it('redirects guests from each report to login', function (string $route) {
    $this->get(route($route))->assertRedirect(route('login'));
})->with('reports');

it('grants manage_report access to every report', function (string $route) {
    $this->actingAs(userWith(['manage_report']))
        ->get(route($route))
        ->assertOk();
})->with('reports');
