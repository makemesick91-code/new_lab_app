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
                    ->assertSee('Verifikasi Surat Persetujuan Tindakan')
                    ->assertPresent('input[name="consent_signed_by_patient"]')
                    ->assertPresent('input[name="consent_signed_by_doctor"]')
                    ->click('button[type="submit"]')
                    ->waitForText('Pembayaran tidak dapat diproses karena Surat Persetujuan Tindakan', 10)
                    ->assertSee('Pembayaran tidak dapat diproses karena Surat Persetujuan Tindakan');

                $this->assertRmePageHealthy($browser);

                $browser->screenshot('rme-cashier-consent-blocked-smoke');
            });
        } finally {
            $this->cleanupSmokeTestInvoice($createdInvoiceId);
        }
    }
}
