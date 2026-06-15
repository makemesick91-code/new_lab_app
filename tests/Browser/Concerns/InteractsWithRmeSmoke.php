<?php

namespace Tests\Browser\Concerns;

use App\Models\User;
use App\Modules\Branch\Services\BranchService;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Odontogram\Models\Odontogram;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use App\Modules\RmeInvoice\Services\RmeInvoiceService;
use Illuminate\Support\Facades\DB;
use Laravel\Dusk\Browser;

trait InteractsWithRmeSmoke
{
    /** Internal marker for Dusk-created RME smoke fixtures (no DB column). */
    protected const SMOKE_TEST_MARKER = '__dusk_rme_smoke__';

    protected function adminCredentials(): array
    {
        return [
            env('DUSK_ADMIN_EMAIL', 'admin@asiadentallab.com'),
            env('DUSK_ADMIN_PASSWORD', 'password'),
        ];
    }

    protected function loginAsAdmin(Browser $browser): Browser
    {
        [$email, $password] = $this->adminCredentials();

        return $browser->visit('/login')
            ->type('email', $email)
            ->type('password', $password)
            ->click('button[type="submit"]')
            ->waitForText('Dashboard', 15);
    }

    protected function assertRmePageHealthy(Browser $browser): void
    {
        foreach ([
            'Server Error',
            'Whoops, looks like something went wrong',
            'SQLSTATE',
            '403 | Forbidden',
            '404 | Not Found',
            '500 | Server Error',
        ] as $text) {
            $browser->assertDontSee($text);
        }
    }

    protected function assertRmeKeywordPresent(Browser $browser): void
    {
        $source = $browser->driver->getPageSource();
        $keywords = ['RME', 'Rekam Medis', 'Kunjungan', 'Pasien'];
        $found = false;

        foreach ($keywords as $keyword) {
            if (str_contains($source, $keyword)) {
                $found = true;
                break;
            }
        }

        $this->assertTrue($found, 'Expected at least one RME keyword on the page.');
    }

    protected function resolveClinicVisit(): ?ClinicVisit
    {
        $rmeBranchIds = app(BranchService::class)->rmeEnabledIds();

        if ($rmeBranchIds === []) {
            return null;
        }

        return ClinicVisit::query()
            ->whereIn('branch_id', $rmeBranchIds)
            ->orderByDesc('id')
            ->first();
    }

    /**
     * @return array{visit: ClinicVisit, created_odontogram_id: int|null}|null
     */
    protected function resolveClinicVisitWithOdontogram(): ?array
    {
        $rmeBranchIds = app(BranchService::class)->rmeEnabledIds();

        if ($rmeBranchIds === []) {
            return null;
        }

        $visit = ClinicVisit::query()
            ->whereIn('branch_id', $rmeBranchIds)
            ->whereHas('odontogram')
            ->orderByDesc('id')
            ->first();

        if ($visit) {
            return [
                'visit' => $visit,
                'created_odontogram_id' => null,
            ];
        }

        $visit = $this->resolveClinicVisit();
        if (! $visit) {
            return null;
        }

        $odontogram = Odontogram::factory()->create([
            'clinic_visit_id' => $visit->id,
            'branch_id' => $visit->branch_id,
            'summary_notes' => self::SMOKE_TEST_MARKER.' temporary odontogram for Dusk RME smoke test',
        ]);

        return [
            'visit' => $visit->fresh(['odontogram']),
            'created_odontogram_id' => $odontogram->id,
        ];
    }

    /**
     * @return array{visit: ClinicVisit, invoice: RmeInvoice, created_by_test: bool}|null
     */
    protected function resolveUnpaidCashierTarget(): ?array
    {
        $invoice = RmeInvoice::query()
            ->where('status', RmeInvoice::STATUS_UNPAID)
            ->with('clinicVisit')
            ->orderByDesc('id')
            ->first();

        if ($invoice?->clinicVisit) {
            return [
                'visit' => $invoice->clinicVisit,
                'invoice' => $invoice,
                'created_by_test' => false,
            ];
        }

        $rmeBranchIds = app(BranchService::class)->rmeEnabledIds();

        if ($rmeBranchIds === []) {
            return null;
        }

        $visit = ClinicVisit::query()
            ->where('status', ClinicVisit::STATUS_CASHIER_PENDING)
            ->whereIn('branch_id', $rmeBranchIds)
            ->whereHas('medicalRecord', fn ($query) => $query->where('status', 'final'))
            ->orderByDesc('id')
            ->first();

        if (! $visit) {
            return null;
        }

        $cashier = User::query()
            ->where('email', env('DUSK_ADMIN_EMAIL', 'admin@asiadentallab.com'))
            ->first();

        if (! $cashier) {
            return null;
        }

        $invoice = app(RmeInvoiceService::class)->create($visit, $cashier, [
            'notes' => self::SMOKE_TEST_MARKER.' temporary invoice for Dusk RME smoke test',
            'items' => [[
                'description' => self::SMOKE_TEST_MARKER.' Dusk Smoke Billing',
                'qty' => 1,
                'unit_price' => 100000,
                'discount' => 0,
            ]],
        ]);

        return [
            'visit' => $visit->fresh(),
            'invoice' => $invoice->fresh(),
            'created_by_test' => true,
        ];
    }

    protected function cleanupSmokeTestInvoice(?int $invoiceId): void
    {
        if ($invoiceId === null) {
            return;
        }

        $invoice = RmeInvoice::query()->find($invoiceId);

        if (! $invoice || ! $this->isSmokeTestInvoice($invoice)) {
            return;
        }

        if ($invoice->payments()->exists()) {
            return;
        }

        DB::transaction(function () use ($invoice) {
            $invoice->load(['labCaseCandidates', 'items']);

            foreach ($invoice->labCaseCandidates as $candidate) {
                $candidate->forceDelete();
            }

            $invoice->followUps()->delete();
            $invoice->items()->delete();
            $invoice->delete();
        });
    }

    protected function cleanupSmokeTestOdontogram(?int $odontogramId): void
    {
        if ($odontogramId === null) {
            return;
        }

        $odontogram = Odontogram::query()->find($odontogramId);

        if (! $odontogram || ! $this->isSmokeTestOdontogram($odontogram)) {
            return;
        }

        $odontogram->forceDelete();
    }

    protected function isSmokeTestInvoice(RmeInvoice $invoice): bool
    {
        return str_contains((string) $invoice->notes, self::SMOKE_TEST_MARKER);
    }

    protected function isSmokeTestOdontogram(Odontogram $odontogram): bool
    {
        return str_contains((string) $odontogram->summary_notes, self::SMOKE_TEST_MARKER);
    }
}
