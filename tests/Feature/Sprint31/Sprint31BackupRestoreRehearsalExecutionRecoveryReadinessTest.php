<?php

it('documents the Sprint 31 backup restore rehearsal execution and recovery readiness', function (): void {
    $path = base_path('docs/sprint_31_backup_restore_rehearsal_execution_recovery_readiness.md');

    expect(file_exists($path))->toBeTrue();

    $content = (string) file_get_contents($path);

    foreach ([
        '# Sprint 31 — Backup Restore Rehearsal Execution & Recovery Readiness',
        'Status: Draft / Local Validation Pending',
        'Baseline: Sprint 30 GO at 53c3442',
        '53c3442',
    ] as $expected) {
        expect($content)->toContain($expected);
    }
});

it('references the Sprint 29.4, 29.5 and 30 GO tags and commits as baseline lineage', function (): void {
    $content = (string) file_get_contents(base_path('docs/sprint_31_backup_restore_rehearsal_execution_recovery_readiness.md'));

    foreach ([
        'sprint-29-phase-29-4-monitoring-backup-restore-rehearsal-non-production-target-go',
        'b6334fc',
        'sprint-29-phase-29-5-pilot-safety-review-final-stabilization-checklist-go',
        '721bb55',
        'sprint-30-pilot-execution-bugfix-operational-smoke-go',
        '53c3442',
    ] as $expected) {
        expect($content)->toContain($expected);
    }
});

it('defines the required Sprint 31 document sections', function (): void {
    $content = (string) file_get_contents(base_path('docs/sprint_31_backup_restore_rehearsal_execution_recovery_readiness.md'));

    foreach ([
        '## Purpose',
        '## Baseline references',
        '## Non-production rehearsal scope',
        '## Backup inventory checklist',
        '## Restore rehearsal checklist',
        '## Recovery readiness gates',
        '## Post-restore smoke checklist',
        '## Evidence template',
        '## Incident and escalation matrix',
        '## Go / Watch / No-Go criteria',
        '## Explicit out of scope',
        '## Validation commands',
        '## PR readiness marker',
        '## Next sprint recommendation',
    ] as $expected) {
        expect($content)->toContain($expected);
    }
});

it('describes the non-production rehearsal scope safeguards', function (): void {
    $content = (string) file_get_contents(base_path('docs/sprint_31_backup_restore_rehearsal_execution_recovery_readiness.md'));

    foreach ([
        'Non-production target only',
        'Isolated database target',
        'Isolated runtime file target',
        'No production overwrite',
        'No production credentials in docs',
        'No real patient data exposure',
        'Manual operator-confirmed checklist only',
    ] as $expected) {
        expect($content)->toContain($expected);
    }
});

it('covers the backup inventory and restore rehearsal checklists', function (): void {
    $content = (string) file_get_contents(base_path('docs/sprint_31_backup_restore_rehearsal_execution_recovery_readiness.md'));

    foreach ([
        'Database dump source identification',
        'Runtime upload/storage directory identification',
        'Checksum / hash evidence',
        'Backup file size evidence',
        'Backup storage location evidence',
        'Restore command approval gate',
        'Database restore step placeholder',
        'Runtime file restore step placeholder',
        'Laravel cache clear placeholder',
        'App health check placeholder',
        'Route availability check placeholder',
    ] as $expected) {
        expect($content)->toContain($expected);
    }
});

it('defines the recovery readiness gates and post-restore smoke checklist', function (): void {
    $content = (string) file_get_contents(base_path('docs/sprint_31_backup_restore_rehearsal_execution_recovery_readiness.md'));

    foreach ([
        'Backup inventory complete',
        'Checksum recorded',
        'Non-production target verified',
        'Restore target empty/approved',
        'Rollback path documented',
        'Operator assigned',
        'Reviewer assigned',
        'Escalation contact assigned',
        'Privacy review complete',
        'GO / WATCH / NO-GO decision recorded',
        'Application boots',
        'Login page reachable',
        'RME visit page smoke',
        'Cashier invoice/payment smoke',
        'Receivable/piutang smoke',
        'Backup/restore evidence location recorded',
    ] as $expected) {
        expect($content)->toContain($expected);
    }
});

it('provides the evidence template fields and incident escalation matrix', function (): void {
    $content = (string) file_get_contents(base_path('docs/sprint_31_backup_restore_rehearsal_execution_recovery_readiness.md'));

    foreach ([
        'Backup identifier',
        'Restore target',
        'Expected result',
        'Actual result',
        'Evidence path',
        'Issue severity',
        'Follow-up owner',
        '**P0**',
        '**P1**',
        '**P2**',
    ] as $expected) {
        expect($content)->toContain($expected);
    }
});

it('defines the Go / Watch / No-Go criteria', function (): void {
    $content = (string) file_get_contents(base_path('docs/sprint_31_backup_restore_rehearsal_execution_recovery_readiness.md'));

    foreach ([
        '**GO**',
        '**WATCH**',
        '**NO-GO**',
        'controlled non-production rehearsal execution',
    ] as $expected) {
        expect($content)->toContain($expected);
    }
});

it('includes the explicit out-of-scope safety restrictions', function (): void {
    $content = (string) file_get_contents(base_path('docs/sprint_31_backup_restore_rehearsal_execution_recovery_readiness.md'));

    foreach ([
        'No production code change.',
        'No migration.',
        'No deployment.',
        'No production/VPS access.',
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
    ] as $expected) {
        expect($content)->toContain($expected);
    }
});

it('contains the exact PR readiness marker and next sprint recommendation', function (): void {
    $content = (string) file_get_contents(base_path('docs/sprint_31_backup_restore_rehearsal_execution_recovery_readiness.md'));

    expect($content)->toContain('GO CANDIDATE FOR PR REVIEW');
    expect($content)->toContain('Sprint 32 — Go-Live Readiness, Training, Handover & SLA');
});

it('records the Sprint 31 entry in sprint history', function (): void {
    $content = (string) file_get_contents(base_path('docs/sprint_history.md'));

    foreach ([
        'Sprint 31 — Backup Restore Rehearsal Execution & Recovery Readiness',
        'docs/sprint_31_backup_restore_rehearsal_execution_recovery_readiness.md',
        'Sprint 30 GO at `53c3442`',
        '53c3442',
        'Sprint 32 — Go-Live Readiness, Training, Handover & SLA',
        'GO CANDIDATE FOR PR REVIEW',
    ] as $expected) {
        expect($content)->toContain($expected);
    }
});
