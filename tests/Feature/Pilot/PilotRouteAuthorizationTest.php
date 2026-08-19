<?php

use App\Modules\Branch\Models\Branch;
use Database\Seeders\BranchSeeder;

beforeEach(function () {
    seedAccessControl();
    test()->seed(BranchSeeder::class);
    $this->branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
});

it('allows Owner to open dashboard and reports but not lab order creation', function () {
    $owner = userInRole('Owner');

    $this->actingAs($owner)->get(route('dashboard'))->assertOk();
    $this->actingAs($owner)->get(route('reports.dashboard'))->assertOk();
    $this->actingAs($owner)->get(route('lab-orders.create'))->assertForbidden();
    $this->actingAs($owner)->get(route('rme.cashier.index'))->assertForbidden();
});

it('allows Kasir to access cashier routes but not visit creation', function () {
    $kasir = userInRole('Kasir');
    rmeMakeKasirActive($kasir, Branch::factory()->create(['is_active' => true, 'is_rme_enabled' => true]));

    $this->actingAs($kasir)->get(route('rme.cashier.index'))->assertOk();
    $this->actingAs($kasir)->get(route('rme.visits.index'))->assertOk();
    $this->actingAs($kasir)->get(route('rme.visits.create'))->assertForbidden();
});

it('allows Doctor clinical routes but denies cashier billing', function () {
    $doctor = doctorWithOnlineContext();

    $this->actingAs($doctor)->get(route('rme.visits.index'))->assertOk();
    $this->actingAs($doctor)->get(route('rme.medical-records.index'))->assertOk();
    $this->actingAs($doctor)->get(route('rme.cashier.index'))->assertForbidden();
});

it('allows Perawat visit queue but denies cashier and lab candidates', function () {
    $rmeBranch = Branch::factory()->create(['is_active' => true, 'is_rme_enabled' => true]);
    $perawat = userInRole('Perawat');
    rmeMakePerawatActive($perawat, $rmeBranch);

    $this->actingAs($perawat)->get(route('rme.visits.index'))->assertOk();
    $this->actingAs($perawat)->get(route('rme.visits.create'))->assertOk();
    $this->actingAs($perawat)->get(route('rme.cashier.index'))->assertForbidden();
    $this->actingAs($perawat)->get(route('lab-case-candidates.index'))->assertForbidden();
});

it('allows Admin Lab lab candidate queue and denies users without lab permissions', function () {
    $adminLab = userInRole('Admin Lab');
    $kasir = userInRole('Kasir');
    rmeMakeKasirActive($kasir, Branch::factory()->create(['is_active' => true, 'is_rme_enabled' => true]));

    $this->actingAs($adminLab)->get(route('lab-case-candidates.index'))->assertOk();
    $this->actingAs($kasir)->get(route('lab-case-candidates.index'))->assertForbidden();
});

it('denies dashboard access to authenticated users without dashboard permissions', function () {
    $user = userWith([]);

    $this->actingAs($user)->get(route('dashboard'))->assertForbidden();
});
