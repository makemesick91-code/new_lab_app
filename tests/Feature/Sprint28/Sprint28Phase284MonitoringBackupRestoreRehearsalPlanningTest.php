<?php

it('documents Sprint 28 Phase 28.4 monitoring backup restore rehearsal planning', function (): void {
    $path = base_path('docs/sprint_28_phase_28_4_monitoring_backup_restore_rehearsal_planning.md');

    expect(file_exists($path))->toBeTrue();

    $content = (string) file_get_contents($path);

    foreach ([
        'Sprint 28 Phase 28.4',
        'Monitoring, Backup & Restore Rehearsal Planning',
        'Baseline:** Sprint 28.3 GO at `7f54016`',
        'GO CANDIDATE FOR PR REVIEW',
    ] as $expected) {
        expect($content)->toContain($expected);
    }
});

it('includes the monitoring planning checklist content', function (): void {
    $content = (string) file_get_contents(base_path('docs/sprint_28_phase_28_4_monitoring_backup_restore_rehearsal_planning.md'));

    foreach ([
        'Monitoring Planning Checklist',
        'App reachability check',
        'Login page response check',
        'Key menu/route quick check',
        'Laravel log review',
        'PHP-FPM/web server error log review',
        'Disk usage check',
        'Database connectivity symptom check',
        'Queue/scheduler status note',
        'Backup job/presence check',
        'Operator feedback issue count check',
        'Critical blocker escalation check',
    ] as $expected) {
        expect($content)->toContain($expected);
    }
});

it('includes the backup readiness checklist content', function (): void {
    $content = (string) file_get_contents(base_path('docs/sprint_28_phase_28_4_monitoring_backup_restore_rehearsal_planning.md'));

    foreach ([
        'Backup Readiness Checklist',
        'Database backup presence',
        'Runtime file backup presence',
        'Storage/uploads backup presence',
        'Backup timestamp verification',
        'Backup file size sanity check',
        'Backup location/inventory note',
        'Backup retention note',
        'Backup access permission note',
        'Backup encryption/privacy note',
        'Backup owner/responsibility',
        'Backup failure escalation',
    ] as $expected) {
        expect($content)->toContain($expected);
    }
});

it('includes the restore rehearsal planning content', function (): void {
    $content = (string) file_get_contents(base_path('docs/sprint_28_phase_28_4_monitoring_backup_restore_rehearsal_planning.md'));

    foreach ([
        'Restore Rehearsal Planning',
        'Restore target must be non-production.',
        'Restore source backup selected by timestamp.',
        'Restore objective defined before rehearsal.',
        'Restore owner assigned.',
        'Restore window planned.',
        'Restore pre-checklist prepared.',
        'Restore execution steps documented for future phase only.',
        'Restore verification checklist prepared.',
        'Rollback/cleanup checklist prepared for future phase.',
        'Evidence/log notes prepared.',
        'Explicit approval required before any real rehearsal.',
    ] as $expected) {
        expect($content)->toContain($expected);
    }
});

it('includes the restore verification checklist content', function (): void {
    $content = (string) file_get_contents(base_path('docs/sprint_28_phase_28_4_monitoring_backup_restore_rehearsal_planning.md'));

    foreach ([
        'Restore Verification Checklist',
        'App boots on non-production target.',
        'Login works on restored target.',
        'Key menus load.',
        'Patient search sample works.',
        'Visit/RME sample exists.',
        'Odontogram sample exists when applicable.',
        'Cashier/invoice sample exists when applicable.',
        'Receivable sample exists when applicable.',
        'Reports/export sample can be checked when applicable.',
        'No production/pilot data overwritten.',
    ] as $expected) {
        expect($content)->toContain($expected);
    }
});

it('includes the RME payment receivable safety notes', function (): void {
    $content = (string) file_get_contents(base_path('docs/sprint_28_phase_28_4_monitoring_backup_restore_rehearsal_planning.md'));

    foreach ([
        'RME / Payment / Receivable Safety Notes',
        'Control visits remain protected: same patient/RM but new visit.',
        'Old RME/odontogram/invoice must not be overwritten.',
        'Payment allocation remains FIFO previous receivable first.',
        'Parent receivable must not block control completion.',
        'Rp0 invoice must remain excluded from active receivables.',
        'Restore rehearsal must never be executed against active pilot data without explicit approval.',
    ] as $expected) {
        expect($content)->toContain($expected);
    }
});

it('includes the support admin daily evidence format', function (): void {
    $content = (string) file_get_contents(base_path('docs/sprint_28_phase_28_4_monitoring_backup_restore_rehearsal_planning.md'));

    foreach ([
        'Support / Admin Daily Evidence Format',
        'Checked by',
        'App reachable status',
        'Login/menu status',
        'Laravel log status',
        'Disk usage status',
        'Backup presence status',
        'Backup timestamp',
        'Backup location/reference',
        'Operator issue count',
        'Critical blocker count',
        'Follow-up owner',
        'Notes/evidence path',
    ] as $expected) {
        expect($content)->toContain($expected);
    }
});

it('includes incident escalation rules and future backlog', function (): void {
    $content = (string) file_get_contents(base_path('docs/sprint_28_phase_28_4_monitoring_backup_restore_rehearsal_planning.md'));

    foreach ([
        'Incident Escalation Rules',
        'App unreachable.',
        'Login fails for multiple users.',
        'Backup missing or stale.',
        'Restore rehearsal target accidentally points to production.',
        'Rp0 invoice appears in active receivables.',
        'Future Implementation Candidate Backlog',
        'Monitoring dashboard candidate.',
        'Daily health check command candidate.',
        'Restore rehearsal SOP execution phase candidate.',
        'Offsite backup candidate.',
    ] as $expected) {
        expect($content)->toContain($expected);
    }
});

it('includes risk mitigation and GO/NO-GO', function (): void {
    $content = (string) file_get_contents(base_path('docs/sprint_28_phase_28_4_monitoring_backup_restore_rehearsal_planning.md'));

    foreach ([
        'Risk and Mitigation',
        'Missing backup risk',
        'Stale backup risk',
        'Corrupt backup risk',
        'Restore tested on wrong target risk',
        'Production overwrite risk',
        'False GO due incomplete evidence risk',
        'GO / NO-GO',
    ] as $expected) {
        expect($content)->toContain($expected);
    }
});

it('declares no production, migration, deploy, backup or restore execution or destructive operation', function (): void {
    $content = (string) file_get_contents(base_path('docs/sprint_28_phase_28_4_monitoring_backup_restore_rehearsal_planning.md'));

    foreach ([
        'No deployment',
        'No migration',
        'No production code change',
        'No real backup executed',
        'No real restore executed',
        'No destructive data operation',
    ] as $expected) {
        expect($content)->toContain($expected);
    }
});

it('records the Sprint 28 Phase 28.4 entry in sprint history', function (): void {
    $content = (string) file_get_contents(base_path('docs/sprint_history.md'));

    foreach ([
        'Sprint 28 Phase 28.4',
        'docs/sprint_28_phase_28_4_monitoring_backup_restore_rehearsal_planning.md',
        'Sprint 28.3 GO at `7f54016`',
    ] as $expected) {
        expect($content)->toContain($expected);
    }
});
