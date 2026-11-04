<?php

use App\Support\Cicd\SelfHostedRunnerScanner;
use Symfony\Component\Yaml\Yaml;

uses()->group('Cicd', 'Ci', 'ScannerAgent');

/**
 * DMS-POSTMIGRATION-PR-INTEGRATION-1 — the scanner agent's security tests are
 * part of a REQUIRED CI gate, and cannot quietly stop being one.
 *
 * The agent is a separate Node program, so its tests never run under Pest. They
 * run in the always-on NSF-R012 quality gate through `npm run test:js`. Three
 * independent edits could each have removed that protection without any test
 * noticing: dropping the agent glob from `test:js`, skipping or softening the
 * step, or removing the step's `shell: bash` (the step pipes into tee, so
 * without pipefail a failing agent test reports green). Each is pinned here.
 */
function scannerAgentQualityGateJob(): array
{
    $workflow = Yaml::parseFile(base_path('.github/workflows/foundation-evidence-gates.yml'));
    $job = $workflow['jobs']['quality_gate'] ?? null;

    expect($job)->not->toBeNull('The NSF-R012 quality gate job is missing.');

    return $job;
}

function scannerAgentJsTestStep(): array
{
    $step = collect(scannerAgentQualityGateJob()['steps'] ?? [])
        ->first(fn (array $step): bool => ($step['name'] ?? '') === 'JavaScript unit tests');

    expect($step)->not->toBeNull('The quality gate no longer has a "JavaScript unit tests" step.');

    return $step;
}

it('includes the scanner agent tests in the test:js script', function () {
    $package = json_decode((string) file_get_contents(base_path('package.json')), true, 512, JSON_THROW_ON_ERROR);
    $script = (string) ($package['scripts']['test:js'] ?? '');

    expect($script)->toContain('tools/daengtisia-scanner-agent/test/*.test.js');

    // The glob must actually reach the suites that prove the origin policy.
    $matched = array_map('basename', glob(base_path('tools/daengtisia-scanner-agent/test/*.test.js')) ?: []);
    foreach (['explicit-allowlist.test.js', 'trusted-origin.test.js', 'security.test.js'] as $suite) {
        expect(in_array($suite, $matched, true))->toBeTrue("{$suite} is not matched by the test:js glob");
    }
});

it('runs test:js in the always-on quality gate, unconditionally and without soft failure', function () {
    $job = scannerAgentQualityGateJob();
    $step = scannerAgentJsTestStep();

    // CICD-CTRL-1: the quality gate is always-on — no job-level condition.
    expect($job)->not->toHaveKey('if')
        ->and($job['continue-on-error'] ?? false)->toBeFalse();

    expect((string) ($step['run'] ?? ''))->toContain('npm run test:js')
        ->and($step)->not->toHaveKey('if')
        ->and($step['continue-on-error'] ?? false)->toBeFalse();
});

it('propagates a failing agent test through the tee pipe', function () {
    $step = scannerAgentJsTestStep();

    // `npm run test:js | tee` reports tee's status unless pipefail is on.
    expect($step['shell'] ?? null)->toBe('bash');

    // And the step is registered, so the pipeline-exit scanner keeps checking it.
    expect(in_array('JavaScript unit tests', (array) config('ci_runner.strict_pipeline_steps'), true))
        ->toBeTrue('the JS test step is not a registered strict pipeline step');

    $posture = app(SelfHostedRunnerScanner::class)->pipelineExitPosture();
    expect($posture['checked'])->toContain('JavaScript unit tests')
        ->and($posture['unprotected'])->toBe([]);
});
