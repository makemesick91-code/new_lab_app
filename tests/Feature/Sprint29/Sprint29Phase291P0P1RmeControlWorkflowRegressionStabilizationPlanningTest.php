<?php

it('documents Sprint 29 Phase 29.1 P0 P1 RME control workflow regression stabilization planning', function (): void {
    $path = base_path('docs/sprint_29_phase_29_1_p0_p1_rme_control_workflow_regression_stabilization_planning.md');

    expect(file_exists($path))->toBeTrue();

    $content = (string) file_get_contents($path);

    foreach ([
        'Sprint 29 Phase 29.1',
        'P0/P1 RME Control Workflow Regression Stabilization Planning',
        'Baseline:** Sprint 29.0 GO at `21ff95a`',
        'Sprint 29.0 GO at `21ff95a`',
        '21ff95a',
        'GO CANDIDATE FOR PR REVIEW',
    ] as $expected) {
        expect($content)->toContain($expected);
    }
});

it('defines the regression risk levels', function (): void {
    $content = (string) file_get_contents(base_path('docs/sprint_29_phase_29_1_p0_p1_rme_control_workflow_regression_stabilization_planning.md'));

    foreach ([
        'P0 BLOCKER',
        'P1 HIGH',
        'NEEDS CONFIRMATION',
    ] as $expected) {
        expect($content)->toContain($expected);
    }
});

it('lists the required evidence and guardrail sections', function (): void {
    $content = (string) file_get_contents(base_path('docs/sprint_29_phase_29_1_p0_p1_rme_control_workflow_regression_stabilization_planning.md'));

    foreach ([
        'Required evidence before implementation',
        'RME Control Workflow invariants',
        'Cashier/payment/receivable connected guardrails',
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

it('includes the RME control workflow invariants', function (): void {
    $content = (string) file_get_contents(base_path('docs/sprint_29_phase_29_1_p0_p1_rme_control_workflow_regression_stabilization_planning.md'));

    foreach ([
        'Same patient/RM must be preserved',
        'Control workflow must create a new visit',
        'Old RME must not be overwritten',
        'Old odontogram must not be overwritten',
        'Old invoice must not be overwritten',
        'Rp0 invoice must not appear in active receivables',
    ] as $expected) {
        expect($content)->toContain($expected);
    }
});

it('includes the connected cashier and payment guardrails', function (): void {
    $content = (string) file_get_contents(base_path('docs/sprint_29_phase_29_1_p0_p1_rme_control_workflow_regression_stabilization_planning.md'));

    foreach ([
        'Payment allocation must remain FIFO previous receivable first',
        'Parent receivable must not block control completion',
        'Disputed balance must be escalated, not silently fixed',
    ] as $expected) {
        expect($content)->toContain($expected);
    }
});

it('records the Sprint 29 Phase 29.1 entry in sprint history', function (): void {
    $content = (string) file_get_contents(base_path('docs/sprint_history.md'));

    foreach ([
        'Sprint 29 Phase 29.1',
        'docs/sprint_29_phase_29_1_p0_p1_rme_control_workflow_regression_stabilization_planning.md',
        'Sprint 29.0 GO at `21ff95a`',
    ] as $expected) {
        expect($content)->toContain($expected);
    }
});
