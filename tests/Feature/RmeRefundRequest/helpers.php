<?php

/*
| FEATURE-RME-BILLING-REFUND-REQUEST-APPROVAL-1 — fixtures of the refund request
| suites. Prefixed `rrr`; built on the `ric`/`rpp` fixtures, so the OPEN obligation
| every test refunds is produced by the REAL post-payment correction path (ledger,
| obligation and registry agree), never inserted by hand.
|
| Actors (RF-1): a Front Office maker with a working context at the fixture branch,
| a Supervisor RME, a Kepala Cabang pinned to the branch, and a Super Admin. The
| pilot (RF-3) admits the fixture branch and designates exactly these accounts, as
| activation would designate verified production accounts (RF-1a).
*/

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use App\Modules\RmeInvoice\Models\RmeOverpaymentObligation;
use App\Modules\RmeInvoice\Models\RmeRefundRequest;
use App\Modules\RmeInvoice\Services\RmeRefundRequestService;
use App\Modules\RmeInvoice\Support\RmeRefundRequestFeatureGuard;
use App\Modules\RmeInvoice\Support\RmeRefundRequestIntent;
use App\Modules\RmeInvoice\Support\RmeRefundRequestRefused;
use App\Modules\RmeInvoice\Support\RmeRefundRequestSnapshot;

require_once __DIR__.'/../RmeInvoiceCorrection/postpayment-helpers.php';

if (! function_exists('rrrSetup')) {
    function rrrFlag(bool $enabled): void
    {
        $flags = config('feature_flags.flags', []);
        $flags[RmeRefundRequestFeatureGuard::FLAG]['default'] = $enabled;
        $flags[RmeRefundRequestFeatureGuard::FLAG]['env_value'] = null;
        config()->set('feature_flags.flags', $flags);
    }

    /** @param  list<string>  $codes */
    function rrrPilot(array $codes): void
    {
        config()->set('rme_refund.pilot.branch_codes', $codes);
    }

    /**
     * @param  list<mixed>  $makers
     * @param  list<mixed>  $reviewers
     */
    function rrrIdentities(array $makers, array $reviewers): void
    {
        config()->set('rme_refund.pilot.identities', [
            'maker_user_ids' => $makers,
            'reviewer_user_ids' => $reviewers,
        ]);
    }

    function rrrSetup(): void
    {
        ricSetup();
        rppFlag(true);
        rrrFlag(true);
        rrrPilot(['IC01']);

        $t = test();
        $t->frontOffice = userInRole('Front Office');
        rmeMakeFrontOfficeActive($t->frontOffice, $t->branch);

        $t->supervisor = userInRole('Supervisor RME');
        $t->kepalaCabang = userInRole('Kepala Cabang');
        $t->kepalaCabang->forceFill(['branch_id' => $t->branch->id])->save();
        $t->superAdmin = userInRole('Super Admin');

        rrrIdentities(
            [(int) $t->frontOffice->id],
            [(int) $t->supervisor->id, (int) $t->kepalaCabang->id, (int) $t->superAdmin->id],
        );
    }

    /** Designate more accounts, keeping the current lists. */
    function rrrDesignate(array $makers = [], array $reviewers = []): void
    {
        $current = (array) config('rme_refund.pilot.identities', []);
        rrrIdentities(
            array_values(array_unique(array_merge($current['maker_user_ids'] ?? [], array_map(fn (User $u) => (int) $u->id, $makers)))),
            array_values(array_unique(array_merge($current['reviewer_user_ids'] ?? [], array_map(fn (User $u) => (int) $u->id, $reviewers)))),
        );
    }

    /**
     * A PAID 500.000 invoice corrected down to 350.000 through the real service:
     * one OPEN obligation of 150.000.
     *
     * @return array{0: RmeInvoice, 1: RmeOverpaymentObligation}
     */
    function rrrObligation(string $suffix = '70'): array
    {
        $invoice = rppPaidInvoice(500000);
        ricApprove(rppSubmit($invoice, reference: rppReference($suffix)));
        test()->actingAs(test()->frontOffice);

        $invoice = $invoice->fresh();
        $obligation = RmeOverpaymentObligation::query()->where('rme_invoice_id', $invoice->id)->firstOrFail();

        return [$invoice, $obligation];
    }

    function rrrOwnerReference(string $suffix = '01'): string
    {
        return 'OWNER-REF-RR-20261031-'.$suffix;
    }

    function rrrPatientReference(string $suffix = '01'): string
    {
        return 'FORM-PASIEN-RR-'.$suffix;
    }

    /** @return array<string, mixed> */
    function rrrData(RmeInvoice $invoice, RmeOverpaymentObligation $obligation, array $overrides = []): array
    {
        return array_merge([
            RmeRefundRequestIntent::FIELD => RmeRefundRequestIntent::issue((int) $obligation->id),
            'obligation_id' => (int) $obligation->id,
            'obligation_amount' => RmeRefundRequestSnapshot::decimal(RmeRefundRequestSnapshot::cents($obligation->amount)),
            'invoice_version' => (int) ($invoice->fresh()->correction_version ?? 0),
            'reason_note' => 'Pasien meminta pengembalian kelebihan bayar.',
            'patient_request_reference' => rrrPatientReference(),
            'owner_approval_reference' => rrrOwnerReference(),
        ], $overrides);
    }

    function rrrSubmit(RmeInvoice $invoice, RmeOverpaymentObligation $obligation, array $overrides = [], ?User $maker = null): RmeRefundRequest
    {
        return app(RmeRefundRequestService::class)->submit($invoice->fresh(), $maker ?? test()->frontOffice, rrrData($invoice, $obligation, $overrides));
    }

    function rrrApprove(RmeRefundRequest $request, ?User $reviewer = null, array $data = []): RmeRefundRequest
    {
        return app(RmeRefundRequestService::class)->approve($request->fresh(), $reviewer ?? test()->supervisor, $data);
    }

    function rrrReject(RmeRefundRequest $request, ?User $reviewer = null, string $note = 'Bukti permintaan pasien belum lengkap.'): RmeRefundRequest
    {
        return app(RmeRefundRequestService::class)->reject($request->fresh(), $reviewer ?? test()->supervisor, ['decision_note' => $note]);
    }

    function rrrCancel(RmeRefundRequest $request, ?User $actor = null, string $reason = 'Pasien membatalkan permintaannya.'): RmeRefundRequest
    {
        return app(RmeRefundRequestService::class)->cancel($request->fresh(), $actor ?? test()->frontOffice, ['cancel_reason' => $reason]);
    }

    /** Asserts a refusal with this exact code (or a 404 when $code is the not-found code). */
    function rrrRefused(callable $call, string $code): RmeRefundRequestRefused
    {
        try {
            $call();
        } catch (RmeRefundRequestRefused $refused) {
            expect($refused->reasonCode)->toBe($code);

            return $refused;
        }

        test()->fail("Expected refusal {$code}");
    }

    function rrrNotFound(callable $call): void
    {
        $refused = rrrRefused($call, RmeRefundRequestRefused::OBLIGATION_NOT_FOUND);
        expect($refused->notFound)->toBeTrue();
    }

    /** A second RME branch with its own Front Office maker, outside the pilot by default. */
    function rrrOtherBranch(string $code = 'IC02'): Branch
    {
        return Branch::factory()->create(['code' => $code, 'name' => 'Cabang Lain '.$code, 'is_rme_enabled' => true, 'is_active' => true]);
    }
}
