<?php

use App\Services\Foundation\ReleaseEvidenceService;
use App\Services\Foundation\ReleaseSafetyService;
use App\Support\Cicd\SelfHostedRunnerScanner;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Yaml\Yaml;

uses()->group('Cicd', 'Ci', 'SelfHostedRunner', 'FoundationGovernance');

/*
 * CICD-CTRL-3B — the NSF-9 and NSF-10 release gates must fail closed.
 *
 * CICD-CTRL-3A fixed the health gate and pinned the pipeline contract, but the
 * scanner that enforces it only ran against the steps named in
 * `ci_runner.strict_pipeline_steps` — and the release gates were not among
 * them. Seven producers in NSF-9/NSF-10 were still written as
 * `producer | tee evidence` under GitHub's default shell, which is `bash -e`
 * WITHOUT `-o pipefail`. A pipeline reports the status of its LAST command, so
 * every one of those steps discarded its producer's exit status.
 *
 * That matters because of what non-zero MEANS for these producers. Each one
 * returns FAILURE for exactly one reason: the governance decision is FAIL. GO
 * and WATCH both exit 0. So the masked status did not hide a flaky command — it
 * turned a release-blocking NO-GO into a green required check.
 *
 * These tests execute the REAL step bodies extracted from the workflow, with
 * the producer swapped for a stub whose exit status is chosen by the test. That
 * is deliberate: asserting `shell: bash` appears in the YAML would pass for a
 * step that still swallows the status. The only proof that counts is running
 * the step and observing what it returns.
 */

/** Absolute path to the Foundation Evidence Gates workflow. */
function nsfWorkflowPath(): string
{
    return base_path('.github/workflows/foundation-evidence-gates.yml');
}

/** Parsed workflow document. */
function nsfWorkflow(): array
{
    return Yaml::parseFile(nsfWorkflowPath());
}

/**
 * The `run:` body of a named step inside a named job.
 */
function nsfStepBody(string $job, string $stepName): string
{
    foreach (nsfWorkflow()['jobs'][$job]['steps'] ?? [] as $step) {
        if (($step['name'] ?? null) === $stepName) {
            return (string) ($step['run'] ?? '');
        }
    }

    throw new RuntimeException("step '{$stepName}' not found in job '{$job}'");
}

/** The declared `shell:` of a named step, or null when it relies on the default. */
function nsfStepShell(string $job, string $stepName): ?string
{
    foreach (nsfWorkflow()['jobs'][$job]['steps'] ?? [] as $step) {
        if (($step['name'] ?? null) === $stepName) {
            return $step['shell'] ?? null;
        }
    }

    throw new RuntimeException("step '{$stepName}' not found in job '{$job}'");
}

/**
 * The argv GitHub Actions uses to execute a step, given its declared `shell`.
 *
 * This distinction is the whole point of the sprint, so the harness must honour
 * it rather than assume it: a step that declares `shell: bash` runs under
 * `-eo pipefail`, and a step that declares nothing runs under GitHub's default
 * `bash -e` with NO pipefail. Forcing pipefail here would make a masked step
 * look protected and the negative tests below would prove nothing.
 *
 * @return list<string>
 */
function nsfShellArgv(?string $declaredShell): array
{
    return $declaredShell === 'bash'
        ? ['bash', '--noprofile', '--norc', '-eo', 'pipefail', '-c']
        : ['bash', '-e', '-c'];
}

/**
 * Execute a real step body with its `php artisan ...` producer stubbed.
 *
 * The body is run under the shell the step itself declares. The producer line
 * is replaced by a subshell that writes to stdout and exits with the requested
 * status, so the evidence path, the pipe and the status handling are all the
 * ones the workflow actually ships.
 *
 * @return array{0: int, 1: string, 2: string} [exitCode, stdout+stderr, evidencePath]
 */
function runNsfStepBody(string $job, string $stepName, int $producerExit): array
{
    $body = nsfStepBody($job, $stepName);

    // The evidence path is whatever the real step tees into; redirect it into a
    // temporary directory so the test never writes into the repository.
    preg_match('/\|\s*tee\s+(\S+)/', $body, $m);
    expect($m[1] ?? null)->not->toBeNull("step '{$stepName}' no longer tees into an evidence file");

    $workdir = sys_get_temp_dir().'/ctl3b-'.bin2hex(random_bytes(6));
    mkdir($workdir.'/storage/ci-evidence', 0o777, true);
    $evidence = $workdir.'/'.$m[1];

    // Swap the producer for a stub with a chosen exit status. Everything else in
    // the body — the pipe, PIPESTATUS capture, and final exit — is untouched.
    $stub = '(echo "STUB-PRODUCER-OUTPUT"; exit '.$producerExit.')';
    $patched = preg_replace('/php artisan [^\n|]*/', $stub.' ', $body, 1);

    $argv = nsfShellArgv(nsfStepShell($job, $stepName));
    $argv[] = (string) $patched;

    $result = Process::path($workdir)->env(['PATH' => getenv('PATH')])->run($argv);

    return [$result->exitCode(), $result->output().$result->errorOutput(), $evidence];
}

/**
 * Every producer piped into tee inside the two release gates.
 *
 * @return list<array{0: string, 1: string}> [job, step name]
 */
function nsfBlockingSteps(): array
{
    return [
        ['release_safety_gate', 'Foundation roadmap check'],
        ['release_safety_gate', 'Feature flags governance'],
        ['release_safety_gate', 'Release safety check'],
        ['release_safety_gate', 'Automated smoke (command-readiness only, no base URL in CI)'],
        ['nsf10_release_evidence_gate', 'Capture NSF-10 release evidence (ci profile)'],
        ['nsf10_release_evidence_gate', 'Check NSF-10 release evidence (ci profile)'],
        ['nsf10_release_evidence_gate', 'Release safety check (ci profile)'],
    ];
}

// ---------------------------------------------------------------------------
// The defect, pinned
// ---------------------------------------------------------------------------

it('pins that the default Actions shell masks a producer failure piped into tee', function () {
    // GitHub's default shell for `run:` on Linux is `bash -e {0}` — `-e` but NOT
    // `-o pipefail`. This is the exact mechanism that hid seven NO-GO decisions.
    $result = Process::run(['bash', '-e', '-c', '(exit 1) | tee /dev/null; echo "masked=$?"']);

    expect($result->exitCode())->toBe(0)
        ->and($result->output())->toContain('masked=0');
});

// ---------------------------------------------------------------------------
// Negative contract — a failing producer must fail the step
// ---------------------------------------------------------------------------

it('fails the step when the producer fails, for every NSF-9 and NSF-10 gate step', function (string $job, string $step) {
    [$code, $output, $evidence] = runNsfStepBody($job, $step, producerExit: 1);

    expect($code)->not->toBe(0, "step '{$step}' swallowed a failing producer");

    // Evidence must survive the failure — observability is not traded for it.
    expect(file_exists($evidence))->toBeTrue("step '{$step}' lost its evidence file on failure")
        ->and(file_get_contents($evidence))->toContain('STUB-PRODUCER-OUTPUT')
        ->and($output)->toContain('STUB-PRODUCER-OUTPUT');
})->with(nsfBlockingSteps());

it('propagates the producer exit status verbatim rather than a generic failure', function () {
    // A non-zero status that is not 1 must still arrive as non-zero, and as the
    // producer's own code — the gate reports what the governance command said.
    [$code] = runNsfStepBody('nsf10_release_evidence_gate', 'Check NSF-10 release evidence (ci profile)', producerExit: 3);

    expect($code)->toBe(3);
});

// ---------------------------------------------------------------------------
// Positive contract — a passing producer must still pass, with evidence
// ---------------------------------------------------------------------------

it('passes the step and writes evidence when the producer succeeds', function (string $job, string $step) {
    [$code, $output, $evidence] = runNsfStepBody($job, $step, producerExit: 0);

    expect($code)->toBe(0, "step '{$step}' failed on a successful producer")
        ->and(file_exists($evidence))->toBeTrue("step '{$step}' did not write its evidence file")
        ->and(file_get_contents($evidence))->toContain('STUB-PRODUCER-OUTPUT')
        ->and($output)->toContain('STUB-PRODUCER-OUTPUT');
})->with(nsfBlockingSteps());

// ---------------------------------------------------------------------------
// Producer semantics — non-zero means NO-GO, and it is real
// ---------------------------------------------------------------------------

it('decides FAIL on the ci evidence chain when the required artifacts are absent', function () {
    // The seam used for the real NSF-10 Actions negative proof: skip the capture
    // step, so the required ci artifacts never exist and the chain decides FAIL.
    // The command turns that decision into a non-zero exit (asserted below), so
    // proving the decision here proves the seam.
    $empty = 'storage/ci-evidence-absent-'.bin2hex(random_bytes(6));
    config()->set('release_evidence.profiles.ci.directory', $empty);

    $report = app(ReleaseEvidenceService::class)->check('ci');

    expect($report['summary']['decision'])->toBe('FAIL');
});

it('maps a FAIL decision to a non-zero exit in every release gate command', function (string $command) {
    // GO and WATCH exit 0; only FAIL exits non-zero. That is what makes a
    // swallowed status dangerous rather than merely untidy.
    $source = file_get_contents(base_path($command));

    expect($source)->toContain("=== 'FAIL' ? self::FAILURE : self::SUCCESS");
})->with([
    'app/Console/Commands/FoundationReleaseSafetyCheckCommand.php',
    'app/Console/Commands/ReleaseEvidenceCheckCommand.php',
    'app/Console/Commands/ReleaseEvidenceCaptureCommand.php',
    'app/Console/Commands/ReleaseAutomatedSmokeCommand.php',
    'app/Console/Commands/FoundationFeatureFlagsListCommand.php',
]);

it('treats a missing deploy gate file as a release-safety FAIL', function () {
    // The seam used for the real NSF-9 Actions negative proof, asserted through
    // the service rather than by moving a file on this machine.
    $missing = collect(config('release_safety.deploy_gate_files'))
        ->reject(fn (string $path) => is_file(base_path($path)));

    expect($missing)->toBeEmpty('deploy gate files must all exist on a healthy checkout');

    config()->set('release_safety.deploy_gate_files.backup_script', 'scripts/does-not-exist.sh');

    $report = app(ReleaseSafetyService::class)->collect('local');

    expect($report['summary']['decision'])->toBe('FAIL');
});

// ---------------------------------------------------------------------------
// Governance registry — the gap that let this survive CICD-CTRL-3A
// ---------------------------------------------------------------------------

it('registers every NSF-9 and NSF-10 blocking step as a strict pipeline step', function () {
    $registered = (array) config('ci_runner.strict_pipeline_steps');

    foreach (nsfBlockingSteps() as [$job, $step]) {
        // `toContain` is variadic — extra arguments are additional needles, not
        // a failure message — so the reason goes in an explicit assertion.
        expect(in_array($step, $registered, true))
            ->toBeTrue("step '{$step}' in '{$job}' is not covered by the scanner");
    }
});

it('reports no unprotected pipeline step anywhere in the workflow', function () {
    $posture = app(SelfHostedRunnerScanner::class)->pipelineExitPosture();

    expect($posture['unprotected'])->toBe([])
        ->and($posture['issues'])->toBe([])
        ->and($posture['ok'])->toBeTrue();
});

it('declares shell bash on every strict pipeline step so pipefail is in force', function (string $job, string $step) {
    expect(nsfStepShell($job, $step))->toBe('bash');
})->with(nsfBlockingSteps());

// ---------------------------------------------------------------------------
// No weakening — the failure must not be recovered by hiding it
// ---------------------------------------------------------------------------

it('never weakens a release gate with continue-on-error or a swallowed status', function (string $job) {
    $definition = nsfWorkflow()['jobs'][$job];

    expect($definition['continue-on-error'] ?? false)->toBeFalse();

    foreach ($definition['steps'] as $step) {
        $label = (string) ($step['name'] ?? $step['uses'] ?? 'unnamed step');

        expect($step['continue-on-error'] ?? false)
            ->toBeFalse("step '{$label}' is allowed to fail silently");

        $body = (string) ($step['run'] ?? '');
        expect(str_contains($body, '|| true'))
            ->toBeFalse("step '{$label}' swallows a failure with || true");
    }
})->with(['release_safety_gate', 'nsf10_release_evidence_gate']);

// ---------------------------------------------------------------------------
// Downstream gating — a failed prerequisite must not become a green successor
// ---------------------------------------------------------------------------

it('runs NSF-9 only when a critical gate variant genuinely succeeded', function () {
    $job = nsfWorkflow()['jobs']['release_safety_gate'];

    // Both variants are required as `needs` because exactly one runs and the
    // other is skipped; the condition demands that one actually succeeded, so a
    // failing critical gate still blocks NSF-9.
    expect($job['needs'])->toContain('critical_test_gate')
        ->and($job['needs'])->toContain('critical_test_gate_self_hosted')
        ->and($job['if'])->toContain("needs.critical_test_gate.result == 'success'")
        ->and($job['if'])->toContain("needs.critical_test_gate_self_hosted.result == 'success'");
});

it('runs NSF-10 only when NSF-9 genuinely succeeded', function () {
    $job = nsfWorkflow()['jobs']['nsf10_release_evidence_gate'];

    // A failing NSF-9 leaves NSF-10 skipped rather than green: it can never run,
    // so it can never report a passing release-evidence result over a failed
    // release-safety result.
    expect($job['needs'])->toBe('release_safety_gate')
        ->and($job['if'])->toContain("needs.release_safety_gate.result == 'success'");
});
