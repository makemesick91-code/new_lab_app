<?php

it('documents Sprint 28 Phase 28.1 pilot readiness operator smoke checklist', function (): void {
    $path = base_path('docs/sprint_28_phase_28_1_pilot_readiness_operator_smoke_checklist.md');

    expect(file_exists($path))->toBeTrue();

    $content = (string) file_get_contents($path);

    foreach ([
        'Sprint 28 Phase 28.1',
        'Baseline:** Sprint 28.0 GO at `c36b852`',
        'GO CANDIDATE FOR PR REVIEW',
    ] as $expected) {
        expect($content)->toContain($expected);
    }
});

it('includes the operator smoke checklist content', function (): void {
    $content = (string) file_get_contents(base_path('docs/sprint_28_phase_28_1_pilot_readiness_operator_smoke_checklist.md'));

    foreach ([
        'Operator Smoke Checklist',
        'Login and role/menu visibility',
        'Patient search',
        'New patient registration smoke',
        'Visit creation smoke',
        'RME visit input smoke',
        'Odontogram input/print smoke',
        'Cashier billing smoke',
        'Payment receipt smoke',
        'Previous receivable / active receivable check',
        'Report export/print smoke',
        'Logout/session check',
    ] as $expected) {
        expect($content)->toContain($expected);
    }
});

it('includes the RME control workflow smoke checklist content', function (): void {
    $content = (string) file_get_contents(base_path('docs/sprint_28_phase_28_1_pilot_readiness_operator_smoke_checklist.md'));

    foreach ([
        'RME Control Workflow Smoke Checklist',
        'Control visit uses the same patient/RM',
        'Control visit still creates a new visit',
        'Old visit / old RME / old odontogram / old invoice not overwritten',
        'Parent receivable can appear in cashier control',
        'Payment allocation remains FIFO previous receivable first',
        'Parent receivable does not block control completion',
        'Rp0 invoice does not appear in active receivables',
    ] as $expected) {
        expect($content)->toContain($expected);
    }
});

it('includes the support and admin checklist content', function (): void {
    $content = (string) file_get_contents(base_path('docs/sprint_28_phase_28_1_pilot_readiness_operator_smoke_checklist.md'));

    foreach ([
        'Support / Admin Checklist',
        'Laravel log check',
        'Backup presence check',
        'Disk usage check',
        'Route/menu quick check',
        'User/role quick check',
        'operator feedback collection',
    ] as $expected) {
        expect($content)->toContain($expected);
    }
});

it('updates sprint history with Sprint 28 Phase 28.1', function (): void {
    $content = (string) file_get_contents(base_path('docs/sprint_history.md'));

    foreach ([
        'Sprint 28 Phase 28.1',
        'docs/sprint_28_phase_28_1_pilot_readiness_operator_smoke_checklist.md',
    ] as $expected) {
        expect($content)->toContain($expected);
    }
});
