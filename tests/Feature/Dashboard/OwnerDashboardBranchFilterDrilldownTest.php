<?php

use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\LabOrder\Models\LabCaseCandidate;
use App\Modules\LabOrder\Models\LabOrder;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\Reporting\Services\OwnerDashboardRmeLabDrilldownService;
use App\Modules\Reporting\Services\OwnerDashboardRmeLabKpiService;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use App\Modules\RmeInvoice\Models\RmePayment;
use Database\Seeders\BranchSeeder;

beforeEach(function () {
    seedAccessControl();
    test()->seed(BranchSeeder::class);
    $this->branchA = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $this->branchB = Branch::factory()->create([
        'code' => 'FLT-BR-B',
        'name' => 'Cabang Filter B',
        'is_active' => true,
    ]);
    $this->service = app(OwnerDashboardRmeLabKpiService::class);
    $this->drilldowns = app(OwnerDashboardRmeLabDrilldownService::class);
});

it('shows branch filter with active branches for Owner', function () {
    $inactive = Branch::factory()->create([
        'code' => 'FLT-INACT',
        'name' => 'Cabang Nonaktif',
        'is_active' => false,
    ]);

    $this->actingAs(userInRole('Owner'))
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Filter Cabang')
        ->assertSee('Semua Cabang')
        ->assertSee($this->branchA->name)
        ->assertSee($this->branchB->name)
        ->assertDontSee($inactive->name);
});

it('aggregates owner dashboard KPIs across all active branches by default', function () {
    ClinicVisit::factory()->cashierPending()->create([
        'branch_id' => $this->branchA->id,
        'visit_date' => now()->toDateString(),
    ]);

    ClinicVisit::factory()->cashierPending()->create([
        'branch_id' => $this->branchB->id,
        'visit_date' => now()->toDateString(),
    ]);

    $this->actingAs(userInRole('Owner'))
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Menampilkan semua cabang aktif')
        ->assertSee(format_number_id(2));
});

it('filters owner dashboard KPIs to selected branch', function () {
    ClinicVisit::factory()->cashierPending()->create([
        'branch_id' => $this->branchA->id,
        'visit_date' => now()->toDateString(),
    ]);

    ClinicVisit::factory()->cashierPending()->create([
        'branch_id' => $this->branchB->id,
        'visit_date' => now()->toDateString(),
    ]);

    $this->actingAs(userInRole('Owner'))
        ->get(route('dashboard', ['branch_id' => $this->branchA->id]))
        ->assertOk()
        ->assertSee('Menampilkan cabang:')
        ->assertSee($this->branchA->name)
        ->assertSee(format_number_id(1))
        ->assertDontSee('Menampilkan semua cabang aktif');
});

it('ignores invalid branch_id and falls back to all active branches', function () {
    ClinicVisit::factory()->cashierPending()->create([
        'branch_id' => $this->branchA->id,
        'visit_date' => now()->toDateString(),
    ]);

    ClinicVisit::factory()->cashierPending()->create([
        'branch_id' => $this->branchB->id,
        'visit_date' => now()->toDateString(),
    ]);

    $this->actingAs(userInRole('Owner'))
        ->get(route('dashboard', ['branch_id' => 999999]))
        ->assertOk()
        ->assertSee('Menampilkan semua cabang aktif')
        ->assertSee(format_number_id(2));
});

it('shows branch summary table with per-branch counts for Owner', function () {
    ClinicVisit::factory()->create([
        'branch_id' => $this->branchA->id,
        'visit_date' => now()->toDateString(),
        'status' => ClinicVisit::STATUS_WAITING,
    ]);

    ClinicVisit::factory()->cashierPending()->create([
        'branch_id' => $this->branchB->id,
        'visit_date' => now()->toDateString(),
    ]);

    LabCaseCandidate::factory()->create([
        'branch_id' => $this->branchB->id,
        'status' => LabCaseCandidate::STATUS_PENDING_REVIEW,
    ]);

    $summary = $this->service->branchSummary();

    expect($summary)->not->toBeEmpty()
        ->and(collect($summary)->pluck('branch_name'))->toContain($this->branchA->name, $this->branchB->name);

    $branchBRow = collect($summary)->firstWhere('branch_id', $this->branchB->id);
    expect($branchBRow['cashier_pending'])->toBe(1)
        ->and($branchBRow['pending_candidates'])->toBe(1);

    $this->actingAs(userInRole('Owner'))
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Ringkasan Per Cabang')
        ->assertSee('Status Perhatian')
        ->assertSee('Perlu cek kasir');
});

it('excludes inactive branches from branch summary', function () {
    $inactive = Branch::factory()->create([
        'code' => 'FLT-SUM-IN',
        'name' => 'Cabang Summary Inaktif',
        'is_active' => false,
    ]);

    ClinicVisit::factory()->cashierPending()->create([
        'branch_id' => $inactive->id,
        'visit_date' => now()->toDateString(),
    ]);

    $summary = collect($this->service->branchSummary());

    expect($summary->pluck('branch_name'))->not->toContain($inactive->name);
});

it('does not show owner branch filter or branch summary for branch admin dashboard', function () {
    $this->actingAs(userWith([
        'view dashboard',
        'view_lab_orders',
        'view_production',
    ]))
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Dashboard Admin Cabang')
        ->assertDontSee('Filter Cabang')
        ->assertDontSee('Ringkasan Per Cabang')
        ->assertDontSee('Monitoring Pilot RME & Lab');
});

it('shows permission-aware drilldown links for Owner with clinic visit access only', function () {
    $owner = userInRole('Owner');
    $links = $this->drilldowns->linksFor($owner);

    expect($links)->toHaveKeys([
        'visits_today',
        'visits_cashier_pending',
        'medical_records_draft',
        'medical_records_final_today',
    ])->not->toHaveKey('lab_candidates_pending')
        ->not->toHaveKey('rme_invoices_unpaid');

    $this->actingAs($owner)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Lihat detail')
        ->assertSee(route('rme.visits.index', ['visit_date' => now()->toDateString()]), false)
        ->assertDontSee(route('lab-case-candidates.index', [
            'status' => LabCaseCandidate::STATUS_PENDING_REVIEW,
        ]), false);
});

it('shows RME receivables drilldown link for user with manage_rme_billing', function () {
    $user = userWith([
        'view dashboard',
        'view_owner_dashboard',
        'manage_rme_billing',
    ]);

    $links = $this->drilldowns->linksFor($user);

    expect($links)->toHaveKey('rme_receivables')
        ->and($links['rme_receivables'])->toBe(route('rme.cashier.receivables'));
});

it('does not show RME receivables drilldown link without manage_rme_billing', function () {
    $owner = userInRole('Owner');

    $links = $this->drilldowns->linksFor($owner);

    expect($links)->not->toHaveKey('rme_receivables');

    $this->actingAs($owner)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertDontSee(route('rme.cashier.receivables'), false);
});

it('shows lab candidate drilldown links when user has lab order permission', function () {
    $user = userWith([
        'view dashboard',
        'view_owner_dashboard',
        'view_lab_orders',
    ]);

    $links = $this->drilldowns->linksFor($user);

    expect($links)->toHaveKeys([
        'lab_candidates_pending',
        'lab_candidates_converted_today',
        'lab_orders_from_rme_today',
    ]);
});

it('does not create operational records when loading dashboard with branch filter', function () {
    ClinicVisit::factory()->cashierPending()->create([
        'branch_id' => $this->branchA->id,
        'visit_date' => now()->toDateString(),
    ]);

    $before = [
        'visits' => ClinicVisit::count(),
        'invoices' => RmeInvoice::count(),
        'payments' => RmePayment::count(),
        'candidates' => LabCaseCandidate::count(),
        'orders' => LabOrder::count(),
        'records' => MedicalRecord::count(),
    ];

    $this->actingAs(userInRole('Owner'))
        ->get(route('dashboard', ['branch_id' => $this->branchA->id]))
        ->assertOk();

    expect(ClinicVisit::count())->toBe($before['visits'])
        ->and(RmeInvoice::count())->toBe($before['invoices'])
        ->and(RmePayment::count())->toBe($before['payments'])
        ->and(LabCaseCandidate::count())->toBe($before['candidates'])
        ->and(LabOrder::count())->toBe($before['orders'])
        ->and(MedicalRecord::count())->toBe($before['records']);
});

it('loads safely with empty RME lab data and zero branch summary counts', function () {
    $metrics = $this->service->metrics();

    expect($metrics['visits_today'])->toBe(0);

    $this->actingAs(userInRole('Owner'))
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Monitoring Pilot RME & Lab')
        ->assertSee('Ringkasan Per Cabang')
        ->assertSee('Dashboard ini hanya monitoring; tidak membuat atau mengubah data RME/Lab.')
        ->assertSee('Belum ada aktivitas RME hari ini');
});
