<?php

it('documents Sprint 28 Phase 28.2 pilot daily operation runbook', function (): void {
    $path = base_path('docs/sprint_28_phase_28_2_pilot_daily_operation_runbook.md');

    expect(file_exists($path))->toBeTrue();

    $content = (string) file_get_contents($path);

    foreach ([
        'Sprint 28 Phase 28.2',
        'Pilot Daily Operation Runbook',
        'fa9842f',
        'GO CANDIDATE FOR PR REVIEW',
    ] as $expected) {
        expect($content)->toContain($expected);
    }
});

it('includes the pre-opening and daily operator flow content', function (): void {
    $content = (string) file_get_contents(base_path('docs/sprint_28_phase_28_2_pilot_daily_operation_runbook.md'));

    foreach ([
        'Pre-Opening Checklist',
        'App reachable',
        'Operator account ready',
        'Menu visibility ready',
        'Printer/browser print ready',
        'Backup presence checked',
        'Laravel log baseline checked',
        'Disk usage checked',
        'Feedback log ready',
        'Daily Operator Flow',
        'Patient search',
        'New patient registration smoke',
        'Visit creation',
        'RME visit input',
        'Odontogram input',
        'Logout/session check',
    ] as $expected) {
        expect($content)->toContain($expected);
    }
});

it('includes the RME control visit daily guardrail content', function (): void {
    $content = (string) file_get_contents(base_path('docs/sprint_28_phase_28_2_pilot_daily_operation_runbook.md'));

    foreach ([
        'RME Control Visit Daily Guardrail',
        'Same patient/RM',
        'New visit created',
        'Old data protected',
        'Parent receivable visible',
        'FIFO allocation protected',
        'Completion not blocked by parent receivable',
        'Rp0 invoice excluded from active receivables',
    ] as $expected) {
        expect($content)->toContain($expected);
    }
});

it('includes the cashier, receivable and monitoring flow content', function (): void {
    $content = (string) file_get_contents(base_path('docs/sprint_28_phase_28_2_pilot_daily_operation_runbook.md'));

    foreach ([
        'Cashier / Receivable Flow',
        'Open cashier billing',
        'Check previous receivable',
        'Process payment',
        'Verify FIFO behavior',
        'Print receipt',
        'Active receivable check',
        'Support / Admin Monitoring Flow',
        'Laravel log check',
        'Backup presence check',
        'Disk usage check',
        'Route/menu quick check',
        'User/role quick check',
        'Feedback collection check',
    ] as $expected) {
        expect($content)->toContain($expected);
    }
});

it('includes the feedback log, closing checklist and escalation rules', function (): void {
    $content = (string) file_get_contents(base_path('docs/sprint_28_phase_28_2_pilot_daily_operation_runbook.md'));

    foreach ([
        'Operator Feedback Log Format',
        'Follow-up owner',
        'End-of-Day Closing Checklist',
        'Daily issue summary prepared',
        'Next-day action list prepared',
        'Escalation Rules',
        'Multi-user login failure',
        'Control visit does not create a new visit',
        'GO / NO-GO',
    ] as $expected) {
        expect($content)->toContain($expected);
    }
});

it('declares no production, migration, deploy or destructive operation', function (): void {
    $content = (string) file_get_contents(base_path('docs/sprint_28_phase_28_2_pilot_daily_operation_runbook.md'));

    foreach ([
        'No deployment',
        'No migration',
        'No production code change',
        'No destructive data operation',
    ] as $expected) {
        expect($content)->toContain($expected);
    }
});

it('records the Sprint 28 Phase 28.2 entry in sprint history', function (): void {
    $content = (string) file_get_contents(base_path('docs/sprint_history.md'));

    foreach ([
        'Sprint 28 Phase 28.2',
        'docs/sprint_28_phase_28_2_pilot_daily_operation_runbook.md',
        'Sprint 28.1 GO at `fa9842f`',
    ] as $expected) {
        expect($content)->toContain($expected);
    }
});
