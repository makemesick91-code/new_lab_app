<?php

use App\Support\Cicd\SelfHostedRunnerScanner;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

uses()->group('Cicd', 'Deploy', 'Security');

/**
 * DMS-POST-MIGRATION-DEV-INFRA-ALIGNMENT-1 — production is the Biznet host and
 * the Hostinger host is write-frozen.
 *
 * Production moved on 2026-11-03. The repository still described the old host
 * as the target: CG-14 named it, eight live runbooks told operators to reach it
 * with `ssh daengtisiams-vps` (an alias that now resolves to the FROZEN box, as
 * root), and the retired deploy workflow would have opened SSH — trusting any
 * host key — to whatever the domain resolved to, which is now live production.
 *
 * Prose drifts; these assertions do not. Every claim here is read from the one
 * authority (deploy/production-host.conf) or from the files themselves, and the
 * workstation check is exercised for real against synthetic SSH configuration —
 * never against a server.
 */

/** @return array<string, string> */
function phiAuthority(string $path = ''): array
{
    $path = $path !== '' ? $path : base_path('deploy/production-host.conf');
    $values = [];

    foreach (preg_split('/\R/', (string) file_get_contents($path)) ?: [] as $line) {
        if (preg_match('/^(DMS_[A-Z0-9_]+)=(.*)$/', $line, $m)) {
            $values[$m[1]] = trim($m[2], '"');
        }
    }

    return $values;
}

/** @return list<string> */
function phiWords(string $value): array
{
    return array_values(array_filter(preg_split('/\s+/', trim($value)) ?: []));
}

/**
 * Documents and scripts that tell an operator what to do today. Sprint records,
 * evidence files and the CLAUDE.md archive are history: they keep the names that
 * were true when they were written and are deliberately not scanned.
 *
 * @return list<string>
 */
function phiLiveFiles(): array
{
    $patterns = [
        'CLAUDE.md',
        'AGENTS.md',
        '.claude/rules/*.md',
        '.cursor/rules/*.mdc',
        'docs/runbooks/*.md',
        'docs/operations/*.md',
        'docs/ai-knowledge/*.md',
        'scripts/*.sh',
        'scripts/*/*.sh',
    ];

    $files = [];
    foreach ($patterns as $pattern) {
        foreach (glob(base_path($pattern)) ?: [] as $file) {
            $files[] = ltrim(substr($file, strlen(base_path())), '/');
        }
    }

    sort($files);

    return array_values(array_unique($files));
}

/** @return list<array{file: string, line: int, text: string}> */
function phiLinesMatching(string $regex, array $files): array
{
    $hits = [];
    foreach ($files as $file) {
        foreach (preg_split('/\R/', (string) file_get_contents(base_path($file))) ?: [] as $index => $text) {
            if (preg_match($regex, $text)) {
                $hits[] = ['file' => $file, 'line' => $index + 1, 'text' => trim($text)];
            }
        }
    }

    return $hits;
}

function phiToolsAvailable(): bool
{
    foreach (['bash', 'ssh', 'ssh-keygen'] as $tool) {
        $probe = new Process(['bash', '-c', 'command -v '.$tool]);
        if ($probe->run() !== 0) {
            return false;
        }
    }

    return true;
}

/**
 * A complete, offline world for scripts/production-target-check.sh: a real
 * ED25519 host key, a known-hosts file pinning it, an authority naming it, and
 * an SSH config with one alias per scenario. Addresses are RFC 5737
 * documentation ranges, so nothing here can ever reach a real machine.
 *
 * @return array{dir: string, config: string, authority: string, fingerprint: string}
 */
function phiWorld(): array
{
    $dir = tempArtifactDir('phi-world-');

    $keygen = function (string $name) use ($dir): array {
        (new Process(['ssh-keygen', '-q', '-t', 'ed25519', '-N', '', '-C', 'phi', '-f', "{$dir}/{$name}"]))->mustRun();
        $fingerprint = explode(' ', trim((new Process(['ssh-keygen', '-l', '-f', "{$dir}/{$name}.pub"]))->mustRun()->getOutput()))[1];
        $public = implode(' ', array_slice(explode(' ', trim((string) file_get_contents("{$dir}/{$name}.pub"))), 0, 2));

        return [$fingerprint, $public];
    };

    [$pinnedFingerprint, $pinnedPublic] = $keygen('pinned');
    [, $foreignPublic] = $keygen('foreign');

    $address = '192.0.2.10';
    file_put_contents("{$dir}/known_hosts", "{$address} {$pinnedPublic}\n");
    file_put_contents("{$dir}/known_hosts_extra", "{$address} {$pinnedPublic}\n{$address} {$foreignPublic}\n");
    file_put_contents("{$dir}/known_hosts_unpinned", "{$address} {$foreignPublic}\n");

    file_put_contents("{$dir}/authority.conf", implode("\n", [
        'DMS_PRODUCTION_HOSTNAME=PhiProduction',
        "DMS_PRODUCTION_ADDRESS={$address}",
        'DMS_PRODUCTION_SSH_ALIAS=phi-prod',
        'DMS_PRODUCTION_ADMIN_USER=dmsadmin',
        "DMS_PRODUCTION_HOST_KEY_SHA256={$pinnedFingerprint}",
        'DMS_FROZEN_LEGACY_HOSTNAMES="phi-frozen-host"',
        'DMS_FROZEN_LEGACY_ADDRESSES="198.51.100.7"',
        'DMS_FROZEN_LEGACY_SSH_ALIASES="phi-legacy"',
        '',
    ]));

    $block = function (string $alias, array $overrides = []) use ($dir, $address): string {
        $settings = array_merge([
            'HostName' => $address,
            'User' => 'dmsadmin',
            'StrictHostKeyChecking' => 'yes',
            'UserKnownHostsFile' => "{$dir}/known_hosts",
            'GlobalKnownHostsFile' => '/dev/null',
            'UpdateHostKeys' => 'no',
            'VerifyHostKeyDNS' => 'no',
        ], $overrides);

        $lines = ["Host {$alias}"];
        foreach ($settings as $key => $value) {
            $lines[] = "  {$key} {$value}";
        }

        return implode("\n", $lines)."\n";
    };

    $config = implode("\n", [
        $block('phi-prod'),
        $block('phi-legacy'),
        $block('phi-frozen-address', ['HostName' => '198.51.100.7']),
        $block('phi-root', ['User' => 'root']),
        $block('phi-loose', ['StrictHostKeyChecking' => 'no']),
        $block('phi-accept-new', ['StrictHostKeyChecking' => 'accept-new']),
        $block('phi-devnull', ['UserKnownHostsFile' => '/dev/null']),
        $block('phi-dns', ['HostName' => 'production.example.invalid']),
        $block('phi-update', ['UpdateHostKeys' => 'yes']),
        $block('phi-extra-key', ['UserKnownHostsFile' => "{$dir}/known_hosts_extra"]),
        $block('phi-unpinned', ['UserKnownHostsFile' => "{$dir}/known_hosts_unpinned"]),
    ]);
    file_put_contents("{$dir}/ssh_config", $config);

    // ssh refuses a configuration file other accounts can write.
    foreach (['ssh_config', 'known_hosts', 'known_hosts_extra', 'known_hosts_unpinned', 'authority.conf'] as $file) {
        chmod("{$dir}/{$file}", 0o600);
    }

    return [
        'dir' => $dir,
        'config' => "{$dir}/ssh_config",
        'authority' => "{$dir}/authority.conf",
        'fingerprint' => $pinnedFingerprint,
    ];
}

/** @return array{exit: int, output: string, seconds: float} */
function phiRunCheck(array $world, array $args): array
{
    $process = new Process(
        array_merge(['bash', base_path('scripts/production-target-check.sh'),
            '--authority', $world['authority'], '--ssh-config', $world['config']], $args),
        null,
        ['HOME' => $world['dir']],
    );
    $started = microtime(true);
    $process->run();

    return [
        'exit' => (int) $process->getExitCode(),
        'output' => $process->getOutput().$process->getErrorOutput(),
        'seconds' => microtime(true) - $started,
    ];
}

it('declares exactly one production host and keeps it disjoint from the frozen legacy host', function () {
    $authority = phiAuthority();

    foreach ([
        'DMS_PRODUCTION_PROVIDER', 'DMS_PRODUCTION_HOSTNAME', 'DMS_PRODUCTION_ADDRESS', 'DMS_PRODUCTION_DOMAIN',
        'DMS_PRODUCTION_APP_DIR', 'DMS_PRODUCTION_SSH_ALIAS', 'DMS_PRODUCTION_ADMIN_USER',
        'DMS_PRODUCTION_HOST_KEY_TYPE', 'DMS_PRODUCTION_HOST_KEY_SHA256',
        'DMS_FROZEN_LEGACY_PROVIDER', 'DMS_FROZEN_LEGACY_HOSTNAMES', 'DMS_FROZEN_LEGACY_ADDRESSES',
        'DMS_FROZEN_LEGACY_SSH_ALIASES',
    ] as $key) {
        expect($authority[$key] ?? '')->not->toBe('', "{$key} must be declared");
    }

    expect($authority['DMS_PRODUCTION_PROVIDER'])->toBe('Biznet Gio')
        ->and($authority['DMS_PRODUCTION_ADDRESS'])->toBe('139.190.106.38')
        ->and($authority['DMS_PRODUCTION_SSH_ALIAS'])->toBe('daengtisiams-biznet-prod')
        ->and($authority['DMS_PRODUCTION_ADMIN_USER'])->not->toBe('root')
        ->and($authority['DMS_PRODUCTION_HOST_KEY_SHA256'])->toStartWith('SHA256:')
        ->and($authority['DMS_FROZEN_LEGACY_PROVIDER'])->toBe('Hostinger')
        ->and(phiWords($authority['DMS_FROZEN_LEGACY_ADDRESSES']))->toContain('145.79.13.224')
        ->and(phiWords($authority['DMS_FROZEN_LEGACY_SSH_ALIASES']))->toContain('daengtisiams-vps');

    // A target can never be both. Overlap would let the frozen host pass as live.
    expect(phiWords($authority['DMS_FROZEN_LEGACY_ADDRESSES']))->not->toContain($authority['DMS_PRODUCTION_ADDRESS'])
        ->and(phiWords($authority['DMS_FROZEN_LEGACY_HOSTNAMES']))->not->toContain($authority['DMS_PRODUCTION_HOSTNAME'])
        ->and(phiWords($authority['DMS_FROZEN_LEGACY_SSH_ALIASES']))->not->toContain($authority['DMS_PRODUCTION_SSH_ALIAS']);
});

it('keeps the authority free of secret material', function () {
    $raw = (string) file_get_contents(base_path('deploy/production-host.conf'));

    // Judge material and assignments, not words: the file's own comments say
    // what must never be added, and a word match would flag that explanation.
    expect($raw)->not->toContain('-----BEGIN')
        ->and($raw)->not->toMatch('/^\s*(APP_KEY|DB_PASSWORD|DB_USERNAME|PGPASSWORD)\s*=/m')
        ->and($raw)->not->toMatch('/^\s*[A-Z0-9_]*(PASSWORD|TOKEN|SECRET|PRIVATE)[A-Z0-9_]*\s*=/m');

    // Every assignment is one of the declared public identity keys.
    preg_match_all('/^([A-Z0-9_]+)=/m', $raw, $keys);
    foreach ($keys[1] as $key) {
        expect($key)->toMatch('/^DMS_(PRODUCTION|FROZEN_LEGACY)_[A-Z0-9_]+$/');
    }

    // The production-identity guide is written for operators and stays free of
    // the literal environment-file name, like every governance document here.
    expect((string) file_get_contents(base_path('docs/runbooks/production-host-identity.md')))
        ->not->toContain('.'.'env');
});

it('names the production authority and the frozen host in CLAUDE.md CG-14', function () {
    $claude = (string) file_get_contents(base_path('CLAUDE.md'));
    preg_match('/- \*\*CG-14 .*?(?=\n- \*\*CG-15)/s', $claude, $m);
    $cg14 = $m[0] ?? '';
    $authority = phiAuthority();

    expect($cg14)->not->toBe('')
        ->toContain($authority['DMS_PRODUCTION_HOSTNAME'])
        ->toContain($authority['DMS_PRODUCTION_ADDRESS'])
        ->toContain($authority['DMS_PRODUCTION_SSH_ALIAS'])
        ->toContain($authority['DMS_PRODUCTION_ADMIN_USER'])
        ->toContain('deploy/production-host.conf')
        ->toContain('scripts/production-target-check.sh')
        ->toContain('WRITE-FROZEN');

    foreach (phiWords($authority['DMS_FROZEN_LEGACY_HOSTNAMES']) as $hostname) {
        expect($cg14)->toContain($hostname);
    }

    // The regression this sprint closes: CG-14 used to say production is
    // "reached with `ssh daengtisiams-vps`".
    expect($cg14)->not->toMatch('/reached\s+(only\s+)?with\s+`ssh daengtisiams-vps`/');
});

it('never instructs a connection to the frozen host in live documents and scripts', function () {
    $authority = phiAuthority();
    $patterns = [];
    foreach (phiWords($authority['DMS_FROZEN_LEGACY_SSH_ALIASES']) as $alias) {
        $patterns[] = 'ssh\s+(-\S+\s+)*'.preg_quote($alias, '/').'\b';
    }
    foreach (phiWords($authority['DMS_FROZEN_LEGACY_ADDRESSES']) as $address) {
        $patterns[] = '@'.preg_quote($address, '/');
        $patterns[] = 'ssh\s+(-\S+\s+)*'.preg_quote($address, '/');
    }
    $patterns[] = 'ssh\s+(-\S+\s+)*root@';

    // The canonical identity guide is the one place allowed to name the old
    // alias, and only to say it is never used.
    $files = array_values(array_diff(phiLiveFiles(), ['docs/runbooks/production-host-identity.md']));

    expect($files)->toContain('CLAUDE.md')
        ->toContain('docs/runbooks/vps-deploy-rollback-runbook.md')
        ->toContain('scripts/deploy-vps-runner.sh');

    expect(phiLinesMatching('/'.implode('|', $patterns).'/', $files))->toBe([]);
});

it('never relaxes host-key checking in live documents and scripts unless the line forbids it', function () {
    $relaxing = '/StrictHostKeyChecking[= ]+(no|off|accept-new)\b|UserKnownHostsFile[= ]+\/dev\/null|ssh-keyscan/i';

    $offending = array_values(array_filter(
        phiLinesMatching($relaxing, phiLiveFiles()),
        fn (array $hit): bool => stripos($hit['text'], 'never') === false,
    ));

    expect($offending)->toBe([]);
});

it('keeps the frozen legacy address out of code and configuration', function () {
    $authority = phiAuthority();
    $hits = [];

    foreach (phiWords($authority['DMS_FROZEN_LEGACY_ADDRESSES']) as $address) {
        $process = new Process(['git', 'grep', '-l', '-F', $address, '--',
            'app', 'config', 'routes', 'database', 'resources', 'scripts', 'deploy', 'tools', '.github', 'bootstrap'], base_path());
        $process->run();
        foreach (array_filter(explode("\n", trim($process->getOutput()))) as $file) {
            if ($file !== 'deploy/production-host.conf') {
                $hits[] = $file;
            }
        }
    }

    // The scanner-agent example configuration was the one recorded residual; it
    // now names https://daengtisia.online (DMS-DEV-INFRA-REMEDIATION-2). Exact
    // set on purpose: any NEW reference to the frozen host fails here.
    sort($hits);
    expect($hits)->toBe([]);
});

it('keeps every workflow free of remote sessions and production deploy programs', function () {
    $scanner = app(SelfHostedRunnerScanner::class);
    $workflows = glob(base_path('.github/workflows/*.yml')) ?: [];

    expect($workflows)->not->toBe([]);

    foreach ($workflows as $workflow) {
        $raw = (string) file_get_contents($workflow);
        $executable = implode("\n", array_filter(
            preg_split('/\R/', $raw) ?: [],
            fn (string $line): bool => ! str_starts_with(ltrim($line), '#'),
        ));

        expect($scanner->remoteAccessMarkersIn($raw))->toBe([], basename($workflow).' opens a remote session');

        foreach (config('ci_runner.forbidden_ci_production_commands') as $command) {
            expect(str_contains($executable, $command))->toBeFalse(basename($workflow)." invokes {$command}");
        }
    }
});

it('keeps the retired deploy workflow fail-closed', function () {
    $raw = (string) file_get_contents(base_path('.github/workflows/deploy-vps.yml'));
    $workflow = Yaml::parse($raw);

    // Symfony YAML keeps the `on:` key as the string 'on'.
    expect(array_keys($workflow['on']))->toBe(['workflow_dispatch'])
        ->and(array_keys($workflow['jobs']))->toBe(['deploy'])
        ->and($workflow['jobs']['deploy']['runs-on'])->toBe('ubuntu-latest');

    $steps = $workflow['jobs']['deploy']['steps'];
    $uses = array_filter(array_map(fn (array $s) => $s['uses'] ?? null, $steps));

    expect($uses)->toBe([])
        ->and($raw)->not->toContain('secrets.')
        ->and(trim((string) end($steps)['run']))->toEndWith('exit 1');

    $posture = app(SelfHostedRunnerScanner::class)->deployIsolationPosture();
    expect($posture['ok'])->toBeTrue(implode('; ', $posture['issues']));
});

it('flags remote-access markers on executable workflow lines but not in comments', function () {
    $scanner = app(SelfHostedRunnerScanner::class);

    $commentOnly = "# this workflow used to run ssh-keyscan and read secrets.VPS_HOST\njobs: {}\n";
    expect($scanner->remoteAccessMarkersIn($commentOnly))->toBe([]);

    $live = "jobs:\n  deploy:\n    steps:\n      - run: ssh-keyscan -H \"\${{ secrets.VPS_HOST }}\" >> known_hosts\n";
    expect($scanner->remoteAccessMarkersIn($live))->toContain('ssh-keyscan')->toContain('secrets.VPS_');

    expect($scanner->remoteAccessMarkersIn("      - run: ssh -i key root@host 'bash -s' < x\n"))
        ->toContain('ssh ')->toContain("'bash -s'");
});

it('restarts only PHP-FPM and reloads only nginx from the deploy and rollback programs', function () {
    // The queue worker, the backup timers and every other daengtisiams unit are
    // owner-controlled (the worker is intentionally OFF on Biznet). A deploy or
    // rollback that started one would change production posture as a side
    // effect of shipping code.
    foreach (['scripts/deploy-vps.sh', 'scripts/rollback-vps.sh'] as $script) {
        $commands = array_values(array_map(
            fn (array $hit): string => $hit['text'],
            array_filter(
                phiLinesMatching('/\bsystemctl\b/', [$script]),
                fn (array $hit): bool => ! str_starts_with($hit['text'], '#'),
            ),
        ));

        expect($commands)->toBe(['systemctl restart php8.3-fpm', 'systemctl reload nginx'], $script);
    }
});

it('pins the guide to the same host the authority declares', function () {
    $guide = (string) file_get_contents(base_path('docs/runbooks/production-host-identity.md'));
    $authority = phiAuthority();

    foreach (['DMS_PRODUCTION_HOSTNAME', 'DMS_PRODUCTION_ADDRESS', 'DMS_PRODUCTION_SSH_ALIAS',
        'DMS_PRODUCTION_ADMIN_USER', 'DMS_PRODUCTION_HOST_KEY_SHA256'] as $key) {
        expect($guide)->toContain($authority[$key]);
    }
    foreach (phiWords($authority['DMS_FROZEN_LEGACY_ADDRESSES']) as $address) {
        expect($guide)->toContain($address);
    }
});

it('records the first Biznet deploy and sends every later one through verify by identifier', function () {
    // DMS-BIZNET-POST-GO-DOCUMENTATION-CLOSURE-1. The first Biznet deploy ran on
    // 2026-11-04 and was proven once with verify-transition. Documents that still say
    // Biznet was never deployed send an operator back into a one-time path the runner
    // now refuses, so the stale claims must not return.
    $claude = (string) file_get_contents(base_path('CLAUDE.md'));
    preg_match('/- \*\*CG-15 .*?(?=\n- \*\*CG-16)/s', $claude, $m);
    $cg15 = preg_replace('/\s+/', ' ', $m[0] ?? '');
    $guide = (string) file_get_contents(base_path('docs/runbooks/production-host-identity.md'));
    $flat = preg_replace('/\s+/', ' ', $guide);

    expect($cg15)->toContain('20261104-151042')
        ->toContain('verify <deploy-id>')
        ->not->toContain('not yet run one');

    expect($flat)->toContain('first deploy verified 2026-11-04')
        ->toContain('EXECUTED 2026-11-04')
        ->toContain('## 6b. Every Biznet deploy after the first')
        ->toContain('deploy-vps-runner.sh verify <deploy-id>')
        ->toContain('never use `verify-transition` again')
        ->toContain('outside the `deploy-*` / `latest.*` contract')
        ->not->toContain('NOT YET PROVEN')
        ->not->toContain('NOT yet executed')
        ->not->toContain('has **never run on Biznet**');

    // The §6b steps keep the safety order: identity, the merged approval (rule
    // 229 replaced "confirm the base tip"), backups, start the approved release,
    // prove.
    $order = array_map(fn (string $needle) => strpos($flat, $needle), [
        'production-target-check.sh --remote` → `PRODUCTION_TARGET=VERIFIED` (§3)',
        '**Read the approval from the host (read-only).**',
        'Check backup protection',
        '**Start** with the approved release and commit',
        '**Prove it by its identifier:**',
    ]);
    $section6b = substr($flat, (int) strpos($flat, '## 6b.'), (int) strpos($flat, '## 6c.') - (int) strpos($flat, '## 6b.'));
    expect($section6b)->toContain('--approved-sha <APPROVED_SHA>')
        ->toContain('DEPLOYMENT=DENIED')
        ->not->toContain('tip of the base branch at launch')
        ->not->toContain('It must print the authorized commit');
    expect($order)->not->toContain(false);
    $sorted = $order;
    sort($sorted);
    expect($order)->toBe($sorted);
});

it('verifies a correctly pinned production alias without touching the network', function () {
    if (! phiToolsAvailable()) {
        $this->markTestSkipped('bash, ssh and ssh-keygen are required to exercise the workstation check.');
    }

    $world = phiWorld();
    $result = phiRunCheck($world, ['--alias', 'phi-prod']);

    expect($result['exit'])->toBe(0, $result['output'])
        ->and($result['output'])->toContain('PRODUCTION_TARGET=VERIFIED')
        ->and($result['output'])->toContain($world['fingerprint'])
        ->and($result['output'])->not->toContain('remote hostname');
});

it('defaults to the alias the authority declares', function () {
    if (! phiToolsAvailable()) {
        $this->markTestSkipped('bash, ssh and ssh-keygen are required to exercise the workstation check.');
    }

    $world = phiWorld();
    $result = phiRunCheck($world, []);

    expect($result['exit'])->toBe(0, $result['output'])
        ->and($result['output'])->toContain('alias:     phi-prod');
});

it('refuses an alias that is not provably production', function (string $alias, string $reason) {
    if (! phiToolsAvailable()) {
        $this->markTestSkipped('bash, ssh and ssh-keygen are required to exercise the workstation check.');
    }

    $world = phiWorld();
    $result = phiRunCheck($world, ['--alias', $alias]);

    expect($result['exit'])->toBe(1, $result['output'])
        ->and($result['output'])->toContain('PRODUCTION_TARGET=NOT_VERIFIED')
        ->and($result['output'])->toContain($reason);
})->with([
    'frozen legacy alias' => ['phi-legacy', 'frozen legacy alias'],
    'frozen legacy address' => ['phi-frozen-address', 'FROZEN legacy infrastructure'],
    'root login' => ['phi-root', "login account is 'root'"],
    'host-key checking off' => ['phi-loose', "StrictHostKeyChecking is 'false'"],
    'accept-new' => ['phi-accept-new', "StrictHostKeyChecking is 'accept-new'"],
    'known hosts discarded' => ['phi-devnull', 'UserKnownHostsFile is /dev/null'],
    'DNS name instead of the pinned address' => ['phi-dns', 'expected the pinned production address'],
    'learned host keys' => ['phi-update', 'UpdateHostKeys is enabled'],
    'unpinned key beside the pinned one' => ['phi-extra-key', 'holds an UNPINNED key'],
    'pinned key absent' => ['phi-unpinned', 'is not recorded for'],
]);

it('never connects when the static checks fail, even with --remote', function () {
    if (! phiToolsAvailable()) {
        $this->markTestSkipped('bash, ssh and ssh-keygen are required to exercise the workstation check.');
    }

    $world = phiWorld();
    $result = phiRunCheck($world, ['--alias', 'phi-root', '--remote']);

    expect($result['exit'])->toBe(1)
        ->and($result['output'])->toContain('remote identity check skipped')
        ->and($result['output'])->not->toContain('remote hostname is')
        ->and($result['seconds'])->toBeLessThan(10.0);
});

it('stops with a usage error when the authority omits a required value', function () {
    if (! phiToolsAvailable()) {
        $this->markTestSkipped('bash, ssh and ssh-keygen are required to exercise the workstation check.');
    }

    $world = phiWorld();
    $authority = (string) file_get_contents($world['authority']);
    file_put_contents($world['authority'], preg_replace('/^DMS_PRODUCTION_HOST_KEY_SHA256=.*$/m', '', $authority));

    $result = phiRunCheck($world, ['--alias', 'phi-prod']);

    expect($result['exit'])->toBe(2, $result['output'])
        ->and($result['output'])->toContain('refusing to guess')
        ->and($result['output'])->not->toContain('PRODUCTION_TARGET=VERIFIED');
});
