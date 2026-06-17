<?php

it('documents Sprint 29 Phase 29.0 pilot stabilization backlog prioritization', function (): void {
    $path = base_path('docs/sprint_29_phase_29_0_pilot_stabilization_backlog_prioritization.md');

    expect(file_exists($path))->toBeTrue();

    $content = (string) file_get_contents($path);

    foreach ([
        'Sprint 29 Phase 29.0',
        'Pilot Stabilization Backlog Prioritization',
        'Baseline:** Sprint 28 closure GO at `b55d485`',
        'Sprint 28 closure GO at `b55d485`',
        'b55d485',
        'GO CANDIDATE FOR PR REVIEW',
    ] as $expected) {
        expect($content)->toContain($expected);
    }
});

it('lists the Sprint 28 inputs and phase identifiers', function (): void {
    $content = (string) file_get_contents(base_path('docs/sprint_29_phase_29_0_pilot_stabilization_backlog_prioritization.md'));

    foreach ([
        'Inputs from Sprint 28',
        'Sprint 28.0',
        'Sprint 28.1',
        'Sprint 28.2',
        'Sprint 28.3',
        'Sprint 28.4',
        'Sprint 28.5',
        'Sprint 28.6',
    ] as $expected) {
        expect($content)->toContain($expected);
    }
});

it('defines prioritization principles and priority levels', function (): void {
    $content = (string) file_get_contents(base_path('docs/sprint_29_phase_29_0_pilot_stabilization_backlog_prioritization.md'));

    foreach ([
        'Prioritization principles',
        'P0 BLOCKER',
        'P1 HIGH',
        'P2 MEDIUM',
        'P3 LOW',
        'P4 ENHANCEMENT',
        'NEEDS CONFIRMATION',
    ] as $expected) {
        expect($content)->toContain($expected);
    }
});

it('defines the scoring model and stabilization lanes', function (): void {
    $content = (string) file_get_contents(base_path('docs/sprint_29_phase_29_0_pilot_stabilization_backlog_prioritization.md'));

    foreach ([
        'Scoring model',
        'Stabilization lanes',
        'Lane A',
        'Lane J',
    ] as $expected) {
        expect($content)->toContain($expected);
    }
});

it('includes RME control workflow and cashier guardrails', function (): void {
    $content = (string) file_get_contents(base_path('docs/sprint_29_phase_29_0_pilot_stabilization_backlog_prioritization.md'));

    foreach ([
        'RME Control Workflow prioritization guardrails',
        'Old RME/odontogram/invoice must not be overwritten.',
        'Payment allocation must remain FIFO previous receivable first.',
        'Rp0 invoice must not appear in active receivables.',
        'Cashier / Payment / Receivable prioritization guardrails',
        'Active receivable follow-up is only for remaining balance > 0.',
        'Rp0 invoice must not be followed up as active receivable.',
    ] as $expected) {
        expect($content)->toContain($expected);
    }
});

it('includes the prioritized backlog template and future candidate phases', function (): void {
    $content = (string) file_get_contents(base_path('docs/sprint_29_phase_29_0_pilot_stabilization_backlog_prioritization.md'));

    foreach ([
        'Prioritized backlog template',
        'P0-RME-001',
        'P1-CASHIER-001',
        'P2-PRINT-001',
        'P2-REPORT-001',
        'P3-TRAINING-001',
        'NEEDS-CONFIRMATION-001',
        'Future Sprint 29 candidate phases',
        'Sprint 29 Phase 29.1 — P0/P1 RME Control Workflow Regression Stabilization Planning',
        'Sprint 29 Phase 29.2 — Cashier Payment Receivable High-Risk Stabilization Planning',
        'Sprint 29 Phase 29.3 — WhatsApp Reminder Manual Pilot SOP',
        'Sprint 29 Phase 29.4 — Monitoring Backup Restore Rehearsal on Non-Production Target',
        'Sprint 29 Phase 29.5 — Pilot Report/Print/UX Polish Backlog Planning',
        'Sprint 29 Phase 29.6 — Sprint 29 Stabilization Execution Readiness GO/NO-GO',
    ] as $expected) {
        expect($content)->toContain($expected);
    }
});

it('includes the GO/NO-GO decision and safety confirmation', function (): void {
    $content = (string) file_get_contents(base_path('docs/sprint_29_phase_29_0_pilot_stabilization_backlog_prioritization.md'));

    foreach ([
        'GO/NO-GO decision for Sprint 29.0',
        'NO-GO if:',
        'Safety confirmation',
        'No production code change.',
        'No migration.',
        'No deployment.',
        'No real backup execution.',
        'No real restore execution.',
        'No RME/payment/receivable/cashier business rule change.',
    ] as $expected) {
        expect($content)->toContain($expected);
    }
});

it('records the Sprint 29 Phase 29.0 entry in sprint history', function (): void {
    $content = (string) file_get_contents(base_path('docs/sprint_history.md'));

    foreach ([
        'Sprint 29 Phase 29.0',
        'docs/sprint_29_phase_29_0_pilot_stabilization_backlog_prioritization.md',
        'Sprint 28 closure GO at `b55d485`',
    ] as $expected) {
        expect($content)->toContain($expected);
    }
});
