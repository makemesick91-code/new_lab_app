<?php

it('documents Sprint 30 pilot execution bugfix and operational smoke', function (): void {
    $path = base_path('docs/sprint_30_pilot_execution_bugfix_operational_smoke.md');

    expect(file_exists($path))->toBeTrue();

    $content = (string) file_get_contents($path);

    foreach ([
        '# Sprint 30 — Pilot Execution Bugfix & Operational Smoke',
        'Status: Draft / Local Validation Complete',
        'Baseline:** Sprint 29.5 GO at `721bb55`',
        '721bb55',
        'GO CANDIDATE FOR PR REVIEW',
    ] as $expected) {
        expect($content)->toContain($expected);
    }
});

it('references the Sprint 29.0 to 29.5 GO tags and merge commits as baseline lineage', function (): void {
    $content = (string) file_get_contents(base_path('docs/sprint_30_pilot_execution_bugfix_operational_smoke.md'));

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
        'sprint-29-phase-29-5-pilot-safety-review-final-stabilization-checklist-go',
    ] as $expected) {
        expect($content)->toContain($expected);
    }
});

it('defines the required Sprint 30 document sections', function (): void {
    $content = (string) file_get_contents(base_path('docs/sprint_30_pilot_execution_bugfix_operational_smoke.md'));

    foreach ([
        '## Scope',
        '## Safety boundaries',
        '## Local operational smoke plan',
        '## Smoke scenarios',
        '## Findings table',
        '## Bugfix summary',
        '## Validation results',
        '## Remaining WATCH items',
        '## No-production / no-deployment statement',
        '## Next recommended phase',
    ] as $expected) {
        expect($content)->toContain($expected);
    }
});

it('covers all required Sprint 30 smoke scenario paths', function (): void {
    $content = (string) file_get_contents(base_path('docs/sprint_30_pilot_execution_bugfix_operational_smoke.md'));

    foreach ([
        'Patient registration / patient identity',
        'RME visit creation',
        'Odontogram / treatment note path',
        'Invoice creation',
        'Payment recording',
        'Receivable / piutang tracking',
        'Receipt / print / export readiness',
        'Cashier workflow',
        'RME control workflow',
        'WhatsApp manual reminder evidence path',
        'Reporting / export / print path',
        'User role / menu / permission access for pilot roles',
        'Monitoring / backup / restore rehearsal evidence readiness',
    ] as $expected) {
        expect($content)->toContain($expected);
    }
});

it('records the local-only validation evidence', function (): void {
    $content = (string) file_get_contents(base_path('docs/sprint_30_pilot_execution_bugfix_operational_smoke.md'));

    foreach ([
        '202 passed (548 assertions)',
        '101 passed (266 assertions)',
        '303 tests passed',
        'No production code bugfix required in this local pass.',
        'git diff --check',
    ] as $expected) {
        expect($content)->toContain($expected);
    }
});

it('includes the out-of-scope safety boundaries', function (): void {
    $content = (string) file_get_contents(base_path('docs/sprint_30_pilot_execution_bugfix_operational_smoke.md'));

    foreach ([
        'No deployment.',
        'No production/VPS access.',
        'No real backup execution.',
        'No real restore execution.',
        'No destructive DB action against production.',
        'No WhatsApp sending automation.',
        'No dependency/package install.',
        'No `.env` change.',
        'No GO tag in this local pass.',
        'performed **no production action**',
    ] as $expected) {
        expect($content)->toContain($expected);
    }
});

it('records the Sprint 30 entry in sprint history', function (): void {
    $content = (string) file_get_contents(base_path('docs/sprint_history.md'));

    foreach ([
        'Sprint 30 — Pilot Execution Bugfix & Operational Smoke',
        'docs/sprint_30_pilot_execution_bugfix_operational_smoke.md',
        'Sprint 29.5 GO at `721bb55`',
        '721bb55',
        'GO CANDIDATE FOR PR REVIEW',
    ] as $expected) {
        expect($content)->toContain($expected);
    }
});
