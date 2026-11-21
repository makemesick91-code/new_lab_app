<?php

use Symfony\Component\Yaml\Yaml;

uses()->group('Cicd', 'Ci', 'CiTriggerRemoval', 'FoundationGovernance');

/*
|--------------------------------------------------------------------------
| CI-CD-PR-ONLY-POLICY-1 phase B5 — governance contract (owner decisions 17 and 18)
|--------------------------------------------------------------------------
|
| CG-16 gains one narrow class that closes without a deploy and receives no
| GO tag of its own: the owner-authorised removal of the `push` and `schedule`
| triggers of .github/workflows/foundation-evidence-gates.yml. The owner
| required the class to read the same in the rule (CLAUDE.md CG-16), in the
| register that implements it (docs/governance/go-tag-exceptions.md) and in
| the tests, and never to become a general exception for GitHub Actions.
|
| This file pins three things:
|  1. the rule and the register state the same, narrow class;
|  2. the workflow invariants that a trigger removal must keep (they hold
|     before and after the removal, so the removal cannot weaken them);
|  3. B5-RESIDUAL-1: the `config/` and `app/` texts that will still describe
|     the removed triggers. They stay OPEN, pinned here so that they can
|     neither be "fixed" nor grow silently, with the proof that nothing on the
|     runtime, deploy, release-guard or security path reads them.
*/

const CTRG_WORKFLOW = '.github/workflows/foundation-evidence-gates.yml';
const CTRG_BASE_BRANCH = 'feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report';

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

/** The CI trigger removal bullet of the GO-tag register, up to the next heading. */
function ctrgRemovalClause(): string
{
    $register = (string) file_get_contents(base_path('docs/governance/go-tag-exceptions.md'));
    $start = strpos($register, '- A **CI trigger removal**');
    expect($start)->not->toBeFalse('the GO-tag register must state the CI trigger removal class');
    $end = strpos($register, "\n## ", (int) $start);

    return substr($register, (int) $start, ($end === false ? strlen($register) : $end) - (int) $start);
}

function ctrgWorkflowRaw(): string
{
    return (string) file_get_contents(base_path(CTRG_WORKFLOW));
}

function ctrgWorkflow(): array
{
    return Yaml::parseFile(base_path(CTRG_WORKFLOW));
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
| 1. The rule and the register state the same, narrow class
|--------------------------------------------------------------------------
*/

it('states the CI trigger removal class in CLAUDE.md CG-16, the rule the register implements', function () {
    $cg16 = ctrgNormalise(ctrgCg16());

    foreach ([
        'Nothing else is an exception',
        'close without a deploy and get no GO tag of their own',
        'never reported as deployed',
        'docs, rules or tests-only',
        'CI trigger removal of `docs/governance/go-tag-exceptions.md`',
        'deleting the `push` and `schedule` triggers of `.github/workflows/foundation-evidence-gates.yml`',
        'keeps `pull_request` and `workflow_dispatch`',
        'weakens no job or gate',
        'never extends to another GitHub Actions change',
    ] as $phrase) {
        expect(str_contains($cg16, $phrase))->toBeTrue("CLAUDE.md CG-16 lost: {$phrase}");
    }
});

it('states the same class, with all six conditions, in the GO-tag register', function () {
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
        'never reported as deployed or released',
        'keeps its own manifest `go_tag`',
        'covers no other GitHub Actions change',
    ] as $phrase) {
        expect(str_contains($clause, $phrase))->toBeTrue("go-tag-exceptions.md CI trigger removal clause lost: {$phrase}");
    }

    preg_match_all('/^ {2}([1-9])\. /m', $raw, $conditions);
    expect($conditions[1])->toBe(['1', '2', '3', '4', '5', '6']);
});

it('keeps the class narrow: one workflow file, two triggers, no general GitHub Actions exception', function () {
    foreach (['CLAUDE.md CG-16' => ctrgCg16(), 'go-tag-exceptions.md' => ctrgRemovalClause()] as $where => $text) {
        preg_match_all('#\.github/workflows/[A-Za-z0-9._-]+#', $text, $paths);
        expect(array_values(array_unique($paths[0])))
            ->toBe([CTRG_WORKFLOW], "{$where} may name only the one workflow the class covers");

        $prose = strtolower(ctrgNormalise($text));
        foreach (['any workflow', 'all workflows', 'any trigger', 'any github actions', 'every workflow'] as $widening) {
            expect(str_contains($prose, $widening))->toBeFalse("{$where} must not widen the class: {$widening}");
        }
    }

    // The class removes triggers; it never adds, restores or edits one.
    expect(ctrgNormalise(ctrgRemovalClause()))
        ->toContain('adding, restoring or editing a trigger, job or workflow is outside it');
});

/*
|--------------------------------------------------------------------------
| 2. Workflow invariants a CI trigger removal must keep
|--------------------------------------------------------------------------
*/

it('pins the workflow invariants a CI trigger removal must keep', function () {
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
| 3. B5-RESIDUAL-1 — open, pinned, and off every runtime path
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
