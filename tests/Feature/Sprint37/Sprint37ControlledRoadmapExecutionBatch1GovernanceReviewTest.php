<?php

use function PHPUnit\Framework\assertFileExists;

function sprint37Doc(): string
{
    return (string) file_get_contents(base_path('docs/sprint_37_controlled_roadmap_execution_batch_1_governance_review.md'));
}

function sprint37History(): string
{
    return (string) file_get_contents(base_path('docs/sprint_history.md'));
}

it('documents the Sprint 37 title, status block and baseline commit', function (): void {
    $path = base_path('docs/sprint_37_controlled_roadmap_execution_batch_1_governance_review.md');

    assertFileExists($path);

    foreach ([
        '# Sprint 37 — Controlled Roadmap Execution Batch 1 & Governance Review',
        'Status: Draft / Local Validation Pending',
        'Baseline: Sprint 36 GO at 7a1f959',
        '7a1f959',
    ] as $expected) {
        expect(sprint37Doc())->toContain($expected);
    }
});

it('references the Sprint 35 and Sprint 36 GO tags, commits and Sprint 36 feature commit', function (): void {
    foreach ([
        'sprint-35-production-operations-baseline-continuous-improvement-roadmap-lock-go',
        'c4293ec',
        'sprint-36-operational-governance-maintenance-cadence-expansion-readiness-go',
        '7a1f959',
        '01bac93',
    ] as $expected) {
        expect(sprint37Doc())->toContain($expected);
    }
});

it('defines the controlled roadmap execution scope', function (): void {
    foreach ([
        '## Controlled roadmap execution scope',
        'controlled roadmap planning only',
        'Batch 1 candidate selection only',
        'implementation readiness only',
        'governance review only',
        'no actual feature implementation in this sprint',
        'manual owner/admin confirmed checklist only',
    ] as $expected) {
        expect(sprint37Doc())->toContain($expected);
    }
});

it('contains the Batch 1 roadmap candidate review', function (): void {
    foreach ([
        '## Batch 1 roadmap candidate review',
        '### RME workflow improvement',
        '### Cashier/payment/receivable improvement',
        '### Reporting/export improvement',
        '### WhatsApp manual reminder operationalization',
        '### Monitoring/backup/recovery governance hardening',
        '### Branch expansion readiness',
        '### UX/UI polish and operator feedback',
        '### Training/documentation gap closure',
        '### Technical debt cleanup',
        'include / watch / defer / reject',
    ] as $expected) {
        expect(sprint37Doc())->toContain($expected);
    }
});

it('contains the Batch 1 selection criteria', function (): void {
    foreach ([
        '## Batch 1 selection criteria',
        'must be approved by owner/admin',
        'must have clear acceptance criteria',
        'must have targeted test scope',
        'must not bypass Sprint 36 governance',
        'must be reversible or have escalation/rollback reference',
    ] as $expected) {
        expect(sprint37Doc())->toContain($expected);
    }
});

it('contains the recommended Batch 1 scope and names RME Workflow Improvement Batch 1', function (): void {
    foreach ([
        '## Recommended Batch 1 scope',
        'RME Workflow Improvement Batch 1',
        'Cashier/payment/receivable improvement discovery',
        'Reporting/export improvement discovery',
        'Training/documentation gap closure',
    ] as $expected) {
        expect(sprint37Doc())->toContain($expected);
    }
});

it('states that Sprint 37 does not implement RME changes', function (): void {
    expect(sprint37Doc())->toContain('Sprint 37 does **not** implement RME changes');
});

it('contains the RME Workflow Improvement Batch 1 implementation-readiness outline', function (): void {
    foreach ([
        '## RME Workflow Improvement Batch 1 implementation-readiness outline',
        'current RME workflow discovery',
        'user/operator pain point placeholder',
        'module map placeholder',
        'route/view/controller/service touchpoint placeholder',
        'cashier/receivable impact placeholder',
        'acceptance criteria placeholder',
        'rollback/escalation reference placeholder',
        'owner approval placeholder',
        'No code implementation in Sprint 37.',
    ] as $expected) {
        expect(sprint37Doc())->toContain($expected);
    }
});

it('contains the governance review checklist', function (): void {
    foreach ([
        '## Governance review checklist',
        'Sprint 36 governance accepted',
        'Batch 1 candidates reviewed',
        'Batch 1 selected',
        'owner/admin approval placeholder',
        'rollback/escalation reference defined',
        'release/GO tag policy reviewed',
        'no production execution performed',
        'GO / WATCH / DEFER / NO-GO decision recorded',
    ] as $expected) {
        expect(sprint37Doc())->toContain($expected);
    }
});

it('contains the risk and decision matrix with R0/R1/R2/R3 levels', function (): void {
    foreach ([
        '## Risk and decision matrix',
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
        '**Implementation permission rule:**',
    ] as $expected) {
        expect(sprint37Doc())->toContain($expected);
    }
});

it('contains the controlled implementation gate for future Sprint 38', function (): void {
    foreach ([
        '## Controlled implementation gate for future Sprint 38',
        'base branch confirmed',
        'GO tag confirmed',
        'feature branch created',
        'candidate item approved',
        'migration need assessed',
        'rollback/escalation reference confirmed',
        'implementation allowed only after explicit user approval',
    ] as $expected) {
        expect(sprint37Doc())->toContain($expected);
    }
});

it('contains the test strategy for future implementation', function (): void {
    foreach ([
        '## Test strategy for future implementation',
        'RME feature tests',
        'permission/access tests',
        'workflow status tests',
        'print/export tests',
        'cashier/receivable impact tests',
        'targeted Pest filters',
        'no full suite unless needed',
    ] as $expected) {
        expect(sprint37Doc())->toContain($expected);
    }
});

it('provides the roadmap batch, acceptance criteria and future implementation templates', function (): void {
    foreach ([
        '## Roadmap batch evidence template',
        'selected decision',
        'follow-up owner',
        '## Batch 1 acceptance criteria template',
        'acceptance criterion',
        'approval status',
        '## Future implementation checklist template',
        'rollback/escalation note',
    ] as $expected) {
        expect(sprint37Doc())->toContain($expected);
    }
});

it('defines the GO / WATCH / DEFER / NO-GO governance decision criteria', function (): void {
    foreach ([
        '## Governance decision criteria',
        '**GO**',
        '**WATCH**',
        '**DEFER**',
        '**NO-GO**',
    ] as $expected) {
        expect(sprint37Doc())->toContain($expected);
    }
});

it('includes the explicit out-of-scope safety restrictions', function (): void {
    foreach ([
        'No production code change.',
        'No bugfix implementation.',
        'No enhancement implementation.',
        'No roadmap item implementation.',
        'No RME implementation.',
        'No cashier/payment/receivable implementation.',
        'No reporting/export implementation.',
        'No WhatsApp automation/send.',
        'No maintenance execution.',
        'No branch expansion execution.',
        'No migration.',
        'No deployment.',
        'No production/VPS access.',
        'No real backup execution.',
        'No real restore execution.',
        'No rollback execution.',
        'No destructive operation.',
        'No monitoring automation.',
        'No cron/scheduler/job/queue/notification change.',
        'No runtime behavior change.',
        'No route/controller/service/model/view/config/seeder change.',
        'No external service call.',
        'No `.env` change.',
        'No dependency/package install.',
    ] as $expected) {
        expect(sprint37Doc())->toContain($expected);
    }
});

it('contains the exact PR readiness marker and next sprint recommendation', function (): void {
    expect(sprint37Doc())->toContain('GO CANDIDATE FOR PR REVIEW');
    expect(sprint37Doc())->toContain('Sprint 38 — RME Workflow Improvement Batch 1');
});

it('records the Sprint 37 entry in sprint history', function (): void {
    foreach ([
        'Sprint 37 — Controlled Roadmap Execution Batch 1 & Governance Review',
        'docs/sprint_37_controlled_roadmap_execution_batch_1_governance_review.md',
        'Sprint 36 GO at `7a1f959`',
        '7a1f959',
        'Sprint 38 — RME Workflow Improvement Batch 1',
        'GO CANDIDATE FOR PR REVIEW',
    ] as $expected) {
        expect(sprint37History())->toContain($expected);
    }
});
