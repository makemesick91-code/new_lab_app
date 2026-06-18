<?php

// Sprint 47 — Pilot Health Check Supervised Execution Approval Gate & Operator Checklist.
// Documentation + checklist regression test only — validates the Sprint 47 governance
// documentation, supervised execution approval gate, operator checklist, approval matrix,
// and sprint history entry.

function sprint47DocPath(): string
{
    return base_path('docs/sprint_47_pilot_health_check_supervised_execution_approval_gate_operator_checklist.md');
}

function sprint47Doc(): string
{
    return file_get_contents(sprint47DocPath());
}

it('sprint 47 document exists', function () {
    expect(file_exists(sprint47DocPath()))->toBeTrue();
});

it('sprint 47 document contains the title, branch, tags and Sprint 46 baseline', function () {
    $doc = sprint47Doc();
    expect($doc)->toContain('# Sprint 47 — Pilot Health Check Supervised Execution Approval Gate & Operator Checklist')
        ->and($doc)->toContain('feature/sprint-47-pilot-health-check-supervised-execution-approval-gate-operator-checklist')
        ->and($doc)->toContain('sprint-47-pilot-health-check-supervised-execution-approval-gate-operator-checklist')
        ->and($doc)->toContain('sprint-47-pilot-health-check-supervised-execution-approval-gate-operator-checklist-go')
        ->and($doc)->toContain('Sprint 46 GO / merge commit 8130050');
});

it('sprint 47 document states it is documentation and checklist-test only', function () {
    $doc = sprint47Doc();
    expect($doc)->toContain('This sprint is documentation/checklist-test only.')
        ->and($doc)->toContain('This sprint prepares a supervised execution approval gate only.')
        ->and($doc)->toContain('This sprint prepares an operator checklist template only.')
        ->and($doc)->toContain('This sprint does not execute a supervised pilot health check.')
        ->and($doc)->toContain('This sprint does not authorize real supervised execution.');
});

it('sprint 47 document defines the supervised execution approval gate and does not authorize execution', function () {
    $doc = sprint47Doc();
    expect($doc)->toContain('## 7. Supervised execution approval gate definition')
        ->and($doc)->toContain('Sprint 47 does not authorize execution.')
        ->and($doc)->toContain('Real supervised execution requires a separate explicitly')
        ->and($doc)->toContain('Any actual supervised execution must be a separate explicitly approved workflow after Sprint 47.');
});

it('sprint 47 document includes approval prerequisites', function () {
    $doc = sprint47Doc();
    expect($doc)->toContain('## 8. Approval prerequisites')
        ->and($doc)->toContain('Base branch and latest GO tag confirmed.')
        ->and($doc)->toContain('Sprint 46 supervised execution plan reviewed.')
        ->and($doc)->toContain('Future time window proposed but not executed.');
});

it('sprint 47 document includes the approval matrix', function () {
    $doc = sprint47Doc();
    expect($doc)->toContain('## 9. Approval matrix')
        ->and($doc)->toContain('Owner/Admin Approver')
        ->and($doc)->toContain('Execution Owner')
        ->and($doc)->toContain('Evidence Reviewer')
        ->and($doc)->toContain('Observer/Recorder');
});

it('sprint 47 document includes operator role boundaries', function () {
    $doc = sprint47Doc();
    expect($doc)->toContain('## 10. Operator role boundaries')
        ->and($doc)->toContain('In Sprint 47, the operator role is defined for a future workflow only.')
        ->and($doc)->toContain('No operator performs real production checks in Sprint 47.');
});

it('sprint 47 document includes the operator readiness checklist', function () {
    $doc = sprint47Doc();
    expect($doc)->toContain('## 11. Operator readiness checklist')
        ->and($doc)->toContain('Operator has read Sprint 45 readiness runbook.')
        ->and($doc)->toContain('Operator has read Sprint 46 execution plan.')
        ->and($doc)->toContain('Operator understands KTP must remain hidden.');
});

it('sprint 47 document includes operator checklist phases', function () {
    $doc = sprint47Doc();
    expect($doc)->toContain('## 12. Operator checklist phases')
        ->and($doc)->toContain('Confirm approval gate status.')
        ->and($doc)->toContain('Confirm manual WhatsApp observation boundary.')
        ->and($doc)->toContain('These operator checklist phases are prepared for a future separately approved supervised workflow and');
});

it('sprint 47 document includes evidence handling responsibilities', function () {
    $doc = sprint47Doc();
    expect($doc)->toContain('## 13. Evidence handling responsibilities')
        ->and($doc)->toContain('Operator records only approved observations in future supervised workflow.')
        ->and($doc)->toContain('No KTP is recorded.')
        ->and($doc)->toContain('Evidence must distinguish observation from execution.');
});

it('sprint 47 document includes evidence acceptance and rejection reminders', function () {
    $doc = sprint47Doc();
    expect($doc)->toContain('## 14. Evidence acceptance and rejection reminders')
        ->and($doc)->toContain('Evidence matches approved scope.')
        ->and($doc)->toContain('Reject evidence containing KTP or unnecessary patient identifiers.')
        ->and($doc)->toContain('Reject evidence that includes WhatsApp automation/send/API activity.');
});

it('sprint 47 document includes stop and abort gates', function () {
    $doc = sprint47Doc();
    expect($doc)->toContain('## 15. Stop / abort gates')
        ->and($doc)->toContain('Any command would mutate production data.')
        ->and($doc)->toContain('WhatsApp automation/send/API is proposed.')
        ->and($doc)->toContain('Communication channel is unavailable.');
});

it('sprint 47 document includes communication and escalation rules', function () {
    $doc = sprint47Doc();
    expect($doc)->toContain('## 16. Communication and escalation rules')
        ->and($doc)->toContain('Operator must stop and escalate when abort criteria appear.')
        ->and($doc)->toContain('No emergency action is executed from Sprint 47 documentation.');
});

it('sprint 47 document includes Go/No-Go carry-forward confirmation', function () {
    $doc = sprint47Doc();
    expect($doc)->toContain('## 17. Go/No-Go carry-forward confirmation')
        ->and($doc)->toContain('Abort criteria override any Go or Conditional Go.')
        ->and($doc)->toContain('Actual execution requires a separate explicitly approved supervised workflow.');
});

it('sprint 47 document includes operational sign-off workflow and approval gates', function () {
    $doc = sprint47Doc();
    expect($doc)->toContain('## 18. Operational sign-off workflow')
        ->and($doc)->toContain('Owner/admin decision: approve checklist, approve with conditions, or reject checklist.')
        ->and($doc)->toContain('## 19. Approval gates')
        ->and($doc)->toContain('Real pilot health-check execution requires a separate supervised workflow.');
});

it('sprint 47 document includes incident escalation and rollback decision gates', function () {
    $doc = sprint47Doc();
    expect($doc)->toContain('## 25. Incident escalation and rollback decision gates')
        ->and($doc)->toContain('Rollback decision tree review only.')
        ->and($doc)->toContain('Execute only in a separately approved workflow.');
});

it('sprint 47 document includes the post-check operator handoff template', function () {
    $doc = sprint47Doc();
    expect($doc)->toContain('## 26. Post-check operator handoff template')
        ->and($doc)->toContain('Related approval gate:')
        ->and($doc)->toContain('Go/No-Go carry-forward result:')
        ->and($doc)->toContain('This post-check operator handoff template is for a future separately approved supervised workflow');
});

it('sprint 47 document forbids production access, deployment, backup, automation and runtime changes', function () {
    $doc = sprint47Doc();
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

it('sprint 47 document preserves privacy constraints', function () {
    $doc = sprint47Doc();
    expect($doc)->toContain('KTP / `ktp_number` must remain hidden')
        ->and($doc)->toContain('No patient identifiers in evidence/runbook/execution-plan/operator material')
        ->and($doc)->toContain('No secrets, tokens, credentials, or `.env` values')
        ->and($doc)->toContain('WhatsApp remains manual-only.')
        ->and($doc)->toContain('No WhatsApp API/send/automation');
});

it('sprint 47 document preserves financial constraints', function () {
    $doc = sprint47Doc();
    expect($doc)->toContain('Zero-remaining receivables remain excluded from active receivables.')
        ->and($doc)->toContain('Overpayment guard remains preserved.')
        ->and($doc)->toContain('Financial rules are not rewritten.');
});

it('sprint 47 document includes validation commands', function () {
    $doc = sprint47Doc();
    expect($doc)->toContain('## 29. Validation commands')
        ->and($doc)->toContain('php artisan test --filter=Sprint47PilotHealthCheckSupervisedExecutionApprovalGateOperatorChecklist')
        ->and($doc)->toContain('vendor/bin/pint --test')
        ->and($doc)->toContain('git diff --check');
});

it('sprint history references Sprint 47, baseline and validation', function () {
    $history = file_get_contents(base_path('docs/sprint_history.md'));
    expect($history)->toContain('## Sprint 47 — Pilot Health Check Supervised Execution Approval Gate & Operator Checklist')
        ->and($history)->toContain('Sprint 46 GO at `8130050`')
        ->and($history)->toContain('Sprint47PilotHealthCheckSupervisedExecutionApprovalGateOperatorChecklist');
});
