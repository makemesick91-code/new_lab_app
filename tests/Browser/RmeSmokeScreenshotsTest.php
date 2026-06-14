<?php

namespace Tests\Browser;

use Laravel\Dusk\Browser;
use Tests\Browser\Concerns\InteractsWithRmeSmoke;
use Tests\DuskTestCase;

class RmeSmokeScreenshotsTest extends DuskTestCase
{
    use InteractsWithRmeSmoke;

    public function test_collects_rme_smoke_screenshots(): void
    {
        $createdInvoiceId = null;
        $createdOdontogramId = null;

        try {
            $visit = $this->resolveClinicVisit();
            $odontogramTarget = $this->resolveClinicVisitWithOdontogram();
            $cashierTarget = $this->resolveUnpaidCashierTarget();

            $odontogramVisit = $odontogramTarget['visit'] ?? null;
            $createdOdontogramId = $odontogramTarget['created_odontogram_id'] ?? null;

            if ($cashierTarget !== null && $cashierTarget['created_by_test']) {
                $createdInvoiceId = $cashierTarget['invoice']->id;
            }

            $this->browse(function (Browser $browser) use ($visit, $odontogramVisit, $cashierTarget) {
                $this->loginAsAdmin($browser);

                $browser->visit(route('rme.visits.index'))
                    ->waitForText('Kunjungan Pasien', 10);
                $this->assertRmePageHealthy($browser);
                $browser->screenshot('rme-smoke-collector-index');

                $browser->visit(route('rme.visits.create'))
                    ->waitForText('Daftar Kunjungan Baru', 10);
                $this->assertRmePageHealthy($browser);
                $browser->screenshot('rme-smoke-collector-create');

                if ($visit) {
                    $browser->visit(route('rme.visits.show', $visit))
                        ->waitForText('Informasi Kunjungan', 10);
                    $this->assertRmePageHealthy($browser);
                    $browser->screenshot('rme-smoke-collector-detail');

                    $browser->visit(route('rme.visits.print', $visit))
                        ->waitForText('Kunjungan', 10);
                    $this->assertRmePageHealthy($browser);
                    $browser->screenshot('rme-smoke-collector-print');
                }

                if ($odontogramVisit) {
                    $browser->visit(route('rme.visits.odontogram.show', $odontogramVisit))
                        ->waitForText('Odontogram', 10);
                    $this->assertRmePageHealthy($browser);
                    $browser->screenshot('rme-smoke-collector-odontogram');
                }

                $browser->visit(route('rme.cashier.index'))
                    ->waitForText('Kasir RME', 10);
                $this->assertRmePageHealthy($browser);
                $browser->screenshot('rme-smoke-collector-cashier-index');

                if ($cashierTarget) {
                    $cashierVisit = $cashierTarget['visit'];
                    $invoice = $cashierTarget['invoice'];

                    $browser->visit(route('rme.cashier.payment.create', [$cashierVisit, $invoice]))
                        ->waitForText('Pembayaran Tagihan RME', 10);
                    $this->assertRmePageHealthy($browser);
                    $browser->screenshot('rme-smoke-collector-cashier-payment');
                }
            });
        } finally {
            $this->cleanupSmokeTestInvoice($createdInvoiceId);
            $this->cleanupSmokeTestOdontogram($createdOdontogramId);
        }
    }
}
