<?php

it('documents Sprint 29 Phase 29.5 pilot safety review and final stabilization checklist', function (): void {
    $path = base_path('docs/sprint_29_phase_29_5_pilot_safety_review_final_stabilization_checklist.md');

    expect(file_exists($path))->toBeTrue();

    $content = (string) file_get_contents($path);

    foreach ([
        '# Sprint 29 Phase 29.5 — Pilot Safety Review & Final Stabilization Checklist',
        'Status: Draft / Local Validation Pending',
        'Baseline: Sprint 29.4 GO at b6334fc',
        'Baseline:** Sprint 29.4 GO at `b6334fc`',
        'b6334fc',
        'GO CANDIDATE FOR PR REVIEW',
    ] as $expected) {
        expect($content)->toContain($expected);
    }
});

it('references all Sprint 29.0 to 29.4 GO tags and merge commits', function (): void {
    $content = (string) file_get_contents(base_path('docs/sprint_29_phase_29_5_pilot_safety_review_final_stabilization_checklist.md'));

    foreach ([
        'sprint-29-phase-29-0-pilot-stabilization-backlog-prioritization-go',
        '21ff95a',
        'sprint-29-phase-29-1-p0-p1-rme-control-workflow-regression-stabilization-planning-go',
        '39b4fd9',
        'sprint-29-phase-29-2-cashier-payment-receivable-high-risk-stabilization-planning-go',
        '266a0d2',
        'sprint-29-phase-29-3-whatsapp-reminder-manual-pilot-sop-go',
        '06c5d81',
        'sprint-29-phase-29-4-monitoring-backup-restore-rehearsal-non-production-target-go',
        'b6334fc',
    ] as $expected) {
        expect($content)->toContain($expected);
    }
});

it('defines P0 and P1 severity classifications', function (): void {
    $content = (string) file_get_contents(base_path('docs/sprint_29_phase_29_5_pilot_safety_review_final_stabilization_checklist.md'));

    foreach ([
        'P0/P1 final stabilization checklist',
        'Data loss risk',
        'Payment/receivable miscalculation risk',
        'Unauthorized access risk',
        'RME critical workflow blocker',
        'Backup/restore unrecoverable risk',
        'Production availability risk',
        'Workflow degradation without data loss',
        'Reporting/export mismatch',
        'Manual SOP gap',
        'Training gap',
        'Role/menu confusion',
    ] as $expected) {
        expect($content)->toContain($expected);
    }
});

it('defines safety gates before Sprint 30', function (): void {
    $content = (string) file_get_contents(base_path('docs/sprint_29_phase_29_5_pilot_safety_review_final_stabilization_checklist.md'));

    foreach ([
        'Safety gates before Sprint 30',
        'No unresolved P0',
        'P1 accepted with owner and mitigation',
        'Backup/restore rehearsal target defined',
        'Monitoring evidence template ready',
        'Cashier/RME/piutang smoke path identified',
        'WhatsApp manual reminder path identified',
        'Escalation contacts and responsibilities defined',
        'Pilot branch/version identified',
        'Rollback/recovery path documented',
    ] as $expected) {
        expect($content)->toContain($expected);
    }
});

it('defines the pilot operational smoke checklist', function (): void {
    $content = (string) file_get_contents(base_path('docs/sprint_29_phase_29_5_pilot_safety_review_final_stabilization_checklist.md'));

    foreach ([
        'Pilot operational smoke checklist',
        'Patient registration / patient identity',
        'RME visit creation',
        'Odontogram / treatment note',
        'Invoice creation',
        'Payment recording',
        'Receivable tracking',
        'Receipt/print/export',
        'WhatsApp manual reminder evidence',
        'Backup evidence',
        'Monitoring evidence',
        'Restore rehearsal evidence on non-production target',
    ] as $expected) {
        expect($content)->toContain($expected);
    }
});

it('provides the pilot evidence template', function (): void {
    $content = (string) file_get_contents(base_path('docs/sprint_29_phase_29_5_pilot_safety_review_final_stabilization_checklist.md'));

    foreach ([
        'Evidence template',
        'Date/time',
        'Tester/operator',
        'Branch/context',
        'Scenario',
        'Expected result',
        'Actual result',
        'Evidence location',
        'Issue severity',
        'Owner',
        'Decision',
    ] as $expected) {
        expect($content)->toContain($expected);
    }
});

it('defines Go Watch and No-Go criteria and a decision matrix', function (): void {
    $content = (string) file_get_contents(base_path('docs/sprint_29_phase_29_5_pilot_safety_review_final_stabilization_checklist.md'));

    foreach ([
        'Go / Watch / No-Go criteria',
        '**GO**',
        '**WATCH**',
        '**NO-GO**',
        'Decision matrix',
        'P0 found',
        'P1 found',
        'Backup incomplete',
        'Restore rehearsal failed',
        'Cashier mismatch',
        'RME blocker',
        'WhatsApp SOP gap',
        'Monitoring unavailable',
        'Training incomplete',
    ] as $expected) {
        expect($content)->toContain($expected);
    }
});

it('includes the out-of-scope safety restrictions', function (): void {
    $content = (string) file_get_contents(base_path('docs/sprint_29_phase_29_5_pilot_safety_review_final_stabilization_checklist.md'));

    foreach ([
        'Out of scope',
        'No production code change.',
        'No migration.',
        'No deployment.',
        'No production/VPS access.',
        'No real backup execution.',
        'No real restore execution.',
        'No automation creation',
        'No runtime behavior change.',
    ] as $expected) {
        expect($content)->toContain($expected);
    }
});

it('records the Sprint 29 Phase 29.5 entry in sprint history', function (): void {
    $content = (string) file_get_contents(base_path('docs/sprint_history.md'));

    foreach ([
        'Sprint 29 Phase 29.5',
        'docs/sprint_29_phase_29_5_pilot_safety_review_final_stabilization_checklist.md',
        'Sprint 29.4 GO at `b6334fc`',
        'b6334fc',
        'Sprint 30 — Pilot Execution Bugfix & Operational Smoke',
    ] as $expected) {
        expect($content)->toContain($expected);
    }
});
