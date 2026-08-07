<?php

use App\Services\Foundation\CiRuntimeControlGovernanceService;
use App\Services\Foundation\SelfHostedRunnerGovernanceService;
use App\Support\Cicd\SelfHostedRunnerScanner;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Yaml\Yaml;

uses()->group('Cicd', 'Ci', 'SelfHostedRunner', 'FoundationGovernance');

/**
 * Runs the real guard command in a subprocess with a CI-shaped environment and
 * returns its exit code.
 *
 * A subprocess is deliberate: it exercises the exact command and exit code CI
 * relies on, and it avoids mutating the connection the test transaction runs on.
 */
function runDatabaseGuard(string $appEnv, string $host, string $database): int
{
    return Process::path(base_path())
        ->env([
            'APP_ENV' => $appEnv,
            'DB_CONNECTION' => 'pgsql',
            'DB_HOST' => $host,
            'DB_DATABASE' => $database,
        ])
        ->run(['php', 'artisan', 'ci:assert-non-production-database'])
        ->exitCode();
}

/**
 * @return array<string, mixed>
 */
function ciWorkflow(): array
{
    return Yaml::parseFile(base_path('.github/workflows/foundation-evidence-gates.yml'));
}

function ciWorkflowRaw(): string
{
    return (string) file_get_contents(base_path('.github/workflows/foundation-evidence-gates.yml'));
}

/**
 * Evaluates a GitHub Actions `if:` expression for the small subset this
 * workflow uses, so the routing invariant can be proven exhaustively rather
 * than asserted by eye.
 */
function evaluateRoutingCondition(string $expression, string $mode, string $event, bool $fork): bool
{
    $repository = 'owner/repo';
    $head = $fork ? 'someone/fork' : $repository;

    $expr = preg_replace('/\s+/', ' ', trim($expression)) ?? '';
    $expr = str_replace(['${{', '}}'], '', $expr);

    $expr = str_replace('needs.classify.outputs.runner_mode', "'{$mode}'", $expr);
    $expr = str_replace('github.event_name', "'{$event}'", $expr);
    $expr = str_replace('github.event.pull_request.head.repo.full_name', "'{$head}'", $expr);
    $expr = str_replace('github.repository', "'{$repository}'", $expr);

    // Tokenise into a tiny boolean grammar: '(' ')' '&&' '||' and 'a == b'.
    $tokens = preg_split('/\s*(\(|\)|&&|\|\|)\s*/', $expr, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [];

    $php = '';
    foreach ($tokens as $token) {
        $token = trim($token);
        if ($token === '') {
            continue;
        }
        if (in_array($token, ['(', ')', '&&', '||'], true)) {
            $php .= " {$token} ";

            continue;
        }
        if (preg_match("/^'([^']*)'\s*(==|!=)\s*'([^']*)'$/", $token, $m) === 1) {
            $result = $m[2] === '==' ? ($m[1] === $m[3]) : ($m[1] !== $m[3]);
            $php .= $result ? 'true' : 'false';

            continue;
        }
        throw new RuntimeException("Unsupported token in routing condition: {$token}");
    }

    return (bool) eval("return {$php};");
}

it('keeps the CI workflow parseable and both critical gate variants present', function () {
    $jobs = ciWorkflow()['jobs'];

    expect($jobs)->toHaveKey('critical_test_gate')
        ->and($jobs)->toHaveKey('critical_test_gate_self_hosted');
});

it('preserves the required check name across both runner variants', function () {
    $jobs = ciWorkflow()['jobs'];

    // CICDCTRL3-R007: routing must never make a required check disappear.
    expect($jobs['critical_test_gate']['name'])->toBe('NSF-R011 Critical Test Gate')
        ->and($jobs['critical_test_gate_self_hosted']['name'])->toBe('NSF-R011 Critical Test Gate');
});

it('runs exactly one critical gate variant for every routing combination', function () {
    $jobs = ciWorkflow()['jobs'];
    $hosted = (string) $jobs['critical_test_gate']['if'];
    $selfHosted = (string) $jobs['critical_test_gate_self_hosted']['if'];

    $combinations = [];
    foreach (['github-hosted', 'self-hosted', ''] as $mode) {
        foreach (['pull_request', 'push', 'schedule', 'workflow_dispatch'] as $event) {
            foreach ([true, false] as $fork) {
                if ($fork && $event !== 'pull_request') {
                    continue;
                }
                $combinations[] = [$mode, $event, $fork];
            }
        }
    }

    expect($combinations)->not->toBeEmpty();

    foreach ($combinations as [$mode, $event, $fork]) {
        $a = evaluateRoutingCondition($hosted, $mode, $event, $fork);
        $b = evaluateRoutingCondition($selfHosted, $mode, $event, $fork);

        $label = sprintf('mode=%s event=%s fork=%s', $mode === '' ? '(unset)' : $mode, $event, $fork ? 'yes' : 'no');

        // CICDCTRL3-R008: never zero (silent pass) and never two (duplicate).
        expect((int) $a + (int) $b)->toBe(1, "exactly one critical gate variant must run for {$label}");
    }
});

it('routes an unset runner mode to GitHub-hosted as the fail-safe default', function () {
    $jobs = ciWorkflow()['jobs'];

    expect(evaluateRoutingCondition((string) $jobs['critical_test_gate']['if'], '', 'push', false))->toBeTrue()
        ->and(evaluateRoutingCondition((string) $jobs['critical_test_gate_self_hosted']['if'], '', 'push', false))->toBeFalse()
        ->and(config('ci_runner.runner_mode.default'))->toBe('github-hosted');
});

it('never runs a fork pull request on the dedicated runner', function () {
    $jobs = ciWorkflow()['jobs'];
    $selfHosted = (string) $jobs['critical_test_gate_self_hosted']['if'];

    // CICDCTRL3-R010: untrusted code is redirected to GitHub-hosted, not skipped.
    expect(evaluateRoutingCondition($selfHosted, 'self-hosted', 'pull_request', true))->toBeFalse()
        ->and(evaluateRoutingCondition((string) $jobs['critical_test_gate']['if'], 'self-hosted', 'pull_request', true))->toBeTrue();
});

it('targets the full project label set and never a bare self-hosted label', function () {
    $jobs = ciWorkflow()['jobs'];

    // CICDCTRL3-R006.
    expect($jobs['critical_test_gate_self_hosted']['runs-on'])
        ->toBe(['self-hosted', 'linux', 'x64', 'daengtisia-ci'])
        ->and(ciWorkflowRaw())->not->toContain('runs-on: self-hosted');
});

it('keeps the classifier on GitHub-hosted infrastructure', function () {
    $jobs = ciWorkflow()['jobs'];

    // A dead self-hosted runner must never stop the routing decision.
    expect($jobs['classify']['runs-on'])->toBe('ubuntu-latest')
        ->and(config('ci_runner.always_github_hosted_jobs'))->toContain('classify');
});

it('runs an equivalent gate on both runners with an identical test selection', function () {
    $jobs = ciWorkflow()['jobs'];

    // Compare the actual test SELECTION, not the literal command: the
    // self-hosted variant is prefixed with the pinned-runtime wrapper, which is
    // an infrastructure difference, not a coverage difference.
    $filterOf = function (array $job): string {
        foreach ($job['steps'] as $step) {
            if (($step['name'] ?? '') === 'Run critical regression tests') {
                preg_match("/--filter='([^']+)'/", (string) $step['run'], $m);

                return $m[1] ?? '';
            }
        }

        return '';
    };

    $hosted = $filterOf($jobs['critical_test_gate']);
    $selfHosted = $filterOf($jobs['critical_test_gate_self_hosted']);

    // CICDCTRL3-R009: the fallback is equivalent, never weaker.
    expect($hosted)->not->toBe('')
        ->and($selfHosted)->toBe($hosted)
        ->and($hosted)->toContain('LegacyRme');
});

it('asserts a non-production database before every migration in every job', function () {
    $jobs = ciWorkflow()['jobs'];

    $migrating = 0;
    foreach ($jobs as $name => $job) {
        $steps = array_map(fn (array $s) => (string) ($s['name'] ?? ''), $job['steps'] ?? []);
        $migrateIndex = array_search('Run migrations', $steps, true);
        if ($migrateIndex === false) {
            continue;
        }
        $migrating++;

        $guardIndex = null;
        foreach ($steps as $i => $step) {
            if (str_contains($step, 'Assert non-production database')) {
                $guardIndex = $i;
                break;
            }
        }

        // CICDCTRL3-R005.
        expect($guardIndex)->not->toBeNull("job {$name} must guard the database before migrating");
        expect($guardIndex)->toBeLessThan($migrateIndex, "job {$name} must guard BEFORE migrating");
    }

    expect($migrating)->toBeGreaterThanOrEqual(5);
});

it('never lets a skipped critical-gate variant silently skip a downstream gate', function () {
    $jobs = ciWorkflow()['jobs'];

    /*
     * Exactly one critical-gate variant runs; the other is SKIPPED. GitHub
     * skips any job whose `needs` were skipped, so a downstream job that
     * depends on only one variant would silently vanish whenever heavy CI is
     * routed the other way — dropping NSF-9 / NSF-10 without any failure.
     * Every dependent must therefore depend on BOTH variants and tolerate a
     * skipped sibling while still requiring one to have succeeded.
     */
    $dependents = [];
    foreach ($jobs as $name => $job) {
        $needs = (array) ($job['needs'] ?? []);
        if (in_array('critical_test_gate', $needs, true) || in_array('critical_test_gate_self_hosted', $needs, true)) {
            $dependents[$name] = $job;
        }
    }

    expect($dependents)->not->toBeEmpty();

    foreach ($dependents as $name => $job) {
        $needs = (array) ($job['needs'] ?? []);
        $condition = ' '.preg_replace('/\s+/', ' ', (string) ($job['if'] ?? '')).' ';

        // NOTE: Pest's toContain() is variadic — extra arguments are additional
        // needles, not a failure message. Assert on booleans so the message
        // actually reaches the reader.
        expect(in_array('critical_test_gate', $needs, true))
            ->toBeTrue("job {$name} must depend on the GitHub-hosted variant");
        expect(in_array('critical_test_gate_self_hosted', $needs, true))
            ->toBeTrue("job {$name} must depend on the self-hosted variant");

        expect(str_contains($condition, "needs.critical_test_gate.result == 'success'"))
            ->toBeTrue("job {$name} must tolerate a skipped GitHub-hosted variant");
        expect(str_contains($condition, "needs.critical_test_gate_self_hosted.result == 'success'"))
            ->toBeTrue("job {$name} must tolerate a skipped self-hosted variant");
    }
});

it('keeps the always-on gates reachable under both runner modes', function () {
    $jobs = ciWorkflow()['jobs'];

    // CICDCTRL-R003 (inherited): these must never be silently skipped.
    foreach (['release_safety_gate', 'nsf10_release_evidence_gate'] as $alwaysOn) {
        expect($jobs)->toHaveKey($alwaysOn);
        $condition = (string) ($jobs[$alwaysOn]['if'] ?? '');
        expect(str_contains($condition, '!cancelled()'))
            ->toBeTrue("{$alwaysOn} must not be skipped by a skipped sibling");
    }
});

it('never invokes a production deploy or rollback command from CI', function () {
    $raw = ciWorkflowRaw();

    // CICDCTRL3-R002.
    foreach (config('ci_runner.forbidden_ci_production_commands') as $command) {
        expect($raw)->not->toContain($command);
    }
});

it('keeps deployment off the general CI runner', function () {
    $deploy = (string) file_get_contents(base_path('.github/workflows/deploy-vps.yml'));

    expect($deploy)->not->toContain('daengtisia-ci')
        ->and($deploy)->not->toContain('self-hosted');
});

it('cleans the persistent workspace after a self-hosted run', function () {
    $jobs = ciWorkflow()['jobs'];
    $steps = $jobs['critical_test_gate_self_hosted']['steps'];

    $names = array_map(fn (array $s) => (string) ($s['name'] ?? ''), $steps);

    // CICDCTRL3-R011: persistent runners must not leak state between jobs.
    expect($names)->toContain('Clean workspace artifacts');

    $checkout = collect($steps)->firstWhere('uses', 'actions/checkout@v4');
    expect($checkout['with']['clean'] ?? false)->toBeTrue();
});

it('uses a local CI database and no Docker service container on the runner', function () {
    $jobs = ciWorkflow()['jobs'];
    $selfHosted = $jobs['critical_test_gate_self_hosted'];

    // The runner has no Docker and its service user is not in the docker group.
    expect($selfHosted)->not->toHaveKey('services')
        ->and($selfHosted['env']['DB_HOST'])->toBe('127.0.0.1')
        ->and($selfHosted['env']['DB_DATABASE'])->toBe('daengtisia_ci')
        ->and($selfHosted['env']['APP_ENV'])->toBe('testing');
});

it('passes the production database guard for both CI database shapes', function (string $database) {
    expect(runDatabaseGuard('testing', '127.0.0.1', $database))->toBe(0);
})->with(['testing', 'daengtisia_ci']);

it('fails the production database guard for an unsafe configuration', function (string $env, string $host, string $database) {
    // CICDCTRL3-R005: fail closed.
    expect(runDatabaseGuard($env, $host, $database))->toBe(1);
})->with([
    'known production database' => ['testing', '127.0.0.1', 'asia_dental_lab_pilot'],
    'production database without marker' => ['testing', '127.0.0.1', 'asia_dental_lab'],
    'remote host' => ['testing', '10.0.0.5', 'testing'],
    'production app env' => ['production', '127.0.0.1', 'testing'],
    'pilot app env' => ['pilot', '127.0.0.1', 'testing'],
    'production-like name' => ['testing', '127.0.0.1', 'daengtisia_production'],
    'empty database' => ['testing', '127.0.0.1', ''],
]);

it('keeps the database guard rules strict', function () {
    $posture = app(SelfHostedRunnerScanner::class)->databaseGuardPosture();

    expect($posture['ok'])->toBeTrue(implode('; ', $posture['issues']))
        ->and(config('ci_runner.database_guard.allowed_app_envs'))->toBe(['testing'])
        ->and(config('ci_runner.database_guard.denied_databases'))->not->toBeEmpty();
});

it('ships a non-destructive runner health script that checks production isolation', function () {
    $posture = app(SelfHostedRunnerScanner::class)->healthScriptPosture();

    expect($posture['exists'])->toBeTrue()
        ->and($posture['ok'])->toBeTrue(implode('; ', $posture['issues']));

    $script = (string) file_get_contents(base_path('scripts/ci/self-hosted-runner-health.sh'));

    // CICDCTRL3-R004: posture only — the script must never print a credential.
    expect($script)->toContain('PRESENT/ABSENT')
        ->and($script)->toContain('production_isolation');
});

it('reports GO for the self-hosted runner governance check', function () {
    $report = app(SelfHostedRunnerGovernanceService::class)->collect();

    expect($report['decision'])->toBe('GO', json_encode($report['checks']))
        ->and($report['sprint'])->toBe('CICD-CTRL-3')
        ->and($report['summary']['errors'])->toBe(0);
});

it('publishes the thirteen CICD-CTRL-3 governance rules', function () {
    $rules = SelfHostedRunnerGovernanceService::rules();
    $ids = array_column($rules, 'id');

    expect($rules)->toHaveCount(13)
        ->and($ids)->toBe(array_unique($ids))
        ->and($ids)->toContain('CICDCTRL3-R001', 'CICDCTRL3-R008', 'CICDCTRL3-R013');
});

it('pins the authoritative CI runtime by digest rather than a floating tag', function () {
    $posture = app(SelfHostedRunnerScanner::class)->ciRuntimePosture();

    // CICDCTRL3-R013: a floating tag would let the runtime drift silently.
    expect($posture['ok'])->toBeTrue(implode('; ', $posture['issues']))
        ->and($posture['digest_pinned'])->toBeTrue();

    $containerfile = (string) file_get_contents(base_path('.github/ci-runtime/Containerfile.php83'));
    expect($containerfile)->toMatch('/^FROM\s+\S+@sha256:[0-9a-f]{64}/m');
});

it('builds the CI runtime image with the authoritative extension set and Poppler', function () {
    $containerfile = (string) file_get_contents(base_path('.github/ci-runtime/Containerfile.php83'));

    // Mirrors the setup-php extension list of the GitHub-hosted critical gate.
    foreach (config('ci_runner.ci_runtime.required_extensions') as $extension) {
        expect($containerfile)->toContain($extension);
    }

    // LegacyRme's Poppler suite SKIPS without these, which would quietly make
    // the self-hosted gate weaker than the authoritative one.
    foreach (config('ci_runner.ci_runtime.required_binaries') as $binary) {
        expect($containerfile)->toContain($binary);
    }
});

it('runs the self-hosted gate through the pinned runtime, never the host PHP', function () {
    $jobs = ciWorkflow()['jobs'];
    $steps = $jobs['critical_test_gate_self_hosted']['steps'];

    $runs = collect($steps)->pluck('run')->filter()->implode("\n");

    // Every php/composer invocation must go through the wrapper.
    expect($runs)->toContain('scripts/ci/self-hosted-php.sh')
        ->and($jobs['critical_test_gate_self_hosted']['env']['REQUIRED_PHP'])->toBe('8.3');

    $names = array_map(fn (array $s) => (string) ($s['name'] ?? ''), $steps);
    expect($names)->toContain('Assert authoritative PHP runtime (CICD-CTRL-3)');

    // No bare `php ...` / `composer ...` step outside the wrapper.
    foreach ($steps as $step) {
        $run = (string) ($step['run'] ?? '');
        if ($run === '' || str_contains($run, 'self-hosted-php.sh')) {
            continue;
        }
        expect($run)->not->toMatch('/^\s*(php|composer)\s/m');
    }
});

it('forbids docker, sudo and lxd for the runner service user', function () {
    $forbidden = config('ci_runner.ci_runtime.forbidden_service_user_groups');

    // docker and lxd both grant root-equivalent control of the host; sudo is
    // direct escalation.
    expect($forbidden)->toContain('docker')->toContain('sudo')->toContain('lxd');

    $wrapper = (string) file_get_contents(base_path('scripts/ci/self-hosted-php.sh'));
    expect($wrapper)->toContain('--userns=keep-id')
        ->and($wrapper)->toContain('podman run')
        ->and($wrapper)->not->toMatch('/^\s*(exec\s+)?docker\s/m');
});

/*
 * ---------------------------------------------------------------------------
 * CICD-CTRL-3 correction — a missing container runtime must never suppress a
 * security finding.
 *
 * The original health script evaluated the docker-group check inside
 * `if have podman`. On a host without Podman the check silently vanished: the
 * gate reported NO-GO for the missing tool and said nothing whatsoever about
 * the runtime user sitting in sudo/docker/lxd. These tests execute the real
 * script against a synthesised bare host to prove the finding survives.
 * ---------------------------------------------------------------------------
 */

/**
 * Build a PATH containing only the coreutils the health script needs, so that
 * podman, psql and ss are genuinely absent no matter what the test host has
 * installed. This is a real bare-host simulation, not a mocked one.
 */
function bareHostBinDir(): string
{
    $dir = sys_get_temp_dir().'/ci-bare-host-'.bin2hex(random_bytes(6));
    mkdir($dir, 0700, true);

    foreach (['bash', 'id', 'df', 'awk', 'hostname', 'grep', 'tr', 'cut', 'tail', 'basename', 'sed'] as $binary) {
        $resolved = trim((string) shell_exec('command -v '.escapeshellarg($binary).' 2>/dev/null'));
        if ($resolved !== '' && is_file($resolved)) {
            symlink($resolved, $dir.'/'.$binary);
        }
    }

    return $dir;
}

/**
 * Run the real health script with the given environment and decode its JSON.
 *
 * @param  array<string, string>  $env
 * @return array<string, mixed>
 */
function runRunnerHealthOnBareHost(array $env = []): array
{
    $bin = bareHostBinDir();
    $bash = trim((string) shell_exec('command -v bash'));

    $result = Process::path(base_path())
        ->env(array_merge(['PATH' => $bin], $env))
        ->run([$bash, 'scripts/ci/self-hosted-runner-health.sh', '--json']);

    $decoded = json_decode($result->output(), true);

    expect($decoded)->toBeArray(
        'health script did not emit valid JSON on a bare host: '.$result->output().$result->errorOutput()
    );

    return $decoded;
}

/**
 * @param  array<string, mixed>  $report
 * @return list<array<string, string>>
 */
function healthChecksNamed(array $report, string $name): array
{
    return array_values(array_filter(
        $report['checks'] ?? [],
        fn (array $check): bool => ($check['check'] ?? null) === $name
    ));
}

it('always evaluates root-equivalent group checks on a host with no podman', function () {
    $report = runRunnerHealthOnBareHost();

    // Precondition: this really is a bare host — no container runtime was found,
    // so the old nesting bug would have emitted no group finding at all.
    expect(healthChecksNamed($report, 'podman_rootless'))->toBeEmpty(
        'expected podman to be absent from the synthesised bare host'
    );

    foreach (['group_docker', 'group_sudo', 'group_lxd'] as $check) {
        $emitted = healthChecksNamed($report, $check);

        // Exactly one result — never zero (suppressed) and never duplicated.
        expect($emitted)->toHaveCount(1, "'{$check}' must emit exactly one result on a bare host")
            ->and($emitted[0]['status'])->toBeIn(['PASS', 'WARN', 'FAIL']);
    }
});

it('reports a forbidden group as FAIL even when no container runtime is installed', function () {
    // The test process cannot join the docker group, so drive the check with a
    // group this user provably IS in. The detection path is identical.
    $primaryGroup = trim((string) shell_exec('id -gn'));
    expect($primaryGroup)->not->toBe('');

    $report = runRunnerHealthOnBareHost(['CI_RUNNER_FORBIDDEN_GROUPS' => $primaryGroup]);

    $emitted = healthChecksNamed($report, "group_{$primaryGroup}");

    expect($emitted)->toHaveCount(1)
        ->and($emitted[0]['status'])->toBe('FAIL')
        ->and($report['decision'])->toBe('NO-GO');
});

it('rejects a health script that hides group checks behind a container-runtime probe', function () {
    // Reconstruct the original defect and prove the scanner now catches it.
    $regressed = <<<'SH'
        #!/usr/bin/env bash
        set -euo pipefail
        record PASS "runtime_user" "ok"
        record PASS "disk_free" "ok"
        record PASS "ram_available" "ok"
        record PASS "production_isolation" "ok"
        record PASS "ci_database" "ok"
        if have podman; then
            record PASS "group_docker" "not in docker"
            record PASS "group_sudo" "not in sudo"
            record PASS "group_lxd" "not in lxd"
        fi
        SH;

    $relative = 'storage/framework/testing/regressed-runner-health.sh';
    $absolute = base_path($relative);
    @mkdir(dirname($absolute), 0755, true);
    file_put_contents($absolute, $regressed);

    try {
        config(['ci_runner.files.runner_health_script' => $relative]);

        $posture = app(SelfHostedRunnerScanner::class)->healthScriptPosture();

        expect($posture['ok'])->toBeFalse();

        $issues = implode("\n", $posture['issues']);
        foreach (['group_docker', 'group_sudo', 'group_lxd'] as $marker) {
            expect($issues)->toContain($marker);
        }
        expect($issues)->toContain('must run unconditionally');
    } finally {
        @unlink($absolute);
    }
});

it('keeps the shipped health script free of the container-runtime nesting defect', function () {
    $posture = app(SelfHostedRunnerScanner::class)->healthScriptPosture();

    expect($posture['exists'])->toBeTrue()
        ->and($posture['ok'])->toBeTrue(implode("\n", $posture['issues']));
});

it('does not regress the CICD-CTRL-1 safe runtime control contract', function () {
    // The runner routing must never weaken the existing gate classifier.
    $report = app(CiRuntimeControlGovernanceService::class)->collect();

    expect($report['decision'])->toBe('GO', json_encode($report['checks']))
        ->and($report['workflow_ok'])->toBeTrue()
        ->and($report['safety_invariant_ok'])->toBeTrue();
});
