<?php

use function PHPUnit\Framework\assertFileExists;

function sprint36Doc(): string
{
    return (string) file_get_contents(base_path('docs/sprint_36_operational_governance_maintenance_cadence_expansion_readiness.md'));
}

function sprint36History(): string
{
    return (string) file_get_contents(base_path('docs/sprint_history.md'));
}

it('documents the Sprint 36 baseline with title, status and baseline commit', function (): void {
    $path = base_path('docs/sprint_36_operational_governance_maintenance_cadence_expansion_readiness.md');

    assertFileExists($path);

    foreach ([
        '# Sprint 36 — Operational Governance, Maintenance Cadence & Expansion Readiness',
        'Status: Draft / Local Validation Pending',
        'Baseline: Sprint 35 GO at c4293ec',
        'c4293ec',
    ] as $expected) {
        expect(sprint36Doc())->toContain($expected);
    }
});

it('references the Sprint 34 and Sprint 35 GO tags, commits and Sprint 35 feature commit', function (): void {
    foreach ([
        'sprint-34-post-go-live-stabilization-issue-burn-down-operational-closure-go',
        '2b594a3',
        'sprint-35-production-operations-baseline-continuous-improvement-roadmap-lock-go',
        'c4293ec',
        'c7d5dcb',
    ] as $expected) {
        expect(sprint36Doc())->toContain($expected);
    }
});

it('defines the operational governance scope', function (): void {
    foreach ([
        'operational governance documentation only',
        'maintenance cadence package only',
        'expansion readiness checklist only',
        'roadmap execution governance only',
        'ownership discipline checklist only',
        'no actual production operation execution in this sprint',
        'manual owner/operator-confirmed checklist only',
    ] as $expected) {
        expect(sprint36Doc())->toContain($expected);
    }
});

it('defines the operational governance cadence', function (): void {
    foreach ([
        '## Operational governance cadence',
        'Daily support review placeholder',
        'Weekly operations review placeholder',
        'Monthly owner/admin review placeholder',
        'Incident review cadence',
        'Maintenance review cadence',
        'Branch expansion review cadence',
        'GO / WATCH / EXTEND SUPPORT / NO-GO decision recording',
    ] as $expected) {
        expect(sprint36Doc())->toContain($expected);
    }
});

it('defines the maintenance cadence and maintenance calendar', function (): void {
    foreach ([
        '## Maintenance cadence and maintenance calendar',
        'Maintenance window',
        'Maintenance owner',
        'Pre-maintenance checklist',
        'Post-maintenance validation',
        'Maintenance closure decision',
        'No real maintenance is executed in this sprint',
    ] as $expected) {
        expect(sprint36Doc())->toContain($expected);
    }
});

it('defines the support review cadence', function (): void {
    foreach ([
        '## Support review cadence',
        'Support ticket/intake review',
        'SLA response review',
        'SLA resolution review',
        'Escalation review',
        'Support action item tracking',
    ] as $expected) {
        expect(sprint36Doc())->toContain($expected);
    }
});

it('defines the expansion readiness framework', function (): void {
    foreach ([
        '## Expansion readiness framework',
        'New branch business approval',
        'RME workflow readiness',
        'Cashier/payment readiness',
        'Receivable/piutang readiness',
        'GO / WATCH / NO-GO expansion decision recorded',
        'No real branch expansion is executed in this sprint',
    ] as $expected) {
        expect(sprint36Doc())->toContain($expected);
    }
});

it('defines the controlled roadmap execution policy', function (): void {
    foreach ([
        '## Controlled roadmap execution policy',
        'Roadmap item intake',
        'Approval gate',
        'Release/GO tag rule',
        'Deferred risk handling',
        'No roadmap item is implemented in this sprint',
    ] as $expected) {
        expect(sprint36Doc())->toContain($expected);
    }
});

it('defines the ownership discipline model', function (): void {
    foreach ([
        '## Ownership discipline model',
        '**Product owner**',
        '**Operations owner**',
        '**Escalation owner**',
        '**Monitoring evidence owner**',
        '**Branch expansion owner**',
        '**Approval authority**',
        '**Responsibility:**',
        '**Decision authority:**',
        '**Backup owner:**',
        '**Review cadence:**',
        '**Evidence location:**',
    ] as $expected) {
        expect(sprint36Doc())->toContain($expected);
    }
});

it('defines the governance risk and decision matrix with R0/R1/R2/R3 levels', function (): void {
    foreach ([
        '## Governance risk and decision matrix',
        '### R0/P0 — Critical / blocking',
        '### R1/P1 — High',
        '### R2/P2 — Medium',
        '### R3/P3 — Low',
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
        '**Roadmap/backlog rule:**',
        '**Governance decision:**',
    ] as $expected) {
        expect(sprint36Doc())->toContain($expected);
    }
});

it('defines the long-term monitoring evidence policy', function (): void {
    foreach ([
        '## Long-term monitoring evidence policy',
        'Manual monitoring evidence review',
        'App availability evidence placeholder',
        'RME/cashier/receivable smoke evidence placeholder',
        'Backup/restore readiness evidence review',
        'Evidence retention note',
        'No monitoring automation is created in this sprint',
    ] as $expected) {
        expect(sprint36Doc())->toContain($expected);
    }
});

it('defines the maintenance readiness checklist', function (): void {
    foreach ([
        '## Maintenance readiness checklist',
        'Stable base branch recorded',
        'Latest GO tag recorded',
        'Rollback/escalation reference reviewed',
        'Post-maintenance smoke placeholder defined',
        'GO / WATCH / NO-GO maintenance decision recorded',
    ] as $expected) {
        expect(sprint36Doc())->toContain($expected);
    }
});

it('defines the expansion readiness checklist', function (): void {
    foreach ([
        '## Expansion readiness checklist',
        'Branch expansion request recorded',
        'User/role matrix placeholder',
        'Permissions matrix placeholder',
        'Privacy/data handling review',
        'GO / WATCH / NO-GO expansion decision recorded',
    ] as $expected) {
        expect(sprint36Doc())->toContain($expected);
    }
});

it('defines the operations governance acceptance gates', function (): void {
    foreach ([
        '## Operations governance acceptance gates',
        'Sprint 35 operations baseline accepted',
        'Governance cadence documented',
        'Controlled roadmap execution policy documented',
        'Ownership discipline assigned',
        'Risk/decision matrix accepted',
        'Owner/admin acceptance recorded',
    ] as $expected) {
        expect(sprint36Doc())->toContain($expected);
    }
});

it('provides the evidence, maintenance, expansion and roadmap templates', function (): void {
    foreach ([
        '## Evidence template',
        'Reviewer/approver',
        'Evidence path',
        'Follow-up owner',
        '## Maintenance calendar template',
        'Maintenance ID',
        'Closure status',
        '## Expansion readiness template',
        'Readiness area',
        '## Roadmap execution governance template',
        'Acceptance criteria',
        'Release/GO tag reference',
    ] as $expected) {
        expect(sprint36Doc())->toContain($expected);
    }
});

it('defines the governance decision criteria', function (): void {
    foreach ([
        '## Governance decision criteria',
        '**GO**',
        '**WATCH**',
        '**EXTEND SUPPORT**',
        '**NO-GO**',
    ] as $expected) {
        expect(sprint36Doc())->toContain($expected);
    }
});

it('includes the explicit out-of-scope safety restrictions', function (): void {
    foreach ([
        'No production code change.',
        'No bugfix implementation.',
        'No enhancement implementation.',
        'No roadmap item implementation.',
        'No maintenance execution.',
        'No branch expansion execution.',
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
        expect(sprint36Doc())->toContain($expected);
    }
});

it('contains the exact PR readiness marker and next sprint recommendation', function (): void {
    expect(sprint36Doc())->toContain('GO CANDIDATE FOR PR REVIEW');
    expect(sprint36Doc())->toContain('Sprint 37 — Controlled Roadmap Execution Batch 1 & Governance Review');
});

it('records the Sprint 36 entry in sprint history', function (): void {
    foreach ([
        'Sprint 36 — Operational Governance, Maintenance Cadence & Expansion Readiness',
        'docs/sprint_36_operational_governance_maintenance_cadence_expansion_readiness.md',
        'Sprint 35 GO at `c4293ec`',
        'c4293ec',
        'Sprint 37 — Controlled Roadmap Execution Batch 1 & Governance Review',
        'GO CANDIDATE FOR PR REVIEW',
    ] as $expected) {
        expect(sprint36History())->toContain($expected);
    }
});
