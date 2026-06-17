<?php

use function PHPUnit\Framework\assertFileExists;

function sprint33Doc(): string
{
    return (string) file_get_contents(base_path('docs/sprint_33_controlled_go_live_execution_hypercare_watch.md'));
}

function sprint33History(): string
{
    return (string) file_get_contents(base_path('docs/sprint_history.md'));
}

it('documents the Sprint 33 controlled go-live execution and hypercare watch with baseline', function (): void {
    $path = base_path('docs/sprint_33_controlled_go_live_execution_hypercare_watch.md');

    assertFileExists($path);

    foreach ([
        '# Sprint 33 — Controlled Go-Live Execution & Hypercare Watch',
        'Status: Draft / Local Validation Pending',
        'Baseline: Sprint 32 GO at 54ed93a',
        '54ed93a',
    ] as $expected) {
        expect(sprint33Doc())->toContain($expected);
    }
});

it('references the Sprint 31 and Sprint 32 GO tags, commits and Sprint 32 feature commit', function (): void {
    foreach ([
        'sprint-31-backup-restore-rehearsal-execution-recovery-readiness-go',
        '0ad4a45',
        'sprint-32-go-live-readiness-training-handover-sla-go',
        '54ed93a',
        '1b53cd2',
    ] as $expected) {
        expect(sprint33Doc())->toContain($expected);
    }
});

it('defines the required Sprint 33 document sections', function (): void {
    foreach ([
        '## Purpose',
        '## Baseline references',
        '## Controlled go-live scope',
        '## Pre-go-live approval gates',
        '## Controlled go-live execution runbook placeholder',
        '## Post-go-live smoke verification checklist',
        '## Hypercare watch checklist',
        '## Incident triage and escalation matrix',
        '## Rollback and recovery decision checklist',
        '## Communication plan',
        '## Evidence template',
        '## Hypercare issue log template',
        '## Go-live acceptance template',
        '## Hypercare closure criteria',
        '## Go / Watch / No-Go / Rollback criteria',
        '## Explicit out of scope',
        '## Validation commands',
        '## PR readiness marker',
        '## Next sprint recommendation',
    ] as $expected) {
        expect(sprint33Doc())->toContain($expected);
    }
});

it('lists the pre-go-live approval gates', function (): void {
    foreach ([
        'Sprint 32 readiness accepted',
        'Operator training accepted',
        'Handover package accepted',
        'Owner/admin sign-off captured',
        'Support contact assigned',
        'Escalation owner assigned',
        'Rollback/escalation path documented',
        'Privacy/data handling review complete',
        'Backup/restore readiness reference accepted from Sprint 31',
        'Known limitations accepted',
        'Communication channel confirmed',
        'Support coverage confirmed',
        'GO / WATCH / NO-GO decision recorded',
    ] as $expected) {
        expect(sprint33Doc())->toContain($expected);
    }
});

it('contains a controlled go-live execution runbook placeholder with supervised safeguards', function (): void {
    foreach ([
        'This sprint does not execute go-live.',
        'separate supervised workflow',
        'Phase 0 — pre-execution confirmation',
        'Phase 1 — final readiness review',
        'Phase 2 — deployment/go-live approval gate',
        'Phase 3 — supervised go-live execution placeholder',
        'Phase 4 — immediate post-go-live smoke verification',
        'Phase 5 — owner/admin acceptance',
        'Phase 6 — hypercare watch activation',
        'Phase 7 — issue tracking and stabilization review',
    ] as $expected) {
        expect(sprint33Doc())->toContain($expected);
    }
});

it('describes the post-go-live smoke verification checklist items', function (): void {
    foreach ([
        'Application boots',
        'Login page reachable',
        'Key roles can access menus',
        'RME visit workflow smoke',
        'Odontogram/medical record smoke',
        'Cashier invoice/payment smoke',
        'Receivable/piutang smoke',
        'Print/export smoke',
        'WhatsApp manual reminder SOP evidence only',
        'Reporting smoke',
        'Monitoring evidence placeholder',
        'Backup/restore readiness evidence location recorded',
        'Owner/admin smoke acceptance recorded',
    ] as $expected) {
        expect(sprint33Doc())->toContain($expected);
    }
});

it('describes the hypercare watch checklist items', function (): void {
    foreach ([
        'Watch window start/end placeholder',
        'Support owner assignment',
        'Daily issue triage',
        'Daily operator feedback',
        'Critical workflow watch',
        'RME workflow watch',
        'Cashier/payment watch',
        'Receivable/piutang watch',
        'Print/export watch',
        'Login/access watch',
        'Monitoring evidence review',
        'Incident log review',
        'Unresolved issue escalation',
        'End-of-day summary',
        'Hypercare closure recommendation',
    ] as $expected) {
        expect(sprint33Doc())->toContain($expected);
    }
});

it('defines the incident triage and escalation matrix with P0/P1/P2/P3 severity levels', function (): void {
    foreach ([
        '**P0:**',
        '**P1:**',
        '**P2:**',
        '**P3:**',
        'production outage',
        'credential exposure',
        'Owner:',
        'Escalation rule:',
        'Communication rule:',
        'Decision outcome:',
    ] as $expected) {
        expect(sprint33Doc())->toContain($expected);
    }
});

it('defines the rollback and recovery decision checklist', function (): void {
    foreach ([
        'Rollback trigger identified',
        'Recovery owner assigned',
        'Impact scope confirmed',
        'Backup/recovery readiness evidence reviewed',
        'Operator/admin notified',
        'Escalation contact notified',
        'Decision recorded as continue / watch / rollback / stop',
        'Post-decision evidence captured',
    ] as $expected) {
        expect(sprint33Doc())->toContain($expected);
    }
});

it('provides the communication plan, evidence, issue log and acceptance templates', function (): void {
    foreach ([
        'Owner/admin announcement',
        'Operator go-live notice',
        'Closure announcement',
        'checklist item',
        'evidence path',
        'follow-up owner',
        'issue ID',
        'module/workflow',
        'closure date',
        'acceptance area',
        'owner/approver',
        'sign-off date',
    ] as $expected) {
        expect(sprint33Doc())->toContain($expected);
    }
});

it('defines hypercare closure and Go / Watch / No-Go / Rollback criteria', function (): void {
    foreach ([
        'No unresolved P0',
        'GO / WATCH / EXTEND HYPERCARE / ROLLBACK recommendation recorded',
        '**GO**',
        '**WATCH**',
        '**NO-GO**',
        '**ROLLBACK**',
    ] as $expected) {
        expect(sprint33Doc())->toContain($expected);
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
        'No rollback execution.',
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
        expect(sprint33Doc())->toContain($expected);
    }
});

it('contains the exact PR readiness marker and next sprint recommendation', function (): void {
    expect(sprint33Doc())->toContain('GO CANDIDATE FOR PR REVIEW');
    expect(sprint33Doc())->toContain('Sprint 34 — Post Go-Live Stabilization, Issue Burn-down & Operational Closure');
});

it('records the Sprint 33 entry in sprint history', function (): void {
    foreach ([
        'Sprint 33 — Controlled Go-Live Execution & Hypercare Watch',
        'docs/sprint_33_controlled_go_live_execution_hypercare_watch.md',
        'Sprint 32 GO at `54ed93a`',
        '54ed93a',
        'Sprint 34 — Post Go-Live Stabilization, Issue Burn-down & Operational Closure',
        'GO CANDIDATE FOR PR REVIEW',
    ] as $expected) {
        expect(sprint33History())->toContain($expected);
    }
});
