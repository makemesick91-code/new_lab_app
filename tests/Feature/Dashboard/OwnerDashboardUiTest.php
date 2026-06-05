<?php

beforeEach(function () {
    seedAccessControl();
});

it('renders the owner dashboard sections for an authenticated user', function () {
    $this->actingAs(userWith(['manage_report']))
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

it('shows safe empty states when owner dashboard data is unavailable', function () {
    $this->actingAs(userWith(['manage_report']))
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Tidak ada peringatan mendesak')
        ->assertSee('Belum ada data performa cabang')
        ->assertSee('Belum ada aktivitas terbaru');
});
