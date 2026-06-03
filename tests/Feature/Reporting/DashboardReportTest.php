<?php

beforeEach(function () {
    seedAccessControl();
});

it('lets an authorized user view the reports dashboard', function () {
    $this->actingAs(userWith(['view_dashboard']))
        ->get(route('reports.dashboard'))
        ->assertOk()
        ->assertViewIs('reports.dashboard')
        ->assertSee('Total Orders');
});

it('handles an empty dataset on the dashboard', function () {
    $this->actingAs(userWith(['manage_report']))
        ->get(route('reports.dashboard'))
        ->assertOk()
        ->assertSee('Revenue');
});

it('denies the dashboard to users without permission', function () {
    $this->actingAs(userWith(['manage users']))
        ->get(route('reports.dashboard'))
        ->assertForbidden();
});

it('redirects guests from the dashboard to login', function () {
    $this->get(route('reports.dashboard'))->assertRedirect(route('login'));
});

it('accepts dashboard date filters', function () {
    $this->actingAs(superAdmin())
        ->get(route('reports.dashboard', ['date_from' => '2026-01-01', 'date_to' => '2026-12-31']))
        ->assertOk();
});

it('rejects an invalid dashboard date range', function () {
    $this->actingAs(superAdmin())
        ->get(route('reports.dashboard', ['date_from' => '2026-12-31', 'date_to' => '2026-01-01']))
        ->assertSessionHasErrors('date_to');
});
