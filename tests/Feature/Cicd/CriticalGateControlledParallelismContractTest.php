<?php

declare(strict_types=1);

use App\Support\Cicd\CriticalGateWarningContract;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

/*
|--------------------------------------------------------------------------
| CI-PERF-04 — controlled parallel testing in the NSF-R011 Critical Gate
|--------------------------------------------------------------------------
| The committed policy (.github/ci-policy/critical-parallel-policy.json) is OFF,
| so every pull_request / push / schedule run executes the critical filter
| SEQUENTIALLY with the exact pre-CI-PERF-04 command line. Parallelism is reachable
| only through an explicit evaluation workflow_dispatch, or — after a reviewed
| change to ACTIVE plus owner approval — through the repository variable.
|
| These tests pin:
|   * the resolver fails toward sequential on every uncertainty and ignores the
|     repository variable while OFF (a variable flip can never activate it);
|   * both critical variants carry the identical CI-PERF-04 steps, the Full Suite
|     stays sequential, and the filter line is untouched;
|   * the warning contract reads ParaTest's ANSI-styled summary (it used to report
|     NO_GO for a green parallel run) without ever hiding a failure or a warning,
|     and without rewriting the evidence log;
|   * the worker-database provisioning script refuses anything but a test run.
*/

function cp04Root(): string
{
    return dirname(__DIR__, 3);
}

/** @return array<string, string> key=value lines printed by the resolver */
function cp04Resolve(array $args): array
{
    $process = new Process(array_merge(['bash', cp04Root().'/scripts/ci/resolve-critical-parallelism.sh'], $args), cp04Root());
    $process->run();
    expect($process->getExitCode())->toBe(0);

    $out = [];
    foreach (preg_split('/\R/', trim($process->getOutput())) as $line) {
        if (str_contains($line, '=')) {
            [$key, $value] = explode('=', $line, 2);
            $out[$key] = $value;
        }
    }

    return $out;
}

function cp04Policy(): array
{
    return json_decode((string) file_get_contents(cp04Root().'/.github/ci-policy/critical-parallel-policy.json'), true, 512, JSON_THROW_ON_ERROR);
}

function cp04TempPolicy(string $contents): string
{
    $path = tempArtifactDir('cp04-policy-').'/policy.json';
    file_put_contents($path, $contents);

    return $path;
}

/** @return array<int, array<string, mixed>> */
function cp04Steps(string $job): array
{
    $workflow = Yaml::parseFile(cp04Root().'/.github/workflows/foundation-evidence-gates.yml');

    return $workflow['jobs'][$job]['steps'];
}

function cp04Step(string $job, string $name): ?array
{
    foreach (cp04Steps($job) as $step) {
        if (($step['name'] ?? null) === $name) {
            return $step;
        }
    }

    return null;
}

const CP04_STEPS = [
    'Resolve critical test parallelism (CI-PERF-04)',
    'Provision parallel worker databases (CI-PERF-04)',
    'Start resource sampler (CI-PERF-04 evaluation)',
    'Stop resource sampler (CI-PERF-04 evaluation)',
];

it('commits the parallel policy OFF, bounded by the measured runner capacity', function () {
    $policy = cp04Policy();

    expect($policy['status'])->toBe('OFF')
        ->and($policy['allowed_statuses'])->toBe(['OFF', 'ACTIVE'])
        ->and($policy['default_workers'])->toBe(1)
        ->and($policy['allowed_worker_counts'])->toBe([1, 2, 4])
        ->and($policy['max_workers'])->toBe(4)
        ->and($policy['repository_variable'])->toBe('CI_CRITICAL_TEST_WORKERS')
        ->and($policy['activation']['forbidden'])->toContain('repository variable alone');
});

it('runs pull_request, push and schedule sequentially while OFF, ignoring the repository variable', function (string $event) {
    $decision = cp04Resolve(['--event', $event, '--repository-workers', '4']);

    expect($decision['critical_test_workers'])->toBe('1')
        ->and($decision['critical_parallel_mode'])->toBe('sequential')
        ->and($decision['critical_parallel_evaluation'])->toBe('false')
        ->and($decision['critical_parallel_reason'])->toBe('CRITICAL_PARALLELISM_OFF')
        ->and($decision['critical_test_extra_args'])->toBe('');
})->with(['pull_request', 'push', 'schedule']);

it('allows an explicit evaluation dispatch while OFF and labels it as one', function (string $workers, string $args) {
    $decision = cp04Resolve(['--event', 'workflow_dispatch', '--dispatch-workers', $workers]);

    expect($decision['critical_test_workers'])->toBe($workers)
        ->and($decision['critical_parallel_evaluation'])->toBe('true')
        ->and($decision['critical_parallel_reason'])->toBe('CRITICAL_PARALLELISM_EVALUATION_DISPATCH')
        ->and($decision['critical_test_extra_args'])->toBe($args);
})->with([
    ['4', '--parallel --processes=4 --log-junit storage/ci-evidence/nsf-r011-critical-junit.xml'],
    ['2', '--parallel --processes=2 --log-junit storage/ci-evidence/nsf-r011-critical-junit.xml'],
    ['1', '--log-junit storage/ci-evidence/nsf-r011-critical-junit.xml'],
]);

it('treats a dispatch without a worker choice like any other event', function () {
    $decision = cp04Resolve(['--event', 'workflow_dispatch', '--dispatch-workers', 'repository-default', '--repository-workers', '4']);

    expect($decision['critical_test_workers'])->toBe('1')
        ->and($decision['critical_parallel_evaluation'])->toBe('false')
        ->and($decision['critical_test_extra_args'])->toBe('');
});

it('fails toward sequential for every out-of-range or hostile worker count', function (string $value) {
    $decision = cp04Resolve(['--event', 'workflow_dispatch', '--dispatch-workers', $value]);

    expect($decision['critical_test_workers'])->toBe('1')
        ->and($decision['critical_parallel_mode'])->toBe('sequential')
        ->and($decision['critical_test_extra_args'])->toBe('')
        ->and($decision['critical_parallel_reason'])->toBe('INVALID_WORKER_COUNT_SEQUENTIAL');
})->with(['0', '3', '8', '16', '-1', 'abc', '2; rm -rf /', '4 --filter=Nothing', '$(id)', '0004', '18446744073709551618', '18446744073709551620']);

it('fails toward sequential when the policy cannot be read unambiguously', function (?string $contents) {
    $path = $contents === null ? '/nonexistent/critical-parallel-policy.json' : cp04TempPolicy($contents);
    $decision = cp04Resolve(['--event', 'workflow_dispatch', '--dispatch-workers', '4', '--policy-file', $path]);

    expect($decision['critical_test_workers'])->toBe('1')
        ->and($decision['critical_parallel_policy_status'])->toBe('UNRESOLVED')
        ->and($decision['critical_parallel_reason'])->toBe('POLICY_UNRESOLVED_SEQUENTIAL')
        ->and($decision['critical_test_extra_args'])->toBe('');
})->with([
    'missing file' => [null],
    'unknown status' => ['{"status": "ON", "allowed_worker_counts": [1, 2, 4], "max_workers": 4}'],
    'two status tokens' => ['{"status": "OFF", "x": {"status": "ACTIVE"}, "allowed_worker_counts": [1, 2, 4], "max_workers": 4}'],
    'no worker list' => ['{"status": "OFF", "max_workers": 4}'],
]);

it('honours the repository variable only once the committed policy is ACTIVE', function () {
    $active = cp04TempPolicy(str_replace('"status": "OFF"', '"status": "ACTIVE"', (string) file_get_contents(cp04Root().'/.github/ci-policy/critical-parallel-policy.json')));

    expect(cp04Resolve(['--event', 'push', '--repository-workers', '4', '--policy-file', $active])['critical_test_extra_args'])
        ->toBe('--parallel --processes=4')
        ->and(cp04Resolve(['--event', 'push', '--policy-file', $active])['critical_test_workers'])->toBe('1')
        ->and(cp04Resolve(['--event', 'push', '--repository-workers', '8', '--policy-file', $active])['critical_test_workers'])->toBe('1');
});

it('gives both critical variants the identical CI-PERF-04 steps in the identical order', function () {
    $hosted = array_values(array_filter(array_map(fn ($s) => $s['name'] ?? '', cp04Steps('critical_test_gate')), fn ($n) => in_array($n, CP04_STEPS, true) || $n === 'Run critical regression tests'));
    $selfHosted = array_values(array_filter(array_map(fn ($s) => $s['name'] ?? '', cp04Steps('critical_test_gate_self_hosted')), fn ($n) => in_array($n, CP04_STEPS, true) || $n === 'Run critical regression tests'));

    expect($hosted)->toBe([CP04_STEPS[0], CP04_STEPS[1], CP04_STEPS[2], 'Run critical regression tests', CP04_STEPS[3]])
        ->and($selfHosted)->toBe($hosted);

    foreach (CP04_STEPS as $name) {
        $a = cp04Step('critical_test_gate', $name);
        $b = cp04Step('critical_test_gate_self_hosted', $name);
        unset($a['env']['CI_PHP_RUNNER'], $b['env']['CI_PHP_RUNNER']);
        expect($b)->toBe($a);
    }
});

it('appends the resolved arguments after the untouched filter in both variants', function (string $job) {
    $run = cp04Step($job, 'Run critical regression tests');

    expect($run['env']['CRITICAL_TEST_EXTRA_ARGS'])->toBe('${{ steps.critical_parallel.outputs.critical_test_extra_args }}')
        ->and($run['run'])->toMatch("/InventoryClinicCalendarBoundary' \\\\\\n\\s+\\\$CRITICAL_TEST_EXTRA_ARGS 2>&1 \\| tee storage\\/ci-evidence\\/nsf-r011-critical-tests\\.log\\n/");
})->with(['critical_test_gate', 'critical_test_gate_self_hosted']);

it('resolves to the exact pre-CI-PERF-04 command line on a non-evaluation run', function () {
    $run = cp04Step('critical_test_gate', 'Run critical regression tests')['run'];
    preg_match("/php artisan test --filter='[^']+' \\\\\n\\s+\\\$CRITICAL_TEST_EXTRA_ARGS 2>&1/", $run, $m);
    expect($m)->not->toBeEmpty();

    $args = cp04Resolve(['--event', 'pull_request', '--repository-workers', '4'])['critical_test_extra_args'];
    $words = preg_split('/\s+/', trim(str_replace(['$CRITICAL_TEST_EXTRA_ARGS', "\\\n"], [$args, ' '], $m[0])));

    expect(end($words))->toBe('2>&1')
        ->and(array_slice($words, 0, 3))->toBe(['php', 'artisan', 'test'])
        ->and(count($words))->toBe(5);
});

it('keeps the Full Suite and the selective module gate sequential', function () {
    $workflow = (string) file_get_contents(cp04Root().'/.github/workflows/foundation-evidence-gates.yml');
    $full = Yaml::parse($workflow)['jobs']['full_suite_gate'];
    $selective = Yaml::parse($workflow)['jobs']['selective_module_gate'];

    expect(json_encode($full))->not->toContain('CRITICAL_TEST_EXTRA_ARGS')->not->toContain('--parallel')
        ->and(json_encode($selective))->not->toContain('CRITICAL_TEST_EXTRA_ARGS')->not->toContain('--parallel');
});

it('offers only the policy worker counts as the dispatch choice', function () {
    $input = Yaml::parseFile(cp04Root().'/.github/workflows/foundation-evidence-gates.yml')['on']['workflow_dispatch']['inputs']['critical_test_workers'];

    expect($input['type'])->toBe('choice')
        ->and($input['default'])->toBe('repository-default')
        ->and($input['options'])->toBe(['repository-default', '1', '2', '4']);
});

it('reads a ParaTest summary through its forced terminal styling', function () {
    $contract = new CriticalGateWarningContract;
    $green = "  \e[90mTests:\e[39m    \e[32;1m7015 passed\e[39;22m\e[90m (39127 assertions)\e[39m\n  \e[90mDuration:\e[39m 852s\n\e[1A  \e[90mParallel:\e[39m 4 processes\n";

    expect($contract->parseSummary($green)['counts'])->toBe(['passed' => 7015])
        ->and($contract->parseSummary($green)['found'])->toBeTrue();
});

it('never lets terminal styling hide a failure or a warning', function () {
    $contract = new CriticalGateWarningContract;
    $red = "  \e[90mTests:\e[39m    \e[31;1m1 failed\e[39;22m\e[90m,\e[39m \e[33;1m2 warnings\e[39;22m\e[90m,\e[39m \e[32;1m11 passed\e[39;22m\n";

    expect($contract->parseSummary($red)['counts'])->toBe(['failed' => 1, 'warnings' => 2, 'passed' => 11]);
});

it('never lets a hyperlink sequence swallow the authoritative summary', function () {
    // Independent review: an OSC that ignored its ESC \\ terminator ran to a later BEL
    // and deleted the real `Tests:` line, leaving an earlier green one to decide.
    $contract = new CriticalGateWarningContract;
    $log = "Tests: 10 passed\n\e]8;;https://example.test\e\\link\e]8;;\e\\\nTests: 3 warnings, 10 passed\n\x07\n";

    expect($contract->parseSummary($log)['counts'])->toBe(['warnings' => 3, 'passed' => 10]);
});

it('keeps the counts next to a malformed escape sequence', function () {
    // Independent review: a malformed CSI must lose only its ESC and introducer,
    // never the digit or word beside it.
    $contract = new CriticalGateWarningContract;

    expect($contract->parseSummary("Tests: \e[2 warnings, 10 passed\n")['counts'])->toBe(['warnings' => 2, 'passed' => 10]);
});

it('evaluates a styled parallel log to the same verdict as a plain one and leaves the file untouched', function () {
    $dir = tempArtifactDir('cp04-log-');
    $styled = $dir.'/styled.log';
    $plain = $dir.'/plain.log';
    file_put_contents($styled, "\e[90mTests:\e[39m    \e[32;1m48 passed\e[39;22m\e[90m (287 assertions)\e[39m\n");
    file_put_contents($plain, "Tests:    48 passed (287 assertions)\n");
    $before = hash_file('sha256', $styled);

    $contract = new CriticalGateWarningContract;
    $a = $contract->evaluate($styled);
    $b = $contract->evaluate($plain);

    expect($a['decision'])->toBe('GO')
        ->and($a['decision'])->toBe($b['decision'])
        ->and($a['observed_warning_count'])->toBe($b['observed_warning_count'])
        ->and(hash_file('sha256', $styled))->toBe($before);
});

it('refuses to provision worker databases outside a test run', function (array $env, int $workers) {
    $process = new Process(['bash', cp04Root().'/scripts/ci/prepare-parallel-test-databases.sh', (string) $workers], cp04Root(), array_merge([
        'APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql', 'DB_DATABASE' => 'testing', 'CI_PHP_RUNNER' => 'false',
    ], $env));
    $process->run();

    expect($process->getExitCode())->toBe(2);
})->with([
    'production environment' => [['APP_ENV' => 'production'], 4],
    'pilot environment' => [['APP_ENV' => 'pilot'], 4],
    'database name with SQL' => [['DB_DATABASE' => 'testing"; DROP DATABASE x; --'], 2],
    'empty database name' => [['DB_DATABASE' => ''], 2],
    'zero workers' => [[], 0],
    'absurd worker count' => [[], 64],
]);
