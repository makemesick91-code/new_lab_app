<?php

/*
| IMPLEMENTATION-RME-BILLING-DISPUTE-ESCALATION-OPS-OQ-1 (rule 203) — shared
| fixtures of the billing dispute suites. Every helper is prefixed `rbd`. They
| build on the invoice-correction fixtures (`ric*`): the same branch, patient,
| treatments and canonical 500.000 invoice, so the dispute suites exercise the
| real invoice, payment and correction services, never a stand-in.
*/

use App\Models\User;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\LabOrder\Models\AuditLog;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\RmeInvoice\Models\RmeBillingDispute;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use App\Modules\RmeInvoice\Services\RmeBillingDisputeService;
use App\Modules\RmeInvoice\Services\RmePaymentService;
use App\Modules\RmeInvoice\Support\RmeBillingDisputeOutcome;
use App\Modules\RmeInvoice\Support\RmeBillingDisputeReason;
use App\Modules\RmeInvoice\Support\RmeInvoicePaymentDisputed;
use App\Modules\RmeOnlineContext\Middleware\EnsureRmeOnlineContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/../RmeInvoiceCorrection/helpers.php';

if (! function_exists('rbdSetup')) {
    /**
     * The correction fixture (branch IC01, patient, cashier, maker, checker…)
     * plus a dispute REVIEWER (the new review permission only) and a read-only
     * VIEWER (Owner-style). The cashier is the reporter; none of these accounts
     * is context-bound, so their working scope is every active RME branch.
     */
    function rbdSetup(): void
    {
        ricSetup();

        $t = test();
        $t->reviewer = userWith(['review_rme_billing_disputes', 'view_clinic_visits']);
        $t->viewer = userWith(['view_rme_billing_disputes', 'view_clinic_visits']);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    function rbdReport(RmeInvoice $invoice, ?User $reporter = null, array $data = []): RmeBillingDispute
    {
        $reporter ??= test()->cashier;
        test()->actingAs($reporter);

        return app(RmeBillingDisputeService::class)->report($invoice->fresh(), $reporter, array_merge([
            'reason_category' => RmeBillingDisputeReason::AMOUNT_INCORRECT,
            'description' => 'Harga tambal tertulis 200.000, seharusnya 50.000.',
        ], $data));
    }

    function rbdConfirmOriginal(RmeBillingDispute $dispute, ?User $resolver = null): RmeBillingDispute
    {
        $resolver ??= test()->reviewer;
        test()->actingAs($resolver);

        return app(RmeBillingDisputeService::class)->resolve($dispute->fresh(), $resolver, [
            'outcome' => RmeBillingDisputeOutcome::ORIGINAL_CONFIRMED_CORRECT,
            'resolution_reason' => 'Dicek dengan nota tindakan: harga sudah benar.',
            'resolution_reference' => 'OWNER-OK/2026-10-24/001',
        ]);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    function rbdPay(RmeInvoice $invoice, float $amount, array $extra = [], ?User $cashier = null): mixed
    {
        $cashier ??= test()->cashier;
        test()->actingAs($cashier);

        return app(RmePaymentService::class)->pay($invoice->fresh(), $cashier, rmeFreshPaymentForm($invoice->fresh(), array_merge([
            'amount' => $amount,
            'paid_at' => now()->format('Y-m-d H:i:s'),
        ], $extra)));
    }

    function rbdExpectDisputeBlocked(callable $work): void
    {
        try {
            $work();
        } catch (ValidationException $e) {
            $errors = $e->errors();
            expect($errors)->toHaveKey(RmePaymentService::PAYMENT_BLOCKED_FIELD);
            $message = implode(' ', $errors[RmePaymentService::PAYMENT_BLOCKED_FIELD]);
            expect($message)->toContain('['.RmeInvoicePaymentDisputed::REASON.']')
                ->and($message)->toContain('Pembayaran sementara tidak dapat diproses');

            return;
        }

        test()->fail('The payment was accepted; the active billing dispute should have held it.');
    }

    /**
     * A carry-over chain: a parent visit invoice and a CONTROL visit invoice.
     *
     * @return array{0: RmeInvoice, 1: RmeInvoice}
     */
    function rbdControlChain(): array
    {
        $parentVisit = ricVisit();
        $parent = ricInvoice($parentVisit);

        $controlVisit = ClinicVisit::factory()->cashierPending()->create([
            'branch_id' => test()->branch->id,
            'clinic_id' => test()->clinic->id,
            'patient_id' => test()->patient->id,
            'doctor_id' => test()->doctor->id,
            'visit_type' => ClinicVisit::VISIT_TYPE_CONTROL,
            'follow_up_of_visit_id' => $parentVisit->id,
            'visit_number' => 'VIS-RBD-CTL-'.uniqid(),
            'visit_date' => clinicalToday()->toDateString(),
        ]);
        MedicalRecord::factory()->final()->create([
            'clinic_visit_id' => $controlVisit->id, 'branch_id' => test()->branch->id,
            'patient_id' => test()->patient->id, 'doctor_id' => test()->doctor->id,
        ]);
        rmeSignedConsentFor($controlVisit);

        return [$parent, ricInvoice($controlVisit)];
    }

    /** @return Collection<int, AuditLog> */
    function rbdAudit(string $action): Collection
    {
        return AuditLog::query()->where('action', $action)->orderBy('id')->get();
    }

    function rbdActingAs(User $user): void
    {
        test()->actingAs($user)->withoutMiddleware(EnsureRmeOnlineContext::class);
    }

    /**
     * The financial ledger an invoice dispute must NEVER change: invoices,
     * items, payments, corrections, document versions and visits, with the
     * money columns summed.
     *
     * @return array<string, mixed>
     */
    function rbdLedger(): array
    {
        return [
            'invoices' => DB::table('trx_rme_invoices')->orderBy('id')->get(['id', 'status', 'grand_total', 'subtotal', 'discount_total', 'correction_version', 'branch_id'])->map(fn ($r) => (array) $r)->all(),
            'items' => DB::table('trx_rme_invoice_items')->orderBy('id')->get(['id', 'rme_invoice_id', 'unit_price', 'discount', 'subtotal', 'qty'])->map(fn ($r) => (array) $r)->all(),
            'payments' => DB::table('trx_rme_payments')->orderBy('id')->get(['id', 'rme_invoice_id', 'amount'])->map(fn ($r) => (array) $r)->all(),
            'corrections' => DB::table('trx_rme_invoice_corrections')->orderBy('id')->get(['id', 'status'])->map(fn ($r) => (array) $r)->all(),
            'document_versions' => DB::table('trx_rme_invoice_document_versions')->count(),
            'visits' => DB::table('trx_clinic_visits')->orderBy('id')->get(['id', 'status', 'branch_id'])->map(fn ($r) => (array) $r)->all(),
            'medical_records' => DB::table('trx_medical_records')->orderBy('id')->get(['id', 'status', 'doctor_id'])->map(fn ($r) => (array) $r)->all(),
        ];
    }
}
