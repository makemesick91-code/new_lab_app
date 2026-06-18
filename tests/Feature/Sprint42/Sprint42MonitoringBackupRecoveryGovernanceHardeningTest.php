<?php

// Sprint 42 — Monitoring, Backup & Recovery Governance Hardening
// Checklist assertions over the Sprint 42 governance doc + sprint history.
// Governance-only sprint: documentation/checklist regression, no functional behavior under test.

function sprint42Doc(): string
{
    return file_get_contents(base_path('docs/sprint_42_monitoring_backup_recovery_governance_hardening.md'));
}

it('sprint 42 document exists', function () {
    expect(file_exists(base_path('docs/sprint_42_monitoring_backup_recovery_governance_hardening.md')))->toBeTrue();
});

it('sprint 42 document contains the title', function () {
    expect(sprint42Doc())
        ->toContain('# Sprint 42 — Monitoring, Backup & Recovery Governance Hardening');
});

it('sprint 42 document references the baseline and required commits', function () {
    $doc = sprint42Doc();
    expect($doc)->toContain('Baseline: Sprint 41 GO at 19e5f74')
        ->and($doc)->toContain('sprint-40-reporting-export-owner-dashboard-improvement-go at 8647b0f')
        ->and($doc)->toContain('sprint-41-whatsapp-manual-reminder-operationalization-follow-up-workflow-go at 19e5f74')
        ->and($doc)->toContain('Sprint 41 feature commit: 02347d8');
});

it('sprint 42 document covers each required governance area', function () {
    $doc = sprint42Doc();
    expect($doc)->toContain('Monitoring governance')
        ->and($doc)->toContain('Backup governance')
        ->and($doc)->toContain('Recovery readiness governance')
        ->and($doc)->toContain('Restore rehearsal approval gates')
        ->and($doc)->toContain('Incident escalation and rollback decision gates')
        ->and($doc)->toContain('Evidence checklist')
        ->and($doc)->toContain('Review cadence');
});

it('sprint 42 document states the required safety boundaries', function () {
    $doc = sprint42Doc();
    expect($doc)->toContain('no production / VPS access')
        ->and($doc)->toContain('no deployment')
        ->and($doc)->toContain('no production backup execution')
        ->and($doc)->toContain('no production restore execution')
        ->and($doc)->toContain('no rollback execution')
        ->and($doc)->toContain('no external monitoring service integration')
        ->and($doc)->toContain('no scheduler / queue / cron automation');
});

it('sprint 42 document contains the PR marker and Sprint 43 recommendation', function () {
    $doc = sprint42Doc();
    expect($doc)->toContain('GO CANDIDATE FOR PR REVIEW')
        ->and($doc)->toContain('Sprint 43 — Operational Monitoring Evidence Review & Pilot Health Check');
});

it('sprint history references Sprint 42, baseline and Sprint 43', function () {
    $history = file_get_contents(base_path('docs/sprint_history.md'));
    expect($history)->toContain('## Sprint 42 — Monitoring, Backup & Recovery Governance Hardening')
        ->and($history)->toContain('19e5f74')
        ->and($history)->toContain('Sprint 43 — Operational Monitoring Evidence Review & Pilot Health Check');
});
