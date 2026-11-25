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

/** The facts the orchestrator verifies on the host (restore-evidence.sh), for a development install. */
const RDME_HOST_VERIFIED = 'backup_sha256,archive_custom_format,manifest_signature,manifest_not_revoked,archive_toc,owners,extensions,per_table_counts,catalog_shape,image_sha256,no_nic_by_construction,limits';

/** This host's name as the orchestrator and the signer write it. */
function rdmeHost(): string
{
    return substr((string) preg_replace('/[^A-Za-z0-9._-]/', '', (string) gethostname()), 0, 64);
}

/** A contract-2 evidence request (the operator side), consistent with the signer's policy; $over replaces values. */
function rdmeRequest(array $over = []): string
{
    $h = str_repeat('a', 64);
    $now = gmdate('Y-m-d\TH:i:s\Z');
    $fields = array_merge([
        'DMS_DRILL_EVIDENCE_RUN_ID' => 'dmsdrill-'.bin2hex(random_bytes(6)),
        'DMS_DRILL_EVIDENCE_DRILL_HOST' => rdmeHost(),
        'DMS_DRILL_EVIDENCE_STARTED_AT' => $now,
        'DMS_DRILL_EVIDENCE_COMPLETED_AT' => $now,
        'DMS_DRILL_EVIDENCE_BACKUP_SHA256' => $h,
        'DMS_DRILL_EVIDENCE_BACKUP_BYTES' => '4304',
        'DMS_DRILL_EVIDENCE_DIGEST_SOURCE' => 'expected-sha256',
        'DMS_DRILL_EVIDENCE_MANIFEST_ID' => 'manifest-0001',
        'DMS_DRILL_EVIDENCE_MANIFEST_SHA256' => $h,
        'DMS_DRILL_EVIDENCE_IMAGE_SHA256' => $h,
        'DMS_DRILL_EVIDENCE_LIMITS' => 'vcpu=1,memory_mib=2048,disk_gib=10,minutes=30,tasks=64',
        'DMS_DRILL_EVIDENCE_HOST_VERIFIED' => RDME_HOST_VERIFIED,
        'DMS_DRILL_EVIDENCE_EXPECTED' => 'tables=2,rows=6,per_table_digest='.$h.',catalog_shape=1:2:0:2:0:0:1',
        'DMS_DRILL_EVIDENCE_GUEST_ASSERTED' => 'restore=ok,tables=2,per_table_digest='.$h.',catalog_shape=1:2:0:2:0:0:1,user_code=0,user_code_executed=0,fidelity=0:2:1:0,vm_ifaces=lo',
        'DMS_DRILL_EVIDENCE_DECISION' => 'GO',
    ], $over);

    return collect($fields)->filter(fn ($v) => $v !== null)->map(fn (string $v, string $k): string => "{$k}={$v}")->implode("\n")."\n";
}

/**
 * Installs the signer entrypoint and its two libraries into a fresh directory, as a drill host would (P4V-7: the
 * signer refuses to run from a directory another identity can write, such as a group-writable checkout).
 */
function rdmeInstallSigner(): string
{
    $root = sys_get_temp_dir().'/rdme-inst-'.bin2hex(random_bytes(6));
    mkdir("{$root}/signer/lib", 0755, true);
    chmod($root, 0755);
    chmod("{$root}/signer", 0755);
    chmod("{$root}/signer/lib", 0755);
    copy(base_path('scripts/restore-drill-evidence-signer.sh'), "{$root}/signer/restore-drill-evidence-signer.sh");
    chmod("{$root}/signer/restore-drill-evidence-signer.sh", 0755);
    foreach (['trusted-path.sh', 'restore-evidence.sh'] as $lib) {
        copy(base_path("scripts/lib/{$lib}"), "{$root}/signer/lib/{$lib}");
        chmod("{$root}/signer/lib/{$lib}", 0644);
    }

    return "{$root}/signer/restore-drill-evidence-signer.sh";
}

function rdmeSignerConf(string $dir, string $extra = ''): void
{
    file_put_contents("{$dir}/signer.conf", "DMS_SIGNER_KEY={$dir}/drill\nDMS_SIGNER_PRINCIPAL=drill-host@dms\nDMS_SIGNER_ANCHOR={$dir}/evidence-anchor\nDMS_SIGNER_STORE={$dir}/store\nDMS_SIGNER_HEAD_LOG={$dir}/head.log\nDMS_SIGNER_ALLOWED_CALLERS=drillop\nDMS_SIGNER_IMAGE_SHA256=".str_repeat('a', 64)."\n"
        .($extra === '' ? "DMS_SIGNER_PROFILE=development\nDMS_SIGNER_WITNESS_USER=none\nDMS_SIGNER_WITNESS_COMMAND=none\nDMS_SIGNER_ORCHESTRATOR_DIR=none\nDMS_SIGNER_QEMU_LAUNCHER=none\n" : $extra));
    chmod("{$dir}/signer.conf", 0600);
    chmod("{$dir}/evidence-anchor", 0600);
}

/** Publishes $count records through the signer library (the signer side) into a fresh store with a head log. */
function rdmeStore(int $count): array
{
    $dir = rdmeDir();
    $store = "{$dir}/store";
    mkdir($store, 0700);
    for ($i = 0; $i < $count; $i++) {
        file_put_contents("{$dir}/req", rdmeRequest());
        $run = rdmeSh('dms_evidence_request_parse "$Q" && dms_evidence_publish "$S" "$K" drill-host@dms operator "$H" "$A" unverified:development', ['Q' => "{$dir}/req", 'S' => $store, 'K' => "{$dir}/drill", 'H' => "{$dir}/head.log", 'A' => "{$dir}/evidence-anchor"]);
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
        ->and($first)->toContain("DMS_DRILL_EVIDENCE_CONTRACT=3\nDMS_DRILL_EVIDENCE_SEQ=000001\nDMS_DRILL_EVIDENCE_PREV_SHA256=genesis\n")
        ->and($first)->toContain("DMS_DRILL_EVIDENCE_SIGNER=drill-host@dms\nDMS_DRILL_EVIDENCE_CALLER=operator\nDMS_DRILL_EVIDENCE_INSTALL=unverified:development\n")
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
    $run = rdmeSh('dms_evidence_request_parse "$Q" && dms_evidence_publish "$S" "$K" drill-host@dms operator "$H" "$A" unverified:development', ['Q' => "{$dir}/req", 'S' => $store, 'K' => "{$dir}/drill", 'H' => "{$dir}/head.log", 'A' => "{$dir}/evidence-anchor"]);
    $entries = array_values(array_diff(scandir($store), ['.', '..']));
    rdmeCleanup($dir);

    expect($run['exit'])->toBe(1)
        ->and($run['out'])->toContain($message)
        ->and($entries)->toBe(['000001']);
})->with([
    ['replayed run id', 'is already recorded (replay refused)'],
    ['missing key', 'request keys differ from contract 3'],
    ['signer key smuggled in', 'request keys differ from contract 3'],
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
    rdmeSignerConf($dir);
    $signerPath = rdmeInstallSigner();
    $uid = (string) getmyuid();
    $env = match ($case) {
        'called directly (no sudo)' => [],
        'sudo from the same identity' => ['SUDO_UID' => $uid, 'SUDO_USER' => 'drillop'],
        'caller not on the allowlist' => ['SUDO_UID' => (string) ((int) $uid + 1), 'SUDO_USER' => 'intruder'],
    };
    $process = new Process(['bash', $signerPath, 'sign'], null, array_merge(['PATH' => getenv('PATH'), 'DMS_SIGNER_CONF' => "{$dir}/signer.conf"], $env), rdmeRequest());
    $process->run();
    $entries = array_values(array_diff(scandir("{$dir}/store"), ['.', '..', '.lock']));
    rdmeCleanup($dir);
    rdmeCleanup(dirname($signerPath, 2));

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
    rdmeSignerConf($dir);
    $signerPath = rdmeInstallSigner();
    // The real separation (a different uid through sudo) runs in the system-mode CI gate; here SUDO_* stand for it.
    $sudo = ['PATH' => getenv('PATH'), 'DMS_SIGNER_CONF' => "{$dir}/signer.conf", 'SUDO_UID' => (string) (getmyuid() + 1), 'SUDO_USER' => 'drillop'];
    $signer = fn (string $mode, string $in = '') => tap(new Process(['bash', $signerPath, $mode], null, $sudo, $in), fn ($p) => $p->run());
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
    rdmeCleanup(dirname($signerPath, 2));

    expect($first->getOutput())->toStartWith('RECORD 000001 ')
        ->and($second->getOutput())->toStartWith('RECORD 000002 ')
        ->and($verify->getOutput())->toStartWith('VERIFIED 2 head=')
        ->and($broken->getExitCode())->toBe(1)
        ->and($recover->getOutput())->toContain('head log recovered to 000002')
        ->and($again->getOutput())->toStartWith('VERIFIED 2 head=')
        ->and($twice->getExitCode())->toBe(1);
});

/** Runs the installed signer's sign mode as an allowed sudo caller would; $prefix runs before it in the same shell. */
function rdmeSign(string $dir, string $signerPath, string $request, string $prefix = ''): array
{
    $env = ['PATH' => getenv('PATH'), 'DMS_SIGNER_CONF' => "{$dir}/signer.conf", 'SUDO_UID' => (string) (getmyuid() + 1), 'SUDO_USER' => 'drillop', 'S' => $signerPath];
    $process = new Process(['bash', '-c', $prefix.' exec bash "$S" sign'], null, $env, $request);
    $process->setTimeout(60);
    $process->run();

    return ['exit' => (int) $process->getExitCode(), 'out' => trim($process->getOutput().$process->getErrorOutput())];
}

it('never signs a contradictory or invented record: the signer checks the request against itself (P4V-4, P4P-5)', function (array $over, string $message) {
    $dir = rdmeDir();
    mkdir("{$dir}/store", 0700);
    rdmeSignerConf($dir);
    $signerPath = rdmeInstallSigner();
    $run = rdmeSign($dir, $signerPath, rdmeRequest($over));
    $entries = array_values(array_diff(scandir("{$dir}/store"), ['.', '..', '.lock']));
    rdmeCleanup($dir);
    rdmeCleanup(dirname($signerPath, 2));

    expect($run['exit'])->toBe(1, $run['out'])
        ->and($run['out'])->toContain($message)
        ->and($entries)->toBe([]);
})->with(function () {
    $h = str_repeat('a', 64);
    $guest = fn (string $replace, string $with) => str_replace($replace, $with, 'restore=ok,tables=2,per_table_digest='.$h.',catalog_shape=1:2:0:2:0:0:1,user_code=0,user_code_executed=0,fidelity=0:2:1:0,vm_ifaces=lo');

    return [
        'GO whose guest digest differs' => [['DMS_DRILL_EVIDENCE_GUEST_ASSERTED' => $guest('per_table_digest='.$h, 'per_table_digest='.str_repeat('b', 64))], 'guest per_table_digest differs'],
        'GO without a restore' => [['DMS_DRILL_EVIDENCE_GUEST_ASSERTED' => $guest('restore=ok', 'restore=none')], 'a GO without restore=ok'],
        'GO with another catalog shape' => [['DMS_DRILL_EVIDENCE_GUEST_ASSERTED' => $guest('catalog_shape=1:2:0:2:0:0:1', 'catalog_shape=0:2:0:2:0:0:1')], 'guest catalog_shape differs'],
        'GO with user code' => [['DMS_DRILL_EVIDENCE_GUEST_ASSERTED' => $guest('user_code_executed=0', 'user_code_executed=3')], 'a GO with user code on a restore path'],
        'GO from a VM with a NIC' => [['DMS_DRILL_EVIDENCE_GUEST_ASSERTED' => $guest('vm_ifaces=lo', 'vm_ifaces=lo:eth0')], 'a GO from a VM with a network interface'],
        'other limits' => [['DMS_DRILL_EVIDENCE_LIMITS' => 'vcpu=64,memory_mib=2048,disk_gib=10,minutes=30,tasks=64'], 'LIMITS are not the D6 limits'],
        'invented host-verified facts' => [['DMS_DRILL_EVIDENCE_HOST_VERIFIED' => 'nothing'], 'HOST_VERIFIED is not the fixed fact list'],
        'another drill host' => [['DMS_DRILL_EVIDENCE_DRILL_HOST' => 'elsewhere'], "DRILL_HOST is not this signer's host"],
        'an image the signer does not pin' => [['DMS_DRILL_EVIDENCE_IMAGE_SHA256' => str_repeat('c', 64)], 'is not a drill image pinned'],
        'completed before it started' => [['DMS_DRILL_EVIDENCE_STARTED_AT' => '2030-01-01T00:00:00Z', 'DMS_DRILL_EVIDENCE_COMPLETED_AT' => '2001-01-01T00:00:00Z'], 'out of order or not recent'],
        'a drill from long ago' => [['DMS_DRILL_EVIDENCE_STARTED_AT' => '2001-01-01T00:00:00Z', 'DMS_DRILL_EVIDENCE_COMPLETED_AT' => '2001-01-01T00:10:00Z'], 'out of order or not recent'],
        'a duplicated, contradictory guest key (P5-3)' => [['DMS_DRILL_EVIDENCE_GUEST_ASSERTED' => $guest('vm_ifaces=lo', 'vm_ifaces=lo,restore=failed,user_code=7')], 'GUEST_ASSERTED is not the fixed key list'],
        'an extra expected key (P5-3)' => [['DMS_DRILL_EVIDENCE_EXPECTED' => 'tables=2,rows=6,per_table_digest='.$h.',catalog_shape=1:2:0:2:0:0:1,tables=999'], 'EXPECTED is not the fixed key list'],
    ];
});

it('still records a FAIL whose guest facts differ from the expectation: a failure is evidence too', function () {
    $dir = rdmeDir();
    mkdir("{$dir}/store", 0700);
    rdmeSignerConf($dir);
    $signerPath = rdmeInstallSigner();
    $run = rdmeSign($dir, $signerPath, rdmeRequest(['DMS_DRILL_EVIDENCE_DECISION' => 'FAIL:per_table_rows_mismatch', 'DMS_DRILL_EVIDENCE_GUEST_ASSERTED' => 'restore=ok,tables=1,per_table_digest='.str_repeat('b', 64).',catalog_shape=1:1:0:1:0:0:1,user_code=0,user_code_executed=0,fidelity=0:1:1:0,vm_ifaces=lo']));
    rdmeCleanup($dir);
    rdmeCleanup(dirname($signerPath, 2));

    expect($run['exit'])->toBe(0, $run['out'])
        ->and($run['out'])->toStartWith('RECORD 000001 ');
});

it('refuses to sign under a file-size limit, with an exposed key, from an unsafe install, or with a relative head log (P4V-5, P4V-7)', function (string $case, int $exit, string $message) {
    $dir = rdmeDir();
    mkdir("{$dir}/store", 0700);
    rdmeSignerConf($dir);
    $signerPath = rdmeInstallSigner();
    $prefix = '';
    match ($case) {
        'file-size limit' => $prefix = "trap '' XFSZ; ulimit -f 1;",
        'key readable by others' => chmod("{$dir}/drill", 0644),
        'install writable by others' => chmod(dirname($signerPath), 0777),
        'library writable by others' => chmod(dirname($signerPath).'/lib/restore-evidence.sh', 0666),
        'relative head log' => null,
    };
    if ($case === 'relative head log') {
        file_put_contents("{$dir}/signer.conf", str_replace("DMS_SIGNER_HEAD_LOG={$dir}/head.log", 'DMS_SIGNER_HEAD_LOG=head.log', (string) file_get_contents("{$dir}/signer.conf")));
    }
    $run = rdmeSign($dir, $signerPath, rdmeRequest(), $prefix);
    $entries = array_values(array_diff(scandir("{$dir}/store"), ['.', '..', '.lock']));
    chmod(dirname($signerPath), 0755);
    rdmeCleanup($dir);
    rdmeCleanup(dirname($signerPath, 2));

    expect($run['exit'])->toBe($exit, $run['out'])
        ->and($run['out'])->toContain($message)
        ->and($entries)->toBe([]);
})->with([
    ['file-size limit', 1, 'a file-size limit is set'],
    ['key readable by others', 2, 'the signing key must be owned by the signer with mode 0600 or 0400'],
    ['install writable by others', 2, 'is not owned by root or the signer, or is writable by another identity'],
    ['library writable by others', 2, 'is unsafe'],
    ['relative head log', 2, 'the head log path is not canonical and absolute'],
]);

it('publishes the head log atomically and removes a crashed staging directory under the lock (P4V-5)', function () {
    [$dir, $store] = rdmeStore(1);
    mkdir("{$store}/.stage-000002-abcdef", 0700);
    file_put_contents("{$store}/.stage-000002-abcdef/record", "half written\n");
    file_put_contents("{$dir}/req", rdmeRequest());
    $run = rdmeSh('dms_evidence_request_parse "$Q" && dms_evidence_publish "$S" "$K" drill-host@dms operator "$H" "$A" unverified:development', ['Q' => "{$dir}/req", 'S' => $store, 'K' => "{$dir}/drill", 'H' => "{$dir}/head.log", 'A' => "{$dir}/evidence-anchor"]);
    $entries = array_values(array_diff(scandir($store), ['.', '..']));
    $leftovers = glob("{$dir}/head.log.*");
    $evidence = (string) file_get_contents(base_path('scripts/lib/restore-evidence.sh'));
    rdmeCleanup($dir);

    expect($run['out'])->toStartWith('RECORD 000002 ', $run['out'].$run['err'])
        ->and($entries)->toBe(['000001', '000002'])
        ->and($leftovers)->toBe([])
        ->and($evidence)->toContain('sync -- "$tmp"')
        ->and($evidence)->toContain('mv -f -- "$tmp" "$headlog"')
        ->and($evidence)->toContain('the written record differs from the built record')
        // P5-5: record, signature and staging directory are synced before the rename, the store directory after.
        ->and($evidence)->toContain('sync -- "${stage}/record" "${stage}/record.sig" "$stage"')
        ->and($evidence)->toContain('sync -- "$store"');
});

/** A refused or aborted drill's request: no host-verified facts, no guest facts, `none` for what was never known. */
function rdmeRefusal(string $decision, array $over = []): string
{
    return rdmeRequest(array_merge([
        'DMS_DRILL_EVIDENCE_MANIFEST_ID' => 'none', 'DMS_DRILL_EVIDENCE_MANIFEST_SHA256' => 'none', 'DMS_DRILL_EVIDENCE_IMAGE_SHA256' => 'none',
        'DMS_DRILL_EVIDENCE_BACKUP_BYTES' => '0', 'DMS_DRILL_EVIDENCE_HOST_VERIFIED' => 'none', 'DMS_DRILL_EVIDENCE_EXPECTED' => 'none',
        'DMS_DRILL_EVIDENCE_GUEST_ASSERTED' => 'none', 'DMS_DRILL_EVIDENCE_DECISION' => $decision,
    ], $over));
}

it('records refused and aborted drills as signed non-GO events, asserting nothing about a restore (Phase 3)', function (string $request, int $exit, string $message) {
    $dir = rdmeDir();
    mkdir("{$dir}/store", 0700);
    rdmeSignerConf($dir);
    $signerPath = rdmeInstallSigner();
    $run = rdmeSign($dir, $signerPath, $request);
    $record = (string) @file_get_contents("{$dir}/store/000001/record");
    rdmeCleanup($dir);
    rdmeCleanup(dirname($signerPath, 2));

    expect($run['exit'])->toBe($exit, $run['out'])
        ->and($run['out'])->toContain($message);
    if ($exit === 0) {
        expect($record)->toContain('DMS_DRILL_EVIDENCE_INSTALL=unverified:development')
            ->and($record)->toMatch('/DMS_DRILL_EVIDENCE_DECISION=(REFUSED|ABORTED):[a-z_]+/');
    }
})->with(function () {
    $h = str_repeat('a', 64);

    return [
        'a refusal at the manifest stage' => [rdmeRefusal('REFUSED:manifest'), 0, 'RECORD 000001 '],
        'an abort during the VM' => [rdmeRefusal('ABORTED:vm', ['DMS_DRILL_EVIDENCE_IMAGE_SHA256' => $h, 'DMS_DRILL_EVIDENCE_EXPECTED' => 'tables=2,rows=6,per_table_digest='.$h.',catalog_shape=1:2:0:2:0:0:1']), 0, 'RECORD 000001 '],
        'a refusal that claims host-verified facts' => [rdmeRefusal('REFUSED:manifest', ['DMS_DRILL_EVIDENCE_HOST_VERIFIED' => RDME_HOST_VERIFIED]), 1, 'claims host-verified facts'],
        'an abort that carries guest facts' => [rdmeRefusal('ABORTED:vm', ['DMS_DRILL_EVIDENCE_GUEST_ASSERTED' => 'restore=ok,tables=2,per_table_digest='.$h.',catalog_shape=1:2:0:2:0:0:1,user_code=0,user_code_executed=0,fidelity=0:2:1:0,vm_ifaces=lo']), 1, 'carries guest facts'],
        'a refusal naming an image the signer does not pin' => [rdmeRefusal('REFUSED:vm', ['DMS_DRILL_EVIDENCE_IMAGE_SHA256' => str_repeat('c', 64)]), 1, 'is not a drill image pinned'],
        'a GO without a manifest' => [rdmeRequest(['DMS_DRILL_EVIDENCE_MANIFEST_SHA256' => 'none']), 1, 'a GO record without MANIFEST_SHA256'],
        'a FAIL without an image' => [rdmeRequest(['DMS_DRILL_EVIDENCE_DECISION' => 'FAIL:vm_exit_1', 'DMS_DRILL_EVIDENCE_IMAGE_SHA256' => 'none']), 1, 'a FAIL record without IMAGE_SHA256'],
    ];
});

it('runs the drill-host profile only from a root-owned configuration (Phase 3)', function () {
    $dir = rdmeDir();
    mkdir("{$dir}/store", 0700);
    rdmeSignerConf($dir, "DMS_SIGNER_PROFILE=drill-host\nDMS_SIGNER_WITNESS_USER=nobody\nDMS_SIGNER_WITNESS_COMMAND=/bin/true\nDMS_SIGNER_ORCHESTRATOR_DIR=/nonexistent\nDMS_SIGNER_QEMU_LAUNCHER=/nonexistent\n");
    $signerPath = rdmeInstallSigner();
    $run = rdmeSign($dir, $signerPath, rdmeRequest());
    rdmeCleanup($dir);
    rdmeCleanup(dirname($signerPath, 2));
    $code = (string) file_get_contents(base_path('scripts/restore-drill-evidence-signer.sh'));

    expect($run['exit'])->toBe(2, $run['out'])
        ->and($run['out'])->toContain('a drill-host configuration must be owned by root')
        // The signer verifies the install itself (never the caller's word) and refuses a GO from anything else.
        ->and($code)->toContain('echo "root:$(printf \'%s\n\' "${lines[@]}" | LC_ALL=C sort | sha256sum | cut -d\' \' -f1)"')
        ->and($code)->toContain('a GO from an install the signer could not verify');
});

/** Installs the witness (and its library) into a fresh directory and writes its configuration and log directory. */
function rdmeWitness(): array
{
    $root = sys_get_temp_dir().'/rdme-wit-'.bin2hex(random_bytes(6));
    foreach (['', '/witness', '/witness/lib', '/state'] as $d) {
        mkdir($root.$d, $d === '/state' ? 0700 : 0755, true);
        chmod($root.$d, $d === '/state' ? 0700 : 0755);
    }
    copy(base_path('scripts/restore-drill-evidence-witness.sh'), "{$root}/witness/restore-drill-evidence-witness.sh");
    chmod("{$root}/witness/restore-drill-evidence-witness.sh", 0755);
    copy(base_path('scripts/lib/trusted-path.sh'), "{$root}/witness/lib/trusted-path.sh");
    chmod("{$root}/witness/lib/trusted-path.sh", 0644);
    $conf = sys_get_temp_dir().'/rdme-witconf-'.bin2hex(random_bytes(6));
    file_put_contents($conf, "DMS_WITNESS_LOG={$root}/state/heads.log\nDMS_WITNESS_APPEND_CALLERS=signer\nDMS_WITNESS_SHOW_CALLERS=signer drillop\n");
    chmod($conf, 0600);

    return [$root, $conf];
}

function rdmeWitnessRun(array $w, string $caller, string ...$args): array
{
    $process = new Process(array_merge(['bash', "{$w[0]}/witness/restore-drill-evidence-witness.sh"], $args), null, [
        'PATH' => getenv('PATH'), 'DMS_WITNESS_CONF' => $w[1], 'SUDO_UID' => (string) (getmyuid() + 1), 'SUDO_USER' => $caller,
    ]);
    $process->run();

    return ['exit' => (int) $process->getExitCode(), 'out' => trim($process->getOutput())];
}

it('keeps an append-only, gapless witness log: next sequence only, exactly once, appended by the signer alone (Phase 3)', function () {
    $w = rdmeWitness();
    $a = str_repeat('a', 64);
    $b = str_repeat('b', 64);
    $first = rdmeWitnessRun($w, 'signer', 'append', '000001', $a);
    $replay = rdmeWitnessRun($w, 'signer', 'append', '000001', $b);
    $gap = rdmeWitnessRun($w, 'signer', 'append', '000003', $b);
    $operator = rdmeWitnessRun($w, 'drillop', 'append', '000002', $b);
    $second = rdmeWitnessRun($w, 'signer', 'append', '000002', $b);
    $show = rdmeWitnessRun($w, 'drillop', 'show');
    $stranger = rdmeWitnessRun($w, 'intruder', 'show');
    file_put_contents("{$w[0]}/state/heads.log", "000001 {$a}\n000003 {$b}\n");
    $broken = rdmeWitnessRun($w, 'drillop', 'show');
    rdmeCleanup($w[0]);
    @unlink($w[1]);

    expect($first['out'])->toBe("WITNESSED 000001 {$a}")
        ->and($replay['out'])->toContain('000001 is not the next sequence number (000002)')
        ->and($gap['out'])->toContain('000003 is not the next sequence number (000002)')
        ->and($operator['out'])->toContain('caller may not append')
        ->and($second['out'])->toBe("WITNESSED 000002 {$b}")
        ->and($show['out'])->toBe("000001 {$a}\n000002 {$b}")
        ->and($stranger['out'])->toContain('caller may not read')
        ->and($broken['out'])->toContain('malformed or has a gap');
});

it('detects rollback, truncation, rewrite and unwitnessed publication against the witness log (Phase 3)', function (string $case, string $message) {
    [$dir, $store] = rdmeStore(3);
    $shas = array_map(fn (int $i): string => hash_file('sha256', sprintf('%s/%06d/record', $store, $i)), [1, 2, 3]);
    $witness = "{$dir}/witness.log";
    file_put_contents($witness, "000001 {$shas[0]}\n000002 {$shas[1]}\n000003 {$shas[2]}\n");
    match ($case) {
        'clean' => null,
        'store rolled back' => (function () use ($store, $dir, $shas) {
            (new Process(['rm', '-rf', '--', "{$store}/000003"]))->mustRun();
            file_put_contents("{$dir}/head.log", "000001 {$shas[0]}\n000002 {$shas[1]}\n");
        })(),
        'record rewritten and re-chained' => file_put_contents($witness, "000001 {$shas[0]}\n000002 ".str_repeat('e', 64)."\n000003 {$shas[2]}\n"),
        'record published without the witness' => file_put_contents($witness, "000001 {$shas[0]}\n000002 {$shas[1]}\n"),
    };
    $run = rdmeSh('dms_evidence_verify_store "$S" "$A" "$H" "$W"', ['S' => $store, 'A' => "{$dir}/evidence-anchor", 'H' => "{$dir}/head.log", 'W' => $witness]);
    rdmeCleanup($dir);

    expect($run['out'])->toContain($message);
})->with([
    ['clean', 'VERIFIED 3 head='],
    ['store rolled back', 'which the store no longer has (rollback or truncation)'],
    ['record rewritten and re-chained', 'record 000002 differs from the digest the witness recorded'],
    ['record published without the witness', 'a record was published without the witness'],
]);
