<?php

use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Patient\Models\Patient;
use Database\Seeders\BranchSeeder;

use function PHPUnit\Framework\assertFileExists;

function sprint38Doc(): string
{
    return (string) file_get_contents(base_path('docs/sprint_38_rme_workflow_improvement_batch_1.md'));
}

function sprint38History(): string
{
    return (string) file_get_contents(base_path('docs/sprint_history.md'));
}

it('documents the Sprint 38 title, status block and baseline commit', function (): void {
    $path = base_path('docs/sprint_38_rme_workflow_improvement_batch_1.md');

    assertFileExists($path);

    foreach ([
        '# Sprint 38 — RME Workflow Improvement Batch 1',
        'Status: Draft / Local Validation Pending',
        'Baseline: Sprint 37 GO at 078be4e',
        '078be4e',
    ] as $expected) {
        expect(sprint38Doc())->toContain($expected);
    }
});

it('references the Sprint 36 and Sprint 37 GO tags, commits and Sprint 37 feature commit', function (): void {
    foreach ([
        'sprint-36-operational-governance-maintenance-cadence-expansion-readiness-go',
        '7a1f959',
        'sprint-37-controlled-roadmap-execution-batch-1-governance-review-go',
        '078be4e',
        'a2d6de8',
    ] as $expected) {
        expect(sprint38Doc())->toContain($expected);
    }
});

it('documents the implemented Batch 1 scope', function (): void {
    foreach ([
        'KTP',
        'Duplicate identity validation',
        'WA number workflow clarity',
        'Treatment consent checklist clarity',
        'RME print / privacy protection',
    ] as $expected) {
        expect(sprint38Doc())->toContain($expected);
    }
});

it('documents the Sprint 38 safety boundaries and PR readiness marker', function (): void {
    foreach ([
        '## Safety boundaries',
        'no production/VPS access',
        'no deployment',
        'no production migration',
        'no external WhatsApp send',
        'no WhatsApp automation',
        'no signature upload/capture integration',
        'no backup/restore/rollback execution',
        'no `.env` change',
        'no dependency/package install',
        'no GO tag',
        'GO CANDIDATE FOR PR REVIEW',
    ] as $expected) {
        expect(sprint38Doc())->toContain($expected);
    }
});

it('records the Sprint 38 entry in sprint history with baseline and next sprint', function (): void {
    foreach ([
        '## Sprint 38 — RME Workflow Improvement Batch 1',
        '078be4e',
        'Sprint 39 — Cashier, Payment & Receivable Improvement Batch 1',
    ] as $expected) {
        expect(sprint38History())->toContain($expected);
    }
});

describe('RME workflow clarity copy', function (): void {
    beforeEach(function (): void {
        test()->seed(BranchSeeder::class);
        seedAccessControl();
        $this->branch = Branch::factory()->create(['code' => 'S38', 'is_active' => true, 'is_rme_enabled' => true]);
    });

    it('shows WA operational help text on the patient form', function (): void {
        $this->actingAs(userWith(['manage patients']))
            ->get(route('settings.patients.create'))
            ->assertOk()
            ->assertSee('Dipakai untuk konfirmasi kehadiran kunjungan dan tindak lanjut piutang. Sistem tidak mengirim pesan WhatsApp otomatis.');
    });

    it('shows WA operational help text on the RME new-patient visit form', function (): void {
        $this->actingAs(userWith(['manage_clinic_visits', 'view_clinic_visits', 'manage patients']))
            ->get(route('rme.visits.create'))
            ->assertOk()
            ->assertSee('Dipakai untuk konfirmasi kehadiran kunjungan dan tindak lanjut piutang. Sistem tidak mengirim pesan WhatsApp otomatis.');
    });

    it('shows the cashier-facing consent verification status on the RME visit detail and never the KTP number', function (): void {
        $patient = Patient::factory()->create(['ktp_number' => '3209090909090038']);
        $visit = ClinicVisit::factory()->create([
            'branch_id' => $this->branch->id,
            'patient_id' => $patient->id,
            'consent_signed_by_patient' => false,
            'consent_signed_by_doctor' => false,
        ]);

        $this->actingAs(userWith(['view_clinic_visits']))
            ->get(route('rme.visits.show', $visit))
            ->assertOk()
            ->assertSee('Persetujuan Tindakan')
            ->assertSee('TTD Surat Persetujuan Tindakan belum diverifikasi kasir')
            ->assertDontSee('3209090909090038')
            ->assertDontSee('Nomor KTP');
    });
});
