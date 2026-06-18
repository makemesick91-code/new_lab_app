<?php

// Sprint 46 — Pilot Health Check Supervised Execution Plan & Evidence Review Pack.
// Documentation + checklist regression test only — validates the Sprint 46 governance
// documentation, supervised execution plan, evidence review pack template, and sprint history entry.

function sprint46DocPath(): string
{
    return base_path('docs/sprint_46_pilot_health_check_supervised_execution_plan_evidence_review_pack.md');
}

function sprint46Doc(): string
{
    return file_get_contents(sprint46DocPath());
}

it('sprint 46 document exists', function () {
    expect(file_exists(sprint46DocPath()))->toBeTrue();
});

it('sprint 46 document contains the title, branch, tags and Sprint 45 baseline', function () {
    $doc = sprint46Doc();
    expect($doc)->toContain('# Sprint 46 — Pilot Health Check Supervised Execution Plan & Evidence Review Pack')
        ->and($doc)->toContain('feature/sprint-46-pilot-health-check-supervised-execution-plan-evidence-review-pack')
        ->and($doc)->toContain('sprint-46-pilot-health-check-supervised-execution-plan-evidence-review-pack')
        ->and($doc)->toContain('sprint-46-pilot-health-check-supervised-execution-plan-evidence-review-pack-go')
        ->and($doc)->toContain('Sprint 45 GO / merge commit 9aa3c11');
});

it('sprint 46 document states it is documentation and checklist-test only', function () {
    $doc = sprint46Doc();
    expect($doc)->toContain('This sprint is documentation/checklist-test only.')
        ->and($doc)->toContain('This sprint prepares a supervised execution plan only.')
        ->and($doc)->toContain('This sprint prepares an evidence review pack template only.')
        ->and($doc)->toContain('This sprint does not execute a supervised pilot health check.');
});

it('sprint 46 document defines the supervised execution plan and does not authorize execution', function () {
    $doc = sprint46Doc();
    expect($doc)->toContain('## 7. Supervised execution plan definition')
        ->and($doc)->toContain('Sprint 46 does not authorize execution.')
        ->and($doc)->toContain('Real supervised execution requires a separate explicitly')
        ->and($doc)->toContain('Any actual supervised execution must be a separate explicitly approved workflow after Sprint 46.');
});

it('sprint 46 document includes execution prerequisites', function () {
    $doc = sprint46Doc();
    expect($doc)->toContain('## 8. Execution prerequisites')
        ->and($doc)->toContain('Base branch and latest GO tag confirmed.')
        ->and($doc)->toContain('Sprint 45 supervised readiness runbook reviewed.')
        ->and($doc)->toContain('Time window proposed but not executed.');
});

it('sprint 46 document includes observation-only supervised execution phases', function () {
    $doc = sprint46Doc();
    expect($doc)->toContain('## 9. Observation-only supervised execution phases')
        ->and($doc)->toContain('Read-only observation checklist.')
        ->and($doc)->toContain('Manual WhatsApp follow-up observation checklist.')
        ->and($doc)->toContain('Go/No-Go carry-forward decision.')
        ->and($doc)->toContain('These phases are prepared for a future separately approved supervised workflow');
});

it('sprint 46 document includes evidence review pack overview and template fields', function () {
    $doc = sprint46Doc();
    expect($doc)->toContain('## 10. Evidence review pack overview')
        ->and($doc)->toContain('## 11. Evidence review pack template')
        ->and($doc)->toContain('Evidence Review Pack ID:')
        ->and($doc)->toContain('KTP exposure check:')
        ->and($doc)->toContain('Go/No-Go carry-forward decision:')
        ->and($doc)->toContain('Final sign-off timestamp:')
        ->and($doc)->toContain('Evidence fields are placeholders only in Sprint 46.');
});

it('sprint 46 document includes evidence acceptance rules', function () {
    $doc = sprint46Doc();
    expect($doc)->toContain('## 12. Evidence acceptance rules')
        ->and($doc)->toContain('Evidence must match approved scope.')
        ->and($doc)->toContain('Evidence must not include KTP.')
        ->and($doc)->toContain('Evidence must support Go/No-Go carry-forward review.');
});

it('sprint 46 document includes evidence rejection rules', function () {
    $doc = sprint46Doc();
    expect($doc)->toContain('## 13. Evidence rejection rules')
        ->and($doc)->toContain('Reject evidence containing KTP or unnecessary patient identifiers.')
        ->and($doc)->toContain('Reject evidence that includes WhatsApp automation/send/API activity.');
});

it('sprint 46 document includes Go/No-Go carry-forward controls and abort criteria', function () {
    $doc = sprint46Doc();
    expect($doc)->toContain('## 14. Go/No-Go carry-forward controls')
        ->and($doc)->toContain('Go/No-Go result from Sprint 45 must be reviewed before any future execution.')
        ->and($doc)->toContain('Abort criteria override any Go or Conditional Go.')
        ->and($doc)->toContain('## 15. Abort criteria')
        ->and($doc)->toContain('Any command would mutate production data.');
});

it('sprint 46 document includes operational sign-off workflow and approval gates', function () {
    $doc = sprint46Doc();
    expect($doc)->toContain('## 16. Operational sign-off workflow')
        ->and($doc)->toContain('Owner/admin decision: approve plan, approve with conditions, or reject plan.')
        ->and($doc)->toContain('## 17. Approval gates')
        ->and($doc)->toContain('Real pilot health-check execution requires a separate supervised workflow.');
});

it('sprint 46 document includes incident escalation and rollback decision gates', function () {
    $doc = sprint46Doc();
    expect($doc)->toContain('## 23. Incident escalation and rollback decision gates')
        ->and($doc)->toContain('Rollback decision tree review only.')
        ->and($doc)->toContain('Execute only in a separately approved workflow.');
});

it('sprint 46 document includes the post-execution review template', function () {
    $doc = sprint46Doc();
    expect($doc)->toContain('## 24. Post-execution review template')
        ->and($doc)->toContain('Related evidence review pack:')
        ->and($doc)->toContain('Go/No-Go carry-forward result:')
        ->and($doc)->toContain('This post-execution review template is for a future separately approved supervised workflow');
});

it('sprint 46 document forbids production access, deployment, backup, automation and runtime changes', function () {
    $doc = sprint46Doc();
    expect($doc)->toContain('No real pilot health-check execution.')
        ->and($doc)->toContain('No production/VPS/server access.')
        ->and($doc)->toContain('No database access.')
        ->and($doc)->toContain('No production log access.')
        ->and($doc)->toContain('No production file access.')
        ->and($doc)->toContain('No deployment.')
        ->and($doc)->toContain('No production command execution.')
        ->and($doc)->toContain('No production backup.')
        ->and($doc)->toContain('No production restore.')
        ->and($doc)->toContain('No rollback execution.')
        ->and($doc)->toContain('No external monitoring integration.')
        ->and($doc)->toContain('No scheduler/queue/cron automation.')
        ->and($doc)->toContain('No `.env` change.')
        ->and($doc)->toContain('No dependency/package install.')
        ->and($doc)->toContain('No migration/schema change.')
        ->and($doc)->toContain('No runtime behavior change.');
});

it('sprint 46 document preserves privacy constraints', function () {
    $doc = sprint46Doc();
    expect($doc)->toContain('KTP / `ktp_number` must remain hidden')
        ->and($doc)->toContain('No patient identifiers in evidence/runbook/execution-plan material')
        ->and($doc)->toContain('No secrets, tokens, credentials, or `.env` values')
        ->and($doc)->toContain('WhatsApp remains manual-only.')
        ->and($doc)->toContain('No WhatsApp API/send/automation');
});

it('sprint 46 document preserves financial constraints', function () {
    $doc = sprint46Doc();
    expect($doc)->toContain('Zero-remaining receivables remain excluded from active receivables.')
        ->and($doc)->toContain('Overpayment guard remains preserved.')
        ->and($doc)->toContain('Financial rules are not rewritten.');
});

it('sprint 46 document includes validation commands', function () {
    $doc = sprint46Doc();
    expect($doc)->toContain('## 27. Validation commands')
        ->and($doc)->toContain('php artisan test --filter=Sprint46PilotHealthCheckSupervisedExecutionPlanEvidenceReviewPack')
        ->and($doc)->toContain('vendor/bin/pint --test')
        ->and($doc)->toContain('git diff --check');
});

it('sprint history references Sprint 46, baseline and validation', function () {
    $history = file_get_contents(base_path('docs/sprint_history.md'));
    expect($history)->toContain('## Sprint 46 — Pilot Health Check Supervised Execution Plan & Evidence Review Pack')
        ->and($history)->toContain('Sprint 45 GO at `9aa3c11`')
        ->and($history)->toContain('Sprint46PilotHealthCheckSupervisedExecutionPlanEvidenceReviewPack');
});
