<?php

use App\Support\Cicd\SelfHostedRunnerScanner;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Yaml\Yaml;

uses()->group('Cicd', 'Ci', 'SelfHostedRunner', 'FoundationGovernance');

/*
 * CICD-CTRL-3C — generated runtime evidence must describe what actually ran.
 *
 * The NSF-R011 self-hosted evidence summary asserted a container engine and
 * image unconditionally. On the dedicated runner — which has no container
 * engine installed and executes the host PHP directly — the uploaded artifact
 * therefore claimed a container runtime while the same job's log reported
 * `runtime_mode=native` and `container_engine=none` two steps earlier. The
 * artifact is the thing quoted in closure reports, so the artifact is the one
 * that must not lie.
 *
 * CICD-CTRL-3A had already introduced the canonical resolver and banned two
 * hard-coded engine literals, but this third site used different wording and
 * slipped through. These tests therefore assert the BEHAVIOUR of the shipped
 * step, not the presence of a string: the real `run:` body is extracted from
 * the workflow and executed against a stubbed resolver, once per runtime mode.
 * A test that only checked for `--print-runtime` in the YAML would pass for a
 * step that printed it and then contradicted it on the next line.
 */

/** The workflow document under test. */
function rtWorkflow(): array
{
    return Yaml::parseFile(base_path('.github/workflows/foundation-evidence-gates.yml'));
}

/** The `run:` body of a named step inside a named job. */
function rtStepBody(string $job, string $stepName): string
{
    foreach (rtWorkflow()['jobs'][$job]['steps'] ?? [] as $step) {
        if (($step['name'] ?? null) === $stepName) {
            return (string) ($step['run'] ?? '');
        }
    }

    throw new RuntimeException("step '{$stepName}' not found in job '{$job}'");
}

/**
 * Runtime resolver output for each mode, copied from the canonical resolver's
 * own contract (scripts/ci/self-hosted-php.sh --print-runtime).
 *
 * @return array<string, string>
 */
function rtResolverOutput(string $mode): string
{
    return match ($mode) {
        'native' => "runtime_mode=native\ncontainer_engine=none\nphp_source=host\nphp_version=8.3\n",
        'container' => "runtime_mode=container\ncontainer_engine=podman\ncontainer_rootless=true\n"
            ."php_source=image\ncontainer_image=localhost/daengtisia-ci-php:8.3\n",
        'unsatisfied' => "runtime_mode=unsatisfied\ncontainer_engine=none\nphp_source=none\n",
        default => throw new InvalidArgumentException("unknown mode {$mode}"),
    };
}

/**
 * Execute the REAL evidence-summary step body against a stubbed resolver and
 * return the artifact it wrote.
 *
 * The stub stands in for the wrapper at the exact relative path the step calls,
 * so the step's own invocation — not a paraphrase of it — is what runs.
 */
function rtGeneratedEvidence(string $mode): string
{
    $body = rtStepBody('critical_test_gate_self_hosted', 'Write NSF-R011 critical evidence summary');

    $workdir = tempArtifactDir('ctl3c-', 0o777);
    mkdir($workdir.'/scripts/ci', 0o777, true);
    mkdir($workdir.'/storage/ci-evidence', 0o777, true);

    file_put_contents(
        $workdir.'/scripts/ci/self-hosted-php.sh',
        "#!/usr/bin/env bash\nif [ \"\$1\" = \"--print-runtime\" ]; then\n"
        .'cat <<'."'EOF'\n".rtResolverOutput($mode)."EOF\n"
        ."exit 0\nfi\nexit 0\n",
    );
    chmod($workdir.'/scripts/ci/self-hosted-php.sh', 0o755);

    // GitHub expands ${{ ... }} before the shell sees it; substitute inert
    // stand-ins so the body is executable outside Actions.
    $script = preg_replace('/\$\{\{[^}]*\}\}/', 'test-value', $body);

    $result = Process::path($workdir)
        ->env(['PATH' => getenv('PATH'), 'REQUIRED_PHP' => '8.3'])
        ->run(['bash', '--noprofile', '--norc', '-eo', 'pipefail', '-c', (string) $script]);

    expect($result->exitCode())->toBe(0, 'evidence step failed: '.$result->errorOutput());

    return (string) file_get_contents($workdir.'/storage/ci-evidence/nsf-r011-critical-tests.txt');
}

/**
 * Runtime claims that cannot coexist. Returns the contradictions found.
 *
 * @return list<string>
 */
function rtContradictions(string $evidence): array
{
    $found = [];

    $saysNative = str_contains($evidence, 'runtime_mode=native');
    $saysNoEngine = str_contains($evidence, 'container_engine=none');
    $namesPodman = preg_match('/container_engine=podman|rootless[- ]podman|engine=podman/i', $evidence) === 1;
    $saysContainerMode = str_contains($evidence, 'runtime_mode=container');
    $namesImage = str_contains($evidence, 'container_image=');

    if ($saysNative && $namesPodman) {
        $found[] = 'claims native mode and names a container engine';
    }

    if ($saysNoEngine && $namesPodman) {
        $found[] = 'claims no container engine and names one';
    }

    if ($saysNative && $saysContainerMode) {
        $found[] = 'claims both native and container mode';
    }

    if ($saysNative && $namesImage) {
        $found[] = 'claims native mode and names a container image';
    }

    if ($saysContainerMode && $saysNoEngine) {
        $found[] = 'claims container mode with no container engine';
    }

    return $found;
}

// ---------------------------------------------------------------------------
// Native mode — the runtime the dedicated runner actually uses
// ---------------------------------------------------------------------------

it('reports native runtime facts when the resolver resolves native', function () {
    $evidence = rtGeneratedEvidence('native');

    expect($evidence)->toContain('runtime_mode=native')
        ->and($evidence)->toContain('container_engine=none')
        ->and($evidence)->toContain('php_source=host');
});

it('never claims a container engine in native mode', function () {
    $evidence = rtGeneratedEvidence('native');

    // The exact false claim that shipped, plus its near variants.
    expect(str_contains($evidence, 'rootless-podman'))->toBeFalse()
        ->and(str_contains($evidence, 'rootless podman'))->toBeFalse()
        ->and(str_contains($evidence, 'container_engine=podman'))->toBeFalse()
        ->and(str_contains($evidence, 'container_image='))->toBeFalse();
});

// ---------------------------------------------------------------------------
// Container mode — the fix must not flatten every runner to "native"
// ---------------------------------------------------------------------------

it('reports container runtime facts when the resolver resolves container mode', function () {
    $evidence = rtGeneratedEvidence('container');

    expect($evidence)->toContain('runtime_mode=container')
        ->and($evidence)->toContain('container_engine=podman')
        ->and($evidence)->toContain('php_source=image')
        ->and($evidence)->toContain('container_image=localhost/daengtisia-ci-php:8.3');
});

it('does not hard-code native either — the evidence follows the resolver', function () {
    $native = rtGeneratedEvidence('native');
    $container = rtGeneratedEvidence('container');

    expect($native)->not->toBe($container)
        ->and(str_contains($container, 'runtime_mode=native'))->toBeFalse()
        ->and(str_contains($native, 'runtime_mode=container'))->toBeFalse();
});

// ---------------------------------------------------------------------------
// Internal consistency
// ---------------------------------------------------------------------------

it('emits internally consistent runtime evidence in every resolvable mode', function (string $mode) {
    expect(rtContradictions(rtGeneratedEvidence($mode)))->toBe([]);
})->with(['native', 'container', 'unsatisfied']);

it('detects a contradiction when one is present', function () {
    // Guards the guard: the shipped artifact that triggered this sprint claimed
    // native mode in its log while the artifact named a container runtime.
    $contradictory = "runtime_mode=native\ncontainer_engine=none\nruntime=rootless-podman image:8.3\n";

    expect(rtContradictions($contradictory))->not->toBe([]);
});

// ---------------------------------------------------------------------------
// The step derives rather than asserts
// ---------------------------------------------------------------------------

it('derives the evidence from the canonical resolver, not a literal', function () {
    $body = rtStepBody('critical_test_gate_self_hosted', 'Write NSF-R011 critical evidence summary');

    expect($body)->toContain('--print-runtime')
        ->and(str_contains($body, 'rootless-podman'))->toBeFalse();
});

it('uses one runtime resolver across health, assertion, smoke and evidence', function () {
    $steps = [
        'Assert authoritative PHP runtime (CICD-CTRL-3)',
        'Self-hosted runner smoke evidence (CICD-CTRL-3)',
        'Write NSF-R011 critical evidence summary',
    ];

    foreach ($steps as $step) {
        expect(rtStepBody('critical_test_gate_self_hosted', $step))
            ->toContain('scripts/ci/self-hosted-php.sh');
    }

    // No second detection mechanism was introduced alongside it.
    $workflow = file_get_contents(base_path('.github/workflows/foundation-evidence-gates.yml'));
    expect(preg_match('/^\s*[A-Z_]*ENGINE=.*podman/m', (string) $workflow))->toBe(0);
});

// ---------------------------------------------------------------------------
// Governance registry
// ---------------------------------------------------------------------------

it('bans the hard-coded engine literal that shipped', function () {
    expect(config('ci_runner.runtime_evidence.forbidden_markers'))
        ->toContain('runtime='.'rootless-podman');
});

it('keeps legitimate resolved container output out of the forbidden list', function () {
    // Banning the resolver's own output would break a real container runner.
    $markers = (array) config('ci_runner.runtime_evidence.forbidden_markers');

    foreach (['container_engine=podman', 'runtime_mode=container', 'php_source=image'] as $legitimate) {
        expect(in_array($legitimate, $markers, true))
            ->toBeFalse("resolved output '{$legitimate}' must not be forbidden");
    }
});

it('reports a clean runtime evidence posture for the shipped workflow', function () {
    $posture = app(SelfHostedRunnerScanner::class)->runtimeEvidencePosture();

    expect($posture['forbidden_present'])->toBe([])
        ->and($posture['issues'])->toBe([])
        ->and($posture['ok'])->toBeTrue();
});
