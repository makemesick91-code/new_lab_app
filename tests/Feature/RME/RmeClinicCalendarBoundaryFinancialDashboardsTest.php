<?php

/**
 * SECURITY-F4A2-CLINIC-CALENDAR-FINANCIAL-DASHBOARDS-1 — part of SECURITY-F4A (split by module).
 * The clinic calendar (Asia/Makassar, UTC+8), never the UTC one.
 *
 * The application timezone is UTC. Between 00:00 and 08:00 WITA the UTC date is
 * still yesterday, so "today" counts, receivable aging, due follow-ups and
 * date defaults named the previous clinic day for eight hours of every day.
 *
 * Every case freezes the clock at a UTC instant chosen so that the UTC date and
 * the clinic date differ (or, for the control, agree):
 *
 *   2026-10-27 15:59 UTC = 2026-10-27 23:59 WITA  (same date)
 *   2026-10-27 16:01 UTC = 2026-10-28 00:01 WITA  (UTC is still the 27th)
 *   2026-10-27 23:59 UTC = 2026-10-28 07:59 WITA  (UTC is still the 27th)
 */

use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\Reporting\Services\OwnerDashboardKpiService;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use App\Modules\RmeInvoice\Models\RmePayment;
use App\Modules\RmeInvoice\Models\RmeReceivableFollowUp;
use Database\Seeders\BranchSeeder;
use Illuminate\Support\Carbon;

beforeEach(function () {
    test()->seed(BranchSeeder::class);
    seedAccessControl();

    Branch::where('code', Branch::MAIN_CODE)->update(['is_rme_enabled' => false]);
    $this->branch = Branch::factory()->create(['code' => 'ATG3', 'is_active' => true, 'is_rme_enabled' => true]);
    $this->cashier = userWith(['manage_rme_billing']);
});

afterEach(function () {
    freeTestClock();
});
function rccInvoice(string $createdAtUtc): RmeInvoice
{
    $invoice = RmeInvoice::factory()->create([
        'branch_id' => test()->branch->id,
        'status' => RmeInvoice::STATUS_UNPAID,
        'subtotal' => 300000,
        'grand_total' => 300000,
    ]);
    RmeInvoice::query()->whereKey($invoice->id)->update(['created_at' => $createdAtUtc]);

    return $invoice->fresh();
}
function rccCsvRows(string $csv): array
{
    $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv);

    return array_map(fn (string $line) => str_getcsv($line), preg_split("/\r\n|\n/", trim($csv)));
}
it('totals payments received on the clinic day on the RME dashboard', function () {
    pinTestClock('2026-10-27 23:59:00'); // clinic 2026-10-28 07:59
    $invoice = rccInvoice('2026-10-27 16:00:00');

    $today = RmePayment::factory()->create(['rme_invoice_id' => $invoice->id, 'branch_id' => $this->branch->id, 'amount' => 100000]);
    $yesterday = RmePayment::factory()->create(['rme_invoice_id' => $invoice->id, 'branch_id' => $this->branch->id, 'amount' => 50000]);
    RmePayment::query()->whereKey($today->id)->update(['paid_at' => '2026-10-27 16:30:00']);     // 10-28 00:30 WITA
    RmePayment::query()->whereKey($yesterday->id)->update(['paid_at' => '2026-10-27 15:30:00']); // 10-27 23:30 WITA

    $metrics = $this->actingAs(userWith(['view_clinic_visits']))
        ->get(route('rme.dashboard'))
        ->assertOk()
        ->viewData('metrics');

    expect($metrics['payments_today_total'])->toBe(100000.0);
});
it('ages a receivable by its clinic date', function () {
    pinTestClock('2026-10-27 16:30:00'); // clinic 2026-10-28 00:30
    $eightDays = rccInvoice('2026-10-20 15:00:00');   // clinic 10-20 23:00 → 8 days → 8-14
    $sevenDays = rccInvoice('2026-10-20 17:00:00');   // clinic 10-21 01:00 → 7 days → 0-7

    $this->actingAs($this->cashier);

    $rows = collect(rccCsvRows($this->get(route('rme.cashier.receivables.export'))->streamedContent()));
    $header = $rows->shift();
    $byInvoice = $rows->mapWithKeys(fn (array $r) => [$r[0] => array_combine($header, $r)]);

    expect($byInvoice[$eightDays->invoice_number]['Umur Hari'])->toBe('8')
        ->and($byInvoice[$eightDays->invoice_number]['Bucket Aging'])->toBe('8-14')
        ->and($byInvoice[$sevenDays->invoice_number]['Umur Hari'])->toBe('7')
        ->and($byInvoice[$sevenDays->invoice_number]['Bucket Aging'])->toBe('0-7');

    $this->get(route('rme.cashier.receivables', ['aging_bucket' => '8-14']))
        ->assertSee($eightDays->invoice_number)
        ->assertDontSee($sevenDays->invoice_number);

    $aging = $this->get(route('rme.cashier.receivables'))->viewData('aging');
    expect($aging['8-14']['count'])->toBe(1)
        ->and($aging['0-7']['count'])->toBe(1);
});
it('treats a follow-up due on the clinic date as due today', function () {
    pinTestClock('2026-10-27 16:30:00'); // clinic 2026-10-28 00:30
    $invoice = rccInvoice('2026-10-25 03:00:00');
    RmeReceivableFollowUp::factory()->create([
        'rme_invoice_id' => $invoice->id,
        'next_follow_up_date' => '2026-10-28',
    ]);

    $this->actingAs($this->cashier)
        ->get(route('rme.cashier.receivables', ['follow_up_filter' => 'today']))
        ->assertSee($invoice->invoice_number);

    $this->get(route('rme.cashier.receivables', ['follow_up_filter' => 'scheduled']))
        ->assertDontSee($invoice->invoice_number);
});
it('counts follow-ups due on the clinic date in the owner KPIs', function () {
    pinTestClock('2026-10-27 16:30:00'); // clinic 2026-10-28 00:30
    $invoice = rccInvoice('2026-10-25 03:00:00');
    RmeReceivableFollowUp::factory()->create([
        'rme_invoice_id' => $invoice->id,
        'next_follow_up_date' => '2026-10-28',
    ]);

    $kpis = app(OwnerDashboardKpiService::class);
    $metrics = $kpis->metrics($this->branch->id, Carbon::parse('2026-10-01'), Carbon::parse('2026-10-31'));
    $perBranch = collect($kpis->branchPerformance($this->branch->id, Carbon::parse('2026-10-01'), Carbon::parse('2026-10-31')))
        ->firstWhere('branch_id', $this->branch->id);

    expect($metrics['follow_up_due'])->toBe(1)
        ->and($perBranch['follow_up_due'])->toBe(1);
});
it('counts the clinic month on the RME dashboard', function () {
    pinTestClock('2026-10-31 16:30:00'); // clinic 2026-11-01 00:30
    $patientVisit = fn (string $date) => ClinicVisit::factory()->create(['branch_id' => $this->branch->id, 'visit_date' => $date]);
    $november = $patientVisit('2026-11-01');
    $patientVisit('2026-10-31');

    $record = MedicalRecord::factory()->create([
        'clinic_visit_id' => $november->id,
        'branch_id' => $this->branch->id,
        'status' => MedicalRecord::STATUS_FINAL,
    ]);
    MedicalRecord::query()->whereKey($record->id)->update(['finalized_at' => '2026-10-31 16:10:00']); // 11-01 00:10 WITA

    $metrics = $this->actingAs(userWith(['view_clinic_visits']))
        ->get(route('rme.dashboard', ['branch_id' => $this->branch->id]))
        ->assertOk()
        ->viewData('metrics');

    expect($metrics['visits_month'])->toBe(1)
        ->and($metrics['medical_records_finalized_month'])->toBe(1);
});
