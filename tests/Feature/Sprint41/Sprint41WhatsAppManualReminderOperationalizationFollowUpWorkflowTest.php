<?php

// Sprint 41 — WhatsApp Manual Reminder Operationalization & Follow-up Workflow
// Checklist assertions over the sprint doc + sprint history, plus functional regression
// over the manual WhatsApp helper, KTP privacy, zero-remaining exclusion and authorization.

use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use Database\Seeders\BranchSeeder;

function sprint41Doc(): string
{
    return file_get_contents(base_path('docs/sprint_41_whatsapp_manual_reminder_operationalization_follow_up_workflow.md'));
}

it('sprint 41 document exists', function () {
    expect(file_exists(base_path('docs/sprint_41_whatsapp_manual_reminder_operationalization_follow_up_workflow.md')))->toBeTrue();
});

it('sprint 41 document contains the title', function () {
    expect(sprint41Doc())
        ->toContain('# Sprint 41 — WhatsApp Manual Reminder Operationalization & Follow-up Workflow');
});

it('sprint 41 document references the required baselines and commits', function () {
    $doc = sprint41Doc();
    expect($doc)->toContain('Baseline: Sprint 40 GO at 8647b0f')
        ->and($doc)->toContain('sprint-39-cashier-payment-receivable-improvement-batch-1-go at 1097d98')
        ->and($doc)->toContain('sprint-40-reporting-export-owner-dashboard-improvement-go at 8647b0f')
        ->and($doc)->toContain('Sprint 40 feature commit: 8ed83ad');
});

it('sprint 41 document covers each required scope area', function () {
    $doc = sprint41Doc();
    expect($doc)->toContain('Manual WhatsApp reminder clarity')
        ->and($doc)->toContain('Follow-up logging workflow')
        ->and($doc)->toContain('Reminder template guidance')
        ->and($doc)->toContain('Receivable/piutang follow-up continuity')
        ->and($doc)->toContain('Dashboard/reporting continuity')
        ->and($doc)->toContain('WA manual follow-up context')
        ->and($doc)->toContain('KTP/privacy protection')
        ->and($doc)->toContain('Zero-remaining receivable exclusion')
        ->and($doc)->toContain('Permission/authorization');
});

it('sprint 41 document contains safety boundaries and PR marker', function () {
    $doc = sprint41Doc();
    expect($doc)->toContain('no external WhatsApp send')
        ->and($doc)->toContain('no WhatsApp automation')
        ->and($doc)->toContain('no WhatsApp API integration')
        ->and($doc)->toContain('no queue/job/cron/scheduler automation')
        ->and($doc)->toContain('no new notification provider')
        ->and($doc)->toContain('GO CANDIDATE FOR PR REVIEW');
});

it('sprint history references Sprint 41, baseline and Sprint 42', function () {
    $history = file_get_contents(base_path('docs/sprint_history.md'));
    expect($history)->toContain('## Sprint 41 — WhatsApp Manual Reminder Operationalization & Follow-up Workflow')
        ->and($history)->toContain('8647b0f')
        ->and($history)->toContain('Sprint 42 — Monitoring, Backup & Recovery Governance Hardening');
});

describe('manual WhatsApp follow-up helper', function () {
    beforeEach(function () {
        test()->seed(BranchSeeder::class);
        seedAccessControl();

        Branch::where('code', Branch::MAIN_CODE)->update(['is_rme_enabled' => false]);
        $this->branch = Branch::factory()->create(['code' => 'ATG8', 'is_rme_enabled' => true]);
        $this->cashier = userWith(['manage_rme_billing']);
        $this->unauthorized = userWith(['view_clinic_visits']);
    });

    function makeSprint41Invoice(Branch $branch, $cashier, string $status = RmeInvoice::STATUS_UNPAID): RmeInvoice
    {
        $visit = ClinicVisit::factory()->cashierPending()->create(['branch_id' => $branch->id]);

        $invoice = RmeInvoice::factory()->create([
            'branch_id' => $branch->id,
            'clinic_visit_id' => $visit->id,
            'patient_id' => $visit->patient_id,
            'cashier_id' => $cashier->id,
            'status' => $status,
            'subtotal' => 300000,
            'grand_total' => 300000,
        ]);

        $invoice->patient()->update(['whatsapp_number' => '081234567890']);

        return $invoice->fresh();
    }

    it('shows the manual WhatsApp helper with a client-side wa.me link', function () {
        $this->actingAs($this->cashier);
        $invoice = makeSprint41Invoice($this->branch, $this->cashier);

        $this->get(route('rme.cashier.receivables.follow-ups.create', $invoice))
            ->assertOk()
            ->assertSee('Bantuan Pengingat WhatsApp (Manual)')
            ->assertSee('tinjau')
            ->assertSee('https://wa.me/6281234567890', false)
            ->assertSee('tidak memanggil API WhatsApp');
    });

    it('never renders No. KTP in the follow-up helper', function () {
        $this->actingAs($this->cashier);
        $invoice = makeSprint41Invoice($this->branch, $this->cashier);
        $invoice->patient()->update(['ktp_number' => '3500000000000041']);

        $this->get(route('rme.cashier.receivables.follow-ups.create', $invoice))
            ->assertOk()
            ->assertDontSee('3500000000000041');
    });

    it('keeps zero-remaining (paid) invoices out of active receivables', function () {
        $this->actingAs($this->cashier);
        $paid = makeSprint41Invoice($this->branch, $this->cashier, RmeInvoice::STATUS_PAID);
        $unpaid = makeSprint41Invoice($this->branch, $this->cashier, RmeInvoice::STATUS_UNPAID);

        $this->get(route('rme.cashier.receivables'))
            ->assertOk()
            ->assertSee($unpaid->invoice_number)
            ->assertDontSee($paid->invoice_number);
    });

    it('forbids the manual helper for unauthorized users', function () {
        $this->actingAs($this->unauthorized);
        $invoice = makeSprint41Invoice($this->branch, $this->cashier);

        $this->get(route('rme.cashier.receivables.follow-ups.create', $invoice))
            ->assertForbidden();
    });

    it('does not register any WhatsApp send route', function () {
        $names = collect(app('router')->getRoutes())->map->getName()->filter()->values();

        expect($names->contains(fn ($name) => str_contains($name, 'whatsapp.send')))->toBeFalse()
            ->and($names->contains(fn ($name) => str_contains($name, 'wa.send')))->toBeFalse();
    });
});
