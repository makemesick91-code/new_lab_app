<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| DMS-RME-COMPREHENSIVE-CLOSURE-1 PR8 (memo M2.5 / MEMO-F2) — payment totals
|--------------------------------------------------------------------------
|
| Owner decision 2026-11-17 ("Hide payment totals"): the RME dashboard is open
| to every holder of `view_clinic_visits`, clinicians included, but its two
| payment cards — "Piutang RME Aktif" and "Pembayaran Hari Ini" — are payment
| information. They are computed and shown only for an account that holds
| `manage_rme_billing` or `view_rme_payment_reports` (effective permissions,
| CG-06). For any other account the figures are never queried, so they cannot
| leak through the view data either.
|
| Selected by the existing `RmePayment` critical-gate token in both runner
| variants and declared in critical_gate_mandatory_suites (CG-19). Helper prefix
| `rpdv` is unique because the critical gate loads every suite into one process.
*/

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use App\Modules\RmeInvoice\Models\RmePayment;
use Database\Seeders\BranchSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    test()->seed(BranchSeeder::class);
    seedAccessControl();
    pinTestClock('2026-11-17 02:00:00'); // clinic 10:00 WITA

    $this->branch = Branch::factory()->create([
        'code' => 'PDV1', 'is_active' => true, 'is_rme_enabled' => true,
    ]);

    $invoice = RmeInvoice::factory()->unpaid()->create([
        'branch_id' => $this->branch->id,
        'grand_total' => 200000,
    ]);
    RmePayment::factory()->create([
        'branch_id' => $this->branch->id,
        'rme_invoice_id' => $invoice->id,
        'amount' => 73500,
        'paid_at' => now(),
    ]);
});

function rpdvDashboard(object $t, User $user)
{
    return $t->actingAs($user)->get(route('rme.dashboard'))->assertOk();
}

function rpdvDoctor(object $t): User
{
    return rmeMakeDoctorOnline(Doctor::factory()->create(), $t->branch);
}

function rpdvPerawat(object $t): User
{
    $user = userInRole('Perawat');
    rmeMakePerawatActive($user, $t->branch);

    return $user;
}

it('PDV-01 hides both payment cards and their values from a Doctor', function () {
    $response = rpdvDashboard($this, rpdvDoctor($this));

    $response->assertDontSee('Piutang RME Aktif')
        ->assertDontSee('Pembayaran Hari Ini')
        ->assertDontSee('Rp 73.500', false);

    $metrics = $response->viewData('metrics');
    expect($metrics)->not->toHaveKey('payments_today_total')
        ->and($metrics)->not->toHaveKey('active_receivables')
        // The clinical cards stay.
        ->and($metrics)->toHaveKey('visits_today');
});

it('PDV-02 hides both payment cards and their values from a Perawat', function () {
    $response = rpdvDashboard($this, rpdvPerawat($this));

    $response->assertDontSee('Piutang RME Aktif')
        ->assertDontSee('Pembayaran Hari Ini')
        ->assertDontSee('Rp 73.500', false);

    expect($response->viewData('metrics'))->not->toHaveKey('payments_today_total');
});

it('PDV-03 never queries payments or receivables for an account without payment authority', function () {
    $doctor = rpdvDoctor($this);

    $statements = [];
    DB::listen(function (QueryExecuted $q) use (&$statements): void {
        $statements[] = strtolower($q->sql);
    });

    rpdvDashboard($this, $doctor);

    $paymentReads = collect($statements)->filter(fn (string $s) => str_contains($s, 'trx_rme_payments') || str_contains($s, 'trx_rme_invoices'));

    expect($paymentReads)->toBeEmpty();
});

it('PDV-04 shows both cards and today\'s total to a Kasir', function () {
    $kasir = userInRole('Kasir');
    rmeMakeKasirActive($kasir, $this->branch);

    rpdvDashboard($this, $kasir)
        ->assertSee('Piutang RME Aktif')
        ->assertSee('Pembayaran Hari Ini')
        ->assertSee('Rp 73.500', false);
});

it('PDV-05 shows both cards to Front Office and to the Owner', function () {
    $frontOffice = User::factory()->create();
    rmeMakeFrontOfficeActive($frontOffice, $this->branch);

    rpdvDashboard($this, $frontOffice)
        ->assertSee('Pembayaran Hari Ini')
        ->assertSee('Rp 73.500', false);

    rpdvDashboard($this, userInRole('Owner'))
        ->assertSee('Piutang RME Aktif')
        ->assertSee('Rp 73.500', false);
});

it('PDV-06 decides on either payment permission alone, never on the role name', function () {
    $reportsOnly = userWith(['view_clinic_visits', 'view_rme_payment_reports']);
    $billingOnly = userWith(['view_clinic_visits', 'manage_rme_billing']);
    $neither = userWith(['view_clinic_visits']);

    rpdvDashboard($this, $reportsOnly)->assertSee('Pembayaran Hari Ini');
    rpdvDashboard($this, $billingOnly)->assertSee('Pembayaran Hari Ini');
    rpdvDashboard($this, $neither)->assertDontSee('Pembayaran Hari Ini')->assertDontSee('Piutang RME Aktif');
});
