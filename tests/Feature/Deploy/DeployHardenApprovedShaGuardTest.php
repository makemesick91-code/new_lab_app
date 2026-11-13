<?php

use App\Support\Deploy\DeploymentEntrypointScanner;

uses()->group('Deploy', 'Security', 'ReleaseSafety');

/**
 * DMS-RME-REVISION-PROGRAM-8D-APPROVED-SHA-GUARD-1 — rule 229, exact approved
 * release SHA.
 *
 * The deploy used to pin whatever the base branch tip was when its bootstrap
 * fetched. The pin was immutable, but nothing tied it to the commit the owner
 * approved. Now a deploy names a release and an exact commit, and proceeds only
 * when a release approval record MERGED on the base branch approves exactly
 * that commit. The runner checks before it writes or detaches anything; the
 * bootstrap checks again inside the deploy lock before the snapshot, the backup
 * or the checkout; the evidence contract binds the run to the approved commit.
 *
 * Every behavioural test runs the REAL scripts against a disposable checkout
 * and a bare origin (r229Sandbox in tests/Pest.php). Nothing touches a server,
 * root, a database or production.
 */
function r229RequiresTooling(): void
{
    foreach (['git', 'flock', 'tar', 'setsid', 'sha256sum'] as $binary) {
        if (trim((string) shell_exec('command -v '.escapeshellarg($binary).' 2>/dev/null')) === '') {
            test()->markTestSkipped("{$binary} is not available on this host");
        }
    }
}

function r229Denied(array $result): void
{
    expect($result['exit'])->toBe(77, $result['output'])
        ->and($result['output'])->toContain('DEPLOYMENT=DENIED')
        ->and($result['output'])->not->toContain('RELEASE APPROVAL: GO');
}

/** The default record fields for a sandbox, for tests that write their own record. */
function r229Fields(array $s, array $overrides = []): array
{
    return array_merge([
        'DMS_APPROVAL_CONTRACT' => '1',
        'DMS_APPROVAL_RELEASE_ID' => $s['release'],
        'DMS_APPROVAL_REPOSITORY' => $s['repository'],
        'DMS_APPROVAL_HOST' => $s['host'],
        'DMS_APPROVAL_BASE_BRANCH' => $s['branch'],
        'DMS_APPROVAL_TARGET_SHA' => $s['sha'],
        'DMS_APPROVAL_TARGET_TREE' => $s['tree'],
        'DMS_APPROVAL_GO_TAG' => $s['go_tag'],
        'DMS_APPROVAL_APPROVED_BY' => 'owner',
        'DMS_APPROVAL_APPROVED_AT' => gmdate('Y-m-d\TH:i:s\Z', time() - 600),
        'DMS_APPROVAL_NOT_AFTER' => gmdate('Y-m-d\TH:i:s\Z', time() + 3600),
        'DMS_APPROVAL_EVIDENCE' => 'docs/sprints/'.$s['release'].'.md',
    ], $overrides);
}

/** Merge a hand-written approval (and an evidence doc quoting $quote) onto origin. */
function r229ApproveWith(array $s, string $record, string $quote): array
{
    $s['tip'] = r229Push($s, [
        "deploy/release-approvals/{$s['release']}.approval" => $record,
        "docs/sprints/{$s['release']}.md" => "# approval\n\nOwner approval: deploy `{$quote}`.\n",
    ], 'approve');

    return $s;
}

function r229RunDirEntries(array $s): array
{
    $dir = "{$s['app']}/storage/logs/deploy-runner";

    return is_dir($dir) ? array_values(array_diff(scandir($dir) ?: [], ['.', '..'])) : [];
}

// ─── The guard library ──────────────────────────────────────────────────────

it('approves exactly the commit a merged approval record approves', function () {
    r229RequiresTooling();
    $s = r229Sandbox();

    $r = r229Verify($s);

    expect($r['exit'])->toBe(0, $r['output'])
        ->and($r['output'])->toContain('RELEASE APPROVAL: GO')
        ->and($r['output'])->toMatch('/^rc=0 approved='.$s['sha'].'$/m')
        ->and($r['output'])->toContain("Approval record:    {$s['tip']}:deploy/release-approvals/{$s['release']}.approval")
        ->and($r['output'])->toContain("PASS: origin is the declared repository '{$s['repository']}'");
});

it('never substitutes another commit for the approved one', function (string $which, string $reason) {
    r229RequiresTooling();
    $s = r229Sandbox();
    $ask = ['branch tip' => $s['tip'], 'running HEAD' => $s['base'], 'unknown commit' => str_repeat('a', 40)][$which];

    $r = r229Verify($s, ['sha' => $ask]);

    r229Denied($r);
    expect($r['output'])->toContain($reason)->and($r['output'])->toMatch('/^rc=77 approved=$/m');
})->with([
    'the branch tip' => ['branch tip', 'no substitution'],
    'the running HEAD' => ['running HEAD', 'no substitution'],
    'a commit the host does not have' => ['unknown commit', "is a 'missing' object"],
]);

it('refuses an approved SHA that is not a full lowercase commit SHA', function (string $sha) {
    r229RequiresTooling();
    $s = r229Sandbox();

    $r = r229Verify($s, ['sha' => $sha === '@short' ? substr($s['sha'], 0, 12) : ($sha === '@upper' ? strtoupper($s['sha']) : $sha)]);

    r229Denied($r);
    expect($r['output'])->toContain('is not a full 40-character lowercase commit SHA');
})->with([
    'abbreviated' => ['@short'],
    'uppercase' => ['@upper'],
    'a branch name' => ['feature/r229-fixture-base'],
    'HEAD' => ['HEAD'],
    'a tag' => ['dms-fixture-release-go'],
    '41 characters' => [str_repeat('a', 41)],
    'empty' => [''],
]);

it('refuses an approved SHA that names a tree or blob instead of a commit', function () {
    r229RequiresTooling();
    $s = r229Sandbox();

    r229Denied($r = r229Verify($s, ['sha' => $s['tree']]));
    expect($r['output'])->toContain("is a 'tree' object in this repository, not a commit");
});

it('refuses a release with no merged approval record', function () {
    r229RequiresTooling();
    $s = r229Sandbox(['approve' => false]);

    // A record in the WORKING TREE is not an approval: the guard reads only the
    // fetched commit object.
    r229Write($s['app'], ["deploy/release-approvals/{$s['release']}.approval" => r229ApprovalRecord(r229Fields($s))]);

    r229Denied($r = r229Verify($s));
    expect($r['output'])->toContain('no approval record deploy/release-approvals/'.$s['release'].'.approval on origin');
});

it('refuses a record whose facts do not match', function (array $fields, string $reason) {
    r229RequiresTooling();
    $now = time();
    $fields = array_map(fn ($v) => is_string($v) ? strtr($v, [
        '@now-3600' => gmdate('Y-m-d\TH:i:s\Z', $now - 3600),
        '@now-120' => gmdate('Y-m-d\TH:i:s\Z', $now - 120),
        '@now-60' => gmdate('Y-m-d\TH:i:s\Z', $now - 60),
        '@now+3600' => gmdate('Y-m-d\TH:i:s\Z', $now + 3600),
        '@now+7200' => gmdate('Y-m-d\TH:i:s\Z', $now + 7200),
        '@now+4d' => gmdate('Y-m-d\TH:i:s\Z', $now + 4 * 86400),
    ]) : $v, $fields);
    $s = r229Sandbox(['approval' => $fields]);

    r229Denied($r = r229Verify($s));
    expect($r['output'])->toContain($reason);
})->with([
    'another release' => [['DMS_APPROVAL_RELEASE_ID' => 'dms-other-release'], "names release 'dms-other-release'"],
    'another repository' => [['DMS_APPROVAL_REPOSITORY' => 'makemesick91-code/new_lab_app'], 'approves repository'],
    'another host' => [['DMS_APPROVAL_HOST' => 'srv1730088'], "approves host 'srv1730088'"],
    'another branch' => [['DMS_APPROVAL_BASE_BRANCH' => 'main'], "approves branch 'main'"],
    'another tree' => [['DMS_APPROVAL_TARGET_TREE' => str_repeat('b', 40)], 'approves tree'],
    'an unauthorised approver' => [['DMS_APPROVAL_APPROVED_BY' => 'claude'], "approver 'claude' is not a declared release approver"],
    'a GO tag the release does not declare' => [['DMS_APPROVAL_GO_TAG' => 'dms-other-go'], "declares 'dms-fixture-release-go'"],
    'a GO tag without -go' => [['DMS_APPROVAL_GO_TAG' => 'dms-fixture-release'], "'-go' convention"],
    'an expired approval' => [['DMS_APPROVAL_APPROVED_AT' => '@now-3600', 'DMS_APPROVAL_NOT_AFTER' => '@now-60'], 'the approval expired'],
    'an approval dated in the future' => [['DMS_APPROVAL_APPROVED_AT' => '@now+3600', 'DMS_APPROVAL_NOT_AFTER' => '@now+7200'], 'dated in the future'],
    'an open-ended approval window' => [['DMS_APPROVAL_APPROVED_AT' => '@now-60', 'DMS_APPROVAL_NOT_AFTER' => '@now+4d'], 'longer than 259200 seconds'],
    'a window that ends before it starts' => [['DMS_APPROVAL_APPROVED_AT' => '@now-60', 'DMS_APPROVAL_NOT_AFTER' => '@now-120'], 'ends'],
    'an impossible date' => [['DMS_APPROVAL_APPROVED_AT' => '2026-02-30T00:00:00Z'], 'is not a canonical UTC instant'],
    'a missing key' => [['DMS_APPROVAL_TARGET_TREE' => null], 'does not declare DMS_APPROVAL_TARGET_TREE'],
    'another approval contract' => [['DMS_APPROVAL_CONTRACT' => '2'], 'approval contract'],
    'evidence outside docs' => [['DMS_APPROVAL_EVIDENCE' => 'README.md'], 'is not a docs/*.md path'],
    'evidence path traversal' => [['DMS_APPROVAL_EVIDENCE' => 'docs/../deploy/x.md'], 'is not a docs/*.md path'],
    'evidence document not on the branch' => [['DMS_APPROVAL_EVIDENCE' => 'docs/sprints/absent.md'], 'is not on origin'],
]);

it('refuses a record whose evidence document does not quote the approved commit', function () {
    r229RequiresTooling();
    $s = r229Sandbox(['evidence' => "# approval\n\nThe owner approved something.\n"]);

    r229Denied($r = r229Verify($s));
    expect($r['output'])->toContain('does not quote '.$s['sha']);
});

it('refuses a record it would have to execute or guess at', function (string $mutation, string $reason) {
    r229RequiresTooling();
    $s = r229Sandbox(['approve' => false]);
    $record = r229ApprovalRecord(r229Fields($s));
    $pwned = "{$s['root']}/pwned";
    $record = match ($mutation) {
        'unknown key' => $record."DMS_APPROVAL_EXTRA=1\n",
        'duplicate key' => $record.'DMS_APPROVAL_TARGET_SHA='.$s['sha']."\n",
        'command substitution' => str_replace('DMS_APPROVAL_APPROVED_BY=owner', 'DMS_APPROVAL_APPROVED_BY=$(touch '.$pwned.')', $record),
        'quoted value' => str_replace('DMS_APPROVAL_APPROVED_BY=owner', 'DMS_APPROVAL_APPROVED_BY="owner"', $record),
        'not KEY=VALUE' => $record."approved, ship it\n",
        'oversized' => $record.str_repeat("# padding\n", 500),
    };
    $s = r229ApproveWith($s, $record, $s['sha']);

    r229Denied($r = r229Verify($s));
    expect($r['output'])->toContain($reason)
        ->and(file_exists($pwned))->toBeFalse('the record was executed');
})->with([
    'an unknown key' => ['unknown key', 'unknown key DMS_APPROVAL_EXTRA'],
    'a duplicated key' => ['duplicate key', 'declares DMS_APPROVAL_TARGET_SHA more than once'],
    'a command substitution' => ['command substitution', 'not KEY=VALUE with a plain value'],
    'a quoted value' => ['quoted value', 'not KEY=VALUE with a plain value'],
    'a free-text line' => ['not KEY=VALUE', 'not KEY=VALUE with a plain value'],
    'an oversized record' => ['oversized', 'the limit is 4096'],
]);

it('refuses when the branch moved past the approved commit with anything but approvals or docs', function (array $files) {
    r229RequiresTooling();
    $s = r229Sandbox();
    r229Push($s, $files, 'pushed after the approval');

    r229Denied($r = r229Verify($s));
    expect($r['output'])->toContain('moved past the approved commit with');
})->with([
    'application code' => [['app/Unreviewed.php' => "<?php\n"]],
    'a deploy script' => [['scripts/deploy-vps.sh' => "#!/usr/bin/env bash\necho swapped\n"]],
    'a migration' => [['database/migrations/2026_11_14_000000_x.php' => "<?php\n"]],
    'a non-Markdown file under docs' => [['docs/run.sh' => "echo\n"]],
    'a malformed approval file name' => [['deploy/release-approvals/../x.approval' => "x\n"]],
]);

it('deploys the approved commit, never the tip, when only approvals or docs followed it', function () {
    r229RequiresTooling();
    $s = r229Sandbox();
    $tip = r229Push($s, [
        'deploy/release-approvals/dms-another-release.approval' => "# another release\n",
        'docs/sprints/dms-fixture-release.md' => "# closure\n\nDeployed `{$s['sha']}`; GO evidence follows.\n",
    ], 'later approval and docs');

    $r = r229Verify($s);

    expect($r['exit'])->toBe(0, $r['output'])
        ->and($r['output'])->toContain("Base branch tip:    {$tip}")
        ->and($r['output'])->toMatch('/^rc=0 approved='.$s['sha'].'$/m')
        ->and($tip)->not->toBe($s['sha']);
});

it('refuses an approved commit that is not merged on the base branch', function () {
    r229RequiresTooling();
    $s = r229Sandbox(['approve' => false]);
    // A commit the host holds but origin's base branch does not carry.
    $side = r229Git($s['app'], ['commit-tree', $s['base'].'^{tree}', '-p', $s['base'], '-m', 'unmerged side commit']);
    $sideTree = r229Git($s['app'], ['rev-parse', $side.'^{tree}']);
    $s = r229ApproveWith($s, r229ApprovalRecord(r229Fields($s, ['DMS_APPROVAL_TARGET_SHA' => $side, 'DMS_APPROVAL_TARGET_TREE' => $sideTree])), $side);

    r229Denied($r = r229Verify($s, ['sha' => $side]));
    expect($r['output'])->toContain('is not on origin '.$s['branch'].' (not merged)');
});

it('never moves production backwards, so a stale approval cannot be replayed', function (string $where) {
    r229RequiresTooling();
    $s = r229Sandbox();
    if ($where === 'newer release') {
        // Production already runs a later release; the old approval is still
        // inside its window.
        r229Git($s['app'], ['checkout', '-q', '-B', $s['branch'], $s['sha']]);
    } else {
        r229Git($s['app'], ['checkout', '-q', '-B', $s['branch'], $s['base']]);
    }
    r229Git($s['app'], ['commit', '-q', '--allow-empty', '-m', $where]);

    r229Denied($r = r229Verify($s));
    expect($r['output'])->toContain('never moves production backwards');
})->with(['newer release', 'unrelated side commit']);

it('refuses the wrong host and the frozen host', function (string $host, string $reason) {
    r229RequiresTooling();
    $s = r229Sandbox();

    r229Denied($r = r229Verify($s, ['host' => $host]));
    expect($r['output'])->toContain($reason);
})->with([
    'another host' => ['staging-box', "this host is 'staging-box'"],
    'the frozen Hostinger host' => ['srv1730088', 'FROZEN legacy infrastructure'],
]);

it('refuses a checkout whose origin is not the declared repository', function () {
    r229RequiresTooling();
    $s = r229Sandbox();
    r229Git($s['root'], ['clone', '-q', '--bare', $s['origin'], "{$s['root']}/impostor.git"]);
    r229Git($s['app'], ['remote', 'set-url', 'origin', "{$s['root']}/impostor.git"]);

    r229Denied($r = r229Verify($s));
    expect($r['output'])->toContain("origin is '{$s['root']}/impostor'");
});

it('refuses an authority that does not declare what the guard checks', function (string $key) {
    r229RequiresTooling();
    $s = r229Sandbox();
    $authority = preg_replace('/^'.$key.'=.*$/m', '', (string) file_get_contents($s['authority']));
    file_put_contents($s['authority'], $authority);

    r229Denied($r = r229Verify($s));
})->with(['DMS_PRODUCTION_HOSTNAME', 'DMS_PRODUCTION_REPOSITORY', 'DMS_PRODUCTION_RELEASE_APPROVERS']);

it('reads the canonical repository from every GitHub remote spelling, and nothing else', function (string $url, string $expected) {
    $r = r229Run(['bash', '-c', 'source "$1"; dms_release_approval_normalize_repository "$2"', '_', base_path('scripts/lib/release-approval.sh'), $url]);

    expect($r['output'])->toBe($expected);
})->with([
    'https' => ['https://github.com/makemesick91-code/new_lab_app.git', 'makemesick91-code/new_lab_app'],
    'https without .git' => ['https://github.com/makemesick91-code/new_lab_app', 'makemesick91-code/new_lab_app'],
    'scp-style ssh' => ['git@github.com:makemesick91-code/new_lab_app.git', 'makemesick91-code/new_lab_app'],
    'ssh url' => ['ssh://git@github.com/makemesick91-code/new_lab_app.git', 'makemesick91-code/new_lab_app'],
    'a look-alike host' => ['https://github.com.evil.example/makemesick91-code/new_lab_app.git', 'https://github.com.evil.example/makemesick91-code/new_lab_app'],
    'another host' => ['https://gitlab.com/makemesick91-code/new_lab_app.git', 'https://gitlab.com/makemesick91-code/new_lab_app'],
]);

it('declares the canonical repository, branch and approver in the production authority', function () {
    $authority = (string) file_get_contents(base_path('deploy/production-host.conf'));
    $deploy = (string) file_get_contents(base_path('scripts/deploy-vps.sh'));
    preg_match('/^BRANCH="([^"]+)"$/m', $deploy, $branch);

    expect($authority)->toMatch('/^DMS_PRODUCTION_REPOSITORY=makemesick91-code\/new_lab_app$/m')
        ->and($authority)->toMatch('/^DMS_PRODUCTION_RELEASE_APPROVERS="owner"$/m')
        ->and($authority)->toContain('DMS_PRODUCTION_DEPLOY_BRANCH='.$branch[1]."\n");
});

// ─── The bootstrap (inside the deploy lock) ─────────────────────────────────

function r229Bootstrap(array $s, array $approval, array $env = []): array
{
    return r229Run(array_merge([
        'bash', "{$s['app']}/scripts/deploy-immutable-exec.sh",
        '--role', 'deploy', '--app-dir', $s['app'], '--lock-dir', $s['lock'], '--branch', $s['branch'],
    ], $approval, ['--', 'scripts/deploy-vps.sh', '--release-id', $s['release'], '--approved-sha', $s['sha']]), $env, $s['app']);
}

it('refuses a deploy that names no approval before it takes the lock', function (string $case) {
    r229RequiresTooling();
    $s = r229Sandbox(['checkout' => 'release']);
    $approval = [
        'no approval' => [],
        'no approved SHA' => ['--release-id', $s['release']],
        'no release id' => ['--approved-sha', $s['sha']],
        'an explicit target instead' => ['--target-sha', $s['sha']],
        'an abbreviated SHA' => ['--release-id', $s['release'], '--approved-sha', substr($s['sha'], 0, 12)],
    ][$case];

    $r = r229Bootstrap($s, $approval);

    expect($r['exit'])->toBe(77, $r['output'])
        ->and($r['output'])->toContain('DEPLOYMENT=DENIED')
        ->and($r['output'])->not->toContain('DEPLOY_LOCK_ACQUIRED')
        ->and($r['output'])->not->toContain('DEPLOY_TARGET_PINNED')
        ->and(file_exists("{$s['lock']}/deploy.lock"))->toBeFalse();
})->with(['no approval', 'no approved SHA', 'no release id', 'an explicit target instead', 'an abbreviated SHA']);

it('refuses an unapproved target inside the lock, before any snapshot or checkout', function () {
    r229RequiresTooling();
    $s = r229Sandbox();
    r229Push($s, ['app/Unreviewed.php' => "<?php\n"], 'pushed after the approval');

    $r = r229Bootstrap($s, ['--release-id', $s['release'], '--approved-sha', $s['sha']]);

    expect($r['exit'])->toBe(77, $r['output'])
        ->and($r['output'])->toContain('DEPLOY_LOCK_ACQUIRED=YES')
        ->and($r['output'])->toContain('DEPLOYMENT=DENIED')
        ->and($r['output'])->toContain('nothing was backed up, checked out or migrated')
        ->and($r['output'])->not->toContain('DEPLOY_TARGET_PINNED')
        ->and($r['output'])->not->toContain('DEPLOY_SNAPSHOT_CREATED')
        ->and(glob("{$s['lock']}/deploy-*") ?: [])->toBe([])
        ->and(r229Git($s['app'], ['rev-parse', 'HEAD']))->toBe($s['base'])
        ->and(r229Git($s['app'], ['status', '--porcelain', '--untracked-files=no']))->toBe('');
});

it('pins exactly the approved commit, carries it through the snapshot and lands on it', function () {
    r229RequiresTooling();
    $s = r229Sandbox();

    $r = r229Bootstrap($s, ['--release-id', $s['release'], '--approved-sha', $s['sha']]);

    expect($r['exit'])->toBe(0, $r['output'])
        ->and($r['output'])->toContain('RELEASE APPROVAL: GO')
        ->and($r['output'])->toContain("DEPLOY_APPROVED_SHA={$s['sha']}")
        ->and($r['output'])->toContain("DEPLOY_APPROVAL_RECORD={$s['tip']}:deploy/release-approvals/{$s['release']}.approval blob=")
        ->and(substr_count($r['output'], 'DEPLOY_TARGET_PINNED='))->toBe(1)
        ->and($r['output'])->toContain("DEPLOY_TARGET_PINNED={$s['sha']}")
        ->and($r['output'])->toContain("DEPLOY_HEAD_TARGET_MATCH=YES ({$s['sha']})")
        ->and($r['output'])->toContain('DEPLOY_SNAPSHOT_CLEANED=YES')
        ->and(r229Git($s['app'], ['rev-parse', 'HEAD']))->toBe($s['sha']);
});

it('keeps the approved commit when the branch moves after the pin', function () {
    r229RequiresTooling();
    $s = r229Sandbox();
    $moveBranch = 'cd '.escapeshellarg($s['maint']).' && echo late > late.txt && git add late.txt'
        .' && git -c user.email=r229@test -c user.name=r229 -c commit.gpgsign=false commit -qm late'
        .' && git push -q origin '.escapeshellarg($s['branch']);

    $r = r229Bootstrap($s, ['--release-id', $s['release'], '--approved-sha', $s['sha']], ['R229_DURING_RUN' => $moveBranch]);
    $movedTip = r229Git($s['maint'], ['rev-parse', 'HEAD']);

    expect($r['exit'])->toBe(0, $r['output'])
        ->and($movedTip)->not->toBe($s['tip'])
        ->and(r229Git($s['app'], ['rev-parse', 'HEAD']))->toBe($s['sha'])
        ->and(substr_count($r['output'], 'DEPLOY_TARGET_PINNED='))->toBe(1)
        ->and($r['output'])->toContain("DEPLOY_TARGET_PINNED={$s['sha']}");
});

// ─── The runner, end to end ─────────────────────────────────────────────────

it('launches nothing for an unapproved start', function (string $case) {
    r229RequiresTooling();
    $s = r229Sandbox();
    $args = [
        'no approval' => ['start'],
        'no approved SHA' => ['start', '--release-id', $s['release']],
        'no release id' => ['start', '--approved-sha', $s['sha']],
        'an unapproved commit' => ['start', '--release-id', $s['release'], '--approved-sha', $s['base']],
        'an unknown release' => ['start', '--release-id', 'dms-unknown-release', '--approved-sha', $s['sha']],
        'run, unapproved' => ['run', '--release-id', $s['release'], '--approved-sha', $s['base']],
        'a stray argument' => ['start', '--release-id', $s['release'], '--approved-sha', $s['sha'], '--force'],
    ][$case];

    $r = r229Runner($s, $args);

    expect($r['exit'])->toBe(77, $r['output'])
        ->and($r['output'])->toContain('DEPLOYMENT=DENIED')
        ->and($r['output'])->not->toContain('started detached')
        ->and(r229RunDirEntries($s))->toBe([])
        ->and(r229Git($s['app'], ['rev-parse', 'HEAD']))->toBe($s['base']);
})->with(['no approval', 'no approved SHA', 'no release id', 'an unapproved commit', 'an unknown release', 'run, unapproved', 'a stray argument']);

it('binds an approved run to the approved commit and proves it by identifier', function () {
    r229RequiresTooling();
    $s = r229Sandbox();

    $start = r229Runner($s, ['start', '--release-id', $s['release'], '--approved-sha', $s['sha']]);
    expect($start['exit'])->toBe(0, $start['output'])
        ->and($start['output'])->toContain('RELEASE APPROVAL: GO')
        ->and($start['output'])->toContain("release_id={$s['release']} approved_sha={$s['sha']}")
        ->and(preg_match('/deploy_id=(\d{8}-\d{6})/', $start['output'], $m))->toBe(1);
    $id = $m[1];
    expect(r229WaitForStatus($s['app'], $id))->toBe('exit=0');

    $record = (string) file_get_contents("{$s['app']}/storage/logs/deploy-runner/deploy-{$id}.host");
    expect($record)->toContain('runner_contract=3')
        ->and($record)->toContain("release_id={$s['release']}\n")
        ->and($record)->toContain("approved_sha={$s['sha']}\n")
        ->and($record)->toContain("approval_record={$s['tip']}:deploy/release-approvals/{$s['release']}.approval\n")
        ->and($record)->toMatch('/^approval_blob=[0-9a-f]{40}$/m')
        ->and($record)->toContain("program={$s['app']}/scripts/deploy-vps.sh\n");

    $verify = r229Runner($s, ['verify', $id]);
    expect($verify['exit'])->toBe(0, $verify['output'])
        ->and($verify['output'])->toContain("target_commit={$s['sha']} (checkout HEAD matches)")
        ->and($verify['output'])->toContain("DEPLOY_APPROVED_TARGET_MATCH=YES ({$s['sha']})")
        ->and($verify['output'])->toContain('DEPLOY RUNNER OK')
        ->and(r229Git($s['app'], ['rev-parse', 'HEAD']))->toBe($s['sha']);
});

it('serialises two approved runs: the second is refused by the lock before it pins', function () {
    r229RequiresTooling();
    $s = r229Sandbox();
    $approval = ['--release-id', $s['release'], '--approved-sha', $s['sha']];

    $started = microtime(true);
    $first = r229Runner($s, array_merge(['start'], $approval), ['R229_HOLD_SECONDS' => '8']);
    preg_match('/deploy_id=(\d{8}-\d{6})/', $first['output'], $a);
    // Wait until the first run is demonstrably inside the locked critical
    // section (its program has started), not for a fixed time.
    $firstLog = "{$s['app']}/storage/logs/deploy-runner/deploy-{$a[1]}.log";
    $deadline = microtime(true) + 30;
    while (microtime(true) < $deadline && ! str_contains((string) @file_get_contents($firstLog), 'PRODUCTION HOST PREFLIGHT: GO')) {
        usleep(100_000);
    }
    expect((string) @file_get_contents($firstLog))->toContain('PRODUCTION HOST PREFLIGHT: GO');
    // The runner's identifier has one-second resolution.
    usleep((int) max(0, 1_200_000 - (microtime(true) - $started) * 1_000_000));
    $second = r229Runner($s, array_merge(['start'], $approval));
    preg_match('/deploy_id=(\d{8}-\d{6})/', $second['output'], $b);

    expect(r229WaitForStatus($s['app'], $b[1]))->toBe('exit=75')
        ->and(r229WaitForStatus($s['app'], $a[1]))->toBe('exit=0');
    $secondLog = (string) file_get_contents("{$s['app']}/storage/logs/deploy-runner/deploy-{$b[1]}.log");
    expect($secondLog)->toContain('BUSY another deploy is already running')
        ->and($secondLog)->not->toContain('DEPLOY_TARGET_PINNED');

    $verifySecond = r229Runner($s, ['verify', $b[1]]);
    expect($verifySecond['exit'])->toBe(1, $verifySecond['output'])
        ->and($verifySecond['output'])->toContain('DEPLOY RUNNER FAILED (exit=75)');
    expect(r229Runner($s, ['verify', $a[1]])['output'])->toContain('DEPLOY RUNNER OK');
});

// ─── The one-time bootstrap from an unguarded production tree (§6c) ─────────

it('bootstraps the first guarded deploy from the approved release object without the legacy runner', function () {
    r229RequiresTooling();
    $s = r229Sandbox(['production' => 'legacy']);
    $bundle = "{$s['root']}/bundle";

    // §6c steps 2–3: fetch (refs only), export the approved commit's deploy
    // chain into a NEW owner-only directory, and prove its provenance.
    r229Git($s['app'], ['fetch', '--no-tags', '-q', 'origin', $s['branch']]);
    mkdir($bundle, 0o700);
    $export = r229Run(['bash', '-c', 'set -o pipefail; git -C "$1" archive "$2" -- scripts deploy | tar -x -C "$3"', '_', $s['app'], $s['sha'], $bundle]);
    expect($export['exit'])->toBe(0, $export['output'])
        ->and(hash_file('sha256', "{$bundle}/scripts/deploy-vps-runner.sh"))
        ->toBe(hash('sha256', r229Git($s['app'], ['show', "{$s['sha']}:scripts/deploy-vps-runner.sh"])."\n"));

    // A wrong commit is refused by the bundle runner too, before anything runs.
    $wrong = r229Runner($s, ['start', '--release-id', $s['release'], '--approved-sha', $s['base']], [], "{$bundle}/scripts/deploy-vps-runner.sh");
    expect($wrong['exit'])->toBe(77, $wrong['output'])->and(r229RunDirEntries($s))->toBe([]);

    // §6c step 4: the bundle's runner deploys the approved commit.
    $start = r229Runner($s, ['start', '--release-id', $s['release'], '--approved-sha', $s['sha']], [], "{$bundle}/scripts/deploy-vps-runner.sh");
    expect($start['exit'])->toBe(0, $start['output']);
    preg_match('/deploy_id=(\d{8}-\d{6})/', $start['output'], $m);
    expect(r229WaitForStatus($s['app'], $m[1]))->toBe('exit=0');

    $record = (string) file_get_contents("{$s['app']}/storage/logs/deploy-runner/deploy-{$m[1]}.host");
    expect($record)->toContain("program={$bundle}/scripts/deploy-vps.sh\n")
        ->and(file_exists("{$s['app']}/legacy-runner-ran"))->toBeFalse('the legacy runner was executed')
        ->and(r229Git($s['app'], ['rev-parse', 'HEAD']))->toBe($s['sha'])
        ->and(r229Git($s['app'], ['status', '--porcelain', '--untracked-files=no']))->toBe('');

    // §6c step 5: the deployed (now guarded) in-tree runner proves the run.
    $verify = r229Runner($s, ['verify', $m[1]]);
    expect($verify['exit'])->toBe(0, $verify['output'])
        ->and($verify['output'])->toContain("DEPLOY_APPROVED_TARGET_MATCH=YES ({$s['sha']})")
        ->and($verify['output'])->toContain('DEPLOY RUNNER OK');
});

// ─── Contracts on the real production scripts ───────────────────────────────

function r229Script(string $relative): string
{
    return (string) file_get_contents(base_path($relative));
}

/** The script without comment lines, so an ordering contract cannot be met by a comment. */
function r229Code(string $relative): string
{
    return implode("\n", array_filter(
        preg_split('/\R/', r229Script($relative)) ?: [],
        fn (string $line): bool => ! str_starts_with(ltrim($line), '#'),
    ));
}

it('the deploy program refuses before the hand-over and re-asserts the approval before its first mutation', function () {
    $deploy = r229Code('scripts/deploy-vps.sh');
    $pos = fn (string $needle) => strpos($deploy, $needle);

    expect($pos('echo "DEPLOYMENT=DENIED"'))->toBeLessThan($pos('exec bash "$IMMUTABLE_EXEC"'))
        ->and(substr_count($deploy, '--approved-sha "$APPROVED_SHA"'))->toBe(2)
        ->and($deploy)->toContain('--release-id "$RELEASE_ID"')
        ->and($deploy)->not->toContain('--target-sha')
        ->and($pos('"${DMS_DEPLOY_APPROVED_SHA:-}" != "$APPROVED_SHA"'))->toBeLessThan($pos('dms_production_host_preflight "'))
        ->and($pos('dms_production_host_preflight "'))->toBeLessThan($pos('echo "== Backup DB =="'))
        ->and($pos('echo "== Backup DB =="'))->toBeLessThan($pos('git checkout "$BRANCH"'))
        ->and($deploy)->toContain('if [ "$FINAL_HEAD" != "$TARGET_SHA" ] || [ "$FINAL_HEAD" != "$APPROVED_SHA" ]; then');
});

it('the runner verifies the approval before it writes, links or detaches anything', function () {
    $runner = r229Code('scripts/deploy-vps-runner.sh');
    $body = substr($runner, (int) strpos($runner, 'start_detached() {'));
    $pos = fn (string $needle) => strpos($body, $needle);

    expect($pos('approve_or_deny'))->toBeLessThan($pos('mkdir -p "$RUN_DIR"'))
        ->and($pos('mkdir -p "$RUN_DIR"'))->toBeLessThan($pos(': > "$DEPLOY_LOG_FILE"'))
        ->and($pos(': > "$DEPLOY_LOG_FILE"'))->toBeLessThan($pos('setsid nohup'))
        ->and($runner)->toContain('DEPLOY_SCRIPT="${RUNNER_DIR}/deploy-vps.sh"')
        ->and($runner)->not->toContain('${DEPLOY_SCRIPT:-')
        ->and($runner)->toContain('bash "$program" --release-id "$release" --approved-sha "$approved"');
});

it('the bootstrap verifies the approval inside the lock and before the snapshot', function () {
    $bootstrap = r229Code('scripts/deploy-immutable-exec.sh');
    $pos = fn (string $needle) => strpos($bootstrap, $needle);

    expect($pos('--target-sha is not accepted for a deploy'))->toBeLessThan($pos('exec 9>"$LOCK_FILE"'))
        ->and($pos('flock -n 9'))->toBeLessThan($pos('dms_release_approval_verify "$APP_DIR"'))
        ->and($pos('dms_release_approval_verify "$APP_DIR"'))->toBeLessThan($pos('echo "DEPLOY_TARGET_PINNED='))
        ->and($pos('echo "DEPLOY_TARGET_PINNED='))->toBeLessThan($pos('git archive --format=tar'))
        ->and($bootstrap)->toContain('TARGET_SHA="$DMS_RELEASE_APPROVED_SHA"')
        ->and($pos('--snapshot-ref is not accepted for a deploy'))->toBeLessThan($pos('exec 9>"$LOCK_FILE"'))
        ->and($pos("runs only its own program"))->toBeLessThan($pos('exec 9>"$LOCK_FILE"'));
});

it('the guard parses the approval record and never executes it', function () {
    $lib = r229Script('scripts/lib/release-approval.sh');

    $code = r229Code('scripts/lib/release-approval.sh');

    expect($code)->not->toContain('FETCH_HEAD')
        ->and($code)->toContain('refs/dms-release-approval/')
        ->and($code)->not->toContain('safe.directory')
        ->and($lib)->not->toMatch('/^\s*(source|\.)\s/m')
        ->and($lib)->not->toContain('eval ')
        ->and($lib)->toContain('cat-file blob "$blob"')
        ->and($lib)->not->toContain('cat "$path"');
});

it('the release wrapper needs the approval and tags the approved commit, never HEAD', function () {
    $wrapper = r229Code('scripts/sprint-release.sh');

    expect($wrapper)->toContain('bash scripts/deploy-vps-runner.sh run --release-id "${RELEASE_ID}" --approved-sha "${APPROVED_SHA}"')
        ->and($wrapper)->toContain('git tag -a "${TAG}" "${APPROVED_SHA}"')
        ->and($wrapper)->toContain('[[ "${DEPLOYED_HEAD}" == "${APPROVED_SHA}" ]]')
        ->and($wrapper)->not->toMatch('/git tag -a "\$\{TAG\}" -m/')
        // The tag name is the approved commit's own go_tag, from the git object.
        ->and($wrapper)->toContain('git show "${APPROVED_SHA}:.sprint/current.yml"')
        ->and($wrapper)->toContain('[[ "${TAG}" == "${APPROVED_GO_TAG}" ]]')
        ->and($wrapper)->not->toContain('"${MANIFEST}" | tail -n1');
});

it('the release wrapper refuses --apply without an approval before touching anything', function (array $args) {
    $r = r229Run(array_merge(['bash', base_path('scripts/sprint-release.sh'), '--apply'], $args), [
        'DEVFLOW_LOCK_FILE' => tempArtifactDir('r229-wrapper-').'/release.lock',
    ], base_path());

    expect($r['exit'])->toBe(1, $r['output'])
        ->and($r['output'])->toContain('DEPLOYMENT=DENIED')
        ->and($r['output'])->not->toContain('release lock acquired')
        ->and($r['output'])->not->toContain('sprint:release-check');
})->with([
    'no approval' => [[]],
    'an abbreviated SHA' => [['--release-id', 'dms-fixture-release', '--approved-sha', 'dde7b9b6']],
    'no release id' => [['--approved-sha', str_repeat('a', 40)]],
]);

it('a rollback refuses a target outside the deployed history, before the hand-over and before its backup', function () {
    $rollback = r229Code('scripts/rollback-vps.sh');
    $pos = fn (string $needle, int $offset = 0) => strpos($rollback, $needle, $offset);
    $first = $pos('git merge-base --is-ancestor "$ROLLBACK_TARGET_SHA" HEAD');
    $second = $pos('git merge-base --is-ancestor "$ROLLBACK_TARGET_SHA" "$CURRENT_HEAD"');

    expect($first)->toBeLessThan($pos('exec bash "$IMMUTABLE_EXEC"'))
        ->and($second)->toBeGreaterThan($pos('CURRENT_HEAD="$(git rev-parse HEAD)"'))
        ->and($second)->toBeLessThan($pos('echo "== Backup DB before rollback =="'));
});

it('governance fails the moment any part of the guard is removed', function () {
    $scanner = app(DeploymentEntrypointScanner::class);
    expect($scanner->approvalGuardPosture())->toMatchArray(['ok' => true, 'issues' => [], 'deploy_branch_matches_authority' => true]);

    config()->set('deployment_entrypoint.approval_guard_expectations.files.runner_script.required_markers', ['approve_or_deny', 'a marker that is not there']);
    expect($scanner->approvalGuardPosture()['issues'])->toContain('runner_script: missing approval-guard marker(s): a marker that is not there');

    config()->set('deployment_entrypoint.approval_guard_expectations.files.runner_script.forbidden_markers', ['approve_or_deny']);
    expect($scanner->approvalGuardPosture()['issues'])->toContain('runner_script: forbidden marker present: approve_or_deny');

    config()->set('deployment_entrypoint.approval_guard_expectations.required_authority_keys', ['DMS_NOT_DECLARED']);
    expect($scanner->approvalGuardPosture()['issues'])->toContain('authority does not declare DMS_NOT_DECLARED');
});

// ─── Independent review findings (8D PR2) — each one is a regression test ──

it('refuses a deploy that would run a program from any commit but the approved one', function (string $case) {
    r229RequiresTooling();
    $s = r229Sandbox(['checkout' => 'release']);
    $approval = ['--release-id', $s['release'], '--approved-sha', $s['sha']];
    $args = [
        'a snapshot of another commit' => array_merge($approval, ['--snapshot-ref', $s['base'], '--', 'scripts/deploy-vps.sh']),
        'another program' => array_merge($approval, ['--', 'scripts/deploy-immutable-exec.sh']),
        'the rollback program' => array_merge($approval, ['--', 'scripts/rollback-vps.sh']),
    ][$case];

    $r = r229Run(array_merge(['bash', "{$s['app']}/scripts/deploy-immutable-exec.sh", '--role', 'deploy',
        '--app-dir', $s['app'], '--lock-dir', $s['lock'], '--branch', $s['branch']], $args), [], $s['app']);

    expect($r['exit'])->toBe(77, $r['output'])
        ->and($r['output'])->toContain('DEPLOYMENT=DENIED')
        ->and($r['output'])->not->toContain('DEPLOY_LOCK_ACQUIRED')
        ->and($r['output'])->not->toContain('DEPLOY_SNAPSHOT_CREATED');
})->with(['a snapshot of another commit', 'another program', 'the rollback program']);

it('denies when it cannot list what follows the approved commit (fail closed)', function () {
    r229RequiresTooling();
    $s = r229Sandbox();
    r229Push($s, ['app/Unreviewed.php' => "<?php\n"], 'pushed after the approval');

    // Fault injection: the listing fails. An empty list must never read as "nothing follows".
    $r = r229Run([
        'bash', '-c', 'source "$1"; shift; dms_release_approval_git() { [ "$1" = diff-tree ] && return 128; git -C "$DMS_RELEASE_APPROVAL_GIT_DIR" "$@"; }; rc=0; dms_release_approval_verify "$@" || rc=$?; exit "$rc"',
        '_', base_path('scripts/lib/release-approval.sh'),
        $s['app'], $s['branch'], $s['release'], $s['sha'], $s['authority'], $s['host'], (string) time(),
    ]);

    r229Denied($r);
    expect($r['output'])->toContain('could not list the changes');
});

it('reads the branch through a private ref it always removes, never through FETCH_HEAD', function () {
    r229RequiresTooling();
    $s = r229Sandbox();
    // A FETCH_HEAD pointing anywhere else changes nothing.
    file_put_contents("{$s['app']}/.git/FETCH_HEAD", $s['base']."\t\tbranch 'aaa-unreviewed' of {$s['origin']}\n");

    $go = r229Verify($s);
    $denied = r229Verify($s, ['sha' => $s['base']]);

    expect($go['exit'])->toBe(0, $go['output'])
        ->and($denied['exit'])->toBe(77, $denied['output'])
        ->and(r229Git($s['app'], ['for-each-ref', 'refs/dms-release-approval/']))->toBe('');
});

it('refuses a local origin that merely spells the GitHub repository', function () {
    r229RequiresTooling();
    $s = r229Sandbox();
    file_put_contents($s['authority'], preg_replace('/^DMS_PRODUCTION_REPOSITORY=.*$/m', 'DMS_PRODUCTION_REPOSITORY=makemesick91-code/new_lab_app', (string) file_get_contents($s['authority'])));
    r229Git($s['root'], ['clone', '-q', '--bare', $s['origin'], "{$s['app']}/makemesick91-code/new_lab_app.git"]);
    r229Git($s['app'], ['remote', 'set-url', 'origin', 'makemesick91-code/new_lab_app.git']);

    r229Denied($r = r229Verify($s));
    expect($r['output'])->toContain('is not a GitHub URL for the declared repository');
});

it('refuses before any mutation when the local branch cannot be fast-forwarded to the approved commit', function () {
    r229RequiresTooling();
    $s = r229Sandbox();
    // After a rollback the checkout is detached at an older commit, while the
    // local branch still points past the commit being deployed.
    $ahead = r229Git($s['app'], ['commit-tree', $s['sha'].'^{tree}', '-p', $s['sha'], '-m', 'local branch ahead']);
    r229Git($s['app'], ['checkout', '-q', '--detach', $s['base']]);
    r229Git($s['app'], ['branch', '-f', $s['branch'], $ahead]);

    r229Denied($r = r229Verify($s));
    expect($r['output'])->toContain('cannot be fast-forwarded to the approved commit');
});

it('refuses an approval record or evidence document that is a symlink', function (string $which) {
    r229RequiresTooling();
    $s = r229Sandbox(['approve' => false]);
    $record = "deploy/release-approvals/{$s['release']}.approval";
    $evidence = "docs/sprints/{$s['release']}.md";
    r229Write($s['maint'], [
        $record => r229ApprovalRecord(r229Fields($s)),
        $evidence => "Owner approval: deploy `{$s['sha']}`.\n",
    ]);
    $link = $which === 'record' ? $record : $evidence;
    unlink("{$s['maint']}/{$link}");
    // A symlink's blob is its target text: here, text that would satisfy the check.
    symlink($which === 'record' ? '../../docs/sprints/x' : $s['sha'], "{$s['maint']}/{$link}");
    r229Git($s['maint'], ['add', '-A']);
    r229Git($s['maint'], ['commit', '-q', '-m', 'symlinked approval']);
    r229Git($s['maint'], ['push', '-q', 'origin', $s['branch']]);

    r229Denied($r = r229Verify($s));
    expect($r['output'])->toContain('is not a regular file');
})->with(['record', 'evidence']);

it('refuses a direct run of the real deploy or rollback program with forged bootstrap variables', function (string $program, array $lock, int $exit, string $reason) {
    r229RequiresTooling();
    $s = r229Sandbox();
    $forged = str_repeat('b', 40);
    $env = array_merge([
        'DMS_DEPLOY_SNAPSHOT_DIR' => base_path(),
        'DMS_DEPLOY_APP_DIR' => $s['app'],
        'DMS_DEPLOY_TARGET_SHA' => $forged,
        'DMS_DEPLOY_APPROVED_SHA' => $forged,
        'DMS_DEPLOY_RELEASE_ID' => 'dms-forged-release',
        'DMS_DEPLOY_RUN_ID' => 'forged',
    ], $lock);
    $args = $program === 'deploy'
        ? ['bash', base_path('scripts/deploy-vps.sh'), '--release-id', 'dms-forged-release', '--approved-sha', $forged]
        : ['bash', base_path('scripts/rollback-vps.sh'), $forged];

    $r = r229Run($args, $env, $s['app']);

    expect($r['exit'])->toBe($exit, $r['output'])
        ->and($r['output'])->toContain($reason)
        ->and($r['output'])->not->toContain('PRODUCTION HOST PREFLIGHT')
        ->and($r['output'])->not->toContain('Pinned target SHA')
        ->and($r['output'])->not->toContain('Backup DB')
        ->and(r229Git($s['app'], ['rev-parse', 'HEAD']))->toBe($s['base']);
})->with([
    'deploy, no lock' => ['deploy', [], 77, 'is not in the trusted lock root'],
    'deploy, a lock elsewhere' => ['deploy', ['DMS_DEPLOY_LOCK_FILE' => '/tmp/deploy.lock'], 77, 'is not in the trusted lock root'],
    'deploy, the real lock root but not its snapshot' => ['deploy', ['DMS_DEPLOY_LOCK_FILE' => '/run/daengtisiams-deploy/deploy.lock'], 77, "is not this run's trusted snapshot"],
    'rollback, no lock' => ['rollback', [], 2, 'is not in the trusted lock root'],
    'rollback, the real lock root but not its snapshot' => ['rollback', ['DMS_DEPLOY_LOCK_FILE' => '/run/daengtisiams-deploy/rollback.lock'], 2, "is not this run's trusted snapshot"],
]);
