<?php

it('documents Sprint 28 Phase 28.0 baseline planning', function (): void {
    $path = base_path('docs/sprint_28_phase_28_0_post_sprint_27_baseline_pilot_readiness_backlog_planning.md');

    expect(file_exists($path))->toBeTrue();

    $content = (string) file_get_contents($path);

    foreach ([
        'Sprint 28 Phase 28.0 — Post Sprint 27 Baseline, Pilot Readiness & Next Backlog Planning',
        'Baseline commit:** `c9e378a`',
        'Baseline GO tag:** `sprint-27-rme-control-workflow-go`',
        'RME control workflow remains closed as Sprint 27 GO',
        'No deployment',
        'No migration',
        'No production code change',
        'GO CANDIDATE FOR PR REVIEW',
    ] as $expected) {
        expect($content)->toContain($expected);
    }
});

it('defines Sprint 28 candidate backlog lanes', function (): void {
    $content = (string) file_get_contents(base_path('docs/sprint_28_phase_28_0_post_sprint_27_baseline_pilot_readiness_backlog_planning.md'));

    foreach ([
        'Pilot readiness and operator smoke checklist',
        'WhatsApp appointment reminder and receivable follow-up planning',
        'RME cashier/reporting polish',
        'Monitoring, backup, and restore rehearsal',
        'Branch rollout readiness',
    ] as $expected) {
        expect($content)->toContain($expected);
    }
});

it('keeps Sprint 28 Phase 28.0 planning-only and non-destructive', function (): void {
    $content = (string) file_get_contents(base_path('docs/sprint_28_phase_28_0_post_sprint_27_baseline_pilot_readiness_backlog_planning.md'));

    foreach ([
        'No controller/service/model/view changes',
        'No database mutation',
        'No queue/WhatsApp integration implementation',
        'No destructive data operation',
        'No deploy mixed with feature coding',
    ] as $expected) {
        expect($content)->toContain($expected);
    }
});

it('updates sprint history with Sprint 28 Phase 28.0', function (): void {
    $content = (string) file_get_contents(base_path('docs/sprint_history.md'));

    foreach ([
        'Sprint 28 Phase 28.0 — Post Sprint 27 Baseline, Pilot Readiness & Next Backlog Planning',
        'docs/sprint_28_phase_28_0_post_sprint_27_baseline_pilot_readiness_backlog_planning.md',
        'Planning baseline after Sprint 27 RME Control Workflow GO',
    ] as $expected) {
        expect($content)->toContain($expected);
    }
});
