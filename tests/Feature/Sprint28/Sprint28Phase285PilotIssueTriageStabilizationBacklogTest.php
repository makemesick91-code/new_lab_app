<?php

it('documents Sprint 28 Phase 28.5 pilot issue triage stabilization backlog', function (): void {
    $path = base_path('docs/sprint_28_phase_28_5_pilot_issue_triage_stabilization_backlog.md');

    expect(file_exists($path))->toBeTrue();

    $content = (string) file_get_contents($path);

    foreach ([
        'Sprint 28 Phase 28.5',
        'Pilot Issue Triage & Stabilization Backlog',
        'Baseline:** Sprint 28.4 GO at `1086d0f`',
        'GO CANDIDATE FOR PR REVIEW',
    ] as $expected) {
        expect($content)->toContain($expected);
    }
});

it('includes the issue intake sources', function (): void {
    $content = (string) file_get_contents(base_path('docs/sprint_28_phase_28_5_pilot_issue_triage_stabilization_backlog.md'));

    foreach ([
        'Issue Intake Sources',
        'Operator smoke checklist findings.',
        'Daily operation runbook notes.',
        'Cashier/payment/receivable findings.',
        'RME control workflow guardrail findings.',
        'Monitoring/backup/restore planning findings.',
        'Laravel log findings.',
        'Owner/supervisor feedback.',
        'Support/admin observations.',
    ] as $expected) {
        expect($content)->toContain($expected);
    }
});

it('includes the issue intake form', function (): void {
    $content = (string) file_get_contents(base_path('docs/sprint_28_phase_28_5_pilot_issue_triage_stabilization_backlog.md'));

    foreach ([
        'Issue Intake Form',
        'Issue ID',
        'Reporter role/name',
        'Steps to reproduce',
        'Expected result',
        'Actual result',
        'Severity',
        'Reproducible?',
        'Privacy risk?',
        'Assigned owner',
        'Triage decision',
        'Next action',
    ] as $expected) {
        expect($content)->toContain($expected);
    }
});

it('includes the severity classification', function (): void {
    $content = (string) file_get_contents(base_path('docs/sprint_28_phase_28_5_pilot_issue_triage_stabilization_backlog.md'));

    foreach ([
        'Severity Classification',
        'BLOCKER',
        'HIGH',
        'MEDIUM',
        'LOW',
        'ENHANCEMENT',
        'NEEDS CONFIRMATION',
    ] as $expected) {
        expect($content)->toContain($expected);
    }
});

it('includes the stabilization lane categories', function (): void {
    $content = (string) file_get_contents(base_path('docs/sprint_28_phase_28_5_pilot_issue_triage_stabilization_backlog.md'));

    foreach ([
        'Stabilization Lane Categories',
        'Lane A:** RME control workflow safety.',
        'Lane B:** Cashier/payment/receivable correctness.',
        'Lane C:** Patient registration/search/RM identity.',
        'Lane D:** Odontogram/RME print and browser print layout.',
        'Lane E:** Report export/print.',
        'Lane F:** Operator access/menu/role visibility.',
        'Lane G:** WhatsApp reminder and receivable follow-up process.',
        'Lane H:** Monitoring/log/backup/restore readiness.',
        'Lane I:** UX copy/help text/training notes.',
        'Lane J:** Technical debt / test hardening.',
    ] as $expected) {
        expect($content)->toContain($expected);
    }
});

it('includes the RME control workflow triage guardrails', function (): void {
    $content = (string) file_get_contents(base_path('docs/sprint_28_phase_28_5_pilot_issue_triage_stabilization_backlog.md'));

    foreach ([
        'RME Control Workflow Triage Guardrails',
        'Same patient/RM must be preserved.',
        'Control visit must create a new visit.',
        'Old RME/odontogram/invoice must not be overwritten.',
        'Parent receivable can remain visible/payable in cashier control.',
        'Payment allocation must remain FIFO previous receivable first.',
        'Parent receivable must not block control completion.',
        'Rp0 invoice must not appear in active receivables.',
        'Any report violating these is BLOCKER until triaged.',
    ] as $expected) {
        expect($content)->toContain($expected);
    }
});

it('includes the cashier receivable triage guardrails', function (): void {
    $content = (string) file_get_contents(base_path('docs/sprint_28_phase_28_5_pilot_issue_triage_stabilization_backlog.md'));

    foreach ([
        'Cashier / Receivable Triage Guardrails',
        'Verify invoice identity before classifying issue.',
        'Verify remaining balance > 0 before receivable follow-up.',
        'Verify Rp0 invoices are excluded from active receivables.',
        'Verify payment receipt shows correct allocation.',
        'Verify split allocation behavior when parent/current invoice both exist.',
        'Verify no duplicate payment entry.',
        'Disputed balance must be escalated and not silently fixed.',
    ] as $expected) {
        expect($content)->toContain($expected);
    }
});

it('includes the pilot backlog template', function (): void {
    $content = (string) file_get_contents(base_path('docs/sprint_28_phase_28_5_pilot_issue_triage_stabilization_backlog.md'));

    foreach ([
        'Pilot Backlog Template',
        'Backlog ID',
        'Lane',
        'Evidence',
        'Reproducible',
        'Owner',
        'Proposed next phase',
        'Status',
    ] as $expected) {
        expect($content)->toContain($expected);
    }
});

it('includes the GO/NO-GO decision matrix', function (): void {
    $content = (string) file_get_contents(base_path('docs/sprint_28_phase_28_5_pilot_issue_triage_stabilization_backlog.md'));

    foreach ([
        'GO/NO-GO Decision Matrix',
        'GO** to stabilization planning if all blocker issues are documented and scoped.',
        'NO-GO** if blocker issue lacks owner.',
        'NO-GO** if RME/payment/receivable anomaly is unresolved and affects pilot safety.',
        'NO-GO** if data overwrite risk is suspected.',
        'NO-GO** if backup/restore readiness is unknown for risky stabilization.',
        'NO-GO** if patient privacy risk is not triaged.',
    ] as $expected) {
        expect($content)->toContain($expected);
    }
});

it('includes the support admin daily triage checklist', function (): void {
    $content = (string) file_get_contents(base_path('docs/sprint_28_phase_28_5_pilot_issue_triage_stabilization_backlog.md'));

    foreach ([
        'Support / Admin Daily Triage Checklist',
        'Review new operator notes.',
        'Review cashier/payment notes.',
        'Review RME control workflow guardrail findings.',
        'Review Laravel logs.',
        'Review backup/monitoring notes.',
        'Group issues by severity.',
        'Assign owner.',
        'Mark reproducibility status.',
        'Prepare next-day action summary.',
        'Escalate blockers.',
    ] as $expected) {
        expect($content)->toContain($expected);
    }
});

it('includes the privacy and evidence rules', function (): void {
    $content = (string) file_get_contents(base_path('docs/sprint_28_phase_28_5_pilot_issue_triage_stabilization_backlog.md'));

    foreach ([
        'Privacy and Evidence Rules',
        'Minimize patient data.',
        'Use patient/RM/visit references only when needed.',
        'Do not expose No. KTP in issue reports unless explicitly required for identity bug analysis and approved.',
        'Do not share logs/screenshots outside approved support channel.',
        'Redact sensitive clinical/payment details when possible.',
    ] as $expected) {
        expect($content)->toContain($expected);
    }
});

it('includes the future stabilization candidate backlog', function (): void {
    $content = (string) file_get_contents(base_path('docs/sprint_28_phase_28_5_pilot_issue_triage_stabilization_backlog.md'));

    foreach ([
        'Future Stabilization Candidate Backlog',
        'RME control workflow regression stabilization.',
        'Cashier/payment/receivable regression stabilization.',
        'Report export/print polish.',
        'Odontogram/RME print layout polish.',
        'Patient search/registration UX polish.',
        'Role/menu visibility hardening.',
        'WhatsApp manual pilot SOP.',
        'Monitoring/backup/restore rehearsal execution on non-production target.',
        'Operator training notes.',
        'Test hardening for high-risk pilot workflows.',
    ] as $expected) {
        expect($content)->toContain($expected);
    }
});

it('includes risk and mitigation and GO/NO-GO', function (): void {
    $content = (string) file_get_contents(base_path('docs/sprint_28_phase_28_5_pilot_issue_triage_stabilization_backlog.md'));

    foreach ([
        'Risk and Mitigation',
        'Vague issue reports',
        'Missing reproduction steps',
        'Patient privacy leakage',
        'Misclassified severity',
        'Silent data overwrite risk',
        'Payment/receivable misallocation risk',
        'Fixing symptoms without root cause',
        'Scope creep into implementation',
        'Merge without validation',
        'GO tag created on wrong commit',
        'GO / NO-GO',
    ] as $expected) {
        expect($content)->toContain($expected);
    }
});

it('declares no production, migration, deploy, bug fix or destructive operation', function (): void {
    $content = (string) file_get_contents(base_path('docs/sprint_28_phase_28_5_pilot_issue_triage_stabilization_backlog.md'));

    foreach ([
        'No deployment',
        'No migration',
        'No production code change',
        'No bug fix implemented',
        'No destructive data operation',
    ] as $expected) {
        expect($content)->toContain($expected);
    }
});

it('records the Sprint 28 Phase 28.5 entry in sprint history', function (): void {
    $content = (string) file_get_contents(base_path('docs/sprint_history.md'));

    foreach ([
        'Sprint 28 Phase 28.5',
        'docs/sprint_28_phase_28_5_pilot_issue_triage_stabilization_backlog.md',
        'Sprint 28.4 GO at `1086d0f`',
    ] as $expected) {
        expect($content)->toContain($expected);
    }
});
