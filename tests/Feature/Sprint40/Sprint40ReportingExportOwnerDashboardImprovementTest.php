<?php

use App\Modules\Branch\Models\Branch;
use App\Modules\Reporting\Services\OwnerDashboardRmeLabKpiService;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use Database\Seeders\BranchSeeder;

// ---------------------------------------------------------------------------
// Documentation + sprint history checklist
// ---------------------------------------------------------------------------

function sprint40Doc(): string
{
    $path = base_path('docs/sprint_40_reporting_export_owner_dashboard_improvement.md');
    expect(file_exists($path))->toBeTrue();

    return (string) file_get_contents($path);
}

function sprint40History(): string
{
    return (string) file_get_contents(base_path('docs/sprint_history.md'));
}

it('has a Sprint 40 document with the required title', function () {
    expect(sprint40Doc())->toContain('# Sprint 40 — Reporting, Export & Owner Dashboard Improvement');
});

it('references the Sprint 39 baseline commit 1097d98', function () {
    expect(sprint40Doc())->toContain('1097d98');
});

it('references the Sprint 38 GO tag and merge commit 253f025', function () {
    $doc = sprint40Doc();
    expect($doc)->toContain('sprint-38-rme-workflow-improvement-batch-1-go')
        ->and($doc)->toContain('253f025');
});

it('references the Sprint 39 GO tag and merge commit 1097d98', function () {
    $doc = sprint40Doc();
    expect($doc)->toContain('sprint-39-cashier-payment-receivable-improvement-batch-1-go')
        ->and($doc)->toContain('1097d98');
});

it('references the Sprint 39 feature commit da34959', function () {
    expect(sprint40Doc())->toContain('da34959');
});

it('mentions the required implemented scope topics', function () {
    $doc = sprint40Doc();
    expect($doc)->toContain('Reporting overview clarity')
        ->and($doc)->toContain('Export consistency')
        ->and($doc)->toContain('Owner/admin dashboard KPI visibility')
        ->and($doc)->toContain('Receivable/payment reporting continuity')
        ->and($doc)->toContain('KTP')
        ->and($doc)->toContain('Zero-remaining receivable exclusion')
        ->and($doc)->toContain('Permission/authorization');
});

it('contains the safety boundaries block', function () {
    $doc = sprint40Doc();
    expect($doc)->toContain('no production/VPS access')
        ->and($doc)->toContain('no deployment')
        ->and($doc)->toContain('no external WhatsApp send')
        ->and($doc)->toContain('no new export/PDF dependency')
        ->and($doc)->toContain('no risky financial calculation rewrite');
});

it('contains the GO CANDIDATE FOR PR REVIEW marker', function () {
    expect(sprint40Doc())->toContain('GO CANDIDATE FOR PR REVIEW');
});

it('recommends Sprint 41 as the next sprint', function () {
    expect(sprint40Doc())->toContain('Sprint 41 — WhatsApp Manual Reminder Operationalization & Follow-up Workflow');
});

it('has a Sprint 40 entry in the sprint history referencing baseline and Sprint 41', function () {
    $history = sprint40History();
    expect($history)->toContain('## Sprint 40 — Reporting, Export & Owner Dashboard Improvement')
        ->and($history)->toContain('1097d98')
        ->and($history)->toContain('Sprint 41 — WhatsApp Manual Reminder Operationalization & Follow-up Workflow');
});

// ---------------------------------------------------------------------------
// Functional regression — Owner Dashboard
// ---------------------------------------------------------------------------

describe('Owner Dashboard reporting clarity and privacy', function () {
    beforeEach(function () {
        seedAccessControl();
        test()->seed(BranchSeeder::class);
        $this->branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    });

    it('renders the manual follow-up and zero-remaining clarity copy for authorized owners', function () {
        $this->actingAs(userInRole('Owner'))
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Ringkasan Piutang per Cabang')
            ->assertSee('tanpa pengiriman WhatsApp otomatis')
            ->assertSee('tidak dihitung sebagai piutang aktif');
    });

    it('does not expose No. KTP on the Owner Dashboard', function () {
        $response = $this->actingAs(userInRole('Owner'))
            ->get(route('dashboard'))
            ->assertOk();

        expect($response->getContent())->not->toContain('ktp_number')
            ->and($response->getContent())->not->toContain('No. KTP');
    });

    it('excludes fully-paid (zero-remaining) invoices from active receivable totals', function () {
        RmeInvoice::factory()->unpaid()->create(['branch_id' => $this->branch->id]);
        RmeInvoice::factory()->partial()->create(['branch_id' => $this->branch->id]);
        RmeInvoice::factory()->paid()->create(['branch_id' => $this->branch->id]);

        $service = app(OwnerDashboardRmeLabKpiService::class);
        $summary = collect($service->branchReceivableSummary())
            ->firstWhere('branch_id', $this->branch->id);

        // Only UNPAID + PARTIAL are counted; PAID (zero-remaining) is excluded.
        expect($summary['unpaid_count'])->toBe(1)
            ->and($summary['partial_count'])->toBe(1);
    });
});
