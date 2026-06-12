<?php

use Illuminate\Support\Facades\File;

const OWNER_DASHBOARD_SMOKE_CHECKLIST = 'docs/pilot/owner_dashboard_manual_smoke_test_checklist.md';

const VPS_DEPLOYMENT_CHECKLIST_DOC = 'docs/pilot/vps_pilot_deployment_checklist.md';

const SAFE_SEEDER_ROLLOUT_DOC = 'docs/pilot/safe_seeder_rollout.md';

const VPS_PREFLIGHT_SCRIPT_PATH = 'scripts/vps_pilot_preflight.sh';

function ownerSmokeDocContents(): string
{
    $path = base_path(OWNER_DASHBOARD_SMOKE_CHECKLIST);

    expect(File::exists($path))->toBeTrue('Owner dashboard manual smoke checklist must exist');

    return File::get($path);
}

it('confirms owner dashboard manual smoke checklist doc exists', function () {
    expect(File::exists(base_path(OWNER_DASHBOARD_SMOKE_CHECKLIST)))->toBeTrue();
});

it('confirms checklist includes monitoring pilot RME and lab section name', function () {
    $content = ownerSmokeDocContents();

    expect($content)->toContain('Monitoring Pilot RME & Lab');
});

it('confirms checklist includes branch filter wording', function () {
    $content = ownerSmokeDocContents();

    expect($content)
        ->toContain('Filter Cabang')
        ->toContain('Semua Cabang')
        ->toContain('Menampilkan semua cabang aktif')
        ->toContain('Menampilkan cabang');
});

it('confirms checklist includes branch summary wording', function () {
    $content = ownerSmokeDocContents();

    expect($content)->toContain('Ringkasan Per Cabang');
});

it('confirms checklist includes drilldown permission and read-only wording', function () {
    $content = ownerSmokeDocContents();

    expect($content)
        ->toContain('Lihat detail')
        ->toMatch('/permission-aware|sesuai permission/i')
        ->toMatch('/read-only|hanya monitoring/i');
});

it('confirms checklist includes role boundary accounts', function () {
    $content = ownerSmokeDocContents();

    expect($content)
        ->toContain('Branch Admin')
        ->toContain('Kasir')
        ->toContain('Owner');
});

it('confirms checklist includes forbidden destructive commands', function () {
    $content = ownerSmokeDocContents();

    expect($content)
        ->toContain('migrate:fresh')
        ->toContain('migrate:fresh --seed')
        ->toContain('db:wipe')
        ->toMatch('/db:seed.*tanpa.*--class|unqualified.*db:seed|tanpa `--class=`/i');
});

it('confirms checklist includes safe seeder class names', function () {
    $content = ownerSmokeDocContents();

    expect($content)
        ->toContain('PermissionSeeder')
        ->toContain('RoleSeeder')
        ->toContain('RmeSmokeTestSeeder');
});

it('confirms vps pilot deployment checklist references owner dashboard smoke checklist', function () {
    $content = File::get(base_path(VPS_DEPLOYMENT_CHECKLIST_DOC));

    expect($content)
        ->toContain('docs/pilot/owner_dashboard_manual_smoke_test_checklist.md')
        ->toContain('Owner Dashboard Smoke Test');
});

it('confirms safe seeder rollout states owner dashboard features do not need new seeder', function () {
    $content = File::get(base_path(SAFE_SEEDER_ROLLOUT_DOC));

    expect($content)
        ->toMatch('/tidak memerlukan seeder baru|do not require a new seeder/i')
        ->toContain('filter cabang')
        ->toContain('PermissionSeeder')
        ->toContain('RoleSeeder');
});

it('confirms vps preflight script mentions owner dashboard manual smoke checklist', function () {
    $script = File::get(base_path(VPS_PREFLIGHT_SCRIPT_PATH));

    expect($script)->toContain('docs/pilot/owner_dashboard_manual_smoke_test_checklist.md');
});

it('confirms vps preflight script does not execute destructive artisan commands', function () {
    $script = File::get(base_path(VPS_PREFLIGHT_SCRIPT_PATH));

    expect($script)
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
