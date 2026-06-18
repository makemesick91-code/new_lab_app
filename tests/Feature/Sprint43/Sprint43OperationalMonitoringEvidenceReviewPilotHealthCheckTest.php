<?php

// Sprint 43 — Operational Monitoring Evidence Review & Pilot Health Check
// Checklist assertions over the Sprint 43 governance doc + sprint history.
// Governance-only sprint: documentation/checklist regression, no functional behavior under test.

function sprint43Doc(): string
{
    return file_get_contents(base_path('docs/sprint_43_operational_monitoring_evidence_review_pilot_health_check.md'));
}

it('sprint 43 document exists', function () {
    expect(file_exists(base_path('docs/sprint_43_operational_monitoring_evidence_review_pilot_health_check.md')))->toBeTrue();
});

it('sprint 43 document contains the title, branch, tags and Sprint 42 baseline', function () {
    $doc = sprint43Doc();
    expect($doc)->toContain('# Sprint 43 — Operational Monitoring Evidence Review & Pilot Health Check')
        ->and($doc)->toContain('feature/sprint-43-operational-monitoring-evidence-review-pilot-health-check')
        ->and($doc)->toContain('sprint-43-operational-monitoring-evidence-review-pilot-health-check')
        ->and($doc)->toContain('sprint-43-operational-monitoring-evidence-review-pilot-health-check-go')
        ->and($doc)->toContain('Sprint 42 GO at 5876070');
});

it('sprint 43 document states documentation / checklist-test only', function () {
    expect(sprint43Doc())->toContain('documentation/checklist-test only');
});

it('sprint 43 document includes the monitoring evidence review and pilot health-check checklists', function () {
    $doc = sprint43Doc();
    expect($doc)->toContain('Operational monitoring evidence review checklist')
        ->and($doc)->toContain('Pilot health-check readiness checklist')
        ->and($doc)->toContain('App availability observation evidence')
        ->and($doc)->toContain('Laravel log review evidence')
        ->and($doc)->toContain('Confirm read-only checks only');
});

it('sprint 43 document includes evidence package structure and approval gates', function () {
    $doc = sprint43Doc();
    expect($doc)->toContain('Evidence package structure')
        ->and($doc)->toContain('docs/evidence/sprint-43/')
        ->and($doc)->toContain('Approval gates')
        ->and($doc)->toContain('Production / VPS access requires a separate supervised workflow');
});

it('sprint 43 document forbids all out-of-scope operational actions', function () {
    $doc = sprint43Doc();
    expect($doc)->toContain('No production / VPS access')
        ->and($doc)->toContain('No deployment')
        ->and($doc)->toContain('No production backup execution')
        ->and($doc)->toContain('No production restore execution')
        ->and($doc)->toContain('No rollback execution')
        ->and($doc)->toContain('No external monitoring service integration')
        ->and($doc)->toContain('No scheduler / queue / cron automation')
        ->and($doc)->toContain('No `.env` change')
        ->and($doc)->toContain('No dependency / package install')
        ->and($doc)->toContain('No migration / schema change');
});

it('sprint 43 document preserves privacy constraints', function () {
    $doc = sprint43Doc();
    expect($doc)->toContain('KTP / `ktp_number` must remain hidden')
        ->and($doc)->toContain('Manual WhatsApp only')
        ->and($doc)->toContain('No WhatsApp automation / send / API');
});

it('sprint 43 document preserves financial constraints', function () {
    $doc = sprint43Doc();
    expect($doc)->toContain('Zero-remaining receivables remain excluded from active receivables')
        ->and($doc)->toContain('Overpayment guard remains preserved')
        ->and($doc)->toContain('Financial rules are not rewritten');
});

it('sprint 43 document includes validation commands', function () {
    $doc = sprint43Doc();
    expect($doc)->toContain('php artisan test --filter=Sprint43OperationalMonitoringEvidenceReviewPilotHealthCheck')
        ->and($doc)->toContain('vendor/bin/pint --test')
        ->and($doc)->toContain('git diff --check');
});

it('sprint history references Sprint 43, baseline and validation', function () {
    $history = file_get_contents(base_path('docs/sprint_history.md'));
    expect($history)->toContain('## Sprint 43 — Operational Monitoring Evidence Review & Pilot Health Check')
        ->and($history)->toContain('5876070')
        ->and($history)->toContain('Sprint43OperationalMonitoringEvidenceReviewPilotHealthCheck');
});
