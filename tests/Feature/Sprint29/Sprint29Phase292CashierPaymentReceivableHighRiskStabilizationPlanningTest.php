<?php

it('documents Sprint 29 Phase 29.2 cashier payment receivable high risk stabilization planning', function (): void {
    $path = base_path('docs/sprint_29_phase_29_2_cashier_payment_receivable_high_risk_stabilization_planning.md');

    expect(file_exists($path))->toBeTrue();

    $content = (string) file_get_contents($path);

    foreach ([
        'Sprint 29 Phase 29.2',
        'Cashier Payment Receivable High-Risk Stabilization Planning',
        'Baseline:** Sprint 29.1 GO at `39b4fd9`',
        'Sprint 29.1 GO at `39b4fd9`',
        '39b4fd9',
        'GO CANDIDATE FOR PR REVIEW',
    ] as $expected) {
        expect($content)->toContain($expected);
    }
});

it('defines the high-risk levels', function (): void {
    $content = (string) file_get_contents(base_path('docs/sprint_29_phase_29_2_cashier_payment_receivable_high_risk_stabilization_planning.md'));

    foreach ([
        'P0 BLOCKER',
        'P1 HIGH',
        'NEEDS CONFIRMATION',
    ] as $expected) {
        expect($content)->toContain($expected);
    }
});

it('lists the required evidence and guardrail sections', function (): void {
    $content = (string) file_get_contents(base_path('docs/sprint_29_phase_29_2_cashier_payment_receivable_high_risk_stabilization_planning.md'));

    foreach ([
        'Required evidence before implementation',
        'Cashier/payment/receivable invariants',
        'RME control visit connected guardrails',
        'P0/P1 triage matrix',
        'Stabilization planning checklist',
        'Future regression test planning',
        'Future implementation sequencing',
        'Out-of-scope implementation list',
        'Risk and mitigation',
        'GO/NO-GO decision',
        'Safety confirmation',
    ] as $expected) {
        expect($content)->toContain($expected);
    }
});

it('includes the cashier payment receivable invariants', function (): void {
    $content = (string) file_get_contents(base_path('docs/sprint_29_phase_29_2_cashier_payment_receivable_high_risk_stabilization_planning.md'));

    foreach ([
        'Invoice identity must be preserved',
        'Remaining balance must be accurate',
        'Payment allocation must remain FIFO previous receivable first',
        'Rp0 invoice must not appear in active receivables',
        'Active receivable follow-up is only for remaining balance > 0',
        'Fully paid invoice must not be followed up as an active receivable',
        'Payment receipt allocation must remain traceable',
        'Disputed balance must be escalated, not silently fixed',
    ] as $expected) {
        expect($content)->toContain($expected);
    }
});

it('includes the connected RME control visit guardrails', function (): void {
    $content = (string) file_get_contents(base_path('docs/sprint_29_phase_29_2_cashier_payment_receivable_high_risk_stabilization_planning.md'));

    foreach ([
        'Same patient/RM must be preserved',
        'Control workflow must create a new visit',
        'Old RME/odontogram/invoice must not be overwritten',
        'Cashier must be able to distinguish parent/current invoice context',
    ] as $expected) {
        expect($content)->toContain($expected);
    }
});

it('records the Sprint 29 Phase 29.2 entry in sprint history', function (): void {
    $content = (string) file_get_contents(base_path('docs/sprint_history.md'));

    foreach ([
        'Sprint 29 Phase 29.2',
        'docs/sprint_29_phase_29_2_cashier_payment_receivable_high_risk_stabilization_planning.md',
        'Sprint 29.1 GO at `39b4fd9`',
    ] as $expected) {
        expect($content)->toContain($expected);
    }
});
