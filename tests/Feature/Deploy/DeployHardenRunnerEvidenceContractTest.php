<?php

use App\Support\Deploy\DeploymentEntrypointScanner;
use Symfony\Component\Process\Process;

uses()->group('Deploy', 'Security', 'ReleaseSafety');

/**
 * DMS-DEV-INFRA-REMEDIATION-2 — a deploy is proven by evidence, never by a bare
 * `exit=0`.
 *
 * The 2026-11-03 migration copied the frozen Hostinger host's
 * storage/logs/deploy-runner/ to Biznet, so Biznet holds Hostinger's
 * `latest.status: exit=0`. Worse, Hostinger's last deploy pinned 89711151 — the
 * very commit the copied Biznet checkout is at — so even "the pinned target is
 * HEAD" holds for the copied evidence. A status therefore counts only when six
 * facts agree: production host identity, execution timestamps, deployment
 * identifier, target commit, runner version and the actual execution result.
 *
 * The owner chose B-R2 Option A for the first Biznet deploy: quarantine the
 * stale evidence (move, never delete), then prove the one deploy launched by
 * the pre-binding runner with `verify-transition`.
 *
 * Every observed value is a parameter of scripts/lib/deploy-runner-evidence.sh,
 * so nothing here needs root, a server or a real deploy.
 */
const DRE_HOST = 'DaengtisiaMS';
const DRE_FROZEN = 'srv1730088';
const DRE_NOW = 1793000000; // 2026-10-26T... fixed clock for the verifier
const DRE_ID = '20261104-020000';

/** @return array{exit: int, output: string} */
function dreRun(array $command, array $env = [], ?string $cwd = null): array
{
    $process = new Process($command, $cwd, $env);
    $process->run();

    return [
        'exit' => (int) $process->getExitCode(),
        'output' => $process->getOutput().$process->getErrorOutput(),
    ];
}

function dreIso(int $epoch): string
{
    return gmdate('Y-m-d\TH:i:s\Z', $epoch);
}

/** A temporary checkout (git, one commit) with an empty runner directory. */
function dreTree(): array
{
    $app = tempArtifactDir('dre-app-');
    $runDir = "{$app}/storage/logs/deploy-runner";
    mkdir($runDir, 0o700, true);
    foreach ([['init', '-q'], ['-c', 'user.email=dre@test', '-c', 'user.name=dre', 'commit', '-q', '--allow-empty', '-m', 'dre']] as $args) {
        $r = dreRun(array_merge(['git', '-C', $app], $args));
        expect($r['exit'])->toBe(0, $r['output']);
    }
    $sha = trim(dreRun(['git', '-C', $app, 'rev-parse', 'HEAD'])['output']);
    dreApproved($sha);
    dreApprovalBlob(dreWriteApprovalBlob($app, 'dms-fixture-release', $sha));

    return [$app, $runDir, $sha];
}

/**
 * Rule 229: the commit the current test's run was approved for — the commit of
 * the tree dreTree() just built, unless a test says otherwise.
 */
function dreApproved(?string $set = null): string
{
    static $approved = '';
    if ($set !== null) {
        $approved = $set;
    }

    return $approved;
}

/** The approval blob (a real git object in the test tree) the current run cites. */
function dreApprovalBlob(?string $set = null): string
{
    static $blob = '';
    if ($set !== null) {
        $blob = $set;
    }

    return $blob;
}

/** Store an approval record approving $release at $sha as a git object; returns its id. */
function dreWriteApprovalBlob(string $app, string $release, string $sha): string
{
    $process = new Process(['git', '-C', $app, 'hash-object', '-w', '--stdin']);
    $process->setInput("DMS_APPROVAL_CONTRACT=1\nDMS_APPROVAL_RELEASE_ID={$release}\nDMS_APPROVAL_TARGET_SHA={$sha}\n");
    $process->mustRun();

    return trim($process->getOutput());
}

const DRE_APPROVAL_TIP = 'cccccccccccccccccccccccccccccccccccccccc';
const DRE_APPROVAL_PATH = 'deploy/release-approvals/dms-fixture-release.approval';

/** A complete, valid contract-3 binding record (host-bound and approval-bound). */
function dreRecord(string $id = DRE_ID, array $overrides = []): array
{
    return array_merge([
        'host' => DRE_HOST,
        'deploy_id' => $id,
        'started_at' => dreIso(DRE_NOW - 600),
        'runner_contract' => '3',
        'runner_sha256' => str_repeat('ab', 32),
        'program' => 'scripts/deploy-vps.sh',
        'release_id' => 'dms-fixture-release',
        'approved_sha' => dreApproved(),
        'approval_record' => DRE_APPROVAL_TIP.':'.DRE_APPROVAL_PATH,
        'approval_blob' => dreApprovalBlob(),
        'finished_at' => dreIso(DRE_NOW - 300),
        'result_exit' => '0',
    ], $overrides);
}

/** The lines a successful deploy writes into its own log. */
function dreSuccessLog(string $sha, string $host = DRE_HOST, ?string $approved = null): array
{
    $approved ??= dreApproved();

    return [
        'RELEASE APPROVAL: GO',
        "DEPLOY_APPROVED_SHA={$approved}",
        'DEPLOY_APPROVAL_RECORD='.DRE_APPROVAL_TIP.':'.DRE_APPROVAL_PATH.' blob='.dreApprovalBlob(),
        '== Production host preflight (before any mutation) ==',
        "PASS: hostname is the production host '{$host}'",
        'PRODUCTION HOST PREFLIGHT: GO',
        "DEPLOY_TARGET_PINNED={$sha}",
        "DEPLOY_HEAD_TARGET_MATCH=YES ({$sha})",
        'DEPLOY OK: 20261104-020001',
    ];
}

/** Write one run. `null` leaves that file out; a record value of null drops that key. */
function dreWriteRun(string $runDir, string $id, ?string $status, ?array $record, ?array $log): void
{
    if ($status !== null) {
        file_put_contents("{$runDir}/deploy-{$id}.status", $status);
    }
    if ($record !== null) {
        $lines = [];
        foreach ($record as $key => $value) {
            if ($value !== null) {
                $lines[] = "{$key}={$value}";
            }
        }
        file_put_contents("{$runDir}/deploy-{$id}.host", implode("\n", $lines)."\n");
    }
    if ($log !== null) {
        file_put_contents("{$runDir}/deploy-{$id}.log", implode("\n", $log)."\n");
    }
}

function dreLib(string $function, array $args): array
{
    return dreRun(array_merge(
        ['bash', '-c', 'source "$1"; shift; "$@"', '_', base_path('scripts/lib/deploy-runner-evidence.sh'), $function],
        $args,
    ));
}

function dreVerify(string $runDir, string $app, string $id = DRE_ID, string $host = DRE_HOST, int $now = DRE_NOW): array
{
    return dreLib('dms_runner_verify_run', [$runDir, $id, $host, base_path('deploy/production-host.conf'), $app, (string) $now]);
}

function dreVerifyTransition(string $runDir, string $app, string $expected, string $id = DRE_ID, string $host = DRE_HOST, int $now = DRE_NOW): array
{
    return dreLib('dms_runner_verify_transition', [$runDir, $id, $expected, $host, base_path('deploy/production-host.conf'), $app, (string) $now]);
}

function dreQuarantine(string $runDir, bool $apply, string $host = DRE_HOST, int $at = DRE_NOW - 3600): array
{
    return dreLib('dms_runner_quarantine', [$runDir, $host, dreIso($at), $apply ? '1' : '0']);
}

/** A completed quarantine marker, as dms_runner_quarantine writes it. */
function dreQuarantineMarker(string $runDir, int $at, string $host = DRE_HOST): void
{
    $dir = "{$runDir}/quarantine/pre-binding-".gmdate('Ymd\THis\Z', $at);
    mkdir($dir, 0o700, true);
    file_put_contents("{$dir}/QUARANTINE.complete", 'quarantined_at='.dreIso($at)."\nquarantined_on={$host}\nevidence_contract=2\nentries=4\n");
}

// ── Bound runs: the six facts ───────────────────────────────────────────────

it('accepts a run only when host, time, identifier, target, runner and result all agree', function () {
    [$app, $runDir, $sha] = dreTree();
    dreWriteRun($runDir, DRE_ID, "exit=0\n", dreRecord(), dreSuccessLog($sha));

    $result = dreVerify($runDir, $app);

    expect($result['exit'])->toBe(0, $result['output'])
        ->and($result['output'])->toContain('deploy_id='.DRE_ID)
        ->and($result['output'])->toContain('recorded on '.DRE_HOST.' (declared production host)')
        ->and($result['output'])->toContain('runner_contract=3 runner_sha256='.str_repeat('ab', 32))
        ->and($result['output'])->toContain("release_id=dms-fixture-release approved_sha={$sha}")
        ->and($result['output'])->toContain("DEPLOY_APPROVED_TARGET_MATCH=YES ({$sha})")
        ->and($result['output'])->toContain('started_at='.dreIso(DRE_NOW - 600).' finished_at='.dreIso(DRE_NOW - 300))
        ->and($result['output'])->toContain("target_commit={$sha} (checkout HEAD matches)")
        ->and($result['output'])->toContain('DEPLOY RUNNER OK');
});

it('refuses a run whose evidence does not prove it', function (callable $mutate, string $verdict, string $host = DRE_HOST) {
    [$app, $runDir, $sha] = dreTree();
    $run = ['status' => "exit=0\n", 'record' => dreRecord(), 'log' => dreSuccessLog($sha), 'id' => DRE_ID];
    $run = $mutate($run, $sha, $app);
    dreWriteRun($runDir, DRE_ID, $run['status'], $run['record'], $run['log']);

    $result = dreVerify($runDir, $app, $run['id'], $host);

    expect($result['exit'])->toBe(4, $result['output'])
        ->and($result['output'])->toContain("DEPLOY RUNNER STATUS {$verdict}")
        ->and($result['output'])->not->toContain('DEPLOY RUNNER OK');
})->with([
    // 1. Production host identity.
    'no record (copied from Hostinger)' => [fn (array $r) => ['record' => null] + $r, 'UNBOUND'],
    'recorded on another host' => [fn (array $r) => ['record' => dreRecord(DRE_ID, ['host' => 'some-other-host'])] + $r, 'FOREIGN'],
    'recorded on the frozen host' => [fn (array $r) => ['record' => dreRecord(DRE_ID, ['host' => DRE_FROZEN])] + $r, 'FROZEN', DRE_FROZEN],
    'verified on a host that is not production' => [fn (array $r) => ['record' => dreRecord(DRE_ID, ['host' => 'staging-box'])] + $r, 'NOT-PRODUCTION', 'staging-box'],
    // 5. Runner version.
    'pre-binding record (host only)' => [fn (array $r) => ['record' => ['host' => DRE_HOST]] + $r, 'INCOMPATIBLE'],
    'older evidence contract' => [fn (array $r) => ['record' => dreRecord(DRE_ID, ['runner_contract' => '1'])] + $r, 'INCOMPATIBLE'],
    'host-bound but not approval-bound (contract 2)' => [fn (array $r) => ['record' => dreRecord(DRE_ID, ['runner_contract' => '2'])] + $r, 'INCOMPATIBLE'],
    // 7. Approved release (rule 229).
    'record names no approved commit' => [fn (array $r) => ['record' => dreRecord(DRE_ID, ['approved_sha' => null])] + $r, 'INCOMPLETE'],
    'record names no release' => [fn (array $r) => ['record' => dreRecord(DRE_ID, ['release_id' => null])] + $r, 'INCOMPLETE'],
    'record approved commit is abbreviated' => [fn (array $r, string $sha) => ['record' => dreRecord(DRE_ID, ['approved_sha' => substr($sha, 0, 12)])] + $r, 'INCOMPLETE'],
    'log never shows the approval' => [fn (array $r, string $sha) => ['log' => array_values(array_diff(dreSuccessLog($sha), ['RELEASE APPROVAL: GO']))] + $r, 'UNVERIFIED'],
    'log names no approved commit' => [fn (array $r, string $sha) => ['log' => array_values(array_diff(dreSuccessLog($sha), ["DEPLOY_APPROVED_SHA={$sha}"]))] + $r, 'UNVERIFIED'],
    'log names two approved commits' => [fn (array $r, string $sha) => ['log' => array_merge(dreSuccessLog($sha), ['DEPLOY_APPROVED_SHA='.str_repeat('2', 40)])] + $r, 'UNVERIFIED'],
    'log approved another commit than the record' => [fn (array $r, string $sha) => ['log' => dreSuccessLog($sha, DRE_HOST, str_repeat('3', 40))] + $r, 'MISMATCHED'],
    'record names no approval' => [fn (array $r) => ['record' => dreRecord(DRE_ID, ['approval_record' => null])] + $r, 'INCOMPLETE'],
    'record names another release\'s approval' => [fn (array $r) => ['record' => dreRecord(DRE_ID, ['approval_record' => DRE_APPROVAL_TIP.':deploy/release-approvals/dms-other-release.approval'])] + $r, 'INCOMPLETE'],
    'record names no approval blob' => [fn (array $r) => ['record' => dreRecord(DRE_ID, ['approval_blob' => null])] + $r, 'INCOMPLETE'],
    'log verified another approval file' => [fn (array $r, string $sha) => ['log' => str_replace(DRE_APPROVAL_PATH, 'deploy/release-approvals/dms-other-release.approval', dreSuccessLog($sha))] + $r, 'MISMATCHED'],
    'log names no approval record' => [fn (array $r, string $sha) => ['log' => array_values(array_filter(dreSuccessLog($sha), fn ($l) => ! str_starts_with($l, 'DEPLOY_APPROVAL_RECORD=')))] + $r, 'UNVERIFIED'],
    'log cites an approval git never held' => [fn (array $r, string $sha) => ['log' => str_replace(dreApprovalBlob(), str_repeat('6', 40), dreSuccessLog($sha))] + $r, 'UNVERIFIED'],
    'record cites an approval git never held' => [fn (array $r) => ['record' => dreRecord(DRE_ID, ['approval_blob' => str_repeat('5', 40)])] + $r, 'UNVERIFIED'],
    'the approval in git approves another commit' => [function (array $r, string $sha, string $app) {
        $other = dreWriteApprovalBlob($app, 'dms-fixture-release', str_repeat('7', 40));

        return ['record' => dreRecord(DRE_ID, ['approval_blob' => $other]), 'log' => str_replace(dreApprovalBlob(), $other, dreSuccessLog($sha))] + $r;
    }, 'MISMATCHED'],
    'the approval in git approves another release' => [function (array $r, string $sha, string $app) {
        $other = dreWriteApprovalBlob($app, 'dms-other-release', $sha);

        return ['record' => dreRecord(DRE_ID, ['approval_blob' => $other])] + $r;
    }, 'MISMATCHED'],
    'run pinned a commit other than the approved one' => [fn (array $r, string $sha) => [
        'record' => dreRecord(DRE_ID, ['approved_sha' => str_repeat('4', 40)]),
        'log' => dreSuccessLog($sha, DRE_HOST, str_repeat('4', 40)),
    ] + $r, 'MISMATCHED'],
    'runner program not identified' => [fn (array $r) => ['record' => dreRecord(DRE_ID, ['runner_sha256' => null])] + $r, 'INCOMPLETE'],
    'runner hash is not a SHA-256' => [fn (array $r) => ['record' => dreRecord(DRE_ID, ['runner_sha256' => 'abc'])] + $r, 'INCOMPLETE'],
    // 3. Deployment identifier.
    'record names another deploy' => [fn (array $r) => ['record' => dreRecord('20261103-010101')] + $r, 'MISMATCHED'],
    'identifier is malformed' => [fn (array $r) => ['id' => '../evil'] + $r, 'MALFORMED'],
    // 2. Execution timestamp.
    'no start time' => [fn (array $r) => ['record' => dreRecord(DRE_ID, ['started_at' => null])] + $r, 'INCOMPLETE'],
    'start time not canonical UTC' => [fn (array $r) => ['record' => dreRecord(DRE_ID, ['started_at' => '2026-11-04 02:00:00'])] + $r, 'INCOMPLETE'],
    'impossible start date' => [fn (array $r) => ['record' => dreRecord(DRE_ID, ['started_at' => '2026-02-30T02:00:00Z'])] + $r, 'INCOMPLETE'],
    'no finish (interrupted)' => [fn (array $r) => ['record' => dreRecord(DRE_ID, ['finished_at' => null, 'result_exit' => null])] + $r, 'INCOMPLETE'],
    'finish before start' => [fn (array $r) => ['record' => dreRecord(DRE_ID, ['finished_at' => dreIso(DRE_NOW - 900)])] + $r, 'INCOMPLETE'],
    'finish in the future' => [fn (array $r) => ['record' => dreRecord(DRE_ID, ['finished_at' => dreIso(DRE_NOW + 3600)])] + $r, 'INCOMPLETE'],
    // 6. Actual execution result.
    'record and status disagree' => [fn (array $r) => ['record' => dreRecord(DRE_ID, ['result_exit' => '1'])] + $r, 'MISMATCHED'],
    'status has two lines' => [fn (array $r) => ['status' => "exit=0\nexit=1\n"] + $r, 'MALFORMED'],
    'status is not exit=N' => [fn (array $r) => ['status' => "exit=ok\n"] + $r, 'MALFORMED'],
    'own log missing' => [fn (array $r) => ['log' => null] + $r, 'UNVERIFIED'],
    'log never proved the production host' => [fn (array $r, string $sha) => ['log' => array_values(array_diff(dreSuccessLog($sha), ['PRODUCTION HOST PREFLIGHT: GO']))] + $r, 'UNVERIFIED'],
    'log never said DEPLOY OK' => [fn (array $r, string $sha) => ['log' => array_values(array_diff(dreSuccessLog($sha), ['DEPLOY OK: 20261104-020001']))] + $r, 'UNVERIFIED'],
    // 4. Target commit.
    'log pins two targets' => [fn (array $r, string $sha) => ['log' => array_merge(dreSuccessLog($sha), ['DEPLOY_TARGET_PINNED='.str_repeat('1', 40)])] + $r, 'UNVERIFIED'],
    'log never confirmed HEAD == target' => [fn (array $r, string $sha) => ['log' => array_values(array_diff(dreSuccessLog($sha), ["DEPLOY_HEAD_TARGET_MATCH=YES ({$sha})"]))] + $r, 'UNVERIFIED'],
    'pinned target is not a full SHA' => [fn (array $r) => ['log' => dreSuccessLog('89711151')] + $r, 'UNVERIFIED'],
    'checkout has moved since the deploy' => [function (array $r, string $sha) {
        $other = str_repeat('f', 40);

        // Approved, pinned and deployed $other; the checkout is now elsewhere.
        return ['log' => dreSuccessLog($other, DRE_HOST, $other), 'record' => dreRecord(DRE_ID, ['approved_sha' => $other])] + $r;
    }, 'STALE'],
]);

it('reports a proven failure as FAILED, never OK', function () {
    [$app, $runDir] = dreTree();
    dreWriteRun($runDir, DRE_ID, "exit=1\n", dreRecord(DRE_ID, ['result_exit' => '1']), ['FATAL: something broke']);

    $result = dreVerify($runDir, $app);

    expect($result['exit'])->toBe(1, $result['output'])
        ->and($result['output'])->toContain('DEPLOY RUNNER FAILED (exit=1)')
        ->and($result['output'])->not->toContain('DEPLOY RUNNER OK');
});

it('reports pending when the run has no status yet', function () {
    [$app, $runDir] = dreTree();
    dreWriteRun($runDir, DRE_ID, null, dreRecord(DRE_ID, ['finished_at' => null, 'result_exit' => null]), ['still running']);

    expect(dreVerify($runDir, $app)['exit'])->toBe(3);
});

it('refuses the exact evidence the migration copied, even though its target is HEAD', function () {
    // Hostinger's last deploy pinned the commit the copied Biznet checkout is
    // at, so the copied log passes "pinned target == HEAD". It is still not
    // evidence: it carries no binding record and no production preflight.
    [$app, $runDir, $sha] = dreTree();
    $hostingerLog = ["DEPLOY_TARGET_PINNED={$sha}", "DEPLOY_HEAD_TARGET_MATCH=YES ({$sha})", 'DEPLOY OK: 20261102-061351'];
    dreWriteRun($runDir, '20261102-061350', "exit=0\n", null, $hostingerLog);
    symlink('deploy-20261102-061350.status', "{$runDir}/latest.status");

    $latest = dreLib('dms_runner_verify_latest', [$runDir, DRE_HOST, base_path('deploy/production-host.conf'), $app, (string) DRE_NOW]);
    expect($latest['exit'])->toBe(4)->and($latest['output'])->toContain('DEPLOY RUNNER STATUS UNBOUND');

    // A forged record naming the production host does not rescue it either.
    dreWriteRun($runDir, '20261102-061350', null, dreRecord('20261102-061350'), null);
    $forged = dreVerify($runDir, $app, '20261102-061350');
    expect($forged['exit'])->toBe(4)
        ->and($forged['output'])->toContain('never proved this was the production host')
        ->and($forged['output'])->not->toContain('DEPLOY RUNNER OK');
});

it('resolves latest.status only to a run inside the runner directory', function (?string $link, int $exit, string $needle) {
    [$app, $runDir, $sha] = dreTree();
    dreWriteRun($runDir, DRE_ID, "exit=0\n", dreRecord(), dreSuccessLog($sha));
    if ($link === 'plain-file') {
        file_put_contents("{$runDir}/latest.status", "exit=0\n");
    } elseif ($link !== null) {
        symlink($link, "{$runDir}/latest.status");
    }

    $result = dreLib('dms_runner_verify_latest', [$runDir, DRE_HOST, base_path('deploy/production-host.conf'), $app, (string) DRE_NOW]);

    expect($result['exit'])->toBe($exit, $result['output'])->and($result['output'])->toContain($needle);
})->with([
    'points at the bound run' => ['deploy-'.DRE_ID.'.status', 0, 'DEPLOY RUNNER OK'],
    'absent' => [null, 3, 'status pending'],
    'a plain file, not a runner link' => ['plain-file', 4, 'DEPLOY RUNNER STATUS MALFORMED'],
    'points outside the directory' => ['../deploy-'.DRE_ID.'.status', 4, 'DEPLOY RUNNER STATUS MALFORMED'],
    'points at something that is not a run' => ['latest.log', 4, 'DEPLOY RUNNER STATUS MALFORMED'],
]);

// ── The runner end to end ───────────────────────────────────────────────────

/** A temporary checkout whose authority names THIS machine, for the real runner. */
function dreRunnerTree(): array
{
    [$app, $runDir, $sha] = dreTree();
    mkdir("{$app}/deploy", 0o700, true);
    file_put_contents("{$app}/deploy/production-host.conf", 'DMS_PRODUCTION_HOSTNAME='.trim((string) gethostname())."\nDMS_FROZEN_LEGACY_HOSTNAMES=\"".DRE_FROZEN."\"\n");

    return [$app, $runDir, $sha];
}

function dreRunner(string $app, array $args, array $env = []): array
{
    return dreRun(array_merge(['bash', base_path('scripts/deploy-vps-runner.sh')], $args), array_merge(['APP_DIR' => $app], $env));
}

function dreWaitForStatus(string $runDir, string $id): void
{
    $status = "{$runDir}/deploy-{$id}.status";
    $deadline = microtime(true) + 15;
    while (microtime(true) < $deadline && ! (is_file($status) && filesize($status) > 0)) {
        usleep(100_000);
    }
    expect(is_file($status))->toBeTrue("the runner never wrote {$status}");
}

it('binds a real run end to end and proves it by identifier', function () {
    // Rule 229: a run starts only for an approved release; the sandbox's deploy
    // program hands over to the real bootstrap (tests/Pest.php r229Sandbox).
    $s = r229Sandbox();

    $start = r229Runner($s, ['start', '--release-id', $s['release'], '--approved-sha', $s['sha']]);
    expect($start['exit'])->toBe(0, $start['output']);
    expect(preg_match('/deploy_id=(\d{8}-\d{6})/', $start['output'], $m))->toBe(1);
    $id = $m[1];
    expect(r229WaitForStatus($s['app'], $id))->toBe('exit=0');

    $record = (string) file_get_contents("{$s['app']}/storage/logs/deploy-runner/deploy-{$id}.host");
    foreach (['host='.trim((string) gethostname()), "deploy_id={$id}", 'runner_contract=3', 'started_at=', 'finished_at=', 'result_exit=0', "approved_sha={$s['sha']}"] as $line) {
        expect($record)->toContain($line);
    }
    expect($record)->toMatch('/^runner_sha256=[0-9a-f]{64}$/m')
        ->and($record)->toContain('runner_sha256='.hash_file('sha256', base_path('scripts/deploy-vps-runner.sh')));

    foreach ([['status'], ['verify', $id]] as $args) {
        $result = r229Runner($s, $args);
        expect($result['exit'])->toBe(0, $result['output'])
            ->and($result['output'])->toContain("deploy_id={$id}")
            ->and($result['output'])->toContain("target_commit={$s['sha']}")
            ->and($result['output'])->toContain('DEPLOY RUNNER OK');
    }
});

it('reports a real failing run as FAILED', function () {
    $s = r229Sandbox(['program' => "#!/usr/bin/env bash\necho 'FATAL: refused'\nexit 7\n"]);

    $start = r229Runner($s, ['start', '--release-id', $s['release'], '--approved-sha', $s['sha']]);
    preg_match('/deploy_id=(\d{8}-\d{6})/', $start['output'], $m);
    expect(r229WaitForStatus($s['app'], $m[1]))->toBe('exit=7');

    $result = r229Runner($s, ['verify', $m[1]]);
    expect($result['exit'])->toBe(1, $result['output'])
        ->and($result['output'])->toContain('DEPLOY RUNNER FAILED (exit=7)');
});

it('cannot redirect a run outside the runner directory', function () {
    $runner = (string) file_get_contents(base_path('scripts/deploy-vps-runner.sh'));

    // The verifier finds a run by identifier, so the run's files are never
    // overridable from the environment.
    expect($runner)->not->toContain('${DEPLOY_STATUS_FILE:-')
        ->and($runner)->not->toContain('${DEPLOY_LOG_FILE:-')
        ->and($runner)->toContain('DEPLOY_STATUS_FILE="${RUN_DIR}/deploy-${STAMP}.status"')
        ->and($runner)->toContain('DEPLOY_LOG_FILE="${RUN_DIR}/deploy-${STAMP}.log"');
});

it('requires a deploy identifier for verify and two arguments for verify-transition', function () {
    [$app] = dreRunnerTree();

    expect(dreRunner($app, ['verify'])['exit'])->toBe(2)
        ->and(dreRunner($app, ['verify-transition', DRE_ID])['exit'])->toBe(2)
        ->and(dreRunner($app, ['no-such-action'])['exit'])->toBe(2);
});

// ── Option A: the one transition deploy ─────────────────────────────────────

it('verifies the first Biznet deploy launched by the pre-binding runner after the quarantine', function () {
    [$app, $runDir, $sha] = dreTree();
    dreQuarantineMarker($runDir, DRE_NOW - 3600);
    dreWriteRun($runDir, DRE_ID, "exit=0\n", null, dreSuccessLog($sha));
    touch("{$runDir}/deploy-".DRE_ID.'.status', DRE_NOW - 60);

    $result = dreVerifyTransition($runDir, $app, $sha);

    expect($result['exit'])->toBe(0, $result['output'])
        ->and($result['output'])->toContain('runner_contract=1 (pre-binding runner')
        ->and($result['output'])->toContain("target_commit={$sha}")
        ->and($result['output'])->toContain('TRANSITION DEPLOY VERIFIED');

    // The same run is still UNBOUND for the ordinary verifier — the transition
    // check never turns it into bound evidence.
    expect(dreVerify($runDir, $app)['output'])->toContain('DEPLOY RUNNER STATUS UNBOUND');
});

it('refuses a transition claim that cannot be proven', function (callable $arrange, string $needle, int $exit = 4, ?string $host = null) {
    [$app, $runDir, $sha] = dreTree();
    $expected = $arrange($runDir, $sha) ?? $sha;

    $result = dreVerifyTransition($runDir, $app, $expected, DRE_ID, $host ?? DRE_HOST);

    expect($result['exit'])->toBe($exit, $result['output'])
        ->and($result['output'])->toContain($needle)
        ->and($result['output'])->not->toContain('TRANSITION DEPLOY VERIFIED');
})->with([
    'never quarantined' => [function (string $runDir, string $sha) {
        dreWriteRun($runDir, DRE_ID, "exit=0\n", null, dreSuccessLog($sha));
        touch("{$runDir}/deploy-".DRE_ID.'.status', DRE_NOW - 60);
    }, 'no completed quarantine'],
    'finished before the quarantine (the copied run)' => [function (string $runDir, string $sha) {
        dreQuarantineMarker($runDir, DRE_NOW - 60);
        dreWriteRun($runDir, DRE_ID, "exit=0\n", null, dreSuccessLog($sha));
        touch("{$runDir}/deploy-".DRE_ID.'.status', DRE_NOW - 3600);
    }, 'DEPLOY RUNNER STATUS STALE'],
    'quarantine recorded on another host' => [function (string $runDir, string $sha) {
        dreQuarantineMarker($runDir, DRE_NOW - 3600, 'some-other-host');
        dreWriteRun($runDir, DRE_ID, "exit=0\n", null, dreSuccessLog($sha));
        touch("{$runDir}/deploy-".DRE_ID.'.status', DRE_NOW - 60);
    }, 'no completed quarantine'],
    'log copied from Hostinger (no production preflight)' => [function (string $runDir, string $sha) {
        dreQuarantineMarker($runDir, DRE_NOW - 3600);
        dreWriteRun($runDir, DRE_ID, "exit=0\n", null, ["DEPLOY_TARGET_PINNED={$sha}", "DEPLOY_HEAD_TARGET_MATCH=YES ({$sha})", 'DEPLOY OK: x']);
        touch("{$runDir}/deploy-".DRE_ID.'.status', DRE_NOW - 60);
    }, 'does not show the production preflight passing'],
    'deployed a different target' => [function (string $runDir, string $sha) {
        dreQuarantineMarker($runDir, DRE_NOW - 3600);
        dreWriteRun($runDir, DRE_ID, "exit=0\n", null, dreSuccessLog($sha));
        touch("{$runDir}/deploy-".DRE_ID.'.status', DRE_NOW - 60);

        return str_repeat('e', 40);
    }, 'DEPLOY RUNNER STATUS MISMATCHED'],
    'the run is bound (use verify)' => [function (string $runDir, string $sha) {
        dreQuarantineMarker($runDir, DRE_NOW - 3600);
        dreWriteRun($runDir, DRE_ID, "exit=0\n", dreRecord(), dreSuccessLog($sha));
        touch("{$runDir}/deploy-".DRE_ID.'.status', DRE_NOW - 60);
    }, 'DEPLOY RUNNER STATUS NOT-TRANSITION'],
    'expected target is not a full SHA' => [function (string $runDir, string $sha) {
        return '89711151';
    }, 'DEPLOY RUNNER STATUS MALFORMED'],
    'claimed on the frozen host' => [function (string $runDir, string $sha) {
        dreQuarantineMarker($runDir, DRE_NOW - 3600, DRE_FROZEN);
        dreWriteRun($runDir, DRE_ID, "exit=0\n", null, dreSuccessLog($sha, DRE_FROZEN));
        touch("{$runDir}/deploy-".DRE_ID.'.status', DRE_NOW - 60);
    }, 'DEPLOY RUNNER STATUS FROZEN', 4, DRE_FROZEN],
    'the transition deploy failed' => [function (string $runDir, string $sha) {
        dreQuarantineMarker($runDir, DRE_NOW - 3600);
        dreWriteRun($runDir, DRE_ID, "exit=1\n", null, ['FATAL: x']);
        touch("{$runDir}/deploy-".DRE_ID.'.status', DRE_NOW - 60);
    }, 'DEPLOY RUNNER FAILED (exit=1)', 1],
]);

// ── Option A: the quarantine ────────────────────────────────────────────────

/** The storage tree as the migration left it on Biznet, plus one bound run. */
function dreMigratedTree(): array
{
    [$app, $runDir, $sha] = dreTree();
    dreWriteRun($runDir, '20261102-061350', "exit=0\n", null, ["DEPLOY_TARGET_PINNED={$sha}", 'DEPLOY OK: 20261102-061351']);
    dreWriteRun($runDir, '20260707-045042', "exit=0\n", null, ['old Hostinger deploy']);
    symlink('deploy-20261102-061350.status', "{$runDir}/latest.status");
    symlink('deploy-20261102-061350.log', "{$runDir}/latest.log");
    dreWriteRun($runDir, '20261105-010101', "exit=0\n", dreRecord('20261105-010101'), dreSuccessLog($sha));
    file_put_contents("{$runDir}/operator-notes.txt", "not runner evidence\n");

    return [$app, $runDir, $sha];
}

it('moves every unbound piece of evidence, verified, and deletes nothing', function () {
    [, $runDir] = dreMigratedTree();
    $before = [];
    foreach (['deploy-20261102-061350.status', 'deploy-20261102-061350.log', 'deploy-20260707-045042.status', 'deploy-20260707-045042.log'] as $name) {
        $before[$name] = hash_file('sha256', "{$runDir}/{$name}");
    }

    $result = dreQuarantine($runDir, true);
    expect($result['exit'])->toBe(0, $result['output'])
        ->and($result['output'])->toContain('QUARANTINE COMPLETE: 6 entr(y/ies)');

    $dest = "{$runDir}/quarantine/pre-binding-".gmdate('Ymd\THis\Z', DRE_NOW - 3600);
    foreach ($before as $name => $sum) {
        expect(file_exists("{$runDir}/{$name}"))->toBeFalse("{$name} must leave the top level")
            ->and(hash_file('sha256', "{$dest}/{$name}"))->toBe($sum, "{$name} must be preserved byte for byte");
    }
    expect(readlink("{$dest}/latest.status"))->toBe('deploy-20261102-061350.status')
        ->and(file_exists("{$runDir}/latest.status") || is_link("{$runDir}/latest.status"))->toBeFalse();

    // The bound run and unrelated files stay where they are.
    foreach (['deploy-20261105-010101.status', 'deploy-20261105-010101.host', 'deploy-20261105-010101.log', 'operator-notes.txt'] as $kept) {
        expect(file_exists("{$runDir}/{$kept}"))->toBeTrue("{$kept} must not be quarantined");
    }

    $manifest = (string) file_get_contents("{$dest}/MANIFEST.tsv");
    foreach ($before as $name => $sum) {
        expect($manifest)->toContain("{$name}\tfile\t")->toContain($sum);
    }
    $marker = (string) file_get_contents("{$dest}/QUARANTINE.complete");
    expect($marker)->toContain('quarantined_at='.dreIso(DRE_NOW - 3600))
        ->and($marker)->toContain('quarantined_on='.DRE_HOST)
        ->and($marker)->toContain('entries=6');
});

it('turns the old false OK into pending', function () {
    [$app, $runDir] = dreMigratedTree();
    unlink("{$runDir}/deploy-20261105-010101.status");
    unlink("{$runDir}/deploy-20261105-010101.host");
    unlink("{$runDir}/deploy-20261105-010101.log");

    // What the pre-binding runner on Biznet does today: read latest.status raw.
    $raw = trim((string) file_get_contents("{$runDir}/latest.status"));
    expect($raw)->toBe('exit=0');

    dreQuarantine($runDir, true);

    expect(file_exists("{$runDir}/latest.status"))->toBeFalse();
    $latest = dreLib('dms_runner_verify_latest', [$runDir, DRE_HOST, base_path('deploy/production-host.conf'), $app, (string) DRE_NOW]);
    expect($latest['exit'])->toBe(3)->and($latest['output'])->toContain('status pending');
});

it('plans without moving on a dry run, and is idempotent once applied', function () {
    [, $runDir] = dreMigratedTree();

    $dry = dreQuarantine($runDir, false);
    expect($dry['exit'])->toBe(0)
        ->and($dry['output'])->toContain('QUARANTINE DRY RUN')
        ->and(is_link("{$runDir}/latest.status"))->toBeTrue()
        ->and(is_dir("{$runDir}/quarantine"))->toBeFalse();

    expect(dreQuarantine($runDir, true)['exit'])->toBe(0);
    $again = dreQuarantine($runDir, true, DRE_HOST, DRE_NOW - 1800);
    expect($again['exit'])->toBe(0)
        ->and($again['output'])->toContain('nothing to do')
        ->and(glob("{$runDir}/quarantine/*"))->toHaveCount(1);
});

it('keeps latest links that point at a bound run', function () {
    [, $runDir, $sha] = dreTree();
    dreWriteRun($runDir, DRE_ID, "exit=0\n", dreRecord(), dreSuccessLog($sha));
    symlink('deploy-'.DRE_ID.'.status', "{$runDir}/latest.status");

    $result = dreQuarantine($runDir, true);

    expect($result['output'])->toContain('nothing to do')
        ->and(is_link("{$runDir}/latest.status"))->toBeTrue();
});

it('refuses to quarantine into an existing directory or through a symlink', function () {
    [, $runDir] = dreMigratedTree();
    mkdir("{$runDir}/quarantine/pre-binding-".gmdate('Ymd\THis\Z', DRE_NOW - 3600), 0o700, true);
    $existing = dreQuarantine($runDir, true);
    expect($existing['exit'])->toBe(1)->and($existing['output'])->toContain('already exists')
        ->and(is_link("{$runDir}/latest.status"))->toBeTrue();

    $real = tempArtifactDir('dre-real-');
    $link = tempArtifactDir('dre-link-parent-').'/deploy-runner';
    symlink($real, $link);
    $symlinked = dreQuarantine($link, true);
    expect($symlinked['exit'])->toBe(1)->and($symlinked['output'])->toContain('is a symlink');
});

it('never marks a quarantine complete when a move fails, and such a quarantine proves nothing', function () {
    if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
        $this->markTestSkipped('a read-only directory does not stop root');
    }
    [$app, $runDir, $sha] = dreMigratedTree();
    mkdir("{$runDir}/quarantine", 0o700);
    chmod($runDir, 0o555); // entries cannot leave: every move fails

    try {
        $result = dreQuarantine($runDir, true);
    } finally {
        chmod($runDir, 0o755);
    }

    $dest = "{$runDir}/quarantine/pre-binding-".gmdate('Ymd\THis\Z', DRE_NOW - 3600);
    expect($result['exit'])->toBe(1, $result['output'])
        ->and($result['output'])->toContain('QUARANTINE NOT COMPLETE')
        ->and($result['output'])->not->toContain('QUARANTINE COMPLETE:')
        ->and(file_exists("{$dest}/QUARANTINE.complete"))->toBeFalse()
        // Nothing was lost: the evidence is still where it was.
        ->and(is_link("{$runDir}/latest.status"))->toBeTrue()
        ->and(file_exists("{$runDir}/deploy-20261102-061350.status"))->toBeTrue();

    // An incomplete quarantine cannot unlock the transition verification.
    dreWriteRun($runDir, DRE_ID, "exit=0\n", null, dreSuccessLog($sha));
    touch("{$runDir}/deploy-".DRE_ID.'.status', DRE_NOW - 60);
    expect(dreVerifyTransition($runDir, $app, $sha)['output'])->toContain('no completed quarantine');
});

it('refuses to run the quarantine tool as a normal user and moves nothing', function () {
    if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
        $this->markTestSkipped('the non-root refusal cannot be observed as root');
    }
    [$app, $runDir] = dreMigratedTree();

    $result = dreRun(['bash', base_path('scripts/deploy-runner-quarantine-stale-status.sh'), '--apply'], ['APP_DIR' => $app]);

    expect($result['exit'])->toBe(1, $result['output'])
        ->and($result['output'])->toContain('QUARANTINE REFUSED')
        ->and($result['output'])->toContain('PRODUCTION HOST PREFLIGHT: NOT GO')
        ->and(is_link("{$runDir}/latest.status"))->toBeTrue()
        ->and(is_dir("{$runDir}/quarantine"))->toBeFalse();
    expect(dreRun(['bash', base_path('scripts/deploy-runner-quarantine-stale-status.sh'), '--force'], ['APP_DIR' => $app])['exit'])->toBe(2);
});

it('gates the quarantine on identity, root and the deploy lock before it touches anything', function () {
    $lines = preg_split('/\R/', (string) file_get_contents(base_path('scripts/deploy-runner-quarantine-stale-status.sh'))) ?: [];
    $first = fn (string $needle): int => (int) array_key_first(array_filter($lines, fn (string $l): bool => ! str_starts_with(ltrim($l), '#') && str_contains($l, $needle)));

    $preflight = $first('dms_production_host_preflight "$AUTHORITY"');
    $lock = $first('flock -n 8');
    $move = $first('dms_runner_quarantine "$RUN_DIR"');

    expect($preflight)->toBeGreaterThan(0)
        ->and($preflight)->toBeLessThan($lock)
        ->and($lock)->toBeLessThan($move)
        // The authority comes from the SAME trusted bundle as the tool, never
        // from the mutable checkout it operates on.
        ->and(implode("\n", $lines))->toContain('AUTHORITY="${BUNDLE_DIR}/deploy/production-host.conf"');

    $code = implode("\n", array_filter($lines, fn (string $l): bool => ! str_starts_with(ltrim($l), '#')));
    foreach (['rm ', 'rm -', 'unlink', 'truncate', 'shred', '> "$RUN_DIR'] as $forbidden) {
        expect(str_contains($code, $forbidden))->toBeFalse("the quarantine tool must never run '{$forbidden}'");
    }
});

it('never deletes or rewrites evidence inside the quarantine library', function () {
    $lib = (string) file_get_contents(base_path('scripts/lib/deploy-runner-evidence.sh'));
    $code = implode("\n", array_filter(
        preg_split('/\R/', $lib) ?: [],
        fn (string $line): bool => ! str_starts_with(ltrim($line), '#'),
    ));

    foreach (['rm ', 'rm -', 'unlink', 'truncate', 'shred', 'mv -f', '$HOSTNAME', '${HOSTNAME', 'getenv', 'export '] as $forbidden) {
        expect(str_contains($code, $forbidden))->toBeFalse("the evidence library must not use '{$forbidden}'");
    }
    expect($code)->toContain('mv -n --');
});

// ── B-R1: no runbook treats a bare latest.status as proof ───────────────────

it('never presents raw latest.status content as deploy evidence in a live runbook', function () {
    $offenders = [];
    foreach (glob(base_path('docs/runbooks/*.md')) ?: [] as $file) {
        foreach (preg_split('/\R/', (string) file_get_contents($file)) ?: [] as $n => $line) {
            if (! str_contains($line, 'latest.status')) {
                continue;
            }
            $prohibits = (bool) preg_match('/\bnever\b|\bnot\b/i', $line);
            if (! $prohibits && (str_contains($line, 'cat ') || str_contains($line, 'exit=0'))) {
                $offenders[] = basename($file).':'.($n + 1).': '.trim($line);
            }
        }
    }

    expect($offenders)->toBe([], "a runbook reads latest.status raw as a GO criterion:\n".implode("\n", $offenders));
});

it('makes the evidence-bound verifier the only GO criterion in the timeout runbook', function () {
    $runbook = (string) file_get_contents(base_path('docs/runbooks/vps-deploy-evidence-timeout-recovery-runbook.md'));

    expect($runbook)->toContain('deploy-vps-runner.sh verify <deploy-id>')
        ->and($runbook)->toContain('DEPLOY RUNNER OK')
        ->and($runbook)->toContain('verify-transition')
        ->and($runbook)->toContain('deploy-runner-quarantine-stale-status.sh');
});

// ── Task D: the toolchain must be the version CI builds with ────────────────

function dreVersions(string $node, string $npm, string $php, string $composer): array
{
    return dreRun(['bash', '-c', 'source "$1"; dms_production_toolchain_versions "$2" "$3" "$4" "$5"', '_',
        base_path('scripts/lib/production-host-preflight.sh'), $node, $npm, $php, $composer]);
}

it('accepts exactly the toolchain CI builds with', function () {
    $result = dreVersions('v22.11.0', '10.9.0', '8.3.6', 'Composer version 2.7.1 2024-02-09 15:26:28');

    expect($result['exit'])->toBe(0, $result['output'])
        ->and($result['output'])->toContain('PRODUCTION TOOLCHAIN VERSIONS: GO');
});

it('refuses a toolchain CI never built with', function (string $node, string $npm, string $php, string $composer, string $needle) {
    $result = dreVersions($node, $npm, $php, $composer);

    expect($result['exit'])->toBe(1, $result['output'])
        ->and($result['output'])->toContain($needle)
        ->and($result['output'])->toContain('PRODUCTION TOOLCHAIN VERSIONS: NOT GO');
})->with([
    'Ubuntu 24.04 archive Node 18' => ['v18.19.1', '9.2.0', '8.3.6', 'Composer version 2.7.1 2024-02-09 15:26:28', 'Node.js must be major 22'],
    'Node 20' => ['v20.18.0', '10.8.2', '8.3.6', 'Composer version 2.7.1 2024-02-09 15:26:28', 'Node.js must be major 22'],
    'Node absent (Biznet today)' => ['', '', '8.3.6', 'Composer version 2.7.1 2024-02-09 15:26:28', "found '<absent>'"],
    'npm too old' => ['v22.11.0', '9.8.1', '8.3.6', 'Composer version 2.7.1 2024-02-09 15:26:28', 'npm 10 or newer'],
    'PHP CLI 8.5 beside the 8.3 pool' => ['v22.11.0', '10.9.0', '8.5.8', 'Composer version 2.7.1 2024-02-09 15:26:28', 'PHP CLI must be 8.3.x'],
    'PHP 8.2' => ['v22.11.0', '10.9.0', '8.2.27', 'Composer version 2.7.1 2024-02-09 15:26:28', 'PHP CLI must be 8.3.x'],
    'Composer 1' => ['v22.11.0', '10.9.0', '8.3.6', 'Composer version 1.10.27 2023-09-29 10:50:23', 'Composer 2.x is required'],
    'Composer absent' => ['v22.11.0', '10.9.0', '8.3.6', '', 'Composer 2.x is required'],
]);

it('checks toolchain versions in the deploy and the rollback right after the preflight, before any mutation', function (string $script, string $backupMarker, string $checkoutMarker) {
    $lines = preg_split('/\R/', (string) file_get_contents(base_path($script))) ?: [];
    $code = array_filter($lines, fn (string $l): bool => ! str_starts_with(ltrim($l), '#'));
    $first = function (string $needle) use ($code): int {
        foreach ($code as $i => $line) {
            if (str_contains($line, $needle)) {
                return $i;
            }
        }

        return PHP_INT_MAX;
    };

    $preflight = $first('if ! dms_production_host_preflight ');
    $versions = $first('if ! dms_production_toolchain_versions');

    expect(count(array_filter($code, fn (string $l): bool => str_contains($l, 'dms_production_toolchain_versions'))))->toBe(1)
        ->and($versions)->toBeGreaterThan($preflight)
        ->and($versions)->toBeLessThan($first($backupMarker))
        ->and($versions)->toBeLessThan($first($checkoutMarker));

    $block = implode("\n", array_slice($lines, $versions, 8));
    expect($block)->toContain('"$(node --version 2>/dev/null || true)"')
        ->and($block)->toContain('"$(npm --version 2>/dev/null || true)"')
        ->and($block)->toContain("\"\$(php -r 'echo PHP_VERSION;' 2>/dev/null || true)\"")
        ->and($block)->toContain('composer --version --no-ansi --no-interaction')
        ->and($block)->toContain('exit 2');
})->with([
    'deploy' => ['scripts/deploy-vps.sh', 'dms_write_private_dump', 'git checkout "$BRANCH"'],
    'rollback' => ['scripts/rollback-vps.sh', 'dms_write_private_dump', 'git checkout "$ROLLBACK_TARGET_SHA"'],
]);

it('declares this suite mandatory for the critical gate', function () {
    expect(config('ci_runner.critical_gate_mandatory_suites'))
        ->toContain('tests/Feature/Deploy/DeployHardenRunnerEvidenceContractTest.php');
});

it('keeps the DEPLOY-HARDEN-1 operator interface green under the verify-based completion criterion', function () {
    // The governed markers are substrings of the runner, checked by the
    // DEPLOY-HARDEN-1 scanner in the critical gate's governance step. This pins
    // the same posture inside the suite, so a reworded launch message fails
    // here rather than only after the full critical run.
    $posture = app(DeploymentEntrypointScanner::class)->runnerPosture();

    expect($posture['ok'])->toBeTrue()
        ->and($posture['launcher_start_is_not_completion'])->toBeTrue()
        ->and($posture['issues'])->toBe([]);

    $markers = (array) config('deployment_entrypoint.runner_expectations.required_markers');
    $runner = (string) file_get_contents(base_path('scripts/deploy-vps-runner.sh'));

    // The completion criterion the operator is told about is the six-fact
    // verification, never exit=0 alone.
    expect(implode("\n", $markers))->toContain('reports DEPLOY RUNNER OK')
        ->not->toContain('until exit=0');
    expect($runner)->not->toContain('NOT complete until exit=0');
});
