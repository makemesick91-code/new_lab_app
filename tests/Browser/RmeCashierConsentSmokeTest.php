<?php

namespace Tests\Browser;

use Laravel\Dusk\Browser;
use Tests\Browser\Concerns\InteractsWithRmeSmoke;
use Tests\DuskTestCase;

class RmeCashierConsentSmokeTest extends DuskTestCase
{
    use InteractsWithRmeSmoke;

    public function test_rme_payment_requires_consent_checkboxes(): void
    {
        $createdInvoiceId = null;

        try {
            $target = $this->resolveUnpaidCashierTarget();

            if (! $target) {
                $this->markTestSkipped('No unpaid RME invoice fixture available for cashier consent smoke test.');
            }

            $visit = $target['visit'];
            $invoice = $target['invoice'];

            if ($target['created_by_test']) {
                $createdInvoiceId = $invoice->id;
            }

            $this->browse(function (Browser $browser) use ($visit, $invoice) {
                $this->loginAsAdmin($browser)
                    ->visit(route('rme.cashier.payment.create', [$visit, $invoice]))
                    ->waitForText('Pembayaran Tagihan RME', 10)
                    // SUPERSEDED by FIX-RME-EXAM-CONSENT-ODONTOGRAM-HISTORY-3 /
                    // FIX-03. This smoke used to drive a consent-BLOCKED payment.
                    // Consent is no longer a payment condition, so the page must
                    // carry no consent verification panel and no consent
                    // checkboxes, and a payment must not be refused for consent.
                    ->assertDontSee('Verifikasi Surat Persetujuan Tindakan')
                    ->assertMissing('input[name="consent_signed_by_patient"]')
                    ->assertMissing('input[name="consent_signed_by_doctor"]')
                    ->assertDontSee('Pembayaran tidak dapat diproses karena Surat Persetujuan Tindakan');

                $this->assertRmePageHealthy($browser);

                $browser->screenshot('rme-cashier-no-consent-gate-smoke');
            });
        } finally {
            $this->cleanupSmokeTestInvoice($createdInvoiceId);
        }
    }
}
