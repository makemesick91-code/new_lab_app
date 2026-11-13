<?php

use Symfony\Component\Process\Process;

uses()->group('Deploy', 'Security', 'ReleaseSafety');

/**
 * DMS-POST-MIGRATION-DEV-INFRA-ALIGNMENT-1 — the deploy chain proves where it is
 * before it changes anything.
 *
 * The deploy scripts were written for the Hostinger host, where the deploy ran
 * as root and Node was installed. On Biznet the admin login is not root and
 * Node is absent, and the deploy discovers both only AFTER it has checked out
 * new code: `npm ci` runs after `git merge` and `composer install`. Nothing
 * stopped a run on the wrong machine either — including the frozen Hostinger
 * host. The storage tree was copied in the migration, so Biznet also inherited
 * Hostinger's `latest.status: exit=0`, and `status` reported DEPLOY RUNNER OK
 * for a deploy that never ran there. And the provisioner's `systemctl restart`
 * would have STARTED the queue worker the owner keeps off.
 *
 * Nothing here needs root or a server: the preflight takes every observed value
 * as a parameter, and the runner is exercised in a temporary application tree.
 */
function dhpRun(array $command, ?string $cwd = null, array $env = []): array
{
    $process = new Process($command, $cwd, $env);
    $process->run();

    return [
        'exit' => (int) $process->getExitCode(),
        'output' => $process->getOutput().$process->getErrorOutput(),
    ];
}

/** @return array{exit: int, output: string} */
function dhpPreflight(string $euid, string $hostname, string $addresses, array $tools = ['bash'], ?string $authority = null): array
{
    return dhpRun(array_merge([
        'bash', '-c',
        'source "$1"; dms_production_host_preflight "$2" "$3" "$4" "$5" "${@:6}"',
        '_',
        base_path('scripts/lib/production-host-preflight.sh'),
        $authority ?? base_path('deploy/production-host.conf'),
        $euid, $hostname, $addresses,
    ], $tools));
}

/** Line numbers of executable (non-comment) lines containing $needle. */
function dhpLinesOf(string $script, string $needle): array
{
    $lines = preg_split('/\R/', (string) file_get_contents(base_path($script))) ?: [];
    $hits = [];
    foreach ($lines as $index => $line) {
        $code = ltrim($line);
        if ($code !== '' && ! str_starts_with($code, '#') && str_contains($code, $needle)) {
            $hits[] = $index + 1;
        }
    }

    return $hits;
}

/** A temporary application tree for the runner, with a status already present. */
function dhpRunnerTree(?string $statusLine, ?string $hostLine): string
{
    $app = tempArtifactDir('dhp-runner-');
    $runDir = "{$app}/storage/logs/deploy-runner";
    mkdir($runDir, 0o700, true);

    if ($statusLine !== null) {
        file_put_contents("{$runDir}/deploy-20261104-000000.status", $statusLine."\n");
        symlink('deploy-20261104-000000.status', "{$runDir}/latest.status");
    }
    if ($hostLine !== null) {
        file_put_contents("{$runDir}/deploy-20261104-000000.host", $hostLine."\n");
    }

    return $app;
}

function dhpRunner(string $app, string $action, array $env = []): array
{
    return dhpRun(['bash', base_path('scripts/deploy-vps-runner.sh'), $action], null, array_merge(['APP_DIR' => $app], $env));
}

it('passes only on the declared production host, as root, with every tool present', function () {
    $result = dhpPreflight('0', 'DaengtisiaMS', '10.0.0.5 139.190.106.38', ['bash', 'sh']);

    expect($result['exit'])->toBe(0, $result['output'])
        ->and($result['output'])->toContain('PRODUCTION HOST PREFLIGHT: GO')
        ->and($result['output'])->toContain("hostname is the production host 'DaengtisiaMS'")
        ->and($result['output'])->toContain('host carries the production address 139.190.106.38');
});

it('refuses before any mutation when it is not provably the production host', function (string $euid, string $host, string $addresses, array $tools, string $reason) {
    $result = dhpPreflight($euid, $host, $addresses, $tools);

    expect($result['exit'])->toBe(1, $result['output'])
        ->and($result['output'])->toContain($reason)
        ->and($result['output'])->toContain('PRODUCTION HOST PREFLIGHT: NOT GO')
        ->and($result['output'])->toContain('nothing was backed up, checked out or migrated')
        ->and($result['output'])->not->toContain('PRODUCTION HOST PREFLIGHT: GO');
})->with([
    'admin account without sudo' => ['1002', 'DaengtisiaMS', '139.190.106.38', ['bash'], 'the deploy must run as root'],
    'the frozen Hostinger host' => ['0', 'srv1730088', '145.79.13.224', ['bash'], "this host 'srv1730088' is FROZEN legacy infrastructure"],
    'a host carrying the frozen address' => ['0', 'DaengtisiaMS', '139.190.106.38 145.79.13.224', ['bash'], 'carries the FROZEN legacy address 145.79.13.224'],
    'the Biznet CI runner' => ['0', 'daengtisia-ci-biznet', '100.121.146.97', ['bash'], "expected the production host 'DaengtisiaMS'"],
    'right name, wrong address' => ['0', 'DaengtisiaMS', '192.0.2.10', ['bash'], 'does not carry the production address 139.190.106.38'],
    'a missing tool (Node on Biznet today)' => ['0', 'DaengtisiaMS', '139.190.106.38', ['bash', 'dms-definitely-absent-tool'], "required tool 'dms-definitely-absent-tool' is not installed"],
]);

it('requires the full deploy toolchain, Node and npm included, when no tools are named', function () {
    $result = dhpRun([
        'bash', '-c', 'source "$1"; dms_production_host_preflight "$2" 0 DaengtisiaMS 139.190.106.38',
        '_', base_path('scripts/lib/production-host-preflight.sh'), base_path('deploy/production-host.conf'),
    ]);

    // Each tool is reported either way (PASS when present, FATAL when absent),
    // which proves the canonical list was applied on this machine.
    foreach (['git', 'composer', 'node', 'npm', 'php', 'pg_dump', 'runuser', 'nginx', 'systemctl'] as $tool) {
        expect($result['output'])->toContain("tool '{$tool}'");
    }
});

it('refuses to guess when the authority is missing or incomplete', function () {
    $missing = dhpPreflight('0', 'DaengtisiaMS', '139.190.106.38', ['bash'], '/nonexistent/production-host.conf');
    expect($missing['exit'])->toBe(1)->and($missing['output'])->toContain('refusing to guess');

    $partial = tempArtifactFile('dhp-authority-');
    file_put_contents($partial, "DMS_PRODUCTION_HOSTNAME=DaengtisiaMS\n");
    $incomplete = dhpPreflight('0', 'DaengtisiaMS', '139.190.106.38', ['bash'], $partial);
    expect($incomplete['exit'])->toBe(1)->and($incomplete['output'])->toContain('refusing to guess');
});

it('takes every observed value as a parameter, so no environment variable can talk it past the check', function () {
    $lib = (string) file_get_contents(base_path('scripts/lib/production-host-preflight.sh'));
    $code = implode("\n", array_filter(
        preg_split('/\R/', $lib) ?: [],
        fn (string $line): bool => ! str_starts_with(ltrim($line), '#'),
    ));

    foreach (['$EUID', '$UID', '$HOSTNAME', '${EUID', '${UID', '${HOSTNAME', 'DMS_PREFLIGHT_', 'getenv', 'export '] as $forbidden) {
        expect(str_contains($code, $forbidden))->toBeFalse("the preflight must not read '{$forbidden}'");
    }

    // Overriding the environment of an otherwise-wrong run changes nothing.
    $result = dhpRun([
        'bash', '-c', 'source "$1"; dms_production_host_preflight "$2" 1002 srv1730088 145.79.13.224 bash',
        '_', base_path('scripts/lib/production-host-preflight.sh'), base_path('deploy/production-host.conf'),
    ], null, ['HOSTNAME' => 'DaengtisiaMS', 'DMS_PRODUCTION_HOSTNAME' => 'srv1730088']);
    expect($result['exit'])->toBe(1);
});

it('runs the preflight in the deploy and the rollback before the secret file, the backup and the checkout', function (string $script, string $backupMarker, string $checkoutMarker) {
    $call = dhpLinesOf($script, 'dms_production_host_preflight ');
    $secret = dhpLinesOf($script, 'harden-secret-permissions.sh" apply');
    $backup = dhpLinesOf($script, $backupMarker);
    $checkout = dhpLinesOf($script, $checkoutMarker);
    $identity = dhpLinesOf($script, 'RUNTIME_IDENTITY_FILE=');

    expect($call)->toHaveCount(1, "{$script} must call the preflight exactly once")
        ->and($backup)->not->toBe([])
        ->and($checkout)->not->toBe([]);

    expect($call[0])->toBeLessThan(min($backup))
        ->and($call[0])->toBeLessThan(min($checkout));
    if ($secret !== []) {
        expect($call[0])->toBeLessThan(min($secret));
    }
    if ($identity !== []) {
        expect($call[0])->toBeLessThan(min($identity));
    }

    $lines = preg_split('/\R/', (string) file_get_contents(base_path($script))) ?: [];
    $callLine = trim($lines[$call[0] - 1]);
    $block = implode("\n", array_slice($lines, $call[0] - 1, 6));

    // The result gates the run, observed values come from the host, and the
    // authority comes from the immutable snapshot, never the mutable tree.
    // No tool override is passed, so the library's canonical toolchain applies.
    expect($callLine)->toStartWith('if ! dms_production_host_preflight "${DEPLOY_TOOLS_DIR}/deploy/production-host.conf"')
        ->and($block)->toContain('"$(id -u)" "$(hostname)" "$(hostname -I 2>/dev/null || true)"; then')
        ->and($block)->toContain('exit 2');
})->with([
    'deploy' => ['scripts/deploy-vps.sh', 'dms_write_private_dump', 'git checkout "$BRANCH"'],
    'rollback' => ['scripts/rollback-vps.sh', 'dms_write_private_dump', 'git checkout "$ROLLBACK_TARGET_SHA"'],
]);

it('declares the preflight and its authority as execution-closure members', function () {
    $members = config('deployment_entrypoint.execution_closure.closure_members');

    expect($members)->toContain('scripts/lib/production-host-preflight.sh')
        ->toContain('deploy/production-host.conf');
});

it('reports a status as proof only on the host that recorded it', function (?string $hostLine, string $statusLine, int $exit, string $verdict) {
    $thisHost = trim((string) gethostname());
    $app = dhpRunnerTree($statusLine, $hostLine === null ? null : str_replace('{this}', $thisHost, $hostLine));

    $result = dhpRunner($app, 'status');

    expect($result['exit'])->toBe($exit, $result['output'])
        ->and($result['output'])->toContain($verdict);
})->with([
    'unbound (copied from Hostinger)' => [null, 'exit=0', 4, 'DEPLOY RUNNER STATUS UNBOUND'],
    'recorded on another host' => ['host=srv1730088', 'exit=0', 4, 'DEPLOY RUNNER STATUS FOREIGN'],
    // DMS-DEV-INFRA-REMEDIATION-2: a host line alone is no longer evidence. It
    // says where, not when, what or which runner; the full six-fact contract is
    // exercised in DeployHardenRunnerEvidenceContractTest.
    'recorded here, host line only, exit=0' => ['host={this}', 'exit=0', 4, 'do NOT treat as GO'],
    'recorded here, host line only, exit=1' => ['host={this}', 'exit=1', 4, 'do NOT treat as GO'],
]);

it('never reports OK for an unbound status even when it says exit=0', function () {
    $app = dhpRunnerTree('exit=0', null);
    $result = dhpRunner($app, 'status');

    expect($result['output'])->not->toContain('DEPLOY RUNNER OK')
        ->and($result['output'])->toContain('NOT evidence of a deploy on');
});

it('binds a fresh run to this host end to end', function () {
    // Rule 229: the runner launches only an approved release, and only the
    // deploy program shipped beside it. This program skips the bootstrap and
    // prints nothing a deploy must print.
    $s = r229Sandbox(['program' => "#!/usr/bin/env bash\necho payload-ran\nexit 0\n"]);

    $start = r229Runner($s, ['start', '--release-id', $s['release'], '--approved-sha', $s['sha']]);
    expect($start['exit'])->toBe(0, $start['output']);
    preg_match('/deploy_id=(\d{8}-\d{6})/', $start['output'], $m);
    expect(r229WaitForStatus($s['app'], $m[1]))->toBe('exit=0');

    // The record now carries the whole evidence contract, host first.
    $host = (string) file_get_contents("{$s['app']}/storage/logs/deploy-runner/latest.host");
    expect($host)->toStartWith('host='.trim((string) gethostname())."\n")
        ->and($host)->toContain('runner_contract=3')
        ->and($host)->toContain('result_exit=0');

    // A run proves nothing when its own log shows no approval, no production
    // preflight and no DEPLOY OK. The positive path is in the contract suite.
    $result = r229Runner($s, ['status']);
    expect($result['exit'])->toBe(4, $result['output'])
        ->and($result['output'])->toContain('DEPLOY RUNNER STATUS UNVERIFIED')
        ->and($result['output'])->not->toContain('DEPLOY RUNNER OK');
});

it('never starts the queue worker from the provisioner', function () {
    $prov = (string) file_get_contents(base_path('scripts/provision-runtime-identity.sh'));
    $code = implode("\n", array_filter(
        preg_split('/\R/', $prov) ?: [],
        fn (string $line): bool => ! str_starts_with(ltrim($line), '#'),
    ));

    // `restart` starts a stopped unit; the worker's running state is an owner
    // decision (off on Biznet, reopening condition C5).
    expect($code)->not->toMatch('/systemctl\s+(restart|start|enable|reenable)\s+"?\$\{?DMS_QUEUE_SERVICE/')
        ->and(substr_count($code, 'systemctl try-restart "$DMS_QUEUE_SERVICE"'))->toBe(2);

    foreach (['enable --now', 'systemctl start '] as $forbidden) {
        expect(str_contains($code, $forbidden))->toBeFalse("the provisioner must not run '{$forbidden}'");
    }
});
