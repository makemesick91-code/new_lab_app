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

it('applies D-Q6-a and P5 to the dump against the manifest, never against a live production cluster', function (string $owner, string $r6, int $exit, string $message) {
    $dir = rdmeDir();
    $file = rdmeManifest($dir, ['DMS_ROLE_MANIFEST_R6_OWNER_ROLE' => $r6]);
    $dump = "{$dir}/dump.sql";
    file_put_contents($dump, "-- PostgreSQL database dump\nCREATE TABLE public.t (id int);\nALTER TABLE public.t OWNER TO {$owner};\nCOPY public.t (id) FROM stdin;\nALTER TABLE x OWNER TO not_a_statement;\n\\.\n");
    $run = rdmeSh('dms_restore_manifest_verify "$M" "$A" > /dev/null && dms_restore_manifest_dump_gate "$D"', ['M' => $file, 'A' => "{$dir}/anchor", 'D' => $dump]);
    rdmeCleanup($dir);

    expect($run['exit'])->toBe($exit)
        ->and($run['out'])->toContain($message);
})->with([
    'runtime-owned dump before R6' => ['app_runtime', 'none', 0, ''],
    'owner-owned dump after R6' => ['dms_owner', 'dms_owner', 0, ''],
    'role the manifest does not list' => ['ghost_role', 'none', 1, 'the manifest does not list them: ghost_role (D-Q6-a)'],
    'pre-R6 dump after R6' => ['app_runtime', 'dms_owner', 1, "the dump makes the runtime role 'app_runtime' an owner (a pre-R6 dump, P5)"],
]);

/** Writes $count signed evidence records into a fresh store and returns [dir, store]. */
function rdmeStore(int $count): array
{
    $dir = rdmeDir();
    $store = "{$dir}/store";
    mkdir($store, 0700);
    $script = <<<'SH'
        now=$(date -u +%Y-%m-%dT%H:%M:%SZ); h=$(printf 'a%.0s' {1..64})
        declare -A E=([DMS_DRILL_EVIDENCE_RUN_ID]=dmsdrill-0123456789ab [DMS_DRILL_EVIDENCE_DRILL_HOST]=drillhost
          [DMS_DRILL_EVIDENCE_SIGNER]=drill-host@dms [DMS_DRILL_EVIDENCE_STARTED_AT]=$now [DMS_DRILL_EVIDENCE_COMPLETED_AT]=$now
          [DMS_DRILL_EVIDENCE_BACKUP_SHA256]=$h [DMS_DRILL_EVIDENCE_BACKUP_BYTES]=100 [DMS_DRILL_EVIDENCE_DIGEST_SOURCE]=expected-sha256
          [DMS_DRILL_EVIDENCE_MANIFEST_ID]=manifest-0001 [DMS_DRILL_EVIDENCE_MANIFEST_SHA256]=$h [DMS_DRILL_EVIDENCE_IMAGE_SHA256]=$h
          [DMS_DRILL_EVIDENCE_LIMITS]=vcpu=1,memory_mib=2048 [DMS_DRILL_EVIDENCE_EXPECTED]=tables=2,rows=3
          [DMS_DRILL_EVIDENCE_OBSERVED]=tables=2,rows=3 [DMS_DRILL_EVIDENCE_FIDELITY]=0:3:1:2 [DMS_DRILL_EVIDENCE_DECISION]=GO)
        for i in $(seq 1 "$N"); do dms_evidence_write "$S" "$K" E > /dev/null || exit 1; done
        SH;
    $run = rdmeSh($script, ['S' => $store, 'K' => "{$dir}/drill", 'N' => (string) $count]);
    expect($run['exit'])->toBe(0, $run['err']);

    return [$dir, $store];
}

it('writes signed, chained evidence records that verify (D10)', function () {
    [$dir, $store] = rdmeStore(3);
    $run = rdmeSh('dms_evidence_verify_store "$S" "$A"', ['S' => $store, 'A' => "{$dir}/evidence-anchor"]);
    $first = (string) file_get_contents("{$store}/000001.evidence");
    $second = (string) file_get_contents("{$store}/000002.evidence");
    rdmeCleanup($dir);

    expect($run['exit'])->toBe(0)
        ->and($run['out'])->toStartWith('VERIFIED 3 head=')
        ->and($first)->toContain("DMS_DRILL_EVIDENCE_PREV_SHA256=genesis\n")
        ->and($second)->toContain('DMS_DRILL_EVIDENCE_PREV_SHA256='.hash('sha256', $first)."\n");
});

it('detects every change to the evidence store', function (string $case, string $message) {
    [$dir, $store] = rdmeStore(3);
    $head = hash_file('sha256', "{$store}/000003.evidence");
    $anchor = '';
    switch ($case) {
        case 'edited record':
            file_put_contents("{$store}/000002.evidence", str_replace('DECISION=GO', 'DECISION=FAIL:x', (string) file_get_contents("{$store}/000002.evidence")));
            break;
        case 'removed record':
            unlink("{$store}/000002.evidence");
            unlink("{$store}/000002.evidence.sig");
            break;
        case 'swapped records':
            foreach (['evidence', 'evidence.sig'] as $ext) {
                rename("{$store}/000002.{$ext}", "{$store}/tmp");
                rename("{$store}/000003.{$ext}", "{$store}/000002.{$ext}");
                rename("{$store}/tmp", "{$store}/000003.{$ext}");
            }
            break;
        case 're-signed by another key':
            unlink("{$store}/000003.evidence.sig");
            (new Process(['ssh-keygen', '-q', '-Y', 'sign', '-f', "{$dir}/evil", '-n', 'dms-restore-drill-evidence', "{$store}/000003.evidence"]))->mustRun();
            break;
        case 'newest record dropped, checked against the head':
            unlink("{$store}/000003.evidence");
            unlink("{$store}/000003.evidence.sig");
            $anchor = $head;
            break;
    }
    $run = rdmeSh('dms_evidence_verify_store "$S" "$A" "$H"', ['S' => $store, 'A' => "{$dir}/evidence-anchor", 'H' => $anchor]);
    rdmeCleanup($dir);

    expect($run['exit'])->toBe(1)
        ->and($run['out'])->toContain($message);
})->with([
    ['edited record', 'signature does not verify'],
    ['removed record', 'sequence gap'],
    ['swapped records', 'SEQ does not match its name'],
    ['re-signed by another key', 'signature does not verify'],
    ['newest record dropped, checked against the head', 'is not the expected head'],
]);

it('refuses to write evidence with an invalid value', function () {
    $dir = rdmeDir();
    mkdir("{$dir}/store", 0700);
    $run = rdmeSh('declare -A E=([DMS_DRILL_EVIDENCE_DECISION]="GO; reboot"); dms_evidence_write "$S" "$K" E', ['S' => "{$dir}/store", 'K' => "{$dir}/drill"]);
    $files = array_values(array_diff(scandir("{$dir}/store"), ['.', '..']));
    rdmeCleanup($dir);

    expect($run['exit'])->toBe(1)
        ->and($run['err'])->toContain('evidence: invalid value for')
        ->and($files)->toBe([]);
});
