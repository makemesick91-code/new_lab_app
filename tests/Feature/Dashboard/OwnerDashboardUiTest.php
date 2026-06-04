<?php

beforeEach(function () {
    seedAccessControl();
});

it('renders the owner dashboard sections for an authenticated user', function () {
    $this->actingAs(userWith(['manage_report', 'view_inventory']))
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Owner Dashboard')
        ->assertSee('Executive KPI Cards')
        ->assertSee('Revenue This Month')
        ->assertSee('Operational Pipeline')
        ->assertSee('Alert Center')
        ->assertSee('Branch Performance')
        ->assertSee('Recent Activity Timeline');
});

it('shows safe empty states when owner dashboard data is unavailable', function () {
    $this->actingAs(userWith(['manage_report']))
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('No urgent alerts')
        ->assertSee('No branch performance data')
        ->assertSee('No recent activity');
});
