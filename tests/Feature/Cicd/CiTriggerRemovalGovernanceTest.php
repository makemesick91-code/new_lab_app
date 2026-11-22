<?php

use Symfony\Component\Yaml\Yaml;

uses()->group('Cicd', 'Ci', 'CiTriggerRemoval', 'FoundationGovernance');

/*
|--------------------------------------------------------------------------
| CI-CD-PR-ONLY-POLICY-1 phase B5 — governance contract (owner decisions 17, 18 and 19)
|--------------------------------------------------------------------------
|
| CG-16 gains one narrow class that closes without a deploy and receives no
| GO tag of its own: the owner-authorised removal of the `push` and `schedule`
| triggers of .github/workflows/foundation-evidence-gates.yml, and its only
| rollback, the byte-for-byte restoration of the pinned pre-removal file. The
| owner required the class to read the same in the rule (CLAUDE.md CG-16), in
| the register that implements it (docs/governance/go-tag-exceptions.md) and
| in the tests, and never to become a general exception for GitHub Actions.
|
| This file pins four things:
|  1. the rule and the register state the same, narrow classes;
|  2. the two approved workflow states (SHA-256) and a classifier that accepts
|     only the exact removal and the exact restoration, rejecting every other
|     change to the workflow;
|  3. the workflow invariants both changes must keep;
|  4. B5-RESIDUAL-1: the `config/` and `app/` texts that will still describe
|     the removed triggers. They stay OPEN, pinned here so that they can
|     neither be "fixed" nor grow silently, with the proof that nothing on the
|     runtime, deploy, release-guard or security path reads them.
*/

const CTRG_WORKFLOW = '.github/workflows/foundation-evidence-gates.yml';
const CTRG_BASE_BRANCH = 'feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report';

// The two approved states of the workflow (decision 19). Also pinned in the register and the sprint record.
const CTRG_PRE_REMOVAL_SHA256 = '978e90c028fe6530b6f4eaf422760e1a1f913ab9400dfb24a70a9310c215d3d4';
const CTRG_POST_REMOVAL_SHA256 = '4cd0c3dbe7c374e30f05659f89e7c22a2f197005da3d0f92f423124622b98cd0';

// The exact lines the removal deletes: pre-removal lines 7–9 and 55–56.
const CTRG_PUSH_LINES = [
    '  push:',
    '    branches:',
    '      - '.CTRG_BASE_BRANCH,
];
const CTRG_SCHEDULE_LINES = [
    '  schedule:',
    "    - cron: '0 2 * * 0'",
];

function ctrgNormalise(string $text): string
{
    return (string) preg_replace('/\s+/', ' ', $text);
}

/** The CG-16 bullet of CLAUDE.md, up to the next critical global rule. */
function ctrgCg16(): string
{
    $claude = (string) file_get_contents(base_path('CLAUDE.md'));
    $start = strpos($claude, '- **CG-16 ');
    $end = strpos($claude, '- **CG-17 ');
    expect($start)->not->toBeFalse()->and($end)->not->toBeFalse();

    return substr($claude, (int) $start, (int) $end - (int) $start);
}

function ctrgRegister(): string
{
    return (string) file_get_contents(base_path('docs/governance/go-tag-exceptions.md'));
}

/** Both register bullets (removal and restoration), up to the next heading. */
function ctrgClasses(): string
{
    $register = ctrgRegister();
    $start = strpos($register, '- A **CI trigger removal**');
    expect($start)->not->toBeFalse('the GO-tag register must state the CI trigger removal class');
    $end = strpos($register, "\n## ", (int) $start);

    return substr($register, (int) $start, ($end === false ? strlen($register) : $end) - (int) $start);
}

function ctrgRemovalClause(): string
{
    $classes = ctrgClasses();
    $split = strpos($classes, '- A **CI trigger restoration**');
    expect($split)->not->toBeFalse('the GO-tag register must state the CI trigger restoration');

    return substr($classes, 0, (int) $split);
}

function ctrgRestorationClause(): string
{
    $classes = ctrgClasses();

    return substr($classes, (int) strpos($classes, '- A **CI trigger restoration**'));
}

function ctrgWorkflowRaw(): string
{
    return (string) file_get_contents(base_path(CTRG_WORKFLOW));
}

function ctrgWorkflow(): array
{
    return Yaml::parseFile(base_path(CTRG_WORKFLOW));
}

/** Pre-removal content minus exactly the push and schedule lines. */
function ctrgRemove(string $pre): string
{
    $lines = explode("\n", $pre);
    expect(array_slice($lines, 6, 3))->toBe(CTRG_PUSH_LINES)
        ->and(array_slice($lines, 54, 2))->toBe(CTRG_SCHEDULE_LINES);

    return implode("\n", array_merge(array_slice($lines, 0, 6), array_slice($lines, 9, 45), array_slice($lines, 56)));
}

/** Post-removal content with exactly the push and schedule lines put back. */
function ctrgRestore(string $post): string
{
    $lines = explode("\n", $post);

    return implode("\n", array_merge(
        array_slice($lines, 0, 6), CTRG_PUSH_LINES, array_slice($lines, 6, 45), CTRG_SCHEDULE_LINES, array_slice($lines, 51),
    ));
}

/** The approved pre-removal and post-removal contents, derived from the checked-in file in either state. */
function ctrgStates(): array
{
    $current = ctrgWorkflowRaw();
    $sha = hash('sha256', $current);
    // (`toContain` is variadic, so the reason lives in `toBeTrue`.)
    expect(in_array($sha, [CTRG_PRE_REMOVAL_SHA256, CTRG_POST_REMOVAL_SHA256], true))
        ->toBeTrue("the workflow ({$sha}) must be in one of the two approved states; any other change is outside the CG-16 CI trigger classes");

    return $sha === CTRG_PRE_REMOVAL_SHA256
        ? ['pre' => $current, 'post' => ctrgRemove($current)]
        : ['pre' => ctrgRestore($current), 'post' => $current];
}

/**
 * Classifies a change to the workflow under the CG-16 CI trigger classes:
 * `removal`, `restoration`, `unchanged`, or `rejected` for everything else.
 */
function ctrgClassify(string $before, string $after): string
{
    if ($before === $after) {
        return 'unchanged';
    }
    $from = hash('sha256', $before);
    $to = hash('sha256', $after);

    if ($from === CTRG_PRE_REMOVAL_SHA256 && $to === CTRG_POST_REMOVAL_SHA256 && ctrgRemove($before) === $after) {
        return 'removal';
    }
    if ($from === CTRG_POST_REMOVAL_SHA256 && $to === CTRG_PRE_REMOVAL_SHA256 && ctrgRestore($before) === $after) {
        return 'restoration';
    }

    return 'rejected';
}

/**
 * Relative paths of source files under $dirs whose content matches $pattern.
 *
 * @param  list<string>  $dirs
 * @return list<string>
 */
function ctrgFilesMatching(array $dirs, string $pattern): array
{
    $hits = [];
    foreach ($dirs as $dir) {
        $root = base_path($dir);
        if (! is_dir($root)) {
            continue;
        }
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            $path = $file->getPathname();
            if (str_contains($path, '/node_modules/') || ! preg_match('/\.(php|sh|conf|js|ts|vue)$/', $path)) {
                continue;
            }
            if (preg_match($pattern, (string) file_get_contents($path)) === 1) {
                $hits[] = ltrim(substr($path, strlen(base_path())), '/');
            }
        }
    }
    sort($hits);

    return $hits;
}

/*
|--------------------------------------------------------------------------
| 1. The rule and the register state the same, narrow classes
|--------------------------------------------------------------------------
*/

it('states the CI trigger removal and its rollback in CLAUDE.md CG-16, the rule the register implements', function () {
    $cg16 = ctrgNormalise(ctrgCg16());

    foreach ([
        'Nothing else is an exception',
        'close without a deploy and get no GO tag of their own',
        'never reported as deployed',
        'docs, rules or tests-only',
        'CI trigger removal of `docs/governance/go-tag-exceptions.md`',
        'deleting the `push` and `schedule` triggers of `.github/workflows/foundation-evidence-gates.yml`',
        'a separately owner-approved PR, green CI before merge',
        'restores the file byte for byte to its pinned pre-removal SHA-256',
        'keep `pull_request` and `workflow_dispatch`',
        'weaken no job or gate',
        'deploy nothing',
        'never extend to another GitHub Actions change',
    ] as $phrase) {
        expect(str_contains($cg16, $phrase))->toBeTrue("CLAUDE.md CG-16 lost: {$phrase}");
    }
});

it('states the removal, with all six conditions, in the GO-tag register', function () {
    $raw = ctrgRemovalClause();
    $clause = ctrgNormalise($raw);

    foreach ([
        'closed without a deploy and receives no GO tag of its own',
        'CLAUDE.md CG-16',
        'authorises in writing',
        'deletes the whole `push` and `schedule` entries from the `on:` block of `.github/workflows/foundation-evidence-gates.yml`',
        'minus exactly those lines',
        'both SHA-256 values',
        '`pull_request` and `workflow_dispatch` stay, with their branches and inputs unchanged',
        'no job, step, `if`, `permissions`, `concurrency`, `env`, secret use, runner selection, classifier input, critical-gate filter, policy file or other workflow',
        '`app/`, `config/`, `scripts/`, `deploy/`, `database/`, `routes/`, `resources/`, `bootstrap/`, `public/` or `.sprint/`',
        'the release guard, the signer, the runner and every approval contract stay unchanged',
        'states that the change was not deployed',
        CTRG_PRE_REMOVAL_SHA256,
        CTRG_POST_REMOVAL_SHA256,
    ] as $phrase) {
        expect(str_contains($clause, $phrase))->toBeTrue("go-tag-exceptions.md CI trigger removal lost: {$phrase}");
    }

    preg_match_all('/^ {2}([1-9])\. /m', $raw, $conditions);
    expect($conditions[1])->toBe(['1', '2', '3', '4', '5', '6']);
});

it('states the rollback, a byte-for-byte restoration with six conditions, in the GO-tag register', function () {
    $raw = ctrgRestorationClause();
    $clause = ctrgNormalise($raw);

    foreach ([
        'CI trigger restoration',
        'the rollback of that removal',
        'without a deploy and with no GO tag of its own',
        'byte for byte to the approved pre-removal version',
        'the pinned post-removal SHA-256, after it the pinned pre-removal SHA-256',
        'A partial or edited restoration is not a restoration',
        'Conditions 2 to 5 of the removal hold',
        'no other trigger, job, step, `permissions`, `concurrency`, `env`, secret use, runner selection, classifier input, critical-gate filter or release gate changes',
        'its own pull request with its own written owner approval',
        'Its CI runs on the exact head and passes before the merge',
        'deploys nothing and changes nothing on the VPS',
        'states that the change was not deployed',
        'Neither change is ever reported as deployed or released',
        'keeps its own manifest `go_tag`',
        'The two classes cover no other GitHub Actions change',
        'adding or editing a trigger, job or workflow, a partial restoration, or a restoration to any other content is outside them',
    ] as $phrase) {
        expect(str_contains($clause, $phrase))->toBeTrue("go-tag-exceptions.md CI trigger restoration lost: {$phrase}");
    }

    preg_match_all('/^ {2}([1-9])\. /m', $raw, $conditions);
    expect($conditions[1])->toBe(['1', '2', '3', '4', '5', '6']);

    // The sprint record pins the same two states.
    $record = (string) file_get_contents(base_path('docs/sprints/ci-cd-pr-only-policy-1.md'));
    expect($record)->toContain(CTRG_PRE_REMOVAL_SHA256)->toContain(CTRG_POST_REMOVAL_SHA256);
});

it('keeps the classes narrow: one workflow file, two triggers, no general GitHub Actions exception', function () {
    foreach (['CLAUDE.md CG-16' => ctrgCg16(), 'go-tag-exceptions.md' => ctrgClasses()] as $where => $text) {
        preg_match_all('#\.github/workflows/[A-Za-z0-9._-]+#', $text, $paths);
        expect(array_values(array_unique($paths[0])))
            ->toBe([CTRG_WORKFLOW], "{$where} may name only the one workflow the classes cover");

        $prose = strtolower(ctrgNormalise($text));
        foreach (['any workflow', 'all workflows', 'any trigger', 'any github actions', 'every workflow'] as $widening) {
            expect(str_contains($prose, $widening))->toBeFalse("{$where} must not widen the classes: {$widening}");
        }
    }
});

/*
|--------------------------------------------------------------------------
| 2. The two approved workflow states and the change classifier
|--------------------------------------------------------------------------
*/

it('keeps the checked-in workflow in one of the two pinned states, which differ by exactly the trigger lines', function () {
    $states = ctrgStates();

    expect(hash('sha256', $states['pre']))->toBe(CTRG_PRE_REMOVAL_SHA256)
        ->and(hash('sha256', $states['post']))->toBe(CTRG_POST_REMOVAL_SHA256)
        ->and(ctrgRemove($states['pre']))->toBe($states['post'])
        ->and(ctrgRestore($states['post']))->toBe($states['pre']);

    $pre = Yaml::parse($states['pre']);
    $post = Yaml::parse($states['post']);
    expect(array_keys($pre['on']))->toBe(['pull_request', 'push', 'workflow_dispatch', 'schedule'])
        ->and(array_keys($post['on']))->toBe(['pull_request', 'workflow_dispatch'])
        ->and($post['jobs'])->toBe($pre['jobs'])
        ->and($post['concurrency'])->toBe($pre['concurrency'])
        ->and($post['on']['pull_request'])->toBe($pre['on']['pull_request'])
        ->and($post['on']['workflow_dispatch'])->toBe($pre['on']['workflow_dispatch']);
});

it('accepts only the exact removal and the exact restoration', function () {
    $states = ctrgStates();

    expect(ctrgClassify($states['pre'], $states['post']))->toBe('removal')
        ->and(ctrgClassify($states['post'], $states['pre']))->toBe('restoration')
        ->and(ctrgClassify($states['pre'], $states['pre']))->toBe('unchanged');
});

it('rejects every workflow change other than the exact removal or restoration', function (Closure $mutate, string $from) {
    $states = ctrgStates();
    [$before, $after] = $mutate($states['pre'], $states['post']);

    // Each case must really differ from the approved pair it imitates, or it would prove nothing.
    $approved = $from === 'post' ? [$states['post'], $states['pre']] : [$states['pre'], $states['post']];
    expect([$before, $after])->not->toBe($approved, 'the negative case did not change anything')
        ->and(ctrgClassify($before, $after))->toBe('rejected');
})->with([
    'restoration plus an extra trigger' => [fn (string $pre, string $post) => [$post, str_replace("on:\n", "on:\n  release:\n    types: [published]\n", $pre)], 'post'],
    'restoration plus a job edit' => [fn (string $pre, string $post) => [$post, preg_replace('/runs-on: ubuntu-latest/', 'runs-on: ubuntu-22.04', $pre, 1)], 'post'],
    'restoration plus token permissions' => [fn (string $pre, string $post) => [$post, str_replace("\nconcurrency:", "\npermissions: write-all\nconcurrency:", $pre)], 'post'],
    'restoration plus a new secret' => [fn (string $pre, string $post) => [$post, str_replace('secrets.CI_DB_PASSWORD', 'secrets.CI_DEPLOY_KEY', $pre)], 'post'],
    'restoration with concurrency weakened' => [fn (string $pre, string $post) => [$post, str_replace('cancel-in-progress: true', 'cancel-in-progress: false', $pre)], 'post'],
    'restoration with a classifier input change' => [fn (string $pre, string $post) => [$post, str_replace('resolve-gates.sh', 'resolve-gates-lenient.sh', $pre)], 'post'],
    'restoration with a different cron' => [fn (string $pre, string $post) => [$post, str_replace("cron: '0 2 * * 0'", "cron: '0 3 * * 0'", $pre)], 'post'],
    'partial restoration: push only' => [fn (string $pre, string $post) => [$post, str_replace(implode("\n", CTRG_SCHEDULE_LINES)."\n", '', $pre)], 'post'],
    'partial restoration: schedule only' => [fn (string $pre, string $post) => [$post, str_replace(implode("\n", CTRG_PUSH_LINES)."\n", '', $pre)], 'post'],
    'restoration with a whitespace-only difference' => [fn (string $pre, string $post) => [$post, $pre."\n"], 'post'],
    'restoration onto an unapproved base' => [fn (string $pre, string $post) => [$post."# drift\n", $pre], 'post'],
    'removal of push only' => [fn (string $pre, string $post) => [$pre, str_replace(implode("\n", CTRG_PUSH_LINES)."\n", '', $pre)], 'pre'],
    'removal plus a job edit' => [fn (string $pre, string $post) => [$pre, preg_replace('/runs-on: ubuntu-latest/', 'runs-on: ubuntu-22.04', $post, 1)], 'pre'],
    'removal that also drops pull_request' => [fn (string $pre, string $post) => [$pre, str_replace("  pull_request:\n    branches:\n      - ".CTRG_BASE_BRANCH."\n", '', $post)], 'pre'],
    'an unrelated edit of the approved file' => [fn (string $pre, string $post) => [$pre, str_replace('name: Foundation Evidence Gates', 'name: Foundation Evidence Gates (edited)', $pre)], 'pre'],
]);

/*
|--------------------------------------------------------------------------
| 3. Workflow invariants both changes must keep
|--------------------------------------------------------------------------
*/

it('pins the workflow invariants a CI trigger removal or restoration must keep', function () {
    $workflow = ctrgWorkflow();
    $raw = ctrgWorkflowRaw();
    $on = $workflow['on'];

    // No trigger beyond the four known ones; only push and schedule may ever be removed.
    expect(array_diff(array_keys($on), ['pull_request', 'push', 'workflow_dispatch', 'schedule']))->toBe([])
        ->and($on)->toHaveKeys(['pull_request', 'workflow_dispatch'])
        ->and($on['pull_request'])->toBe(['branches' => [CTRG_BASE_BRANCH]])
        ->and(array_keys($on['workflow_dispatch']['inputs']))
        ->toBe(['run_full_suite', 'full_suite_policy_override', 'runner_mode', 'critical_test_workers']);

    expect(array_keys($workflow['jobs']))->toBe([
        'classify', 'quality_gate', 'critical_test_gate', 'critical_test_gate_self_hosted', 'selective_module_gate',
        'release_safety_gate', 'nsf10_release_evidence_gate', 'full_suite_gate', 'android_clinic_app_gate',
    ]);

    // No token permission is widened at workflow or job level.
    expect($workflow)->not->toHaveKey('permissions');
    foreach ($workflow['jobs'] as $name => $job) {
        expect($job)->not->toHaveKey('permissions', "job {$name} must not declare token permissions");
    }

    preg_match_all('/secrets\.([A-Za-z0-9_]+)/', $raw, $secrets);
    expect(array_values(array_unique($secrets[1])))->toBe(['CI_DB_PASSWORD'])
        ->and($raw)->not->toContain('pull_request_target')
        ->and($workflow['concurrency'])->toBe([
            'group' => 'foundation-evidence-${{ github.ref }}',
            'cancel-in-progress' => true,
        ]);

    // Both critical-gate variants select the same tests.
    preg_match_all("/--filter='([^']+)'/", $raw, $filters);
    $critical = array_values(array_filter($filters[1], fn (string $f): bool => substr_count($f, '|') > 50));
    expect($critical)->toHaveCount(2)
        ->and($critical[0])->toBe($critical[1]);
});

/*
|--------------------------------------------------------------------------
| 4. B5-RESIDUAL-1 — open, pinned, and off every runtime path
|--------------------------------------------------------------------------
*/

it('pins B5-RESIDUAL-1 as open, so it can neither be fixed nor grow silently', function () {
    // Closing this residual belongs to the next CI governance sprint that is released under its own
    // manifest and go_tag (owner decision 18). That sprint replaces these expectations with their inverse.
    expect(config('ci_runtime_control.required_workflow_markers'))->toContain('schedule')
        ->and(config('ci_runtime_control.full_suite_fallback_triggers'))->toBe(['schedule', 'workflow_dispatch'])
        ->and(config('ci_runtime_control.temporary_full_suite_policy.deferred_automatic_events'))->toBe(['schedule', 'push']);

    $service = (string) file_get_contents(base_path('app/Services/Foundation/CiRuntimeControlGovernanceService.php'));
    expect($service)
        ->toContain('The full Pest suite remains available via schedule (weekly), manual workflow_dispatch, and push-to-base.')
        ->toContain('Both automatic paths are deferred: the weekly schedule and the post-merge push to the base branch');
});

it('proves B5-RESIDUAL-1 reaches no runtime, deploy, release-guard or security path', function () {
    $runtimeDirs = ['app', 'routes', 'scripts', 'deploy', 'bootstrap', 'resources', 'database', 'public'];

    // Only governance and tooling code reads the CI runtime control config or classes.
    expect(ctrgFilesMatching($runtimeDirs, '/ci_runtime_control|CiRuntimeControl(Scanner|GovernanceService)/'))->toBe([
        'app/Console/Commands/FoundationCiRuntimeControlCheckCommand.php',
        'app/Console/Commands/FoundationSelfHostedRunnerCheckCommand.php',
        'app/Services/Foundation/CiRuntimeControlGovernanceService.php',
        'app/Services/Foundation/FiveBranchRolloutReadinessService.php',
        'app/Services/Foundation/SelfHostedRunnerGovernanceService.php',
        'app/Support/Cicd/CiRuntimeControlScanner.php',
        'app/Support/Devflow/DevflowScanner.php',
    ]);

    // The residual keys themselves are read by the scanner alone.
    expect(ctrgFilesMatching($runtimeDirs, '/ci_runtime_control\.(required_workflow_markers|full_suite_fallback_triggers|temporary_full_suite_policy\.deferred_automatic_events)/'))
        ->toBe(['app/Support/Cicd/CiRuntimeControlScanner.php']);

    // DEVFLOW reads two unrelated keys only.
    preg_match_all("/ci_runtime_control\.([a-z_.]+)/", (string) file_get_contents(base_path('app/Support/Devflow/DevflowScanner.php')), $keys);
    expect(array_values(array_unique($keys[1])))->toBe(['skip_critical_profiles', 'default_profile']);

    // The deploy never runs the CI runtime control or self-hosted runner checks.
    foreach (['scripts/deploy-vps.sh', 'scripts/deploy-vps-runner.sh'] as $script) {
        $body = (string) file_get_contents(base_path($script));
        expect($body)->not->toContain('ci-runtime-control-check')
            ->and($body)->not->toContain('self-hosted-runner-check');
    }

    // The web monitor never executes the check; it reads the cached decision only.
    expect(config('foundation_monitoring.signals.audit_ci_runtime_control.auto_run'))->toBeFalse();
});
