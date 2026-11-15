<?php

/**
 * DMS-DEPLOY-PRIVSEP-PHASE-2 (rule 235) — least privilege for the deploy path.
 *
 *  1. OD-6 — the deploy operator's ONLY privileged entrypoint
 *     (scripts/deploy-operator-entrypoint.sh): exactly the allowlisted requests,
 *     split without a shell; everything else (shells, chaining, substitution,
 *     extra words, rollback, restore) refused before sudo is ever called; the
 *     trust checks on programs, trees and PATH.
 *  2. The sudo policy (scripts/lib/sudo-policy.sh): the classifier that reads
 *     sudo's own long listing (passwordless ALL is a violation, the declared
 *     administrator may keep password-only ALL, the operator may hold only the
 *     entrypoint with narrow expressions), the rendered operator rule (parses,
 *     agrees with the entrypoint's validator, classifies as OPERATOR).
 *  3. R1 — composer and npm as the build identity (scripts/lib/build-privsep.sh):
 *     hand-over and claim refuse what they must, the sandbox properties, and the
 *     deploy/rollback order; the CI scanner rejects every root composer/npm form.
 *  4. R4 — restore integrity (scripts/lib/restore-integrity.sh): trusted
 *     deploy-log evidence only, a root-only staging copy, digest mismatch and
 *     ambiguity refused; both restore tools refuse a dump without evidence.
 *  5. R2 — nothing but placeholders is tracked under the runtime trees.
 *
 * Tests run unprivileged: root-only steps (useradd, sudoers, systemd-run, chown
 * to root) are exercised through stubs or pinned statically, and proven on the
 * host by the provisioning, the build rehearsal and the deploy (sprint doc).
 */

use App\Support\Deploy\DeploymentRollbackScanner;
use Symfony\Component\Process\Process;

const LP_SHA = '9312818fc9115707b9e0b351ae805080be465b4a';
const LP_ENTRY = '/usr/local/sbin/dms-deploy-operator';

/** @return array{0:int,1:string,2:string} */
function lpBash(string $script, array $env = [], ?string $cwd = null): array
{
    $process = new Process(['bash', '-c', $script], $cwd ?? base_path(), $env === [] ? null : $env);
    $process->setTimeout(120);
    $process->run();

    return [(int) $process->getExitCode(), $process->getOutput(), $process->getErrorOutput()];
}

function lpSource(string $relative): string
{
    return '. '.escapeshellarg(base_path($relative)).';';
}

function lpRead(string $relative): string
{
    return (string) file_get_contents(base_path($relative));
}

/** Non-comment lines of a script. */
function lpCode(string $relative): string
{
    return (string) preg_replace('/^\s*#.*$/m', '', lpRead($relative));
}

function lpMe(): array
{
    return [
        'user' => trim((string) shell_exec('id -un')),
        'group' => trim((string) shell_exec('id -gn')),
        'uid' => trim((string) shell_exec('id -u')),
    ];
}

/** A directory of stub commands placed first on PATH. */
function lpStubs(array $stubs): string
{
    $dir = tempArtifactDir('lp-stubs-', 0o755);
    foreach ($stubs as $name => $body) {
        file_put_contents("{$dir}/{$name}", "#!/bin/bash\n{$body}\n");
        chmod("{$dir}/{$name}", 0o755);
    }

    return $dir;
}

/** sudo's long listing (`sudo -ll -U <user>`) for one account. */
function lpListing(string $user, array $entries, string $defaults = 'env_reset, mail_badpass, use_pty', array $cmdDefaults = []): string
{
    if ($entries === []) {
        return "User {$user} is not allowed to run sudo on DaengtisiaMS.\n";
    }
    $text = "Matching Defaults entries for {$user} on DaengtisiaMS:\n    {$defaults}\n\n";
    if ($cmdDefaults !== []) {
        $text .= "Runas and Command-specific defaults for {$user}:\n    ".implode("\n    ", $cmdDefaults)."\n\n";
    }
    $text .= "User {$user} may run the following commands on DaengtisiaMS:\n";
    foreach ($entries as $entry) {
        $text .= "\nSudoers entry: {$entry['file']}\n    RunAsUsers: {$entry['runas']}\n";
        if (($entry['groups'] ?? '') !== '') {
            $text .= "    RunAsGroups: {$entry['groups']}\n";
        }
        if (($entry['options'] ?? '') !== '') {
            $text .= "    Options: {$entry['options']}\n";
        }
        foreach ($entry['extra'] ?? [] as $extra) {
            $text .= "    {$extra}\n";
        }
        $text .= "    Commands:\n";
        foreach ($entry['commands'] as $command) {
            $text .= "\t{$command}\n";
        }
    }

    return $text;
}

const LP_OP_DEFAULTS = 'env_reset, mail_badpass, secure_path=/usr/local/sbin\\:/usr/local/bin\\:/usr/sbin\\:/usr/bin\\:/sbin\\:/bin\\:/snap/bin, use_pty, env_reset, !setenv, secure_path=/usr/local/sbin\\:/usr/local/bin\\:/usr/sbin\\:/usr/bin';

function lpClassify(string $listing, int $isAdmin): array
{
    $file = tempArtifactDir('lp-listing-').'/listing';
    file_put_contents($file, $listing);
    [$exit, $out] = lpBash(lpSource('scripts/lib/sudo-policy.sh').' dms_sudo_classify '.LP_ENTRY." {$isAdmin} < ".escapeshellarg($file));

    return [$exit, trim($out)];
}

function lpOperatorCommands(): array
{
    return [
        LP_ENTRY.' ^(status|follow|trust-check|drift-check|sudo-policy-check|posture)$',
        LP_ENTRY.' ^verify [0-9]{8}-[0-9]{6}$',
        LP_ENTRY.' ^start --release-id [a-z0-9][a-z0-9-][a-z0-9-]+ --approved-sha [0-9a-f]{40}$',
    ];
}

function lpAllowedRequests(): array
{
    return [
        'status' => ['status', 'status'],
        'follow' => ['follow', 'follow'],
        'trust-check' => ['trust-check', 'trust-check'],
        'drift-check' => ['drift-check', 'drift-check'],
        'sudo-policy-check' => ['sudo-policy-check', 'sudo-policy-check'],
        'posture' => ['posture', 'posture'],
        'verify' => ['verify 20261115-063339', 'verify 20261115-063339'],
        'start' => ['start --release-id dms-deploy-privsep-phase-2 --approved-sha '.LP_SHA, 'start --release-id dms-deploy-privsep-phase-2 --approved-sha '.LP_SHA],
        'start, 80-character id' => ['start --release-id '.str_repeat('a', 80).' --approved-sha '.LP_SHA, 'start --release-id '.str_repeat('a', 80).' --approved-sha '.LP_SHA],
    ];
}

function lpDeniedRequests(): array
{
    return [
        'empty' => [''],
        'a shell' => ['bash'],
        'a shell with a script' => ['bash -c id'],
        'chaining' => ['status; id'],
        'and-chaining' => ['status && id'],
        'a pipe' => ['status | sh'],
        'a redirect' => ['status > /tmp/x'],
        'command substitution' => ['verify $(id)'],
        'backticks' => ['verify `id`'],
        'a glob' => ['verify 2026111?-063339'],
        'an extra word' => ['status now'],
        'verify with an extra word' => ['verify 20261115-063339 x'],
        'a malformed deploy id' => ['verify 2026111-063339'],
        'uppercase' => ['STATUS'],
        'two spaces' => ['verify  20261115-063339'],
        'a leading space' => [' status'],
        'a trailing space' => ['status '],
        'a tab' => ["verify\t20261115-063339"],
        'a newline' => ["status\nid"],
        'non-ASCII' => ["statu\u{0161}"],
        'rollback' => ['rollback '.LP_SHA],
        'restore' => ['restore'],
        'the one-time transition check' => ['verify-transition 20261115-063339 '.LP_SHA],
        'run (start + follow)' => ['run --release-id dms-x --approved-sha '.LP_SHA],
        'a two-character release id' => ['start --release-id ab --approved-sha '.LP_SHA],
        'an 81-character release id' => ['start --release-id '.str_repeat('a', 81).' --approved-sha '.LP_SHA],
        'a release id starting with a dash' => ['start --release-id -abc --approved-sha '.LP_SHA],
        'an uppercase SHA' => ['start --release-id dms-x --approved-sha '.strtoupper(LP_SHA)],
        'an abbreviated SHA' => ['start --release-id dms-x --approved-sha '.substr(LP_SHA, 0, 12)],
        'swapped flags' => ['start --approved-sha '.LP_SHA.' --release-id dms-x'],
        'a missing SHA' => ['start --release-id dms-x'],
        'over 200 characters' => [str_repeat('a', 201)],
    ];
}

// ── 1. OD-6: the operator entrypoint ─────────────────────────────────────────

it('accepts exactly the allowlisted operator requests, split without a shell', function (string $request, string $words) {
    [$exit, $out, $err] = lpBash(lpSource('scripts/deploy-operator-entrypoint.sh').' dms_operator_split '.escapeshellarg($request));

    expect($exit)->toBe(0, $err)->and(trim(str_replace("\n", ' ', $out)))->toBe($words);
})->with(lpAllowedRequests());

it('refuses every other operator request before sudo is ever called', function (string $request) {
    [$exit, $out] = lpBash(lpSource('scripts/deploy-operator-entrypoint.sh').' dms_operator_split '.escapeshellarg($request));
    expect($exit)->not->toBe(0)->and($out)->toBe('');

    // As the SSH gateway (an unprivileged caller): exit 64, DENIED, no sudo.
    $sudoLog = tempArtifactDir('lp-sudo-').'/called';
    $stubs = lpStubs(['sudo' => 'echo called >> '.escapeshellarg($sudoLog)]);
    [$exit, , $err] = lpBash('bash '.escapeshellarg(base_path('scripts/deploy-operator-entrypoint.sh')), [
        'SSH_ORIGINAL_COMMAND' => $request,
        'PATH' => $stubs.':/usr/bin:/bin',
    ]);
    expect($exit)->toBe(64)->and($err)->toContain('DEPLOY_OPERATOR=DENIED')
        ->and(file_exists($sudoLog))->toBeFalse();
})->with(lpDeniedRequests());

it('gives an interactive login no shell', function () {
    [$exit, , $err] = lpBash('env -u SSH_ORIGINAL_COMMAND bash '.escapeshellarg(base_path('scripts/deploy-operator-entrypoint.sh')));

    expect($exit)->toBe(64)->and($err)->toContain('never a shell');
});

it('refuses to dispatch when the request is not allowlisted or the installed entrypoint is not trusted', function () {
    [$exit, , $err] = lpBash(lpSource('scripts/deploy-operator-entrypoint.sh').' dms_operator_dispatch bash -c id');
    expect($exit)->toBe(64)->and($err)->toContain('not an allowlisted request');

    // No root-owned entrypoint is installed where the tests run: nothing runs.
    if (! file_exists(LP_ENTRY)) {
        [$exit, , $err] = lpBash(lpSource('scripts/deploy-operator-entrypoint.sh').' dms_operator_dispatch status');
        expect($exit)->toBe(65)->and($err)->toContain('untrusted');
    }
});

it('trusts only root-owned, non-writable chains, trees and PATH entries', function () {
    $me = lpMe();
    $src = lpSource('scripts/deploy-operator-entrypoint.sh');

    expect(lpBash($src.' dms_operator_trusted_chain 0 /usr/bin/bash')[0])->toBe(0)
        ->and(lpBash($src.' dms_operator_trusted_path 0 /usr/bin:/usr/sbin')[0])->toBe(0);

    [$exit, $out] = lpBash($src.' dms_operator_trusted_chain 0 /tmp');
    expect($exit)->toBe(1)->and($out)->toContain('writable by group or others');
    [$exit, $out] = lpBash($src.' dms_operator_trusted_path 0 /usr/bin:/tmp');
    expect($exit)->toBe(1)->and($out)->toContain('PATH entry /tmp');

    $tree = tempArtifactDir('lp-tree-', 0o755);
    mkdir("{$tree}/lib", 0o755);
    file_put_contents("{$tree}/lib/a.sh", 'x');
    chmod("{$tree}/lib/a.sh", 0o644);
    expect(lpBash($src." dms_operator_trusted_tree {$me['uid']} ".escapeshellarg($tree))[0])->toBe(0)
        ->and(lpBash($src.' dms_operator_trusted_tree 0 '.escapeshellarg($tree))[0])->toBe(1);

    chmod("{$tree}/lib/a.sh", 0o664);
    expect(lpBash($src." dms_operator_trusted_tree {$me['uid']} ".escapeshellarg($tree))[0])->toBe(1);
    chmod("{$tree}/lib/a.sh", 0o644);
    symlink('/etc/passwd', "{$tree}/lib/link");
    expect(lpBash($src." dms_operator_trusted_tree {$me['uid']} ".escapeshellarg($tree))[0])->toBe(1);
});

it('reads no environment but the SSH request and reaches no break-glass program', function () {
    $code = lpCode('scripts/deploy-operator-entrypoint.sh');

    preg_match_all('/\$\{?([A-Z_][A-Z0-9_]*)/', $code, $vars);
    foreach (array_unique($vars[1]) as $var) {
        expect(str_starts_with($var, 'DMS_OPERATOR_') || in_array($var, ['SSH_ORIGINAL_COMMAND', 'BASH_SOURCE'], true))
            ->toBeTrue("reads \${$var}");
    }
    foreach (['rollback', 'restore', 'provision', 'bk2', 'allowed_signers', 'verify-transition'] as $word) {
        expect(stripos($code, $word))->toBeFalse($word);
    }
    expect($code)->toContain('export PATH="$DMS_OPERATOR_SAFE_PATH"')
        ->and($code)->toContain('dms_operator_require dms_operator_trusted_tree 0 "${DMS_OPERATOR_APP_DIR}/.git"')
        ->and($code)->toContain('dms_operator_require dms_operator_trusted_tools 0 $DMS_OPERATOR_TOOLS')
        ->and($code)->toContain('readonly DMS_OPERATOR_TOOLS="bash env sudo git composer node npm php pg_dump runuser systemd-run sha256sum find stat"');
    expect($code)->toContain('exec /usr/bin/sudo -n -- "$DMS_OPERATOR_ENTRYPOINT"')
        ->and($code)->toContain('exec /usr/bin/env -i PATH="$DMS_OPERATOR_SAFE_PATH"')
        ->and($code)->toContain('/usr/bin/bash --noprofile --norc')
        ->and($code)->toContain('readonly DMS_OPERATOR_ENTRYPOINT=/usr/local/sbin/dms-deploy-operator');
});

it('maps each action to its one in-tree program', function () {
    $src = lpSource('scripts/deploy-operator-entrypoint.sh');
    foreach ([
        'start' => 'scripts/deploy-vps-runner.sh', 'verify' => 'scripts/deploy-vps-runner.sh',
        'drift-check' => 'scripts/privsep-drift-check.sh', 'sudo-policy-check' => 'scripts/sudo-policy-check.sh',
        'posture' => 'scripts/production-posture.sh',
    ] as $action => $program) {
        [, $out] = lpBash($src.' dms_operator_program '.$action);
        expect(trim($out))->toBe($program)->and(file_exists(base_path($program)))->toBeTrue();
    }
    expect(lpBash($src.' dms_operator_program rollback')[0])->toBe(1);
});

// ── 2. The sudo policy ───────────────────────────────────────────────────────

it('classifies each account from sudo\'s own listing', function (string $user, array $entries, string $defaults, int $isAdmin, string $verdict, array $cmdDefaults = []) {
    [$exit, $out] = lpClassify(lpListing($user, $entries, $defaults, $cmdDefaults), $isAdmin);

    expect($out)->toStartWith($verdict)->and($exit)->toBe(str_starts_with($verdict, 'VIOLATION') ? 1 : 0);
})->with(function () {
    $main = ['file' => '/etc/sudoers', 'runas' => 'ALL', 'groups' => 'ALL', 'commands' => ['ALL']];
    $nopasswd = ['file' => '/etc/sudoers.d/10-dmsadmin', 'runas' => 'ALL', 'options' => '!authenticate', 'commands' => ['ALL']];
    $op = ['file' => '/etc/sudoers.d/20-dms-deploy-operator', 'runas' => 'root', 'options' => '!authenticate', 'commands' => lpOperatorCommands()];
    $with = fn (array $entry, array $changes) => array_merge($entry, $changes);

    return [
        'OD-6: the admin with passwordless ALL' => ['dmsadmin', [$main, $nopasswd], 'env_reset', 1, 'VIOLATION unrestricted passwordless sudo'],
        'the admin with password-only ALL' => ['dmsadmin', [$main], 'env_reset', 1, 'ADMIN_PASSWORD'],
        'the admin made passwordless by Defaults' => ['dmsadmin', [$main], 'env_reset, !authenticate', 1, 'VIOLATION the administrator\'s Defaults'],
        'the admin with rootpw (root\'s password is locked)' => ['dmsadmin', [$main], 'env_reset, rootpw', 1, 'VIOLATION the administrator\'s Defaults'],
        'the admin with targetpw' => ['dmsadmin', [$main], 'env_reset, targetpw', 1, 'VIOLATION the administrator\'s Defaults'],
        'the admin passwordless through command-specific Defaults' => ['dmsadmin', [$main], 'env_reset', 1, 'VIOLATION the administrator\'s Defaults', ['Defaults!ALL !authenticate']],
        'the admin with ALL only as another user' => ['dmsadmin', [$with($main, ['runas' => 'www-data', 'groups' => ''])], 'env_reset', 1, 'VIOLATION no password rule gives the administrator root'],
        'another account with password ALL' => ['someone', [$main], 'env_reset', 0, 'VIOLATION unrestricted sudo for an account that is not the declared administrator'],
        'no sudo at all' => ['daengtisiams', [], '', 0, 'NONE'],
        'the operator' => ['op', [$op], LP_OP_DEFAULTS, 0, 'OPERATOR'],
        'the operator with a shell' => ['op', [$with($op, ['commands' => [...lpOperatorCommands(), '/usr/bin/bash']])], LP_OP_DEFAULTS, 0, 'VIOLATION [/usr/bin/bash] is not one of the deploy entrypoint\'s rendered requests'],
        'the operator with any arguments' => ['op', [$with($op, ['commands' => [LP_ENTRY]])], LP_OP_DEFAULTS, 0, 'VIOLATION ['.LP_ENTRY.'] is not one of'],
        'the operator with a broad expression' => ['op', [$with($op, ['commands' => [LP_ENTRY.' ^verify .*$']])], LP_OP_DEFAULTS, 0, 'VIOLATION ['.LP_ENTRY.' ^verify .*$] is not one of'],
        'the operator with a character-class expression' => ['op', [$with($op, ['commands' => [LP_ENTRY.' ^[ -z]+$']])], LP_OP_DEFAULTS, 0, 'VIOLATION ['.LP_ENTRY.' ^[ -z]+$] is not one of'],
        'the operator with a glob' => ['op', [$with($op, ['commands' => [LP_ENTRY.' verify *']])], LP_OP_DEFAULTS, 0, 'VIOLATION ['.LP_ENTRY.' verify *] is not one of'],
        'the operator as any user' => ['op', [$with($op, ['runas' => 'ALL'])], LP_OP_DEFAULTS, 0, 'VIOLATION the entrypoint runs as [ALL]'],
        'the operator with a run-as group' => ['op', [$with($op, ['groups' => 'ALL'])], LP_OP_DEFAULTS, 0, 'VIOLATION the entrypoint runs as [root:ALL]'],
        'the operator with SETENV' => ['op', [$with($op, ['options' => '!authenticate, setenv'])], LP_OP_DEFAULTS, 0, 'VIOLATION SETENV'],
        'the operator with another tag' => ['op', [$with($op, ['options' => '!authenticate, !noexec'])], LP_OP_DEFAULTS, 0, 'VIOLATION the entrypoint rule carries options other than NOPASSWD'],
        'the operator with a chroot' => ['op', [$with($op, ['extra' => ['Chroot: *']])], LP_OP_DEFAULTS, 0, 'VIOLATION the entrypoint rule carries a Chroot'],
        'the operator with a working directory' => ['op', [$with($op, ['extra' => ['Cwd: *']])], LP_OP_DEFAULTS, 0, 'VIOLATION the entrypoint rule carries a Chroot, Cwd'],
        'the operator with command-specific Defaults' => ['op', [$op], LP_OP_DEFAULTS, 0, 'VIOLATION the operator carries run-as or command-specific Defaults', ['Defaults!'.LP_ENTRY.' !env_reset, setenv']],
        'the operator keeping BASH_ENV' => ['op', [$op], LP_OP_DEFAULTS.', env_keep+="BASH_ENV LD_PRELOAD"', 0, 'VIOLATION the operator\'s Defaults weaken'],
        'the operator with runchroot' => ['op', [$op], LP_OP_DEFAULTS.', runchroot=*', 0, 'VIOLATION the operator\'s Defaults weaken'],
        'the operator without env_reset' => ['op', [$op], 'mail_badpass, !env_reset, secure_path=/usr/bin', 0, 'VIOLATION the operator\'s Defaults weaken'],
        'the operator without a secure_path' => ['op', [$op], 'env_reset, !setenv', 0, 'VIOLATION the operator\'s Defaults do not keep env_reset and a secure_path'],
        'the operator with passwordless ALL' => ['op', [$op, $nopasswd], LP_OP_DEFAULTS, 0, 'VIOLATION unrestricted passwordless sudo'],
        'the admin also holding the entrypoint' => ['dmsadmin', [$main, $op], 'env_reset', 1, 'VIOLATION the administrator also holds the operator entrypoint'],
    ];
});

it('renders an operator rule that parses, holds only the entrypoint and classifies as OPERATOR', function () {
    [$exit, $rule, $err] = lpBash(lpSource('scripts/lib/sudo-policy.sh').' dms_sudo_render_operator_rule opuser '.LP_ENTRY);
    expect($exit)->toBe(0, $err)
        ->and($rule)->toContain('Defaults:opuser env_reset, !setenv, secure_path="/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin"')
        ->and($rule)->toContain('opuser ALL=(root) NOPASSWD: ')
        ->and($rule)->not->toContain('SETENV')
        ->and($rule)->not->toMatch('/NOPASSWD:\s*ALL/')
        ->and(substr_count($rule, LP_ENTRY.' ^'))->toBe(3);

    preg_match_all('#'.preg_quote(LP_ENTRY, '#').' (\^[^\n]*\$)#', $rule, $m);
    expect($m[1])->toHaveCount(3);
    $commands = array_map(fn ($expr) => LP_ENTRY.' '.$expr, $m[1]);
    expect($commands)->toBe(lpOperatorCommands());
    [, $verdict] = lpClassify(lpListing('opuser', [['file' => '/etc/sudoers.d/20-dms-deploy-operator', 'runas' => 'root', 'options' => '!authenticate', 'commands' => $commands]], LP_OP_DEFAULTS), 0);
    expect($verdict)->toBe('OPERATOR');

    if (trim((string) shell_exec('command -v visudo')) !== '') {
        $file = tempArtifactDir('lp-sudoers-').'/rule';
        file_put_contents($file, $rule);
        [$exit, $out, $err] = lpBash('visudo -c -f '.escapeshellarg($file));
        expect($exit)->toBe(0, $out.$err);
    }

    foreach (['Root', 'op user', 'x', 'a;b'] as $bad) {
        expect(lpBash(lpSource('scripts/lib/sudo-policy.sh').' dms_sudo_render_operator_rule '.escapeshellarg($bad).' '.LP_ENTRY)[0])->toBe(2);
    }
    expect(lpBash(lpSource('scripts/lib/sudo-policy.sh').' dms_sudo_render_operator_rule opuser "relative/path"')[0])->toBe(2);
});

it('lets sudo pass every request the entrypoint accepts and refuse every shell-shaped one', function () {
    $expressions = array_map(fn ($c) => substr($c, strlen(LP_ENTRY) + 1), lpOperatorCommands());
    $sudoAllows = function (string $args) use ($expressions): bool {
        foreach ($expressions as $expr) {
            if (preg_match('/'.str_replace('/', '\/', $expr).'/D', $args) === 1) {
                return true;
            }
        }

        return false;
    };

    foreach (lpAllowedRequests() as [$request]) {
        expect($sudoAllows($request))->toBeTrue($request);
    }
    foreach (['bash', 'status; id', 'verify $(id)', 'status now', 'rollback '.LP_SHA, 'verify 2026111-063339',
        'start --release-id dms-x --approved-sha '.strtoupper(LP_SHA), "status\nid", 'verify-transition 20261115-063339 '.LP_SHA] as $request) {
        expect($sudoAllows($request))->toBeFalse($request);
    }
});

it('keeps the policy check and the posture read-only and root-only', function (string $script) {
    $code = lpCode($script);
    // No mutating command in command position, no write into /etc, no PHP.
    foreach ([
        '/(?:^|[;&|(]\s*|\b(?:then|do|else)\s+)(?:mv|rm|chmod|chown|install|useradd|usermod|tee|truncate|ln)\s/m',
        '/\bvisudo\s+-f\b/', '/>\s*\/etc\//', '/(?<![\w.\/-])php(?=\s)/',
    ] as $pattern) {
        expect(preg_match($pattern, $code))->toBe(0, "{$script}: {$pattern}");
    }
    [$exit, , $err] = lpBash('bash '.escapeshellarg(base_path($script)));
    expect($exit)->toBe(2)->and($err)->toContain('run');
})->with([
    'sudo policy check' => ['scripts/sudo-policy-check.sh'],
    'production posture' => ['scripts/production-posture.sh'],
]);

it('requires a least-privilege end state in the policy check', function () {
    $code = lpCode('scripts/sudo-policy-check.sh');

    expect($code)->toContain('sudo -ll -U "$user"')
        ->and($code)->toContain('dms_sudo_classify "$ENTRYPOINT" "$is_admin"')
        ->and($code)->toContain('SUDO_POLICY=LEAST_PRIVILEGE')
        ->and($code)->toContain('SUDO_POLICY=NO_OPERATOR')
        ->and($code)->toContain('visudo -c -q')
        ->and($code)->toContain('DEPLOY_OPERATOR_ENTRYPOINT=');
});

it('reads the application log as the runtime user in the posture', function () {
    expect(lpCode('scripts/production-posture.sh'))
        ->toContain('runuser -u "$RUNTIME_USER" -- grep -c')
        ->toContain('ADDRESSES=');
});

// ── 2b. Provisioning (dry run by default, root only) ─────────────────────────

it('provisions only from root, with explicit modes and a complete operator request', function () {
    $script = base_path('scripts/provision-deploy-identities.sh');

    expect(lpBash('bash '.escapeshellarg($script))[0])->toBe(2)
        ->and(lpBash('bash '.escapeshellarg($script).' --operator --operator-user opx')[0])->toBe(2);
    [$exit, , $err] = lpBash('bash '.escapeshellarg($script).' --build-identity');
    expect($exit)->toBe(1)->and($err)->toContain('run as root');
});

it('installs the operator so it cannot change its own key, its sudo or its entrypoint', function () {
    $code = lpCode('scripts/provision-deploy-identities.sh');

    expect($code)->toContain('key_line="restrict,command=\"${ENTRYPOINT}\" ssh-ed25519')
        ->and($code)->toContain('install -d -o root -g root -m 0755 -- "$home"')
        ->and($code)->toContain('install -d -o root -g root -m 0755 -- "${home}/.ssh"')
        ->and($code)->toContain('keytmp="$(mktemp /root/.dms-operator-key.XXXXXX)"')
        ->and($code)->toContain('mv -f -T -- "$keytmp" "${home}/.ssh/authorized_keys"')
        ->and($code)->toContain('usermod -L -- "$OPERATOR_USER"')
        ->and($code)->toContain('[ "$(id -G -- "$OPERATOR_USER" | wc -w)" -ne 1 ]')
        ->and($code)->toContain('if pgrep -u "$(id -u -- "$OPERATOR_USER")"')
        ->and($code)->not->toContain('authorized_keys.new')
        ->and($code)->toContain('rm -f -- "${home}/.ssh/authorized_keys2"')
        ->and($code)->toContain('install -o root -g root -m 0755 -- "${SELF_DIR}/deploy-operator-entrypoint.sh" "${ENTRYPOINT}.new"')
        ->and($code)->toContain('[ "$key_type" = "ssh-ed25519" ]')
        ->and($code)->toContain('--shell /bin/bash "$OPERATOR_USER"');
    expect($code)->not->toMatch('/usermod\s+-aG?\s+(sudo|adm|admin|wheel)/');
});

it('installs the sudoers drop-in only after visudo accepts it and moves it back out if the whole policy fails', function () {
    $code = lpCode('scripts/provision-deploy-identities.sh');
    $pos = fn (string $needle) => strpos($code, $needle);

    // A staged name with a dot: sudo ignores it while it is checked.
    expect($code)->toContain('staged="$(dirname -- "$SUDOERS")/.$(basename -- "$SUDOERS").staged"')
        ->and($pos('visudo -c -q -f "$staged"'))->toBeLessThan($pos('mv -f -- "$staged" "$SUDOERS"'))
        ->and($pos('mv -f -- "$staged" "$SUDOERS"'))->toBeLessThan($pos('mv -f -- "$SUDOERS" "${staged}.rejected"'))
        ->and($code)->toContain('[ "$verdict" = "OPERATOR" ]');
});

it('retires the administrator\'s passwordless sudo only with every safeguard and never deletes it', function () {
    $code = lpCode('scripts/provision-deploy-identities.sh');
    $retire = substr($code, (int) strpos($code, 'retire_admin_nopasswd() {'));

    expect($retire)->toContain('[ "$PASSWORD_VERIFIED" -eq 1 ]')
        ->and($retire)->toContain('[ "$status" = "P" ]')
        ->and($retire)->toContain('[ "$operator_ok" -eq 1 ]')
        // The administrator's listing WITHOUT the passwordless rules must still be
        // password-only root sudo, or the change would lock it out.
        ->and($retire)->toContain('verdict="$(dms_sudo_classify "$ENTRYPOINT" 1 <<< "$without" || true)"')
        ->and($retire)->toContain('[ "$verdict" = "ADMIN_PASSWORD" ]')
        ->and($retire)->toContain('NOPASSWD:\ ?ALL$ ]]')
        ->and($retire)->toContain('mv -- "$file" "${RETIRED_DIR}/')
        ->and($retire)->toContain('[ "$status" = "ADMIN_PASSWORD" ]')
        ->and($retire)->not->toMatch('/\brm\b/');
    // No password is ever read, set or passed.
    expect($code)->not->toMatch('/read\s+-s|chpasswd|passwd\s+--stdin|echo\s+[^|]*\|\s*sudo\s+-S/');
});

// ── 3. R1: composer and npm as the build identity ────────────────────────────

it('provisions the build home as root\'s with only a private cache for the identity', function () {
    $code = lpCode('scripts/provision-deploy-identities.sh');

    expect($code)->toContain('install -d -o root -g root -m 0755 -- "$BUILD_HOME"')
        ->and($code)->toContain('install -d -o "$BUILD_USER" -g "$BUILD_USER" -m 0700 -- "${BUILD_HOME}/cache"')
        ->and($code)->toContain('--shell /usr/sbin/nologin "$BUILD_USER"');
    $check = lpCode('scripts/lib/build-privsep.sh');
    expect($check)->toContain('find "$home" -mindepth 1 -maxdepth 1 ! -name cache -print -quit')
        ->and($check)->toContain('"$(stat -c \'%u %a\' -- "${home}/cache")" != "${uid} 700"')
        ->and($check)->toContain('_dms_build_trusted_chain "$home" 0');
});

it('refuses a missing, shared, privileged or loginable build identity', function () {
    $me = lpMe();
    $src = lpSource('scripts/lib/build-privsep.sh');
    $home = tempArtifactDir('lp-build-home-');

    [$exit, , $err] = lpBash($src.' dms_build_identity_check no-such-build-'.bin2hex(random_bytes(3)).' '.escapeshellarg($home).' daengtisiams daengtisiams');
    expect($exit)->toBe(1)->and($err)->toContain('does not exist');

    [$exit, , $err] = lpBash($src." dms_build_identity_check {$me['user']} ".escapeshellarg($home)." {$me['user']} {$me['group']}");
    expect($exit)->toBe(1)->and($err)->toContain('shared with the runtime');

    [$exit, , $err] = lpBash($src.' dms_build_identity_check root '.escapeshellarg($home).' daengtisiams daengtisiams');
    expect($exit)->toBe(1)->and($err)->toContain('privileged');

    // The test account is a login account in the runtime's role here.
    [$exit, , $err] = lpBash($src." dms_build_identity_check {$me['user']} ".escapeshellarg($home).' no-such-runtime-x no-such-group-x');
    expect($exit)->toBe(1)->and($err)->toMatch('/login shell|belongs to group|home/');
});

it('hands over and claims build trees only when nothing of the build identity runs', function () {
    $me = lpMe();
    $app = tempArtifactDir('lp-build-app-', 0o755);
    mkdir("{$app}/vendor", 0o755);
    $stubs = lpStubs(['pgrep' => 'echo 2']);
    $env = ['PATH' => $stubs.':/usr/bin:/bin'];

    foreach (['dms_build_handover '.$me['user'].' '.$me['group'].' vendor', 'dms_build_claim '.$me['user'].' vendor'] as $call) {
        [$exit, , $err] = lpBash(lpSource('scripts/lib/build-privsep.sh').' '.$call, $env, $app);
        expect($exit)->toBe(1)->and($err)->toContain('process(es) of the build identity are running');
    }
});

it('refuses trees a build could abuse at hand-over and claim', function (string $phase, string $setup, string $message, int $code) {
    $me = lpMe();
    $app = tempArtifactDir('lp-build-app-', 0o755);
    mkdir("{$app}/vendor/pkg", 0o755, true);
    file_put_contents("{$app}/vendor/pkg/a.php", 'x');
    $chownLog = "{$app}/../".basename($app).'-chown.log';
    $stubs = lpStubs(['pgrep' => 'echo 0', 'chown' => 'echo "$*" >> '.escapeshellarg($chownLog)]);
    $env = ['PATH' => $stubs.':/usr/bin:/bin'];
    lpBash($setup, [], $app);

    $tree = $setup === '' ? '/etc' : 'vendor';
    $call = $phase === 'handover'
        ? "dms_build_handover {$me['user']} {$me['group']} {$tree}"
        : "dms_build_claim {$me['user']} {$tree}";
    if (str_contains($message, '..')) {
        $call = str_replace(" {$tree}", ' vendor/../..', $call);
    }
    [$exit, , $err] = lpBash(lpSource('scripts/lib/build-privsep.sh').' '.$call, $env, $app);

    expect($exit)->toBe($code)->and($err)->toContain(str_replace('..', '', $message))
        ->and(file_exists($chownLog))->toBeFalse();
})->with([
    'an absolute tree' => ['handover', '', 'must be a plain relative path', 2],
    'a parent-escaping tree' => ['claim', 'true', '..must be a plain relative path', 2],
    'a symlinked tree at hand-over' => ['handover', 'mv vendor real && ln -s real vendor', 'is a symlink', 1],
    'a hardlinked file at hand-over' => ['handover', 'ln vendor/pkg/a.php vendor/pkg/b.php', 'hardlinked file', 1],
    'a FIFO at the claim' => ['claim', 'mkfifo vendor/pkg/fifo', 'FIFO', 1],
    'a setuid file at the claim' => ['claim', 'chmod 4755 vendor/pkg/a.php', 'setuid/setgid', 1],
    'a group-writable file at the claim' => ['claim', 'chmod 0664 vendor/pkg/a.php', 'writable by group or others', 1],
    'a world-writable directory at the claim' => ['claim', 'chmod 0777 vendor/pkg', 'writable by group or others', 1],
    'a hardlinked file at the claim' => ['claim', 'ln vendor/pkg/a.php vendor/pkg/b.php', 'hardlinked file', 1],
    'a symlinked tree at the claim' => ['claim', 'mv vendor real && ln -s real vendor', 'missing or a symlink', 1],
]);

it('hands a tree over without following links and fails the claim closed until everything is root\'s', function () {
    $me = lpMe();
    $app = tempArtifactDir('lp-build-app-', 0o755);
    mkdir("{$app}/vendor/pkg", 0o755, true);
    file_put_contents("{$app}/vendor/pkg/a.php", 'x');
    $log = tempArtifactDir('lp-chown-').'/log';
    $stubs = lpStubs(['pgrep' => 'echo 0', 'chown' => 'echo "$*" >> '.escapeshellarg($log)]);
    $env = ['PATH' => $stubs.':/usr/bin:/bin'];

    [$exit, , $err] = lpBash(lpSource('scripts/lib/build-privsep.sh')." dms_build_handover {$me['user']} {$me['group']} vendor node_modules", $env, $app);
    expect($exit)->toBe(0, $err)
        ->and(is_dir("{$app}/node_modules"))->toBeTrue()
        ->and(file_get_contents($log))->toBe("-R -h --from=0 {$me['user']}:{$me['group']} -- vendor\n-R -h --from=0 {$me['user']}:{$me['group']} -- node_modules\n");

    // The stubbed chown changes nothing, so the entries stay the test user's:
    // the claim must refuse to report success.
    [$exit, , $err] = lpBash(lpSource('scripts/lib/build-privsep.sh')." dms_build_claim {$me['user']} vendor", $env, $app);
    expect($exit)->toBe(1)->and($err)->toContain('still not root-owned and read-only after the claim')
        ->and(file_get_contents($log))->toContain("-R -h root:root -- vendor\n");
});

it('runs a build step in a sandboxed transient service with a clean environment', function () {
    $me = lpMe();
    $home = tempArtifactDir('lp-build-home-');
    $stubs = lpStubs(['systemd-run' => 'printf "%s\n" "$@"; exit 7']);
    [$exit, $out] = lpBash(
        lpSource('scripts/lib/build-privsep.sh')." dms_build_run {$me['user']} ".escapeshellarg($home)
        .' /var/www/app "/var/www/app/node_modules /var/www/app/public/build" "/var/www/app/.env /var/www/app/.env.production" npm run build',
        ['PATH' => $stubs.':/usr/bin:/bin', 'DB_PASSWORD' => 'lp-secret-value', 'APP_KEY' => 'lp-secret-key'],
    );

    expect($exit)->toBe(7);
    foreach ([
        '--wait', '--pipe', '--collect', '--service-type=exec',
        "User={$me['user']}", "Group={$me['group']}", 'WorkingDirectory=/var/www/app', 'UMask=0022',
        'NoNewPrivileges=yes', 'RestrictSUIDSGID=yes', 'CapabilityBoundingSet=', 'PrivateTmp=yes', 'PrivateDevices=yes',
        'ProtectSystem=strict', 'ProtectHome=yes', 'ProtectProc=invisible', 'KillMode=control-group', 'RuntimeMaxSec=1800',
        "ReadWritePaths={$home}/cache", 'ReadWritePaths=/var/www/app/node_modules', 'ReadWritePaths=/var/www/app/public/build',
        'BindReadOnlyPaths=/dev/null:/var/www/app/.env', 'BindReadOnlyPaths=/dev/null:/var/www/app/.env.production',
        'IPAddressAllow=127.0.0.53', 'IPAddressDeny=localhost',
        'InaccessiblePaths=-/var/www/app/storage -/var/www/app/bootstrap/cache -/var/www/app/.git',
        "Environment=HOME={$home}/cache PATH=/usr/local/bin:/usr/bin LANG=C.UTF-8 CI=1",
    ] as $property) {
        expect($out)->toContain($property."\n");
    }
    expect($out)->toContain("--\nnpm\nrun\nbuild\n")
        ->and($out)->not->toContain('lp-secret')
        ->and($out)->not->toContain("ReadWritePaths=/var/www/app\n")
        ->and($out)->not->toContain("ReadWritePaths={$home}\n");
});

it('refuses to run a build step as root, an unknown account or through an unplain path', function (string $args) {
    $stubs = lpStubs(['systemd-run' => 'echo RAN']);
    [$exit, $out] = lpBash(lpSource('scripts/lib/build-privsep.sh').' dms_build_run '.$args, ['PATH' => $stubs.':/usr/bin:/bin']);

    expect($exit)->toBe(2)->and($out)->not->toContain('RAN');
})->with(function () {
    $me = lpMe()['user'];

    return [
        'root' => ['root /var/lib/b /var/www/app "" "" npm ci'],
        'an unknown account' => ['no-such-build-x /var/lib/b /var/www/app "" "" npm ci'],
        'a writable path with a space' => [$me.' /var/lib/b /var/www/app "/var/www/my app" "" npm ci'],
        'a parent-escaping writable path' => [$me.' /var/lib/b /var/www/app "/var/www/app/../../etc" "" npm ci'],
        'a relative work dir' => [$me.' /var/lib/b app "" "" npm ci'],
        'no command' => [$me.' /var/lib/b /var/www/app "" ""'],
    ];
});

it('masks exactly the environment files the asset build reads, whatever else lies beside them', function () {
    $app = tempArtifactDir('lp-env-', 0o755);
    foreach (['.env', '.env.production', '.env.local', '.env.backup-20261103', '.env.odd:name', 'env.txt', '.envrc'] as $file) {
        file_put_contents("{$app}/{$file}", 'x');
    }
    [$exit, $out] = lpBash(lpSource('scripts/lib/build-privsep.sh').' dms_build_env_masks '.escapeshellarg($app));
    $masked = array_map('basename', preg_split('/\s+/', trim($out)) ?: []);
    sort($masked);

    expect($exit)->toBe(0)->and($masked)->toBe(['.env', '.env.local', '.env.production']);
});

it('computes the build masks before the first mutation of deploy and rollback', function (string $script) {
    $code = lpCode($script);

    expect(strpos($code, 'BUILD_MASKS="$(dms_build_env_masks "$APP_DIR")"'))->toBeLessThan(strpos($code, 'dms_write_private_dump'))
        ->and(substr_count($code, 'BUILD_MASKS="$('))->toBe(1);
})->with(['deploy' => ['scripts/deploy-vps.sh'], 'rollback' => ['scripts/rollback-vps.sh']]);

it('runs composer and npm only as the build identity in deploy and rollback, claimed before anything loads them', function (string $script, string $after) {
    $code = lpCode($script);
    $pos = function (string $needle) use ($code): int {
        $at = strpos($code, $needle);
        expect($at)->not->toBeFalse($needle);

        return (int) $at;
    };

    expect($pos('dms_build_identity_check "$BUILD_USER" "$BUILD_HOME" "$RUNTIME_USER" "$RUNTIME_GROUP"'))
        ->toBeLessThan($pos('dms_write_private_dump'))
        ->and($pos('dms_build_handover "$BUILD_USER" "$BUILD_GROUP" vendor'))->toBeLessThan($pos('"$BUILD_MASKS" composer install'))
        ->and($pos('"$BUILD_MASKS" composer install'))->toBeLessThan($pos('dms_build_claim "$BUILD_USER" vendor'))
        ->and($pos('dms_build_claim "$BUILD_USER" vendor'))->toBeLessThan($pos('as_runtime php artisan package:discover --ansi'))
        ->and($pos('"$BUILD_MASKS" npm run build'))->toBeLessThan($pos('dms_build_claim "$BUILD_USER" node_modules public/build'))
        ->and($pos('dms_build_claim "$BUILD_USER" node_modules public/build'))->toBeLessThan($pos($after))
        ->and($pos('dms_build_assert_no_symlinks public/build'))->toBeLessThan($pos('dms_privsep_final_gate 0 0'));

    // Every composer/npm line is a build hand-off or a version probe.
    foreach (preg_split('/\R/', $code) ?: [] as $line) {
        if (preg_match('/(?<![\w.\/-])(?:composer|npm|npx)(?=[\s\'"`;)|&]|$)/', $line) === 1) {
            expect(preg_match('/^\s*dms_build_run\b|--version/', $line))->toBe(1, $line);
        }
    }
    expect($code)->toContain('cd / && COMPOSER_ALLOW_SUPERUSER=1 composer --version --no-plugins');
})->with([
    'deploy' => ['scripts/deploy-vps.sh', 'as_runtime php artisan migrate --force'],
    'rollback' => ['scripts/rollback-vps.sh', 'as_runtime php artisan foundation:backup-verify'],
]);

it('rejects each way root composer or npm could come back', function (string $regression) {
    $script = lpRead('scripts/deploy-vps.sh')."\n".$regression."\n";

    expect(implode("\n", (new DeploymentRollbackScanner)->privilegeSeparationIssues($script)))
        ->toContain('composer/npm runs as root');
})->with([
    'root composer even without scripts' => ['composer install --no-dev --optimize-autoloader --no-scripts'],
    'root npm ci' => ['npm ci'],
    'root npm build' => ['npm run build'],
    'npx' => ['npx vite build'],
    'node script' => ['node scripts/build.js'],
    'yarn' => ['yarn install'],
    'pnpm' => ['pnpm install'],
    'absolute npm path' => ['/usr/local/bin/npm ci'],
    'npm behind timeout' => ['timeout 600 npm ci'],
    'npm after &&' => ['true && npm ci'],
    'npm in a subshell' => ['x="$(npm run build)"'],
    'npm split over lines' => ["npm \\\n  ci"],
    'composer plugin probe without --version' => ['COMPOSER_ALLOW_SUPERUSER=1 composer diagnose'],
    'npm chained after a hand-off' => ['dms_build_run "$BUILD_USER" "$BUILD_HOME" "$APP_DIR" "${APP_DIR}/node_modules" "$BUILD_MASKS" npm ci && npm run build'],
    'npm piped after a hand-off' => ['dms_build_run "$BUILD_USER" "$BUILD_HOME" "$APP_DIR" "" "" composer --version | npm ci'],
    'nodejs' => ['nodejs scripts/build.js'],
    'yarnpkg' => ['yarnpkg install'],
    'bun' => ['bun install'],
    'npm-cli.js by path' => ['/usr/lib/node_modules/npm/bin/npm-cli.js ci'],
]);

it('accepts the build hand-off and the version probes', function () {
    $scanner = new DeploymentRollbackScanner;
    $base = lpRead('scripts/deploy-vps.sh');

    foreach ([
        'dms_build_run "$BUILD_USER" "$BUILD_HOME" "$APP_DIR" "${APP_DIR}/node_modules" "$BUILD_MASKS" npm ci',
        'dms_build_handover "$BUILD_USER" "$BUILD_GROUP" node_modules public/build',
        'echo "$(cd / && npm --version 2>/dev/null || true)"',
        'echo "$(cd / && node --version 2>/dev/null || true)"',
        'echo "== NPM build (build identity) =="',
        '# npm ci in a comment is not code',
    ] as $line) {
        expect($scanner->privilegeSeparationIssues($base."\n".$line."\n"))->toBe([], $line);
    }
});

it('carries the build library in the immutable closure and the build identity in the host authority', function () {
    $authority = lpRead('deploy/production-host.conf');

    expect(config('deployment_entrypoint.execution_closure.closure_members'))->toContain('scripts/lib/build-privsep.sh')
        ->and($authority)->toMatch('/^DMS_PRODUCTION_BUILD_USER=daengtisiams-build$/m')
        ->and($authority)->toMatch('/^DMS_PRODUCTION_BUILD_HOME=\/var\/lib\/daengtisiams-build$/m')
        ->and($authority)->toMatch('/^DMS_PRODUCTION_OPERATOR_ENTRYPOINT=\/usr\/local\/sbin\/dms-deploy-operator$/m')
        ->and($authority)->toMatch('/^DMS_PRODUCTION_OPERATOR_SUDOERS=\/etc\/sudoers\.d\/20-dms-deploy-operator$/m')
        ->and($authority)->toMatch('/^DMS_PRODUCTION_DEPLOY_SSH_ALIAS=daengtisiams-biznet-deploy$/m')
        ->and(lpRead('scripts/lib/production-host-preflight.sh'))->toContain('systemctl systemd-run pgrep)');
});

it('rehearses the build on a scratch export without touching the application', function () {
    $code = lpCode('scripts/build-privsep-rehearsal.sh');

    expect($code)->toContain('WORK="$(mktemp -d /var/lib/dms-build-rehearsal.XXXXXX)"')
        ->and($code)->toContain('git -C "$APP_DIR" archive "$REF" | tar -x --no-same-owner --no-same-permissions -C "$WORK"')
        ->and($code)->toContain("printf 'REHEARSAL_PLACEHOLDER=1\\n' > \"\${WORK}/.env\"")
        ->and($code)->toContain('BUILD_PRIVSEP_REHEARSAL=PASS')
        ->and($code)->not->toContain('php artisan');
    // The application directory is only ever READ: its git objects, its
    // identity authority and the live manifest's digest.
    preg_match_all('/^.*\bAPP_DIR\b.*$/m', $code, $uses);
    foreach ($uses[0] as $use) {
        expect(
            str_contains($use, 'APP_DIR="$(value DMS_PRODUCTION_APP_DIR)"')
            || str_contains($use, 'git -C "$APP_DIR" ')
            || str_contains($use, '"${APP_DIR}/deploy/runtime-identity.conf"')
            || str_contains($use, 'sha256sum < "${APP_DIR}/public/build/manifest.json"')
        )->toBeTrue($use);
    }
    expect(lpBash('bash '.escapeshellarg(base_path('scripts/build-privsep-rehearsal.sh')).' --ref abc')[0])->toBe(2);
});

it('hashes the pre-deploy and pre-rollback dumps from root\'s own copy of pg_dump\'s bytes', function (string $script, string $echo) {
    $code = lpCode($script);
    $pos = fn (string $needle) => strpos($code, $needle);

    expect($code)->toContain('DUMP_STAGING="${DMS_DEPLOY_SNAPSHOT_DIR}/dump-staging"')
        ->and($code)->toContain('mkdir -m 0700 -- "$DUMP_STAGING"')
        ->and($code)->toContain('PGPASSWORD="${DB_PASSWORD}" dms_write_private_dump "${DUMP_STAGING}/dump.sql" pg_dump')
        ->and($code)->toContain($echo)
        ->and($code)->not->toContain('sha256=$(as_runtime sha256sum')
        ->and($pos('DUMP_SHA256="$(sha256sum < "${DUMP_STAGING}/dump.sql"'))->toBeLessThan($pos('dms_privsep_as_runtime_fn_from "${DUMP_STAGING}/dump.sql"'))
        ->and($pos('!= "$DUMP_SHA256" ]; then'))->toBeLessThan($pos($echo))
        ->and($pos('rm -rf -- "$DUMP_STAGING"'))->toBeLessThan($pos($echo));
    // The staging copy is root's and lives in the run's root-only snapshot,
    // never in a runtime tree.
    expect(preg_match('/DUMP_STAGING=.*storage/', $code))->toBe(0);
})->with([
    'deploy' => ['scripts/deploy-vps.sh', 'echo "Pre-deploy backup: ${BACKUP} sha256=${DUMP_SHA256}"'],
    'rollback' => ['scripts/rollback-vps.sh', 'echo "Backup written: ${BACKUP} sha256=${DUMP_SHA256}"'],
]);

it('lets root write only its own staging dump, never a dump in a runtime tree', function () {
    $scanner = new DeploymentRollbackScanner;
    $base = lpRead('scripts/deploy-vps.sh');

    expect($scanner->privilegeSeparationIssues($base."\n".'PGPASSWORD="${DB_PASSWORD}" dms_write_private_dump "${DUMP_STAGING}/dump.sql" pg_dump'."\n"))->toBe([]);
    foreach ([
        'PGPASSWORD="${DB_PASSWORD}" dms_write_private_dump "$BACKUP" pg_dump',
        'dms_write_private_dump "${DUMP_STAGING}x/dump.sql" pg_dump',
        'dms_prepare_private_backup_dir "${DUMP_STAGING}/x"',
    ] as $line) {
        expect(implode("\n", $scanner->privilegeSeparationIssues($base."\n".$line."\n")))->toContain('root dump helper');
    }
});

it('hands the runtime user a root file only as a read-only stdin', function () {
    $me = lpMe();
    $dir = tempArtifactDir('lp-from-', 0o755);
    file_put_contents("{$dir}/bytes", "dump-bytes\n");
    [$exit, $out, $err] = lpBash(lpSource('scripts/lib/runtime-privsep.sh')
        ."probe() { cat > copy; flags=\$(awk '/^flags:/ {print \$2}' /proc/self/fdinfo/0); echo \"mode=\$(( 0\$flags & 3 ))\"; ls /proc/self/fd | sort -n | tr '\\n' ' '; }\n"
        .'dms_privsep_as_runtime_fn_from '.escapeshellarg("{$dir}/bytes")." {$me['user']} '' probe", [], $dir);

    expect($exit)->toBe(0, $err)
        ->and(file_get_contents("{$dir}/copy"))->toBe("dump-bytes\n")
        ->and($out)->toContain('mode=0');

    symlink("{$dir}/bytes", "{$dir}/link");
    foreach (["{$dir}/link", "{$dir}/missing", ''] as $input) {
        [$exit, , $err] = lpBash(lpSource('scripts/lib/runtime-privsep.sh').' greet() { :; }; dms_privsep_as_runtime_fn_from '.escapeshellarg($input)." {$me['user']} '' greet", [], $dir);
        expect($exit)->toBe(2)->and($err)->toContain('is not a regular file');
    }
    // The rule 233 entry points keep /dev/null as stdin.
    [, $out] = lpBash(lpSource('scripts/lib/runtime-privsep.sh')." dms_privsep_as_runtime {$me['user']} readlink /proc/self/fd/0", [], $dir);
    expect(trim($out))->toBe('/dev/null');
});

// ── 4. R4: restore integrity ─────────────────────────────────────────────────

/** A trusted evidence directory with one deploy's log and record. */
function lpEvidence(string $logBody, string $host = 'lp-host', string $recordId = '20261115-063339'): array
{
    $root = tempArtifactDir('lp-evidence-', 0o755);
    $dir = "{$root}/runner";
    mkdir($dir, 0o700);
    file_put_contents("{$dir}/deploy-20261115-063339.log", $logBody);
    file_put_contents("{$dir}/deploy-20261115-063339.host", "host={$host}\ndeploy_id={$recordId}\n");
    chmod("{$dir}/deploy-20261115-063339.log", 0o600);
    chmod("{$dir}/deploy-20261115-063339.host", 0o600);

    return [$root, $dir];
}

/** @return array{0:int,1:string,2:string} */
function lpEvidenceDigest(string $dir, string $id = '20261115-063339'): array
{
    return lpBash(lpSource('scripts/lib/trusted-path.sh').lpSource('scripts/lib/restore-integrity.sh')
        .' dms_restore_evidence_digest '.escapeshellarg($dir).' '.escapeshellarg($id).' lp-host /nonexistent-app '.lpMe()['uid']);
}

it('reads the expected digest only from one well-formed line of the deploy\'s root-only log', function () {
    $digest = hash('sha256', 'x');
    $line = "Pre-deploy backup: storage/app/backups/deploy/pre_auto_deploy_20261115-063340.sql sha256={$digest}\n";
    [, $dir] = lpEvidence("== Backup DB ==\n{$line}== Advance checkout ==\n");

    [$exit, $out, $err] = lpEvidenceDigest($dir);
    expect($exit)->toBe(0, $err)->and(trim($out))->toBe("storage/app/backups/deploy/pre_auto_deploy_20261115-063340.sql {$digest}");
});

it('refuses missing, ambiguous, forged or untrusted restore evidence', function (string $case) {
    $digest = hash('sha256', 'x');
    $line = "Pre-deploy backup: storage/app/backups/deploy/pre_auto_deploy_20261115-063340.sql sha256={$digest}\n";
    $body = match ($case) {
        'no line' => "DEPLOY OK\n",
        'a second (forged) line' => $line.str_replace($digest, hash('sha256', 'y'), $line),
        'a malformed digest' => str_replace($digest, 'abc', $line),
        'a path outside the deploy backups' => str_replace('storage/app/backups/deploy/', 'storage/app/private/', $line),
        default => $line,
    };
    [$root, $dir] = lpEvidence($body, $case === 'another host\'s record' ? 'other-host' : 'lp-host',
        $case === 'another deploy\'s record' ? '20261114-225508' : '20261115-063339');
    $id = '20261115-063339';
    match ($case) {
        'a group-writable evidence directory' => chmod($dir, 0o770),
        'a symlinked log' => (function () use ($dir) {
            rename("{$dir}/deploy-20261115-063339.log", "{$dir}/real.log");
            symlink("{$dir}/real.log", "{$dir}/deploy-20261115-063339.log");
        })(),
        'a group-writable log' => chmod("{$dir}/deploy-20261115-063339.log", 0o620),
        default => null,
    };
    if ($case === 'a malformed deploy id') {
        $id = '20261115-06333';
    }
    if ($case === 'a missing log') {
        unlink("{$dir}/deploy-20261115-063339.log");
    }

    [$exit, $out] = lpEvidenceDigest($dir, $id);
    expect($exit)->toBe(1)->and($out)->toBe('');
})->with([
    'no line', 'a second (forged) line', 'a malformed digest', 'a path outside the deploy backups',
    'another host\'s record', 'another deploy\'s record', 'a group-writable evidence directory', 'a symlinked log',
    'a group-writable log', 'a malformed deploy id', 'a missing log',
]);

it('restores only a private copy whose digest matches and which is a pg_dump', function (string $case) {
    $work = tempArtifactDir('lp-restore-', 0o755);
    $dump = "{$work}/dump.sql";
    $content = "--\n-- PostgreSQL database dump\n--\nSELECT 1;\n";
    file_put_contents($dump, $content);
    $expected = hash('sha256', $content);
    $staging = tempArtifactDir('lp-staging-');
    match ($case) {
        'a changed byte' => file_put_contents($dump, str_replace('SELECT 1', 'SELECT 2', $content)),
        'a planted meta-command' => file_put_contents($dump, $content."\\! id\n"),
        'not a pg_dump' => (function () use ($dump, &$expected) {
            file_put_contents($dump, "SELECT 1;\n");
            $expected = hash('sha256', "SELECT 1;\n");
        })(),
        'a symlinked dump' => (function () use ($dump, $work) {
            rename($dump, "{$work}/real.sql");
            symlink("{$work}/real.sql", $dump);
        })(),
        'an empty dump' => file_put_contents($dump, ''),
        'a shared staging directory' => chmod($staging, 0o755),
        default => null,
    };

    [$exit, $out, $err] = lpBash(lpSource('scripts/lib/restore-integrity.sh')
        .' staged="$(dms_restore_stage_copy '.escapeshellarg($dump).' '.escapeshellarg($staging).')" && dms_restore_verify_copy "$staged" '.$expected);

    if ($case === 'the original') {
        expect($exit)->toBe(0, $err)->and($out)->toContain("RESTORE_INTEGRITY=VERIFIED sha256={$expected}")
            ->and(substr(sprintf('%o', fileperms("{$staging}/dump.sql")), -3))->toBe('600');
    } else {
        expect($exit)->toBe(1)->and($out)->not->toContain('VERIFIED');
        if (in_array($case, ['a changed byte', 'a planted meta-command'], true)) {
            expect($err)->toContain('DIGEST MISMATCH');
        }
    }
})->with([
    'the original', 'a changed byte', 'a planted meta-command', 'not a pg_dump', 'a symlinked dump', 'an empty dump',
    'a shared staging directory',
]);

it('refuses a restore without trusted digest evidence, before reading any database setting', function (string $script, array $args, string $message) {
    $app = tempArtifactDir('lp-restore-app-', 0o755);
    file_put_contents("{$app}/dump.sql", "--\n-- PostgreSQL database dump\n");
    [$exit, $out, $err] = lpBash('bash '.escapeshellarg(base_path($script)).' '.implode(' ', array_map('escapeshellarg', $args)), ['APP_DIR' => $app], $app);

    expect($exit)->toBe(2)->and($out.$err)->toContain($message);
})->with([
    'restore, no evidence' => ['scripts/restore_postgres.sh', ['--file', 'dump.sql', '--target', 'scratch_x'], 'without trusted digest evidence'],
    'restore, both evidence kinds' => ['scripts/restore_postgres.sh', ['--file', 'dump.sql', '--deploy-id', '20261115-063339', '--expected-sha256', str_repeat('a', 64)], 'not both'],
    'rehearsal of a passed dump, no evidence' => ['scripts/restore-rehearsal.sh', ['dump.sql'], 'needs trusted digest evidence'],
    'rehearsal, both evidence kinds' => ['scripts/restore-rehearsal.sh', ['dump.sql', '--deploy-id', '20261115-063339', '--expected-sha256', str_repeat('a', 64)], 'not both'],
]);

it('streams only the verified staging copy to the superuser psql, after the integrity check', function (string $script, string $reader, string $firstWrite) {
    $code = lpCode($script);

    expect($code)->toContain($reader."() {\n")
        ->and(preg_match('/'.$reader.'\(\) \{\s*cat -- "\$STAGED_[A-Z]+"\s*\}/', $code))->toBe(1)
        ->and(strpos($code, 'dms_restore_verify_copy'))->toBeLessThan(strpos($code, $firstWrite))
        ->and($code)->not->toContain('sudo -n -u "$DUMP_OWNER" -- cat')
        ->and($code)->not->toContain('sudo -n -u "$BACKUP_OWNER" -- cat');
})->with([
    'restore' => ['scripts/restore_postgres.sh', 'read_dump', 'createdb -O'],
    'rehearsal' => ['scripts/restore-rehearsal.sh', 'read_backup', 'createdb -O'],
]);

it('hashes a fresh rehearsal snapshot from root\'s private copy and never reads the runtime copy back', function () {
    $code = lpCode('scripts/restore-rehearsal.sh');

    expect($code)->toContain('as_superuser pg_dump -d "${DB_DATABASE}" > "$STAGED_BACKUP"')
        ->and($code)->toContain('EXPECTED_SHA256="$(sha256sum < "$STAGED_BACKUP" | cut -d\' \' -f1)"')
        ->and($code)->toContain('as_runtime tee "$BACKUP_FILE" < "$STAGED_BACKUP" > /dev/null')
        ->and($code)->not->toContain('restore_postgres');
});

// ── 5. R2: nothing but placeholders under the runtime trees ──────────────────

it('tracks nothing but placeholders under the runtime-owned trees', function () {
    $process = new Process(['git', 'ls-files', '--', 'storage', 'bootstrap/cache'], base_path());
    $process->mustRun();
    $files = array_filter(preg_split('/\R/', trim($process->getOutput())) ?: []);

    expect($files)->not->toBe([]);
    foreach ($files as $file) {
        expect(in_array(basename($file), ['.gitignore', '.gitkeep'], true))
            ->toBeTrue("{$file} is tracked inside a runtime-owned tree: root's checkout would write it and the runtime user could rewrite it");
    }
});

// ── 6. The workstation's operator alias ──────────────────────────────────────

it('verifies the operator alias as neither root nor the administrator', function () {
    if (trim((string) shell_exec('command -v ssh-keygen')) === '' || trim((string) shell_exec('command -v ssh')) === '') {
        $this->markTestSkipped('ssh tools are not installed');
    }
    $dir = tempArtifactDir('lp-target-');
    (new Process(['ssh-keygen', '-q', '-t', 'ed25519', '-N', '', '-C', 'lp', '-f', "{$dir}/hostkey"]))->mustRun();
    $fingerprint = explode(' ', trim((new Process(['ssh-keygen', '-l', '-f', "{$dir}/hostkey.pub"]))->mustRun()->getOutput()))[1];
    $public = implode(' ', array_slice(explode(' ', trim((string) file_get_contents("{$dir}/hostkey.pub"))), 0, 2));
    file_put_contents("{$dir}/known_hosts", "192.0.2.10 {$public}\n");
    file_put_contents("{$dir}/authority.conf", implode("\n", [
        'DMS_PRODUCTION_HOSTNAME=LpProduction', 'DMS_PRODUCTION_ADDRESS=192.0.2.10', 'DMS_PRODUCTION_SSH_ALIAS=lp-admin',
        'DMS_PRODUCTION_ADMIN_USER=dmsadmin', "DMS_PRODUCTION_HOST_KEY_SHA256={$fingerprint}", 'DMS_PRODUCTION_DEPLOY_SSH_ALIAS=lp-deploy',
        'DMS_FROZEN_LEGACY_HOSTNAMES="lp-frozen"', 'DMS_FROZEN_LEGACY_ADDRESSES="198.51.100.7"', 'DMS_FROZEN_LEGACY_SSH_ALIASES="lp-legacy"', '',
    ]));
    $block = fn (string $alias, string $user) => "Host {$alias}\n  HostName 192.0.2.10\n  User {$user}\n  StrictHostKeyChecking yes\n"
        ."  UserKnownHostsFile {$dir}/known_hosts\n  GlobalKnownHostsFile /dev/null\n  UpdateHostKeys no\n  VerifyHostKeyDNS no\n";
    file_put_contents("{$dir}/ssh_config", $block('lp-deploy', 'opdeploy').$block('lp-admin-as-deploy', 'dmsadmin').$block('lp-root-as-deploy', 'root').$block('lp-admin', 'dmsadmin'));
    foreach (['ssh_config', 'known_hosts', 'authority.conf'] as $file) {
        chmod("{$dir}/{$file}", 0o600);
    }
    $run = function (array $args) use ($dir): array {
        $process = new Process(array_merge(['bash', base_path('scripts/production-target-check.sh'), '--authority', "{$dir}/authority.conf", '--ssh-config', "{$dir}/ssh_config"], $args), null, ['HOME' => $dir]);
        $process->run();

        return [(int) $process->getExitCode(), $process->getOutput().$process->getErrorOutput()];
    };

    [$exit, $out] = $run(['--operator']);
    expect($exit)->toBe(0, $out)->and($out)->toContain('login account is the deploy operator')->and($out)->toContain('alias:     lp-deploy');
    foreach (['lp-admin-as-deploy', 'lp-root-as-deploy'] as $alias) {
        [$exit, $out] = $run(['--operator', '--alias', $alias]);
        expect($exit)->toBe(1, $out)->and($out)->toContain('the deploy alias must use the restricted operator account');
    }
    [$exit, $out] = $run([]);
    expect($exit)->toBe(0, $out)->and($out)->toContain("login account is the production admin 'dmsadmin'");
});

it('declares this suite mandatory for the critical gate', function () {
    expect(config('ci_runner.critical_gate_mandatory_suites'))
        ->toContain('tests/Feature/Deploy/DeployHardenLeastPrivilegeTest.php');
});
