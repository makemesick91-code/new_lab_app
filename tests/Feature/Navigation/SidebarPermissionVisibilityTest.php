<?php

use App\Modules\Branch\Models\Branch;

beforeEach(function () {
    seedAccessControl();
});

it('shows doctor-focused RME navigation but hides admin queue links and Kasir RME for Doctor', function () {
    $doctor = doctorWithOnlineContext();

    $this->actingAs($doctor)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Dashboard RME')
        ->assertSee('Rekam Medis')
        ->assertSee('Ruang Perawatan')
        ->assertSee('Buka Ruang Perawatan')
        ->assertSee(route('rme.treatment-room-worklist.index'))
        ->assertDontSee('Dasbor RME')
        ->assertDontSee('Antrian Pasien')
        ->assertDontSee('Buka Kunjungan')
        ->assertDontSee(route('rme.visits.index'))
        ->assertDontSee(route('rme.dashboard'))
        ->assertDontSee(route('rme.patient-queue.index'))
        ->assertDontSee('Kasir RME');
});

it('shows Kasir RME for Kasir but not Doctor or Admin Klinik', function () {
    $this->actingAs(userInRole('Kasir'))
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Kasir RME');

    $this->actingAs(doctorWithOnlineContext())
        ->get(route('dashboard'))
        ->assertOk()
        ->assertDontSee('Kasir RME');
});

it('hides cashier, audit, and master-data shortcuts for Admin Klinik while keeping operational menus', function () {
    $adminBranch = Branch::factory()->create(['is_active' => true, 'is_rme_enabled' => true]);
    $admin = userInRole('Admin Klinik');
    rmeMakeAdminClinicActive($admin, $adminBranch);

    $this->actingAs($admin)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Dashboard RME')
        ->assertSee('Kunjungan')
        ->assertSee('Antrian Pasien')
        ->assertSee('Rekam Medis')
        ->assertSee('Buka Kunjungan')
        ->assertDontSee('Sinkronisasi Dokter–Kasir')
        ->assertDontSee('Kasir RME')
        ->assertDontSee('Piutang RME')
        ->assertDontSee('Audit Data Pasien')
        ->assertDontSee('Master Data RME')
        ->assertDontSee('Buka Kasir RME');
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
