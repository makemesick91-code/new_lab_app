<?php

beforeEach(function () {
    seedAccessControl();
});

it('renders the branch admin dashboard for an operational branch user', function () {
    $this->actingAs(userWith([
        'view_lab_orders',
        'view_production',
        'view_quality_control',
        'view_delivery',
        'view_inventory',
        'view_invoice',
    ]))
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Branch Admin Dashboard')
        ->assertSee('Daily Summary')
        ->assertSee('Work Queue Board')
        ->assertSee('Production Queue')
        ->assertSee('QC Queue')
        ->assertSee('Delivery Queue')
        ->assertSee('Inventory Alerts')
        ->assertSee('Finance Alerts');
});

it('shows safe branch admin empty states when dashboard data is unavailable', function () {
    $this->actingAs(userWith(['view_lab_orders', 'view_production']))
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('No new orders today.')
        ->assertSee('All new orders are assigned.')
        ->assertSee('No urgent branch alerts');
});
