<?php

namespace Tests\Browser;

use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use App\Modules\RmeInvoice\Services\RmeInvoiceService;
use Laravel\Dusk\Browser;
use Tests\Browser\Concerns\InteractsWithRmeSmoke;
use Tests\DuskTestCase;

class RmeControlCashierSmokeTest extends DuskTestCase
{
    use InteractsWithRmeSmoke;

    public function test_control_cashier_payment_page_loads_with_carry_over_section(): void
    {
        $fixture = $this->createControlCarryOverFixture();

        if ($fixture === null) {
            $this->markTestSkipped('No RME-enabled branch available for Dusk smoke fixture.');
        }

        $this->browse(function (Browser $browser) use ($fixture) {
            $this->loginAsAdmin($browser)
                ->visit(route('rme.cashier.show', [$fixture['control'], $fixture['control_invoice']]))
                ->waitForText('Piutang Kunjungan Sebelumnya', 10)
                ->assertSee('Piutang Kunjungan Sebelumnya')
                ->assertSee('Total Harus Dibayar')
                ->assertSee($fixture['parent_invoice']->invoice_number);

            $this->assertRmePageHealthy($browser);

            $browser->visit(route('rme.cashier.payment.create', [$fixture['control'], $fixture['control_invoice']]))
                ->waitForText('Form Pembayaran', 10)
                ->assertSee('Total Harus Dibayar');

            $this->assertRmePageHealthy($browser);
        });

        $this->cleanupControlCarryOverFixture($fixture);
    }

    /**
     * @return array{control: ClinicVisit, control_invoice: RmeInvoice, parent_invoice: RmeInvoice, created_invoice_ids: array<int>}|null
     */
    protected function createControlCarryOverFixture(): ?array
    {
        $base = $this->resolveUnpaidCashierTarget();

        if ($base === null) {
            return null;
        }

        $parentVisit = $base['visit'];
        $parentInvoice = $base['invoice'];
        $createdInvoiceIds = [];

        if ($base['created_by_test']) {
            $createdInvoiceIds[] = $parentInvoice->id;
        }

        $controlVisit = ClinicVisit::factory()->cashierPending()->create([
            'branch_id' => $parentVisit->branch_id,
            'clinic_id' => $parentVisit->clinic_id,
            'patient_id' => $parentVisit->patient_id,
            'doctor_id' => $parentVisit->doctor_id,
            'visit_type' => ClinicVisit::VISIT_TYPE_CONTROL,
            'follow_up_of_visit_id' => $parentVisit->id,
            'visit_number' => 'VIS-DUSK-COV-'.uniqid(),
        ]);

        $cashier = $parentInvoice->cashier ?? $parentVisit->createdBy;

        if (! $cashier) {
            return null;
        }

        $controlInvoice = app(RmeInvoiceService::class)->create($controlVisit, $cashier, [
            'notes' => self::SMOKE_TEST_MARKER.' control carry-over dusk fixture',
            'items' => [[
                'description' => self::SMOKE_TEST_MARKER.' Control Billing',
                'qty' => 1,
                'unit_price' => 100000,
                'discount' => 0,
            ]],
        ]);

        $createdInvoiceIds[] = $controlInvoice->id;

        return [
            'control' => $controlVisit->fresh(),
            'control_invoice' => $controlInvoice->fresh(),
            'parent_invoice' => $parentInvoice->fresh(),
            'created_invoice_ids' => $createdInvoiceIds,
        ];
    }

    /**
     * @param  array{created_invoice_ids: array<int>}|null  $fixture
     */
    protected function cleanupControlCarryOverFixture(?array $fixture): void
    {
        if ($fixture === null) {
            return;
        }

        foreach ($fixture['created_invoice_ids'] as $invoiceId) {
            $this->cleanupSmokeTestInvoice($invoiceId);
        }
    }
}
