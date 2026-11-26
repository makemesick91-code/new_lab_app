<?php

use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

/*
 * O-CI-1 (owner decision "REL-1 ACCEPTANCE AND POST-DEPLOYMENT INTEGRATION" §5, 2026-11-26).
 *
 * The hosted critical gate installs the PostgreSQL 16 client from the image's signed apt sources, puts it first on
 * PATH, logs client and server versions and fails unless their majors match. The R6-B13 rehearsal's restore_db keeps
 * its narrow accommodation for a newer client (one session-header line, one transaction, ON_ERROR_STOP), and none of
 * its paths can let a restore failure pass silently.
 *
 * Selected by the `RestoreDrill` critical-gate token in both runner variants and declared in
 * critical_gate_mandatory_suites (CG-19).
 */

function ociStep(string $name): array
{
    $steps = Yaml::parseFile(base_path('.github/workflows/foundation-evidence-gates.yml'))['jobs']['critical_test_gate']['steps'];
    foreach ($steps as $step) {
        if (($step['name'] ?? '') === $name) {
            return $step;
        }
    }
    throw new RuntimeException("critical_test_gate has no step '{$name}'");
}

function ociStepIndex(string $name): int
{
    $steps = Yaml::parseFile(base_path('.github/workflows/foundation-evidence-gates.yml'))['jobs']['critical_test_gate']['steps'];
    $index = array_search($name, array_map(fn (array $s) => (string) ($s['name'] ?? ''), $steps), true);
    expect($index)->not->toBeFalse("critical_test_gate has no step '{$name}'");

    return (int) $index;
}

it('installs and selects the PostgreSQL 16 client in the hosted critical gate before any database or test step (O-CI-1)', function () {
    $install = ociStep('Install and select the PostgreSQL 16 client (O-CI-1)');
    $verify = ociStep('Verify the PostgreSQL client and server majors match (O-CI-1)');

    expect($install['shell'] ?? null)->toBe('bash')
        ->and($verify['shell'] ?? null)->toBe('bash')
        ->and($install)->not->toHaveKey('continue-on-error')
        ->and($verify)->not->toHaveKey('continue-on-error')
        ->and($install['run'])->toContain('apt-cache policy postgresql-client-16')
        ->and($install['run'])->toContain('apt\.postgresql\.org/pub/repos/apt')
        ->and($install['run'])->toContain('is not offered by a trusted, signed apt source"; exit 1; }')
        ->and($install['run'])->toContain('sudo apt-get install -y --no-install-recommends postgresql-client-16')
        ->and($install['run'])->toContain('echo /usr/lib/postgresql/16/bin >> "$GITHUB_PATH"')
        ->and($install['run'])->not->toContain('--allow-unauthenticated')
        ->and($install['run'])->not->toContain('trusted=yes')
        ->and($verify['run'])->toContain('SHOW server_version_num')
        ->and($verify['run'])->toContain('not the selected 16 client"; exit 1; }')
        ->and($verify['run'])->toContain('does not match the server major $smaj"; exit 1; }')
        ->and($verify['run'])->toContain('postgresql_client_server_major=MATCH');

    $poppler = ociStepIndex('Install and verify Poppler (LEGACY-RME-PDF-1B)');
    $i = ociStepIndex('Install and select the PostgreSQL 16 client (O-CI-1)');
    $v = ociStepIndex('Verify the PostgreSQL client and server majors match (O-CI-1)');
    expect($i)->toBe($poppler + 1)
        ->and($v)->toBe($i + 1)
        ->and($v)->toBeLessThan(ociStepIndex('Run migrations'))
        ->and($v)->toBeLessThan(ociStepIndex('Run critical regression tests'));
});

it('keeps the gate database service at PostgreSQL 16 and leaves the R6-B6 pinned 16.15 drill binaries alone (O-CI-1)', function () {
    $job = Yaml::parseFile(base_path('.github/workflows/foundation-evidence-gates.yml'))['jobs']['critical_test_gate'];
    $install = ociStep('Install and select the PostgreSQL 16 client (O-CI-1)')['run'];

    expect($job['services']['postgres']['image'])->toBe('postgres:16')
        ->and($install)->not->toContain('restore-drill')
        ->and($install)->not->toMatch('/postgresql-16(\s|$)/')
        ->and((string) file_get_contents(base_path('scripts/restore-drill-vm-image.sh')))
        ->toContain('psql \(PostgreSQL\) 16\.(1[5-9]|[2-9][0-9])');
});

it('keeps restore_db strict: one header line, one transaction, ON_ERROR_STOP, and exit-on-error on the direct paths (O-CI-1)', function () {
    // Code lines only: the header comment describes the flags it requires.
    $helper = implode("\n", array_filter(
        explode("\n", (string) file_get_contents(base_path('tests/Fixtures/clinical-assets/restore-db.sh'))),
        fn (string $line): bool => ! str_starts_with(ltrim($line), '#'),
    ));
    $rehearsal = (string) file_get_contents(base_path('tests/Fixtures/clinical-assets/rehearsal.sh'));

    expect(substr_count($helper, "grep -vxF 'SET transaction_timeout = 0;'"))->toBe(1)
        ->and(substr_count($helper, 'grep -v'))->toBe(1)
        ->and(substr_count($helper, '--exit-on-error'))->toBe(2)
        ->and($helper)->toContain('psql -X -q -v ON_ERROR_STOP=1 --single-transaction -d "$2"')
        ->and($helper)->not->toContain('|| true')
        ->and($helper)->not->toContain('2> /dev/null; return')
        ->and($rehearsal)->toContain('. "$REPO/tests/Fixtures/clinical-assets/restore-db.sh"')
        ->and($rehearsal)->not->toContain('restore_db() {')
        ->and($rehearsal)->toContain('[ "$rc" = 0 ] && [ "$r4" = 0 ]; ok C4-restore-isolated');
});

it('never lets a restore failure pass silently on any restore_db path, and rolls back a partly applied restore (O-CI-1)', function () {
    if (config('database.default') !== 'pgsql') {
        $this->markTestSkipped('needs PostgreSQL: the guard creates throwaway databases');
    }
    $c = config('database.connections.pgsql');
    $work = sys_get_temp_dir().'/rdg-'.bin2hex(random_bytes(6));
    $p = new Process(['bash', base_path('tests/Fixtures/clinical-assets/restore-db-guard.sh'), $work], null, [
        'PGHOST' => (string) $c['host'], 'PGPORT' => (string) $c['port'], 'PGUSER' => (string) $c['username'],
        'PGPASSWORD' => (string) ($c['password'] ?? ''), 'PGDATABASE' => (string) $c['database'], 'PATH' => getenv('PATH'),
    ]);
    $p->setTimeout(300);
    $p->run();
    $out = $p->getOutput();

    expect($p->getExitCode())->toBe(0, $out.$p->getErrorOutput())
        ->and($out)->toContain('TOTAL pass=6 fail=0')
        ->and($out)->toContain('PASS N2-any-other-setting-fails-and-restores-nothing')
        ->and($out)->toContain('PASS N3-late-error-rolls-back-everything')
        ->and(is_dir($work))->toBeFalse();
});
