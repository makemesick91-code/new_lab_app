<?php

use Symfony\Component\Process\Process;

/*
 * R6-B6 isolated restore drill — the disposable-VM transport and the host orchestrator (owner directives D1, D3, D6).
 *
 * Without a hypervisor: the input/output disk formats, the guest's refusals before it starts any unit, the host's
 * decision over the VM's output, the QEMU command line, and the orchestrator's fail-closed refusals. The VM boot
 * itself runs only in the dedicated real-sandbox CI gate on disposable infrastructure (D8).
 */

function rdvmSh(string $script, array $env = []): array
{
    $process = new Process(['bash', '-c', '. "$LIB/restore-integrity.sh"; . "$LIB/restore-sandbox.sh"; . "$LIB/restore-vm.sh"; '.$script], null, array_merge([
        'LIB' => base_path('scripts/lib'),
        'PATH' => getenv('PATH'),
    ], $env));
    $process->setTimeout(60);
    $process->run();

    return ['exit' => (int) $process->getExitCode(), 'out' => trim($process->getOutput()), 'err' => $process->getErrorOutput()];
}

function rdvmDir(): string
{
    $dir = sys_get_temp_dir().'/rdvm-'.bin2hex(random_bytes(6));
    mkdir($dir, 0700, true);

    return $dir;
}

const RDVM_BUILD = <<<'SH'
    printf 'SELECT 1;\n' > "$T/payload"
    declare -A IN=([run_id]=dmsdrill-0123456789ab [payload_bytes]=$(stat -c %s "$T/payload") [payload_sha256]=$(sha256sum "$T/payload" | cut -d' ' -f1)
      [roles]="app_runtime dms_owner" [locale]=C.UTF-8 [tmpfs_mib]=1024 [memory_mib]=1536 [tasks]=64 [timeout_seconds]=1500)
    dms_vm_input_build "$T/input.raw" "$T/payload" IN || exit 9

    SH;

it('builds an input disk without privileges and parses it back exactly (D1)', function () {
    $dir = rdvmDir();
    $run = rdvmSh(RDVM_BUILD.'dms_vm_input_parse "$T/input.raw" && for k in $DMS_VM_INPUT_KEYS; do echo "$k=${DMS_VM_INPUT[$k]}"; done; dms_vm_input_payload "$T/input.raw" "${DMS_VM_INPUT[payload_bytes]}"; echo; stat -c %s "$T/input.raw"', ['T' => $dir]);
    (new Process(['rm', '-rf', '--', $dir]))->run();

    expect($run['exit'])->toBe(0, $run['err'])
        ->and($run['out'])->toContain("run_id=dmsdrill-0123456789ab\npayload_bytes=10\n")
        ->and($run['out'])->toContain('roles=app_runtime dms_owner')
        ->and($run['out'])->toContain("SELECT 1;\n\n4608");
});

it('refuses an input header that is malformed, incomplete or carries an unknown or unsafe value', function (string $mutation) {
    $dir = rdvmDir();
    $run = rdvmSh(RDVM_BUILD.$mutation.'; dms_vm_input_parse "$T/input.raw"', ['T' => $dir]);
    (new Process(['rm', '-rf', '--', $dir]))->run();

    expect($run['exit'])->toBe(1);
})->with([
    'wrong magic' => ['printf "XMSDRILL" | dd of="$T/input.raw" conv=notrunc status=none'],
    'no END' => ['sed -i "s/^END$/XXX/" "$T/input.raw"'],
    'injected role' => ['sed -i "s/^roles=app_runtime dms_owner$/roles=app_runtime x;y/" "$T/input.raw"'],
    'unknown key' => ['sed -i "s/^locale=/lokale=/" "$T/input.raw"'],
    'duplicate key' => ['sed -i "s/^locale=C.UTF-8$/run_id=dmsdrill-0123456789ab/" "$T/input.raw"'],
]);

it('makes the guest refuse a payload whose digest differs, before it starts any unit', function () {
    $dir = rdvmDir();
    $run = rdvmSh(RDVM_BUILD.'printf X | dd of="$T/input.raw" bs=1 seek=4097 conv=notrunc status=none; head -c 65536 /dev/zero > "$T/output.raw"; bash "$G" "$T/input.raw" "$T/output.raw" user; echo "guest=$?"; dms_vm_output_parse "$T/output.raw" && echo "stage=${DMS_VM_OUTPUT[guest_stage]} run=${DMS_VM_OUTPUT[run_id]}"', [
        'T' => $dir, 'G' => base_path('scripts/restore-drill-vm-guest.sh'),
    ]);
    (new Process(['rm', '-rf', '--', $dir]))->run();

    expect($run['out'])->toContain('guest=1')
        ->and($run['out'])->toContain('stage=payload_digest_mismatch run=dmsdrill-0123456789ab');
});

it('parses the VM output strictly and decides only from facts the host can check', function (string $lines, int $qemu, string $decision) {
    $dir = rdvmDir();
    file_put_contents("{$dir}/lines", $lines);
    $run = rdvmSh('dms_vm_output_write "$T/output.raw" "$T/lines"; dms_vm_output_parse "$T/output.raw"; dms_vm_decide '.$qemu.' dmsdrill-0123456789ab '.str_repeat('a', 64).' 10 2 3', ['T' => $dir]);
    (new Process(['rm', '-rf', '--', $dir]))->run();

    expect($run['out'])->toBe($decision);
})->with(function () {
    $sha = str_repeat('a', 64);
    $good = "run_id=dmsdrill-0123456789ab\npayload_sha256={$sha}\nunit_exit=0\nfeed_exit=0\nresult_duplicates=0\nresult_rejected=0\nsandbox_profile=attested\nsandbox_bootstrap_superuser=nologin\nsandbox_result=GO\nsandbox_migrations_table=1\nsandbox_base_table_count=2\nsandbox_rows_total=3\nguest_stage=done\n";

    return [
        'all facts hold' => [$good, 0, 'GO'],
        'VM killed or crashed' => [$good, 124, 'FAIL:vm_exit_124'],
        'another run' => [str_replace('dmsdrill-0123456789ab', 'dmsdrill-ffffffffffff', $good), 0, 'FAIL:vm_run_id_mismatch'],
        'other payload' => [str_replace($sha, str_repeat('b', 64), $good), 0, 'FAIL:vm_payload_digest_mismatch'],
        'guest stopped early' => [str_replace('guest_stage=done', 'guest_stage=profile_build_failed', $good), 0, 'FAIL:guest_profile_build_failed'],
        'feeder cut short' => [str_replace('feed_exit=0', 'feed_exit=141', $good), 0, 'FAIL:feed_exit_141'],
        'rows lost' => [str_replace('sandbox_rows_total=3', 'sandbox_rows_total=1', $good), 0, 'FAIL:row_count_mismatch'],
        'forged duplicate' => [$good."sandbox_result=GO\n", 0, 'FAIL:vm_output_malformed'],
        'injected noise' => [$good."\x1b]0;owned\x07\n", 0, 'FAIL:vm_output_malformed'],
    ];
});

it('treats an output disk without the frame as no result at all', function () {
    $dir = rdvmDir();
    file_put_contents("{$dir}/output.raw", "garbage\nresult=GO\n");
    $run = rdvmSh('dms_vm_output_parse "$T/output.raw"; echo "frame=$?"', ['T' => $dir]);
    (new Process(['rm', '-rf', '--', $dir]))->run();

    expect($run['out'])->toBe('frame=1');
});

it('boots the VM with KVM, no network device, a read-only input disk, QEMU\'s own sandbox and the D6 size', function () {
    $run = rdvmSh('dms_vm_qemu_args /w/root.qcow2 /w/input.raw /w/output.raw 1 2048');
    $args = explode("\n", $run['out']);

    expect($args)->toContain('-nic')
        ->and($args[array_search('-nic', $args, true) + 1])->toBe('none')
        ->and($args[array_search('-smp', $args, true) + 1])->toBe('1')
        ->and($args[array_search('-m', $args, true) + 1])->toBe('2048')
        ->and($args)->toContain('-nodefaults')
        ->and($args)->toContain('-sandbox')
        ->and($args)->toContain('on,obsolete=deny,elevateprivileges=deny,spawn=deny,resourcecontrol=deny')
        ->and($args)->toContain('if=virtio,file=/w/input.raw,format=raw,readonly=on,serial=dmsdrill-input')
        ->and($run['out'])->not->toContain('netdev')
        ->and($run['out'])->not->toContain('virtfs')
        ->and($run['out'])->not->toContain('-chardev');
});

it('pins the orchestrator to the D6 limits, one drill at a time, and refuses on the production host (D1)', function () {
    $code = (string) file_get_contents(base_path('scripts/restore-drill-vm.sh'));

    expect($code)->toContain('VCPU=1; MEMORY_MIB=2048; DISK_GIB=10; MINUTES=30; TASKS=64')
        ->and($code)->toContain('flock -n "$lock_fd"')
        ->and($code)->toContain('timeout --kill-after=10 "$((MINUTES * 60))"')
        ->and($code)->toContain('[ "$(hostname)" != "$PROD_HOSTNAME" ]')
        ->and($code)->toContain('[ ! -e "$PROD_APP_DIR" ]')
        ->and($code)->toContain('dms_restore_manifest_verify "$MANIFEST"')
        ->and($code)->toContain('dms_evidence_write')
        // The configuration is parsed, never sourced; nothing connects to production PostgreSQL (D3).
        ->and($code)->not->toMatch('/^\s*(\.|source)\s+"?\$CONF/m')
        ->and($code)->not->toContain('psql')
        ->and(strpos($code, 'dms_restore_verify_copy "$STAGED"'))->toBeLessThan(strpos($code, 'dms_vm_input_build'));
});

function rdvmOrchestrate(string $dir, array $args, array $env = []): array
{
    $process = new Process(array_merge(['bash', base_path('scripts/restore-drill-vm.sh')], $args), null, array_merge([
        'PATH' => getenv('PATH'),
        'DMS_RESTORE_DRILL_CONF' => "{$dir}/drill.conf",
    ], $env));
    $process->setTimeout(60);
    $process->run();

    return ['exit' => (int) $process->getExitCode(), 'out' => $process->getOutput().$process->getErrorOutput()];
}

it('refuses before anything runs when its inputs or its environment are not trusted', function (string $case, string $message) {
    $dir = rdvmDir();
    file_put_contents("{$dir}/backup.sql", "-- PostgreSQL database dump\n");
    file_put_contents("{$dir}/manifest", "x\n");
    $conf = "DMS_DRILL_IMAGE={$dir}/image.qcow2\nDMS_DRILL_IMAGE_SHA256=".str_repeat('a', 64)."\nDMS_DRILL_MANIFEST_ANCHOR={$dir}/anchor\nDMS_DRILL_EVIDENCE_KEY={$dir}/key\nDMS_DRILL_EVIDENCE_SIGNER=drill-host@dms\nDMS_DRILL_EVIDENCE_STORE={$dir}/store\nDMS_DRILL_WORK_DIR={$dir}/work\nDMS_DRILL_LOCALE=C.UTF-8\nDMS_DRILL_QEMU={$dir}/no-qemu\nDMS_DRILL_QEMU_IMG={$dir}/no-qemu-img\n";
    foreach (['image.qcow2', 'anchor', 'key'] as $f) {
        file_put_contents("{$dir}/{$f}", "x\n");
        chmod("{$dir}/{$f}", 0600);
    }
    mkdir("{$dir}/store", 0700);
    mkdir("{$dir}/work", 0700);
    $args = ['--expected-sha256', hash('sha256', "-- PostgreSQL database dump\n"), '--manifest', "{$dir}/manifest", "{$dir}/backup.sql"];
    match ($case) {
        'no digest' => $args = ['--manifest', "{$dir}/manifest", "{$dir}/backup.sql"],
        'no manifest' => $args = ['--expected-sha256', str_repeat('a', 64), "{$dir}/backup.sql"],
        'missing configuration' => null,
        'configuration writable by others' => (function () use ($dir, $conf) {
            file_put_contents("{$dir}/drill.conf", $conf);
            chmod("{$dir}/drill.conf", 0666);
        })(),
        'configuration with shell code' => (function () use ($dir, $conf) {
            file_put_contents("{$dir}/drill.conf", $conf."DMS_DRILL_LOCALE=\$(touch {$dir}/pwned)\n");
            chmod("{$dir}/drill.conf", 0600);
        })(),
        'no hypervisor' => (function () use ($dir, $conf) {
            file_put_contents("{$dir}/drill.conf", $conf);
            chmod("{$dir}/drill.conf", 0600);
        })(),
    };
    $run = rdvmOrchestrate($dir, $args);
    $pwned = file_exists("{$dir}/pwned");
    $workLeft = array_values(array_diff(scandir("{$dir}/work"), ['.', '..', 'drill.lock']));
    (new Process(['rm', '-rf', '--', $dir]))->run();

    expect($run['exit'])->toBe(2, $run['out'])
        ->and($run['out'])->toContain('REFUSED:')
        ->and($run['out'])->toContain($message)
        ->and($run['out'])->toContain('Nothing ran.')
        ->and($pwned)->toBeFalse()
        ->and($workLeft)->toBe([]);
})->with([
    ['no digest', '--expected-sha256 must be the 64-hex digest'],
    ['no manifest', '--manifest <signed role manifest> is required (D3)'],
    ['missing configuration', 'drill configuration'],
    ['configuration writable by others', 'writable by group or others'],
    ['configuration with shell code', 'unexpected line in'],
    ['no hypervisor', 'DMS_DRILL_QEMU'],
]);

it('retires the in-place replay of the production-side drill script (finding A-1)', function () {
    $code = (string) file_get_contents(base_path('scripts/rollout-restore-drill.sh'));
    $body = implode("\n", array_filter(explode("\n", $code), fn (string $l): bool => ! str_starts_with(ltrim($l), '#')));

    expect($body)->toContain('exit 3')
        ->and($body)->not->toContain('-f -')
        ->and($body)->not->toContain('"$CREATEDB_BIN" -O')
        ->and($body)->not->toContain('"$DROPDB_BIN" --')
        ->and($body)->toContain('scripts/restore-drill-vm.sh --expected-sha256')
        ->and(trim((string) collect(explode("\n", trim($body)))->last()))->toBe('exit 3');
});
