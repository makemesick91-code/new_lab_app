<?php

// Sprint 45 — Pilot Health Check Supervised Readiness Runbook & Go/No-Go Control
// Checklist assertions over the Sprint 45 governance doc + sprint history.
// Governance-only sprint: documentation/checklist regression, no functional behavior under test.

function sprint45DocPath(): string
{
    return base_path('docs/sprint_45_pilot_health_check_supervised_readiness_runbook_go_no_go_control.md');
}

function sprint45Doc(): string
{
    return file_get_contents(sprint45DocPath());
}

it('sprint 45 document exists', function () {
    expect(file_exists(sprint45DocPath()))->toBeTrue();
});

it('sprint 45 document contains the title, branch, tags and Sprint 44 baseline', function () {
    $doc = sprint45Doc();
    expect($doc)->toContain('# Sprint 45 — Pilot Health Check Supervised Readiness Runbook & Go/No-Go Control')
        ->and($doc)->toContain('feature/sprint-45-pilot-health-check-supervised-readiness-runbook-go-no-go-control')
        ->and($doc)->toContain('sprint-45-pilot-health-check-supervised-readiness-runbook-go-no-go-control')
        ->and($doc)->toContain('sprint-45-pilot-health-check-supervised-readiness-runbook-go-no-go-control-go')
        ->and($doc)->toContain('Sprint 44 GO at f1debae');
});

it('sprint 45 document states documentation / checklist-test only', function () {
    expect(sprint45Doc())->toContain('documentation/checklist-test only');
});

it('sprint 45 document defines supervised readiness and states it does not authorize execution', function () {
    $doc = sprint45Doc();
    expect($doc)->toContain('Supervised readiness definition')
        ->and($doc)->toContain('Supervised readiness means')
        ->and($doc)->toContain('does not authorize execution')
        ->and($doc)->toContain('separate explicitly approved supervised workflow')
        ->and($doc)->toContain('supervised readiness runbook only')
        ->and($doc)->toContain('does not execute a supervised pilot health check');
});

it('sprint 45 document includes readiness prerequisites', function () {
    $doc = sprint45Doc();
    expect($doc)->toContain('Readiness prerequisites')
        ->and($doc)->toContain('GO tag from previous sprint confirmed')
        ->and($doc)->toContain('Dry-run evidence package from Sprint 44 reviewed')
        ->and($doc)->toContain('Time window proposed but **not** executed')
        ->and($doc)->toContain('Exit criteria agreed');
});

it('sprint 45 document includes supervised pilot health-check runbook phases', function () {
    $doc = sprint45Doc();
    expect($doc)->toContain('Supervised pilot health-check runbook phases')
        ->and($doc)->toContain('Pre-check readiness review')
        ->and($doc)->toContain('Scope and environment confirmation')
        ->and($doc)->toContain('Closure and next workflow recommendation')
        ->and($doc)->toContain('These phases are prepared for a future supervised workflow and are not executed in Sprint 45');
});

it('sprint 45 document includes the Go/No-Go control framework', function () {
    $doc = sprint45Doc();
    expect($doc)->toContain('Go/No-Go control framework')
        ->and($doc)->toContain('governance only, not deployment authorization');
});

it('sprint 45 document includes Go, Conditional Go, No-Go and Abort criteria', function () {
    $doc = sprint45Doc();
    expect($doc)->toContain('## 11. Go criteria')
        ->and($doc)->toContain('## 12. Conditional Go criteria')
        ->and($doc)->toContain('## 13. No-Go criteria')
        ->and($doc)->toContain('## 14. Abort criteria')
        ->and($doc)->toContain('Environment access is separately authorized')
        ->and($doc)->toContain('Execution still requires a separate supervised workflow')
        ->and($doc)->toContain('Production / VPS access not approved')
        ->and($doc)->toContain('Any command would mutate production data');
});

it('sprint 45 document includes the evidence checklist', function () {
    $doc = sprint45Doc();
    expect($doc)->toContain('Evidence checklist')
        ->and($doc)->toContain('Evidence Package ID:')
        ->and($doc)->toContain('Go/No-Go decision:')
        ->and($doc)->toContain('KTP exposure check:')
        ->and($doc)->toContain('Final sign-off:')
        ->and($doc)->toContain('does not collect real production screenshots');
});

it('sprint 45 document includes the operational sign-off workflow', function () {
    $doc = sprint45Doc();
    expect($doc)->toContain('Operational sign-off workflow')
        ->and($doc)->toContain('**Go**, **Conditional Go**, or **No-Go**')
        ->and($doc)->toContain('does **not** authorize deployment');
});

it('sprint 45 document includes approval gates', function () {
    $doc = sprint45Doc();
    expect($doc)->toContain('Approval gates')
        ->and($doc)->toContain('Real pilot health-check execution requires a separate supervised workflow')
        ->and($doc)->toContain('Any financial logic change requires a separate approved implementation sprint');
});

it('sprint 45 document includes incident escalation and rollback decision gates', function () {
    $doc = sprint45Doc();
    expect($doc)->toContain('Incident escalation and rollback decision gates')
        ->and($doc)->toContain('Rollback decision tree review only')
        ->and($doc)->toContain('Execute only in separately approved workflow');
});

it('sprint 45 document forbids all out-of-scope operational actions', function () {
    $doc = sprint45Doc();
    expect($doc)->toContain('No real pilot health-check execution')
        ->and($doc)->toContain('No production / VPS / server access')
        ->and($doc)->toContain('No deployment')
        ->and($doc)->toContain('No production command execution')
        ->and($doc)->toContain('No production backup execution')
        ->and($doc)->toContain('No production restore execution')
        ->and($doc)->toContain('No rollback execution')
        ->and($doc)->toContain('No external monitoring integration')
        ->and($doc)->toContain('No scheduler / queue / cron automation')
        ->and($doc)->toContain('No `.env` change')
        ->and($doc)->toContain('No dependency / package install')
        ->and($doc)->toContain('No migration / schema change')
        ->and($doc)->toContain('No runtime behavior change');
});

it('sprint 45 document preserves privacy constraints', function () {
    $doc = sprint45Doc();
    expect($doc)->toContain('KTP / `ktp_number` must remain hidden')
        ->and($doc)->toContain('runbook content')
        ->and($doc)->toContain('No patient identifiers in evidence/runbook material')
        ->and($doc)->toContain('avoid unnecessary exposure of patient contact data')
        ->and($doc)->toContain('Manual WhatsApp only')
        ->and($doc)->toContain('No WhatsApp automation / send / API');
});

it('sprint 45 document preserves financial constraints', function () {
    $doc = sprint45Doc();
    expect($doc)->toContain('Zero-remaining receivables remain excluded from active receivables')
        ->and($doc)->toContain('Overpayment guard remains preserved')
        ->and($doc)->toContain('Financial rules are not rewritten');
});

it('sprint 45 document includes validation commands', function () {
    $doc = sprint45Doc();
    expect($doc)->toContain('php artisan test --filter=Sprint45PilotHealthCheckSupervisedReadinessRunbookGoNoGoControl')
        ->and($doc)->toContain('vendor/bin/pint --test')
        ->and($doc)->toContain('git diff --check');
});

it('sprint history references Sprint 45, baseline and validation', function () {
    $history = file_get_contents(base_path('docs/sprint_history.md'));
    expect($history)->toContain('## Sprint 45 — Pilot Health Check Supervised Readiness Runbook & Go/No-Go Control')
        ->and($history)->toContain('f1debae')
        ->and($history)->toContain('Sprint45PilotHealthCheckSupervisedReadinessRunbookGoNoGoControl');
});
