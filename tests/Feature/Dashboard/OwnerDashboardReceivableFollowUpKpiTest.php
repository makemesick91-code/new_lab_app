<?php

use App\Modules\Branch\Models\Branch;
use App\Modules\Reporting\Services\OwnerDashboardRmeLabDrilldownService;
use App\Modules\Reporting\Services\OwnerDashboardRmeLabKpiService;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use App\Modules\RmeInvoice\Models\RmeReceivableFollowUp;
use Database\Seeders\BranchSeeder;

beforeEach(function () {
    seedAccessControl();
    test()->seed(BranchSeeder::class);
    $this->branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $this->service = app(OwnerDashboardRmeLabKpiService::class);
});

function receivableWithFollowUp(
    Branch $branch,
    string $invoiceState,
    ?string $nextDate,
    string $followUpStatus = RmeReceivableFollowUp::STATUS_CONTACTED,
): RmeInvoice {
    $invoice = RmeInvoice::factory()->{$invoiceState}()->create(['branch_id' => $branch->id]);

    RmeReceivableFollowUp::factory()->create([
        'rme_invoice_id' => $invoice->id,
        'branch_id' => $branch->id,
        'status' => $followUpStatus,
        'next_follow_up_date' => $nextDate,
    ]);

    return $invoice;
}

it('shows the follow-up KPI labels on the Owner Dashboard', function () {
    $this->actingAs(userInRole('Owner'))
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Follow-up Jatuh Tempo')
        ->assertSee('Follow-up Hari Ini')
        ->assertSee('Belum Pernah Follow-up')
        ->assertSee('Follow-up Terjadwal');
});

it('counts overdue follow-up only for active receivables with latest next date before today', function () {
    receivableWithFollowUp($this->branch, 'unpaid', now()->subDay()->toDateString());
    receivableWithFollowUp($this->branch, 'partial', now()->addDay()->toDateString());

    expect($this->service->metrics()['rme_receivable_follow_up_overdue_count'])->toBe(1);
});

it('counts today follow-up only for active receivables with latest next date today', function () {
    receivableWithFollowUp($this->branch, 'unpaid', now()->toDateString());
    receivableWithFollowUp($this->branch, 'partial', now()->subDay()->toDateString());

    expect($this->service->metrics()['rme_receivable_follow_up_today_count'])->toBe(1);
});

it('counts scheduled follow-up only for active receivables with latest next date after today', function () {
    receivableWithFollowUp($this->branch, 'unpaid', now()->addDays(5)->toDateString());
    receivableWithFollowUp($this->branch, 'partial', now()->toDateString());

    expect($this->service->metrics()['rme_receivable_follow_up_scheduled_count'])->toBe(1);
});

it('counts never-followed-up only for active receivables without any follow-up', function () {
    RmeInvoice::factory()->unpaid()->create(['branch_id' => $this->branch->id]);
    receivableWithFollowUp($this->branch, 'partial', now()->addDay()->toDateString());

    expect($this->service->metrics()['rme_receivable_never_followed_up_count'])->toBe(1);
});

it('excludes PAID invoices from follow-up KPI counts', function () {
    $paid = RmeInvoice::factory()->paid()->create(['branch_id' => $this->branch->id]);
    RmeReceivableFollowUp::factory()->create([
        'rme_invoice_id' => $paid->id,
        'branch_id' => $this->branch->id,
        'next_follow_up_date' => now()->subDay()->toDateString(),
    ]);

    RmeInvoice::factory()->paid()->create(['branch_id' => $this->branch->id]);

    $metrics = $this->service->metrics();

    expect($metrics['rme_receivable_follow_up_overdue_count'])->toBe(0)
        ->and($metrics['rme_receivable_never_followed_up_count'])->toBe(0);
});

it('scopes follow-up KPI counts by the Owner branch filter', function () {
    $otherBranch = Branch::factory()->create([
        'code' => 'OWN-FU-B',
        'name' => 'Cabang Follow-up B',
        'is_active' => true,
    ]);

    receivableWithFollowUp($this->branch, 'unpaid', now()->subDay()->toDateString());
    receivableWithFollowUp($otherBranch, 'unpaid', now()->subDay()->toDateString());

    $global = $this->service->metrics();
    $mainOnly = $this->service->metrics($this->branch->id);
    $otherOnly = $this->service->metrics($otherBranch->id);

    expect($global['rme_receivable_follow_up_overdue_count'])->toBe(2)
        ->and($mainOnly['rme_receivable_follow_up_overdue_count'])->toBe(1)
        ->and($otherOnly['rme_receivable_follow_up_overdue_count'])->toBe(1);
});

it('points follow-up shortcuts to the Piutang RME route with filters', function () {
    // Drilldowns are gated by manage_rme_billing (Kasir), mirroring the existing
    // rme_receivables shortcut. Owner without billing access simply sees no link.
    $links = app(OwnerDashboardRmeLabDrilldownService::class)->linksFor(userInRole('Kasir'));

    expect($links['rme_receivable_follow_up_overdue'])->toBe(route('rme.cashier.receivables', ['follow_up_filter' => 'overdue']))
        ->and($links['rme_receivable_follow_up_today'])->toBe(route('rme.cashier.receivables', ['follow_up_filter' => 'today']))
        ->and($links['rme_receivable_never_followed_up'])->toBe(route('rme.cashier.receivables', ['follow_up_filter' => 'never']))
        ->and($links['rme_receivable_follow_up_scheduled'])->toBe(route('rme.cashier.receivables', ['follow_up_filter' => 'scheduled']));
});
