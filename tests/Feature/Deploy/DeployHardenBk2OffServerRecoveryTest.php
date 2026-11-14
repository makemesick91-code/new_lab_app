<?php

uses()->group('Deploy', 'ReleaseSafety', 'Backup');

/**
 * DMS-BK2-BACKUP-RELIABILITY-WSL2-RECOVERY-1 — rule 230, BK-2 off-server
 * workstation recovery.
 *
 * The BK-2 pull and its custodian tooling run on the owner's workstation from a
 * private tree, deliberately outside this PUBLIC repository (that tree carries
 * its own fixture test harness). What this repository carries is the contract
 * the deploy preflight relies on: the identity states, the result semantics,
 * the custodian rules and the operator procedure. This suite keeps those
 * documents complete and consistent, and keeps the backup HDD's hardware
 * identifiers out of the public tree. It checks shapes only and never contains
 * a real value. The pull account and the escrow file names are kept out by
 * review instead: a guard would have to publish them in order to detect them.
 *
 * DMS-BK2-MANUAL-ONLY-1 — rule 232 (owner directive, 2026-11-14): BK-2 is
 * manual-only and no longer a deploy gate. It supersedes rule 230 §9; the
 * deploy's own verified dump stays the mandatory pre-deploy backup.
 */
function bk2r230Read(string $relative): string
{
    $path = base_path($relative);
    expect(is_file($path))->toBeTrue("{$relative} is missing");

    return (string) file_get_contents($path);
}

const BK2R230_RULE = '.cursor/rules/230-bk2-offserver-workstation-recovery.mdc';
const BK2R230_RUNBOOK = 'docs/runbooks/bk2-offserver-workstation-recovery.md';
const BK2R232_RULE = '.cursor/rules/232-bk2-manual-only-not-a-deploy-gate.mdc';
const BK2R230_STATES = [
    'OK', 'ABSENT', 'LOCKED', 'NOT_MOUNTED', 'NOT_IN_PID1_NS',
    'MISMATCH', 'WRONG_MAPPING', 'DUPLICATE_MOUNT', 'READ_ONLY', 'UNVERIFIABLE',
];

it('is declared in the critical gate so the contract stays enforced', function () {
    expect(config('ci_runner.critical_gate_mandatory_suites'))
        ->toContain('tests/Feature/Deploy/DeployHardenBk2OffServerRecoveryTest.php');
});

it('keeps the canonical rule, the runbook, the domain index and the deploy runbook in step', function () {
    $rule = bk2r230Read(BK2R230_RULE);
    expect($rule)->toContain('**Status:** ACTIVE')
        ->and($rule)->toContain('docs/sprints/dms-bk2-backup-reliability-wsl2-recovery-1.md')
        ->and($rule)->toContain(BK2R230_RUNBOOK);

    bk2r230Read(BK2R230_RUNBOOK);
    bk2r230Read('docs/sprints/dms-bk2-backup-reliability-wsl2-recovery-1.md');

    $domain = bk2r230Read('.claude/rules/deploy-infra.md');
    expect($domain)->toContain('**AIR-DEP-36**')
        ->and($domain)->toContain('`'.BK2R230_RULE.'`');

    expect(bk2r230Read('docs/00-INDEX.md'))->toContain('(runbooks/bk2-offserver-workstation-recovery.md)');

    $deploy = bk2r230Read('docs/runbooks/production-host-identity.md');
    expect($deploy)->toContain('**Check backup protection (read-only):**')
        ->and($deploy)->toContain(BK2R230_RUNBOOK);
});

it('keeps BK-2 out of the deploy gate while the deploy\'s own dump stays mandatory (rule 232)', function () {
    $rule = bk2r230Read(BK2R232_RULE);
    expect($rule)->toContain('**Status:** ACTIVE')
        ->and($rule)->toContain('alwaysApply: true')
        ->and($rule)->toContain('docs/sprints/dms-bk2-manual-only-1.md')
        ->and($rule)->toContain('BK2_POLICY=MANUAL_ONLY')
        ->and($rule)->toContain('BK2_REQUIRED_FOR_GO_GATE=FALSE')
        ->and($rule)->toContain('BK2_EXPLICIT_OWNER_AUTHORIZATION=REQUIRED')
        ->and($rule)->toContain('**BLOCKED**')
        ->and($rule)->toContain('`SKIPPED — DMS-BK2-MANUAL-ONLY-1 (Owner Directive)`');

    bk2r230Read('docs/sprints/dms-bk2-manual-only-1.md');

    $domain = bk2r230Read('.claude/rules/deploy-infra.md');
    expect($domain)->toContain('**AIR-DEP-38**')
        ->and($domain)->toContain('`'.BK2R232_RULE.'`');

    // The deploy runbook names the runner's dump as the required backup and no
    // longer waits on BK-2, in §6b step 3 or through §6d's inherited list.
    $deploy = bk2r230Read('docs/runbooks/production-host-identity.md');
    expect($deploy)->toContain('that dump is the required pre-deploy backup')
        ->and($deploy)->toContain('(rule 232, owner directive `DMS-BK2-MANUAL-ONLY-1`, 2026-11-14)')
        ->and($deploy)->not->toContain('report both the on-server and the off-server copy healthy')
        ->and($deploy)->not->toContain('(BK-2, backup window');

    expect(bk2r230Read(BK2R230_RULE))->toContain('**SUPERSEDED by rule 232**')
        ->and(bk2r230Read(BK2R230_RULE))->not->toContain('is unchanged as the gate');
    expect(bk2r230Read(BK2R230_RUNBOOK))->toContain('BK-2 is not a deploy gate (rule 232')
        ->and(bk2r230Read(BK2R230_RUNBOOK))->not->toContain('stays the gate');

    // The mandatory backup is real: the deploy aborts on a failed dump, and no
    // deploy script consults BK-2.
    $deployScript = bk2r230Read('scripts/deploy-vps.sh');
    expect($deployScript)->toContain("set -euo pipefail\n")
        ->and($deployScript)->toContain('pre_auto_deploy_')
        ->and($deployScript)->toContain('dms_write_private_dump');
    foreach (['scripts/deploy-vps.sh', 'scripts/deploy-vps-runner.sh'] as $script) {
        expect(strtolower(bk2r230Read($script)))->not->toContain('bk2');
    }
});

it('documents every HDD identity state, and only the four custodian-recoverable ones as recoverable', function () {
    $rule = bk2r230Read(BK2R230_RULE);
    $runbook = bk2r230Read(BK2R230_RUNBOOK);

    foreach (BK2R230_STATES as $state) {
        expect($rule)->toContain("`{$state}`")
            ->and($runbook)->toContain("`{$state}`");
    }

    foreach (['ABSENT', 'LOCKED', 'NOT_MOUNTED', 'NOT_IN_PID1_NS'] as $recoverable) {
        expect($rule)->toMatch('/\| `'.$recoverable.'` \| 2 \|/');
    }
    foreach (['MISMATCH', 'WRONG_MAPPING', 'DUPLICATE_MOUNT', 'READ_ONLY'] as $failClosed) {
        expect($rule)->toMatch('/\| `'.$failClosed.'` \| 3 \|/');
    }
    expect($rule)->toMatch('/\| `UNVERIFIABLE` \| 4 \|/')
        ->and($rule)->toContain('/proc/1/mountinfo');
});

it('never lets an exit code alone claim the off-server copy', function () {
    $rule = bk2r230Read(BK2R230_RULE);

    expect($rule)->toMatch('/\| `OK` \| 0 \|/')
        ->and($rule)->toMatch('/\| `DEGRADED` \| 75 \|/')
        ->and($rule)->toMatch('/\| `FAIL` \| 1 \|/')
        ->and($rule)->toContain('`UNVERIFIED`')
        ->and($rule)->toContain('server_OFF_SERVER')
        ->and(bk2r230Read(BK2R230_RUNBOOK))->toContain("systemd's \"success\" is not the verdict");
});

it('keeps the custodian contract: interactive only, passphrase only at the cryptsetup prompt, mount in PID 1', function () {
    $rule = bk2r230Read(BK2R230_RULE);
    $runbook = bk2r230Read(BK2R230_RUNBOOK);

    expect($rule)->toContain('refuses without a terminal on stdin and stdout')
        ->and($rule)->toContain("the LUKS passphrase is typed into cryptsetup's own prompt")
        ->and($rule)->toContain('nsenter --target 1 --mount')
        ->and($rule)->toContain('wsl --mount <disk> --bare')
        ->and($runbook)->toContain('sudo nsenter --target 1 --mount -- mount -t ext4');

    // The runbook may name what is forbidden only as prose; it never instructs a key file, a format, a repair or a wipe.
    expect($runbook)->not->toMatch('/--key-?file|luksFormat|luksAddKey|\bmkfs\b|\bwipefs\b|\be2fsck\b|Format-Volume|Initialize-Disk/');
});

it('keeps the backup HDD hardware identifiers out of this public repository', function () {
    $shapes = [
        'disk WWN' => '/wwn-0x[0-9a-f]{16}/i',
        'by-uuid device path' => '#/dev/disk/by-uuid/[0-9a-f]{8}-[0-9a-f]{4}-#i',
        'dm-crypt uuid' => '/CRYPT-LUKS2-[0-9a-f]{32}/i',
        'drive serial' => '/\bWD-W[A-Z0-9]{10,}\b/',
    ];
    $extensions = ['md', 'mdc', 'sh', 'php', 'conf', 'yml', 'yaml', 'json', 'txt', 'service', 'timer', 'env'];
    $offenders = [];

    foreach (['docs', '.cursor/rules', '.claude', 'deploy', 'scripts', 'config', '.github'] as $root) {
        $dir = base_path($root);
        if (! is_dir($dir)) {
            continue;
        }
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (! $file->isFile() || ! in_array(strtolower($file->getExtension()), $extensions, true)) {
                continue;
            }
            $content = (string) file_get_contents($file->getPathname());
            foreach ($shapes as $label => $pattern) {
                if (preg_match($pattern, $content) === 1) {
                    $offenders[] = substr($file->getPathname(), strlen(base_path()) + 1)." ({$label})";
                }
            }
        }
    }

    expect($offenders)->toBe([], 'BK-2 identifiers belong in the owner\'s private tree: '.implode(', ', $offenders));
});
