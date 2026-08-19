<?php

use App\Services\Foundation\CiRuntimeControlGovernanceService;
use App\Support\Cicd\CiRuntimeControlScanner;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Yaml\Yaml;

uses()->group('Cicd', 'Ci', 'CiRuntimeControl', 'TemporaryFullSuitePolicy', 'FoundationGovernance');

/*
|--------------------------------------------------------------------------
| CI-TEMP-FULL-SUITE-SCHEDULE-GATE
|--------------------------------------------------------------------------
|
| Proves that while the GLOBAL TEMPORARY FULL-SUITE POLICY is ACTIVE, no
| AUTOMATIC trigger can execute the NSF-R011 Full Suite — and that the
| authorised consolidated closure run stays reachable.
|
| These tests NEVER run the Full Suite. They compose the two real artefacts:
|
|   1. the real resolver script            -> full_suite_authorized
|   2. the real workflow `if:` expression  -> would the job actually run?
|
| Simulating the decision is the point: proving the gate cannot fire must not
| require firing it.
|
*/

const TFS_BASE_BRANCH = 'feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report';

/**
 * Runs the REAL policy resolver and returns its parsed key=value output.
 *
 * @return array<string, string>
 */
function tfsResolve(string $event, array $opts = []): array
{
    $args = ['bash', base_path('scripts/ci/resolve-full-suite-policy.sh'), '--event', $event];

    $args[] = '--ref';
    $args[] = $opts['ref'] ?? '';
    $args[] = '--base-branch';
    $args[] = $opts['base_branch'] ?? TFS_BASE_BRANCH;
    $args[] = '--dispatch-run-full-suite';
    $args[] = ($opts['run_full_suite'] ?? false) ? 'true' : 'false';
    $args[] = '--dispatch-override';
    $args[] = ($opts['override'] ?? false) ? 'true' : 'false';

    if (array_key_exists('policy_file', $opts)) {
        $args[] = '--policy-file';
        $args[] = (string) $opts['policy_file'];
    }

    $result = Process::path(base_path())->run($args);

    expect($result->successful())->toBeTrue('the resolver must never fail the job; it decides, it does not error');

    $out = [];
    foreach (preg_split('/\r?\n/', $result->output()) as $line) {
        if (str_contains($line, '=')) {
            [$k, $v] = explode('=', $line, 2);
            $out[trim($k)] = trim($v);
        }
    }

    return $out;
}

/**
 * @return array<string, mixed>
 */
function tfsWorkflow(): array
{
    return Yaml::parseFile(base_path('.github/workflows/foundation-evidence-gates.yml'));
}

function tfsWorkflowRaw(): string
{
    return (string) file_get_contents(base_path('.github/workflows/foundation-evidence-gates.yml'));
}

/**
 * Evaluates the REAL `full_suite_gate` if-expression for one scenario, so the
 * invariant is proven against the shipped workflow rather than a paraphrase.
 */
function tfsGateWouldRun(string $event, string $ref, bool $runFullSuiteInput, string $authorized): bool
{
    $expr = (string) tfsWorkflow()['jobs']['full_suite_gate']['if'];
    $expr = preg_replace('/\s+/', ' ', trim($expr)) ?? '';
    $expr = str_replace(['${{', '}}'], '', $expr);

    // The critical gate is assumed green — that is the precondition under which
    // the Full Suite would otherwise be free to run, i.e. the worst case.
    $expr = str_replace('needs.critical_test_gate_self_hosted.result', "'skipped'", $expr);
    $expr = str_replace('needs.critical_test_gate.result', "'success'", $expr);
    $expr = str_replace('needs.classify.outputs.full_suite_authorized', "'{$authorized}'", $expr);
    $expr = str_replace('github.event_name', "'{$event}'", $expr);
    $expr = str_replace('github.ref', "'{$ref}'", $expr);
    // A GitHub boolean input is a real boolean, not a string.
    $expr = str_replace('inputs.run_full_suite', $runFullSuiteInput ? 'true' : 'false', $expr);
    $expr = str_replace('!cancelled()', 'true', $expr);

    // Only the tiny subset the workflow actually uses may survive to eval().
    expect(preg_match("/^[\\s()'a-zA-Z0-9_\\-\\/.!=|&]+$/", $expr))
        ->toBe(1, "unexpected token in gate expression: {$expr}");

    return (bool) eval("return {$expr};");
}

/*
|--------------------------------------------------------------------------
| The canonical policy state
|--------------------------------------------------------------------------
*/

it('keeps the temporary Full Suite policy ACTIVE in exactly one canonical place', function () {
    $path = base_path('.github/ci-policy/full-suite-policy.json');

    expect($path)->toBeReadableFile();

    $state = json_decode((string) file_get_contents($path), true);

    expect($state)->toBeArray()
        ->and($state['status'])->toBe('ACTIVE')
        ->and($state['deferred_automatic_events'])->toBe(['schedule', 'push'])
        ->and($state['authorised_manual_path']['requires_inputs'])
        ->toBe(['run_full_suite', 'full_suite_policy_override']);

    // The state is declared once. Governance reads this file; it never carries
    // its own copy of the boolean.
    expect(config('ci_runtime_control.temporary_full_suite_policy'))
        ->not->toHaveKey('status')
        ->not->toHaveKey('active');
});

/*
|--------------------------------------------------------------------------
| Policy ACTIVE — the automatic triggers are deferred
|--------------------------------------------------------------------------
*/

it('defers the weekly scheduled Full Suite while the policy is ACTIVE', function () {
    $r = tfsResolve('schedule');

    expect($r['temporary_full_suite_policy_active'])->toBe('true')
        ->and($r['full_suite_authorized'])->toBe('false')
        ->and($r['full_suite_defer_reason'])->toBe('TEMPORARY_FULL_SUITE_POLICY_ACTIVE');

    // End-to-end: the real workflow condition is false for a scheduled run.
    expect(tfsGateWouldRun('schedule', 'refs/heads/'.TFS_BASE_BRANCH, false, $r['full_suite_authorized']))
        ->toBeFalse('a scheduled run must not execute the Full Suite while the policy is ACTIVE');
});

it('defers the post-merge push-to-base Full Suite while the policy is ACTIVE', function () {
    // A squash-merge of a fix PR IS a push to the base branch.
    $r = tfsResolve('push', ['ref' => 'refs/heads/'.TFS_BASE_BRANCH]);

    expect($r['full_suite_authorized'])->toBe('false')
        ->and($r['full_suite_defer_reason'])->toBe('TEMPORARY_FULL_SUITE_POLICY_ACTIVE');

    expect(tfsGateWouldRun('push', 'refs/heads/'.TFS_BASE_BRANCH, false, $r['full_suite_authorized']))
        ->toBeFalse('a fix merge must not execute the Full Suite while the policy is ACTIVE');
});

it('never lets a pull request reach the Full Suite', function () {
    $r = tfsResolve('pull_request');

    expect($r['full_suite_authorized'])->toBe('false')
        ->and($r['full_suite_defer_reason'])->toBe('FULL_SUITE_NOT_ENABLED_FOR_EVENT')
        ->and(tfsGateWouldRun('pull_request', 'refs/pull/1/merge', false, $r['full_suite_authorized']))->toBeFalse();
});

it('blocks an unauthorised manual dispatch and says an override is required', function () {
    $r = tfsResolve('workflow_dispatch', ['run_full_suite' => true, 'override' => false]);

    expect($r['full_suite_authorized'])->toBe('false')
        // Deliberately distinct from "not requested": the operator asked, and the
        // policy — not a mistake — is what deferred it.
        ->and($r['full_suite_defer_reason'])->toBe('TEMPORARY_FULL_SUITE_POLICY_ACTIVE_OVERRIDE_REQUIRED')
        ->and(tfsGateWouldRun('workflow_dispatch', 'refs/heads/'.TFS_BASE_BRANCH, true, $r['full_suite_authorized']))
        ->toBeFalse();
});

it('reports a plain dispatch that did not ask for the suite as not requested', function () {
    $r = tfsResolve('workflow_dispatch', ['run_full_suite' => false, 'override' => false]);

    expect($r['full_suite_authorized'])->toBe('false')
        ->and($r['full_suite_defer_reason'])->toBe('FULL_SUITE_NOT_REQUESTED');
});

/*
|--------------------------------------------------------------------------
| The authorised path must stay reachable
|--------------------------------------------------------------------------
*/

it('still allows the explicitly authorised consolidated Full Suite while ACTIVE', function () {
    $r = tfsResolve('workflow_dispatch', ['run_full_suite' => true, 'override' => true]);

    expect($r['full_suite_authorized'])->toBe('true')
        ->and($r['full_suite_defer_reason'])->toBe('AUTHORISED_CONSOLIDATED_FULL_SUITE');

    // The final consolidated closure must not be made impossible by this sprint.
    expect(tfsGateWouldRun('workflow_dispatch', 'refs/heads/'.TFS_BASE_BRANCH, true, $r['full_suite_authorized']))
        ->toBeTrue('the consolidated closure run must remain reachable');
});

/*
|--------------------------------------------------------------------------
| Fail-closed
|--------------------------------------------------------------------------
*/

it('fails closed to deferred when the policy state cannot be resolved', function (string $body) {
    $tmp = tempnam(sys_get_temp_dir(), 'tfs-policy-');
    file_put_contents($tmp, $body);

    try {
        foreach (['schedule', 'push', 'workflow_dispatch'] as $event) {
            $r = tfsResolve($event, [
                'policy_file' => $tmp,
                'ref' => 'refs/heads/'.TFS_BASE_BRANCH,
                'run_full_suite' => true,
                'override' => true,
            ]);

            expect($r['policy_status'])->toBe('UNRESOLVED')
                ->and($r['full_suite_authorized'])->toBe('false', "event {$event} must fail closed")
                ->and($r['full_suite_defer_reason'])->toBe('POLICY_STATE_UNRESOLVED_FAIL_CLOSED');
        }
    } finally {
        @unlink($tmp);
    }
})->with([
    'empty file' => [''],
    'no status key' => ['{"policy_id":"X"}'],
    'unknown status' => ['{"status":"PAUSED"}'],
    'ambiguous duplicate status' => ['{"status":"ACTIVE","other":{"status":"RETIRED"}}'],
    'not json at all' => ['totally corrupt <<<'],
]);

it('fails closed when the policy state file is missing entirely', function () {
    $r = tfsResolve('schedule', ['policy_file' => '/nonexistent/full-suite-policy.json']);

    expect($r['policy_status'])->toBe('UNRESOLVED')
        ->and($r['full_suite_authorized'])->toBe('false')
        ->and($r['policy_source'])->toBe('fail-closed-default');
});

it('treats a push whose base branch cannot be proven as still governed', function () {
    // Unable to prove it is NOT the base push -> stay deferred. Never widen.
    $r = tfsResolve('push', ['ref' => 'refs/heads/something', 'base_branch' => '']);

    expect($r['full_suite_authorized'])->toBe('false');
});

/*
|--------------------------------------------------------------------------
| Retirement must restore the previous cadence — exactly, and only then
|--------------------------------------------------------------------------
*/

it('restores the automatic cadence only once the policy is explicitly RETIRED', function () {
    $tmp = tempnam(sys_get_temp_dir(), 'tfs-policy-');
    file_put_contents($tmp, '{"policy_id":"GLOBAL-TEMPORARY-FULL-SUITE-POLICY","status":"RETIRED"}');

    try {
        $schedule = tfsResolve('schedule', ['policy_file' => $tmp]);
        $push = tfsResolve('push', ['policy_file' => $tmp, 'ref' => 'refs/heads/'.TFS_BASE_BRANCH]);
        $dispatch = tfsResolve('workflow_dispatch', ['policy_file' => $tmp, 'run_full_suite' => true]);
        $pr = tfsResolve('pull_request', ['policy_file' => $tmp]);

        expect($schedule['full_suite_authorized'])->toBe('true')
            ->and($schedule['full_suite_defer_reason'])->toBe('POLICY_RETIRED_SCHEDULED_RUN')
            ->and($push['full_suite_authorized'])->toBe('true')
            ->and($dispatch['full_suite_authorized'])->toBe('true')
            // A PR never ran the Full Suite before this policy, and must not start now.
            ->and($pr['full_suite_authorized'])->toBe('false');
    } finally {
        @unlink($tmp);
    }
});

/*
|--------------------------------------------------------------------------
| The workflow itself
|--------------------------------------------------------------------------
*/

it('gates the Full Suite job on the resolved authorisation', function () {
    $gate = (string) tfsWorkflow()['jobs']['full_suite_gate']['if'];

    expect($gate)->toContain("needs.classify.outputs.full_suite_authorized == 'true'")
        ->and(tfsWorkflow()['jobs']['full_suite_gate']['needs'])->toContain('classify');
});

it('offers the explicit override input, defaulting to off', function () {
    $inputs = tfsWorkflow()['on']['workflow_dispatch']['inputs'];

    expect($inputs)->toHaveKey('full_suite_policy_override')
        ->and($inputs['full_suite_policy_override']['default'])->toBeFalse()
        ->and($inputs['run_full_suite']['default'])->toBeFalse();
});

it('publishes the deferral reason on every run from the always-on classifier', function () {
    $classify = tfsWorkflow()['jobs']['classify'];

    // classify always runs and is GitHub-hosted, so a skipped Full Suite job
    // still leaves a machine-readable record of WHY it was skipped.
    expect($classify['outputs'])->toHaveKey('full_suite_authorized')
        ->and($classify['outputs'])->toHaveKey('full_suite_defer_reason')
        ->and($classify['runs-on'])->toBe('ubuntu-latest')
        ->and(tfsWorkflowRaw())->toContain('resolve-full-suite-policy.sh');
});

it('keeps the Full Suite deferred, never deleted', function () {
    $raw = tfsWorkflowRaw();
    $on = tfsWorkflow()['on'];

    // CICDCTRL-R004 / R012: the triggers and the job survive; only the timing moved.
    expect($on)->toHaveKey('schedule')
        ->and($on)->toHaveKey('workflow_dispatch')
        ->and(tfsWorkflow()['jobs'])->toHaveKey('full_suite_gate')
        ->and($raw)->toContain('Run full Pest suite')
        // NSF-9 / CICDCTRL-R003: suppressing the Full Suite must not disable CI.
        ->and(tfsWorkflow()['jobs'])->toHaveKeys(['quality_gate', 'release_safety_gate', 'nsf10_release_evidence_gate'])
        ->and($raw)->not->toContain('paths-ignore');
});

it('preserves step-level Full Suite evidence semantics', function () {
    $steps = collect(tfsWorkflow()['jobs']['full_suite_gate']['steps'])
        ->pluck('name')
        ->filter()
        ->all();

    // Policy s7.1: a green JOB proves nothing; only the executing STEP does.
    // Both branches must survive so that evidence stays readable at step level.
    expect($steps)->toContain('Run full Pest suite')
        ->and(collect($steps)->contains(fn ($n) => str_contains((string) $n, 'Note skipped full suite')))->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Governance + the CICD-CTRL-1 runtime-control invariant
|--------------------------------------------------------------------------
*/

it('keeps the CI runtime control governance GO with the policy enforced', function () {
    $result = app(CiRuntimeControlGovernanceService::class)->collect();

    expect($result['decision'])->toBe('GO')
        ->and($result['temporary_full_suite_policy_ok'])->toBeTrue()
        ->and($result['temporary_full_suite_policy_status'])->toBe('ACTIVE')
        ->and($result['temporary_full_suite_policy_active'])->toBeTrue()
        // The structural requirement that at least one fallback trigger lives on.
        ->and($result['full_suite_fallback'])->toBeTrue();

    $ids = collect($result['rules'])->pluck('id');
    expect($ids)->toContain('CICDCTRL-R012', 'CICDCTRL-R013', 'CICDCTRL-R014');
});

it('detects a workflow that stops honouring the policy', function () {
    $scanner = app(CiRuntimeControlScanner::class);

    expect($scanner->temporaryFullSuitePolicyPosture()['ok'])->toBeTrue();

    // Point the contract at a workflow that lacks the authorisation wiring: the
    // scanner must fail rather than quietly report a policy that isn't enforced.
    $decoy = tempnam(sys_get_temp_dir(), 'tfs-wf-');
    file_put_contents($decoy, "jobs:\n  full_suite_gate:\n    if: github.event_name == 'schedule'\n");

    try {
        config()->set('ci_runtime_control.files.ci_workflow', str_replace(base_path().'/', '', $decoy));
        $posture = app(CiRuntimeControlScanner::class)->temporaryFullSuitePolicyPosture();

        expect($posture['ok'])->toBeFalse()
            ->and($posture['workflow_wired'])->toBeFalse();
    } finally {
        @unlink($decoy);
    }
});

it('passes CI context to the resolver through env, never the command line', function () {
    $step = collect(tfsWorkflow()['jobs']['classify']['steps'])
        ->firstWhere('id', 'full_suite_policy');

    // A ref is attacker-influenceable text. Interpolating it into `run:` would
    // let a crafted branch name terminate the quote and join the command — the
    // same convention the runner-routing step already follows.
    expect($step)->not->toBeNull()
        ->and($step['env'])->toHaveKeys(['EVENT_NAME', 'EVENT_REF', 'DISPATCH_RUN_FULL_SUITE', 'DISPATCH_POLICY_OVERRIDE'])
        ->and($step['run'])->not->toContain('github.ref')
        ->and($step['run'])->not->toContain('github.event_name')
        ->and($step['run'])->not->toContain('inputs.run_full_suite')
        ->and($step['run'])->not->toContain('inputs.full_suite_policy_override');
});

it('keeps the resolver read-only and incapable of running the suite', function () {
    $resolver = (string) file_get_contents(base_path('scripts/ci/resolve-full-suite-policy.sh'));

    expect($resolver)->toContain('set -euo pipefail')
        ->and($resolver)->not->toContain('artisan test')
        ->and($resolver)->not->toContain('vendor/bin/pest');

    // bash -n proves it parses; CI runs this exact file.
    expect(Process::path(base_path())->run(['bash', '-n', 'scripts/ci/resolve-full-suite-policy.sh'])->successful())
        ->toBeTrue();
});

it('leaves the CICD-CTRL-1 classifier safety invariant untouched', function () {
    // This sprint narrows WHEN the Full Suite may run. It must not touch which
    // profiles may skip the critical gate.
    expect(config('ci_runtime_control.skip_critical_profiles'))->toBe(['docs_only'])
        ->and(config('ci_runtime_control.default_profile'))->toBe('unknown_high_risk')
        ->and(config('ci_runtime_control.always_on_jobs'))
        ->toBe(['quality_gate', 'release_safety_gate', 'nsf10_release_evidence_gate']);
});
