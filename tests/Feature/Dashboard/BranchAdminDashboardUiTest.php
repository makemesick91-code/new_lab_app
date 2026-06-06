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
        ->assertSee('Dasbor')
        ->assertDontSee('>Dashboard<', false)
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

it('uses Indonesian sidebar labels for key modules', function () {
    $this->actingAs(userWith([
        'view_lab_orders',
        'view_inventory',
        'view_production',
        'view_delivery',
        'view_order_report',
        'manage users',
    ]))
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Dasbor')
        ->assertSee('Persediaan')
        ->assertSee('Produk')
        ->assertSee('Pemasok')
        ->assertSee('Lokasi Persediaan')
        ->assertSee('Produksi')
        ->assertSee('Pengiriman')
        ->assertSee('Pengaturan')
        ->assertSee('Laporan')
        ->assertDontSee('>Dashboard<', false)
        ->assertDontSee('>Inventory<', false)
        ->assertDontSee('>Production<', false)
        ->assertDontSee('>Delivery<', false)
        ->assertDontSee('>Settings<', false)
        ->assertDontSee('>Reports<', false);
});
