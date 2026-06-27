<?php

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Patient\Models\Patient;
use App\Modules\Reporting\Services\OwnerDashboardKpiService;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use App\Modules\RmeInvoice\Models\RmePayment;
use App\Modules\RmeInvoice\Models\RmeReceivableFollowUp;
use Database\Seeders\BranchSeeder;

beforeEach(function () {
    seedAccessControl();
    test()->seed(BranchSeeder::class);
    $this->branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $this->service = app(OwnerDashboardKpiService::class);
});

// ---------------------------------------------------------------------------
// Access & authorization
// ---------------------------------------------------------------------------

it('lets the Owner access the Owner KPI dashboard', function () {
    $this->actingAs(userInRole('Owner'))
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Dashboard KPI Owner');
});

it('forbids a user without dashboard permissions', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('dashboard'))
        ->assertForbidden();
});

it('does not load the Owner KPI block for Supervisor RME', function () {
    $this->actingAs(userInRole('Supervisor RME'))
        ->get(route('dashboard'))
        ->assertOk()
        ->assertDontSee('Dashboard KPI Owner');
});

// ---------------------------------------------------------------------------
// Rendering
// ---------------------------------------------------------------------------

it('renders the executive KPI labels for the Owner', function () {
    $response = $this->actingAs(userInRole('Owner'))->get(route('dashboard'))->assertOk();

    foreach ([
        'Total Kunjungan', 'Pasien Baru', 'Total Pendapatan', 'Piutang Aktif',
        'Invoice Belum Lunas', 'Follow-up Jatuh Tempo', 'Lab Order Aktif',
        'Low Stock', 'Nilai Stok', 'Tingkat Penagihan', 'Performa Cabang',
    ] as $label) {
        $response->assertSee($label);
    }
});

it('does not include HR content on the Owner KPI dashboard', function () {
    $this->actingAs(userInRole('Owner'))
        ->get(route('dashboard'))
        ->assertOk()
        ->assertDontSee('Kepegawaian')
        ->assertDontSee('Penggajian')
        ->assertDontSee('Absensi');
});

it('never renders KTP/NIK on the Owner KPI dashboard', function () {
    $ktp = '3273010101900099';
    $patient = Patient::factory()->create(['branch_id' => $this->branch->id, 'ktp_number' => $ktp, 'name' => 'Pasien Piutang']);
    $visit = ClinicVisit::factory()->create(['branch_id' => $this->branch->id, 'patient_id' => $patient->id, 'visit_date' => now()->toDateString()]);
    RmeInvoice::factory()->unpaid()->create([
        'branch_id' => $this->branch->id,
        'patient_id' => $patient->id,
        'clinic_visit_id' => $visit->id,
        'grand_total' => 400000,
    ]);

    $this->actingAs(userInRole('Owner'))
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Pasien Piutang')
        ->assertDontSee($ktp);
});

// ---------------------------------------------------------------------------
// Filters
// ---------------------------------------------------------------------------

it('applies the date range filter to visit counts', function () {
    ClinicVisit::factory()->create(['branch_id' => $this->branch->id, 'visit_date' => now()->toDateString()]);
    ClinicVisit::factory()->create(['branch_id' => $this->branch->id, 'visit_date' => now()->subMonths(2)->toDateString()]);

    $today = $this->service->resolvePeriod('today', null, null);
    expect($this->service->metrics(null, $today['from'], $today['to'])['total_visits'])->toBe(1);

    $wide = $this->service->resolvePeriod('custom', now()->subMonths(3)->toDateString(), now()->toDateString());
    expect($this->service->metrics(null, $wide['from'], $wide['to'])['total_visits'])->toBe(2);
});

it('applies the branch filter to KPI metrics', function () {
    $other = Branch::factory()->create(['code' => 'KPI-BR-B', 'name' => 'Cabang KPI B', 'is_active' => true]);
    ClinicVisit::factory()->create(['branch_id' => $this->branch->id, 'visit_date' => now()->toDateString()]);
    ClinicVisit::factory()->create(['branch_id' => $other->id, 'visit_date' => now()->toDateString()]);

    $period = $this->service->resolvePeriod('month', null, null);

    expect($this->service->metrics(null, $period['from'], $period['to'])['total_visits'])->toBe(2);
    expect($this->service->metrics($this->branch->id, $period['from'], $period['to'])['total_visits'])->toBe(1);
});

// ---------------------------------------------------------------------------
// KPI calculations
// ---------------------------------------------------------------------------

it('excludes zero grand total and zero remaining from active receivables', function () {
    // Included: real outstanding balance.
    RmeInvoice::factory()->unpaid()->create(['branch_id' => $this->branch->id, 'grand_total' => 500000]);
    // Excluded: zero grand total.
    RmeInvoice::factory()->unpaid()->create(['branch_id' => $this->branch->id, 'grand_total' => 0]);
    // Excluded: fully paid down (remaining zero).
    $settled = RmeInvoice::factory()->unpaid()->create(['branch_id' => $this->branch->id, 'grand_total' => 300000]);
    RmePayment::factory()->create([
        'branch_id' => $this->branch->id,
        'rme_invoice_id' => $settled->id,
        'amount' => 300000,
        'paid_at' => now(),
    ]);

    $period = $this->service->resolvePeriod('month', null, null);
    $metrics = $this->service->metrics(null, $period['from'], $period['to']);

    expect($metrics['active_receivable'])->toBe(500000.0);
    expect($metrics['unpaid_invoices'])->toBe(1);
});

it('counts overdue and due-today follow-ups in the follow-up KPI', function () {
    $invoice = RmeInvoice::factory()->unpaid()->create(['branch_id' => $this->branch->id, 'grand_total' => 200000]);
    RmeReceivableFollowUp::factory()->create([
        'rme_invoice_id' => $invoice->id,
        'next_follow_up_date' => now()->subDay()->toDateString(),
        'status' => RmeReceivableFollowUp::STATUS_CONTACTED,
    ]);

    $period = $this->service->resolvePeriod('month', null, null);

    expect($this->service->metrics(null, $period['from'], $period['to'])['follow_up_due'])->toBe(1);
});

it('exposes a low stock KPI card backed by inventory data', function () {
    $period = $this->service->resolvePeriod('month', null, null);
    $metrics = $this->service->metrics(null, $period['from'], $period['to']);

    expect($metrics)->toHaveKey('low_stock_items');
    expect($metrics)->toHaveKey('stock_value');

    $this->actingAs(userInRole('Owner'))->get(route('dashboard'))->assertOk()->assertSee('Low Stock');
});

// ---------------------------------------------------------------------------
// Drilldown links — permission & route aware
// ---------------------------------------------------------------------------

it('renders drilldown links only for permitted, existing routes', function () {
    $period = $this->service->resolvePeriod('month', null, null);

    $ownerLinks = $this->service->drilldownLinks(userInRole('Owner'), $period['from'], $period['to']);
    expect($ownerLinks)->toHaveKey('total_visits');
    expect($ownerLinks['total_visits'])->toBe(route('rme.visits.index'));
    // Owner lacks inventory/lab/billing operational permissions.
    expect($ownerLinks)->not->toHaveKey('low_stock_items');

    $kasirLinks = $this->service->drilldownLinks(userInRole('Kasir'), $period['from'], $period['to']);
    expect($kasirLinks)->toHaveKey('active_receivable');
    expect($kasirLinks['active_receivable'])->toBe(route('rme.cashier.receivables'));
});
