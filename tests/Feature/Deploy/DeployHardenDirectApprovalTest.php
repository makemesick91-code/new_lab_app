<?php

use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

uses()->group('Deploy', 'Security', 'ReleaseSafety');

/**
 * DMS-RELEASE-APPROVAL-DIRECT-1 — direct signed release approval (approval contract 3).
 *
 * The owner signs a canonical record on the workstation
 * (scripts/release-approval-sign.sh), the operator submits the bundle through
 * the entrypoint (`submit-approval`, scripts/release-approval-submit.sh), root
 * verifies and stores it, and `start` deploys it with no approval commit —
 * verifying it again, plus the required CI of the exact commit, which the host
 * asks GitHub for itself. Proven here:
 *
 *   1. the canonical record (parse matrix, renderer) and the bundle format;
 *   2. submission: signature, every binding, window, replay, one active
 *      approval per release, hostile bundles and paths;
 *   3. the deploy guard: contract 3 selected only by a matching active stored
 *      approval, never a fallback, contract 2 untouched without one, the
 *      activation key, store integrity (symlinks, hardlinks, modes, strays);
 *   4. the required-CI gate against a stubbed `curl` (no network): every
 *      failure mode is DENIED;
 *   5. the runner record and `verify` round trip of a real contract-3 deploy
 *      with no approval commit on the branch;
 *   6. the owner's signing helper, the root submit wrapper and the operator
 *      entrypoint / sudo expression.
 *
 * Everything runs unprivileged against the rule 229 sandbox (r229Sandbox in
 * tests/Pest.php): a disposable checkout whose origin is a GitHub URL served by
 * a fixture ssh transport, ephemeral keys, a private store. Nothing reaches a
 * server, GitHub or production.
 */
const DRA_REPO = 'fixture-owner/fixture-repo';
const DRA_WORKFLOW = '.github/workflows/foundation-evidence-gates.yml';
const DRA_JOBS = 'NSF-R011 Critical Test Gate;NSF-R012 Quality Gate;CICD-CTRL Gate Classifier';
const DRA_RUN_ID = 1001;
const DRA_REQUIRED_STEP = 'NSF-R011 Critical Test Gate:Run critical regression tests';
const DRA_FORBIDDEN_STEP = 'NSF-R011 Critical Test Gate:Note skipped critical regression tests (docs-only change)';
/** CI-CD-PR-ONLY-POLICY-1 phase B: the run events production accepts, and the step only an evaluation run takes. */
const DRA_EVENTS = 'push workflow_dispatch';
const DRA_EVALUATION_STEP = 'NSF-R011 Critical Test Gate:Start resource sampler (CI-PERF-04 evaluation)';
/** When the stubbed GitHub rate limit resets (any fixed instant). */
const DRA_RATE_RESET = 1795000000;

function draRequiresTooling(): void
{
    foreach (['git', 'flock', 'tar', 'setsid', 'sha256sum', 'ssh-keygen', 'python3', 'script'] as $binary) {
        if (trim((string) shell_exec('command -v '.escapeshellarg($binary).' 2>/dev/null')) === '') {
            test()->markTestSkipped("{$binary} is not available on this host");
        }
    }
}

/** Rewrite keys of BOTH authorities of a sandbox: the library's and the in-tree runner's. */
function draAuthority(array $s, array $values): void
{
    foreach ([$s['authority'], "{$s['app']}/deploy/production-host.conf"] as $file) {
        $text = (string) file_get_contents($file);
        foreach ($values as $key => $value) {
            $text = (string) preg_replace('/^'.$key.'=.*\n/m', '', $text);
            if ($value !== null) {
                $text .= "{$key}={$value}\n";
            }
        }
        file_put_contents($file, $text);
    }
}

/**
 * A contract-3 sandbox: origin is `git@github.com:fixture-owner/fixture-repo.git`
 * served by a fixture ssh command, the authority accepts contracts 2 and 3 and
 * declares a private approval store and the CI gate, and `curl` is a stub that
 * serves a successful push run of the workflow on the release commit.
 * `approve` adds the contract-2 approval commit (off by default: contract 3
 * needs none).
 */
function draSandbox(array $options = []): array
{
    $s = r229Sandbox(array_merge([
        'approve' => $options['approve'] ?? false,
        'authority' => ['DMS_PRODUCTION_REPOSITORY' => DRA_REPO, 'DMS_PRODUCTION_DEPLOY_SSH_ALIAS' => 'dms-fixture-deploy'],
        'approval' => ['DMS_APPROVAL_REPOSITORY' => DRA_REPO],
    ], $options['sandbox'] ?? []));

    mkdir("{$s['root']}/bin", 0o700);
    $ssh = "{$s['root']}/bin/ssh-origin";
    file_put_contents($ssh, "#!/bin/bash\nfor last; do :; done\ncase \"\$last\" in \"git-upload-pack '".DRA_REPO.".git'\") exec git upload-pack "
        .escapeshellarg($s['origin'])." ;; esac\necho \"fixture ssh: unexpected request \$*\" >&2\nexit 1\n");
    chmod($ssh, 0o700);
    r229Git($s['app'], ['remote', 'set-url', 'origin', 'git@github.com:'.DRA_REPO.'.git']);
    r229Git($s['app'], ['config', 'core.sshCommand', $ssh]);
    r229Git($s['app'], ['config', 'ssh.variant', 'simple']);

    $s['store'] = "{$s['root']}/approvals";
    $s['ci'] = "{$s['root']}/ci";
    mkdir($s['ci'], 0o700);
    mkdir("{$s['root']}/stubs", 0o700);
    file_put_contents("{$s['root']}/stubs/curl", "#!/bin/bash\nci=".escapeshellarg($s['ci'])."\nurl=\"\${@: -1}\"\n"
        ."printf '%s\\n' \"\$*\" >> \"\$ci/requests.log\"\n"
        ."case \"\$url\" in */rate_limit) kind=rate_limit ;; */jobs\\?*) kind=jobs ;; *) kind=runs ;; esac\n"
        ."if [ -f \"\$ci/\$kind.sleep\" ]; then sleep \"\$(cat \"\$ci/\$kind.sleep\")\"; fi\n"
        ."if [ -f \"\$ci/\$kind.exit\" ]; then exit \"\$(cat \"\$ci/\$kind.exit\")\"; fi\n"
        ."cat \"\$ci/\$kind.json\"\nprintf '\\n%s' \"\$(cat \"\$ci/\$kind.code\" 2>/dev/null || echo 200)\"\n");
    chmod("{$s['root']}/stubs/curl", 0o700);
    $s['path'] = "{$s['root']}/stubs:".getenv('PATH');
    draAuthority($s, [
        'DMS_PRODUCTION_RELEASE_APPROVAL_CONTRACTS' => '"2 3"',
        'DMS_PRODUCTION_RELEASE_APPROVAL_STORE' => $s['store'],
        'DMS_PRODUCTION_CI_WORKFLOW' => DRA_WORKFLOW,
        'DMS_PRODUCTION_CI_REQUIRED_JOBS' => '"'.DRA_JOBS.'"',
        'DMS_PRODUCTION_CI_REQUIRED_STEP' => '"'.DRA_REQUIRED_STEP.'"',
        'DMS_PRODUCTION_CI_FORBIDDEN_STEP' => '"'.DRA_FORBIDDEN_STEP.'"',
    ]);
    $s['release_lock'] = "{$s['root']}/release.lock";
    draCi($s, [draRun($s)]);
    draRateLimit($s, 60);

    return $s;
}

function draRun(array $s, array $overrides = []): array
{
    return array_replace_recursive([
        'id' => DRA_RUN_ID, 'name' => 'Foundation Evidence Gates', 'path' => DRA_WORKFLOW, 'event' => 'push',
        'head_sha' => $s['sha'], 'head_branch' => $s['branch'], 'status' => 'completed', 'conclusion' => 'success',
        'repository' => ['full_name' => DRA_REPO], 'head_repository' => ['full_name' => DRA_REPO],
    ], $overrides);
}

function draJob(array $s, string $name, ?string $conclusion = 'success', array $overrides = []): array
{
    // A critical gate that ran its tests: the test step succeeded, the
    // docs-only note step was skipped (the workflow's real step names).
    $steps = $conclusion === 'success' && $name === 'NSF-R011 Critical Test Gate'
        ? draSteps(['Run critical regression tests' => 'success', 'Note skipped critical regression tests (docs-only change)' => 'skipped'])
        : ($conclusion === 'skipped' ? [] : draSteps(['Run' => $conclusion]));

    return array_merge([
        'id' => crc32($name.$conclusion), 'run_id' => DRA_RUN_ID, 'name' => $name, 'head_sha' => $s['sha'],
        'status' => $conclusion === null ? 'in_progress' : 'completed', 'conclusion' => $conclusion,
        'steps' => $steps,
    ], $overrides);
}

/** @param array<string, ?string> $conclusions step name => conclusion */
function draSteps(array $conclusions): array
{
    $steps = [['name' => 'Set up job', 'status' => 'completed', 'conclusion' => 'success', 'number' => 1]];
    foreach ($conclusions as $name => $conclusion) {
        $steps[] = ['name' => $name, 'status' => $conclusion === null ? 'in_progress' : 'completed', 'conclusion' => $conclusion, 'number' => count($steps) + 1];
    }

    return $steps;
}

/** The green jobs, with the critical gate's steps replaced (null: no `steps` key at all). */
function draCriticalSteps(array $s, ?array $steps): array
{
    return array_map(function (array $job) use ($steps) {
        if ($job['name'] !== 'NSF-R011 Critical Test Gate' || $job['conclusion'] !== 'success') {
            return $job;
        }
        if ($steps === null) {
            unset($job['steps']);

            return $job;
        }

        return array_merge($job, ['steps' => $steps]);
    }, draJobs($s));
}

/** The jobs of a real green push run: the hosted critical gate ran, the self-hosted twin was skipped. */
function draJobs(array $s): array
{
    return [
        draJob($s, 'CICD-CTRL Gate Classifier'),
        draJob($s, 'NSF-R012 Quality Gate'),
        draJob($s, 'NSF-R011 Critical Test Gate'),
        draJob($s, 'NSF-R011 Critical Test Gate', 'skipped'),
        // Skipped on push runs that touch no module code: never required.
        draJob($s, 'CICD-CTRL Selective Module Gate', 'skipped'),
    ];
}

/**
 * What the stubbed GitHub API serves: run and job lists (raw strings are served
 * as-is), HTTP codes, or a curl exit code.
 */
function draCi(array $s, array|string $runs, array|string|null $jobs = null, array $codes = [], array $exits = []): void
{
    $jobs ??= draJobs($s);
    file_put_contents("{$s['ci']}/runs.json", is_string($runs) ? $runs : json_encode(['total_count' => count($runs), 'workflow_runs' => $runs]));
    file_put_contents("{$s['ci']}/jobs.json", is_string($jobs) ? $jobs : json_encode(['total_count' => count($jobs), 'jobs' => $jobs]));
    foreach (['runs', 'jobs'] as $kind) {
        @unlink("{$s['ci']}/{$kind}.code");
        @unlink("{$s['ci']}/{$kind}.exit");
        if (isset($codes[$kind])) {
            file_put_contents("{$s['ci']}/{$kind}.code", (string) $codes[$kind]);
        }
        if (isset($exits[$kind])) {
            file_put_contents("{$s['ci']}/{$kind}.exit", (string) $exits[$kind]);
        }
    }
}

/**
 * What the stubbed `GET /rate_limit` serves: the core budget left (int), or a
 * raw body (string); optionally an HTTP code or a curl exit code instead.
 */
function draRateLimit(array $s, int|string $remaining, ?int $code = null, ?int $exit = null): void
{
    $core = ['limit' => 60, 'remaining' => $remaining, 'reset' => DRA_RATE_RESET, 'used' => is_int($remaining) ? 60 - $remaining : 0, 'resource' => 'core'];
    file_put_contents("{$s['ci']}/rate_limit.json", is_string($remaining) ? $remaining : json_encode(['resources' => ['core' => $core, 'search' => $core], 'rate' => $core]));
    @unlink("{$s['ci']}/rate_limit.code");
    @unlink("{$s['ci']}/rate_limit.exit");
    if ($code !== null) {
        file_put_contents("{$s['ci']}/rate_limit.code", (string) $code);
    }
    if ($exit !== null) {
        file_put_contents("{$s['ci']}/rate_limit.exit", (string) $exit);
    }
}

function draRequests(array $s): string
{
    return (string) @file_get_contents("{$s['ci']}/requests.log");
}

/** The runner's read-only `check-approval`, its lock root the sandbox's (the one the fixture bootstrap locks). */
function draCheck(array $s, string $release = ''): array
{
    return r229Runner($s, ['check-approval', '--release-id', $release !== '' ? $release : $s['release'], '--approved-sha', $s['sha']],
        ['PATH' => $s['path'], 'DMS_DEPLOY_LOCK_DIR' => $s['lock']]);
}

/** Hold an flock on $file from another process (until stopped); returns once it is held. */
function draHoldLock(string $file, string $mode = '-x'): Process
{
    touch($file);
    // -o: the lock is held by flock itself, so stopping it frees the lock.
    $holder = new Process(['flock', $mode, '-o', $file, 'sleep', '15']);
    $holder->start();
    $deadline = microtime(true) + 10;
    while (microtime(true) < $deadline && r229Run(['flock', '-n', '-x', $file, 'true'])['exit'] === 0) {
        usleep(50_000);
    }

    return $holder;
}

/** Every byte, mode and link of a directory tree (absent: one marker). */
function draTree(string $dir): array
{
    if (! is_dir($dir)) {
        return ['<absent>'];
    }
    $tree = [];
    $entries = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS | FilesystemIterator::CURRENT_AS_PATHNAME),
        RecursiveIteratorIterator::SELF_FIRST,
    );
    foreach ($entries as $path) {
        $stat = (array) lstat($path);
        $tree[substr($path, strlen($dir))] = match (true) {
            is_link($path) => 'link '.readlink($path),
            is_dir($path) => sprintf('dir %o', $stat['mode']),
            default => sprintf('file %o links=%d %s', $stat['mode'], $stat['nlink'], hash_file('sha256', $path)),
        };
    }
    ksort($tree);

    return $tree;
}

/** What a read-only check must leave byte-identical: refs (loose and packed), FETCH_HEAD, the evidence (spent records included), the store. */
function draHostState(array $s): array
{
    $git = "{$s['app']}/.git";

    return [
        'refs' => r229Git($s['app'], ['for-each-ref', '--format=%(refname) %(objecttype) %(objectname)']),
        'loose refs' => draTree("{$git}/refs"),
        'packed-refs' => is_file("{$git}/packed-refs") ? (string) file_get_contents("{$git}/packed-refs") : '<absent>',
        'FETCH_HEAD' => is_file("{$git}/FETCH_HEAD") ? (string) file_get_contents("{$git}/FETCH_HEAD") : '<absent>',
        'evidence' => draTree($s['evidence']),
        'spent' => draTree("{$s['evidence']}/spent"),
        'store' => draTree($s['store']),
    ];
}

/** The canonical contract-3 fields of a sandbox release. */
function draFields(array $s, array $overrides = []): array
{
    return array_merge([
        'DMS_APPROVAL_CONTRACT' => '3',
        'DMS_APPROVAL_ID' => bin2hex(random_bytes(16)),
        'DMS_APPROVAL_RELEASE_ID' => $s['release'],
        'DMS_APPROVAL_SCOPE' => 'production-deploy',
        'DMS_APPROVAL_REPOSITORY' => DRA_REPO,
        'DMS_APPROVAL_HOST' => $s['host'],
        'DMS_APPROVAL_HOST_KEY_SHA256' => $s['host_key'],
        'DMS_APPROVAL_BASE_BRANCH' => $s['branch'],
        'DMS_APPROVAL_TARGET_SHA' => $s['sha'],
        'DMS_APPROVAL_TARGET_TREE' => $s['tree'],
        'DMS_APPROVAL_GO_TAG' => $s['go_tag'],
        'DMS_APPROVAL_APPROVED_BY' => 'owner',
        'DMS_APPROVAL_APPROVED_AT' => gmdate('Y-m-d\TH:i:s\Z', time() - 600),
        'DMS_APPROVAL_NOT_AFTER' => gmdate('Y-m-d\TH:i:s\Z', time() + 3600),
    ], $overrides);
}

function draRecord(array $fields): string
{
    $text = '';
    foreach ($fields as $key => $value) {
        $text .= "{$key}={$value}\n";
    }

    return $text;
}

/** @return array{bundle: string, record: string, id: string, fields: array<string, string>} */
function draBundle(array $s, array $overrides = [], string $signer = 'owner', string $namespace = 'dms-release-approval'): array
{
    $fields = draFields($s, $overrides);
    $record = draRecord($fields);
    $key = $signer === 'intruder' ? $s['intruder_key'] : $s['owner_key'];

    return ['bundle' => $record.r229Sign($key, $record, $namespace), 'record' => $record, 'id' => $fields['DMS_APPROVAL_ID'], 'fields' => $fields];
}

/** @return array{exit: int, output: string} */
function draBash(array $s, string $script, array $args, ?string $input = null, array $env = []): array
{
    $process = new Process(array_merge(['bash', '-c', $script, '_'], $args), null, array_merge(['PATH' => $s['path']], $env), $input, 90);
    $process->run();

    return ['exit' => (int) $process->getExitCode(), 'output' => $process->getOutput().$process->getErrorOutput()];
}

/** dms_release_approval_submit (root's half of submit-approval) with the bundle on stdin. */
function draSubmit(array $s, string $bundle, array $overrides = []): array
{
    $a = array_merge([
        'authority' => $s['authority'], 'app' => $s['app'], 'uid' => r229Uid(), 'release' => $s['release'],
        'host' => $s['host'], 'host_key' => $s['host_key'], 'now' => (string) time(), 'by' => 'deploy-operator',
        'lock' => $s['release_lock'],
    ], $overrides);

    return draBash($s, ($a['shell'] ?? '').'set -euo pipefail; source "$1"; shift; rc=0; dms_release_approval_submit "$@" || rc=$?; exit "$rc"',
        [base_path('scripts/lib/release-approval.sh'), $a['authority'], $a['app'], $a['uid'], $a['release'], $a['host'], $a['host_key'], $a['now'], $a['by'], $a['lock']],
        $bundle);
}

/** dms_release_approval_verify (the deploy guard) with the stubbed curl on PATH. */
function draVerify(array $s, array $overrides = []): array
{
    $a = array_merge([
        'app' => $s['app'], 'branch' => $s['branch'], 'release' => $s['release'], 'sha' => $s['sha'],
        'authority' => $s['authority'], 'host' => $s['host'], 'now' => (string) time(), 'host_key' => $s['host_key'], 'uid' => r229Uid(),
    ], $overrides);

    return draBash($s, ($a['shell'] ?? '').'set -euo pipefail; source "$1"; shift; rc=0; dms_release_approval_verify "$@" || rc=$?; '
        .'echo "rc=${rc} approved=${DMS_RELEASE_APPROVED_SHA:-} contract=${DMS_RELEASE_APPROVAL_VERIFIED_CONTRACT:-}"; '
        .'echo "id=${DMS_RELEASE_APPROVAL_ID:-} record_sha256=${DMS_RELEASE_APPROVAL_RECORD_SHA256:-} signature_sha256=${DMS_RELEASE_APPROVAL_SIGNATURE_SHA256:-}"; '
        .'echo "store=${DMS_RELEASE_APPROVAL_STORE_PATH:-} signer=${DMS_RELEASE_APPROVAL_SIGNER_KEY:-} ci_run=${DMS_RELEASE_APPROVAL_VERIFIED_CI_RUN_ID:-}"; exit "$rc"',
        [base_path('scripts/lib/release-approval.sh'), $a['app'], $a['branch'], $a['release'], $a['sha'], $a['authority'], $a['host'], $a['now'], $a['host_key'], $a['uid']]);
}

function draCiVerify(array $s, array $overrides = []): array
{
    // `events` / `evaluation_step` empty: an authority that predates
    // CI-CD-PR-ONLY-POLICY-1 phase B (push runs only, no evaluation step).
    $a = array_merge(['repo' => DRA_REPO, 'sha' => $s['sha'], 'branch' => $s['branch'], 'workflow' => DRA_WORKFLOW, 'jobs' => DRA_JOBS,
        'required_step' => DRA_REQUIRED_STEP, 'forbidden_step' => DRA_FORBIDDEN_STEP, 'events' => '', 'evaluation_step' => '', 'reserve' => null], $overrides);
    // The reserve is set the way the runner's check-approval sets it: after sourcing.
    $reserve = $a['reserve'] === null ? '' : 'DMS_RELEASE_APPROVAL_CI_RESERVE='.escapeshellarg((string) $a['reserve']).'; ';

    return draBash($s, 'set -euo pipefail; source "$1"; shift; '.$reserve.'rc=0; dms_release_approval_ci_verify "$@" || rc=$?; echo "ci_run=${DMS_RELEASE_APPROVAL_CI_RUN_ID:-}"; exit "$rc"',
        [base_path('scripts/lib/release-approval.sh'), $a['repo'], $a['sha'], $a['branch'], $a['workflow'], $a['jobs'], $a['required_step'], $a['forbidden_step'],
            $a['events'], $a['evaluation_step']]);
}

/** The required-CI gate as production declares it since phase B: push or workflow_dispatch runs, evaluation step set. */
function draDispatchCiVerify(array $s, array $overrides = []): array
{
    return draCiVerify($s, array_merge(['events' => DRA_EVENTS, 'evaluation_step' => DRA_EVALUATION_STEP], $overrides));
}

/** A green manual dispatch run of the workflow on the base branch at the release commit. */
function draDispatchRun(array $s, array $overrides = []): array
{
    return draRun($s, array_merge(['event' => 'workflow_dispatch'], $overrides));
}

/** The jobs of a green sequential dispatch run: the evaluation sampler and the docs-only note were skipped. */
function draDispatchJobs(array $s, ?string $sampler = 'skipped', string $tests = 'success'): array
{
    return draCriticalSteps($s, draSteps(array_merge(
        $sampler === 'absent' ? [] : ['Start resource sampler (CI-PERF-04 evaluation)' => $sampler],
        ['Run critical regression tests' => $tests, 'Note skipped critical regression tests (docs-only change)' => 'skipped'],
    )));
}

/** @return array{0: int, 1: string} a shell snippet run from the repository root */
function draShell(string $script): array
{
    $process = new Process(['bash', '-c', $script], base_path(), null, null, 60);
    $process->run();

    return [(int) $process->getExitCode(), $process->getOutput().$process->getErrorOutput()];
}

/** A script without its comment lines. */
function draCode(string $relative): string
{
    return (string) preg_replace('/^\s*#.*$/m', '', (string) file_get_contents(base_path($relative)));
}

/** dms_release_approval_withdraw (the administrator's revocation). */
function draWithdraw(array $s, string $id, array $overrides = []): array
{
    $a = array_merge(['authority' => $s['authority'], 'app' => $s['app'], 'uid' => r229Uid(), 'release' => $s['release'],
        'now' => (string) time(), 'by' => 'admin-owner'], $overrides);

    return draBash($s, 'set -euo pipefail; source "$1"; shift; rc=0; dms_release_approval_withdraw "$@" || rc=$?; exit "$rc"',
        [base_path('scripts/lib/release-approval.sh'), $a['authority'], $a['app'], $a['uid'], $a['release'], $id, $a['now'], $a['by']]);
}

function draStoreDir(array $s, string $release = ''): string
{
    return "{$s['store']}/".($release !== '' ? $release : $s['release']);
}

function draSubmitted(array $result, string $id): void
{
    expect($result['exit'])->toBe(0, $result['output'])
        ->and($result['output'])->toContain("RELEASE APPROVAL SUBMITTED: {$id}");
}

function draSubmitDenied(array $s, array $result, string $reason): void
{
    expect($result['exit'])->toBe(77, $result['output'])
        ->and($result['output'])->toContain('RELEASE APPROVAL SUBMISSION: DENIED')
        ->and($result['output'])->not->toContain('RELEASE APPROVAL SUBMITTED')
        ->and($result['output'])->toContain($reason)
        ->and(glob(draStoreDir($s).'/*.approval') ?: [])->toBe([]);
}

function draDenied(array $result, string $reason): void
{
    expect($result['exit'])->toBe(77, $result['output'])
        ->and($result['output'])->toContain('DEPLOYMENT=DENIED')
        ->and($result['output'])->not->toContain('RELEASE APPROVAL: GO')
        ->and($result['output'])->toContain($reason);
}

function draGo(array $result, string $contract): void
{
    expect($result['exit'])->toBe(0, $result['output'])
        ->and($result['output'])->toContain('RELEASE APPROVAL: GO')
        ->and($result['output'])->toContain("contract={$contract}");
}

/** Make a stored (0400) file writable, rewrite it through $edit, and restore its mode. */
function draRewrite(string $file, callable $edit): void
{
    chmod($file, 0o600);
    file_put_contents($file, $edit((string) file_get_contents($file)));
    chmod($file, 0o400);
}

function draParse(array $s, string $content): array
{
    $file = "{$s['root']}/parse-".bin2hex(random_bytes(4));
    file_put_contents($file, $content);

    return draBash($s, 'source "$1"; if dms_release_approval_parse_direct "$2"; then echo "CANONICAL id=${DMS_RELEASE_APPROVAL_DIRECT_RECORD[DMS_APPROVAL_ID]}"; else echo "REFUSED: ${DMS_RELEASE_APPROVAL_DIRECT_WHY}"; exit 1; fi',
        [base_path('scripts/lib/release-approval.sh'), $file]);
}

// ─── 1. The canonical record and the bundle ────────────────────────────────

it('accepts exactly the canonical contract-3 record', function () {
    draRequiresTooling();
    $s = draSandbox();
    $fields = draFields($s);

    $r = draParse($s, draRecord($fields));

    expect($r['exit'])->toBe(0, $r['output'])->and($r['output'])->toContain("CANONICAL id={$fields['DMS_APPROVAL_ID']}");
});

it('refuses every record that is not byte-for-byte canonical', function (callable $mutate, string $reason) {
    draRequiresTooling();
    $s = draSandbox();
    $lines = explode("\n", rtrim(draRecord(draFields($s)), "\n"));

    $r = draParse($s, $mutate($lines));

    expect($r['exit'])->toBe(1, $r['output'])->and($r['output'])->toContain('REFUSED: ')->and($r['output'])->toContain($reason);
})->with([
    'CRLF line ends' => [fn (array $l) => implode("\r\n", $l)."\r\n", 'neither printable ASCII nor LF'],
    'no final line feed' => [fn (array $l) => implode("\n", $l), 'does not end with a line feed'],
    'a comment line' => [fn (array $l) => "# owner approval\n".implode("\n", $l)."\n", 'lines; a canonical contract-3 record has exactly 14'],
    'a blank line' => [fn (array $l) => implode("\n", $l)."\n\n", 'lines; a canonical contract-3 record has exactly 14'],
    'keys out of order' => [fn (array $l) => implode("\n", [$l[0], $l[2], $l[1], ...array_slice($l, 3)])."\n", 'the canonical record has DMS_APPROVAL_ID= there'],
    'a key twice' => [fn (array $l) => implode("\n", [...array_slice($l, 0, 13), $l[12]])."\n", 'the canonical record has DMS_APPROVAL_NOT_AFTER= there'],
    'an unknown key' => [fn (array $l) => implode("\n", [...array_slice($l, 0, 3), 'DMS_APPROVAL_EVIDENCE=docs/x.md', ...array_slice($l, 4)])."\n", 'the canonical record has DMS_APPROVAL_SCOPE= there'],
    'a key missing' => [fn (array $l) => implode("\n", array_slice($l, 0, 13))."\n", 'has 13 lines'],
    'an extra key' => [fn (array $l) => implode("\n", [...$l, 'DMS_APPROVAL_EVIDENCE=docs/x.md'])."\n", 'has 15 lines'],
    'contract 2' => [fn (array $l) => implode("\n", ['DMS_APPROVAL_CONTRACT=2', ...array_slice($l, 1)])."\n", "DMS_APPROVAL_CONTRACT '2' is not a canonical value"],
    'an uppercase approval id' => [fn (array $l) => implode("\n", [$l[0], strtoupper($l[1]), ...array_slice($l, 2)])."\n", "DMS_APPROVAL_ID '"],
    'a short approval id' => [fn (array $l) => implode("\n", [$l[0], substr($l[1], 0, -1), ...array_slice($l, 2)])."\n", 'DMS_APPROVAL_ID'],
    'a space in a value' => [fn (array $l) => str_replace('APPROVED_BY=owner', 'APPROVED_BY=own er', implode("\n", $l))."\n", "DMS_APPROVAL_APPROVED_BY 'own er' is not a canonical value"],
    'a trailing space' => [fn (array $l) => str_replace('APPROVED_BY=owner', 'APPROVED_BY=owner ', implode("\n", $l))."\n", 'is not a canonical value'],
    'a tab' => [fn (array $l) => str_replace('APPROVED_BY=owner', "APPROVED_BY=owner\t", implode("\n", $l))."\n", 'neither printable ASCII nor LF'],
    'a NUL byte' => [fn (array $l) => str_replace('APPROVED_BY=owner', "APPROVED_BY=own\0er", implode("\n", $l))."\n", 'neither printable ASCII nor LF'],
    'a non-ASCII byte' => [fn (array $l) => str_replace('APPROVED_BY=owner', "APPROVED_BY=own\u{00e9}r", implode("\n", $l))."\n", 'neither printable ASCII nor LF'],
    'a quoted value' => [fn (array $l) => str_replace('APPROVED_BY=owner', 'APPROVED_BY="owner"', implode("\n", $l))."\n", 'is not a canonical value'],
    'shell syntax' => [fn (array $l) => (string) preg_replace('/RELEASE_ID=.*/', 'RELEASE_ID=$(id)', implode("\n", $l))."\n", 'DMS_APPROVAL_RELEASE_ID'],
    'an impossible date' => [fn (array $l) => (string) preg_replace('/APPROVED_AT=.*/', 'APPROVED_AT=2026-02-30T10:00:00Z', implode("\n", $l))."\n", 'DMS_APPROVAL_APPROVED_AT'],
    'a local time' => [fn (array $l) => (string) preg_replace('/NOT_AFTER=.*/', 'NOT_AFTER=2026-11-17T10:00:00+08:00', implode("\n", $l))."\n", 'DMS_APPROVAL_NOT_AFTER'],
    'an abbreviated target' => [fn (array $l) => (string) preg_replace('/TARGET_SHA=(.{12}).*/', 'TARGET_SHA=$1', implode("\n", $l))."\n", 'DMS_APPROVAL_TARGET_SHA'],
    'oversized' => [fn (array $l) => (string) preg_replace('/GO_TAG=.*/', 'GO_TAG='.str_repeat('a', 4100).'-go', implode("\n", $l))."\n", 'a record is 1 to 4096 bytes'],
    'empty' => [fn (array $l) => '', 'a record is 1 to 4096 bytes'],
]);

it('renders exactly the canonical record the guard parses', function () {
    draRequiresTooling();
    $s = draSandbox();
    $f = draFields($s);

    $r = draBash($s, 'source "$1"; shift; dms_release_approval_render_direct "$@"', [base_path('scripts/lib/release-approval.sh'),
        $f['DMS_APPROVAL_ID'], $f['DMS_APPROVAL_RELEASE_ID'], $f['DMS_APPROVAL_REPOSITORY'], $f['DMS_APPROVAL_HOST'], $f['DMS_APPROVAL_HOST_KEY_SHA256'],
        $f['DMS_APPROVAL_BASE_BRANCH'], $f['DMS_APPROVAL_TARGET_SHA'], $f['DMS_APPROVAL_TARGET_TREE'], $f['DMS_APPROVAL_GO_TAG'],
        $f['DMS_APPROVAL_APPROVED_BY'], $f['DMS_APPROVAL_APPROVED_AT'], $f['DMS_APPROVAL_NOT_AFTER']]);

    expect($r['exit'])->toBe(0, $r['output'])->and($r['output'])->toBe(draRecord($f))
        ->and(draParse($s, $r['output'])['exit'])->toBe(0);
});

// ─── 2. Submission ──────────────────────────────────────────────────────────

it('stores a verified approval as root-only files with a receipt, and deploys nothing', function () {
    draRequiresTooling();
    $s = draSandbox();
    $b = draBundle($s);

    $r = draSubmit($s, $b['bundle']);

    draSubmitted($r, $b['id']);
    $dir = draStoreDir($s);
    $owner = r229Fingerprint($s['owner_key'].'.pub');
    $receipt = (string) file_get_contents("{$dir}/{$b['id']}.receipt");
    expect(substr(sprintf('%o', fileperms($s['store'])), -3))->toBe('700')
        ->and(substr(sprintf('%o', fileperms($dir)), -3))->toBe('700')
        ->and(scandir($dir))->toBe(['.', '..', "{$b['id']}.approval", "{$b['id']}.receipt", "{$b['id']}.sig"])
        ->and(file_get_contents("{$dir}/{$b['id']}.approval"))->toBe($b['record'])
        ->and($b['record'].file_get_contents("{$dir}/{$b['id']}.sig"))->toBe($b['bundle'])
        ->and($r['output'])->toContain('record_sha256='.hash('sha256', $b['record']))
        ->and($r['output'])->toContain("signer_key={$owner}")
        ->and($r['output'])->toContain('Nothing was deployed')
        ->and($receipt)->toContain("host={$s['host']}\n")
        ->and($receipt)->toContain("signer_key={$owner}\n")
        ->and($receipt)->toContain('record_sha256='.hash('sha256', $b['record'])."\n")
        ->and($receipt)->toContain('signature_sha256='.hash('sha256', substr($b['bundle'], strlen($b['record'])))."\n")
        ->and($receipt)->toContain("submitted_by=deploy-operator\n")
        ->and($receipt)->toMatch('/^verified_at=\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/m')
        ->and(r229Git($s['app'], ['rev-parse', 'HEAD']))->toBe($s['base'])
        ->and(is_dir($s['evidence']))->toBeFalse();
    foreach (['approval', 'sig', 'receipt'] as $ext) {
        expect(substr(sprintf('%o', fileperms("{$dir}/{$b['id']}.{$ext}")), -3))->toBe('400');
    }
});

it('refuses a bundle whose signature the host cannot authenticate', function (string $case, string $reason) {
    draRequiresTooling();
    $s = draSandbox();
    $b = match ($case) {
        'intruder' => draBundle($s, [], 'intruder'),
        'namespace' => draBundle($s, [], 'owner', 'git'),
        default => draBundle($s),
    };
    $bundle = match ($case) {
        // A field changed after the owner signed (the window widened).
        'record' => str_replace('DMS_APPROVAL_NOT_AFTER='.$b['fields']['DMS_APPROVAL_NOT_AFTER'], 'DMS_APPROVAL_NOT_AFTER='.gmdate('Y-m-d\TH:i:s\Z', time() + 7200), $b['bundle']),
        // One character of the signature changed.
        'signature' => (function () use ($b) {
            $sig = substr($b['bundle'], strlen($b['record']));
            $lines = explode("\n", $sig);
            $lines[2] = substr($lines[2], 0, 20).($lines[2][20] === 'A' ? 'B' : 'A').substr($lines[2], 21);

            return $b['record'].implode("\n", $lines);
        })(),
        default => $b['bundle'],
    };

    draSubmitDenied($s, draSubmit($s, $bundle), $reason);
})->with([
    'the record was modified after signing' => ['record', 'does not verify for \'owner\''],
    'the signature was modified' => ['signature', 'does not verify'],
    'signed by a key the anchor does not trust' => ['intruder', 'does not verify for \'owner\''],
    'a signature made for another purpose (namespace)' => ['namespace', 'does not verify'],
]);

it('refuses a bundle that approves anything but this release, host, repository and branch', function (array $fields, array $call, string $reason) {
    draRequiresTooling();
    $s = draSandbox(['sandbox' => ['approvers' => 'owner auditor']]);
    $overrides = $fields;
    if (($overrides['DMS_APPROVAL_HOST_KEY_SHA256'] ?? null) === 'other') {
        $overrides['DMS_APPROVAL_HOST_KEY_SHA256'] = 'SHA256:'.str_repeat('Q', 43);
    }

    draSubmitDenied($s, draSubmit($s, draBundle($s, $overrides)['bundle'], $call), $reason);
})->with([
    'another release id than the one submitted' => [['DMS_APPROVAL_RELEASE_ID' => 'dms-other-release'], [], "the record names release 'dms-other-release'"],
    'another host' => [['DMS_APPROVAL_HOST' => 'other-host'], [], "the record approves host 'other-host'"],
    'another host key' => [['DMS_APPROVAL_HOST_KEY_SHA256' => 'other'], [], 'the record approves host key'],
    'a host presenting another key' => [[], ['host_key' => 'SHA256:'.str_repeat('Z', 43)], 'this host presents host key'],
    'another host by name' => [[], ['host' => 'not-production'], "this host is 'not-production'"],
    'the frozen legacy host' => [[], ['host' => 'srv1730088'], 'is FROZEN legacy infrastructure'],
    'another repository' => [['DMS_APPROVAL_REPOSITORY' => 'someone/else'], [], "the record approves repository 'someone/else'"],
    'another branch' => [['DMS_APPROVAL_BASE_BRANCH' => 'main'], [], "the record approves branch 'main'"],
    'another scope' => [['DMS_APPROVAL_SCOPE' => 'production-rollback'], [], "scope is 'production-rollback'"],
    'an undeclared approver' => [['DMS_APPROVAL_APPROVED_BY' => 'intruder'], [], "approver 'intruder' is not a declared release approver"],
    'a declared approver the anchor does not map to the key' => [['DMS_APPROVAL_APPROVED_BY' => 'auditor'], [], "does not verify for 'auditor'"],
    'a path-traversal release id' => [[], ['release' => '../../etc'], "release id '../../etc' is not a release identifier"],
]);

it('refuses a window that is expired, future-dated or longer than 72 hours', function (int $approvedAt, int $notAfter, string $reason) {
    draRequiresTooling();
    $s = draSandbox();
    $b = draBundle($s, [
        'DMS_APPROVAL_APPROVED_AT' => gmdate('Y-m-d\TH:i:s\Z', time() + $approvedAt),
        'DMS_APPROVAL_NOT_AFTER' => gmdate('Y-m-d\TH:i:s\Z', time() + $notAfter),
    ]);

    draSubmitDenied($s, draSubmit($s, $b['bundle']), $reason);
})->with([
    'expired' => [-7200, -60, 'the approval expired at'],
    'future-dated' => [3600, 7200, 'the approval is dated in the future'],
    'longer than 72 hours' => [-600, 259200, 'the approval window is longer than 259200 seconds'],
    'ending before it starts' => [-600, -1200, 'ends'],
]);

it('refuses hostile or malformed bundles before reading any field', function (callable $make, string $reason) {
    draRequiresTooling();
    $s = draSandbox();
    $b = draBundle($s);

    draSubmitDenied($s, draSubmit($s, $make($b)), $reason);
})->with([
    'empty' => [fn (array $b) => '', 'the bundle is empty'],
    'oversized (8193 bytes)' => [fn (array $b) => str_repeat('#', 8193 - strlen($b['bundle'])).$b['bundle'], 'larger than 8192 bytes'],
    'binary' => [fn (array $b) => "\x7fELF\0\0".$b['bundle'], 'neither printable ASCII nor LF'],
    'garbage text' => [fn (array $b) => "hello, please deploy\n", 'exactly one armored SSH signature'],
    'a record without signature' => [fn (array $b) => $b['record'], 'exactly one armored SSH signature'],
    'a signature without record' => [fn (array $b) => substr($b['bundle'], strlen($b['record'])), 'carries no record before its signature'],
    'two signatures' => [fn (array $b) => $b['bundle'].substr($b['bundle'], strlen($b['record'])), 'exactly one armored SSH signature'],
    'trailing data after the signature' => [fn (array $b) => $b['bundle']."DMS_APPROVAL_TARGET_SHA=0000\n", 'something after its signature'],
    'CRLF line ends' => [fn (array $b) => str_replace("\n", "\r\n", $b['bundle']), 'neither printable ASCII nor LF'],
    'a comment before the record' => [fn (array $b) => "# approved\n".$b['bundle'], 'lines; a canonical contract-3 record has exactly 14'],
]);

it('stores one active approval per release and refuses a replacement while it is active', function () {
    draRequiresTooling();
    $s = draSandbox();
    $first = draBundle($s);
    draSubmitted(draSubmit($s, $first['bundle']), $first['id']);

    // The same bundle again, and a second, genuinely signed approval of the release.
    $again = draSubmit($s, $first['bundle']);
    expect($again['exit'])->toBe(77, $again['output'])->and($again['output'])->toContain("approval {$first['id']} is already stored");
    $second = draBundle($s, ['DMS_APPROVAL_TARGET_SHA' => $s['base'], 'DMS_APPROVAL_TARGET_TREE' => r229Git($s['app'], ['rev-parse', "{$s['base']}^{tree}"])]);
    $r = draSubmit($s, $second['bundle']);
    expect($r['exit'])->toBe(77, $r['output'])
        ->and($r['output'])->toContain("approval {$first['id']} of {$s['release']} (for {$s['sha']}) is still active until")
        ->and(glob(draStoreDir($s).'/*.approval') ?: [])->toBe([draStoreDir($s)."/{$first['id']}.approval"]);

    // Once the first one has expired, a new approval of the release is stored,
    // and the expired one is inert: the deploy selects the active one.
    $later = time() + 4000;
    $third = draBundle($s, [
        'DMS_APPROVAL_APPROVED_AT' => gmdate('Y-m-d\TH:i:s\Z', $later - 60),
        'DMS_APPROVAL_NOT_AFTER' => gmdate('Y-m-d\TH:i:s\Z', $later + 3600),
    ]);
    draSubmitted(draSubmit($s, $third['bundle'], ['now' => (string) $later]), $third['id']);
    $v = draVerify($s, ['now' => (string) $later]);
    draGo($v, '3');
    expect($v['output'])->toContain("id={$third['id']}");
});

it('refuses a submission for a spent release or a spent approval id', function () {
    draRequiresTooling();
    $s = draSandbox();
    $b = draBundle($s);
    mkdir("{$s['evidence']}/spent", 0o700, true);
    chmod($s['evidence'], 0o700);

    file_put_contents("{$s['evidence']}/spent/approval-{$b['id']}.spent", "approval_id={$b['id']}\n");
    draSubmitDenied($s, draSubmit($s, $b['bundle']), "approval {$b['id']} was already used");

    file_put_contents("{$s['evidence']}/spent/{$s['release']}.spent", "release_id={$s['release']}\nrun_id=earlier\n");
    draSubmitDenied($s, draSubmit($s, draBundle($s)['bundle']), "release {$s['release']} was already deployed on this host");
});

it('refuses a submission when the host does not accept contract 3 or the store is not trusted', function (string $case, string $reason) {
    draRequiresTooling();
    $s = draSandbox();
    match ($case) {
        'contract 3 not enabled' => draAuthority($s, ['DMS_PRODUCTION_RELEASE_APPROVAL_CONTRACTS' => '"2"']),
        'an authority without the key' => draAuthority($s, ['DMS_PRODUCTION_RELEASE_APPROVAL_CONTRACTS' => null]),
        'a symlinked store' => symlink(tempArtifactDir('dra-elsewhere-'), $s['store']),
        'a group-writable store' => mkdir($s['store'], 0o770) && chmod($s['store'], 0o770),
        'a store inside the checkout' => draAuthority($s, ['DMS_PRODUCTION_RELEASE_APPROVAL_STORE' => "{$s['app']}/storage/approvals"]),
    };

    draSubmitDenied($s, draSubmit($s, draBundle($s)['bundle']), $reason);
})->with([
    'contract 3 not enabled' => ['contract 3 not enabled', 'contract 3 is not enabled'],
    'an authority without the key' => ['an authority without the key', "accepts approval contracts '2' only"],
    'a symlinked store' => ['a symlinked store', 'is not a canonical absolute directory'],
    'a group-writable store' => ['a group-writable store', 'mode 770'],
    'a store inside the checkout' => ['a store inside the checkout', 'lies inside the application checkout'],
]);

// ─── 3. The deploy guard ────────────────────────────────────────────────────

it('approves a stored approval as contract 3 and binds its id and digests', function () {
    draRequiresTooling();
    $s = draSandbox();
    $b = draBundle($s);
    draSubmitted(draSubmit($s, $b['bundle']), $b['id']);

    $r = draVerify($s);

    draGo($r, '3');
    expect($r['output'])->toContain('Stored approval:    '.draStoreDir($s)."/{$b['id']}.approval (contract 3)")
        ->and($r['output'])->toContain("id={$b['id']} record_sha256=".hash('sha256', $b['record']))
        ->and($r['output'])->toContain('signature_sha256='.hash('sha256', substr($b['bundle'], strlen($b['record']))))
        ->and($r['output'])->toContain('store='.draStoreDir($s).' signer='.r229Fingerprint($s['owner_key'].'.pub'))
        ->and($r['output'])->toContain('PASS: required CI — push run 1001 of '.DRA_WORKFLOW." on {$s['sha']}")
        ->and($r['output'])->toContain("PASS: approval {$b['id']} is unspent on this host")
        ->and($r['output'])->not->toContain('deploy/release-approvals/');
});

it('refuses a stored approval whose commit, tree or GO tag is not the one deployed', function (string $case, string $reason) {
    draRequiresTooling();
    $s = draSandbox();
    $overrides = match ($case) {
        'tree' => ['DMS_APPROVAL_TARGET_TREE' => str_repeat('a', 40)],
        'go tag' => ['DMS_APPROVAL_GO_TAG' => 'dms-another-release-go'],
        'commit' => ['DMS_APPROVAL_TARGET_SHA' => $s['base'], 'DMS_APPROVAL_TARGET_TREE' => r229Git($s['app'], ['rev-parse', "{$s['base']}^{tree}"])],
    };
    draSubmitted(draSubmit($s, ($b = draBundle($s, $overrides))['bundle']), $b['id']);

    draDenied(draVerify($s), $reason);
    expect(draRequests($s))->toBe('', 'no CI query for an approval that does not bind');
})->with([
    'another tree' => ['tree', 'the record approves tree '.str_repeat('a', 40)],
    'another GO tag' => ['go tag', "the record names GO tag 'dms-another-release-go'"],
    'another commit (no substitution, no fallback)' => ['commit', 'no substitution, no fallback'],
]);

it('never falls back to contract 2 when the release has a stored approval that does not match', function (string $case, string $reason) {
    draRequiresTooling();
    // A valid, merged, owner-signed contract-2 approval of the same release and commit.
    $s = draSandbox(['approve' => true]);
    draGo(draVerify($s), '2');

    $now = time();
    if ($case === 'expired') {
        draSubmitted(draSubmit($s, ($b = draBundle($s))['bundle']), $b['id']);
        $now += 7200;
    } else {
        draSubmitted(draSubmit($s, ($b = draBundle($s, ['DMS_APPROVAL_TARGET_SHA' => $s['base'], 'DMS_APPROVAL_TARGET_TREE' => r229Git($s['app'], ['rev-parse', "{$s['base']}^{tree}"])]))['bundle']), $b['id']);
    }

    draDenied(draVerify($s, ['now' => (string) $now]), $reason);
})->with([
    'a stored approval of another commit' => ['other commit', 'no substitution, no fallback'],
    'an expired stored approval' => ['expired', 'none is active'],
]);

it('keeps contract 2 unchanged for a release with no stored approval, and never asks GitHub for it', function () {
    draRequiresTooling();
    $s = draSandbox(['approve' => true]);
    mkdir($s['store'], 0o700);
    mkdir(draStoreDir($s), 0o700);

    $r = draVerify($s);

    draGo($r, '2');
    expect($r['output'])->toContain("PASS: evidence docs/sprints/{$s['release']}.md")
        ->and($r['output'])->toContain('signer='.r229Fingerprint($s['owner_key'].'.pub'))
        ->and($r['output'])->toContain('id= record_sha256= signature_sha256=')
        ->and(draRequests($s))->toBe('');
});

it('accepts contract 3 only where the authority lists it', function (string $contracts, string $reason) {
    draRequiresTooling();
    $s = draSandbox(['approve' => true]);
    draSubmitted(draSubmit($s, ($b = draBundle($s))['bundle']), $b['id']);
    draAuthority($s, ['DMS_PRODUCTION_RELEASE_APPROVAL_CONTRACTS' => $contracts === '' ? null : '"'.$contracts.'"']);

    draDenied(draVerify($s), $reason);
})->with([
    'contract 2 only' => ['2', "this host accepts approval contracts '2' only (DMS_PRODUCTION_RELEASE_APPROVAL_CONTRACTS) — no fallback to another contract"],
    'an authority without the key' => ['', "this host accepts approval contracts '2' only"],
    'an unknown contract' => ['2 4', "DMS_PRODUCTION_RELEASE_APPROVAL_CONTRACTS '2 4' is not a list of approval contracts"],
]);

it('refuses contract 2 where the authority accepts contract 3 only, and contract 3 without a store', function () {
    draRequiresTooling();
    $s = draSandbox(['approve' => true]);
    draAuthority($s, ['DMS_PRODUCTION_RELEASE_APPROVAL_CONTRACTS' => '"3"']);
    draDenied(draVerify($s), "has no stored approval, and this host accepts approval contracts '3' only");

    draAuthority($s, ['DMS_PRODUCTION_RELEASE_APPROVAL_CONTRACTS' => '"2 3"', 'DMS_PRODUCTION_RELEASE_APPROVAL_STORE' => null]);
    draDenied(draVerify($s), 'accepts approval contract 3 but declares no approval store');
});

it('refuses a store it cannot trust completely', function (string $case, string $reason) {
    draRequiresTooling();
    $s = draSandbox();
    draSubmitted(draSubmit($s, ($b = draBundle($s))['bundle']), $b['id']);
    $dir = draStoreDir($s);
    $record = "{$dir}/{$b['id']}.approval";
    switch ($case) {
        case 'a symlinked store':
            rename($s['store'], "{$s['store']}.real");
            symlink("{$s['store']}.real", $s['store']);
            break;
        case 'a symlinked release directory':
            rename($dir, "{$s['store']}/moved");
            symlink("{$s['store']}/moved", $dir);
            break;
        case 'a symlinked record':
            rename($record, "{$s['root']}/record-copy");
            symlink("{$s['root']}/record-copy", $record);
            break;
        case 'a hardlinked record':
            link($record, "{$s['root']}/second-name");
            break;
        case 'a record made writable':
            chmod($record, 0o600);
            break;
        case 'a store others can write':
            chmod($s['store'], 0o777);
            break;
        case 'a temporary file of an interrupted submission':
            file_put_contents("{$dir}/.{$b['id']}.approval.tmp", 'partial');
            break;
        case 'an incomplete set':
            unlink("{$dir}/{$b['id']}.receipt");
            break;
        case 'an edited record':
            draRewrite($record, fn (string $text) => (string) preg_replace('/NOT_AFTER=.*/', 'NOT_AFTER='.gmdate('Y-m-d\TH:i:s\Z', time() + 7000), $text));
            break;
        case 'a record of another release':
            draRewrite($record, fn (string $text) => str_replace("RELEASE_ID={$s['release']}", 'RELEASE_ID=dms-other-release', $text));
            break;
    }

    draDenied(draVerify($s), $reason);
    expect(draRequests($s))->toBe('');
})->with([
    'a symlinked store' => ['a symlinked store', 'is not a canonical absolute directory'],
    'a symlinked release directory' => ['a symlinked release directory', 'is not a canonical absolute directory'],
    'a symlinked record' => ['a symlinked record', 'is not a regular file'],
    'a hardlinked record' => ['a hardlinked record', 'with 2 link(s)'],
    'a record made writable' => ['a record made writable', 'mode 600'],
    'a store others can write' => ['a store others can write', 'mode 777'],
    'a temporary file of an interrupted submission' => ['a temporary file of an interrupted submission', 'a temporary file of an interrupted submission — re-run submit-approval'],
    'an incomplete set' => ['an incomplete set', 'is incomplete'],
    'an edited record (signature no longer verifies)' => ['an edited record', 'does not verify for \'owner\''],
    'a record of another release' => ['a record of another release', 'of release dms-other-release, not of'],
]);

it('refuses a spent approval id at deploy time too', function () {
    draRequiresTooling();
    $s = draSandbox();
    draSubmitted(draSubmit($s, ($b = draBundle($s))['bundle']), $b['id']);
    mkdir("{$s['evidence']}/spent", 0o700, true);
    chmod($s['evidence'], 0o700);
    file_put_contents("{$s['evidence']}/spent/approval-{$b['id']}.spent", "approval_id={$b['id']}\n");

    draDenied(draVerify($s), "{$b['id']} spent");
});

// ─── 3b. Withdrawal, interrupted submissions, noglob, the release lock ─────

it('withdraws a stored approval for good, keeps it as evidence, and lets a corrected approval in', function () {
    draRequiresTooling();
    $s = draSandbox();
    // The owner signed the wrong GO tag: the approval can never deploy, and it
    // blocks every other approval of the release while it is active.
    draSubmitted(draSubmit($s, ($bad = draBundle($s, ['DMS_APPROVAL_GO_TAG' => 'dms-wrong-release-go']))['bundle']), $bad['id']);
    draDenied(draVerify($s), "the record names GO tag 'dms-wrong-release-go'");
    $good = draBundle($s);
    expect(draSubmit($s, $good['bundle'])['output'])->toContain("approval {$bad['id']} of {$s['release']} (for {$s['sha']}) is still active");

    $w = draWithdraw($s, $bad['id']);

    $dir = draStoreDir($s);
    $marker = "{$dir}/{$bad['id']}.withdrawn";
    expect($w['exit'])->toBe(0, $w['output'])
        ->and($w['output'])->toContain("RELEASE APPROVAL WITHDRAWN: {$bad['id']}")
        ->and(substr(sprintf('%o', fileperms($marker)), -3))->toBe('400')
        ->and((string) file_get_contents($marker))->toContain("withdrawn_by=admin-owner\n")
        ->and((string) file_get_contents($marker))->toMatch('/^withdrawn_at=\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/m')
        ->and((string) file_get_contents("{$s['evidence']}/spent/approval-{$bad['id']}.spent"))->toContain("withdrawn=yes\n")
        ->and(scandir($dir))->toBe(['.', '..', "{$bad['id']}.approval", "{$bad['id']}.receipt", "{$bad['id']}.sig", "{$bad['id']}.withdrawn"]);

    // Withdrawn means inactive: never deployable, never a fallback, no longer blocking.
    draDenied(draVerify($s), "{$bad['id']} withdrawn");
    draSubmitted(draSubmit($s, $good['bundle']), $good['id']);
    $v = draVerify($s);
    draGo($v, '3');
    expect($v['output'])->toContain("id={$good['id']}");

    expect(draWithdraw($s, $bad['id'])['output'])->toContain("approval {$bad['id']} of {$s['release']} is already withdrawn")
        ->and(draWithdraw($s, str_repeat('c', 32))['output'])->toContain('is not stored for')
        ->and(draWithdraw($s, '../../etc')['exit'])->toBe(77);
});

it('runs the withdrawal only as an administrator, never through the deploy operator', function () {
    draRequiresTooling();
    $s = draSandbox();
    copy(base_path('scripts/release-approval-withdraw.sh'), "{$s['app']}/scripts/release-approval-withdraw.sh");
    $wrapper = "{$s['app']}/scripts/release-approval-withdraw.sh";
    draSubmitted(draSubmit($s, ($b = draBundle($s))['bundle']), $b['id']);

    $asUser = draBash($s, 'bash "$1" --release-id "$2" --approval-id "$3"', [$wrapper, $s['release'], $b['id']]);
    expect($asUser['exit'])->toBe(2)->and($asUser['output'])->toContain('run as root');
    $main = fn (array $args, array $env = []) => draBash($s,
        'source "$1"; shift; rc=0; dms_approval_withdraw_main "$@" || rc=$?; exit "$rc"', [$wrapper, r229Uid(), ...$args], null, $env);
    foreach ([[], ['--release-id', $s['release']], ['--release-id', $s['release'], '--approval-id', 'ABC'],
        ['--approval-id', $b['id'], '--release-id', $s['release']], ['--release-id', '../x', '--approval-id', $b['id']]] as $args) {
        expect($main($args)['exit'])->toBe(64, implode(' ', $args));
    }
    $ok = $main(['--release-id', $s['release'], '--approval-id', $b['id']], ['SUDO_USER' => 'dmsadmin']);
    expect($ok['exit'])->toBe(0, $ok['output'])
        ->and((string) file_get_contents(draStoreDir($s)."/{$b['id']}.withdrawn"))->toContain("withdrawn_by=dmsadmin\n");

    // Not an operator action: neither the entrypoint nor the sudo rule knows it.
    $src = '. '.escapeshellarg(base_path('scripts/deploy-operator-entrypoint.sh')).';';
    foreach (['withdraw-approval --release-id dms-x --approval-id '.$b['id'], 'release-approval-withdraw --release-id dms-x'] as $request) {
        expect(draShell($src.' dms_operator_split '.escapeshellarg($request))[0])->not->toBe(0, $request);
    }
    expect(draShell($src.' dms_operator_program withdraw-approval')[0])->toBe(1)
        ->and(draShell('. '.escapeshellarg(base_path('scripts/lib/sudo-policy.sh')).'; dms_sudo_operator_expressions')[1])->not->toContain('withdraw')
        ->and(draCode('scripts/deploy-operator-entrypoint.sh'))->not->toContain('withdraw');
});

it('lets the next submission clear exactly what an interrupted one left, and nothing else', function (string $case, ?string $reason) {
    draRequiresTooling();
    $s = draSandbox();
    $dir = draStoreDir($s);
    mkdir($s['store'], 0o700);
    mkdir($dir, 0o700);
    $stale = bin2hex(random_bytes(16));
    switch ($case) {
        case 'temporary files':
            foreach (['sig', 'receipt', 'approval', 'withdrawn'] as $ext) {
                file_put_contents("{$dir}/.{$stale}.{$ext}.tmp", 'partial');
            }
            break;
        case 'a set whose record was never renamed into place':
            draSubmitted(draSubmit($s, ($first = draBundle($s, ['DMS_APPROVAL_ID' => $stale]))['bundle']), $stale);
            unlink("{$dir}/{$stale}.approval");
            break;
        case 'a stray file':
            file_put_contents("{$dir}/notes.txt", 'mine');
            break;
        case 'a record without its signature':
            draSubmitted(draSubmit($s, draBundle($s, ['DMS_APPROVAL_ID' => $stale])['bundle']), $stale);
            unlink("{$dir}/{$stale}.sig");
            break;
    }
    $before = scandir($dir);

    // The deploy never clears anything: it refuses and says how to clear it.
    $deploy = draVerify($s);
    expect($deploy['exit'])->toBe(77, $deploy['output']);
    if ($reason === null) {
        expect($deploy['output'])->toContain('re-run submit-approval');
    }
    expect(scandir($dir))->toBe($before);

    $b = draBundle($s);
    $r = draSubmit($s, $b['bundle']);

    if ($reason === null) {
        draSubmitted($r, $b['id']);
        expect(scandir($dir))->toBe(['.', '..', "{$b['id']}.approval", "{$b['id']}.receipt", "{$b['id']}.sig"])
            ->and($r['output'])->toContain('removed');
        draGo(draVerify($s), '3');
    } else {
        expect($r['exit'])->toBe(77, $r['output'])->and($r['output'])->toContain($reason)
            ->and(scandir($dir))->toBe($before);
    }
})->with([
    'temporary files' => ['temporary files', null],
    'a set whose record was never renamed into place' => ['a set whose record was never renamed into place', null],
    'a stray file (never removed)' => ['a stray file', 'holds an unexpected entry'],
    'a record without its signature (never removed)' => ['a record without its signature', 'an administrator inspects it'],
]);

it('reads the store the same way under noglob (set -f), never as an empty store', function () {
    draRequiresTooling();
    // A stored approval of another commit, beside a valid contract-2 approval.
    $s = draSandbox(['approve' => true]);
    $base = draBundle($s, ['DMS_APPROVAL_TARGET_SHA' => $s['base'], 'DMS_APPROVAL_TARGET_TREE' => r229Git($s['app'], ['rev-parse', "{$s['base']}^{tree}"])]);
    draSubmitted(draSubmit($s, $base['bundle'], ['shell' => 'set -f; ']), $base['id']);
    draDenied(draVerify($s, ['shell' => 'set -f; ']), 'no substitution, no fallback');

    // And a matching approval is found and verified under noglob too.
    $t = draSandbox();
    draSubmitted(draSubmit($t, ($b = draBundle($t))['bundle'], ['shell' => 'set -f; ']), $b['id']);
    $v = draVerify($t, ['shell' => 'set -f; ']);
    draGo($v, '3');
    expect($v['output'])->toContain("id={$b['id']}");

    // A spent approval stays spent under noglob (the runner-record scan included).
    $spentId = str_repeat('d', 32);
    mkdir($t['evidence'], 0o700);
    file_put_contents("{$t['evidence']}/deploy-20261116-000000.host", "deploy_id=20261116-000000\napproval_id={$spentId}\nresult_exit=0\n");
    $state = draBash($t, 'set -f; source "$1"; dms_release_approval_id_state "$2" "$3" "$4" "$5"', [base_path('scripts/lib/release-approval.sh'), $t['authority'], $t['app'], r229Uid(), $spentId]);
    expect($state['output'])->toContain('spent (completed deploy 20261116-000000)');
});

it('refuses a submission while a deploy or rollback holds the release lock', function () {
    draRequiresTooling();
    $s = draSandbox();
    touch($s['release_lock']);
    // -o: the lock is held by flock itself, so stopping it frees the lock.
    $holder = new Process(['flock', '-o', $s['release_lock'], 'sleep', '8']);
    $holder->start();
    $deadline = microtime(true) + 10;
    while (microtime(true) < $deadline && r229Run(['flock', '-n', $s['release_lock'], 'true'])['exit'] === 0) {
        usleep(50_000);
    }

    try {
        draSubmitDenied($s, draSubmit($s, ($b = draBundle($s))['bundle']), "a deploy or rollback holds the release lock {$s['release_lock']}");
    } finally {
        $holder->stop(0);
    }
    draSubmitted(draSubmit($s, $b['bundle']), $b['id']);
});

it('reports the approval store in the read-only trust check', function () {
    draRequiresTooling();
    $s = draSandbox();

    $absent = r229Runner($s, ['trust-check'], ['PATH' => $s['path']]);
    expect($absent['exit'])->toBe(0, $absent['output'])
        ->and($absent['output'])->toContain('APPROVAL_CONTRACTS=2 3')
        ->and($absent['output'])->toContain("APPROVAL_STORE=ABSENT ({$s['store']}")
        ->and($absent['output'])->toContain('RELEASE GUARD TRUST: READY')
        ->and(file_exists($s['store']))->toBeFalse('trust-check must not create the store');

    draSubmitted(draSubmit($s, ($b = draBundle($s))['bundle']), $b['id']);
    expect(r229Runner($s, ['trust-check'], ['PATH' => $s['path']])['output'])->toContain("APPROVAL_STORE=OK ({$s['store']})");

    chmod($s['store'], 0o750);
    $untrusted = r229Runner($s, ['trust-check'], ['PATH' => $s['path']]);
    expect($untrusted['exit'])->toBe(1, $untrusted['output'])
        ->and($untrusted['output'])->toContain('APPROVAL_STORE=UNTRUSTED')
        ->and($untrusted['output'])->toContain('RELEASE GUARD TRUST: NOT READY');
});

// ─── 4. Required CI (stubbed curl, no network) ─────────────────────────────

it('accepts the required CI only from the latest successful push run of the pinned workflow on the exact commit', function () {
    draRequiresTooling();
    $s = draSandbox();
    draCi($s, [draRun($s, ['id' => 900, 'event' => 'pull_request', 'conclusion' => 'failure']), draRun($s)]);

    $r = draCiVerify($s);

    expect($r['exit'])->toBe(0, $r['output'])
        ->and($r['output'])->toContain('PASS: required CI — push run 1001')
        ->and(draRequests($s))->toContain('https://api.github.com/repos/'.DRA_REPO."/actions/runs?head_sha={$s['sha']}&event=push&branch={$s['branch']}&per_page=50")
        ->and(draRequests($s))->toContain('https://api.github.com/repos/'.DRA_REPO.'/actions/runs/1001/jobs?filter=latest&per_page=100')
        // The budget is asked first (GET /rate_limit does not count against it).
        ->and(strpos(draRequests($s), 'https://api.github.com/rate_limit'))->toBeLessThan(strpos(draRequests($s), '/actions/runs?head_sha='))
        ->and($r['output'])->toContain('PASS: GitHub API rate limit — 60 of 60 requests left (this check needs 2, reserve 0; resets '.gmdate('Y-m-d\TH:i:s\Z', DRA_RATE_RESET).' UTC)')
        ->and(draRequests($s))->toContain('--proto =https')
        ->and(draRequests($s))->toContain('--max-time 30')
        ->and(draRequests($s))->not->toContain('Authorization')
        ->and(draRequests($s))->not->toContain('--insecure')
        ->and($r['output'])->toContain('ci_run=1001')
        ->and($r['output'])->toContain("'Run critical regression tests' ran");
    // curl reads no ~/.curlrc: -q is its first argument on every request.
    foreach (array_filter(explode("\n", draRequests($s))) as $request) {
        expect($request)->toStartWith('-q --silent ');
    }
});

it('denies the deploy on every CI outcome but a green run of the exact commit', function (callable $arrange, string $reason) {
    draRequiresTooling();
    $s = draSandbox();
    $arrange($s);

    $r = draCiVerify($s);

    expect($r['exit'])->toBe(77, $r['output'])
        ->and($r['output'])->toContain('DEPLOYMENT=DENIED')
        ->and($r['output'])->toContain('required CI: ')
        ->and($r['output'])->toContain($reason)
        ->and($r['output'])->not->toContain('PASS: required CI');
})->with([
    'no run at all' => [fn (array $s) => draCi($s, []), 'no push run of'],
    'the run failed' => [fn (array $s) => draCi($s, [draRun($s, ['conclusion' => 'failure'])]), 'is completed/failure, not completed/success'],
    'the run was cancelled' => [fn (array $s) => draCi($s, [draRun($s, ['conclusion' => 'cancelled'])]), 'is completed/cancelled'],
    'the run is still in progress' => [fn (array $s) => draCi($s, [draRun($s, ['status' => 'in_progress', 'conclusion' => null])]), 'is in_progress/None'],
    'a later run of the commit failed' => [fn (array $s) => draCi($s, [draRun($s), draRun($s, ['id' => 1002, 'conclusion' => 'failure'])]), 'the latest push run 1002'],
    'a run of another commit' => [fn (array $s) => draCi($s, [draRun($s, ['head_sha' => $s['base']])]), 'no push run of'],
    'a run in another repository' => [fn (array $s) => draCi($s, [draRun($s, ['repository' => ['full_name' => 'someone/fork']])]), 'no push run of'],
    'a run from a fork' => [fn (array $s) => draCi($s, [draRun($s, ['head_repository' => ['full_name' => 'someone/fork']])]), 'no push run of'],
    'a run of another workflow' => [fn (array $s) => draCi($s, [draRun($s, ['path' => '.github/workflows/deploy-vps.yml'])]), 'no push run of'],
    'a pull request run' => [fn (array $s) => draCi($s, [draRun($s, ['event' => 'pull_request'])]), 'no push run of'],
    'a run on another branch' => [fn (array $s) => draCi($s, [draRun($s, ['head_branch' => 'main'])]), 'no push run of'],
    'a required job skipped' => [fn (array $s) => draCi($s, [draRun($s)], array_map(fn (array $j) => $j['name'] === 'NSF-R012 Quality Gate' ? array_merge($j, ['conclusion' => 'skipped']) : $j, draJobs($s))), 'NSF-R012 Quality Gate did not succeed (missing or skipped)'],
    'a required job missing' => [fn (array $s) => draCi($s, [draRun($s)], array_values(array_filter(draJobs($s), fn (array $j) => $j['name'] !== 'NSF-R012 Quality Gate'))), 'NSF-R012 Quality Gate did not succeed (missing or skipped)'],
    'a required job failed' => [fn (array $s) => draCi($s, [draRun($s)], [...array_filter(draJobs($s), fn (array $j) => $j['name'] !== 'NSF-R012 Quality Gate'), draJob($s, 'NSF-R012 Quality Gate', 'failure')]), 'NSF-R012 Quality Gate concluded failure'],
    'a required job both succeeded and timed out' => [fn (array $s) => draCi($s, [draRun($s)], [...draJobs($s), draJob($s, 'NSF-R011 Critical Test Gate', 'timed_out')]), 'NSF-R011 Critical Test Gate concluded timed_out'],
    'a job of another run' => [fn (array $s) => draCi($s, [draRun($s)], [...draJobs($s), draJob($s, 'NSF-R012 Quality Gate', 'success', ['run_id' => 7])]), 'belongs to another run or commit'],
    'a truncated job list' => [fn (array $s) => draCi($s, [draRun($s)], json_encode(['total_count' => 150, 'jobs' => draJobs($s)])), 'the run has 150 jobs and the response holds 5'],
    'a network error' => [fn (array $s) => draCi($s, [draRun($s)], null, [], ['runs' => 7]), 'could not be reached (curl exit 7'],
    'a timeout on the jobs' => [fn (array $s) => draCi($s, [draRun($s)], null, [], ['jobs' => 28]), 'could not be reached (curl exit 28'],
    'rate limited (HTTP 403)' => [fn (array $s) => draCi($s, [draRun($s)], null, ['runs' => 403]), 'answered HTTP 403 — this may be its rate limit (unauthenticated: 60 requests per hour per host address)'],
    'rate limited (HTTP 429)' => [fn (array $s) => draCi($s, [draRun($s)], null, ['jobs' => 429]), 'answered HTTP 429 — this may be its rate limit'],
    'a redirect is not followed' => [fn (array $s) => draCi($s, [draRun($s)], null, ['runs' => 301]), 'answered HTTP 301'],
    'jobs not found (HTTP 404)' => [fn (array $s) => draCi($s, [draRun($s)], null, ['jobs' => 404]), 'answered HTTP 404'],
    'unparsable runs' => [fn (array $s) => draCi($s, '{"workflow_runs": [', null), 'is not valid JSON'],
    'unparsable jobs' => [fn (array $s) => draCi($s, [draRun($s)], '<html>rate limited</html>'), 'is not valid JSON'],
    'a JSON array instead of an object' => [fn (array $s) => draCi($s, '[]'), 'is not a JSON object'],
    'a response without runs' => [fn (array $s) => draCi($s, '{"message": "Not Found"}'), 'lists no workflow runs'],
    // A docs-only change set: the critical job concludes success without testing.
    'a docs-only run (the test step skipped, the note step ran)' => [fn (array $s) => draCi($s, [draRun($s)], draCriticalSteps($s, draSteps([
        'Run critical regression tests' => 'skipped', 'Note skipped critical regression tests (docs-only change)' => 'success']))), 'took the step Note skipped critical regression tests (docs-only change): its tests did not run'],
    'the test step missing' => [fn (array $s) => draCi($s, [draRun($s)], draCriticalSteps($s, draSteps(['Something else' => 'success']))), 'did not run the step Run critical regression tests with success (no such step)'],
    'the test step skipped' => [fn (array $s) => draCi($s, [draRun($s)], draCriticalSteps($s, draSteps(['Run critical regression tests' => 'skipped']))), 'did not run the step Run critical regression tests with success (skipped)'],
    'the test step failed inside a green job' => [fn (array $s) => draCi($s, [draRun($s)], draCriticalSteps($s, draSteps(['Run critical regression tests' => 'failure']))), 'with success (failure)'],
    'a critical job without steps' => [fn (array $s) => draCi($s, [draRun($s)], draCriticalSteps($s, null)), 'lists no steps'],
]);

it('takes every CI fact from the authority, never from the record', function (array $overrides, string $reason) {
    draRequiresTooling();
    $s = draSandbox();

    $r = draCiVerify($s, $overrides);

    expect($r['exit'])->toBe(77, $r['output'])->and($r['output'])->toContain($reason)->and(draRequests($s))->toBe('');
})->with([
    'no workflow declared' => [['workflow' => ''], 'declares no workflow path'],
    'no required job declared' => [['jobs' => ''], 'declares no required job'],
    'an empty job name' => [['jobs' => DRA_JOBS.';'], 'has an empty job name'],
    'an empty job name in the middle' => [['jobs' => 'NSF-R012 Quality Gate;;NSF-R011 Critical Test Gate'], 'has an empty job name'],
    'a repository that is not OWNER/REPO' => [['repo' => '/srv/origin'], 'is not a GitHub OWNER/REPO'],
    'an abbreviated commit' => [['sha' => '0123456789ab'], 'is not a full commit SHA'],
    'no required step declared' => [['required_step' => ''], "'' is not '<job>:<step>'"],
    'no forbidden step declared' => [['forbidden_step' => ''], "'' is not '<job>:<step>'"],
    'a required step without its job' => [['required_step' => 'Run critical regression tests'], "is not '<job>:<step>'"],
    'a required step of a job that is not required' => [['required_step' => 'CICD-CTRL Selective Module Gate:Run'], "the required step's job 'CICD-CTRL Selective Module Gate' is not a required job"],
]);

it('denies a contract-3 deploy whose commit has no green CI', function () {
    draRequiresTooling();
    $s = draSandbox();
    draSubmitted(draSubmit($s, ($b = draBundle($s))['bundle']), $b['id']);
    draCi($s, [draRun($s, ['conclusion' => 'failure'])]);

    draDenied(draVerify($s), 'required CI: the latest push run 1001');
});

// ─── 4b. Manual dispatch evidence (CI-CD-PR-ONLY-POLICY-1 phase B) ─────────
//
// The authority may also accept a `workflow_dispatch` run of the same workflow
// on the base branch. GitHub runs a dispatch on the tip of the ref it names, so
// such a run proves exactly the commit the branch held when it was dispatched:
// it counts only when that commit IS the release commit. Everything a push run
// must prove, a dispatch run must prove too, and it must not be a CI-PERF-04
// evaluation (rule 212 §3).

it('accepts a green push run of the exact commit where the authority accepts push and workflow_dispatch', function () {
    draRequiresTooling();
    $s = draSandbox();
    draCi($s, [draRun($s)]);

    $r = draDispatchCiVerify($s);

    expect($r['exit'])->toBe(0, $r['output'])
        ->and($r['output'])->toContain('PASS: required CI — push run 1001 of '.DRA_WORKFLOW." on {$s['sha']}")
        ->and($r['output'])->toContain('ci_run=1001')
        // Two accepted events: GitHub filters commit and branch, the evaluator every event.
        ->and(draRequests($s))->toContain('https://api.github.com/repos/'.DRA_REPO."/actions/runs?head_sha={$s['sha']}&branch={$s['branch']}&per_page=50")
        ->and(draRequests($s))->not->toContain('event=')
        ->and(draRequests($s))->toContain('https://api.github.com/repos/'.DRA_REPO.'/actions/runs/1001/jobs?filter=latest&per_page=100')
        // Still exactly two counted requests: the budget is unchanged.
        ->and($r['output'])->toContain('(this check needs 2, reserve 0;');
});

it('accepts a green manual dispatch run of the exact commit on the base branch', function (?string $sampler) {
    draRequiresTooling();
    $s = draSandbox();
    draCi($s, [draDispatchRun($s)], draDispatchJobs($s, $sampler));

    $r = draDispatchCiVerify($s);

    expect($r['exit'])->toBe(0, $r['output'])
        ->and($r['output'])->toContain('PASS: required CI — workflow_dispatch run 1001 of '.DRA_WORKFLOW." on {$s['sha']} (".DRA_REPO.", {$s['branch']}) completed with success")
        ->and($r['output'])->toContain("'Run critical regression tests' ran")
        ->and($r['output'])->toContain('ci_run=1001');
})->with([
    'the evaluation sampler skipped (sequential run)' => ['skipped'],
    'no evaluation sampler step at all' => ['absent'],
]);

it('accepts only one kind of run where the authority names one event', function (string $events, string $event, bool $accepted, string $query) {
    draRequiresTooling();
    $s = draSandbox();
    draCi($s, [draRun($s, ['event' => $event])], draDispatchJobs($s));

    $r = draDispatchCiVerify($s, ['events' => $events]);

    expect($r['exit'])->toBe($accepted ? 0 : 77, $r['output'])
        ->and(draRequests($s))->toContain("/actions/runs?head_sha={$s['sha']}&{$query}branch={$s['branch']}&per_page=50");
    if (! $accepted) {
        expect($r['output'])->toContain("required CI: no {$events} run of");
    }
})->with([
    'push only: a push run' => ['push', 'push', true, 'event=push&'],
    'push only: a dispatch run is not evidence' => ['push', 'workflow_dispatch', false, 'event=push&'],
    'dispatch only: a dispatch run' => ['workflow_dispatch', 'workflow_dispatch', true, 'event=workflow_dispatch&'],
    'dispatch only: a push run is not evidence' => ['workflow_dispatch', 'push', false, 'event=workflow_dispatch&'],
]);

it('never takes a dispatch run as evidence on a host whose authority predates it', function () {
    draRequiresTooling();
    $s = draSandbox();
    draCi($s, [draDispatchRun($s)], draDispatchJobs($s));

    // No DMS_PRODUCTION_CI_EVENTS: push runs only, the original query byte for byte.
    $r = draCiVerify($s);

    expect($r['exit'])->toBe(77, $r['output'])
        ->and($r['output'])->toContain('required CI: no push run of')
        ->and(draRequests($s))->toContain("/actions/runs?head_sha={$s['sha']}&event=push&branch={$s['branch']}&per_page=50");
});

it('denies every dispatch outcome but a green dispatch run of the exact commit', function (callable $arrange, string $reason) {
    draRequiresTooling();
    $s = draSandbox();
    $arrange($s);

    $r = draDispatchCiVerify($s);

    expect($r['exit'])->toBe(77, $r['output'])
        ->and($r['output'])->toContain('DEPLOYMENT=DENIED')
        ->and($r['output'])->toContain('required CI: ')
        ->and($r['output'])->toContain($reason)
        ->and($r['output'])->not->toContain('PASS: required CI')
        ->and($r['output'])->toContain('ci_run='."\n");
})->with([
    // Identity: commit, branch, repository, fork, workflow, event.
    'no run at all (missing evidence)' => [fn (array $s) => draCi($s, []), 'no push or workflow_dispatch run of'],
    'a dispatch after the branch moved on (it ran on the newer tip)' => [fn (array $s) => draCi($s, [draDispatchRun($s, ['head_sha' => sha1('a newer tip of the base branch')])], draDispatchJobs($s)), 'no push or workflow_dispatch run of'],
    'a dispatch of an older commit' => [fn (array $s) => draCi($s, [draDispatchRun($s, ['head_sha' => $s['base']])], draDispatchJobs($s)), 'no push or workflow_dispatch run of'],
    'a dispatch on another branch' => [fn (array $s) => draCi($s, [draDispatchRun($s, ['head_branch' => 'feature/someone-else'])], draDispatchJobs($s)), 'no push or workflow_dispatch run of'],
    'a dispatch on a ref that only resembles the branch' => [fn (array $s) => draCi($s, [draDispatchRun($s, ['head_branch' => "{$s['branch']}-copy"])], draDispatchJobs($s)), 'no push or workflow_dispatch run of'],
    'a dispatch in another repository' => [fn (array $s) => draCi($s, [draDispatchRun($s, ['repository' => ['full_name' => 'someone/fork']])], draDispatchJobs($s)), 'no push or workflow_dispatch run of'],
    'a dispatch from a fork' => [fn (array $s) => draCi($s, [draDispatchRun($s, ['head_repository' => ['full_name' => 'someone/fork']])], draDispatchJobs($s)), 'no push or workflow_dispatch run of'],
    'a dispatch of another workflow' => [fn (array $s) => draCi($s, [draDispatchRun($s, ['path' => '.github/workflows/deploy-vps.yml'])], draDispatchJobs($s)), 'no push or workflow_dispatch run of'],
    'a dispatch run without a numeric id' => [fn (array $s) => draCi($s, [draDispatchRun($s, ['id' => '1001'])], draDispatchJobs($s)), 'no push or workflow_dispatch run of'],
    'a repository_dispatch run (an API event, not a manual dispatch)' => [fn (array $s) => draCi($s, [draRun($s, ['event' => 'repository_dispatch'])], draDispatchJobs($s)), 'no push or workflow_dispatch run of'],
    'a scheduled run' => [fn (array $s) => draCi($s, [draRun($s, ['event' => 'schedule'])], draDispatchJobs($s)), 'no push or workflow_dispatch run of'],
    'a workflow_run run' => [fn (array $s) => draCi($s, [draRun($s, ['event' => 'workflow_run'])], draDispatchJobs($s)), 'no push or workflow_dispatch run of'],
    'a pull request run' => [fn (array $s) => draCi($s, [draRun($s, ['event' => 'pull_request'])], draDispatchJobs($s)), 'no push or workflow_dispatch run of'],
    // State of the run.
    'the dispatch run is still in progress' => [fn (array $s) => draCi($s, [draDispatchRun($s, ['status' => 'in_progress', 'conclusion' => null])], draDispatchJobs($s)), 'the latest workflow_dispatch run 1001 of '.DRA_WORKFLOW],
    'the dispatch run is queued' => [fn (array $s) => draCi($s, [draDispatchRun($s, ['status' => 'queued', 'conclusion' => null])], draDispatchJobs($s)), 'is queued/None, not completed/success'],
    'the dispatch run failed' => [fn (array $s) => draCi($s, [draDispatchRun($s, ['conclusion' => 'failure'])], draDispatchJobs($s)), 'is completed/failure, not completed/success'],
    'the dispatch run was cancelled' => [fn (array $s) => draCi($s, [draDispatchRun($s, ['conclusion' => 'cancelled'])], draDispatchJobs($s)), 'is completed/cancelled'],
    'the dispatch run timed out' => [fn (array $s) => draCi($s, [draDispatchRun($s, ['conclusion' => 'timed_out'])], draDispatchJobs($s)), 'is completed/timed_out'],
    'the dispatch run concluded neutral' => [fn (array $s) => draCi($s, [draDispatchRun($s, ['conclusion' => 'neutral'])], draDispatchJobs($s)), 'is completed/neutral'],
    'the dispatch run was skipped' => [fn (array $s) => draCi($s, [draDispatchRun($s, ['conclusion' => 'skipped'])], draDispatchJobs($s)), 'is completed/skipped'],
    // The latest run of either event decides.
    'a newer dispatch of the commit failed after a green push run' => [fn (array $s) => draCi($s, [draRun($s), draDispatchRun($s, ['id' => 1002, 'conclusion' => 'failure'])], draDispatchJobs($s)), 'the latest workflow_dispatch run 1002'],
    'a newer push run of the commit failed after a green dispatch' => [fn (array $s) => draCi($s, [draDispatchRun($s), draRun($s, ['id' => 1002, 'conclusion' => 'failure'])], draDispatchJobs($s)), 'the latest push run 1002'],
    'a newer dispatch of the commit is still running' => [fn (array $s) => draCi($s, [draRun($s), draDispatchRun($s, ['id' => 1002, 'status' => 'in_progress', 'conclusion' => null])], draDispatchJobs($s)), 'the latest workflow_dispatch run 1002'],
    // Jobs and steps of a green run.
    'a required job skipped' => [fn (array $s) => draCi($s, [draDispatchRun($s)], array_map(fn (array $j) => $j['name'] === 'NSF-R012 Quality Gate' ? array_merge($j, ['conclusion' => 'skipped']) : $j, draDispatchJobs($s))), 'NSF-R012 Quality Gate did not succeed (missing or skipped)'],
    'a required job missing' => [fn (array $s) => draCi($s, [draDispatchRun($s)], array_values(array_filter(draDispatchJobs($s), fn (array $j) => $j['name'] !== 'CICD-CTRL Gate Classifier'))), 'CICD-CTRL Gate Classifier did not succeed (missing or skipped)'],
    'a green run with a failed required job' => [fn (array $s) => draCi($s, [draDispatchRun($s)], [...array_filter(draDispatchJobs($s), fn (array $j) => $j['name'] !== 'NSF-R012 Quality Gate'), draJob($s, 'NSF-R012 Quality Gate', 'failure')]), 'NSF-R012 Quality Gate concluded failure'],
    'a green run with a cancelled required job' => [fn (array $s) => draCi($s, [draDispatchRun($s)], [...draDispatchJobs($s), draJob($s, 'NSF-R012 Quality Gate', 'cancelled')]), 'NSF-R012 Quality Gate concluded cancelled'],
    'a green run with a neutral required job' => [fn (array $s) => draCi($s, [draDispatchRun($s)], [...draDispatchJobs($s), draJob($s, 'CICD-CTRL Gate Classifier', 'neutral')]), 'CICD-CTRL Gate Classifier concluded neutral'],
    'the critical test step skipped' => [fn (array $s) => draCi($s, [draDispatchRun($s)], draDispatchJobs($s, 'skipped', 'skipped')), 'did not run the step Run critical regression tests with success (skipped)'],
    'the critical test step failed inside a green job' => [fn (array $s) => draCi($s, [draDispatchRun($s)], draDispatchJobs($s, 'skipped', 'failure')), 'did not run the step Run critical regression tests with success (failure)'],
    'the critical test step missing' => [fn (array $s) => draCi($s, [draDispatchRun($s)], draCriticalSteps($s, draSteps(['Something else' => 'success']))), 'did not run the step Run critical regression tests with success (no such step)'],
    'a docs-only skip' => [fn (array $s) => draCi($s, [draDispatchRun($s)], draCriticalSteps($s, draSteps([
        'Run critical regression tests' => 'skipped', 'Note skipped critical regression tests (docs-only change)' => 'success']))), 'took the step Note skipped critical regression tests (docs-only change): its tests did not run'],
    'a job of another run' => [fn (array $s) => draCi($s, [draDispatchRun($s)], [...draDispatchJobs($s), draJob($s, 'NSF-R012 Quality Gate', 'success', ['run_id' => 7])]), 'belongs to another run or commit'],
    'a job of another commit' => [fn (array $s) => draCi($s, [draDispatchRun($s)], [...draDispatchJobs($s), draJob($s, 'NSF-R012 Quality Gate', 'success', ['head_sha' => $s['base']])]), 'belongs to another run or commit'],
    // A CI-PERF-04 evaluation dispatch is never a gate's verdict (rule 212 §3).
    'an evaluation dispatch (the sampler ran)' => [fn (array $s) => draCi($s, [draDispatchRun($s)], draDispatchJobs($s, 'success')), 'took the step Start resource sampler (CI-PERF-04 evaluation): an evaluation run is never release evidence (rule 212)'],
    'an evaluation dispatch whose sampler failed' => [fn (array $s) => draCi($s, [draDispatchRun($s)], draDispatchJobs($s, 'failure')), 'an evaluation run is never release evidence (rule 212)'],
    'an evaluation step with no conclusion' => [fn (array $s) => draCi($s, [draDispatchRun($s)], draDispatchJobs($s, null)), 'an evaluation run is never release evidence (rule 212)'],
    // The API.
    'a network error' => [fn (array $s) => draCi($s, [draDispatchRun($s)], draDispatchJobs($s), [], ['runs' => 6]), 'could not be reached (curl exit 6'],
    'a server error on the runs' => [fn (array $s) => draCi($s, [draDispatchRun($s)], draDispatchJobs($s), ['runs' => 500]), 'answered HTTP 500'],
    'rate limited on the jobs' => [fn (array $s) => draCi($s, [draDispatchRun($s)], draDispatchJobs($s), ['jobs' => 403]), 'answered HTTP 403 — this may be its rate limit'],
    'unparsable runs' => [fn (array $s) => draCi($s, '{"workflow_runs": [', draDispatchJobs($s)), 'is not valid JSON'],
    'unparsable jobs' => [fn (array $s) => draCi($s, [draDispatchRun($s)], '{"jobs": '), 'is not valid JSON'],
    'runs that are not a list' => [fn (array $s) => draCi($s, '{"total_count": 1, "workflow_runs": {"id": 1001}}', draDispatchJobs($s)), 'lists no workflow runs'],
    'jobs without a count' => [fn (array $s) => draCi($s, [draDispatchRun($s)], json_encode(['jobs' => draDispatchJobs($s)])), 'lists no jobs'],
    'a truncated job list' => [fn (array $s) => draCi($s, [draDispatchRun($s)], json_encode(['total_count' => 150, 'jobs' => draDispatchJobs($s)])), 'the run has 150 jobs and the response holds 5'],
]);

it('takes the accepted events and the evaluation step from the authority, and refuses a declaration it cannot read', function (array $overrides, string $reason) {
    draRequiresTooling();
    $s = draSandbox();
    draCi($s, [draDispatchRun($s)], draDispatchJobs($s));

    $r = draDispatchCiVerify($s, $overrides);

    // Refused before a single request, the rate-limit read included.
    expect($r['exit'])->toBe(77, $r['output'])->and($r['output'])->toContain($reason)->and(draRequests($s))->toBe('');
})->with([
    'an event GitHub never sends for a manual run' => [['events' => 'push repository_dispatch'], "names 'repository_dispatch', which is not an accepted CI event (push, workflow_dispatch)"],
    'the schedule event' => [['events' => 'schedule'], "names 'schedule', which is not an accepted CI event"],
    'the pull_request event' => [['events' => 'pull_request push'], "names 'pull_request', which is not an accepted CI event"],
    'an event twice' => [['events' => 'push push'], "names 'push' twice"],
    'a comma-separated list' => [['events' => 'push,workflow_dispatch'], 'is not a space-separated list of push and workflow_dispatch'],
    'a double space' => [['events' => 'push  workflow_dispatch'], 'is not a space-separated list of push and workflow_dispatch'],
    'three events' => [['events' => 'push workflow_dispatch push'], 'is not a space-separated list of push and workflow_dispatch'],
    'an upper-case event' => [['events' => 'PUSH'], 'is not a space-separated list of push and workflow_dispatch'],
    'dispatch without an evaluation step' => [['evaluation_step' => ''], 'accepts workflow_dispatch runs but declares no evaluation step (DMS_PRODUCTION_CI_EVALUATION_STEP)'],
    'dispatch only, without an evaluation step' => [['events' => 'workflow_dispatch', 'evaluation_step' => ''], 'declares no evaluation step'],
    'an evaluation step without its job' => [['evaluation_step' => 'Start resource sampler (CI-PERF-04 evaluation)'], "is not '<job>:<step>' (DMS_PRODUCTION_CI_EVALUATION_STEP)"],
    'an evaluation step of a job that is not required' => [['evaluation_step' => 'CICD-CTRL Selective Module Gate:Start'], "the evaluation step's job 'CICD-CTRL Selective Module Gate' is not a required job"],
]);

it('verifies a contract-3 release on dispatch evidence alone, and binds the run it verified', function () {
    draRequiresTooling();
    $s = draSandbox();
    draAuthority($s, ['DMS_PRODUCTION_CI_EVENTS' => '"'.DRA_EVENTS.'"', 'DMS_PRODUCTION_CI_EVALUATION_STEP' => '"'.DRA_EVALUATION_STEP.'"']);
    draSubmitted(draSubmit($s, ($b = draBundle($s))['bundle']), $b['id']);
    draCi($s, [draDispatchRun($s, ['id' => 2002])], array_map(fn (array $j) => array_merge($j, ['run_id' => 2002]), draDispatchJobs($s)));

    $r = draVerify($s);

    draGo($r, '3');
    expect($r['output'])->toContain('PASS: required CI — workflow_dispatch run 2002 of '.DRA_WORKFLOW." on {$s['sha']}")
        ->and($r['output'])->toContain("PASS: approval {$b['id']} is unspent on this host")
        ->and($r['output'])->toContain('ci_run=2002');
});

it('denies a contract-3 release whose only dispatch run proves a newer tip, or is an evaluation', function (callable $arrange, string $reason) {
    draRequiresTooling();
    $s = draSandbox();
    draAuthority($s, ['DMS_PRODUCTION_CI_EVENTS' => '"'.DRA_EVENTS.'"', 'DMS_PRODUCTION_CI_EVALUATION_STEP' => '"'.DRA_EVALUATION_STEP.'"']);
    draSubmitted(draSubmit($s, ($b = draBundle($s))['bundle']), $b['id']);
    $arrange($s);

    draDenied(draVerify($s), $reason);
})->with([
    'the branch moved on before the dispatch' => [fn (array $s) => draCi($s, [draDispatchRun($s, ['head_sha' => sha1('a newer tip of the base branch')])], draDispatchJobs($s)), 'required CI: no push or workflow_dispatch run of'],
    'an evaluation dispatch' => [fn (array $s) => draCi($s, [draDispatchRun($s)], draDispatchJobs($s, 'success')), 'an evaluation run is never release evidence (rule 212)'],
    'the authority lists dispatch but no evaluation step' => [fn (array $s) => draAuthority($s, ['DMS_PRODUCTION_CI_EVALUATION_STEP' => null]), 'declares no evaluation step (DMS_PRODUCTION_CI_EVALUATION_STEP)'],
]);

it('never lets green dispatch evidence stand in for the owner approval, or replay a spent one', function () {
    draRequiresTooling();
    // No stored approval and no contract-2 record: denied, and GitHub is never asked.
    $s = draSandbox();
    draAuthority($s, ['DMS_PRODUCTION_CI_EVENTS' => '"'.DRA_EVENTS.'"', 'DMS_PRODUCTION_CI_EVALUATION_STEP' => '"'.DRA_EVALUATION_STEP.'"']);
    mkdir($s['store'], 0o700);
    mkdir(draStoreDir($s), 0o700);
    draCi($s, [draDispatchRun($s)], draDispatchJobs($s));

    draDenied(draVerify($s), 'the release is not approved');
    expect(draRequests($s))->toBe('');

    // A spent approval id stays spent whatever CI says (rule 231 / rule 236 §2.4).
    $t = draSandbox();
    draAuthority($t, ['DMS_PRODUCTION_CI_EVENTS' => '"'.DRA_EVENTS.'"', 'DMS_PRODUCTION_CI_EVALUATION_STEP' => '"'.DRA_EVALUATION_STEP.'"']);
    draSubmitted(draSubmit($t, ($b = draBundle($t))['bundle']), $b['id']);
    draCi($t, [draDispatchRun($t)], draDispatchJobs($t));
    mkdir("{$t['evidence']}/spent", 0o700, true);
    chmod($t['evidence'], 0o700);
    file_put_contents("{$t['evidence']}/spent/approval-{$b['id']}.spent", "approval_id={$b['id']}\n");

    draDenied(draVerify($t), "{$b['id']} spent");
});

it('declares the phase-B CI facts in the production authority, pinned to real workflow steps', function () {
    $authority = (string) file_get_contents(base_path('deploy/production-host.conf'));

    expect($authority)->toMatch('/^DMS_PRODUCTION_CI_EVENTS="push workflow_dispatch"$/m')
        ->and($authority)->toMatch('/^DMS_PRODUCTION_CI_EVALUATION_STEP="'.preg_quote(DRA_EVALUATION_STEP, '/').'"$/m')
        ->and(config('deployment_entrypoint.approval_guard_expectations.required_authority_keys'))
        ->toContain('DMS_PRODUCTION_CI_EVENTS', 'DMS_PRODUCTION_CI_EVALUATION_STEP')
        ->and(config('deployment_entrypoint.approval_guard_expectations.pinned_authority_values.DMS_PRODUCTION_CI_EVENTS'))->toBe(DRA_EVENTS)
        ->and(config('deployment_entrypoint.approval_guard_expectations.pinned_authority_values.DMS_PRODUCTION_CI_EVALUATION_STEP'))->toBe(DRA_EVALUATION_STEP);

    // CI-CD-PR-ONLY-POLICY-1 phase B5 (CG-16 CI trigger removal, owner decisions 17–19): CI runs
    // automatically only for pull requests. The release evidence is a manual dispatch of the exact
    // commit; the authority still lists `push` (B5-2), so the guard keeps accepting historical push
    // runs, but the workflow no longer creates them (rule 236 §5).
    $workflow = Yaml::parseFile(base_path(DRA_WORKFLOW));
    expect($workflow['on'])->toHaveKeys(['pull_request', 'workflow_dispatch'])
        ->and($workflow['on'])->not->toHaveKey('push')
        ->and($workflow['on'])->not->toHaveKey('schedule');

    // In both critical-gate variants the evaluation step exists, runs ONLY on a
    // CI-PERF-04 evaluation, and the required test step exists beside it.
    [$job, $step] = explode(':', DRA_EVALUATION_STEP, 2);
    foreach (['critical_test_gate', 'critical_test_gate_self_hosted'] as $key) {
        $steps = collect($workflow['jobs'][$key]['steps'])->keyBy('name');
        expect($workflow['jobs'][$key]['name'])->toBe($job)
            ->and($steps->has($step))->toBeTrue("{$key} has no step '{$step}'")
            ->and((string) $steps->get($step)['if'])->toContain("steps.critical_parallel.outputs.critical_parallel_evaluation == 'true'")
            ->and($steps->has(explode(':', DRA_REQUIRED_STEP, 2)[1]))->toBeTrue();
    }

    // The resolver labels only a dispatch with a worker count as an evaluation.
    $resolve = function (array $args): string {
        $p = new Process(['bash', base_path('scripts/ci/resolve-critical-parallelism.sh'), ...$args], base_path());
        $p->run();

        return $p->getOutput();
    };
    expect($resolve(['--event', 'push']))->toContain('critical_parallel_evaluation=false')
        ->and($resolve(['--event', 'workflow_dispatch', '--dispatch-workers', 'repository-default']))->toContain('critical_parallel_evaluation=false')
        ->and($resolve(['--event', 'workflow_dispatch', '--dispatch-workers', '4']))->toContain('critical_parallel_evaluation=true');
});

// ─── 5. A real contract-3 deploy: runner record and verify ─────────────────

it('deploys a stored approval with no approval commit on the branch, records it and proves it by identifier', function () {
    draRequiresTooling();
    $s = draSandbox();
    // The base branch carries only the release commit: no approval record, no evidence document.
    expect(r229Git($s['origin'], ['rev-parse', $s['branch']]))->toBe($s['sha'])
        ->and(r229Git($s['app'], ['ls-tree', '-r', '--name-only', $s['sha'], 'deploy/release-approvals']))->toBe('');
    draSubmitted(draSubmit($s, ($b = draBundle($s))['bundle']), $b['id']);

    $start = r229Runner($s, ['start', '--release-id', $s['release'], '--approved-sha', $s['sha']], ['PATH' => $s['path']]);
    expect($start['exit'])->toBe(0, $start['output'])
        ->and($start['output'])->toContain('RELEASE APPROVAL: GO')
        ->and(preg_match('/deploy_id=(\d{8}-\d{6})/', $start['output'], $m))->toBe(1);
    $id = $m[1];
    expect(r229WaitForStatus($s, $id))->toBe('exit=0', (string) @file_get_contents(r229EvidenceFile($s, "deploy-{$id}.log")));

    $record = (string) file_get_contents(r229EvidenceFile($s, "deploy-{$id}.host"));
    $log = (string) file_get_contents(r229EvidenceFile($s, "deploy-{$id}.log"));
    $recordSha = hash('sha256', $b['record']);
    expect($record)->toContain("approval_contract=3\n")
        ->and($record)->toContain("approval_id={$b['id']}\n")
        ->and($record)->toContain("approval_record_sha256={$recordSha}\n")
        ->and($record)->toContain('approval_signature_sha256='.hash('sha256', substr($b['bundle'], strlen($b['record'])))."\n")
        ->and($record)->toContain('approval_store_path='.draStoreDir($s)."\n")
        ->and($record)->toContain('approval_signer_key='.r229Fingerprint($s['owner_key'].'.pub')."\n")
        ->and($record)->toContain('approval_ci_run_id='.DRA_RUN_ID."\n")
        ->and($log)->toContain('DEPLOY_APPROVAL_CI_RUN_ID='.DRA_RUN_ID."\n")
        ->and($record)->not->toContain('approval_blob=')
        ->and($record)->toContain('runner_contract=4')
        ->and($log)->toContain("DEPLOY_APPROVAL_CONTRACT=3\nDEPLOY_APPROVAL_ID={$b['id']}\n")
        ->and($log)->not->toContain('DEPLOY_APPROVAL_RECORD=')
        ->and($log)->toContain("DEPLOY_APPROVAL_ID_SPENT={$s['evidence']}/spent/approval-{$b['id']}.spent")
        ->and(is_file("{$s['evidence']}/spent/{$s['release']}.spent"))->toBeTrue()
        ->and(r229Git($s['app'], ['rev-parse', 'HEAD']))->toBe($s['sha'])
        // The runner and the bootstrap (inside the lock) each asked GitHub.
        ->and(substr_count(draRequests($s), '/actions/runs?head_sha='))->toBe(2)
        ->and(substr_count(draRequests($s), '/jobs?filter=latest'))->toBe(2)
        ->and(substr_count(draRequests($s), '/rate_limit'))->toBe(2)
        ->and($log)->toContain('reserve 0;');

    $verify = r229Runner($s, ['verify', $id], ['PATH' => $s['path']]);
    expect($verify['exit'])->toBe(0, $verify['output'])
        ->and($verify['output'])->toContain("approval contract 3, stored approval {$b['id']} (record sha256 {$recordSha}), required CI run ".DRA_RUN_ID)
        ->and($verify['output'])->toContain("DEPLOY_APPROVED_TARGET_MATCH=YES ({$s['sha']})")
        ->and($verify['output'])->toContain('DEPLOY RUNNER OK');

    // Single use: the approval id is spent (the stored approval is no longer
    // active, and nothing falls back), and the release takes no new approval.
    $again = r229Runner($s, ['start', '--release-id', $s['release'], '--approved-sha', $s['sha']], ['PATH' => $s['path']]);
    expect($again['exit'])->toBe(77, $again['output'])
        ->and($again['output'])->toContain("{$b['id']} spent")
        ->and($again['output'])->toContain('none is active')
        ->and($again['output'])->not->toContain('started detached');
    $resubmit = draSubmit($s, draBundle($s)['bundle']);
    expect($resubmit['exit'])->toBe(77, $resubmit['output'])
        ->and($resubmit['output'])->toContain("release {$s['release']} was already deployed on this host");
});

it('never proves a contract-3 run whose stored approval or log changed afterwards', function (string $case, string $verdict) {
    draRequiresTooling();
    $s = draSandbox();
    draSubmitted(draSubmit($s, ($b = draBundle($s))['bundle']), $b['id']);
    $start = r229Runner($s, ['start', '--release-id', $s['release'], '--approved-sha', $s['sha']], ['PATH' => $s['path']]);
    expect(preg_match('/deploy_id=(\d{8}-\d{6})/', $start['output'], $m))->toBe(1, $start['output']);
    expect(r229WaitForStatus($s, $m[1]))->toBe('exit=0');
    $dir = draStoreDir($s);
    $log = r229EvidenceFile($s, "deploy-{$m[1]}.log");
    $record = r229EvidenceFile($s, "deploy-{$m[1]}.host");
    switch ($case) {
        case 'the stored record rewritten':
            draRewrite("{$dir}/{$b['id']}.approval", fn (string $t) => $t."\n");
            break;
        case 'the stored signature removed':
            unlink("{$dir}/{$b['id']}.sig");
            break;
        case 'the stored record opened up':
            chmod("{$dir}/{$b['id']}.approval", 0o644);
            break;
        case 'the log naming another approval':
            file_put_contents($log, str_replace("DEPLOY_APPROVAL_ID={$b['id']}", 'DEPLOY_APPROVAL_ID='.str_repeat('0', 32), (string) file_get_contents($log)));
            break;
        case 'the log naming a contract-2 record too':
            file_put_contents($log, (string) file_get_contents($log)."DEPLOY_APPROVAL_RECORD={$s['sha']}:deploy/release-approvals/x.approval blob={$s['sha']}\n");
            break;
        case 'the record pointing at another store':
            file_put_contents($record, str_replace('approval_store_path='.$dir, 'approval_store_path=/elsewhere', (string) file_get_contents($record)));
            break;
        case 'the record naming an unknown approval contract':
            file_put_contents($record, str_replace('approval_contract=3', 'approval_contract=9', (string) file_get_contents($record)));
            break;
        case 'the log naming another CI run':
            file_put_contents($log, str_replace('DEPLOY_APPROVAL_CI_RUN_ID='.DRA_RUN_ID, 'DEPLOY_APPROVAL_CI_RUN_ID=999', (string) file_get_contents($log)));
            break;
        case 'the record naming no CI run':
            file_put_contents($record, str_replace('approval_ci_run_id='.DRA_RUN_ID."\n", '', (string) file_get_contents($record)));
            break;
    }

    $verify = r229Runner($s, ['verify', $m[1]], ['PATH' => $s['path']]);

    expect($verify['exit'])->toBe(4, $verify['output'])
        ->and($verify['output'])->toContain("DEPLOY RUNNER STATUS {$verdict}")
        ->and($verify['output'])->not->toContain('DEPLOY RUNNER OK');
})->with([
    'the stored record rewritten' => ['the stored record rewritten', 'TAMPERED'],
    'the stored signature removed' => ['the stored signature removed', 'TAMPERED'],
    'the stored record opened up' => ['the stored record opened up', 'TAMPERED'],
    'the log naming another approval' => ['the log naming another approval', 'MISMATCHED'],
    'the log naming a contract-2 record too' => ['the log naming a contract-2 record too', 'MISMATCHED'],
    'the record pointing at another store' => ['the record pointing at another store', 'MISMATCHED'],
    'the record naming an unknown approval contract' => ['the record naming an unknown approval contract', 'INCOMPATIBLE'],
    'the log naming another CI run' => ['the log naming another CI run', 'MISMATCHED'],
    'the record naming no CI run' => ['the record naming no CI run', 'INCOMPLETE'],
]);

it('still deploys and proves a contract-2 release on a host that accepts contract 3', function () {
    draRequiresTooling();
    $s = draSandbox(['approve' => true]);

    $start = r229Runner($s, ['start', '--release-id', $s['release'], '--approved-sha', $s['sha']], ['PATH' => $s['path']]);
    expect(preg_match('/deploy_id=(\d{8}-\d{6})/', $start['output'], $m))->toBe(1, $start['output']);
    expect(r229WaitForStatus($s, $m[1]))->toBe('exit=0');
    $record = (string) file_get_contents(r229EvidenceFile($s, "deploy-{$m[1]}.host"));

    expect($record)->toContain("approval_contract=2\n")
        ->and($record)->toMatch('/^approval_blob=[0-9a-f]{40}$/m')
        ->and($record)->not->toContain('approval_id=')
        ->and(glob("{$s['evidence']}/spent/approval-*") ?: [])->toBe([])
        ->and(r229Runner($s, ['verify', $m[1]], ['PATH' => $s['path']])['output'])->toContain('DEPLOY RUNNER OK')
        ->and(draRequests($s))->toBe('');
});

// ─── 6. The owner's helper, the root wrapper, the operator interface ───────

/**
 * Run the owner's signing helper against the sandbox's maintainer clone.
 * `tty`: inside a fresh pseudo-terminal (script(1)) that becomes its
 * controlling terminal, the answer typed on it — the owner at a terminal.
 * Otherwise in a new session with NO controlling terminal (setsid), the answer
 * piped on stdin — a script trying to approve.
 */
function draSign(array $s, array $args, string $answer = "YES\n", ?string $home = null, bool $tty = true): array
{
    $home ??= tempArtifactDir('dra-home-');
    $command = array_merge(['bash', base_path('scripts/release-approval-sign.sh')], $args);
    $command = $tty
        ? ['script', '-qec', implode(' ', array_map('escapeshellarg', $command)), '/dev/null']
        : ['setsid', '-w', ...$command];
    $process = new Process($command, null, ['HOME' => $home, 'PATH' => getenv('PATH'), 'SSH_AUTH_SOCK' => '', 'TZ' => 'UTC'], $answer, 60);
    $process->run();

    return ['exit' => (int) $process->getExitCode(), 'output' => str_replace("\r\n", "\n", $process->getOutput().$process->getErrorOutput()), 'home' => $home];
}

function draSignArgs(array $s, array $overrides = []): array
{
    $args = array_merge([
        '--release-id' => $s['release'], '--target-sha' => $s['sha'], '--go-tag' => $s['go_tag'],
        '--hours' => '24', '--key' => $s['owner_key'], '--repo-dir' => $s['maint'],
    ], $overrides);
    $flat = [];
    foreach ($args as $flag => $value) {
        if ($value !== null) {
            $flat[] = $flag;
            $flat[] = $value;
        }
    }

    return $flat;
}

it('signs a bundle on the owner workstation that the host accepts and deploys', function () {
    draRequiresTooling();
    $s = draSandbox();

    $r = draSign($s, draSignArgs($s));

    expect($r['exit'])->toBe(0, $r['output'])
        ->and(preg_match('/^RELEASE APPROVAL BUNDLE: (\S+)$/m', $r['output'], $m))->toBe(1);
    $bundle = $m[1];
    $dir = "{$r['home']}/DaengtisiaMS-release-approvals/{$s['release']}";
    preg_match('/^approval_id=([0-9a-f]{32})$/m', $r['output'], $id);
    expect($bundle)->toBe("{$dir}/{$id[1]}.bundle")
        ->and(substr(sprintf('%o', fileperms($bundle)), -3))->toBe('600')
        ->and(substr(sprintf('%o', fileperms($dir)), -3))->toBe('700')
        ->and(substr(sprintf('%o', fileperms(dirname($dir))), -3))->toBe('700')
        ->and($r['output'])->toContain('bundle_sha256='.hash_file('sha256', $bundle))
        ->and($r['output'])->toContain("ssh dms-fixture-deploy submit-approval --release-id {$s['release']} < {$bundle}")
        ->and($r['output'])->toContain('Window (WITA):')
        ->and($r['output'])->toContain('WITA')
        ->and($r['output'])->toContain("DMS_APPROVAL_TARGET_SHA={$s['sha']}")
        ->and($r['output'])->not->toContain('PRIVATE KEY')
        ->and($r['output'])->not->toContain((string) file_get_contents($s['owner_key']));
    $text = (string) file_get_contents($bundle);
    preg_match('/^DMS_APPROVAL_APPROVED_AT=(.+)$/m', $text, $at);
    preg_match('/^DMS_APPROVAL_NOT_AFTER=(.+)$/m', $text, $until);
    expect(strtotime($until[1]) - strtotime($at[1]))->toBe(24 * 3600)
        ->and($r['output'])->toContain('record_sha256='.hash('sha256', substr($text, 0, (int) strpos($text, '-----BEGIN SSH SIGNATURE-----'))));

    draSubmitted(draSubmit($s, $text), $id[1]);
    draGo(draVerify($s), '3');
});

it('refuses to sign anything it cannot bind, and writes nothing', function (array $overrides, string $answer, string $reason, bool $tty = false) {
    draRequiresTooling();
    $s = draSandbox();
    if (($overrides['--target-sha'] ?? null) === 'unpushed') {
        r229Write($s['maint'], ['unpushed.txt' => "local only\n"]);
        r229Git($s['maint'], ['add', 'unpushed.txt']);
        r229Git($s['maint'], ['commit', '-q', '-m', 'not on origin']);
        $overrides['--target-sha'] = r229Git($s['maint'], ['rev-parse', 'HEAD']);
    }

    $r = draSign($s, draSignArgs($s, $overrides), $answer, null, $tty);

    expect($r['exit'])->not->toBe(0)
        ->and($r['output'])->toContain($reason)
        ->and(file_exists("{$r['home']}/DaengtisiaMS-release-approvals"))->toBeFalse();
})->with([
    'a window over 72 hours' => [['--hours' => '73'], "YES\n", 'must be a whole number of hours from 1 to 72'],
    'a zero window' => [['--hours' => '0'], "YES\n", 'must be a whole number of hours from 1 to 72'],
    'a zero window written 00' => [['--hours' => '00'], "YES\n", 'must be a whole number of hours from 1 to 72'],
    'a window that is not a number' => [['--hours' => 'abc'], "YES\n", 'must be a whole number of hours from 1 to 72'],
    'a three-digit window' => [['--hours' => '008'], "YES\n", 'must be a whole number of hours from 1 to 72'],
    'an abbreviated commit' => [['--target-sha' => '0123456789ab'], "YES\n", 'is not a full 40-character lowercase commit SHA'],
    'an unknown commit' => [['--target-sha' => str_repeat('e', 40)], "YES\n", 'is not a commit in'],
    'a commit not on origin' => [['--target-sha' => 'unpushed'], "YES\n", 'is not on origin/feature/r229-fixture-base'],
    'another GO tag' => [['--go-tag' => 'dms-another-go'], "YES\n", "the commit's .sprint/current.yml declares 'dms-fixture-release-go'"],
    'a path-traversal release id' => [['--release-id' => '../x'], "YES\n", 'is not a release identifier'],
    'a missing key' => [['--key' => '/nonexistent/key'], "YES\n", 'does not exist'],
    'the owner does not confirm' => [[], "no\n", "not confirmed (typed 'no')", true],
    'no answer at all' => [[], '', 'not confirmed', true],
    'a piped YES with no terminal (a script, not the owner)' => [[], "YES\n", 'must be confirmed by the owner at a terminal', false],
]);

it('reads --hours as decimal (08 is eight hours, never an octal error)', function () {
    draRequiresTooling();
    $s = draSandbox();

    $r = draSign($s, draSignArgs($s, ['--hours' => '08']));

    expect($r['exit'])->toBe(0, $r['output'])->and(preg_match('/^RELEASE APPROVAL BUNDLE: (\S+)$/m', $r['output'], $m))->toBe(1);
    $text = (string) file_get_contents($m[1]);
    preg_match('/^DMS_APPROVAL_APPROVED_AT=(.+)$/m', $text, $at);
    preg_match('/^DMS_APPROVAL_NOT_AFTER=(.+)$/m', $text, $until);
    expect(strtotime($until[1]) - strtotime($at[1]))->toBe(8 * 3600)
        ->and($r['output'])->toContain('(8 h)');
});

it('signs without any ssh-agent and reads the confirmation from the terminal only', function () {
    $code = draCode('scripts/release-approval-sign.sh');

    expect($code)->toContain('env -u SSH_AUTH_SOCK SSH_ASKPASS_REQUIRE=never ssh-keygen -Y sign -f "$KEY"')
        ->and($code)->toContain('{ exec 3<>/dev/tty; } 2>/dev/null')
        ->and($code)->toContain('read -r answer <&3')
        ->and($code)->not->toMatch('/read -r answer\s*(\|\||$)/m');
});

it('runs the root submit wrapper only from a trusted tree, as root, with exactly --release-id', function () {
    draRequiresTooling();
    $s = draSandbox();
    copy(base_path('scripts/release-approval-submit.sh'), "{$s['app']}/scripts/release-approval-submit.sh");
    $wrapper = "{$s['app']}/scripts/release-approval-submit.sh";
    $main = fn (array $args, string $input = '', array $env = []) => draBash($s,
        'source "$1"; shift; rc=0; dms_approval_submit_main "$@" || rc=$?; exit "$rc"', [$wrapper, r229Uid(), ...$args], $input, $env);

    // Executed by anyone but root: refused before anything is read.
    $asUser = draBash($s, 'bash "$1" --release-id "$2"', [$wrapper, $s['release']]);
    expect($asUser['exit'])->toBe(2)->and($asUser['output'])->toContain('run as root');

    foreach ([[], ['--release-id'], ['--release-id', '../../etc'], ['--release-id', $s['release'], '--approved-sha', $s['sha']], ['--approved-sha', $s['sha']]] as $args) {
        expect($main($args)['exit'])->toBe(64, implode(' ', $args));
    }

    // The trusted tree: the submitter's name is taken from sudo only when plain.
    $b = draBundle($s);
    $ok = $main(['--release-id', $s['release']], $b['bundle'], ['SUDO_USER' => 'deploy-op']);
    draSubmitted($ok, $b['id']);
    expect((string) file_get_contents(draStoreDir($s)."/{$b['id']}.receipt"))->toContain("submitted_by=deploy-op\n");

    // A library another identity could change is never read.
    chmod("{$s['app']}/scripts/lib/release-approval.sh", 0o664);
    $untrusted = $main(['--release-id', 'dms-another-release'], draBundle($s, ['DMS_APPROVAL_RELEASE_ID' => 'dms-another-release'])['bundle']);
    expect($untrusted['exit'])->toBe(2)->and($untrusted['output'])->toContain('refusing to read')
        ->and(is_dir(draStoreDir($s, 'dms-another-release')))->toBeFalse();
});

it('records an unknown submitter rather than an unsafe name', function () {
    draRequiresTooling();
    $s = draSandbox();
    $b = draBundle($s);

    draSubmitted(draSubmit($s, $b['bundle'], ['by' => 'Bad;name']), $b['id']);

    expect((string) file_get_contents(draStoreDir($s)."/{$b['id']}.receipt"))->toContain("submitted_by=unknown\n");
});

it('reaches submit-approval only through the entrypoint allowlist and the rendered sudo expression', function () {
    $sha = str_repeat('a', 40);
    $src = '. '.escapeshellarg(base_path('scripts/deploy-operator-entrypoint.sh')).';';
    $split = fn (string $request) => draShell($src.' dms_operator_split '.escapeshellarg($request));

    [$exit, $out] = $split('submit-approval --release-id dms-release-approval-direct-1');
    expect($exit)->toBe(0)->and(trim(str_replace("\n", ' ', $out)))->toBe('submit-approval --release-id dms-release-approval-direct-1');
    foreach (['submit-approval', 'submit-approval --release-id', 'submit-approval --release-id ../x', 'submit-approval --release-id ab',
        'submit-approval --release-id dms-x --approved-sha '.$sha, 'submit-approval --release-id dms-x extra', 'submit-approval --bundle /tmp/x',
        'submit-approval --release-id dms-x < /tmp/x'] as $request) {
        expect($split($request)[0])->not->toBe(0, $request);
    }
    expect(trim(draShell($src.' dms_operator_program submit-approval')[1]))->toBe('scripts/release-approval-submit.sh');

    [, $expressions] = draShell('. '.escapeshellarg(base_path('scripts/lib/sudo-policy.sh')).'; dms_sudo_operator_expressions');
    expect($expressions)->toContain('^submit-approval --release-id [a-z0-9][a-z0-9-][a-z0-9-]+$');
    expect(preg_match('/^submit-approval --release-id [a-z0-9][a-z0-9-][a-z0-9-]+$/D', 'submit-approval --release-id dms-x'))->toBe(1)
        ->and(preg_match('/^submit-approval --release-id [a-z0-9][a-z0-9-][a-z0-9-]+$/D', 'submit-approval --release-id ../x'))->toBe(0);

    // The dispatcher hands the submission only `--release-id <id>`, its stdin
    // and the sudo-authenticated account name; nothing else reads SUDO_USER.
    $code = draCode('scripts/deploy-operator-entrypoint.sh');
    expect($code)->toContain('submit-approval) shift; submission=("SUDO_USER=${submitter}") ;;')
        ->and($code)->toContain('[[ "$submitter" =~ ^[a-z][a-z0-9-]{0,31}$ ]] || submitter="unknown"')
        ->and(draCode('scripts/release-approval-submit.sh'))->not->toMatch('/\b(deploy-vps|runner|rollback)\b/');
});

it('declares the contract-3 facts in the production authority and keeps the suite mandatory', function () {
    $authority = (string) file_get_contents(base_path('deploy/production-host.conf'));

    expect($authority)->toContain("DMS_PRODUCTION_RELEASE_APPROVAL_CONTRACTS=\"2 3\"\n")
        ->and($authority)->toContain("DMS_PRODUCTION_RELEASE_APPROVAL_STORE=/var/lib/daengtisiams-deploy/approvals\n")
        ->and($authority)->toContain("DMS_PRODUCTION_CI_WORKFLOW=.github/workflows/foundation-evidence-gates.yml\n")
        ->and($authority)->toContain('DMS_PRODUCTION_CI_REQUIRED_JOBS="'.DRA_JOBS."\"\n")
        ->and(config('ci_runner.critical_gate_mandatory_suites'))->toContain('tests/Feature/Deploy/DeployHardenDirectApprovalTest.php');

    // Every required job name is a job of the pinned workflow.
    $workflow = (string) file_get_contents(base_path(DRA_WORKFLOW));
    foreach (explode(';', DRA_JOBS) as $name) {
        expect($workflow)->toContain("    name: {$name}\n");
    }
    // The workflow's pull_request trigger targets the deploy branch the authority names; since phase B5
    // there is no push trigger, so the base branch's runs are the manual dispatches the guard checks.
    $deployBranch = preg_quote(trim((string) preg_replace('/^.*DMS_PRODUCTION_DEPLOY_BRANCH=(\S+).*$/s', '$1', $authority)), '/');
    expect($workflow)->toMatch('/^  pull_request:\n    branches:\n      - '.$deployBranch.'$/m')
        ->and($workflow)->not->toMatch('/^  push:$/m');
});

it('requires exactly the CI jobs that run on every push of the base branch', function () {
    // The Selective Module Gate is skipped on push runs that touch no module
    // code (classifier), so requiring it would deny such releases forever.
    $authority = (string) file_get_contents(base_path('deploy/production-host.conf'));

    expect($authority)->toMatch('/^DMS_PRODUCTION_CI_REQUIRED_JOBS="NSF-R011 Critical Test Gate;NSF-R012 Quality Gate;CICD-CTRL Gate Classifier"$/m')
        ->and($authority)->toMatch('/^DMS_PRODUCTION_CI_WORKFLOW=\.github\/workflows\/foundation-evidence-gates\.yml$/m')
        ->and(DRA_JOBS)->toBe('NSF-R011 Critical Test Gate;NSF-R012 Quality Gate;CICD-CTRL Gate Classifier');
});

it('offers a read-only release-guard check that never claims, records or launches', function () {
    $runner = draCode('scripts/deploy-vps-runner.sh');
    preg_match('/^  check-approval\)\n(.*?)\n    ;;$/ms', $runner, $m);
    $body = $m[1] ?? '';

    expect($body)->toContain('parse_release_args "$@"')
        ->and($body)->toContain('approve_or_deny')
        ->and($body)->toContain('trap check_approval_cleanup EXIT')
        ->and(strpos($body, 'check_approval_lock'))->toBeLessThan(strpos($body, 'approve_or_deny'))
        ->and(strpos($body, 'DMS_RELEASE_APPROVAL_CI_RESERVE=4'))->toBeLessThan(strpos($body, 'approve_or_deny'))
        ->and($body)->toContain('RELEASE APPROVAL CHECK: GO')
        ->and($body)->not->toContain('start_detached')
        ->and($body)->not->toContain('claim_run_id')
        ->and($body)->not->toContain('trusted_run_dir_or_deny')
        ->and($body)->not->toContain('record_spent');
    // Reachable by the operator with exactly start's argument shape.
    expect(draCode('scripts/lib/sudo-policy.sh'))
        ->toContain("'^check-approval --release-id [a-z0-9][a-z0-9-][a-z0-9-]+ --approved-sha [0-9a-f]{40}\$'");
});

// ─── 7. check-approval: read-only, serialized, within a deploy's API budget ─

it('proves a stored approval without changing a ref, FETCH_HEAD, the evidence, the spent records or the store', function () {
    draRequiresTooling();
    $s = draSandbox();
    // A completed contract-3 deploy first, so evidence, spent records and the store all hold something.
    draSubmitted(draSubmit($s, ($b = draBundle($s))['bundle']), $b['id']);
    $start = r229Runner($s, ['start', '--release-id', $s['release'], '--approved-sha', $s['sha']], ['PATH' => $s['path']]);
    expect(preg_match('/deploy_id=(\d{8}-\d{6})/', $start['output'], $m))->toBe(1, $start['output']);
    expect(r229WaitForStatus($s, $m[1]))->toBe('exit=0');
    $next = 'dms-fixture-release-two';
    draSubmitted(draSubmit($s, ($c = draBundle($s, ['DMS_APPROVAL_RELEASE_ID' => $next]))['bundle'], ['release' => $next]), $c['id']);
    // A remote-tracking ref behind origin (an opportunistic fetch update would move
    // it), and every ref packed (a rewrite of packed-refs would show).
    r229Git($s['app'], ['update-ref', "refs/remotes/origin/{$s['branch']}", $s['base']]);
    r229Git($s['app'], ['pack-refs', '--all']);
    $before = draHostState($s);
    expect($before['spent'])->not->toBe(['<absent>'])->and($before['FETCH_HEAD'])->toBe('<absent>');

    $check = draCheck($s, $next);

    expect($check['exit'])->toBe(0, $check['output'])
        ->and($check['output'])->toContain("PASS: contract 3 approval {$c['id']}")
        ->and($check['output'])->toContain('reserve 4;')
        ->and($check['output'])->toContain('RELEASE APPROVAL CHECK: GO')
        ->and(draHostState($s))->toBe($before)
        ->and(r229Git($s['app'], ['for-each-ref', 'refs/dms-release-approval/']))->toBe('')
        ->and(r229Git($s['app'], ['rev-parse', "refs/remotes/origin/{$s['branch']}"]))->toBe($s['base']);
});

it('leaves a deploy its GitHub API budget: check-approval needs 2 requests plus a reserve of 4, start and the bootstrap 2', function () {
    draRequiresTooling();
    $s = draSandbox();
    draSubmitted(draSubmit($s, ($b = draBundle($s))['bundle']), $b['id']);
    $reset = gmdate('Y-m-d\TH:i:s\Z', DRA_RATE_RESET);

    draRateLimit($s, 5);
    $check = draCheck($s);
    draDenied($check, "required CI: the GitHub API rate limit of this host has 5 of 60 requests left; this check needs 2 and keeps 4 in reserve for a deploy — the limit resets at {$reset} (UTC); try again after that");
    expect($check['output'])->not->toContain('RELEASE APPROVAL CHECK: GO')
        ->and(draRequests($s))->toContain('/rate_limit')
        ->and(draRequests($s))->not->toContain('/actions/runs');

    // The deploy guard (runner `start`, bootstrap) keeps no reserve.
    draGo(draVerify($s), '3');
    draRateLimit($s, 2);
    draGo(draVerify($s), '3');
    draRateLimit($s, 1);
    draDenied(draVerify($s), 'has 1 of 60 requests left; this check needs 2 and keeps 0 in reserve');
    draRateLimit($s, 6);
    expect(draCheck($s)['output'])->toContain('RELEASE APPROVAL CHECK: GO');
});

it('denies when the GitHub rate limit cannot be read, before any CI request', function (callable $arrange, string $reason) {
    draRequiresTooling();
    $s = draSandbox();
    $arrange($s);

    $r = draCiVerify($s);

    expect($r['exit'])->toBe(77, $r['output'])
        ->and($r['output'])->toContain('DEPLOYMENT=DENIED')
        ->and($r['output'])->toContain("required CI: {$reason}")
        ->and($r['output'])->not->toContain('PASS: required CI')
        ->and(draRequests($s))->toContain('/rate_limit')
        ->and(draRequests($s))->not->toContain('/actions/runs');
})->with([
    'unreachable' => [fn (array $s) => draRateLimit($s, 60, null, 6), 'the GitHub API rate limit could not be read (the GitHub API could not be reached (curl exit 6'],
    'HTTP 500' => [fn (array $s) => draRateLimit($s, 60, 500), 'the GitHub API rate limit could not be read (the GitHub API answered HTTP 500)'],
    'HTTP 403' => [fn (array $s) => draRateLimit($s, 60, 403), 'the GitHub API rate limit could not be read (the GitHub API answered HTTP 403 — this may be its rate limit'],
    'not JSON' => [fn (array $s) => draRateLimit($s, '<html>busy</html>'), 'the GitHub response is not valid JSON'],
    'no core limit' => [fn (array $s) => draRateLimit($s, '{"rate": {"limit": 60, "remaining": 60, "reset": 1}}'), 'the GitHub rate-limit response names no core limit'],
    'remaining as a string' => [fn (array $s) => draRateLimit($s, '{"resources": {"core": {"limit": 60, "remaining": "60", "reset": 1}}}'), 'the GitHub rate-limit response has no usable remaining/limit/reset'],
    'a negative remaining' => [fn (array $s) => draRateLimit($s, '{"resources": {"core": {"limit": 60, "remaining": -1, "reset": 1}}}'), 'the GitHub rate-limit response has no usable remaining/limit/reset'],
    'no reset' => [fn (array $s) => draRateLimit($s, '{"resources": {"core": {"limit": 60, "remaining": 60}}}'), 'the GitHub rate-limit response has no usable remaining/limit/reset'],
]);

it('refuses a reserve that is not a small count', function () {
    draRequiresTooling();
    $s = draSandbox();

    $r = draCiVerify($s, ['reserve' => '-4']);

    expect($r['exit'])->toBe(77, $r['output'])
        ->and($r['output'])->toContain("the rate-limit reserve '-4' is not a small count")
        ->and(draRequests($s))->toBe('');
});

it('answers BUSY while a deploy or rollback holds the release lock, or another check runs, and checks nothing', function (string $lock, string $reason) {
    draRequiresTooling();
    $s = draSandbox();
    draSubmitted(draSubmit($s, ($b = draBundle($s))['bundle']), $b['id']);
    $holder = draHoldLock("{$s['lock']}/{$lock}");

    try {
        $busy = draCheck($s);
    } finally {
        $holder->stop(0);
    }

    expect($busy['exit'])->toBe(75, $busy['output'])
        ->and($busy['output'])->toContain("BUSY {$reason}")
        ->and($busy['output'])->toContain('RELEASE APPROVAL CHECK: BUSY')
        ->and($busy['output'])->not->toContain('Release approval guard')
        ->and(draRequests($s))->toBe('')
        ->and(draCheck($s)['output'])->toContain('RELEASE APPROVAL CHECK: GO');
})->with([
    'a deploy or rollback (exclusive release lock)' => ['release.lock', 'a deploy or rollback holds the release lock'],
    'another check-approval' => ['check-approval.lock', 'another check-approval is running'],
]);

it('shares the release lock with other readers but excludes a deploy and a second check while it runs, and cleans up after a signal', function () {
    draRequiresTooling();
    $s = draSandbox();
    draSubmitted(draSubmit($s, ($b = draBundle($s))['bundle']), $b['id']);
    // Another shared holder of the release lock does not block the check.
    $reader = draHoldLock("{$s['lock']}/release.lock", '-s');
    file_put_contents("{$s['ci']}/rate_limit.sleep", '3');
    $check = new Process(['bash', "{$s['app']}/scripts/deploy-vps-runner.sh", 'check-approval', '--release-id', $s['release'], '--approved-sha', $s['sha']],
        $s['app'], ['APP_DIR' => $s['app'], 'PATH' => $s['path'], 'DMS_DEPLOY_LOCK_DIR' => $s['lock']], null, 60);
    $check->start();

    try {
        $deadline = microtime(true) + 30;
        while (microtime(true) < $deadline && ! str_contains(draRequests($s), '/rate_limit') && $check->isRunning()) {
            usleep(50_000);
        }
        // Mid-check (inside the guard's CI step): its private ref exists, a
        // deploy's exclusive release lock and a second check are refused.
        $midRefs = r229Git($s['app'], ['for-each-ref', '--format=%(refname)', 'refs/dms-release-approval/']);
        $deployLock = r229Run(['flock', '-n', '-x', "{$s['lock']}/release.lock", 'true'])['exit'];
        $secondCheck = r229Run(['flock', '-n', '-x', "{$s['lock']}/check-approval.lock", 'true'])['exit'];
        $check->signal(15);
        $check->wait();
    } finally {
        $reader->stop(0);
        $check->stop(0);
    }

    expect($midRefs)->toMatch('#^refs/dms-release-approval/\d+-\d+$#', $check->getOutput().$check->getErrorOutput())
        ->and($deployLock)->toBe(1)
        ->and($secondCheck)->toBe(1)
        ->and($check->getExitCode())->toBe(143)
        ->and($check->getOutput())->not->toContain('RELEASE APPROVAL CHECK: GO')
        ->and(r229Git($s['app'], ['for-each-ref', 'refs/dms-release-approval/']))->toBe('')
        ->and(r229Run(['flock', '-n', '-x', "{$s['lock']}/release.lock", 'true'])['exit'])->toBe(0)
        ->and(r229Run(['flock', '-n', '-x', "{$s['lock']}/check-approval.lock", 'true'])['exit'])->toBe(0);
});
