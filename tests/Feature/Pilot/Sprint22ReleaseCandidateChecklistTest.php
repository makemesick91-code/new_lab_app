<?php

use Illuminate\Support\Facades\File;

const SPRINT_22_RC_NOTES = 'docs/pilot/sprint_22_release_candidate_notes.md';

const VPS_GO_NO_GO_CHECKLIST = 'docs/pilot/vps_pilot_go_no_go_checklist.md';

const VPS_DEPLOY_CHECKLIST_PATH = 'docs/pilot/vps_pilot_deployment_checklist.md';

const SAFE_SEEDER_ROLLOUT_PATH = 'docs/pilot/safe_seeder_rollout.md';

const VPS_PREFLIGHT_SCRIPT_FILE = 'scripts/vps_pilot_preflight.sh';

function sprint22RcDocContents(): string
{
    $path = base_path(SPRINT_22_RC_NOTES);

    expect(File::exists($path))->toBeTrue('Sprint 22 release candidate notes must exist');

    return File::get($path);
}

function goNoGoDocContents(): string
{
    $path = base_path(VPS_GO_NO_GO_CHECKLIST);

    expect(File::exists($path))->toBeTrue('VPS go/no-go checklist must exist');

    return File::get($path);
}

it('confirms sprint 22 release candidate notes doc exists', function () {
    expect(File::exists(base_path(SPRINT_22_RC_NOTES)))->toBeTrue();
});

it('confirms release candidate notes include all phases 22.1 through 22.8', function () {
    $content = sprint22RcDocContents();

    foreach (range(1, 8) as $phase) {
        expect($content)->toContain('22.'.$phase);
    }
});

it('confirms release candidate notes include pushed branch and tag references for phases 22.1 through 22.7', function () {
    $content = sprint22RcDocContents();

    $expectedBranches = [
        'feature/sprint-22-role-permission-menu-hardening',
        'feature/sprint-22-rme-smoke-test-checklist',
        'feature/sprint-22-vps-pilot-deployment-checklist',
        'feature/sprint-22-rme-lab-candidate-e2e-validation',
        'feature/sprint-22-owner-dashboard-rme-lab-kpi',
        'feature/sprint-22-owner-dashboard-branch-filter-drilldown',
        'feature/sprint-22-vps-owner-dashboard-smoke-checklist',
    ];

    $expectedTags = [
        'sprint-22-phase-22-1-pilot-role-permission-menu-hardening',
        'sprint-22-phase-22-2-rme-smoke-test-checklist',
        'sprint-22-phase-22-3-vps-pilot-deployment-checklist',
        'sprint-22-phase-22-4-rme-lab-candidate-e2e-validation',
        'sprint-22-phase-22-5-owner-dashboard-rme-lab-kpi',
        'sprint-22-phase-22-6-owner-dashboard-branch-filter-drilldown',
        'sprint-22-phase-22-7-owner-dashboard-smoke-checklist',
    ];

    foreach ($expectedBranches as $branch) {
        expect($content)->toContain($branch);
    }

    foreach ($expectedTags as $tag) {
        expect($content)->toContain($tag);
    }
});

it('confirms release candidate notes include deploy target branch and tag for phase 22.8', function () {
    $content = sprint22RcDocContents();

    expect($content)
        ->toContain('feature/sprint-22-closure-rc-go-no-go')
        ->toContain('sprint-22-phase-22-8-closure-rc-go-no-go');
});

it('confirms release candidate notes include safe deploy commands', function () {
    $content = sprint22RcDocContents();

    expect($content)
        ->toContain('git fetch --all --tags')
        ->toContain('composer install --no-dev --optimize-autoloader')
        ->toContain('php artisan optimize:clear')
        ->toContain('php artisan migrate --force')
        ->toContain('php artisan db:seed --class=PermissionSeeder')
        ->toContain('php artisan db:seed --class=RoleSeeder');
});

it('confirms release candidate notes include optional RmeSmokeTestSeeder wording', function () {
    $content = sprint22RcDocContents();

    expect($content)
        ->toContain('RmeSmokeTestSeeder')
        ->toMatch('/opsional|optional/i');
});

it('confirms release candidate notes include forbidden commands', function () {
    $content = sprint22RcDocContents();

    expect($content)
        ->toContain('migrate:fresh')
        ->toContain('migrate:fresh --seed')
        ->toContain('db:wipe')
        ->toMatch('/db:seed.*tanpa|unqualified|tanpa `--class=`/i');
});

it('confirms release candidate notes include GO criteria', function () {
    $content = sprint22RcDocContents();

    expect($content)
        ->toMatch('/Kriteria GO|## 10\. Kriteria GO/i')
        ->toContain('Monitoring Pilot RME & Lab')
        ->toContain('Filter Cabang')
        ->toContain('Ringkasan Per Cabang');
});

it('confirms release candidate notes include NO-GO criteria', function () {
    $content = sprint22RcDocContents();

    expect($content)
        ->toMatch('/Kriteria NO-GO|## 11\. Kriteria NO-GO/i')
        ->toMatch('/backup.*0 byte|0 byte/i')
        ->toContain('500');
});

it('confirms release candidate notes include rollback plan', function () {
    $content = sprint22RcDocContents();

    expect($content)
        ->toMatch('/Rencana Rollback|rollback/i')
        ->toContain('git checkout')
        ->toContain('composer install --no-dev --optimize-autoloader');
});

it('confirms go no go checklist doc exists', function () {
    expect(File::exists(base_path(VPS_GO_NO_GO_CHECKLIST)))->toBeTrue();
});

it('confirms go no go checklist includes sign-off roles', function () {
    $content = goNoGoDocContents();

    expect($content)
        ->toContain('Developer')
        ->toContain('Operator Klinik')
        ->toContain('Owner')
        ->toContain('Admin Lab')
        ->toContain('Kasir');
});

it('confirms go no go checklist includes owner dashboard checks', function () {
    $content = goNoGoDocContents();

    expect($content)
        ->toContain('Monitoring Pilot RME & Lab')
        ->toContain('Filter Cabang')
        ->toContain('Ringkasan Per Cabang');
});

it('confirms go no go checklist includes RME and lab candidate checks', function () {
    $content = goNoGoDocContents();

    expect($content)
        ->toMatch('/RME|rme_smoke_test_operator_checklist/i')
        ->toMatch('/Lab Candidate|lab candidate|rme_lab_candidate/i');
});

it('confirms vps deployment checklist references sprint 22 rc and go no go docs', function () {
    $content = File::get(base_path(VPS_DEPLOY_CHECKLIST_PATH));

    expect($content)
        ->toContain('docs/pilot/sprint_22_release_candidate_notes.md')
        ->toContain('docs/pilot/vps_pilot_go_no_go_checklist.md')
        ->toMatch('/Sprint 22 Release Candidate|Go\/No-Go/i');
});

it('confirms safe seeder rollout references sprint 22 rc and go no go docs', function () {
    $content = File::get(base_path(SAFE_SEEDER_ROLLOUT_PATH));

    expect($content)
        ->toContain('docs/pilot/sprint_22_release_candidate_notes.md')
        ->toContain('docs/pilot/vps_pilot_go_no_go_checklist.md');
});

it('confirms preflight script mentions rc and go no go docs without executing destructive commands', function () {
    $script = File::get(base_path(VPS_PREFLIGHT_SCRIPT_FILE));

    expect($script)
        ->toContain('docs/pilot/sprint_22_release_candidate_notes.md')
        ->toContain('docs/pilot/vps_pilot_go_no_go_checklist.md')
        ->toContain('Do not run migrate:fresh or db:wipe on VPS.');

    foreach (explode("\n", $script) as $line) {
        $trimmed = trim($line);

        if ($trimmed === '' || str_starts_with($trimmed, '#') || str_starts_with($trimmed, 'echo ')) {
            continue;
        }

        expect($trimmed)
            ->not->toMatch('/^php artisan (migrate:fresh|db:wipe|migrate --force|db:seed)\b/');
    }
});
