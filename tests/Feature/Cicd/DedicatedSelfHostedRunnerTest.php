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

it('runs an equivalent gate on both runners with an identical test filter', function () {
    $jobs = ciWorkflow()['jobs'];

    $filterOf = function (array $job): string {
        foreach ($job['steps'] as $step) {
            if (($step['name'] ?? '') === 'Run critical regression tests') {
                return trim((string) $step['run']);
            }
        }

        return '';
    };

    $hosted = $filterOf($jobs['critical_test_gate']);
    $selfHosted = $filterOf($jobs['critical_test_gate_self_hosted']);

    // CICDCTRL3-R009: the fallback is equivalent, never weaker.
    expect($hosted)->not->toBe('')
        ->and($selfHosted)->toBe($hosted);
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

it('publishes the twelve CICD-CTRL-3 governance rules', function () {
    $rules = SelfHostedRunnerGovernanceService::rules();
    $ids = array_column($rules, 'id');

    expect($rules)->toHaveCount(12)
        ->and($ids)->toBe(array_unique($ids))
        ->and($ids)->toContain('CICDCTRL3-R001', 'CICDCTRL3-R008', 'CICDCTRL3-R012');
});

it('does not regress the CICD-CTRL-1 safe runtime control contract', function () {
    // The runner routing must never weaken the existing gate classifier.
    $report = app(CiRuntimeControlGovernanceService::class)->collect();

    expect($report['decision'])->toBe('GO', json_encode($report['checks']))
        ->and($report['workflow_ok'])->toBeTrue()
        ->and($report['safety_invariant_ok'])->toBeTrue();
});
