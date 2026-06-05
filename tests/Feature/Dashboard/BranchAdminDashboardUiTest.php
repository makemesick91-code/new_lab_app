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
        ->assertSee('Dasbor Admin Cabang')
        ->assertSee('Ringkasan Harian')
        ->assertSee('Papan Antrean Kerja')
        ->assertSee('Antrean Produksi')
        ->assertSee('Antrean QC')
        ->assertSee('Antrean Pengiriman')
        ->assertSee('Peringatan Persediaan')
        ->assertSee('Peringatan Keuangan');
});

it('shows safe branch admin empty states when dashboard data is unavailable', function () {
    $this->actingAs(userWith(['view_lab_orders', 'view_production']))
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Belum ada order baru hari ini.')
        ->assertSee('Semua order baru sudah ditugaskan.')
        ->assertSee('Tidak ada peringatan cabang mendesak');
});
