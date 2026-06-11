<?php

beforeEach(function () {
    seedAccessControl();
});

it('renders the owner dashboard sections for the Owner role', function () {
    $this->actingAs(userInRole('Owner'))
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Dasbor Owner')
        ->assertSee('Kartu KPI Eksekutif')
        ->assertSee('Pendapatan Bulan Ini')
        ->assertSee('Pipeline Operasional')
        ->assertSee('Pusat Peringatan')
        ->assertSee('Performa Cabang')
        ->assertSee('Timeline Aktivitas Terbaru');
});

it('renders the owner dashboard for legacy manage_report users', function () {
    $this->actingAs(userWith(['view dashboard', 'manage_report']))
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Dasbor Owner');
});

it('shows safe empty states when owner dashboard data is unavailable', function () {
    $this->actingAs(userInRole('Owner'))
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Tidak ada peringatan mendesak')
        ->assertSee('Belum ada data performa cabang')
        ->assertSee('Belum ada aktivitas terbaru');
});
