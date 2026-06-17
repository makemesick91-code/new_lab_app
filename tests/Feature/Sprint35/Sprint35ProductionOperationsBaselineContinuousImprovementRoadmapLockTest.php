<?php

use function PHPUnit\Framework\assertFileExists;

function sprint35Doc(): string
{
    return (string) file_get_contents(base_path('docs/sprint_35_production_operations_baseline_continuous_improvement_roadmap_lock.md'));
}

function sprint35History(): string
{
    return (string) file_get_contents(base_path('docs/sprint_history.md'));
}

it('documents the Sprint 35 baseline with title, status and baseline commit', function (): void {
    $path = base_path('docs/sprint_35_production_operations_baseline_continuous_improvement_roadmap_lock.md');

    assertFileExists($path);

    foreach ([
        '# Sprint 35 — Production Operations Baseline, Continuous Improvement & Roadmap Lock',
        'Status: Draft / Local Validation Pending',
        'Baseline: Sprint 34 GO at 2b594a3',
        '2b594a3',
    ] as $expected) {
        expect(sprint35Doc())->toContain($expected);
    }
});

it('references the Sprint 33 and Sprint 34 GO tags, commits and Sprint 34 feature commit', function (): void {
    foreach ([
        'sprint-33-controlled-go-live-execution-hypercare-watch-go',
        '203c5e2',
        'sprint-34-post-go-live-stabilization-issue-burn-down-operational-closure-go',
        '2b594a3',
        '84c98f8',
    ] as $expected) {
        expect(sprint35Doc())->toContain($expected);
    }
});

it('defines the required Sprint 35 document sections', function (): void {
    foreach ([
        '## Purpose',
        '## Baseline references',
        '## Production operations baseline scope',
        '## Normal operations baseline checklist',
        '## Support metrics baseline',
        '## Continuous improvement backlog review',
        '## Roadmap lock framework',
        '## Ownership and governance model',
        '## Long-term operational monitoring policy',
        '## Change control and release governance',
        '## Incident and support governance review',
        '## Operations acceptance gates',
        '## Evidence template',
        '## Support metrics template',
        '## Continuous improvement backlog template',
        '## Roadmap lock sign-off template',
        '## Operations baseline decision criteria',
        '## Explicit out of scope',
        '## Validation commands',
        '## PR readiness marker',
        '## Next sprint recommendation',
    ] as $expected) {
        expect(sprint35Doc())->toContain($expected);
    }
});

it('describes the production operations baseline scope', function (): void {
    foreach ([
        'production operations baseline documentation only',
        'continuous improvement backlog review only',
        'roadmap lock package only',
        'ownership and governance checklist only',
        'no actual production operation execution in this sprint',
        'manual operator-confirmed checklist only',
    ] as $expected) {
        expect(sprint35Doc())->toContain($expected);
    }
});

it('lists the normal operations baseline checklist items', function (): void {
    foreach ([
        'Stable base branch recorded',
        'Latest GO tag recorded',
        'Owner/admin acceptance from Sprint 34 referenced',
        'Support contact list confirmed',
        'Escalation owner confirmed',
        'Incident reporting SOP accepted',
        'SLA/support model accepted from Sprint 32',
        'Hypercare closure reference accepted from Sprint 33',
        'Operational closure reference accepted from Sprint 34',
        'Backup/restore readiness reference accepted from Sprint 31',
        'Known limitations recorded',
        'Accepted backlog recorded',
        'Monitoring evidence placeholder defined',
        'Support routine defined',
        'Change request intake defined',
        'GO / WATCH / EXTEND SUPPORT / NO-GO decision recorded',
    ] as $expected) {
        expect(sprint35Doc())->toContain($expected);
    }
});

it('provides the support metrics baseline placeholders', function (): void {
    foreach ([
        'Baseline period',
        'Support hours',
        'Total issue count',
        'Open issue count',
        'Closed issue count',
        'Average response time',
        'Average resolution time',
        'Recurring issue count',
        'Recurring issue pattern',
        'Training gap count',
        'Documentation gap count',
        'Change request count',
        'Deferred risk count',
        'Owner/admin satisfaction note',
        'Operations baseline decision',
    ] as $expected) {
        expect(sprint35Doc())->toContain($expected);
    }
});

it('describes the continuous improvement backlog review workflow', function (): void {
    foreach ([
        'Backlog source review',
        'Issue-to-backlog conversion',
        'Duplicate backlog check',
        'Business impact review',
        'Operational risk review',
        'Priority assignment',
        'Owner assignment',
        'Acceptance criteria definition',
        'Dependency review',
        'Deferred risk review',
        'Roadmap candidate decision',
        'Backlog lock sign-off',
        'No bugfix or enhancement implementation is performed in this sprint',
    ] as $expected) {
        expect(sprint35Doc())->toContain($expected);
    }
});

it('defines the roadmap lock framework categories', function (): void {
    foreach ([
        '### Stabilization',
        '### Compliance/safety',
        '### RME workflow improvement',
        '### Cashier/payment/receivable improvement',
        '### Reporting/export improvement',
        '### WhatsApp/manual reminder operationalization',
        '### Monitoring/backup/recovery governance',
        '### Training/documentation improvement',
        '### UX/UI improvement',
        '### Branch expansion readiness',
        '### Technical debt',
        '### Future automation candidate',
        '**Objective:**',
        '**Lock decision:**',
    ] as $expected) {
        expect(sprint35Doc())->toContain($expected);
    }
});

it('defines the ownership and governance model roles', function (): void {
    foreach ([
        '**Product owner**',
        '**Operational owner**',
        '**Support owner**',
        '**Technical owner**',
        '**Escalation owner**',
        '**Training/documentation owner**',
        '**Data/privacy owner**',
        '**Backup/recovery owner**',
        '**Roadmap owner**',
        '**Approval authority**',
        '**Responsibility:**',
        '**Decision authority:**',
        '**Backup owner:**',
        '**Evidence location:**',
    ] as $expected) {
        expect(sprint35Doc())->toContain($expected);
    }
});

it('defines the long-term operational monitoring policy', function (): void {
    foreach ([
        'Manual monitoring evidence review',
        'App availability check placeholder',
        'Login/access smoke placeholder',
        'Critical workflow smoke placeholder',
        'RME/cashier/receivable smoke placeholder',
        'Reporting/export smoke placeholder',
        'Backup/restore evidence review placeholder',
        'Incident log review',
        'Support metrics review',
        'No monitoring automation is created in this sprint',
    ] as $expected) {
        expect(sprint35Doc())->toContain($expected);
    }
});

it('defines the change control and release governance checklist', function (): void {
    foreach ([
        'Change request intake',
        'Impact classification',
        'Risk classification',
        'Approval gate',
        'Target sprint assignment',
        'Test scope definition',
        'Documentation update requirement',
        'Rollout rule',
        'Rollback/escalation reference',
        'Post-change review',
        'Release tag policy',
        'GO tag policy',
        'Emergency change rule',
    ] as $expected) {
        expect(sprint35Doc())->toContain($expected);
    }
});

it('defines the incident and support governance review with P0/P1/P2/P3 levels', function (): void {
    foreach ([
        '### P0 — Critical / blocking',
        '### P1 — High',
        '### P2 — Medium',
        '### P3 — Low',
        'production outage, data loss, credential exposure',
        'failed login for key operator',
        'training gap',
        'enhancement request',
        '**Owner:**',
        '**Expected action:**',
        '**Response target placeholder:**',
        '**Resolution target placeholder:**',
        '**Escalation rule:**',
        '**Closure rule:**',
        '**Backlog rule:**',
    ] as $expected) {
        expect(sprint35Doc())->toContain($expected);
    }
});

it('defines the operations acceptance gates', function (): void {
    foreach ([
        'Sprint 34 operational closure accepted',
        'Support metrics baseline recorded',
        'Accepted backlog consolidated',
        'Roadmap categories reviewed',
        'Roadmap lock decision recorded',
        'Ownership model assigned',
        'Monitoring policy documented',
        'Change control rule accepted',
        'Incident/support governance accepted',
        'Known limitations accepted',
        'Deferred risks accepted',
        'Owner/admin acceptance recorded',
    ] as $expected) {
        expect(sprint35Doc())->toContain($expected);
    }
});

it('provides the evidence, support metrics, backlog and roadmap lock templates', function (): void {
    foreach ([
        'Reviewer/approver',
        'Evidence path',
        'Follow-up owner',
        'Metric name',
        'Baseline value',
        'Review frequency',
        'Backlog ID',
        'Acceptance criteria',
        'Roadmap decision',
        'Lock status',
        'Sign-off date',
    ] as $expected) {
        expect(sprint35Doc())->toContain($expected);
    }
});

it('defines the operations baseline decision criteria', function (): void {
    foreach ([
        '**GO**',
        '**WATCH**',
        '**EXTEND SUPPORT**',
        '**NO-GO**',
    ] as $expected) {
        expect(sprint35Doc())->toContain($expected);
    }
});

it('includes the explicit out-of-scope safety restrictions', function (): void {
    foreach ([
        'No production code change.',
        'No bugfix implementation.',
        'No enhancement implementation.',
        'No migration.',
        'No deployment.',
        'No production/VPS access.',
        'No real go-live execution.',
        'No real post-go-live operation execution.',
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
        expect(sprint35Doc())->toContain($expected);
    }
});

it('contains the exact PR readiness marker and next sprint recommendation', function (): void {
    expect(sprint35Doc())->toContain('GO CANDIDATE FOR PR REVIEW');
    expect(sprint35Doc())->toContain('Sprint 36 — Operational Governance, Maintenance Cadence & Expansion Readiness');
});

it('records the Sprint 35 entry in sprint history', function (): void {
    foreach ([
        'Sprint 35 — Production Operations Baseline, Continuous Improvement & Roadmap Lock',
        'docs/sprint_35_production_operations_baseline_continuous_improvement_roadmap_lock.md',
        'Sprint 34 GO at `2b594a3`',
        '2b594a3',
        'Sprint 36 — Operational Governance, Maintenance Cadence & Expansion Readiness',
        'GO CANDIDATE FOR PR REVIEW',
    ] as $expected) {
        expect(sprint35History())->toContain($expected);
    }
});
