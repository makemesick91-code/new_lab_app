<?php

use function PHPUnit\Framework\assertFileExists;

function sprint34Doc(): string
{
    return (string) file_get_contents(base_path('docs/sprint_34_post_go_live_stabilization_issue_burn_down_operational_closure.md'));
}

function sprint34History(): string
{
    return (string) file_get_contents(base_path('docs/sprint_history.md'));
}

it('documents the Sprint 34 post go-live stabilization with title and baseline', function (): void {
    $path = base_path('docs/sprint_34_post_go_live_stabilization_issue_burn_down_operational_closure.md');

    assertFileExists($path);

    foreach ([
        '# Sprint 34 — Post Go-Live Stabilization, Issue Burn-down & Operational Closure',
        'Status: Draft / Local Validation Pending',
        'Baseline: Sprint 33 GO at 203c5e2',
        '203c5e2',
    ] as $expected) {
        expect(sprint34Doc())->toContain($expected);
    }
});

it('references the Sprint 32 and Sprint 33 GO tags, commits and Sprint 33 feature commit', function (): void {
    foreach ([
        'sprint-32-go-live-readiness-training-handover-sla-go',
        '54ed93a',
        'sprint-33-controlled-go-live-execution-hypercare-watch-go',
        '203c5e2',
        '724c8e2',
    ] as $expected) {
        expect(sprint34Doc())->toContain($expected);
    }
});

it('defines the required Sprint 34 document sections', function (): void {
    foreach ([
        '## Purpose',
        '## Baseline references',
        '## Post go-live stabilization scope',
        '## Stabilization readiness checklist',
        '## Issue burn-down workflow',
        '## Issue severity and burn-down matrix',
        '## Stabilization smoke re-check checklist',
        '## Support metrics review',
        '## Accepted backlog consolidation',
        '## Operational closure gates',
        '## Operational handover closure checklist',
        '## Incident closure and escalation review',
        '## Evidence template',
        '## Issue burn-down log template',
        '## Operational closure sign-off template',
        '## Go / Watch / Extend Hypercare / No-Go criteria',
        '## Explicit out of scope',
        '## Validation commands',
        '## PR readiness marker',
        '## Next sprint recommendation',
    ] as $expected) {
        expect(sprint34Doc())->toContain($expected);
    }
});

it('lists the stabilization readiness checklist items', function (): void {
    foreach ([
        'Sprint 33 hypercare package accepted',
        'Owner/admin acceptance status reviewed',
        'Support channel active/confirmed',
        'Escalation owner assigned',
        'Issue log location confirmed',
        'P0/P1 issue handling rule confirmed',
        'P2/P3 backlog handling rule confirmed',
        'Support coverage confirmed',
        'Operator feedback channel confirmed',
        'Monitoring evidence placeholder reviewed',
        'Backup/restore readiness reference accepted from Sprint 31',
        'SLA/support model reference accepted from Sprint 32',
        'GO / WATCH / EXTEND HYPERCARE / NO-GO decision recorded',
    ] as $expected) {
        expect(sprint34Doc())->toContain($expected);
    }
});

it('describes the issue burn-down workflow steps', function (): void {
    foreach ([
        'Issue intake',
        'Issue severity classification',
        'Duplicate check',
        'Owner assignment',
        'Impact assessment',
        'Workaround/mitigation recording',
        'Resolution target placeholder',
        'Validation evidence capture',
        'Closure approval',
        'Backlog conversion for non-critical items',
        'Daily burn-down review',
        'Unresolved issue escalation',
        'No production bugfix is implemented in this sprint',
    ] as $expected) {
        expect(sprint34Doc())->toContain($expected);
    }
});

it('defines the issue severity and burn-down matrix with P0/P1/P2/P3 levels', function (): void {
    foreach ([
        'P0 — Critical / blocking',
        'P1 — High',
        'P2 — Medium',
        'P3 — Low',
        'production outage, data loss, credential exposure',
        'failed login for key operator',
        'training gap',
        'enhancement request',
        '**Owner:**',
        '**Escalation rule:**',
        '**Closure rule:**',
        '**Backlog rule:**',
        '**Response target placeholder:**',
        '**Resolution target placeholder:**',
    ] as $expected) {
        expect(sprint34Doc())->toContain($expected);
    }
});

it('describes the stabilization smoke re-check checklist items', function (): void {
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
        'Support/escalation contact evidence',
        'Monitoring evidence placeholder',
        'Backup/restore evidence location recorded',
        'Owner/admin stabilization acceptance recorded',
    ] as $expected) {
        expect(sprint34Doc())->toContain($expected);
    }
});

it('provides the support metrics review placeholders', function (): void {
    foreach ([
        'Support window',
        'Total issue count',
        'Open issue count',
        'Closed issue count',
        'Average response time',
        'Average resolution time',
        'Unresolved issue owner',
        'Recurring issue pattern',
        'Training gap count',
        'Documentation gap count',
        'Change request count',
        'Owner/admin satisfaction note',
        'Operational closure recommendation',
    ] as $expected) {
        expect(sprint34Doc())->toContain($expected);
    }
});

it('defines accepted backlog consolidation with backlog types', function (): void {
    foreach ([
        'Backlog ID',
        'Source issue ID',
        'Backlog type',
        'Acceptance criteria',
        'bugfix',
        'enhancement',
        'documentation',
        'training',
        'operational support',
        'deferred risk',
    ] as $expected) {
        expect(sprint34Doc())->toContain($expected);
    }
});

it('defines the operational closure gates', function (): void {
    foreach ([
        'No unresolved P0',
        'P1 issues resolved or accepted with mitigation',
        'P2/P3 issues logged with owner and priority',
        'Support metrics reviewed',
        'Operator feedback reviewed',
        'Training gaps identified',
        'Documentation gaps identified',
        'Accepted backlog consolidated',
        'Support/SLA handover confirmed',
        'Owner/admin acceptance recorded',
    ] as $expected) {
        expect(sprint34Doc())->toContain($expected);
    }
});

it('defines the operational handover closure checklist', function (): void {
    foreach ([
        'Stable branch and GO tag recorded',
        'Support contact list placeholder',
        'Escalation matrix accepted',
        'Incident reporting SOP accepted',
        'RME SOP accepted',
        'Cashier/payment SOP accepted',
        'Receivable/piutang SOP accepted',
        'Print/export SOP accepted',
        'WhatsApp manual reminder SOP accepted',
        'Backup/restore readiness SOP reference accepted',
        'SLA/support model accepted',
        'Known limitations accepted',
        'Open backlog accepted',
        'Closure sign-off captured',
    ] as $expected) {
        expect(sprint34Doc())->toContain($expected);
    }
});

it('defines the incident closure and escalation review fields', function (): void {
    foreach ([
        'Incident summary',
        'Root cause placeholder',
        'Workaround used',
        'Permanent fix status',
        'Escalation path used',
        'Customer/operator communication',
        'Closure decision',
        'Prevention note',
        'no fixes implemented this',
    ] as $expected) {
        expect(sprint34Doc())->toContain($expected);
    }
});

it('provides the evidence, burn-down log and closure sign-off templates', function (): void {
    foreach ([
        'Reviewer/approver',
        'Evidence path',
        'Follow-up owner',
        'Issue ID',
        'Module/workflow',
        'Closure date',
        'Closure area',
        'Owner/approver',
        'Sign-off date',
    ] as $expected) {
        expect(sprint34Doc())->toContain($expected);
    }
});

it('defines the Go / Watch / Extend Hypercare / No-Go criteria', function (): void {
    foreach ([
        '**GO**',
        '**WATCH**',
        '**EXTEND HYPERCARE**',
        '**NO-GO**',
    ] as $expected) {
        expect(sprint34Doc())->toContain($expected);
    }
});

it('includes the explicit out-of-scope safety restrictions', function (): void {
    foreach ([
        'No production code change.',
        'No bugfix implementation.',
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
        expect(sprint34Doc())->toContain($expected);
    }
});

it('contains the exact PR readiness marker and next sprint recommendation', function (): void {
    expect(sprint34Doc())->toContain('GO CANDIDATE FOR PR REVIEW');
    expect(sprint34Doc())->toContain('Sprint 35 — Production Operations Baseline, Continuous Improvement & Roadmap Lock');
});

it('records the Sprint 34 entry in sprint history', function (): void {
    foreach ([
        'Sprint 34 — Post Go-Live Stabilization, Issue Burn-down & Operational Closure',
        'docs/sprint_34_post_go_live_stabilization_issue_burn_down_operational_closure.md',
        'Sprint 33 GO at `203c5e2`',
        '203c5e2',
        'Sprint 35 — Production Operations Baseline, Continuous Improvement & Roadmap Lock',
        'GO CANDIDATE FOR PR REVIEW',
    ] as $expected) {
        expect(sprint34History())->toContain($expected);
    }
});
