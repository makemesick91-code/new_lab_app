<?php

use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\LabOrder\Models\LabCaseCandidate;
use App\Modules\LabOrder\Models\LabOrder;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\Reporting\Services\OwnerDashboardRmeLabKpiService;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use App\Modules\RmeInvoice\Models\RmePayment;
use Database\Seeders\BranchSeeder;

beforeEach(function () {
    seedAccessControl();
    test()->seed(BranchSeeder::class);
    $this->branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $this->service = app(OwnerDashboardRmeLabKpiService::class);
});

function seedOwnerDashboardPilotMetrics(Branch $branch): void
{
    $today = now()->toDateString();

    ClinicVisit::factory()->create([
        'branch_id' => $branch->id,
        'visit_date' => $today,
        'status' => ClinicVisit::STATUS_WAITING,
    ]);

    ClinicVisit::factory()->cashierPending()->create([
        'branch_id' => $branch->id,
        'visit_date' => $today,
    ]);

    $draftVisit = ClinicVisit::factory()->inProgress()->create([
        'branch_id' => $branch->id,
        'visit_date' => $today,
    ]);

    MedicalRecord::factory()->create([
        'branch_id' => $branch->id,
        'clinic_visit_id' => $draftVisit->id,
        'patient_id' => $draftVisit->patient_id,
        'doctor_id' => $draftVisit->doctor_id,
        'status' => MedicalRecord::STATUS_DRAFT,
    ]);

    $finalVisit = ClinicVisit::factory()->cashierPending()->create([
        'branch_id' => $branch->id,
        'visit_date' => $today,
    ]);

    MedicalRecord::factory()->final()->create([
        'branch_id' => $branch->id,
        'clinic_visit_id' => $finalVisit->id,
        'patient_id' => $finalVisit->patient_id,
        'doctor_id' => $finalVisit->doctor_id,
        'finalized_at' => now(),
    ]);

    RmeInvoice::factory()->unpaid()->create([
        'branch_id' => $branch->id,
    ]);

    $paidVisit = ClinicVisit::factory()->completed()->create([
        'branch_id' => $branch->id,
        'visit_date' => $today,
        'completed_at' => now(),
    ]);

    $paidInvoice = RmeInvoice::factory()->paid()->create([
        'branch_id' => $branch->id,
        'clinic_visit_id' => $paidVisit->id,
        'patient_id' => $paidVisit->patient_id,
        'grand_total' => 750000,
    ]);

    RmePayment::factory()->create([
        'branch_id' => $branch->id,
        'rme_invoice_id' => $paidInvoice->id,
        'clinic_visit_id' => $paidVisit->id,
        'patient_id' => $paidVisit->patient_id,
        'amount' => 750000,
        'paid_at' => now(),
    ]);

    LabCaseCandidate::factory()->create([
        'branch_id' => $branch->id,
        'status' => LabCaseCandidate::STATUS_PENDING_REVIEW,
    ]);

    $labOrder = LabOrder::factory()->create([
        'branch_id' => $branch->id,
        'created_at' => now(),
    ]);

    LabCaseCandidate::factory()->converted()->create([
        'branch_id' => $branch->id,
        'converted_lab_order_id' => $labOrder->id,
        'reviewed_at' => now(),
    ]);
}

it('shows Monitoring Pilot RME and Lab section for Owner with Indonesian labels', function () {
    $this->actingAs(userInRole('Owner'))
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Monitoring Pilot RME & Lab')
        ->assertSee('Kunjungan RME Hari Ini')
        ->assertSee('RM Draft')
        ->assertSee('RM Final Hari Ini')
        ->assertSee('Menunggu Kasir')
        ->assertSee('Invoice RME Belum Dibayar')
        ->assertSee('Pembayaran RME Hari Ini')
        ->assertSee('Kandidat Lab RME Pending')
        ->assertSee('Kandidat Lab Dikonversi')
        ->assertSee('Lab Order dari RME Hari Ini')
        ->assertSee('Funnel RME ke Lab')
        ->assertSee('Perlu Perhatian')
        ->assertSee('Data bersifat monitoring pilot dan dihitung dari transaksi RME/Lab saat ini.');
});

it('shows safe empty state counts for Owner when no RME lab data exists', function () {
    $metrics = $this->service->metrics();

    expect($metrics['visits_today'])->toBe(0)
        ->and($metrics['lab_candidates_pending'])->toBe(0)
        ->and($metrics['rme_invoices_unpaid'])->toBe(0);

    $this->actingAs(userInRole('Owner'))
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Monitoring Pilot RME & Lab')
        ->assertSee('Belum ada aktivitas RME hari ini');
});

it('computes owner dashboard RME lab KPIs from realistic pilot data', function () {
    seedOwnerDashboardPilotMetrics($this->branch);

    $metrics = $this->service->metrics();

    expect($metrics['visits_today'])->toBeGreaterThanOrEqual(4)
        ->and($metrics['visits_cashier_pending'])->toBeGreaterThanOrEqual(2)
        ->and($metrics['medical_records_draft'])->toBeGreaterThanOrEqual(1)
        ->and($metrics['medical_records_final_today'])->toBeGreaterThanOrEqual(1)
        ->and($metrics['rme_invoices_unpaid'])->toBeGreaterThanOrEqual(1)
        ->and($metrics['rme_invoices_paid_today'])->toBeGreaterThanOrEqual(1)
        ->and((float) $metrics['rme_revenue_paid_today'])->toBeGreaterThanOrEqual(750000.0)
        ->and($metrics['lab_candidates_pending'])->toBeGreaterThanOrEqual(1)
        ->and($metrics['lab_candidates_converted_today'])->toBeGreaterThanOrEqual(1)
        ->and($metrics['lab_orders_from_rme_today'])->toBeGreaterThanOrEqual(1);

    $this->actingAs(userInRole('Owner'))
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee(format_number_id($metrics['visits_today']))
        ->assertSee(format_number_id($metrics['lab_candidates_pending']))
        ->assertSee(format_currency_id($metrics['rme_revenue_paid_today']));
});

it('aggregates owner KPIs across all active branches', function () {
    $otherBranch = Branch::factory()->create([
        'code' => 'OWN-KPI-B',
        'name' => 'Cabang KPI B',
        'is_active' => true,
    ]);

    ClinicVisit::factory()->cashierPending()->create([
        'branch_id' => $this->branch->id,
        'visit_date' => now()->toDateString(),
    ]);

    ClinicVisit::factory()->cashierPending()->create([
        'branch_id' => $otherBranch->id,
        'visit_date' => now()->toDateString(),
    ]);

    $global = $this->service->metrics();
    $mainOnly = $this->service->metrics($this->branch->id);
    $otherOnly = $this->service->metrics($otherBranch->id);

    expect($global['visits_cashier_pending'])->toBe(2)
        ->and($mainOnly['visits_cashier_pending'])->toBe(1)
        ->and($otherOnly['visits_cashier_pending'])->toBe(1);
});

it('lists branch attention items when pilot queues need follow-up', function () {
    $cashierVisit = ClinicVisit::factory()->cashierPending()->create([
        'branch_id' => $this->branch->id,
        'visit_date' => now()->toDateString(),
    ]);

    $billingVisit = ClinicVisit::factory()->cashierPending()->create([
        'branch_id' => $this->branch->id,
        'visit_date' => now()->toDateString(),
    ]);

    RmeInvoice::factory()->unpaid()->create([
        'branch_id' => $this->branch->id,
        'clinic_visit_id' => $billingVisit->id,
        'patient_id' => $billingVisit->patient_id,
    ]);

    $metrics = $this->service->metrics();

    expect($metrics['attention_items'])->not->toBeEmpty()
        ->and(collect($metrics['attention_items'])->pluck('title'))->toContain($this->branch->name);

    $this->actingAs(userInRole('Owner'))
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee($this->branch->name)
        ->assertSee('kunjungan menunggu kasir');
});

it('does not show owner RME lab section for branch admin dashboard users', function () {
    $this->actingAs(userWith([
        'view dashboard',
        'view_lab_orders',
        'view_production',
    ]))
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Dashboard Admin Cabang')
        ->assertDontSee('Monitoring Pilot RME & Lab');
});

it('does not show owner RME lab section for Kasir role dashboard', function () {
    // FIX-03 — a Kasir now works from a selected branch, so give it one before
    // asserting what its dashboard shows.
    $kasir = userInRole('Kasir');
    rmeMakeKasirActive($kasir, $this->branch);

    $this->actingAs($kasir)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertDontSee('Monitoring Pilot RME & Lab')
        ->assertDontSee('Dashboard Owner');
});

it('does not create operational records when owner dashboard is loaded', function () {
    seedOwnerDashboardPilotMetrics($this->branch);

    $before = [
        'visits' => ClinicVisit::count(),
        'invoices' => RmeInvoice::count(),
        'payments' => RmePayment::count(),
        'candidates' => LabCaseCandidate::count(),
        'orders' => LabOrder::count(),
    ];

    $this->actingAs(userInRole('Owner'))
        ->get(route('dashboard'))
        ->assertOk();

    expect(ClinicVisit::count())->toBe($before['visits'])
        ->and(RmeInvoice::count())->toBe($before['invoices'])
        ->and(RmePayment::count())->toBe($before['payments'])
        ->and(LabCaseCandidate::count())->toBe($before['candidates'])
        ->and(LabOrder::count())->toBe($before['orders']);
});

it('computes RME receivable KPIs from UNPAID and PARTIAL invoices only', function () {
    RmeInvoice::factory()->unpaid()->create([
        'branch_id' => $this->branch->id,
        'grand_total' => 400000,
    ]);

    $partialInvoice = RmeInvoice::factory()->partial()->create([
        'branch_id' => $this->branch->id,
        'grand_total' => 1000000,
    ]);

    // Create the payment via the invoice relation to avoid the payment
    // factory spawning a phantom UNPAID invoice that would skew counts.
    $partialInvoice->payments()->create([
        'branch_id' => $this->branch->id,
        'clinic_visit_id' => $partialInvoice->clinic_visit_id,
        'patient_id' => $partialInvoice->patient_id,
        'cashier_id' => $partialInvoice->cashier_id,
        'payment_number' => 'RCV-TEST-PARTIAL',
        'amount' => 300000,
        'paid_at' => now(),
    ]);

    // PAID invoice with full payment — must be excluded from receivables.
    $paidInvoice = RmeInvoice::factory()->paid()->create([
        'branch_id' => $this->branch->id,
        'grand_total' => 500000,
    ]);

    $paidInvoice->payments()->create([
        'branch_id' => $this->branch->id,
        'clinic_visit_id' => $paidInvoice->clinic_visit_id,
        'patient_id' => $paidInvoice->patient_id,
        'cashier_id' => $paidInvoice->cashier_id,
        'payment_number' => 'RCV-TEST-PAID',
        'amount' => 500000,
        'paid_at' => now(),
    ]);

    $metrics = $this->service->metrics();

    // 400000 (unpaid) + (1000000 - 300000) partial = 1100000; PAID excluded.
    expect((float) $metrics['rme_receivable_total_remaining'])->toBe(1100000.0)
        ->and($metrics['rme_receivable_partial_count'])->toBe(1)
        ->and($metrics['rme_receivable_unpaid_count'])->toBe(1);

    $this->actingAs(userInRole('Owner'))
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Sisa Piutang RME')
        ->assertSee('Invoice Cicilan')
        ->assertSee('Invoice Belum Dibayar')
        ->assertSee(format_currency_id(1100000));
});

it('limits RME receivable KPIs to the selected branch', function () {
    $otherBranch = Branch::factory()->create([
        'code' => 'OWN-RCV-B',
        'name' => 'Cabang Piutang B',
        'is_active' => true,
    ]);

    RmeInvoice::factory()->unpaid()->create([
        'branch_id' => $this->branch->id,
        'grand_total' => 200000,
    ]);

    RmeInvoice::factory()->unpaid()->create([
        'branch_id' => $otherBranch->id,
        'grand_total' => 500000,
    ]);

    $global = $this->service->metrics();
    $mainOnly = $this->service->metrics($this->branch->id);
    $otherOnly = $this->service->metrics($otherBranch->id);

    expect((float) $global['rme_receivable_total_remaining'])->toBe(700000.0)
        ->and((float) $mainOnly['rme_receivable_total_remaining'])->toBe(200000.0)
        ->and((float) $otherOnly['rme_receivable_total_remaining'])->toBe(500000.0)
        ->and($global['rme_receivable_unpaid_count'])->toBe(2)
        ->and($mainOnly['rme_receivable_unpaid_count'])->toBe(1);
});

it('shows owner dashboard branch receivable summary table with Indonesian labels', function () {
    RmeInvoice::factory()->unpaid()->create([
        'branch_id' => $this->branch->id,
        'grand_total' => 400000,
    ]);

    $this->actingAs(userInRole('Owner'))
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Ringkasan Piutang per Cabang')
        ->assertSee('Sisa Piutang')
        ->assertSee('Invoice Cicilan')
        ->assertSee('Invoice Belum Dibayar')
        ->assertSee('Tindak Lanjut')
        ->assertSee($this->branch->name)
        ->assertSee(format_currency_id(400000));
});

it('shows Lihat Piutang branch action only when user can manage RME billing', function () {
    RmeInvoice::factory()->unpaid()->create([
        'branch_id' => $this->branch->id,
        'grand_total' => 400000,
    ]);

    // Owner sees the summary table but not the gated receivables action.
    $this->actingAs(userInRole('Owner'))
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Ringkasan Piutang per Cabang')
        ->assertDontSee('Lihat Piutang');

    // Owner-dashboard user that also manages RME billing sees the branch-filtered action.
    $this->actingAs(userWith([
        'view dashboard',
        'view_owner_dashboard',
        'manage_rme_billing',
    ]))
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Lihat Piutang')
        ->assertSee(route('rme.cashier.receivables', ['branch_id' => $this->branch->id]), false);
});

it('aggregates branch receivable remaining from UNPAID and PARTIAL invoices and excludes PAID', function () {
    RmeInvoice::factory()->unpaid()->create([
        'branch_id' => $this->branch->id,
        'grand_total' => 400000,
    ]);

    $partialInvoice = RmeInvoice::factory()->partial()->create([
        'branch_id' => $this->branch->id,
        'grand_total' => 1000000,
    ]);

    $partialInvoice->payments()->create([
        'branch_id' => $this->branch->id,
        'clinic_visit_id' => $partialInvoice->clinic_visit_id,
        'patient_id' => $partialInvoice->patient_id,
        'cashier_id' => $partialInvoice->cashier_id,
        'payment_number' => 'RCV-BRANCH-PARTIAL',
        'amount' => 300000,
        'paid_at' => now(),
    ]);

    // PAID invoice must be excluded from the receivable summary.
    $paidInvoice = RmeInvoice::factory()->paid()->create([
        'branch_id' => $this->branch->id,
        'grand_total' => 500000,
    ]);

    $paidInvoice->payments()->create([
        'branch_id' => $this->branch->id,
        'clinic_visit_id' => $paidInvoice->clinic_visit_id,
        'patient_id' => $paidInvoice->patient_id,
        'cashier_id' => $paidInvoice->cashier_id,
        'payment_number' => 'RCV-BRANCH-PAID',
        'amount' => 500000,
        'paid_at' => now(),
    ]);

    $summary = $this->service->branchReceivableSummary();
    $mainRow = collect($summary)->firstWhere('branch_id', $this->branch->id);

    // 400000 (unpaid) + (1000000 - 300000) partial = 1100000; PAID excluded.
    expect((float) $mainRow['receivable_total_remaining'])->toBe(1100000.0)
        ->and($mainRow['partial_count'])->toBe(1)
        ->and($mainRow['unpaid_count'])->toBe(1);
});

it('limits branch receivable summary to the selected branch', function () {
    $otherBranch = Branch::factory()->create([
        'code' => 'OWN-RCV-SUM-B',
        'name' => 'Cabang Piutang Sum B',
        'is_active' => true,
    ]);

    RmeInvoice::factory()->unpaid()->create([
        'branch_id' => $this->branch->id,
        'grand_total' => 200000,
    ]);

    RmeInvoice::factory()->unpaid()->create([
        'branch_id' => $otherBranch->id,
        'grand_total' => 500000,
    ]);

    $global = $this->service->branchReceivableSummary();
    $mainOnly = $this->service->branchReceivableSummary($this->branch->id);
    $otherOnly = $this->service->branchReceivableSummary($otherBranch->id);

    // No filter returns every active branch; a selected branch returns only it.
    expect($mainOnly)->toHaveCount(1)
        ->and($otherOnly)->toHaveCount(1)
        ->and(collect($global)->pluck('branch_id'))->toContain($this->branch->id, $otherBranch->id)
        ->and((float) collect($mainOnly)->firstWhere('branch_id', $this->branch->id)['receivable_total_remaining'])->toBe(200000.0)
        ->and((float) collect($otherOnly)->firstWhere('branch_id', $otherBranch->id)['receivable_total_remaining'])->toBe(500000.0)
        ->and((float) collect($global)->firstWhere('branch_id', $this->branch->id)['receivable_total_remaining'])->toBe(200000.0)
        ->and((float) collect($global)->firstWhere('branch_id', $otherBranch->id)['receivable_total_remaining'])->toBe(500000.0);
});

it('does not show branch receivable summary table for non-owner dashboard users', function () {
    $this->actingAs(userWith([
        'view dashboard',
        'view_lab_orders',
        'view_production',
    ]))
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Dashboard Admin Cabang')
        ->assertDontSee('Ringkasan Piutang per Cabang');
});

it('does not create operational records when branch receivable summary is computed', function () {
    RmeInvoice::factory()->unpaid()->create([
        'branch_id' => $this->branch->id,
        'grand_total' => 400000,
    ]);

    $before = [
        'invoices' => RmeInvoice::count(),
        'payments' => RmePayment::count(),
    ];

    $this->service->branchReceivableSummary();

    $this->actingAs(userInRole('Owner'))
        ->get(route('dashboard'))
        ->assertOk();

    expect(RmeInvoice::count())->toBe($before['invoices'])
        ->and(RmePayment::count())->toBe($before['payments']);
});

it('builds RME to lab funnel stages from paid invoice and converted candidate metrics', function () {
    seedOwnerDashboardPilotMetrics($this->branch);

    $metrics = $this->service->metrics();

    expect($metrics['funnel_stages'])->toHaveCount(5)
        ->and(collect($metrics['funnel_stages'])->pluck('label')->all())->toBe([
            'Kunjungan',
            'Menunggu Kasir',
            'Bayar RME',
            'Kandidat Lab',
            'Lab Order RME',
        ])
        ->and($metrics['rme_invoices_paid_today'])->toBeGreaterThanOrEqual(1)
        ->and($metrics['lab_orders_from_rme_today'])->toBeGreaterThanOrEqual(1);
});
