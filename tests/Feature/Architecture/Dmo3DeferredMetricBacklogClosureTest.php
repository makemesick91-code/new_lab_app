<?php

use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Delivery\Models\Delivery;
use App\Modules\Invoice\Models\Invoice;
use App\Modules\Invoice\Models\Payment;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use App\Modules\RmeInvoice\Models\RmePayment;
use App\Modules\RmeInvoice\Services\RmeInvoiceService;
use App\Modules\Tariff\Models\Tariff;
use App\Modules\Tariff\Services\TariffBoundaryService;
use App\Modules\Treatment\Models\Treatment;
use App\Modules\TreatmentCategory\Models\TreatmentCategory;
use App\Services\Architecture\DmoMetricService;
use App\Services\Architecture\FoundationGovernanceSummaryService;
use Database\Seeders\BranchSeeder;
use Illuminate\Support\Facades\Artisan;

uses()->group('Architecture', 'Dmo3', 'Dmo', 'FoundationGovernance');

beforeEach(function () {
    test()->seed(BranchSeeder::class);
    seedAccessControl();

    Branch::where('code', Branch::MAIN_CODE)->update(['is_rme_enabled' => false]);
    $this->branchA = Branch::factory()->create(['code' => 'DMO3A', 'is_rme_enabled' => true]);
    $this->branchB = Branch::factory()->create(['code' => 'DMO3B', 'is_rme_enabled' => true]);
    $this->cashier = userWith(['manage_rme_billing']);
    $this->metrics = app(DmoMetricService::class);
    $this->from = now()->startOfMonth();
    $this->to = now()->endOfMonth();
});

function dmo3FinalizedVisit(Branch $branch)
{
    $visit = ClinicVisit::factory()->cashierPending()->create(['branch_id' => $branch->id]);
    MedicalRecord::factory()->final()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $branch->id,
        'patient_id' => $visit->patient_id,
        'doctor_id' => $visit->doctor_id,
    ]);

    return $visit;
}

function dmo3ItemPayload(array $overrides = []): array
{
    return array_merge([
        'treatment_id' => null,
        'description' => 'Tindakan DMO-3',
        'qty' => 1,
        'unit_price' => 200000,
        'discount' => 0,
    ], $overrides);
}

it('counts paid rme amount as net revenue without remaining receivable', function () {
    $visit = dmo3FinalizedVisit($this->branchA);
    $invoice = app(RmeInvoiceService::class)->create($visit, $this->cashier, [
        'items' => [dmo3ItemPayload(['unit_price' => 300000])],
    ]);

    RmePayment::factory()->create([
        'branch_id' => $this->branchA->id,
        'rme_invoice_id' => $invoice->id,
        'clinic_visit_id' => $visit->id,
        'patient_id' => $visit->patient_id,
        'amount' => 100000,
        'paid_at' => now(),
    ]);
    $invoice->update(['status' => RmeInvoice::STATUS_PARTIAL]);

    $result = $this->metrics->netRevenue($this->branchA->id, $this->from, $this->to);

    expect($result['rme_collected_revenue'])->toBe(100000.0)
        ->and($result['net_revenue'])->toBe(100000.0);
});

it('excludes void invoice payments from net revenue', function () {
    $visit = dmo3FinalizedVisit($this->branchA);
    $invoice = app(RmeInvoiceService::class)->create($visit, $this->cashier, [
        'items' => [dmo3ItemPayload()],
    ]);
    RmePayment::factory()->create([
        'branch_id' => $this->branchA->id,
        'rme_invoice_id' => $invoice->id,
        'clinic_visit_id' => $visit->id,
        'patient_id' => $visit->patient_id,
        'amount' => 150000,
        'paid_at' => now(),
    ]);
    $invoice->update(['status' => RmeInvoice::STATUS_VOID]);

    $result = $this->metrics->netRevenue($this->branchA->id, $this->from, $this->to);

    expect($result['rme_collected_revenue'])->toBe(0.0);
});

it('keeps lab revenue separate from rme revenue', function () {
    $invoice = Invoice::factory()->issued()->create(['branch_id' => $this->branchA->id]);
    Payment::factory()->create([
        'branch_id' => $this->branchA->id,
        'invoice_id' => $invoice->id,
        'amount' => 50000,
        'payment_date' => now()->toDateString(),
    ]);

    $visit = dmo3FinalizedVisit($this->branchA);
    $rmeInvoice = app(RmeInvoiceService::class)->create($visit, $this->cashier, [
        'items' => [dmo3ItemPayload(['unit_price' => 100000])],
    ]);
    RmePayment::factory()->create([
        'branch_id' => $this->branchA->id,
        'rme_invoice_id' => $rmeInvoice->id,
        'clinic_visit_id' => $visit->id,
        'patient_id' => $visit->patient_id,
        'amount' => 100000,
        'paid_at' => now(),
    ]);

    $result = $this->metrics->netRevenue($this->branchA->id, $this->from, $this->to);

    expect($result['rme_collected_revenue'])->toBe(100000.0)
        ->and($result['lab_collected_revenue'])->toBe(50000.0)
        ->and($result['combined_collected_revenue'])->toBe(150000.0);
});

it('respects branch filter for net revenue', function () {
    $visitA = dmo3FinalizedVisit($this->branchA);
    $invoiceA = app(RmeInvoiceService::class)->create($visitA, $this->cashier, ['items' => [dmo3ItemPayload()]]);
    RmePayment::factory()->create([
        'branch_id' => $this->branchA->id,
        'rme_invoice_id' => $invoiceA->id,
        'clinic_visit_id' => $visitA->id,
        'patient_id' => $visitA->patient_id,
        'amount' => 80000,
        'paid_at' => now(),
    ]);

    $visitB = dmo3FinalizedVisit($this->branchB);
    $invoiceB = app(RmeInvoiceService::class)->create($visitB, $this->cashier, ['items' => [dmo3ItemPayload()]]);
    RmePayment::factory()->create([
        'branch_id' => $this->branchB->id,
        'rme_invoice_id' => $invoiceB->id,
        'clinic_visit_id' => $visitB->id,
        'patient_id' => $visitB->patient_id,
        'amount' => 120000,
        'paid_at' => now(),
    ]);

    $branchAOnly = $this->metrics->netRevenue($this->branchA->id, $this->from, $this->to);

    expect($branchAOnly['rme_collected_revenue'])->toBe(80000.0);
});

it('places receivable invoices in correct aging buckets', function () {
    foreach ([3 => '0-7', 10 => '8-14', 20 => '15-30', 45 => '31-60', 90 => '61+'] as $days => $bucket) {
        $visit = dmo3FinalizedVisit($this->branchA);
        $invoice = app(RmeInvoiceService::class)->create($visit, $this->cashier, [
            'items' => [dmo3ItemPayload(['unit_price' => 100000])],
        ]);
        RmeInvoice::query()->whereKey($invoice->id)->update(['created_at' => now()->subDays($days)]);
    }

    $summary = $this->metrics->receivableAgingBuckets($this->branchA->id);

    foreach (['0-7', '8-14', '15-30', '31-60', '61+'] as $bucket) {
        expect($summary[$bucket]['count'])->toBe(1);
    }
});

it('excludes paid void and zero remaining receivables from aging buckets', function () {
    $active = dmo3FinalizedVisit($this->branchA);
    app(RmeInvoiceService::class)->create($active, $this->cashier, ['items' => [dmo3ItemPayload()]]);

    $paidVisit = dmo3FinalizedVisit($this->branchA);
    $paid = app(RmeInvoiceService::class)->create($paidVisit, $this->cashier, ['items' => [dmo3ItemPayload()]]);
    $paid->update(['status' => RmeInvoice::STATUS_PAID]);

    $voidVisit = dmo3FinalizedVisit($this->branchA);
    $void = app(RmeInvoiceService::class)->create($voidVisit, $this->cashier, ['items' => [dmo3ItemPayload()]]);
    $void->update(['status' => RmeInvoice::STATUS_VOID]);

    $zero = RmeInvoice::factory()->unpaid()->create([
        'branch_id' => $this->branchA->id,
        'clinic_visit_id' => dmo3FinalizedVisit($this->branchA)->id,
        'grand_total' => 0,
        'subtotal' => 0,
    ]);

    $summary = $this->metrics->receivableAgingBuckets($this->branchA->id);
    $totalCount = collect($summary)->sum('count');

    expect($totalCount)->toBe(1)
        ->and($zero->id)->toBeInt();
});

it('resolves branch specific tariffs without cross branch fallback', function () {
    $category = TreatmentCategory::factory()->create();
    $treatment = Treatment::factory()->create(['treatment_category_id' => $category->id]);
    Tariff::factory()->create([
        'branch_id' => $this->branchA->id,
        'treatment_id' => $treatment->id,
        'price' => 111000,
        'is_active' => true,
        'effective_date' => now()->subDay()->toDateString(),
    ]);
    Tariff::factory()->create([
        'branch_id' => $this->branchB->id,
        'treatment_id' => $treatment->id,
        'price' => 222000,
        'is_active' => true,
        'effective_date' => now()->subDay()->toDateString(),
    ]);

    $service = app(TariffBoundaryService::class);

    expect($service->resolveActivePrice($this->branchA->id, $treatment->id))->toBe(111000.0)
        ->and($service->resolveActivePrice($this->branchB->id, $treatment->id))->toBe(222000.0)
        ->and($service->resolveActivePrice($this->branchA->id, $treatment->id + 99999))->toBeNull();
});

it('counts only proven pod deliveries in period', function () {
    Delivery::factory()->completed()->create([
        'branch_id' => $this->branchA->id,
        'received_at' => now(),
    ]);
    Delivery::factory()->create([
        'branch_id' => $this->branchA->id,
        'status' => Delivery::STATUS_READY_FOR_DELIVERY,
    ]);
    Delivery::factory()->create([
        'branch_id' => $this->branchA->id,
        'status' => Delivery::STATUS_CANCELLED,
        'receiver_signature_path' => 'x.png',
        'received_at' => now(),
    ]);

    $count = $this->metrics->podCount($this->branchA->id, $this->from, $this->to);

    expect($count)->toBe(1);
});

it('dmo governance reports resolved deferred metrics as passed', function () {
    Artisan::call('architecture:dmo-governance-check', ['--json' => true]);
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    $deferred = collect($payload['results'])->whereIn('rule_id', ['DMO-M001', 'DMO-M003', 'DMO-M006', 'DMO-M007']);

    expect($payload['summary']['decision'])->toBe('GO')
        ->and($deferred)->toHaveCount(4)
        ->and($deferred->every(fn (array $r) => $r['status'] === 'passed'))->toBeTrue();
});

it('foundation summary shows dmo raw go after dmo3 closure', function () {
    $summary = app(FoundationGovernanceSummaryService::class)->collect();

    expect($summary['summary']['dmo_decision'])->toBe('GO')
        ->and($summary['summary']['dmo_effective_decision'])->toBe('GO')
        ->and($summary['summary']['combined_decision'])->toBe('GO')
        ->and(collect($summary['watch_causes']['dmo'])->pluck('rule_id'))->not->toContain('DMO-M001');
});

it('foundation governance json includes dmo3 resolved metrics config', function () {
    Artisan::call('architecture:foundation-governance-summary', ['--json' => true]);
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($payload['metadata']['sprint'])->toBe('DMO-3')
        ->and(config('foundation_governance.resolved_metrics'))->toHaveKeys(['DMO-M001', 'DMO-M003', 'DMO-M006', 'DMO-M007']);
});
