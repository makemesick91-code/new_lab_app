<?php

use Illuminate\Support\Facades\File;

const VPS_DEPLOYMENT_CHECKLIST = 'docs/pilot/vps_pilot_deployment_checklist.md';

const SAFE_SEEDER_ROLLOUT = 'docs/pilot/safe_seeder_rollout.md';

const VPS_PREFLIGHT_SCRIPT = 'scripts/vps_pilot_preflight.sh';

function pilotDocContents(string $path): string
{
    $full = base_path($path);

    expect(File::exists($full))->toBeTrue("Expected doc at {$path}");

    return File::get($full);
}

function forbiddenSection(string $markdown): string
{
    if (preg_match('/## 11\. Perintah Terlarang(.*?)(?=## \d+\.|$)/s', $markdown, $matches)) {
        return $matches[1];
    }

    if (preg_match('/## 11\. Forbidden commands(.*?)(?=## \d+\.|$)/s', $markdown, $matches)) {
        return $matches[1];
    }

    return '';
}

it('confirms vps pilot deployment checklist doc exists', function () {
    expect(File::exists(base_path(VPS_DEPLOYMENT_CHECKLIST)))->toBeTrue();
});

it('confirms safe seeder rollout doc exists', function () {
    expect(File::exists(base_path(SAFE_SEEDER_ROLLOUT)))->toBeTrue();
});

it('confirms deployment checklist includes backup before seed guidance', function () {
    $content = pilotDocContents(VPS_DEPLOYMENT_CHECKLIST);

    expect($content)
        ->toContain('pg_dump')
        ->toContain('BACKUP_FILE')
        ->toContain('backup');
});

it('confirms deployment checklist includes safe seeder commands', function () {
    $content = pilotDocContents(VPS_DEPLOYMENT_CHECKLIST);

    expect($content)
        ->toContain('php artisan db:seed --class=PermissionSeeder')
        ->toContain('php artisan db:seed --class=RoleSeeder')
        ->toContain('php artisan db:seed --class=RmeSmokeTestSeeder');
});

it('confirms forbidden destructive commands are listed as forbidden in deployment checklist', function () {
    $content = pilotDocContents(VPS_DEPLOYMENT_CHECKLIST);
    $forbidden = forbiddenSection($content);

    expect($forbidden)->not->toBeEmpty()
        ->and($forbidden)->toContain('migrate:fresh')
        ->and($forbidden)->toContain('db:wipe');
});

it('confirms deployment checklist mentions heavy tests must use Ubuntu Terminal', function () {
    $content = pilotDocContents(VPS_DEPLOYMENT_CHECKLIST);

    expect($content)
        ->toContain('Ubuntu Terminal')
        ->toMatch('/bukan.*Cursor|not.*Cursor/i');
});

it('confirms deployment checklist includes rollback guidance', function () {
    $content = pilotDocContents(VPS_DEPLOYMENT_CHECKLIST);

    expect($content)
        ->toContain('Rollback')
        ->toContain('git checkout');
});

it('confirms safe seeder rollout mentions smoke test accounts and identifiers', function () {
    $content = pilotDocContents(SAFE_SEEDER_ROLLOUT);

    expect($content)
        ->toContain('dokter.smoke@pilot-test.local')
        ->toContain('perawat.smoke@pilot-test.local')
        ->toContain('kasir.smoke@pilot-test.local')
        ->toContain('owner.smoke@pilot-test.local')
        ->toContain('MRN-SMOKE-TEST-RME')
        ->toContain('VIS-SMOKE-TEST-RME')
        ->toContain('VIS-SMOKE-CASHIER-RME');
});

it('confirms vps preflight script exists and is safety-focused', function () {
    $path = base_path(VPS_PREFLIGHT_SCRIPT);

    expect(File::exists($path))->toBeTrue();

    $script = File::get($path);

    expect($script)
        ->toContain('This script does not modify database data.')
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
