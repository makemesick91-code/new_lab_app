<?php

use Symfony\Component\Process\Process;

/*
 * R6-B6 isolated restore drill — the authenticated role manifest (owner directive D3) and the authenticated,
 * tamper-evident drill evidence (D10), with real `ssh-keygen -Y sign/verify` keys made for each test.
 */

function rdmeDir(): string
{
    $dir = sys_get_temp_dir().'/rdme-'.bin2hex(random_bytes(6));
    mkdir($dir, 0700, true);
    foreach (['owner', 'drill', 'evil'] as $key) {
        (new Process(['ssh-keygen', '-q', '-t', 'ed25519', '-N', '', '-C', $key, '-f', "{$dir}/{$key}"]))->mustRun();
    }
    file_put_contents("{$dir}/anchor", 'owner@dms '.file_get_contents("{$dir}/owner.pub"));
    file_put_contents("{$dir}/evidence-anchor", 'drill-host@dms '.file_get_contents("{$dir}/drill.pub"));

    return $dir;
}

function rdmeCleanup(string $dir): void
{
    (new Process(['rm', '-rf', '--', $dir]))->run();
}

function rdmeSh(string $script, array $env = []): array
{
    $process = new Process(['bash', '-c', '. "$LIB/restore-integrity.sh"; . "$LIB/restore-manifest.sh"; . "$LIB/restore-evidence.sh"; '.$script], null, array_merge([
        'LIB' => base_path('scripts/lib'),
        'PATH' => getenv('PATH'),
    ], $env));
    $process->run();

    return ['exit' => (int) $process->getExitCode(), 'out' => trim($process->getOutput()), 'err' => $process->getErrorOutput()];
}

/** Writes a manifest (key => value, in order) and signs it with $key in $namespace. */
function rdmeManifest(string $dir, array $overrides = [], string $key = 'owner', string $namespace = 'dms-restore-role-manifest', array $extraLines = []): string
{
    $fields = array_merge([
        'DMS_ROLE_MANIFEST_CONTRACT' => '1',
        'DMS_ROLE_MANIFEST_ID' => 'manifest-0001',
        'DMS_ROLE_MANIFEST_ROLES' => 'app_runtime dms_owner',
        'DMS_ROLE_MANIFEST_RUNTIME_ROLE' => 'app_runtime',
        'DMS_ROLE_MANIFEST_R6_OWNER_ROLE' => 'none',
        'DMS_ROLE_MANIFEST_ISSUED_BY' => 'owner@dms',
        'DMS_ROLE_MANIFEST_ISSUED_AT' => gmdate('Y-m-d\TH:i:s\Z', time() - 60),
        'DMS_ROLE_MANIFEST_NOT_AFTER' => gmdate('Y-m-d\TH:i:s\Z', time() + 30 * 86400),
    ], $overrides);
    $file = "{$dir}/manifest-".bin2hex(random_bytes(4));
    $lines = collect($fields)->filter(fn ($v) => $v !== null)->map(fn (string $v, string $k): string => "{$k}={$v}")->values()->all();
    file_put_contents($file, implode("\n", array_merge($lines, $extraLines))."\n");
    (new Process(['ssh-keygen', '-q', '-Y', 'sign', '-f', "{$dir}/{$key}", '-n', $namespace, $file]))->mustRun();

    return $file;
}

it('verifies a manifest signed by the trusted principal and prints its sha256 (D3)', function () {
    $dir = rdmeDir();
    $file = rdmeManifest($dir);
    $run = rdmeSh('dms_restore_manifest_verify "$M" "$A" && echo "roles=${DMS_ROLE_MANIFEST[DMS_ROLE_MANIFEST_ROLES]}"', ['M' => $file, 'A' => "{$dir}/anchor"]);
    $sha = hash_file('sha256', $file);
    rdmeCleanup($dir);

    expect($run['exit'])->toBe(0, $run['out'])
        ->and($run['out'])->toBe("{$sha}\nroles=app_runtime dms_owner");
});

it('refuses a manifest whose signature, signer or namespace is wrong, before reading any value', function (string $case) {
    $dir = rdmeDir();
    $file = match ($case) {
        'tampered after signing' => (function () use ($dir) {
            $f = rdmeManifest($dir);
            file_put_contents($f, str_replace('app_runtime dms_owner', 'app_runtime dms_owner attacker', (string) file_get_contents($f)));

            return $f;
        })(),
        'signed by an untrusted key' => rdmeManifest($dir, key: 'evil'),
        'signed for another purpose' => rdmeManifest($dir, namespace: 'dms-release-approval'),
        'claims another principal' => rdmeManifest($dir, ['DMS_ROLE_MANIFEST_ISSUED_BY' => 'drill-host@dms']),
        'no signature file' => (function () use ($dir) {
            $f = rdmeManifest($dir);
            unlink("{$f}.sig");

            return $f;
        })(),
    };
    $run = rdmeSh('dms_restore_manifest_verify "$M" "$A"; echo "count=${#DMS_ROLE_MANIFEST[@]}"', ['M' => $file, 'A' => "{$dir}/anchor"]);
    rdmeCleanup($dir);

    expect($run['out'])->toContain('manifest: ')
        ->and($run['out'])->toContain('count=0');
})->with(['tampered after signing', 'signed by an untrusted key', 'signed for another purpose', 'claims another principal', 'no signature file']);

it('refuses a correctly signed manifest whose content is invalid', function (array $overrides, array $extra, string $message) {
    $dir = rdmeDir();
    $file = rdmeManifest($dir, $overrides, extraLines: $extra);
    $run = rdmeSh('dms_restore_manifest_verify "$M" "$A"', ['M' => $file, 'A' => "{$dir}/anchor"]);
    rdmeCleanup($dir);

    expect($run['exit'])->toBe(1)
        ->and($run['out'])->toContain($message);
})->with([
    'expired' => [['DMS_ROLE_MANIFEST_NOT_AFTER' => gmdate('Y-m-d\TH:i:s\Z', time() - 10)], [], 'expired at'],
    'issued in the future' => [['DMS_ROLE_MANIFEST_ISSUED_AT' => gmdate('Y-m-d\TH:i:s\Z', time() + 3600), 'DMS_ROLE_MANIFEST_NOT_AFTER' => gmdate('Y-m-d\TH:i:s\Z', time() + 7200)], [], 'issued in the future'],
    'longer than 90 days' => [['DMS_ROLE_MANIFEST_NOT_AFTER' => gmdate('Y-m-d\TH:i:s\Z', time() + 100 * 86400)], [], 'longer than 90 days'],
    'non-canonical time' => [['DMS_ROLE_MANIFEST_NOT_AFTER' => '2026-02-30T00:00:00Z'], [], 'NOT_AFTER is not a canonical UTC time'],
    'unknown key' => [[], ['DMS_ROLE_MANIFEST_EXTRA=1'], 'unknown key DMS_ROLE_MANIFEST_EXTRA'],
    'duplicate key' => [[], ['DMS_ROLE_MANIFEST_ROLES=app_runtime'], 'DMS_ROLE_MANIFEST_ROLES appears twice'],
    'missing key' => [['DMS_ROLE_MANIFEST_R6_OWNER_ROLE' => null], [], 'DMS_ROLE_MANIFEST_R6_OWNER_ROLE is missing'],
    'stray line' => [[], ['# comment'], 'unexpected line'],
    'injected role name' => [['DMS_ROLE_MANIFEST_ROLES' => 'app_runtime x";DROP'], [], 'is not a plain lowercase role name'],
    'reserved role' => [['DMS_ROLE_MANIFEST_ROLES' => 'app_runtime drill_super'], [], "role name 'drill_super' is reserved"],
    'runtime not listed' => [['DMS_ROLE_MANIFEST_RUNTIME_ROLE' => 'other'], [], 'RUNTIME_ROLE is not one of ROLES'],
    'owner not listed' => [['DMS_ROLE_MANIFEST_R6_OWNER_ROLE' => 'ghost'], [], 'R6_OWNER_ROLE is neither none nor one of ROLES'],
    'unsupported contract' => [['DMS_ROLE_MANIFEST_CONTRACT' => '2'], [], 'unsupported contract'],
]);

it('refuses a revoked manifest, or one issued before the revocation list\'s not-before (PR-9)', function (string $list, int $exit, string $message) {
    $dir = rdmeDir();
    $file = rdmeManifest($dir, ['DMS_ROLE_MANIFEST_ID' => 'manifest-0007']);
    file_put_contents("{$dir}/revocations", $list);
    $run = rdmeSh('dms_restore_manifest_verify "$M" "$A" > /dev/null && dms_restore_manifest_revocation_check "$R"', ['M' => $file, 'A' => "{$dir}/anchor", 'R' => "{$dir}/revocations"]);
    rdmeCleanup($dir);

    expect($run['exit'])->toBe($exit)
        ->and($run['out'])->toContain($message);
})->with(function () {
    return [
        'empty list' => ["# none revoked\n", 0, ''],
        'another id revoked' => ["revoked manifest-0001\n", 0, ''],
        'this id revoked' => ["revoked manifest-0007\n", 1, 'manifest-0007 is revoked'],
        'issued before not-before' => ['not-before '.gmdate('Y-m-d\TH:i:s\Z', time() + 3600)."\n", 1, 'was issued before'],
        'issued after not-before' => ['not-before '.gmdate('Y-m-d\TH:i:s\Z', time() - 86400)."\n", 0, ''],
        'malformed list fails closed' => ["revoke manifest-0007\n", 1, 'unexpected line'],
    ];
});

/** A contract-2 evidence request (the operator side); $over replaces values. */
function rdmeRequest(array $over = []): string
{
    $h = str_repeat('a', 64);
    $now = gmdate('Y-m-d\TH:i:s\Z');
    $fields = array_merge([
        'DMS_DRILL_EVIDENCE_RUN_ID' => 'dmsdrill-'.bin2hex(random_bytes(6)),
        'DMS_DRILL_EVIDENCE_DRILL_HOST' => 'drillhost',
        'DMS_DRILL_EVIDENCE_STARTED_AT' => $now,
        'DMS_DRILL_EVIDENCE_COMPLETED_AT' => $now,
        'DMS_DRILL_EVIDENCE_BACKUP_SHA256' => $h,
        'DMS_DRILL_EVIDENCE_BACKUP_BYTES' => '4304',
        'DMS_DRILL_EVIDENCE_DIGEST_SOURCE' => 'expected-sha256',
        'DMS_DRILL_EVIDENCE_MANIFEST_ID' => 'manifest-0001',
        'DMS_DRILL_EVIDENCE_MANIFEST_SHA256' => $h,
        'DMS_DRILL_EVIDENCE_IMAGE_SHA256' => $h,
        'DMS_DRILL_EVIDENCE_LIMITS' => 'vcpu=1,memory_mib=2048,disk_gib=10,minutes=30,tasks=64',
        'DMS_DRILL_EVIDENCE_HOST_VERIFIED' => 'backup_sha256,archive_toc,per_table_counts',
        'DMS_DRILL_EVIDENCE_EXPECTED' => 'tables=2,rows=6,per_table_digest='.$h,
        'DMS_DRILL_EVIDENCE_GUEST_ASSERTED' => 'restore=ok,tables=2,per_table_digest='.$h,
        'DMS_DRILL_EVIDENCE_DECISION' => 'GO',
    ], $over);

    return collect($fields)->filter(fn ($v) => $v !== null)->map(fn (string $v, string $k): string => "{$k}={$v}")->implode("\n")."\n";
}

/** Publishes $count records through the signer library (the signer side) into a fresh store with a head log. */
function rdmeStore(int $count): array
{
    $dir = rdmeDir();
    $store = "{$dir}/store";
    mkdir($store, 0700);
    for ($i = 0; $i < $count; $i++) {
        file_put_contents("{$dir}/req", rdmeRequest());
        $run = rdmeSh('dms_evidence_request_parse "$Q" && dms_evidence_publish "$S" "$K" drill-host@dms operator "$H" "$A"', ['Q' => "{$dir}/req", 'S' => $store, 'K' => "{$dir}/drill", 'H' => "{$dir}/head.log", 'A' => "{$dir}/evidence-anchor"]);
        expect($run['out'])->toStartWith('RECORD ', $run['out'].$run['err']);
    }

    return [$dir, $store];
}

it('publishes signed, chained records atomically and keeps an independent head log (D10, PR-9)', function () {
    [$dir, $store] = rdmeStore(3);
    $run = rdmeSh('dms_evidence_verify_store "$S" "$A" "$H"', ['S' => $store, 'A' => "{$dir}/evidence-anchor", 'H' => "{$dir}/head.log"]);
    $first = (string) file_get_contents("{$store}/000001/record");
    $second = (string) file_get_contents("{$store}/000002/record");
    $head = (string) file_get_contents("{$dir}/head.log");
    $entries = array_values(array_diff(scandir($store), ['.', '..']));
    rdmeCleanup($dir);

    expect($run['exit'])->toBe(0, $run['out'])
        ->and($run['out'])->toStartWith('VERIFIED 3 head=')
        ->and($entries)->toBe(['000001', '000002', '000003'])
        ->and($first)->toContain("DMS_DRILL_EVIDENCE_CONTRACT=2\nDMS_DRILL_EVIDENCE_SEQ=000001\nDMS_DRILL_EVIDENCE_PREV_SHA256=genesis\n")
        ->and($first)->toContain("DMS_DRILL_EVIDENCE_SIGNER=drill-host@dms\nDMS_DRILL_EVIDENCE_CALLER=operator\n")
        ->and($second)->toContain('DMS_DRILL_EVIDENCE_PREV_SHA256='.hash('sha256', $first)."\n")
        ->and(substr_count($head, "\n"))->toBe(3)
        ->and($head)->toContain('000001 '.hash('sha256', $first));
});

it('detects every change to the store, including dropped newest records, through the head log', function (string $case, string $message) {
    [$dir, $store] = rdmeStore(3);
    switch ($case) {
        case 'edited record':
            file_put_contents("{$store}/000002/record", str_replace('DECISION=GO', 'DECISION=FAIL:x', (string) file_get_contents("{$store}/000002/record")));
            break;
        case 'removed record':
            (new Process(['rm', '-rf', '--', "{$store}/000002"]))->run();
            break;
        case 'swapped records':
            rename("{$store}/000002", "{$store}/tmp");
            rename("{$store}/000003", "{$store}/000002");
            rename("{$store}/tmp", "{$store}/000003");
            break;
        case 're-signed by another key':
            unlink("{$store}/000003/record.sig");
            (new Process(['ssh-keygen', '-q', '-Y', 'sign', '-f', "{$dir}/evil", '-n', 'dms-restore-drill-evidence', "{$store}/000003/record"]))->mustRun();
            break;
        case 'newest record dropped':
            (new Process(['rm', '-rf', '--', "{$store}/000003"]))->run();
            break;
        case 'head log truncated':
            file_put_contents("{$dir}/head.log", implode("\n", array_slice(explode("\n", (string) file_get_contents("{$dir}/head.log")), 0, 2))."\n");
            break;
    }
    $run = rdmeSh('dms_evidence_verify_store "$S" "$A" "$H"', ['S' => $store, 'A' => "{$dir}/evidence-anchor", 'H' => "{$dir}/head.log"]);
    rdmeCleanup($dir);

    expect($run['exit'])->toBe(1)
        ->and($run['out'])->toContain($message);
})->with([
    ['edited record', 'signature does not verify'],
    ['removed record', 'sequence gap'],
    ['swapped records', 'SEQ does not match its directory'],
    ['re-signed by another key', 'signature does not verify'],
    ['newest record dropped', "is not the head log's last entry"],
    ['head log truncated', "is not the head log's last entry"],
]);

it('refuses to sign a replayed run, an invalid request, or onto a store that does not verify (VR-4, PR-9)', function (string $case, string $message) {
    [$dir, $store] = rdmeStore(1);
    $req = match ($case) {
        'replayed run id' => rdmeRequest(['DMS_DRILL_EVIDENCE_RUN_ID' => trim((string) shell_exec('sed -n "s/^DMS_DRILL_EVIDENCE_RUN_ID=//p" '.escapeshellarg("{$store}/000001/record")))]),
        'missing key' => rdmeRequest(['DMS_DRILL_EVIDENCE_IMAGE_SHA256' => null]),
        'signer key smuggled in' => rdmeRequest()."DMS_DRILL_EVIDENCE_SEQ=000009\n",
        'shell text in a value' => rdmeRequest(['DMS_DRILL_EVIDENCE_DECISION' => 'GO; reboot']),
        'tampered store' => rdmeRequest(),
    };
    if ($case === 'tampered store') {
        file_put_contents("{$store}/000001/record", str_replace('DECISION=GO', 'DECISION=FAIL:x', (string) file_get_contents("{$store}/000001/record")));
    }
    file_put_contents("{$dir}/req", $req);
    $run = rdmeSh('dms_evidence_request_parse "$Q" && dms_evidence_publish "$S" "$K" drill-host@dms operator "$H" "$A"', ['Q' => "{$dir}/req", 'S' => $store, 'K' => "{$dir}/drill", 'H' => "{$dir}/head.log", 'A' => "{$dir}/evidence-anchor"]);
    $entries = array_values(array_diff(scandir($store), ['.', '..']));
    rdmeCleanup($dir);

    expect($run['exit'])->toBe(1)
        ->and($run['out'])->toContain($message)
        ->and($entries)->toBe(['000001']);
})->with([
    ['replayed run id', 'is already recorded (replay refused)'],
    ['missing key', 'request keys differ from contract 2'],
    ['signer key smuggled in', 'request keys differ from contract 2'],
    ['shell text in a value', 'request has an invalid DMS_DRILL_EVIDENCE_DECISION'],
    ['tampered store', 'the store does not verify, nothing is appended'],
]);

it('ignores an unpublished staging directory left by a crash, and never counts it as a record', function () {
    [$dir, $store] = rdmeStore(2);
    mkdir("{$store}/.stage-000003-abcdef", 0700);
    file_put_contents("{$store}/.stage-000003-abcdef/record", "half written\n");
    $run = rdmeSh('dms_evidence_verify_store "$S" "$A" "$H"', ['S' => $store, 'A' => "{$dir}/evidence-anchor", 'H' => "{$dir}/head.log"]);
    rdmeCleanup($dir);

    expect($run['out'])->toStartWith('VERIFIED 2 head=');
});

it('lets only a different identity, through sudo, from the allowlist, ask the signer for a record (VR-4)', function (string $case, string $message) {
    $dir = rdmeDir();
    mkdir("{$dir}/store", 0700);
    file_put_contents("{$dir}/signer.conf", "DMS_SIGNER_KEY={$dir}/drill\nDMS_SIGNER_PRINCIPAL=drill-host@dms\nDMS_SIGNER_ANCHOR={$dir}/evidence-anchor\nDMS_SIGNER_STORE={$dir}/store\nDMS_SIGNER_HEAD_LOG={$dir}/head.log\nDMS_SIGNER_ALLOWED_CALLERS=drillop\n");
    chmod("{$dir}/signer.conf", 0600);
    chmod("{$dir}/evidence-anchor", 0600);
    $uid = (string) getmyuid();
    $env = match ($case) {
        'called directly (no sudo)' => [],
        'sudo from the same identity' => ['SUDO_UID' => $uid, 'SUDO_USER' => 'drillop'],
        'caller not on the allowlist' => ['SUDO_UID' => (string) ((int) $uid + 1), 'SUDO_USER' => 'intruder'],
    };
    $process = new Process(['bash', base_path('scripts/restore-drill-evidence-signer.sh'), 'sign'], null, array_merge(['PATH' => getenv('PATH'), 'DMS_SIGNER_CONF' => "{$dir}/signer.conf"], $env), rdmeRequest());
    $process->run();
    $entries = array_values(array_diff(scandir("{$dir}/store"), ['.', '..', '.lock']));
    rdmeCleanup($dir);

    expect($process->getExitCode())->toBe(1)
        ->and($process->getOutput())->toContain($message)
        ->and($entries)->toBe([]);
})->with([
    ['called directly (no sudo)', 'requests come only through sudo from a different identity (VR-4)'],
    ['sudo from the same identity', 'requests come only through sudo from a different identity (VR-4)'],
    ['caller not on the allowlist', 'caller is not allowed'],
]);

it('signs through the signer entrypoint for an allowed caller, verifies, and recovers a head log one record behind', function () {
    $dir = rdmeDir();
    mkdir("{$dir}/store", 0700);
    file_put_contents("{$dir}/signer.conf", "DMS_SIGNER_KEY={$dir}/drill\nDMS_SIGNER_PRINCIPAL=drill-host@dms\nDMS_SIGNER_ANCHOR={$dir}/evidence-anchor\nDMS_SIGNER_STORE={$dir}/store\nDMS_SIGNER_HEAD_LOG={$dir}/head.log\nDMS_SIGNER_ALLOWED_CALLERS=drillop\n");
    chmod("{$dir}/signer.conf", 0600);
    chmod("{$dir}/evidence-anchor", 0600);
    // The real separation (a different uid through sudo) runs in the system-mode CI gate; here SUDO_* stand for it.
    $sudo = ['PATH' => getenv('PATH'), 'DMS_SIGNER_CONF' => "{$dir}/signer.conf", 'SUDO_UID' => (string) (getmyuid() + 1), 'SUDO_USER' => 'drillop'];
    $signer = fn (string $mode, string $in = '') => tap(new Process(['bash', base_path('scripts/restore-drill-evidence-signer.sh'), $mode], null, $sudo, $in), fn ($p) => $p->run());
    $first = $signer('sign', rdmeRequest());
    $second = $signer('sign', rdmeRequest());
    $verify = $signer('verify');
    // A crash between publication and the head-log append: drop the last head entry, then recover it.
    $lines = explode("\n", trim((string) file_get_contents("{$dir}/head.log")));
    file_put_contents("{$dir}/head.log", $lines[0]."\n");
    $broken = $signer('verify');
    $recover = $signer('recover-head');
    $again = $signer('verify');
    $twice = $signer('recover-head');
    rdmeCleanup($dir);

    expect($first->getOutput())->toStartWith('RECORD 000001 ')
        ->and($second->getOutput())->toStartWith('RECORD 000002 ')
        ->and($verify->getOutput())->toStartWith('VERIFIED 2 head=')
        ->and($broken->getExitCode())->toBe(1)
        ->and($recover->getOutput())->toContain('head log recovered to 000002')
        ->and($again->getOutput())->toStartWith('VERIFIED 2 head=')
        ->and($twice->getExitCode())->toBe(1);
});
