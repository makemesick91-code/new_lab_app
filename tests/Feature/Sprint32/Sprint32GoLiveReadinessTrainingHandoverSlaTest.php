<?php

use function PHPUnit\Framework\assertFileExists;

function sprint32Doc(): string
{
    return (string) file_get_contents(base_path('docs/sprint_32_go_live_readiness_training_handover_sla.md'));
}

function sprint32History(): string
{
    return (string) file_get_contents(base_path('docs/sprint_history.md'));
}

it('documents the Sprint 32 go-live readiness, training, handover and SLA', function (): void {
    $path = base_path('docs/sprint_32_go_live_readiness_training_handover_sla.md');

    assertFileExists($path);

    foreach ([
        '# Sprint 32 — Go-Live Readiness, Training, Handover & SLA',
        'Status: Draft / Local Validation Pending',
        'Baseline: Sprint 31 GO at 0ad4a45',
        '0ad4a45',
    ] as $expected) {
        expect(sprint32Doc())->toContain($expected);
    }
});

it('references the Sprint 30 and Sprint 31 GO tags, commits and Sprint 31 feature commit', function (): void {
    foreach ([
        'sprint-30-pilot-execution-bugfix-operational-smoke-go',
        '53c3442',
        'sprint-31-backup-restore-rehearsal-execution-recovery-readiness-go',
        '0ad4a45',
        'de85daf',
    ] as $expected) {
        expect(sprint32Doc())->toContain($expected);
    }
});

it('defines the required Sprint 32 document sections', function (): void {
    foreach ([
        '## Purpose',
        '## Baseline references',
        '## Go-live readiness scope',
        '## Operational readiness checklist',
        '## Training plan',
        '## Handover package checklist',
        '## SLA/support model',
        '## Go-live decision gates',
        '## Go / Watch / No-Go criteria',
        '## Go-live runbook placeholder',
        '## Evidence template',
        '## Training acceptance template',
        '## Handover sign-off template',
        '## Explicit out of scope',
        '## Validation commands',
        '## PR readiness marker',
        '## Next sprint recommendation',
    ] as $expected) {
        expect(sprint32Doc())->toContain($expected);
    }
});

it('describes the operational readiness checklist items', function (): void {
    foreach ([
        'Branch/operator readiness',
        'Role and permission readiness',
        'Login/access readiness',
        'RME workflow readiness',
        'Odontogram/medical record readiness',
        'Cashier invoice/payment readiness',
        'Receivable/piutang readiness',
        'Print/export readiness',
        'WhatsApp manual reminder SOP readiness',
        'Monitoring evidence readiness',
        'Backup/restore readiness reference',
        'Support contact readiness',
        'Escalation path readiness',
        'Owner/admin acceptance readiness',
    ] as $expected) {
        expect(sprint32Doc())->toContain($expected);
    }
});

it('defines the training plan audience groups', function (): void {
    foreach ([
        'Owner / management',
        'Admin cabang',
        'Cashier',
        'Doctor / clinic operator using RME',
        'Lab/admin operator',
        'Support/technical maintainer',
    ] as $expected) {
        expect(sprint32Doc())->toContain($expected);
    }
});

it('covers the handover package checklist and SLA/support model with severity levels', function (): void {
    foreach ([
        'Application scope summary',
        'Current stable branch and GO tag',
        'Incident reporting SOP',
        'Escalation matrix',
        'Known limitations',
        'Open risks',
        'Acceptance sign-off',
        'Support hours',
        'Response target',
        'Resolution target',
        '**P0:**',
        '**P1:**',
        '**P2:**',
        '**P3:**',
    ] as $expected) {
        expect(sprint32Doc())->toContain($expected);
    }
});

it('defines the go-live decision gates and Go / Watch / No-Go criteria', function (): void {
    foreach ([
        'Sprint 30 pilot smoke accepted',
        'Sprint 31 recovery readiness accepted',
        'Operator training complete',
        'Handover package complete',
        'Support contact assigned',
        'Escalation owner assigned',
        'Privacy/data handling review complete',
        'Known limitations accepted',
        'Rollback/escalation path documented',
        'Owner/admin sign-off recorded',
        'GO / WATCH / NO-GO decision recorded',
        '**GO**',
        '**WATCH**',
        '**NO-GO**',
    ] as $expected) {
        expect(sprint32Doc())->toContain($expected);
    }
});

it('contains a go-live runbook placeholder with separate supervised workflow safeguards', function (): void {
    foreach ([
        'This sprint does not execute go-live.',
        'separate supervised workflow',
        'Pre-go-live confirmation',
        'Final backup/recovery readiness confirmation',
        'Operator availability confirmation',
        'Support coverage confirmation',
        'Go-live execution approval gate',
        'Post-go-live smoke verification',
    ] as $expected) {
        expect(sprint32Doc())->toContain($expected);
    }
});

it('provides the evidence, training acceptance and handover sign-off templates', function (): void {
    foreach ([
        'audience/group',
        'readiness item',
        'expected result',
        'actual result',
        'evidence path',
        'issue severity',
        'follow-up owner',
        'trainee group',
        'module trained',
        'scenario demonstrated',
        'handover item',
        'accepted status',
        'sign-off date',
    ] as $expected) {
        expect(sprint32Doc())->toContain($expected);
    }
});

it('includes the explicit out-of-scope safety restrictions', function (): void {
    foreach ([
        'No production code change.',
        'No migration.',
        'No deployment.',
        'No production/VPS access.',
        'No real go-live execution.',
        'No real backup execution.',
        'No real restore execution.',
        'No destructive operation.',
        'No monitoring automation.',
        'No backup automation.',
        'No restore automation.',
        'No cron/scheduler/job/queue/notification change.',
        'No runtime behavior change.',
        'No route/controller/service/model/view/config/seeder change.',
        'No WhatsApp automation/send.',
        'No external service call.',
        'No `.env` change.',
        'No dependency/package install.',
    ] as $expected) {
        expect(sprint32Doc())->toContain($expected);
    }
});

it('contains the exact PR readiness marker and next sprint recommendation', function (): void {
    expect(sprint32Doc())->toContain('GO CANDIDATE FOR PR REVIEW');
    expect(sprint32Doc())->toContain('Sprint 33 — Controlled Go-Live Execution & Hypercare Watch');
});

it('records the Sprint 32 entry in sprint history', function (): void {
    foreach ([
        'Sprint 32 — Go-Live Readiness, Training, Handover & SLA',
        'docs/sprint_32_go_live_readiness_training_handover_sla.md',
        'Sprint 31 GO at `0ad4a45`',
        '0ad4a45',
        'Sprint 33 — Controlled Go-Live Execution & Hypercare Watch',
        'GO CANDIDATE FOR PR REVIEW',
    ] as $expected) {
        expect(sprint32History())->toContain($expected);
    }
});
