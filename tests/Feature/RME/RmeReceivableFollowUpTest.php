<?php

// Sprint 24 Phase 24.8 — RME Receivable Follow-up / Reminder Foundation tests

use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use App\Modules\RmeInvoice\Models\RmeReceivableFollowUp;
use Database\Seeders\BranchSeeder;

beforeEach(function () {
    test()->seed(BranchSeeder::class);
    seedAccessControl();

    Branch::where('code', Branch::MAIN_CODE)->update(['is_rme_enabled' => false]);
    $this->branch = Branch::factory()->create(['code' => 'ATG8', 'is_rme_enabled' => true]);
    $this->cashier = userWith(['manage_rme_billing']);
    $this->unauthorized = userWith(['view_clinic_visits']);
});

function makeFollowUpInvoice(Branch $branch, $cashier, string $status = RmeInvoice::STATUS_UNPAID): RmeInvoice
{
    $visit = ClinicVisit::factory()->cashierPending()->create(['branch_id' => $branch->id]);

    return RmeInvoice::factory()->create([
        'branch_id' => $branch->id,
        'clinic_visit_id' => $visit->id,
        'patient_id' => $visit->patient_id,
        'cashier_id' => $cashier->id,
        'status' => $status,
        'subtotal' => 300000,
        'grand_total' => 300000,
    ]);
}

it('cashier can open follow-up form for an unpaid invoice', function () {
    $this->actingAs($this->cashier);
    $invoice = makeFollowUpInvoice($this->branch, $this->cashier);

    $this->get(route('rme.cashier.receivables.follow-ups.create', $invoice))
        ->assertOk()
        ->assertSee('Tambah Follow-up Piutang')
        ->assertSee($invoice->invoice_number);
});

it('cashier can open follow-up form for a partial invoice', function () {
    $this->actingAs($this->cashier);
    $invoice = makeFollowUpInvoice($this->branch, $this->cashier, RmeInvoice::STATUS_PARTIAL);

    $this->get(route('rme.cashier.receivables.follow-ups.create', $invoice))
        ->assertOk();
});

it('cashier can store a follow-up for an unpaid invoice', function () {
    $this->actingAs($this->cashier);
    $invoice = makeFollowUpInvoice($this->branch, $this->cashier);

    $this->post(route('rme.cashier.receivables.follow-ups.store', $invoice), [
        'status' => RmeReceivableFollowUp::STATUS_CONTACTED,
        'channel' => RmeReceivableFollowUp::CHANNEL_WHATSAPP,
        'contacted_at' => now()->toDateTimeString(),
        'next_follow_up_date' => now()->addDays(3)->toDateString(),
        'note' => 'Pasien minta diingatkan minggu depan',
    ])->assertRedirect(route('rme.cashier.receivables'));

    $this->assertDatabaseHas('trx_rme_receivable_follow_ups', [
        'rme_invoice_id' => $invoice->id,
        'branch_id' => $this->branch->id,
        'user_id' => $this->cashier->id,
        'status' => RmeReceivableFollowUp::STATUS_CONTACTED,
        'channel' => RmeReceivableFollowUp::CHANNEL_WHATSAPP,
    ]);
});

it('latest follow-up summary appears on the receivables page', function () {
    $this->actingAs($this->cashier);
    $invoice = makeFollowUpInvoice($this->branch, $this->cashier);

    RmeReceivableFollowUp::factory()->create([
        'rme_invoice_id' => $invoice->id,
        'branch_id' => $this->branch->id,
        'user_id' => $this->cashier->id,
        'status' => RmeReceivableFollowUp::STATUS_PROMISED,
        'next_follow_up_date' => now()->addDays(5)->toDateString(),
    ]);

    $this->get(route('rme.cashier.receivables'))
        ->assertOk()
        ->assertSee('Janji Bayar')
        ->assertSee('Terjadwal');
});

it('shows a due indicator when next follow-up date is today or past', function () {
    $this->actingAs($this->cashier);
    $invoice = makeFollowUpInvoice($this->branch, $this->cashier);

    RmeReceivableFollowUp::factory()->create([
        'rme_invoice_id' => $invoice->id,
        'branch_id' => $this->branch->id,
        'user_id' => $this->cashier->id,
        'status' => RmeReceivableFollowUp::STATUS_CONTACTED,
        'next_follow_up_date' => now()->subDay()->toDateString(),
    ]);

    $this->get(route('rme.cashier.receivables'))
        ->assertOk()
        ->assertSee('Jatuh Tempo');
});

it('cannot store a follow-up for a paid invoice', function () {
    $this->actingAs($this->cashier);
    $invoice = makeFollowUpInvoice($this->branch, $this->cashier, RmeInvoice::STATUS_PAID);

    $this->post(route('rme.cashier.receivables.follow-ups.store', $invoice), [
        'status' => RmeReceivableFollowUp::STATUS_CONTACTED,
    ])->assertForbidden();

    $this->assertDatabaseCount('trx_rme_receivable_follow_ups', 0);
});

it('unauthorized user cannot create a follow-up', function () {
    $this->actingAs($this->unauthorized);
    $invoice = makeFollowUpInvoice($this->branch, $this->cashier);

    $this->get(route('rme.cashier.receivables.follow-ups.create', $invoice))
        ->assertForbidden();
});

it('cannot follow up an invoice from a non-RME branch', function () {
    $this->actingAs($this->cashier);
    $otherBranch = Branch::factory()->create(['is_active' => true, 'is_rme_enabled' => false]);
    $invoice = makeFollowUpInvoice($otherBranch, $this->cashier);

    $this->get(route('rme.cashier.receivables.follow-ups.create', $invoice))
        ->assertForbidden();
});

it('validation rejects an invalid status', function () {
    $this->actingAs($this->cashier);
    $invoice = makeFollowUpInvoice($this->branch, $this->cashier);

    $this->post(route('rme.cashier.receivables.follow-ups.store', $invoice), [
        'status' => 'BOGUS_STATUS',
    ])->assertSessionHasErrors('status');
});
