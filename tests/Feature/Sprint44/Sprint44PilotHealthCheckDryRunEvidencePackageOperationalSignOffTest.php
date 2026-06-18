<?php

// Sprint 44 — Pilot Health Check Dry-Run Evidence Package & Operational Sign-off
// Checklist assertions over the Sprint 44 governance doc + sprint history.
// Governance-only sprint: documentation/checklist regression, no functional behavior under test.

function sprint44Doc(): string
{
    return file_get_contents(base_path('docs/sprint_44_pilot_health_check_dry_run_evidence_package_operational_sign_off.md'));
}

it('sprint 44 document exists', function () {
    expect(file_exists(base_path('docs/sprint_44_pilot_health_check_dry_run_evidence_package_operational_sign_off.md')))->toBeTrue();
});

it('sprint 44 document contains the title, branch, tags and Sprint 43 baseline', function () {
    $doc = sprint44Doc();
    expect($doc)->toContain('# Sprint 44 — Pilot Health Check Dry-Run Evidence Package & Operational Sign-off')
        ->and($doc)->toContain('feature/sprint-44-pilot-health-check-dry-run-evidence-package-operational-sign-off')
        ->and($doc)->toContain('sprint-44-pilot-health-check-dry-run-evidence-package-operational-sign-off')
        ->and($doc)->toContain('sprint-44-pilot-health-check-dry-run-evidence-package-operational-sign-off-go')
        ->and($doc)->toContain('Sprint 43 GO at 5c2d8b5');
});

it('sprint 44 document states documentation / checklist-test only', function () {
    expect(sprint44Doc())->toContain('documentation/checklist-test only');
});

it('sprint 44 document defines dry-run as no production / VPS / system touch', function () {
    $doc = sprint44Doc();
    expect($doc)->toContain('Dry-run definition')
        ->and($doc)->toContain('without touching VPS/production systems')
        ->and($doc)->toContain('dry-run evidence package template only');
});

it('sprint 44 document includes the pilot health-check dry-run checklist', function () {
    $doc = sprint44Doc();
    expect($doc)->toContain('Pilot health-check dry-run checklist')
        ->and($doc)->toContain('Confirm approved dry-run scope')
        ->and($doc)->toContain('Confirm the target environment is **not** accessed during this sprint')
        ->and($doc)->toContain('Confirm no production command execution')
        ->and($doc)->toContain('Confirm next supervised workflow requirements');
});

it('sprint 44 document includes the dry-run evidence package template fields', function () {
    $doc = sprint44Doc();
    expect($doc)->toContain('Dry-run evidence package template')
        ->and($doc)->toContain('Evidence Package ID:')
        ->and($doc)->toContain('KTP exposure check:')
        ->and($doc)->toContain('Approval decision:')
        ->and($doc)->toContain('Sign-off timestamp:')
        ->and($doc)->toContain('placeholders only');
});

it('sprint 44 document includes the evidence package naming convention', function () {
    $doc = sprint44Doc();
    expect($doc)->toContain('Evidence package naming convention')
        ->and($doc)->toContain('docs/evidence/sprint-44/');
});

it('sprint 44 document includes the operational sign-off workflow', function () {
    $doc = sprint44Doc();
    expect($doc)->toContain('Operational sign-off workflow')
        ->and($doc)->toContain('Approve, approve with conditions, or reject')
        ->and($doc)->toContain('does **not** authorize deployment');
});

it('sprint 44 document includes approval gates and incident escalation dry-run gates', function () {
    $doc = sprint44Doc();
    expect($doc)->toContain('Approval gates')
        ->and($doc)->toContain('Real pilot health-check execution requires a separate supervised workflow')
        ->and($doc)->toContain('Incident escalation dry-run gates')
        ->and($doc)->toContain('Execute only in separately approved workflow');
});

it('sprint 44 document forbids all out-of-scope operational actions', function () {
    $doc = sprint44Doc();
    expect($doc)->toContain('No real pilot health-check execution')
        ->and($doc)->toContain('No production / VPS access')
        ->and($doc)->toContain('No deployment')
        ->and($doc)->toContain('No production backup execution')
        ->and($doc)->toContain('No production restore execution')
        ->and($doc)->toContain('No rollback execution')
        ->and($doc)->toContain('No external monitoring service integration')
        ->and($doc)->toContain('No scheduler / queue / cron automation')
        ->and($doc)->toContain('No `.env` change')
        ->and($doc)->toContain('No dependency / package install')
        ->and($doc)->toContain('No migration / schema change')
        ->and($doc)->toContain('No runtime behavior change');
});

it('sprint 44 document preserves privacy constraints', function () {
    $doc = sprint44Doc();
    expect($doc)->toContain('KTP / `ktp_number` must remain hidden')
        ->and($doc)->toContain('evidence package content')
        ->and($doc)->toContain('avoid unnecessary exposure of patient contact data')
        ->and($doc)->toContain('Manual WhatsApp only')
        ->and($doc)->toContain('No WhatsApp automation / send / API');
});

it('sprint 44 document preserves financial constraints', function () {
    $doc = sprint44Doc();
    expect($doc)->toContain('Zero-remaining receivables remain excluded from active receivables')
        ->and($doc)->toContain('Overpayment guard remains preserved')
        ->and($doc)->toContain('Financial rules are not rewritten');
});

it('sprint 44 document includes validation commands', function () {
    $doc = sprint44Doc();
    expect($doc)->toContain('php artisan test --filter=Sprint44PilotHealthCheckDryRunEvidencePackageOperationalSignOff')
        ->and($doc)->toContain('vendor/bin/pint --test')
        ->and($doc)->toContain('git diff --check');
});

it('sprint history references Sprint 44, baseline and validation', function () {
    $history = file_get_contents(base_path('docs/sprint_history.md'));
    expect($history)->toContain('## Sprint 44 — Pilot Health Check Dry-Run Evidence Package & Operational Sign-off')
        ->and($history)->toContain('5c2d8b5')
        ->and($history)->toContain('Sprint44PilotHealthCheckDryRunEvidencePackageOperationalSignOff');
});
