<?php

use App\Support\Deploy\DeploymentEntrypointScanner;
use Symfony\Component\Process\Process;

uses()->group('Deploy', 'Security', 'ReleaseSafety');

/**
 * DMS-RELEASE-GUARD-TRUST-HARDENING-1 — rule 231, release guard trust.
 *
 * Rule 229 bound a deploy to an approval record on the base branch but could not
 * authenticate it, kept the runner's evidence where the runtime user can write,
 * and let a deploy and a rollback run at once. This suite proves the hardening:
 *
 *   - an approval counts only with the approver's SSH signature over its exact
 *     bytes, verified against a trust anchor on the host (never the repository),
 *     and only for this host's SSH host key;
 *   - an approval is spent by the deploy it completes (no replay after a rollback);
 *   - evidence lives in the deploy identity's own directory outside the checkout,
 *     each run is claimed exclusively, and tampered evidence never verifies;
 *   - deploy and rollback share one lock (and still honour the pre-231 role locks);
 *   - an interrupted run is reported INTERRUPTED and cleaned up by the next run;
 *   - a rollback resolves its target locally and refuses ambiguous names.
 *
 * Every test runs the REAL scripts against a disposable checkout and bare origin
 * (r229Sandbox in tests/Pest.php) with ephemeral keys. Nothing touches a server,
 * root or production.
 */
function rgtRequiresTooling(): void
{
    foreach (['git', 'flock', 'tar', 'setsid', 'sha256sum', 'ssh-keygen'] as $binary) {
        if (trim((string) shell_exec('command -v '.escapeshellarg($binary).' 2>/dev/null')) === '') {
            test()->markTestSkipped("{$binary} is not available on this host");
        }
    }
}

function rgtDenied(array $result, string $reason): void
{
    expect($result['exit'])->toBe(77, $result['output'])
        ->and($result['output'])->toContain('DEPLOYMENT=DENIED')
        ->and($result['output'])->not->toContain('RELEASE APPROVAL: GO')
        ->and($result['output'])->toContain($reason);
}

/** Push an approval record with a signature of the caller's choosing (null: none). */
function rgtApprove(array $s, string $record, ?string $signature, string $release = ''): array
{
    $release = $release !== '' ? $release : $s['release'];
    $files = [
        "deploy/release-approvals/{$release}.approval" => $record,
        "docs/sprints/{$release}.md" => "# approval\n\nOwner approval: deploy `{$s['sha']}`.\n",
    ];
    if ($signature !== null) {
        $files["deploy/release-approvals/{$release}.approval.sig"] = $signature;
    }
    $s['tip'] = r229Push($s, $files, 'approve');

    return $s;
}

/** The default contract-2 record of a sandbox. */
function rgtRecord(array $s, array $overrides = []): string
{
    return r229ApprovalRecord(array_merge([
        'DMS_APPROVAL_CONTRACT' => '2',
        'DMS_APPROVAL_RELEASE_ID' => $s['release'],
        'DMS_APPROVAL_REPOSITORY' => $s['repository'],
        'DMS_APPROVAL_HOST' => $s['host'],
        'DMS_APPROVAL_HOST_KEY_SHA256' => $s['host_key'],
        'DMS_APPROVAL_BASE_BRANCH' => $s['branch'],
        'DMS_APPROVAL_TARGET_SHA' => $s['sha'],
        'DMS_APPROVAL_TARGET_TREE' => $s['tree'],
        'DMS_APPROVAL_GO_TAG' => $s['go_tag'],
        'DMS_APPROVAL_APPROVED_BY' => 'owner',
        'DMS_APPROVAL_APPROVED_AT' => gmdate('Y-m-d\TH:i:s\Z', time() - 600),
        'DMS_APPROVAL_NOT_AFTER' => gmdate('Y-m-d\TH:i:s\Z', time() + 3600),
        'DMS_APPROVAL_EVIDENCE' => 'docs/sprints/'.$s['release'].'.md',
    ], $overrides));
}

/** Rewrite one key of a sandbox's authority (the file the library reads). */
function rgtAuthority(array $s, string $key, ?string $value): void
{
    $text = (string) preg_replace('/^'.$key.'=.*\n/m', '', (string) file_get_contents($s['authority']));
    file_put_contents($s['authority'], $value === null ? $text : $text."{$key}={$value}\n");
}

/** Rewrite one key of the authority the sandbox's in-tree runner reads (beside it). */
function rgtRunnerAuthority(array $s, string $key, ?string $value): void
{
    $file = "{$s['app']}/deploy/production-host.conf";
    $text = (string) preg_replace('/^'.$key.'=.*\n/m', '', (string) file_get_contents($file));
    file_put_contents($file, $value === null ? $text : $text."{$key}={$value}\n");
}

function rgtStart(array $s, array $env = [], string $release = ''): array
{
    return r229Runner($s, ['start', '--release-id', $release !== '' ? $release : $s['release'], '--approved-sha', $s['sha']], $env);
}

function rgtDeployId(array $start): string
{
    expect(preg_match('/deploy_id=(\d{8}-\d{6})/', $start['output'], $m))->toBe(1, $start['output']);

    return $m[1];
}

function rgtWaitFor(string $file, string $needle, int $seconds = 30): void
{
    $deadline = microtime(true) + $seconds;
    while (microtime(true) < $deadline && ! str_contains((string) @file_get_contents($file), $needle)) {
        usleep(100_000);
    }
    expect((string) @file_get_contents($file))->toContain($needle);
}

/** A bootstrap command line for a sandbox, as a shell string (for background holders). */
function rgtBootstrap(array $s, string $role): array
{
    return $role === 'deploy'
        ? ['bash', "{$s['app']}/scripts/deploy-immutable-exec.sh", '--role', 'deploy', '--app-dir', $s['app'], '--lock-dir', $s['lock'],
            '--branch', $s['branch'], '--release-id', $s['release'], '--approved-sha', $s['sha'],
            '--', 'scripts/deploy-vps.sh', '--release-id', $s['release'], '--approved-sha', $s['sha']]
        : ['bash', "{$s['app']}/scripts/deploy-immutable-exec.sh", '--role', 'rollback', '--app-dir', $s['app'], '--lock-dir', $s['lock'],
            '--target-sha', $s['base'], '--snapshot-ref', 'HEAD', '--', 'scripts/rollback-vps.sh', $s['base']];
}

/**
 * Start a bootstrap in the background, its output going to $log through the
 * shell (a Process callback only runs when the parent reads the pipes).
 */
function rgtHolder(array $s, string $role, array $env, string $log): Process
{
    $command = implode(' ', array_map('escapeshellarg', rgtBootstrap($s, $role))).' > '.escapeshellarg($log).' 2>&1';
    $process = new Process(['bash', '-c', $command], $s['app'], $env, null, 90);
    $process->start();

    return $process;
}

/** A sandbox at the release, carrying a rollback program that can hold the lock. */
function rgtLockSandbox(): array
{
    return r229Sandbox(['checkout' => 'release', 'files' => [
        'scripts/rollback-vps.sh' => "#!/usr/bin/env bash\necho ROLLBACK_IN_CRITICAL_SECTION\nsleep \"\${RGT_HOLD:-0}\"\necho ROLLBACK_FIXTURE_DONE\n",
    ]]);
}

// ─── 1. Signed approvals ────────────────────────────────────────────────────

it('approves a record signed by the approver key the host trusts, and names that key', function () {
    rgtRequiresTooling();
    $s = r229Sandbox();

    $r = r229Verify($s);
    $owner = r229Fingerprint($s['owner_key'].'.pub');

    expect($r['exit'])->toBe(0, $r['output'])
        ->and($r['output'])->toContain('RELEASE APPROVAL: GO')
        ->and($r['output'])->toContain("is a good 'dms-release-approval' signature by 'owner' (key {$owner}, anchor {$s['anchor']})")
        ->and($r['output'])->toContain("signer={$owner}")
        ->and($r['output'])->toContain("(host key {$s['host_key']})");
});

it('refuses an approval that the host cannot authenticate', function (string $case, string $reason) {
    rgtRequiresTooling();
    $s = r229Sandbox(['approve' => false, 'approvers' => 'owner auditor']);
    $record = rgtRecord($s, $case === 'principal' ? ['DMS_APPROVAL_APPROVED_BY' => 'auditor'] : []);
    $signature = match ($case) {
        'unsigned' => null,
        'intruder' => r229Sign($s['intruder_key'], $record),
        'edited after signing' => r229Sign($s['owner_key'], str_replace('owner', 'owner ', $record)),
        'namespace' => r229Sign($s['owner_key'], $record, 'git'),
        'principal' => r229Sign($s['owner_key'], $record),
        'not armored' => "signed: owner\n",
        'oversized' => r229Sign($s['owner_key'], $record).str_repeat("#\n", 2100),
    };
    $s = rgtApprove($s, $record, $signature);

    rgtDenied(r229Verify($s), $reason);
})->with([
    'no signature (rule 229 contract 1 era)' => ['unsigned', 'an unsigned record is not an approval'],
    'signed by a key the anchor does not trust' => ['intruder', 'does not verify for \'owner\''],
    'the record was edited after it was signed' => ['edited after signing', 'does not verify'],
    'a signature made for another purpose (namespace)' => ['namespace', 'does not verify'],
    'a declared approver the anchor does not map to the key' => ['principal', 'does not verify for \'auditor\''],
    'not an armored SSH signature' => ['not armored', 'is not an armored SSH signature'],
    'an oversized signature' => ['oversized', 'the limit is 4096'],
]);

it('stops a pusher who rewrites a signed record on the base branch', function () {
    rgtRequiresTooling();
    $s = r229Sandbox();
    // Someone with push access, but without the approver's key, widens the
    // window of the owner-signed record and keeps the old signature.
    $forged = rgtRecord($s, ['DMS_APPROVAL_NOT_AFTER' => gmdate('Y-m-d\TH:i:s\Z', time() + 7200)]);
    r229Push($s, ["deploy/release-approvals/{$s['release']}.approval" => $forged], 'extend the approval');

    rgtDenied(r229Verify($s), 'does not verify');
});

it('refuses a signature file that is a symlink in the commit', function () {
    rgtRequiresTooling();
    $s = r229Sandbox(['approve' => false]);
    $record = rgtRecord($s);
    $path = "deploy/release-approvals/{$s['release']}.approval";
    r229Write($s['maint'], [$path => $record, "docs/sprints/{$s['release']}.md" => "Owner approval: `{$s['sha']}`.\n"]);
    symlink('../../docs/sprints/x', "{$s['maint']}/{$path}.sig");
    r229Git($s['maint'], ['add', '-A']);
    r229Git($s['maint'], ['commit', '-q', '-m', 'symlinked signature']);
    r229Git($s['maint'], ['push', '-q', 'origin', $s['branch']]);

    rgtDenied(r229Verify($s), '.sig at');
});

it('trusts only an anchor that the deploy identity alone controls, outside the checkout', function (string $case, string $reason) {
    rgtRequiresTooling();
    $s = r229Sandbox();
    $uid = r229Uid();
    switch ($case) {
        case 'missing':
            unlink($s['anchor']);
            break;
        case 'a symlink':
            rename($s['anchor'], "{$s['anchor']}.real");
            symlink("{$s['anchor']}.real", $s['anchor']);
            break;
        case 'writable by others':
            chmod($s['anchor'], 0o666);
            break;
        case 'in a directory others can write':
            chmod($s['trust'], 0o777);
            break;
        case 'inside the checkout':
            copy($s['anchor'], "{$s['app']}/deploy/anchor.allowed_signers");
            rgtAuthority($s, 'DMS_PRODUCTION_RELEASE_TRUST_ANCHOR', "{$s['app']}/deploy/anchor.allowed_signers");
            break;
        case 'owned by someone else':
            $uid = (string) ((int) $uid + 1);
            break;
        case 'not declared':
            rgtAuthority($s, 'DMS_PRODUCTION_RELEASE_TRUST_ANCHOR', null);
            break;
        case 'trusting another key':
            file_put_contents($s['anchor'], 'owner namespaces="dms-release-approval" '.trim((string) file_get_contents($s['intruder_key'].'.pub'))."\n");
            break;
    }

    rgtDenied(r229Verify($s, ['uid' => $uid]), $reason);
})->with([
    'missing' => ['missing', 'is missing or is not a regular file'],
    'a symlink' => ['a symlink', 'is not a canonical absolute path'],
    'writable by others' => ['writable by others', 'is writable by group or others'],
    'in a directory others can write' => ['in a directory others can write', 'is writable by group or others'],
    'inside the checkout' => ['inside the checkout', 'lies inside the application checkout'],
    'owned by someone else' => ['owned by someone else', 'neither root nor the deploy identity'],
    'not declared' => ['not declared', 'declares no release trust anchor'],
    'trusting another key' => ['trusting another key', 'does not verify'],
]);

it('binds the approval to the host key, not only to a hostname', function (string $case, string $reason) {
    rgtRequiresTooling();
    $s = r229Sandbox();
    $overrides = [];
    if ($case === 'another machine with the same hostname') {
        $overrides['host_key'] = 'SHA256:'.str_repeat('Q', 43);
    } elseif ($case === 'an unreadable host key') {
        $overrides['host_key'] = '';
    } else {
        rgtAuthority($s, 'DMS_PRODUCTION_HOST_KEY_SHA256', null);
    }

    rgtDenied(r229Verify($s, $overrides), $reason);
})->with([
    'another machine with the same hostname' => ['another machine with the same hostname', 'this host presents host key'],
    'an unreadable host key' => ['an unreadable host key', "this host presents host key '<unreadable>'"],
    'an authority that pins no key' => ['no pin', 'pins no SHA256 host key fingerprint'],
]);

// ─── 2. One approval, one completed deploy ──────────────────────────────────

it('spends an approval with the deploy it completes, so a rollback cannot be undone by replaying it', function () {
    rgtRequiresTooling();
    $s = r229Sandbox();
    $first = rgtDeployId(rgtStart($s));
    expect(r229WaitForStatus($s, $first))->toBe('exit=0');

    // An operator rolls back (the checkout is detached at the older commit) and,
    // inside the same approval window, tries to deploy the release again.
    r229Git($s['app'], ['checkout', '-q', '--detach', $s['base']]);
    $again = rgtStart($s);
    rgtDenied($again, "release {$s['release']} was already deployed on this host");
    expect(is_file("{$s['evidence']}/spent/{$s['release']}.spent"))->toBeTrue();
    expect(r229Git($s['app'], ['rev-parse', 'HEAD']))->toBe($s['base']);

    // A new owner approval of the same commit (a new record) deploys it.
    $release = $s['release'].'-r2';
    $record = rgtRecord($s, ['DMS_APPROVAL_RELEASE_ID' => $release, 'DMS_APPROVAL_EVIDENCE' => "docs/sprints/{$release}.md"]);
    $s = rgtApprove($s, $record, r229Sign($s['owner_key'], $record), $release);
    $second = rgtDeployId(rgtStart($s, [], $release));
    expect(r229WaitForStatus($s, $second))->toBe('exit=0')
        ->and(r229Git($s['app'], ['rev-parse', 'HEAD']))->toBe($s['sha']);
});

it('refuses a spent release inside the lock too, and spends a release the bootstrap deploys on its own', function () {
    rgtRequiresTooling();
    $s = r229Sandbox();
    // A deploy run without the runner (the bootstrap directly) still spends the
    // release: the spend record is written inside the lock by the deploying process.
    $direct = r229Run(rgtBootstrap($s, 'deploy'), [], $s['app']);
    expect($direct['exit'])->toBe(0, $direct['output'])
        ->and($direct['output'])->toContain("DEPLOY_APPROVAL_SPENT={$s['evidence']}/spent/{$s['release']}.spent")
        ->and(substr(sprintf('%o', fileperms("{$s['evidence']}/spent")), -3))->toBe('700');

    // The runner refuses before launch ...
    rgtDenied(rgtStart($s), 'was already deployed on this host');
    // ... and the bootstrap refuses inside the lock, before it pins anything.
    r229Git($s['app'], ['checkout', '-q', '--detach', $s['base']]);
    $inside = r229Run(rgtBootstrap($s, 'deploy'), [], $s['app']);
    expect($inside['exit'])->toBe(77, $inside['output'])
        ->and($inside['output'])->toContain('DEPLOY_LOCK_ACQUIRED=YES')
        ->and($inside['output'])->toContain('was already deployed on this host')
        ->and($inside['output'])->not->toContain('DEPLOY_TARGET_PINNED')
        ->and(r229Git($s['app'], ['rev-parse', 'HEAD']))->toBe($s['base']);
});

it('cannot replay another signed version of a release that already deployed', function () {
    rgtRequiresTooling();
    $s = r229Sandbox();
    $first = rgtDeployId(rgtStart($s));
    expect(r229WaitForStatus($s, $first))->toBe('exit=0');
    r229Git($s['app'], ['checkout', '-q', '--detach', $s['base']]);

    // A different, genuinely owner-signed record of the SAME release (an earlier
    // draft restored by someone with push access, or a re-signed correction).
    $other = rgtRecord($s, ['DMS_APPROVAL_NOT_AFTER' => gmdate('Y-m-d\TH:i:s\Z', time() + 5400)]);
    $s = rgtApprove($s, $other, r229Sign($s['owner_key'], $other));

    rgtDenied(rgtStart($s), "release {$s['release']} was already deployed on this host");
    expect(r229Git($s['app'], ['rev-parse', 'HEAD']))->toBe($s['base']);
});

it('does not spend an approval on a deploy that failed', function () {
    rgtRequiresTooling();
    $s = r229Sandbox();
    $failed = rgtDeployId(rgtStart($s, ['R229_DURING_RUN' => 'exit 3']));
    expect(r229WaitForStatus($s, $failed))->toBe('exit=3');

    $retry = rgtStart($s);
    expect($retry['exit'])->toBe(0, $retry['output'])
        ->and(r229WaitForStatus($s, rgtDeployId($retry)))->toBe('exit=0');
});

// ─── 3. Trusted evidence ────────────────────────────────────────────────────

it('keeps the evidence in the deploy identity\'s own directory, never in the checkout', function () {
    rgtRequiresTooling();
    $s = r229Sandbox();
    $id = rgtDeployId(rgtStart($s));
    expect(r229WaitForStatus($s, $id))->toBe('exit=0');

    expect(substr(sprintf('%o', fileperms($s['evidence'])), -3))->toBe('700')
        ->and(is_dir("{$s['app']}/storage/logs/deploy-runner"))->toBeFalse()
        ->and(is_link(r229EvidenceFile($s, "deploy-{$id}.host")))->toBeFalse()
        ->and((string) file_get_contents(r229EvidenceFile($s, "deploy-{$id}.log")))->toContain('DEPLOY_APPROVAL_SIGNER='.r229Fingerprint($s['owner_key'].'.pub'));

    $verify = r229Runner($s, ['verify', $id]);
    expect($verify['exit'])->toBe(0, $verify['output'])
        ->and($verify['output'])->toContain("evidence {$s['evidence']}")
        ->and($verify['output'])->toContain('DEPLOY RUNNER OK');
});

it('refuses to start when the evidence directory is not trusted, and creates nothing', function (string $case, string $reason) {
    rgtRequiresTooling();
    $s = r229Sandbox();
    $dir = match ($case) {
        'inside the checkout' => "{$s['app']}/storage/logs/deploy-runner",
        'group-writable' => $s['evidence'],
        'a symlink' => $s['evidence'],
        'not declared' => null,
    };
    if ($case === 'group-writable') {
        mkdir($dir, 0o700);
        chmod($dir, 0o770);
    } elseif ($case === 'a symlink') {
        symlink(tempArtifactDir('rgt-elsewhere-'), $dir);
    }
    rgtRunnerAuthority($s, 'DMS_PRODUCTION_DEPLOY_EVIDENCE_DIR', $dir);

    $r = rgtStart($s);

    rgtDeniedRunner($r, $reason);
    expect(is_dir("{$s['app']}/storage/logs/deploy-runner"))->toBeFalse()
        ->and(r229Git($s['app'], ['rev-parse', 'HEAD']))->toBe($s['base']);
})->with([
    'inside the checkout' => ['inside the checkout', 'lies inside the application checkout'],
    'group-writable' => ['group-writable', 'mode 770'],
    'a symlink' => ['a symlink', 'is not a canonical absolute directory'],
    'not declared' => ['not declared', 'declares no deploy evidence directory (DMS_PRODUCTION_DEPLOY_EVIDENCE_DIR)'],
]);

function rgtDeniedRunner(array $result, string $reason): void
{
    expect($result['exit'])->toBe(77, $result['output'])
        ->and($result['output'])->toContain('DEPLOYMENT=DENIED')
        ->and($result['output'])->toContain($reason)
        ->and($result['output'])->not->toContain('started detached');
}

it('never writes through, opens or reuses an entry planted at a run identifier', function () {
    rgtRequiresTooling();
    $s = r229Sandbox();
    mkdir($s['evidence'], 0o700);
    $victim = "{$s['root']}/victim";
    file_put_contents($victim, "untouched\n");
    // Every identifier the runner can try (it tries up to five seconds) is taken,
    // by a symlink to a real file, a dangling symlink, a regular file or a FIFO —
    // the runtime user of rule 229 could do this in storage/logs; here the
    // directory is private, and the runner still never follows or opens one.
    $now = time();
    foreach (range(-1, 20) as $ahead) {
        // The runner names runs in the shell's local time.
        $stamp = trim((string) shell_exec('date -d @'.($now + $ahead).' +%Y%m%d-%H%M%S'));
        $base = "{$s['evidence']}/deploy-{$stamp}";
        match ($ahead % 4) {
            0 => symlink($victim, "{$base}.host"),
            1, -1 => symlink("{$s['root']}/dangling-{$stamp}", "{$base}.host"),
            2 => file_put_contents("{$base}.log", "someone else's\n"),
            3 => posix_mkfifo("{$base}.status", 0o600),
        };
    }
    $before = scandir($s['evidence']);

    try {
        $start = rgtStart($s);

        rgtDeniedRunner($start, 'could not claim a free deploy identifier');
        expect((string) file_get_contents($victim))->toBe("untouched\n")
            ->and(glob("{$s['root']}/dangling-*") ?: [])->toBe([])
            ->and(scandir($s['evidence']))->toBe($before);
    } finally {
        // The shared temp cleanup removes files and directories, not FIFOs.
        foreach (glob("{$s['evidence']}/deploy-*.status") ?: [] as $fifo) {
            @unlink($fifo);
        }
    }
})->skip(! function_exists('posix_mkfifo'), 'posix_mkfifo is not available');

it('never proves a run from evidence that was tampered with after it finished', function (string $case, string $verdict) {
    rgtRequiresTooling();
    $s = r229Sandbox();
    $id = rgtDeployId(rgtStart($s));
    expect(r229WaitForStatus($s, $id))->toBe('exit=0');
    $log = r229EvidenceFile($s, "deploy-{$id}.log");
    $record = r229EvidenceFile($s, "deploy-{$id}.host");
    switch ($case) {
        case 'the directory opened to the group':
            chmod($s['evidence'], 0o750);
            break;
        case 'the log replaced by a link':
            $forged = tempArtifactDir('rgt-forged-').'/log';
            copy($log, $forged);
            unlink($log);
            symlink($forged, $log);
            break;
        case 'the record made writable by others':
            chmod($record, 0o666);
            break;
        case 'the record moved to another directory':
            file_put_contents($record, str_replace("evidence_dir={$s['evidence']}", 'evidence_dir=/elsewhere', (string) file_get_contents($record)));
            break;
    }

    $verify = r229Runner($s, ['verify', $id]);

    expect($verify['exit'])->toBe(4, $verify['output'])
        ->and($verify['output'])->toContain("DEPLOY RUNNER STATUS {$verdict}")
        ->and($verify['output'])->not->toContain('DEPLOY RUNNER OK');
})->with([
    'the directory opened to the group' => ['the directory opened to the group', 'UNTRUSTED'],
    'the log replaced by a link' => ['the log replaced by a link', 'TAMPERED'],
    'the record made writable by others' => ['the record made writable by others', 'TAMPERED'],
    'the record moved to another directory' => ['the record moved to another directory', 'MISMATCHED'],
]);

it('reports the trust a host needs, read-only', function () {
    rgtRequiresTooling();
    $s = r229Sandbox();

    $ready = r229Runner($s, ['trust-check']);
    expect($ready['exit'])->toBe(0, $ready['output'])
        ->and($ready['output'])->toContain("TRUST_ANCHOR=OK ({$s['anchor']})")
        ->and($ready['output'])->toContain('signer key: 256 '.r229Fingerprint($s['owner_key'].'.pub').' owner (ED25519)')
        ->and($ready['output'])->toContain("HOST_KEY=OK ({$s['host_key']})")
        ->and($ready['output'])->toContain('EVIDENCE_DIR=ABSENT')
        ->and($ready['output'])->toContain('RELEASE GUARD TRUST: READY')
        ->and(is_dir($s['evidence']))->toBeFalse();

    unlink($s['anchor']);
    $notReady = r229Runner($s, ['trust-check']);
    expect($notReady['exit'])->toBe(1, $notReady['output'])
        ->and($notReady['output'])->toContain('TRUST_ANCHOR=NOT-READY')
        ->and($notReady['output'])->toContain('RELEASE GUARD TRUST: NOT READY')
        ->and(file_exists($s['evidence']))->toBeFalse('trust-check must not create anything');
});

// ─── 4. One lock for deploy and rollback ────────────────────────────────────

it('keeps a deploy and a rollback out of each other\'s critical section', function (string $holder) {
    rgtRequiresTooling();
    $s = rgtLockSandbox();
    $holderLog = "{$s['root']}/holder.log";
    $marker = $holder === 'deploy' ? 'PRODUCTION HOST PREFLIGHT: GO' : 'ROLLBACK_IN_CRITICAL_SECTION';
    $process = rgtHolder($s, $holder, ['R229_HOLD_SECONDS' => '4', 'RGT_HOLD' => '4'], $holderLog);
    rgtWaitFor($holderLog, $marker);

    $challenger = r229Run(rgtBootstrap($s, $holder === 'deploy' ? 'rollback' : 'deploy'), [], $s['app']);
    $process->wait();

    expect($challenger['exit'])->toBe(75, $challenger['output'])
        ->and($challenger['output'])->toContain('BUSY another deploy or rollback holds the release lock')
        ->and($challenger['output'])->toContain("last recorded holder: role={$holder}")
        ->and($challenger['output'])->not->toContain('DEPLOY_TARGET_PINNED')
        ->and($challenger['output'])->not->toContain('DEPLOY_SNAPSHOT_CREATED')
        ->and($process->getExitCode())->toBe(0, (string) @file_get_contents($holderLog))
        ->and(file_exists("{$s['lock']}/release.holder"))->toBeFalse();
})->with(['deploy', 'rollback']);

it('still excludes a bootstrap from a tree older than rule 231, which locks only its role', function (string $legacy, string $role) {
    rgtRequiresTooling();
    $s = rgtLockSandbox();
    // An old bootstrap holds only `<role>.lock` for its whole run.
    $old = new Process(['flock', "{$s['lock']}/{$legacy}.lock", 'sleep', '4']);
    $old->start();
    $deadline = microtime(true) + 10;
    while (microtime(true) < $deadline && r229Run(['flock', '-n', "{$s['lock']}/{$legacy}.lock", 'true'])['exit'] === 0) {
        usleep(50_000);
    }

    $new = r229Run(rgtBootstrap($s, $role), [], $s['app']);
    $old->stop(0);

    expect($new['exit'])->toBe(75, $new['output'])
        ->and($new['output'])->toContain("a pre-rule-231 {$legacy} holds {$legacy}.lock")
        ->and($new['output'])->not->toContain('DEPLOY_TARGET_PINNED');
})->with([
    'an old deploy blocks a new rollback' => ['deploy', 'rollback'],
    'an old rollback blocks a new deploy' => ['rollback', 'deploy'],
]);

it('holds the pre-231 role locks too, so an old bootstrap cannot start beside it', function () {
    rgtRequiresTooling();
    $s = rgtLockSandbox();
    $holderLog = "{$s['root']}/holder.log";
    $process = rgtHolder($s, 'deploy', ['R229_HOLD_SECONDS' => '4'], $holderLog);
    rgtWaitFor($holderLog, 'PRODUCTION HOST PREFLIGHT: GO');

    $oldRollback = r229Run(['flock', '-n', "{$s['lock']}/rollback.lock", 'true']);
    $oldDeploy = r229Run(['flock', '-n', "{$s['lock']}/deploy.lock", 'true']);
    $process->wait();

    expect($oldRollback['exit'])->not->toBe(0)
        ->and($oldDeploy['exit'])->not->toBe(0)
        ->and($process->getExitCode())->toBe(0);
});

// ─── 5. Recovery after an interrupted run ───────────────────────────────────

it('reports a killed run as INTERRUPTED, frees the lock and lets the next run clean up', function () {
    rgtRequiresTooling();
    $s = r229Sandbox();
    $id = rgtDeployId(rgtStart($s, ['R229_HOLD_SECONDS' => '60']));
    rgtWaitFor(r229EvidenceFile($s, "deploy-{$id}.log"), 'PRODUCTION HOST PREFLIGHT: GO');
    preg_match('/^launcher_pid=(\d+)$/m', (string) file_get_contents(r229EvidenceFile($s, "deploy-{$id}.host")), $pid);
    expect($pid[1] ?? null)->not->toBeNull();

    // SIGKILL the launcher's whole session (setsid): no trap runs, no status.
    // (argv, not a shell string: dash's kill builtin rejects `-- -PGID`.)
    $kill = r229Run(['kill', '-KILL', '--', '-'.$pid[1]]);
    expect($kill['exit'])->toBe(0, $kill['output']);
    $deadline = microtime(true) + 10;
    while (microtime(true) < $deadline && is_dir("/proc/{$pid[1]}")) {
        usleep(100_000);
    }
    expect(glob("{$s['lock']}/deploy-*") ?: [])->not->toBe([]);

    $verify = r229Runner($s, ['verify', $id]);
    expect($verify['exit'])->toBe(4, $verify['output'])
        ->and($verify['output'])->toContain('DEPLOY RUNNER STATUS INTERRUPTED')
        ->and($verify['output'])->not->toContain('status pending');

    $started = microtime(true);
    $follow = r229Runner($s, ['follow']);
    expect(microtime(true) - $started)->toBeLessThan(20.0)
        ->and($follow['output'])->toContain('DEPLOY RUNNER STATUS INTERRUPTED');

    // The interrupted run completed nothing, so its approval is unspent.
    $next = rgtDeployId(rgtStart($s));
    expect(r229WaitForStatus($s, $next))->toBe('exit=0');
    expect((string) file_get_contents(r229EvidenceFile($s, "deploy-{$next}.log")))->toContain('DEPLOY_STALE_SNAPSHOT_RECONCILED=deploy-')
        ->and(glob("{$s['lock']}/deploy-*") ?: [])->toBe([])
        ->and(r229Runner($s, ['verify', $next])['output'])->toContain('DEPLOY RUNNER OK');
});

it('refuses to start over a git lock an interrupted checkout left behind', function (string $lock) {
    rgtRequiresTooling();
    $s = r229Sandbox(['checkout' => 'release']);
    touch("{$s['app']}/.git/{$lock}");

    $r = r229Run(rgtBootstrap($s, 'deploy'), [], $s['app']);

    expect($r['exit'])->toBe(2, $r['output'])
        ->and($r['output'])->toContain("stale git lock {$s['app']}/.git/{$lock}")
        ->and($r['output'])->toContain('nothing was backed up, checked out or migrated')
        ->and($r['output'])->not->toContain('DEPLOY_TARGET_PINNED')
        ->and(glob("{$s['lock']}/deploy-*") ?: [])->toBe([]);

    unlink("{$s['app']}/.git/{$lock}");
    expect(r229Run(rgtBootstrap($s, 'deploy'), [], $s['app'])['exit'])->toBe(0);
})->with(['index.lock', 'HEAD.lock', 'packed-refs.lock', 'refs/heads/feature/r229-fixture-base.lock']);

it('removes the private approval refs of processes that are gone, and only those', function () {
    rgtRequiresTooling();
    $s = r229Sandbox(['checkout' => 'release']);
    $dead = new Process(['sleep', '0.1']);
    $dead->start();
    $deadPid = (int) $dead->getPid();
    $dead->wait();
    $alivePid = getmypid();
    r229Git($s['app'], ['update-ref', "refs/dms-release-approval/{$deadPid}-1", $s['sha']]);
    r229Git($s['app'], ['update-ref', "refs/dms-release-approval/{$alivePid}-1", $s['sha']]);

    $r = r229Run(rgtBootstrap($s, 'deploy'), [], $s['app']);

    expect($r['exit'])->toBe(0, $r['output'])
        ->and($r['output'])->toContain("DEPLOY_STALE_APPROVAL_REF_RECONCILED=refs/dms-release-approval/{$deadPid}-1")
        ->and(r229Git($s['app'], ['for-each-ref', '--format=%(refname)', 'refs/dms-release-approval/']))->toBe("refs/dms-release-approval/{$alivePid}-1");
});

// ─── 6. Rollback target resolution (outside the lock) ───────────────────────

/**
 * Run the REAL rollback pre-stage against the sandbox: a copy of
 * scripts/rollback-vps.sh whose production app dir is the sandbox checkout, with
 * a stub bootstrap beside it that only reports what it was handed.
 */
function rgtRollbackPrestage(array $s, string $target): array
{
    $dir = "{$s['root']}/rollback-copy/scripts";
    if (! is_dir($dir)) {
        mkdir($dir, 0o700, true);
    }
    $source = (string) file_get_contents(base_path('scripts/rollback-vps.sh'));
    $line = 'APP_DIR="/var/www/asia-dental-lab-v2"';
    expect(substr_count($source, $line."\n"))->toBe(1, 'the rollback copy would silently drift from the real script');
    file_put_contents("{$dir}/rollback-vps.sh", str_replace($line."\n", 'APP_DIR="'.$s['app'].'"'."\n", $source));
    file_put_contents("{$dir}/deploy-immutable-exec.sh", "#!/usr/bin/env bash\necho \"STUB-BOOTSTRAP \$*\"\n");

    return r229Run(['bash', "{$dir}/rollback-vps.sh", $target], [], $s['app']);
}

it('resolves a rollback target locally, refuses ambiguity and never prunes or bulk-fetches', function (string $case, int $exit, string $needle) {
    rgtRequiresTooling();
    $s = r229Sandbox(['checkout' => 'release']);
    $short = substr($s['base'], 0, 8);
    $target = match ($case) {
        'full SHA' => $s['base'],
        'full SHA also used as a tag name' => $s['base'],
        'remote GO tag' => 'dms-fixture-old-go',
        'abbreviated SHA' => $short,
        'short SHA shadowed by a tag and a branch' => $short,
        'branch name' => $s['branch'],
        'newer commit' => $s['tip'],
        'unknown name' => 'dms-no-such-go',
        'option-like' => '--upload-pack=x',
    };
    if (in_array($case, ['full SHA', 'full SHA also used as a tag name'], true)) {
        // No network is needed: point origin nowhere.
        r229Git($s['app'], ['remote', 'set-url', 'origin', "{$s['root']}/no-such-origin.git"]);
    }
    if ($case === 'full SHA also used as a tag name') {
        r229Git($s['app'], ['tag', $s['base'], $s['sha']]);
    } elseif ($case === 'remote GO tag') {
        r229Git($s['maint'], ['tag', 'dms-fixture-old-go', $s['base']]);
        r229Git($s['maint'], ['push', '-q', 'origin', 'dms-fixture-old-go']);
    } elseif ($case === 'short SHA shadowed by a tag and a branch') {
        // Two refs share the name: `git rev-parse --symbolic-full-name` prints
        // nothing for it, yet `rev-parse` still resolves the tag.
        r229Git($s['app'], ['tag', $short, $s['sha']]);
        r229Git($s['app'], ['branch', $short, $s['sha']]);
    } elseif ($case === 'newer commit') {
        r229Git($s['app'], ['fetch', '-q', '--no-tags', 'origin', $s['branch']]);
    }
    $tagsBefore = r229Git($s['app'], ['tag', '-l']);

    $r = rgtRollbackPrestage($s, $target);

    expect($r['exit'])->toBe($exit, $r['output'])->and($r['output'])->toContain($needle);
    if ($exit === 0) {
        expect($r['output'])->toContain("--target-sha {$s['base']}")
            ->and(r229Git($s['app'], ['tag', '-l']))->toBe($tagsBefore)
            ->and(r229Git($s['app'], ['for-each-ref', 'refs/dms-rollback-target/']))->toBe('');
    } else {
        expect($r['output'])->not->toContain('STUB-BOOTSTRAP');
    }
})->with([
    'a full commit SHA on the deployed history' => ['full SHA', 0, 'STUB-BOOTSTRAP --role rollback'],
    'a full SHA that a tag also names (the object wins)' => ['full SHA also used as a tag name', 0, 'STUB-BOOTSTRAP --role rollback'],
    'a GO tag only the remote has' => ['remote GO tag', 0, 'STUB-BOOTSTRAP --role rollback'],
    'an abbreviated SHA' => ['abbreviated SHA', 2, 'is an abbreviated commit id and is ambiguous'],
    'a short SHA shadowed by a tag and a branch' => ['short SHA shadowed by a tag and a branch', 2, 'is an abbreviated commit id and is ambiguous'],
    'a branch name (only tags are names)' => ['branch name', 2, 'does not resolve to a commit'],
    'a newer commit (that is a deploy)' => ['newer commit', 2, 'is not an ancestor of the running checkout'],
    'a name that resolves nowhere' => ['unknown name', 2, 'does not resolve to a commit'],
    'an option-like name' => ['option-like', 2, 'is not a full commit SHA or a plain tag name'],
]);

// ─── 7. Static posture ──────────────────────────────────────────────────────

it('pins the production trust anchor, evidence directory and host key file outside the checkout', function () {
    $authority = (string) file_get_contents(base_path('deploy/production-host.conf'));

    expect($authority)->toMatch('/^DMS_PRODUCTION_RELEASE_TRUST_ANCHOR=\/etc\/daengtisiams\/release-approvers\.allowed_signers$/m')
        ->and($authority)->toMatch('/^DMS_PRODUCTION_DEPLOY_EVIDENCE_DIR=\/var\/lib\/daengtisiams-deploy\/runner$/m')
        ->and($authority)->toMatch('/^DMS_PRODUCTION_HOST_KEY_FILE=\/etc\/ssh\/ssh_host_ed25519_key\.pub$/m')
        ->and($authority)->toMatch('/^DMS_PRODUCTION_HOST_KEY_SHA256=SHA256:[A-Za-z0-9+\/]{43}$/m')
        ->and($authority)->not->toContain('ssh-ed25519 AAAA');

    // No allowed-signers file or private key is ever shipped in the repository.
    $tracked = (new Process(['git', 'ls-files'], base_path()))->mustRun()->getOutput();
    expect($tracked)->not->toContain('allowed_signers')
        ->and($tracked)->not->toMatch('/(^|\/)id_(ed25519|rsa|ecdsa)(\n|$)/m');
});

it('fails the posture check when the authority relocates a pinned trust path', function () {
    $scanner = app(DeploymentEntrypointScanner::class);
    expect($scanner->approvalGuardPosture())->toMatchArray(['ok' => true, 'issues' => []]);

    config()->set('deployment_entrypoint.approval_guard_expectations.pinned_authority_values.DMS_PRODUCTION_DEPLOY_EVIDENCE_DIR', '/var/www/asia-dental-lab-v2/storage/logs/deploy-runner');
    expect(implode("\n", $scanner->approvalGuardPosture()['issues']))->toContain('DMS_PRODUCTION_DEPLOY_EVIDENCE_DIR=/var/lib/daengtisiams-deploy/runner, production pins /var/www/asia-dental-lab-v2/storage/logs/deploy-runner');
});

it('keeps every rule 231 control in the scripts', function () {
    $code = fn (string $relative) => implode("\n", array_filter(
        preg_split('/\R/', (string) file_get_contents(base_path($relative))) ?: [],
        fn (string $line): bool => ! str_starts_with(ltrim($line), '#'),
    ));
    $bootstrap = $code('scripts/deploy-immutable-exec.sh');
    $pos = fn (string $needle) => strpos($bootstrap, $needle);

    // The locks come first; recovery and the approval run inside them; the pin follows.
    expect($pos('exec 9>"$LOCK_FILE"'))->toBeLessThan($pos('exec 8>"${LOCK_DIR}/deploy.lock"'))
        ->and($pos('exec 7>"${LOCK_DIR}/rollback.lock"'))->toBeLessThan($pos('DEPLOY_STALE_SNAPSHOT_RECONCILED'))
        ->and($pos('stale git lock'))->toBeLessThan($pos('dms_release_approval_verify "$APP_DIR"'))
        ->and($pos('dms_release_approval_verify "$APP_DIR"'))->toBeLessThan($pos('echo "DEPLOY_TARGET_PINNED='))
        ->and($code('scripts/lib/release-approval.sh'))->toContain('dms_release_approval_spent "$authority" "$app_dir" "$deploy_uid" "$release_id" || return')
        ->and($code('scripts/rollback-vps.sh'))->not->toContain('--prune')
        ->and($code('scripts/rollback-vps.sh'))->not->toContain('fetch --tags')
        ->and($code('scripts/lib/release-approval.sh'))->toContain('-s <(dms_release_approval_git cat-file blob "$sig_blob")');
});
