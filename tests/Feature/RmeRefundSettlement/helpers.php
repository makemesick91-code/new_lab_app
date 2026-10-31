<?php

/*
| FEATURE-RME-REFUND-SETTLEMENT-1 (Sprint 5/7, rule 216) — fixtures of the refund
| settlement suites. Prefixed `rs`; built on the `rrr` fixtures, so the APPROVED
| request every test settles is produced by the REAL post-payment correction and the
| REAL refund request service (ledger, obligation, request and registries agree),
| never inserted by hand.
|
| Settlers are TEST-ONLY fixtures: the Owner has not designated a production settler
| (RF-10 actor PENDING), so production ships an EMPTY designation list. Here a Kasir
| account is given `settle_rme_refund` directly and designated for the fixture branch,
| exactly as an activation would designate one verified production account.
*/

use App\Models\User;
use App\Modules\RmeInvoice\Models\RmeRefundRequest;
use App\Modules\RmeInvoice\Models\RmeRefundSettlement;
use App\Modules\RmeInvoice\Services\RmeRefundSettlementService;
use App\Modules\RmeInvoice\Support\RmeRefundRequestSnapshot;
use App\Modules\RmeInvoice\Support\RmeRefundSettlementEvidence;
use App\Modules\RmeInvoice\Support\RmeRefundSettlementFeatureGuard;
use App\Modules\RmeInvoice\Support\RmeRefundSettlementIntent;
use App\Modules\RmeInvoice\Support\RmeRefundSettlementMethod;
use App\Modules\RmeInvoice\Support\RmeRefundSettlementRefused;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/../RmeRefundRequest/helpers.php';

if (! function_exists('rsSetup')) {
    function rsFlag(bool $enabled): void
    {
        $flags = config('feature_flags.flags', []);
        $flags[RmeRefundSettlementFeatureGuard::FLAG]['default'] = $enabled;
        $flags[RmeRefundSettlementFeatureGuard::FLAG]['env_value'] = null;
        config()->set('feature_flags.flags', $flags);
    }

    /** @param  list<string>  $codes */
    function rsPilot(array $codes): void
    {
        config()->set('rme_refund_settlement.pilot.branch_codes', $codes);
    }

    /** @param  list<mixed>  $entries */
    function rsSettlers(array $entries): void
    {
        config()->set('rme_refund_settlement.pilot.settlers', $entries);
    }

    /** A test-only settler: any role, granted the permission directly, designated for the codes. */
    function rsMakeSettler(string $role = 'Kasir', array $codes = ['IC01']): User
    {
        $user = userInRole($role);
        $user->givePermissionTo(RmeRefundSettlementService::PERMISSION_SETTLE);
        rsDesignate($user, $codes);

        return $user;
    }

    function rsDesignate(User $user, array $codes = ['IC01']): void
    {
        $entries = (array) config('rme_refund_settlement.pilot.settlers', []);
        $entries[] = ['user_id' => (int) $user->id, 'branch_codes' => $codes];
        rsSettlers($entries);
    }

    function rsSetup(): void
    {
        rrrSetup();
        rsFlag(true);
        rsPilot(['IC01']);
        rsSettlers([]);
        Storage::fake(RmeRefundSettlementEvidence::DISK);

        $t = test();
        $t->settler = rsMakeSettler('Kasir');
        $t->settler2 = rsMakeSettler('Kasir');
    }

    /**
     * An APPROVED refund request of 150.000 (approved by the Supervisor RME) on a
     * PAID invoice corrected from 500.000 to 350.000.
     */
    function rsApproved(string $suffix = '70'): RmeRefundRequest
    {
        [$invoice, $obligation] = rrrObligation($suffix);
        $request = rrrSubmit($invoice, $obligation, [
            'owner_approval_reference' => rrrOwnerReference($suffix),
            'patient_request_reference' => rrrPatientReference($suffix),
        ]);

        return rrrApprove($request);
    }

    /** @return array<string, mixed> */
    function rsStartData(RmeRefundRequest $request, string $method = RmeRefundSettlementMethod::BANK_TRANSFER, array $overrides = []): array
    {
        return array_merge([
            RmeRefundSettlementIntent::FIELD => RmeRefundSettlementIntent::issue((int) $request->id),
            'method' => $method,
            'amount' => RmeRefundRequestSnapshot::decimal(RmeRefundRequestSnapshot::cents($request->amount)),
        ], $overrides);
    }

    function rsStart(RmeRefundRequest $request, ?User $settler = null, string $method = RmeRefundSettlementMethod::BANK_TRANSFER, array $overrides = []): RmeRefundSettlement
    {
        return app(RmeRefundSettlementService::class)->start($request->fresh(), $settler ?? test()->settler, rsStartData($request, $method, $overrides));
    }

    /** A small but genuine PDF (finfo detects application/pdf). */
    function rsPdf(string $name = 'bukti-transfer.pdf', string $salt = 'a'): UploadedFile
    {
        $body = "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\n% evidence ".$salt."\ntrailer<</Root 1 0 R>>\n%%EOF\n";

        return UploadedFile::fake()->createWithContent($name, $body);
    }

    /** A 1x1 PNG. */
    function rsPng(string $name = 'tanda-terima.png'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='));
    }

    /** @return array<string, mixed> */
    function rsBankConfirmData(array $overrides = []): array
    {
        return array_merge([
            'disbursement_reference' => 'TRF-BCA-20261031-0001',
            'destination_bank_name' => 'BCA',
            'destination_account_last4' => '4321',
            'attest_disbursed' => '1',
        ], $overrides);
    }

    /** @return array<string, mixed> */
    function rsCashConfirmData(array $overrides = []): array
    {
        return array_merge([
            'disbursement_reference' => 'TT-REFUND-SPN4-0001',
            'recipient_relation' => 'PATIENT',
            'attest_disbursed' => '1',
        ], $overrides);
    }

    function rsConfirm(RmeRefundSettlement $settlement, ?User $settler = null, ?array $data = null, ?UploadedFile $evidence = null): RmeRefundSettlement
    {
        $settlement = $settlement->fresh();
        $data ??= $settlement->method === RmeRefundSettlementMethod::CASH ? rsCashConfirmData() : rsBankConfirmData();

        return app(RmeRefundSettlementService::class)->confirm($settlement, $settler ?? test()->settler, $data, $evidence ?? rsPdf());
    }

    function rsUnknown(RmeRefundSettlement $settlement, ?User $settler = null, string $note = 'Bank menampilkan status transfer tertunda.'): RmeRefundSettlement
    {
        return app(RmeRefundSettlementService::class)->reportUnknown($settlement->fresh(), $settler ?? test()->settler, ['outcome_note' => $note]);
    }

    function rsAbort(RmeRefundSettlement $settlement, ?User $settler = null, ?UploadedFile $evidence = null, array $overrides = []): RmeRefundSettlement
    {
        return app(RmeRefundSettlementService::class)->abort($settlement->fresh(), $settler ?? test()->settler, array_merge([
            'abort_reason' => 'Pembayaran belum dilakukan; dibatalkan oleh pembayar.',
            'attest_not_disbursed' => '1',
        ], $overrides), $evidence);
    }

    /** Asserts a refusal with this exact code. */
    function rsRefused(callable $call, string $code): RmeRefundSettlementRefused
    {
        try {
            $call();
        } catch (RmeRefundSettlementRefused $refused) {
            expect($refused->reasonCode)->toBe($code);

            return $refused;
        }

        test()->fail("Expected settlement refusal {$code}");
    }

    function rsNotFound(callable $call): void
    {
        $refused = rsRefused($call, RmeRefundSettlementRefused::NOT_FOUND);
        expect($refused->notFound)->toBeTrue();
    }

    /**
     * The ledger fingerprint of RFI-8/RFS-5: what a settlement must never change.
     *
     * @return array<string, mixed>
     */
    function rsLedgerFingerprint(): array
    {
        return [
            'payments' => DB::table('trx_rme_payments')->selectRaw('COUNT(*) AS c, COALESCE(SUM(amount),0) AS s, COALESCE(MAX(id),0) AS m')->first(),
            'payment_rows' => DB::table('trx_rme_payments')->orderBy('id')->get(['id', 'amount', 'paid_at', 'rme_invoice_id', 'branch_id'])->toArray(),
            'invoices' => DB::table('trx_rme_invoices')->orderBy('id')->get(['id', 'status', 'grand_total', 'correction_version'])->toArray(),
            'items' => DB::table('trx_rme_invoice_items')->orderBy('id')->get(['id', 'subtotal', 'discount'])->toArray(),
            'receipts' => DB::table('trx_rme_receipt_snapshots')->count(),
            'versions' => DB::table('trx_rme_invoice_document_versions')->count(),
            'corrections' => DB::table('trx_rme_invoice_corrections')->orderBy('id')->get(['id', 'status'])->toArray(),
        ];
    }
}
