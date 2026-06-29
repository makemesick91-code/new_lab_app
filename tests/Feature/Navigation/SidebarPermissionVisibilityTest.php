<?php

beforeEach(function () {
    seedAccessControl();
});

it('shows RME visit links but hides Kasir RME for Doctor', function () {
    $this->actingAs(userInRole('Doctor'))
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Dashboard RME')
        ->assertSee('Kunjungan')
        ->assertSee('Rekam Medis')
        ->assertDontSee('Kasir RME');
});

it('shows Kasir RME for Kasir and Admin Klinik but not Doctor', function () {
    $this->actingAs(userInRole('Kasir'))
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Kasir RME');

    $this->actingAs(userInRole('Admin Klinik'))
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Kasir RME');

    $this->actingAs(userInRole('Doctor'))
        ->get(route('dashboard'))
        ->assertOk()
        ->assertDontSee('Kasir RME');
});

it('shows read-only RME and reporting for Owner but hides lab operations', function () {
    $this->actingAs(userInRole('Owner'))
        ->get(route('dashboard'))
        ->assertOk()
        ->assertDontSee(route('lab-orders.index'))
        ->assertDontSee(route('lab-case-candidates.index'))
        ->assertSee(route('rme.visits.index'))
        ->assertDontSee('Kasir RME')
        ->assertSee('Laporan')
        ->assertSee(route('reports.dashboard'));
});

it('shows lab workflow menus for Admin Lab but not Kasir', function () {
    $this->actingAs(userInRole('Admin Lab'))
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Order Lab')
        ->assertSee('Kandidat Lab RME');

    $this->actingAs(userInRole('Kasir'))
        ->get(route('dashboard'))
        ->assertOk()
        ->assertDontSee('Order Lab')
        ->assertDontSee('Kandidat Lab RME');
});

it('shows clinic visit menu for Perawat without cashier or settings', function () {
    $this->actingAs(userInRole('Perawat'))
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Dashboard RME')
        ->assertSee('Kunjungan')
        ->assertDontSee('Kasir RME')
        ->assertDontSee('Pengaturan');
});

it('shows production menu for Technician but not RME group', function () {
    $this->actingAs(userInRole('Technician'))
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Produksi')
        ->assertDontSee('Dashboard RME');
});

it('shows Master Cabang RME for Owner but hides it from Courier', function () {
    $this->actingAs(userInRole('Owner'))
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Master Cabang RME');

    $this->actingAs(userInRole('Courier'))
        ->get(route('dashboard'))
        ->assertOk()
        ->assertDontSee('Master Cabang RME');
});
