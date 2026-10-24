<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| IMPLEMENTATION-RME-BILLING-DISPUTE-ESCALATION-OPS-OQ-1 — a dispute is NOT a
| correction, and it widens no correction control (rule 203 × rules 195–202).
|--------------------------------------------------------------------------
|
| OPS-OQ-1 applies to every branch; the invoice-correction pilot stays SPN4
| only, maker 29 / checker 1. Reporting a dispute must never become a way to
| correct an invoice outside the pilot, around maker != checker, around the
| Rp0-original guard (D28), or with the correction feature switched off. And
| it works on every branch whether the correction flag is on or off — the
| hold depends on nothing the correction pilot decides.
*/

use App\Modules\Branch\Models\Branch;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use App\Modules\RmeInvoice\Services\RmeBillingDisputeService;
use App\Modules\RmeInvoice\Services\RmeInvoiceCorrectionService;
use App\Modules\RmeInvoice\Services\RmeInvoiceService;
use App\Modules\RmeInvoice\Support\RmeBillingDisputeOutcome;
use App\Modules\RmeInvoice\Support\RmeBillingDisputeStatus;
use App\Modules\RmeInvoice\Support\RmeInvoiceCorrectionDenied;
use App\Modules\RmeInvoice\Support\RmeInvoiceCorrectionStatus;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/helpers.php';

beforeEach(function () {
    rbdSetup();
    $this->branchB = Branch::factory()->create(['code' => 'IC02', 'name' => 'Cabang Non-Pilot', 'is_rme_enabled' => true, 'is_active' => true]);
});

function rbdZeroInvoice(): RmeInvoice
{
    return app(RmeInvoiceService::class)->create(ricVisit(), test()->cashier, [
        'items' => [
            ['description' => 'Kontrol pasca tindakan', 'qty' => 1, 'unit_price' => '0', 'discount' => '0', 'treatment_id' => test()->treatment->id],
        ],
    ])->refresh();
}

function rbdCorrectionRefused(callable $work): void
{
    try {
        $work();
    } catch (RmeInvoiceCorrectionDenied|ValidationException) {
        return;
    }

    test()->fail('The correction was accepted; a billing dispute must not widen correction authority.');
}

it('ships the correction pilot unchanged: SPN4 only, maker 29, checker 1', function () {
    $shipped = require base_path('config/rme_invoice_correction.php');

    expect($shipped['pilot']['branch_codes'])->toBe(['SPN4'])
        ->and($shipped['pilot']['identities']['maker_user_ids'])->toBe([29])
        ->and($shipped['pilot']['identities']['checker_user_ids'])->toBe([1]);
});

it('reports and holds on a branch outside the correction pilot, where correction stays refused', function () {
    $invoice = ricInvoice(ricVisit($this->branchB));

    $dispute = rbdReport($invoice);
    expect($dispute->status)->toBe(RmeBillingDisputeStatus::OPEN);
    rbdExpectDisputeBlocked(fn () => rbdPay($invoice, 100000));

    rbdCorrectionRefused(fn () => ricSubmit($invoice->fresh()));
    expect($invoice->fresh()->corrections()->count())->toBe(0);
});

it('works with the correction feature switched off, and correction stays off', function () {
    ricFlag(false);
    $invoice = ricInvoice();

    $dispute = rbdReport($invoice);
    rbdExpectDisputeBlocked(fn () => rbdPay($invoice, 100000));

    rbdCorrectionRefused(fn () => ricSubmit($invoice->fresh()));
    expect($dispute->fresh()->status)->toBe(RmeBillingDisputeStatus::OPEN);
});

it('keeps the Rp0-original guard (D28): a disputed Rp0 invoice is still refused correction', function () {
    $zero = rbdZeroInvoice();
    $dispute = rbdReport($zero);

    try {
        ricSubmit($zero->fresh(), test()->maker, [[
            'original_item_id' => $zero->items()->firstOrFail()->id,
            'treatment_id' => test()->treatment->id,
            'description' => 'Kontrol pasca tindakan',
            'qty' => 1, 'unit_price' => '150000', 'discount' => '0',
        ]]);
        test()->fail('A Rp0 original was corrected because it was disputed.');
    } catch (ValidationException $e) {
        expect(json_encode($e->errors(), JSON_UNESCAPED_UNICODE))->toContain('CORRECTION_ORIGINAL_TOTAL_ZERO');
    }

    expect($dispute->fresh()->status)->toBe(RmeBillingDisputeStatus::OPEN)
        ->and((float) $zero->fresh()->grand_total)->toBe(0.0);
});

it('keeps maker != checker: the maker cannot approve their own correction of a disputed invoice', function () {
    $invoice = ricInvoice();
    rbdReport($invoice);
    $correction = ricSubmit($invoice);

    rbdCorrectionRefused(fn () => ricApprove($correction, test()->maker));
    expect($correction->fresh()->status)->toBe(RmeInvoiceCorrectionStatus::SUBMITTED);
});

it('gives the dispute reviewer no correction power at all', function () {
    $invoice = ricInvoice();
    rbdReport($invoice);

    rbdCorrectionRefused(fn () => ricSubmit($invoice, test()->reviewer));
    expect($invoice->fresh()->corrections()->count())->toBe(0);

    $correction = ricSubmit($invoice);
    rbdCorrectionRefused(fn () => ricApprove($correction, test()->reviewer));
    expect($correction->fresh()->status)->toBe(RmeInvoiceCorrectionStatus::SUBMITTED)
        ->and((float) $invoice->fresh()->grand_total)->toBe(500000.0);
});

it('lets the legitimate pilot correction proceed on a disputed invoice, then resolves the dispute from it', function () {
    $invoice = ricInvoice();
    $dispute = rbdReport($invoice);
    $snapshot = $dispute->fresh()->only(['invoice_grand_total_at_report', 'invoice_status_at_report', 'invoice_correction_version_at_report']);

    $correction = ricApprove(ricSubmit($invoice)); // D1a: approval applies
    expect((float) $invoice->fresh()->grand_total)->toBe(350000.0);

    test()->actingAs(test()->reviewer);
    $resolved = app(RmeBillingDisputeService::class)->resolve($dispute->fresh(), test()->reviewer, [
        'outcome' => RmeBillingDisputeOutcome::CORRECTION_APPLIED,
        'resolution_reason' => 'Koreksi pilot sudah diterapkan oleh pemeriksa.',
    ])->fresh();

    expect($resolved->status)->toBe(RmeBillingDisputeStatus::RESOLVED)
        ->and((int) $resolved->resolution_correction_id)->toBe((int) $correction->id)
        ->and($resolved->only(array_keys($snapshot)))->toBe($snapshot);

    rbdPay($invoice, 350000);
    expect($invoice->fresh()->status)->toBe(RmeInvoice::STATUS_PAID);
});

it('lets a dispute be reported while a correction is already in flight, and holds through its rejection', function () {
    $invoice = ricInvoice();
    $correction = ricSubmit($invoice);

    $dispute = rbdReport($invoice);
    expect($dispute->status)->toBe(RmeBillingDisputeStatus::OPEN);

    ricActingAs(test()->checker);
    app(RmeInvoiceCorrectionService::class)
        ->reject($correction->fresh(), test()->checker, 'Usulan koreksi tidak didukung bukti.');

    rbdExpectDisputeBlocked(fn () => rbdPay($invoice, 100000));
});
