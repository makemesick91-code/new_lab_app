<?php

// Sprint 39 — Cashier, Payment & Receivable Improvement Batch 1

use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Patient\Models\Patient;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use Database\Seeders\BranchSeeder;

use function PHPUnit\Framework\assertFileExists;

function sprint39Doc(): string
{
    return (string) file_get_contents(base_path('docs/sprint_39_cashier_payment_receivable_improvement_batch_1.md'));
}

function sprint39History(): string
{
    return (string) file_get_contents(base_path('docs/sprint_history.md'));
}

// ─── Documentation checklist ─────────────────────────────────────────────────

it('documents the Sprint 39 title, status block and baseline commit', function (): void {
    assertFileExists(base_path('docs/sprint_39_cashier_payment_receivable_improvement_batch_1.md'));

    foreach ([
        '# Sprint 39 — Cashier, Payment & Receivable Improvement Batch 1',
        'Status: Draft / Local Validation Pending',
        'Baseline: Sprint 38 GO at 253f025',
        '253f025',
    ] as $expected) {
        expect(sprint39Doc())->toContain($expected);
    }
});

it('references the Sprint 37 and Sprint 38 GO tags, commits and Sprint 38 feature commit', function (): void {
    foreach ([
        'sprint-37-controlled-roadmap-execution-batch-1-governance-review-go',
        '078be4e',
        'sprint-38-rme-workflow-improvement-batch-1-go',
        '253f025',
        'beb8eb8',
    ] as $expected) {
        expect(sprint39Doc())->toContain($expected);
    }
});

it('documents the implemented Batch 1 scope', function (): void {
    foreach ([
        'Cashier verification clarity',
        'Payment/remaining-balance clarity',
        'Receivable/piutang follow-up context',
        'WA manual follow-up',
        'Consent checklist/status',
        'Zero-remaining receivable exclusion',
        'Overpayment/validation',
        'KTP/privacy',
    ] as $expected) {
        expect(sprint39Doc())->toContain($expected);
    }
});

it('documents the Sprint 39 safety boundaries and PR readiness marker', function (): void {
    foreach ([
        '## Safety boundaries',
        'no production/VPS access',
        'no deployment',
        'no production migration',
        'no external WhatsApp send',
        'no WhatsApp automation',
        'no signature upload/capture integration',
        'no risky financial calculation rewrite',
        'no backup/restore/rollback execution',
        'no `.env` change',
        'no dependency/package install',
        'no GO tag',
        'GO CANDIDATE FOR PR REVIEW',
    ] as $expected) {
        expect(sprint39Doc())->toContain($expected);
    }
});

it('records the Sprint 39 entry in sprint history with baseline and next sprint', function (): void {
    foreach ([
        '## Sprint 39 — Cashier, Payment & Receivable Improvement Batch 1',
        '253f025',
        'Sprint 40 — Reporting, Export & Owner Dashboard Improvement',
    ] as $expected) {
        expect(sprint39History())->toContain($expected);
    }
});

// ─── Functional clarity / regression ─────────────────────────────────────────

describe('cashier payment receivable clarity', function (): void {
    beforeEach(function (): void {
        test()->seed(BranchSeeder::class);
        seedAccessControl();

        Branch::where('code', Branch::MAIN_CODE)->update(['is_rme_enabled' => false]);
        $this->branch = Branch::factory()->create(['code' => 'S39', 'is_active' => true, 'is_rme_enabled' => true]);
        $this->cashier = userWith(['manage_rme_billing']);
    });

    function sprint39Invoice(Branch $branch, $cashier, string $status = RmeInvoice::STATUS_UNPAID, array $visitOverrides = [], array $patientOverrides = []): RmeInvoice
    {
        $patient = Patient::factory()->create(array_merge([
            'whatsapp_number' => '081299990000',
        ], $patientOverrides));

        $visit = ClinicVisit::factory()->cashierPending()->create(array_merge([
            'branch_id' => $branch->id,
            'patient_id' => $patient->id,
        ], $visitOverrides));

        return RmeInvoice::factory()->create([
            'branch_id' => $branch->id,
            'clinic_visit_id' => $visit->id,
            'patient_id' => $patient->id,
            'cashier_id' => $cashier->id,
            'status' => $status,
            'subtotal' => 300000,
            'grand_total' => 300000,
        ]);
    }

    it('shows the read-only consent verification status and never the KTP number on the cashier billing detail', function (): void {
        $invoice = sprint39Invoice($this->branch, $this->cashier, RmeInvoice::STATUS_UNPAID, [
            'consent_signed_by_patient' => false,
            'consent_signed_by_doctor' => false,
        ], ['ktp_number' => '3209090909090039']);

        $this->actingAs($this->cashier)
            ->get(route('rme.cashier.show', [$invoice->clinicVisit, $invoice]))
            ->assertOk()
            ->assertSee('Status Persetujuan Tindakan (TTD)')
            ->assertSee('Belum Diverifikasi')
            ->assertSee('Sistem tidak mengirim pesan WhatsApp otomatis')
            ->assertDontSee('3209090909090039')
            ->assertDontSee('Nomor KTP');
    });

    it('shows the consent verification status as verified when both parties signed', function (): void {
        $invoice = sprint39Invoice($this->branch, $this->cashier, RmeInvoice::STATUS_UNPAID, [
            'consent_signed_by_patient' => true,
            'consent_signed_by_doctor' => true,
        ]);

        $this->actingAs($this->cashier)
            ->get(route('rme.cashier.show', [$invoice->clinicVisit, $invoice]))
            ->assertOk()
            ->assertSee('Status Persetujuan Tindakan (TTD)')
            ->assertSee('Terverifikasi');
    });

    it('shows payment and remaining-balance clarity on the cashier payment screen', function (): void {
        $invoice = sprint39Invoice($this->branch, $this->cashier);

        $this->actingAs($this->cashier)
            ->get(route('rme.cashier.payment.create', [$invoice->clinicVisit, $invoice]))
            ->assertOk()
            ->assertSee('Grand Total')
            ->assertSee('Sisa Tagihan')
            ->assertSee('Nomor WA');
    });

    it('excludes fully paid / zero-remaining invoices from active receivables', function (): void {
        $unpaid = sprint39Invoice($this->branch, $this->cashier, RmeInvoice::STATUS_UNPAID);
        $paid = sprint39Invoice($this->branch, $this->cashier, RmeInvoice::STATUS_PAID);

        $this->actingAs($this->cashier)
            ->get(route('rme.cashier.receivables'))
            ->assertOk()
            ->assertSee($unpaid->invoice_number)
            ->assertDontSee($paid->invoice_number)
            ->assertSee('Sistem tidak mengirim pesan WhatsApp otomatis');
    });

    it('keeps partially paid invoices visible in active receivables with remaining context', function (): void {
        $partial = sprint39Invoice($this->branch, $this->cashier, RmeInvoice::STATUS_PARTIAL);

        $this->actingAs($this->cashier)
            ->get(route('rme.cashier.receivables'))
            ->assertOk()
            ->assertSee($partial->invoice_number)
            ->assertSee('Sisa Tagihan');
    });

    it('rejects an overpayment that exceeds the remaining balance', function (): void {
        $invoice = sprint39Invoice($this->branch, $this->cashier);

        // FIX-RME-CONSENT-WORKFLOW-PRINT-UX-2 / FIX-01 — consent is signed here so
        // this test still measures the AMOUNT cap; without it the request would be
        // refused by the consent gate first and prove nothing about overpayment.
        rmeSignedConsentFor($invoice->clinicVisit);

        $this->actingAs($this->cashier)
            ->from(route('rme.cashier.payment.create', [$invoice->clinicVisit, $invoice]))
            ->post(route('rme.cashier.payment.store', [$invoice->clinicVisit, $invoice]), [
                'amount' => 9999999,
                'paid_at' => now()->toDateTimeString(),
                'consent_signed_by_patient' => '1',
                'consent_signed_by_doctor' => '1',
            ])
            ->assertSessionHasErrors('amount');

        expect($invoice->fresh()->status)->toBe(RmeInvoice::STATUS_UNPAID);
    });

    it('shows the manual WA follow-up context on the receivable follow-up form', function (): void {
        $invoice = sprint39Invoice($this->branch, $this->cashier);

        $this->actingAs($this->cashier)
            ->get(route('rme.cashier.receivables.follow-ups.create', $invoice))
            ->assertOk()
            ->assertSee('Nomor WA')
            ->assertSee('Sistem tidak mengirim pesan WhatsApp otomatis');
    });
});
