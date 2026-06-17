<?php

it('documents Sprint 28 Phase 28.6 closure GO NO-GO report', function (): void {
    $path = base_path('docs/sprint_28_phase_28_6_sprint_28_closure_go_no_go_report.md');

    expect(file_exists($path))->toBeTrue();

    $content = (string) file_get_contents($path);

    foreach ([
        'Sprint 28 Phase 28.6',
        'Sprint 28 Closure GO/NO-GO Report',
        'Baseline:** Sprint 28.5 GO at `3e44a8d`',
        'GO CANDIDATE FOR PR REVIEW',
    ] as $expected) {
        expect($content)->toContain($expected);
    }
});

it('lists every Sprint 28 phase identifier', function (): void {
    $content = (string) file_get_contents(base_path('docs/sprint_28_phase_28_6_sprint_28_closure_go_no_go_report.md'));

    foreach ([
        'Sprint 28.0',
        'Sprint 28.1',
        'Sprint 28.2',
        'Sprint 28.3',
        'Sprint 28.4',
        'Sprint 28.5',
    ] as $expected) {
        expect($content)->toContain($expected);
    }
});

it('lists every Sprint 28 merge commit', function (): void {
    $content = (string) file_get_contents(base_path('docs/sprint_28_phase_28_6_sprint_28_closure_go_no_go_report.md'));

    foreach ([
        'c36b852',
        'fa9842f',
        '05539ef',
        '7f54016',
        '1086d0f',
        '3e44a8d',
    ] as $expected) {
        expect($content)->toContain($expected);
    }
});

it('lists every Sprint 28 feature commit', function (): void {
    $content = (string) file_get_contents(base_path('docs/sprint_28_phase_28_6_sprint_28_closure_go_no_go_report.md'));

    foreach ([
        'ec26aea',
        '9905df6',
        'be3534e',
        '2bcb4d7',
        '0b03695',
        '58344a6',
    ] as $expected) {
        expect($content)->toContain($expected);
    }
});

it('lists every Sprint 28 PR number', function (): void {
    $content = (string) file_get_contents(base_path('docs/sprint_28_phase_28_6_sprint_28_closure_go_no_go_report.md'));

    foreach ([
        'PR #13',
        'PR #14',
        'PR #15',
        'PR #16',
        'PR #17',
        'PR #18',
    ] as $expected) {
        expect($content)->toContain($expected);
    }
});

it('includes the RME control workflow protection summary', function (): void {
    $content = (string) file_get_contents(base_path('docs/sprint_28_phase_28_6_sprint_28_closure_go_no_go_report.md'));

    foreach ([
        'RME Control Workflow Protection Summary',
        'Control visits keep the same patient/RM but create a new visit.',
        'Old RME/odontogram/invoice must not be overwritten.',
        'Parent receivable may remain visible/payable in cashier control.',
        'Payment allocation remains FIFO previous receivable first.',
        'Parent receivable does not block control completion.',
        'Rp0 invoice remains excluded from active receivables.',
        'Sprint 28 planning phases did not change these rules.',
    ] as $expected) {
        expect($content)->toContain($expected);
    }
});

it('includes the payment receivable cashier safety summary', function (): void {
    $content = (string) file_get_contents(base_path('docs/sprint_28_phase_28_6_sprint_28_closure_go_no_go_report.md'));

    foreach ([
        'Payment / Receivable / Cashier Safety Summary',
        'Active receivable follow-up is only for remaining balance > 0.',
        'Rp0 invoice must not be followed up as active receivable.',
        'Payment receipt allocation must remain traceable.',
        'Disputed balance must be escalated, not silently fixed.',
        'No payment/receivable business logic was changed in Sprint 28.',
    ] as $expected) {
        expect($content)->toContain($expected);
    }
});

it('includes the pilot readiness posture', function (): void {
    $content = (string) file_get_contents(base_path('docs/sprint_28_phase_28_6_sprint_28_closure_go_no_go_report.md'));

    foreach ([
        'Pilot Readiness Posture',
        'Operator can use the Sprint 28.1 checklist.',
        'Operator/support can use the Sprint 28.2 runbook.',
        'Cashier/admin can use the Sprint 28.3 WhatsApp/piutang planning.',
        'Support/admin can use the Sprint 28.4 monitoring/backup/restore planning.',
        'Team can use the Sprint 28.5 issue triage/stabilization backlog.',
    ] as $expected) {
        expect($content)->toContain($expected);
    }
});

it('includes the safety confirmation', function (): void {
    $content = (string) file_get_contents(base_path('docs/sprint_28_phase_28_6_sprint_28_closure_go_no_go_report.md'));

    foreach ([
        'Safety Confirmation',
        'No production code change.',
        'No migration.',
        'No deployment.',
        'No destructive operation.',
        'No bug fix implementation.',
        'No runtime behavior change.',
        'No real backup execution.',
        'No real restore execution.',
        'No RME/payment/receivable/cashier business rule change.',
    ] as $expected) {
        expect($content)->toContain($expected);
    }
});

it('includes the Sprint 28 GO and NO-GO criteria', function (): void {
    $content = (string) file_get_contents(base_path('docs/sprint_28_phase_28_6_sprint_28_closure_go_no_go_report.md'));

    foreach ([
        'Sprint 28 GO Criteria',
        'Sprint 28.0–28.5 are merged and GO tagged.',
        'Sprint 28 NO-GO Criteria',
        'GO tag is created on a feature commit instead of the merge commit.',
    ] as $expected) {
        expect($content)->toContain($expected);
    }
});

it('includes the recommended next Sprint 29 options', function (): void {
    $content = (string) file_get_contents(base_path('docs/sprint_28_phase_28_6_sprint_28_closure_go_no_go_report.md'));

    foreach ([
        'Sprint 29 Phase 29.0 — Pilot Stabilization Backlog Prioritization',
        'Sprint 29 Phase 29.0 — WhatsApp Reminder Manual Pilot SOP',
        'Sprint 29 Phase 29.0 — Monitoring/Backup/Restore Rehearsal Execution on Non-Production Target',
        'Sprint 29 Phase 29.0 — RME/Cashier High-Risk Regression Stabilization Planning',
    ] as $expected) {
        expect($content)->toContain($expected);
    }
});

it('records the Sprint 28 Phase 28.6 entry in sprint history', function (): void {
    $content = (string) file_get_contents(base_path('docs/sprint_history.md'));

    foreach ([
        'Sprint 28 Phase 28.6',
        'docs/sprint_28_phase_28_6_sprint_28_closure_go_no_go_report.md',
        'Sprint 28.5 GO at `3e44a8d`',
    ] as $expected) {
        expect($content)->toContain($expected);
    }
});
