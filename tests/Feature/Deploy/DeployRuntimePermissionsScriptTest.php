<?php

/**
 * FIX-LOGIN-REDIRECT-RUNTIME-PERMISSIONS — deploy runtime-permission contract.
 *
 * Static guards on the deploy automation so the post-login 500 (root-owned
 * Laravel cache) can never regress: cache commands run as the PHP-FPM runtime
 * user, ownership is normalized, writable gates are mandatory, an authenticated
 * authorization smoke runs, and the deploy stays backup-first + non-destructive.
 */
function deployScript(): string
{
    return file_get_contents(base_path('scripts/deploy-vps.sh'));
}

function deployRunnerScript(): string
{
    return file_get_contents(base_path('scripts/deploy-vps-runner.sh'));
}

test('deploy script is fail-fast', function () {
    expect(deployScript())->toContain('set -euo pipefail');
});

test('deploy script detects the runtime user and fails closed on root', function () {
    $script = deployScript();

    expect($script)->toContain('RUNTIME_USER')
        ->and($script)->toContain('php-fpm')
        ->and($script)->toContain("refusing to use 'root'");
});

test('deploy script runs cache commands as the runtime user', function () {
    $script = deployScript();

    foreach ([
        'as_runtime php artisan optimize:clear',
        'as_runtime php artisan config:cache',
        'as_runtime php artisan route:cache',
        'as_runtime php artisan view:cache',
    ] as $marker) {
        expect($script)->toContain($marker);
    }

    expect($script)->toContain('runuser -u "$RUNTIME_USER"');
});

test('deploy script normalizes ownership with safe modes and never chmod 777', function () {
    $script = deployScript();

    expect($script)->toContain('chown -R "${RUNTIME_USER}:${RUNTIME_GROUP}" storage bootstrap/cache')
        ->and($script)->toContain('chmod 2775')
        ->and($script)->toContain('chmod 0664')
        ->and($script)->not->toContain('chmod -R 777')
        ->and($script)->not->toContain('chmod 777');
});

test('deploy script enforces mandatory writable gates that fail the deploy', function () {
    $script = deployScript();

    expect($script)->toContain('assert_runtime_writable')
        ->and($script)->toContain('CACHE WRITE')
        ->and($script)->toContain('SESSION WRITE')
        ->and($script)->toContain('VIEW CACHE WRITE')
        ->and($script)->toContain('LOG WRITE')
        ->and($script)->toContain('BOOTSTRAP CACHE WRITE')
        ->and($script)->toContain('NOT GO');
});

test('deploy script runs the authenticated authorization smoke strictly', function () {
    expect(deployScript())->toContain('as_runtime php artisan deploy:auth-landing-smoke --strict');
});

test('deploy script is backup-first before migrate', function () {
    $script = deployScript();

    $backupPos = strpos($script, 'pg_dump');
    $migratePos = strpos($script, 'php artisan migrate --force');

    expect($backupPos)->not->toBeFalse()
        ->and($migratePos)->not->toBeFalse()
        ->and($backupPos)->toBeLessThan($migratePos);
});

test('deploy script uses only non-destructive migration and no db wipe', function () {
    $script = deployScript();

    expect($script)->toContain('php artisan migrate --force')
        ->and($script)->not->toContain('migrate:fresh')
        ->and($script)->not->toContain('db:wipe')
        ->and($script)->not->toContain('migrate:reset');
});

test('deploy runner is intended to run on the VPS and records the real exit status', function () {
    $runner = deployRunnerScript();

    expect($runner)->toContain('set -euo pipefail')
        ->and($runner)->toContain('/var/www/asia-dental-lab-v2')
        ->and($runner)->toContain('exit=')
        ->and($runner)->toContain('do NOT treat as GO');
});
