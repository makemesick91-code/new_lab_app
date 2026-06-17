<?php

it('documents Sprint 29 Phase 29.4 monitoring backup restore rehearsal on non production target', function (): void {
    $path = base_path('docs/sprint_29_phase_29_4_monitoring_backup_restore_rehearsal_non_production_target.md');

    expect(file_exists($path))->toBeTrue();

    $content = (string) file_get_contents($path);

    foreach ([
        'Sprint 29 Phase 29.4',
        'Monitoring Backup Restore Rehearsal on Non-Production Target',
        '06c5d81',
        'Baseline:** Sprint 29.3 GO at `06c5d81`',
        'Sprint 29.3 GO at `06c5d81`',
        'GO CANDIDATE FOR PR REVIEW',
    ] as $expected) {
        expect($content)->toContain($expected);
    }
});

it('lists the required SOP sections', function (): void {
    $content = (string) file_get_contents(base_path('docs/sprint_29_phase_29_4_monitoring_backup_restore_rehearsal_non_production_target.md'));

    foreach ([
        'Non-production target definition',
        'Monitoring readiness SOP',
        'Backup inventory SOP',
        'Restore rehearsal SOP on non-production target',
        'Data privacy and safety rules',
        'Rehearsal evidence template',
        'P0/P1 escalation matrix',
        'Pilot daily monitoring checklist',
        'Future implementation/rehearsal sequencing',
        'Out-of-scope implementation list',
        'Risk and mitigation',
        'GO/NO-GO decision',
    ] as $expected) {
        expect($content)->toContain($expected);
    }
});

it('includes the safety confirmation and no-automation statements', function (): void {
    $content = (string) file_get_contents(base_path('docs/sprint_29_phase_29_4_monitoring_backup_restore_rehearsal_non_production_target.md'));

    foreach ([
        'Safety confirmation',
        'No production/VPS access',
        'No real backup execution',
        'No real restore execution',
        'No monitoring automation',
        'No backup automation',
        'No restore automation',
    ] as $expected) {
        expect($content)->toContain($expected);
    }
});

it('includes the required privacy statements', function (): void {
    $content = (string) file_get_contents(base_path('docs/sprint_29_phase_29_4_monitoring_backup_restore_rehearsal_non_production_target.md'));

    foreach ([
        'Do not expose No. KTP',
        'Do not paste .env secrets',
        'Do not paste database credentials',
        'Do not paste patient-identifiable data into public PR',
    ] as $expected) {
        expect($content)->toContain($expected);
    }
});

it('includes the non-production safeguards', function (): void {
    $content = (string) file_get_contents(base_path('docs/sprint_29_phase_29_4_monitoring_backup_restore_rehearsal_non_production_target.md'));

    foreach ([
        'Non-production target must not be the live pilot/production VPS',
        'Production database must not be overwritten',
        'Production storage/uploads must not be overwritten',
        'Restore rehearsal must be clearly labeled as rehearsal',
    ] as $expected) {
        expect($content)->toContain($expected);
    }
});

it('records the Sprint 29 Phase 29.4 entry in sprint history', function (): void {
    $content = (string) file_get_contents(base_path('docs/sprint_history.md'));

    foreach ([
        'Sprint 29 Phase 29.4',
        'docs/sprint_29_phase_29_4_monitoring_backup_restore_rehearsal_non_production_target.md',
        'Sprint 29.3 GO at `06c5d81`',
    ] as $expected) {
        expect($content)->toContain($expected);
    }
});
