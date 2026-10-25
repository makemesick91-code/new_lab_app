<?php

/*
| FEATURE-RME-POSTPAYMENT-INVOICE-CORRECTION-1 — fixtures of the post-payment
| correction suites. Prefixed `rpp`; built on the `ric` fixtures (same branch,
| actors, pilot allowlist and canonical 500.000 invoice).
*/

use App\Models\User;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use App\Modules\RmeInvoice\Models\RmeInvoiceCorrection;
use App\Modules\RmeInvoice\Services\RmeInvoiceCorrectionService;
use App\Modules\RmeInvoice\Support\RmeInvoicePostPaymentCorrectionFeatureGuard;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/helpers.php';

if (! function_exists('rppFlag')) {
    /** The second switch (PP-1). The main switch is set by ricSetup(). */
    function rppFlag(bool $enabled): void
    {
        $flags = config('feature_flags.flags', []);
        $flags[RmeInvoicePostPaymentCorrectionFeatureGuard::FLAG]['default'] = $enabled;
        $flags[RmeInvoicePostPaymentCorrectionFeatureGuard::FLAG]['env_value'] = null;
        config()->set('feature_flags.flags', $flags);
    }

    /** A rule 202 reference shape. It refers to no real form. */
    function rppReference(string $suffix = '01'): string
    {
        return 'OWNER-COR-IC-20261025-'.$suffix;
    }

    /** The canonical 500.000 invoice with $amount received through the payment service. */
    function rppPaidInvoice(float $amount = 500000): RmeInvoice
    {
        $invoice = ricInvoice();
        ricPay($invoice, $amount);

        return $invoice->fresh();
    }

    /**
     * @param  list<array<string, mixed>>|null  $lines
     * @param  array<string, mixed>  $extra
     */
    function rppSubmit(RmeInvoice $invoice, ?array $lines = null, ?string $reference = 'default', array $extra = [], ?User $maker = null): RmeInvoiceCorrection
    {
        $maker ??= test()->maker;
        test()->actingAs($maker);

        $data = array_merge([
            'reason' => 'Harga tambal salah input oleh kasir.',
            'lines' => $lines ?? ricLinesReducing($invoice->fresh()),
        ], $extra);

        if ($reference !== null) {
            $data['owner_approval_reference'] = $reference === 'default' ? rppReference() : $reference;
        }

        return app(RmeInvoiceCorrectionService::class)->submit($invoice->fresh(), $maker, $data);
    }

    /**
     * Lines for the canonical invoice with explicit prices (Scaling, Tambal).
     *
     * @return list<array<string, mixed>>
     */
    function rppLines(RmeInvoice $invoice, string $scaling, string $tambal): array
    {
        $lines = ricLinesReducing($invoice->fresh(), $tambal);
        $lines[0]['unit_price'] = $scaling;

        return $lines;
    }

    function rppExpectRefusal(callable $call, string $code): void
    {
        try {
            $call();
            test()->fail("Expected refusal {$code}");
        } catch (ValidationException $e) {
            expect(json_encode($e->errors()))->toContain($code);
        }
    }
}
