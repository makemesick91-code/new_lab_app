<?php

use App\Models\User;

beforeEach(function () {
    seedAccessControl();
});

function userInRole(string $role): User
{
    return User::factory()->create()->assignRole($role);
}

it('grants Admin Lab access to every report', function () {
    $admin = userInRole('Admin Lab');

    foreach (['reports.dashboard', 'reports.orders', 'reports.production', 'reports.qc', 'reports.delivery', 'reports.invoices', 'reports.payments', 'reports.outstanding', 'reports.revenue'] as $route) {
        $this->actingAs($admin)->get(route($route))->assertOk();
    }
});

it('grants Finance access to financial reports but not order reports', function () {
    $finance = userInRole('Finance');

    $this->actingAs($finance)->get(route('reports.dashboard'))->assertOk();
    $this->actingAs($finance)->get(route('reports.invoices'))->assertOk();
    $this->actingAs($finance)->get(route('reports.payments'))->assertOk();
    $this->actingAs($finance)->get(route('reports.revenue'))->assertOk();
    $this->actingAs($finance)->get(route('reports.orders'))->assertForbidden();
});

it('grants a Technician (lab staff) limited operational report access', function () {
    $technician = userInRole('Technician');

    $this->actingAs($technician)->get(route('reports.production'))->assertOk();
    $this->actingAs($technician)->get(route('reports.invoices'))->assertForbidden();
});

it('denies all reporting access to a Courier', function () {
    $courier = userInRole('Courier');

    $this->actingAs($courier)->get(route('reports.dashboard'))->assertForbidden();
    $this->actingAs($courier)->get(route('reports.orders'))->assertForbidden();
    $this->actingAs($courier)->get(route('reports.invoices'))->assertForbidden();
});

it('lets Finance export but a Courier cannot', function () {
    $this->actingAs(userInRole('Finance'))->get(route('reports.invoices.export'))->assertOk();
    $this->actingAs(userInRole('Courier'))->get(route('reports.invoices.export'))->assertForbidden();
});
