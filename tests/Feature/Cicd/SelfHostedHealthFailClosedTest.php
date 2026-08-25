<?php

use App\Services\Foundation\SelfHostedRunnerGovernanceService;
use App\Support\Cicd\SelfHostedRunnerScanner;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Process\ExecutableFinder;

uses()->group('Cicd', 'Ci', 'SelfHostedRunner', 'FoundationGovernance');

/*
 * CICD-CTRL-3A — the self-hosted health gate must fail closed, verify the
 * database the job actually uses, and describe the runtime that actually ran.
 *
 * These are regression tests for three defects that a real Actions run on the
 * dedicated runner exposed, none of which source inspection had caught:
 *
 *  1. `health.sh | tee` reported tee's exit status, so DECISION: NO-GO went
 *     green and migrations and Pest ran anyway.
 *  2. The health script defaulted to a port the workflow never sets, so the
 *     PostgreSQL major-version check never executed.
 *  3. Runtime evidence named Podman on a host that has none.
 *
 * Everything below runs the real scripts. Nothing modifies the host: the
 * database probe is redirected through a stub `psql` on PATH, and production
 * artefacts are simulated by pointing HOME at a temporary directory.
 */

/** Run a shell snippet and return [exitCode, output]. */
function runShell(string $script, array $env = []): array
{
    $result = Process::path(base_path())->env($env)->run(['bash', '-c', $script]);

    return [$result->exitCode(), $result->output().$result->errorOutput()];
}

/**
 * Build a throwaway bin directory containing a stub `psql`.
 *
 * The health check probes the CI database with `psql -tAc 'SHOW server_version'`.
 * Stubbing that one binary lets the PostgreSQL major-version logic be exercised
 * deterministically, on any machine, without a live server and without touching
 * the real one.
 */
function stubPsqlBin(string $reportedVersion): string
{
    // FIX-TEST-TEMPFILE-SIBLING-LEAKS-1 — this stub PATH must outlive the
    // function so the child process can read it, so the registry owns it. It
    // previously had NO cleanup on any path at all.
    $dir = tempArtifactDir('ctl3a-bin-', 0o777);

    file_put_contents($dir.'/psql', <<<SH
        #!/usr/bin/env bash
        for arg in "\$@"; do
            if [ "\$arg" = "--version" ]; then
                echo "psql (PostgreSQL) {$reportedVersion}"
                exit 0
            fi
        done
        echo "{$reportedVersion}"
        exit 0
        SH);
    chmod($dir.'/psql', 0o755);

    return $dir;
}

/**
 * Build a throwaway bin directory that is the ENTIRE PATH for a runtime probe.
 *
 * `resolve_mode` in `auto` mode branches on which binaries PATH can reach, so a
 * probe pointed at the host's real bin directories asserts whatever that host
 * happens to ship. GitHub-hosted images include /usr/bin/podman, so "no
 * container engine available" silently became "podman available" there and the
 * fail-closed branch was never exercised — the assertion passed on the
 * dedicated runner and on a developer machine, and failed only in the full
 * suite. Naming the reachable commands explicitly makes every branch behave
 * identically on any host, with or without a container engine installed.
 *
 * `bash` and `php` are symlinked to the real binaries because the probe must
 * run the genuine interpreter. Anything in $stubs is written as a shell stub,
 * which lets a test make a command reachable without installing it.
 *
 * @param  array<string, string>  $stubs  command name => bash body
 */
function stubRuntimeBin(array $stubs = []): string
{
    $dir = tempArtifactDir('ctl3d-bin-', 0o777);

    $real = [
        'bash' => (new ExecutableFinder)->find('bash', '/bin/bash'),
        'php' => PHP_BINARY,
    ];

    foreach ($real as $name => $target) {
        symlink($target, $dir.'/'.$name);
    }

    foreach ($stubs as $name => $body) {
        file_put_contents($dir.'/'.$name, "#!/usr/bin/env bash\n".$body."\n");
        chmod($dir.'/'.$name, 0o755);
    }

    return $dir;
}

/** Environment that isolates the health check from this developer machine. */
function healthEnv(array $overrides = []): array
{
    $home = tempArtifactDir('ctl3a-home-', 0o777);
    mkdir($home.'/.ssh', 0o777, true);

    return array_merge([
        'HOME' => $home,
        'APP_ENV' => 'testing',
        // Match whatever PHP is running the suite: this test is about the gate's
        // decision logic, not about this machine's PHP version.
        'CI_RUNNER_PHP_VERSION' => PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION,
        'DB_HOST' => '127.0.0.1',
        'DB_PORT' => '5432',
        'DB_DATABASE' => 'daengtisia_ci',
        'DB_USERNAME' => 'daengtisia_ci_user',
        'DB_PASSWORD' => 'stub-not-a-real-credential',
    ], $overrides);
}

// ---------------------------------------------------------------------------
// Defect A — the pipeline exit contract
// ---------------------------------------------------------------------------

it('proves a bare producer-into-tee pipeline masks the producer failure', function () {
    // This is the defect itself, pinned. GitHub's default shell is `bash -e`
    // WITHOUT pipefail, so the pipeline reports tee's status and the producer's
    // failure disappears.
    [$code, $out] = runShell('bash -e -c \'(exit 1) | tee /dev/null\'; echo "masked=$?"');

    expect($code)->toBe(0)
        ->and($out)->toContain('masked=0');
});

it('propagates a failing producer through tee under the workflow pattern', function () {
    // The pattern the health step now uses: shell bash + PIPESTATUS + explicit exit.
    $pattern = <<<'SH'
        set -eo pipefail
        set +e
        (echo "DECISION: NO-GO"; exit 1) | tee /dev/null
        STATUS="${PIPESTATUS[0]}"
        set -e
        exit "$STATUS"
        SH;

    [$code, $out] = runShell($pattern);

    expect($code)->not->toBe(0)
        ->and($out)->toContain('DECISION: NO-GO');
});

it('still succeeds through tee when the producer succeeds', function () {
    $pattern = <<<'SH'
        set -eo pipefail
        set +e
        (echo "DECISION: GO"; exit 0) | tee /dev/null
        STATUS="${PIPESTATUS[0]}"
        set -e
        exit "$STATUS"
        SH;

    [$code, $out] = runShell($pattern);

    expect($code)->toBe(0)
        ->and($out)->toContain('DECISION: GO');
});

it('preserves the evidence while propagating the failure', function () {
    // Evidence must not be sacrificed to recover the exit status.
    // Registry-owned so the evidence survives an assertion failure below
    // without surviving the test run.
    $evidence = tempArtifactFile('ctl3a-evidence-');

    $pattern = <<<SH
        set -eo pipefail
        set +e
        (echo "DECISION: NO-GO"; exit 1) | tee -a "{$evidence}"
        STATUS="\${PIPESTATUS[0]}"
        set -e
        exit "\$STATUS"
        SH;

    [$code] = runShell($pattern);
    $written = (string) file_get_contents($evidence);
    @unlink($evidence);

    expect($code)->not->toBe(0)
        ->and($written)->toContain('DECISION: NO-GO');
});

it('runs the real health-check step pattern from the workflow and fails closed on NO-GO', function () {
    // Force a NO-GO by presenting a production SSH key, then run the exact
    // shell pattern the workflow step uses around the real script.
    $home = tempArtifactDir('ctl3a-home-', 0o777);
    mkdir($home.'/.ssh', 0o777, true);
    file_put_contents($home.'/.ssh/daengtisiams_vps_ed25519', "not-a-real-key\n");

    $pattern = <<<'SH'
        set -eo pipefail
        set +e
        bash scripts/ci/self-hosted-runner-health.sh 2>&1 | tee -a /dev/null
        HEALTH_STATUS="${PIPESTATUS[0]}"
        set -e
        echo "runner_health_exit_status=${HEALTH_STATUS}"
        exit "$HEALTH_STATUS"
        SH;

    [$code, $out] = runShell($pattern, healthEnv(['HOME' => $home]));

    expect($code)->not->toBe(0)
        ->and($out)->toContain('DECISION: NO-GO')
        ->and($out)->toContain('production_isolation')
        ->and($out)->toContain('runner_health_exit_status=1');
});

// ---------------------------------------------------------------------------
// Defect A — governance detects an unprotected step
// ---------------------------------------------------------------------------

it('flags a safety-critical step that pipes into tee without propagating the status', function () {
    $workflow = <<<'YAML'
        jobs:
          demo:
            steps:
              - name: Runner health check (CICD-CTRL-3)
                run: |
                  bash scripts/ci/self-hosted-runner-health.sh 2>&1 | tee -a "$GITHUB_STEP_SUMMARY"
        YAML;

    // The scanner resolves workflow paths through base_path(), so the fixture
    // lives inside the project rather than in the system temp directory.
    $rel = 'storage/framework/ctl3a-wf-'.bin2hex(random_bytes(6)).'.yml';
    file_put_contents(base_path($rel), $workflow);

    config()->set('ci_runner.files.ci_workflow', $rel);
    config()->set('ci_runner.strict_pipeline_steps', ['Runner health check (CICD-CTRL-3)']);

    try {
        $posture = (new SelfHostedRunnerScanner)->pipelineExitPosture();
    } finally {
        // FIX-TEST-TEMPFILE-SIBLING-LEAKS-1 — the fixture lives in the project
        // tree, so the temp-artifact registry cannot own it; a `finally` is the
        // owner instead. Previously a throw above stranded it in the repository.
        @unlink(base_path($rel));
    }

    expect($posture['ok'])->toBeFalse()
        ->and($posture['unprotected'])->toContain('Runner health check (CICD-CTRL-3)');
});

it('accepts a safety-critical step that declares shell bash', function () {
    $workflow = <<<'YAML'
        jobs:
          demo:
            steps:
              - name: Runner health check (CICD-CTRL-3)
                shell: bash
                run: |
                  set +e
                  bash scripts/ci/self-hosted-runner-health.sh 2>&1 | tee -a "$GITHUB_STEP_SUMMARY"
                  HEALTH_STATUS="${PIPESTATUS[0]}"
                  set -e
                  exit "$HEALTH_STATUS"
        YAML;

    $rel = 'storage/framework/ctl3a-wf-'.bin2hex(random_bytes(6)).'.yml';
    file_put_contents(base_path($rel), $workflow);

    config()->set('ci_runner.files.ci_workflow', $rel);
    config()->set('ci_runner.strict_pipeline_steps', ['Runner health check (CICD-CTRL-3)']);

    try {
        $posture = (new SelfHostedRunnerScanner)->pipelineExitPosture();
    } finally {
        @unlink(base_path($rel));
    }

    expect($posture['ok'])->toBeTrue()
        ->and($posture['unprotected'])->toBe([]);
});

it('keeps the real workflow protected on every safety-critical step', function () {
    $posture = (new SelfHostedRunnerScanner)->pipelineExitPosture();

    expect($posture['unprotected'])->toBe([])
        ->and($posture['ok'])->toBeTrue()
        ->and($posture['checked'])->toContain('Runner health check (CICD-CTRL-3)');
});

// ---------------------------------------------------------------------------
// Defect B — one database contract, and the PostgreSQL major check really runs
// ---------------------------------------------------------------------------

it('probes the database port the job actually uses', function () {
    $bin = stubPsqlBin('16.14');

    [, $out] = runShell(
        'bash scripts/ci/self-hosted-runner-health.sh',
        healthEnv(['DB_PORT' => '5432', 'PATH' => $bin.':'.getenv('PATH')])
    );

    expect($out)->toContain('127.0.0.1:5432')
        ->and($out)->not->toContain('127.0.0.1:5433');
});

it('lets an explicit runner override win over the job database port', function () {
    $bin = stubPsqlBin('16.14');

    [, $out] = runShell(
        'bash scripts/ci/self-hosted-runner-health.sh',
        healthEnv(['DB_PORT' => '5432', 'CI_RUNNER_DB_PORT' => '5433', 'PATH' => $bin.':'.getenv('PATH')])
    );

    expect($out)->toContain('127.0.0.1:5433');
});

it('executes the PostgreSQL major-version check and passes on a matching major', function () {
    $bin = stubPsqlBin('16.14');

    [, $out] = runShell(
        'bash scripts/ci/self-hosted-runner-health.sh',
        healthEnv(['PATH' => $bin.':'.getenv('PATH')])
    );

    expect($out)->toContain('ci_database_version')
        ->and($out)->toContain('PostgreSQL 16 matches');
});

it('fails closed when the PostgreSQL major does not match the authoritative gate', function () {
    // The aishrunner divergence class: a different PG major produced
    // self-hosted-only failures. It must now be caught, not assumed away.
    $bin = stubPsqlBin('18.2');

    [$code, $out] = runShell(
        'bash scripts/ci/self-hosted-runner-health.sh',
        healthEnv(['PATH' => $bin.':'.getenv('PATH')])
    );

    expect($code)->not->toBe(0)
        ->and($out)->toContain('ci_database_version')
        ->and($out)->toContain('does not match')
        ->and($out)->toContain('DECISION: NO-GO');
});

it('fails closed when the CI database is unreachable', function () {
    $bin = tempArtifactDir('ctl3a-bin-', 0o777);
    file_put_contents($bin.'/psql', "#!/usr/bin/env bash\nexit 2\n");
    chmod($bin.'/psql', 0o755);

    [$code, $out] = runShell(
        'bash scripts/ci/self-hosted-runner-health.sh',
        healthEnv(['PATH' => $bin.':'.getenv('PATH')])
    );

    expect($code)->not->toBe(0)
        ->and($out)->toContain('is not reachable')
        ->and($out)->toContain('DECISION: NO-GO');
});

// ---------------------------------------------------------------------------
// Security fail-closed behaviour (no host posture is weakened to test this)
// ---------------------------------------------------------------------------

it('fails closed when the runtime user is in a root-equivalent group', function () {
    $bin = stubPsqlBin('16.14');

    // Declare a group this user really is in as forbidden, rather than adding
    // the user to a privileged group.
    $ownGroup = trim((string) shell_exec('id -gn'));

    [$code, $out] = runShell(
        'bash scripts/ci/self-hosted-runner-health.sh',
        healthEnv(['CI_RUNNER_FORBIDDEN_GROUPS' => $ownGroup, 'PATH' => $bin.':'.getenv('PATH')])
    );

    expect($code)->not->toBe(0)
        ->and($out)->toContain("group_{$ownGroup}")
        ->and($out)->toContain('DECISION: NO-GO');
});

it('fails closed when a production SSH key is present on the runner', function () {
    $bin = stubPsqlBin('16.14');
    $home = tempArtifactDir('ctl3a-home-', 0o777);
    mkdir($home.'/.ssh', 0o777, true);
    file_put_contents($home.'/.ssh/daengtisiams_deploy', "not-a-real-key\n");

    [$code, $out] = runShell(
        'bash scripts/ci/self-hosted-runner-health.sh',
        healthEnv(['HOME' => $home, 'PATH' => $bin.':'.getenv('PATH')])
    );

    expect($code)->not->toBe(0)
        ->and($out)->toContain('Production artifact PRESENT')
        ->and($out)->toContain('DECISION: NO-GO');
});

// ---------------------------------------------------------------------------
// Defect C — runtime evidence describes what actually ran
// ---------------------------------------------------------------------------

it('reports the native runtime without claiming a container engine', function () {
    [$code, $out] = runShell(
        'bash scripts/ci/self-hosted-php.sh --print-runtime',
        ['CI_RUNTIME_MODE' => 'native', 'CI_RUNNER_PHP_VERSION' => PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION]
    );

    expect($code)->toBe(0)
        ->and($out)->toContain('runtime_mode=native')
        ->and($out)->toContain('container_engine=none')
        ->and($out)->toContain('php_source=host')
        ->and($out)->not->toContain('podman');
});

it('fails and reports an unsatisfied runtime rather than describing it as usable', function () {
    // The only combination that may report `unsatisfied`: the host PHP does not
    // match the authoritative version AND no container engine is reachable.
    // Both halves are stated explicitly — an earlier revision passed PATH
    // '/usr/bin:/bin' and so depended on the host not shipping podman.
    $bin = stubRuntimeBin();

    [$code, $out] = runShell(
        'bash scripts/ci/self-hosted-php.sh --print-runtime',
        ['CI_RUNTIME_MODE' => 'auto', 'CI_RUNNER_PHP_VERSION' => '5.6', 'PATH' => $bin]
    );

    expect($code)->not->toBe(0)
        ->and($out)->toContain('runtime_mode=unsatisfied')
        ->and($out)->toContain('container_engine=none')
        ->and($out)->toContain('php_source=none');
});

it('falls back to the container runtime when the host PHP is unsuitable but podman is reachable', function () {
    // The complement of the assertion above, and the reason it must be written
    // as a pair: making the fail-closed branch deterministic must not silently
    // disable the legitimate container fallback. Same unsuitable host PHP, one
    // difference — podman is reachable — so `auto` must resolve to a container.
    $bin = stubRuntimeBin(['podman' => 'echo true']);

    [$code, $out] = runShell(
        'bash scripts/ci/self-hosted-php.sh --print-runtime',
        ['CI_RUNTIME_MODE' => 'auto', 'CI_RUNNER_PHP_VERSION' => '5.6', 'PATH' => $bin]
    );

    expect($code)->toBe(0)
        ->and($out)->toContain('runtime_mode=container')
        ->and($out)->toContain('container_engine=podman')
        ->and($out)->toContain('php_source=image')
        ->and($out)->not->toContain('runtime_mode=unsatisfied');
});

it('never asserts a fixed container engine in the workflow evidence', function () {
    $posture = (new SelfHostedRunnerScanner)->runtimeEvidencePosture();

    expect($posture['ok'])->toBeTrue()
        ->and($posture['forbidden_present'])->toBe([]);
});

it('flags workflow evidence that hard-codes a container engine', function () {
    $workflow = <<<'YAML'
        jobs:
          demo:
            steps:
              - name: Self-hosted runner smoke evidence (CICD-CTRL-3)
                run: |
                  echo "engine=rootless podman, image=${CI_PHP_IMAGE}"
        YAML;

    $rel = 'storage/framework/ctl3a-wf-'.bin2hex(random_bytes(6)).'.yml';
    file_put_contents(base_path($rel), $workflow);

    config()->set('ci_runner.files.ci_workflow', $rel);

    try {
        $posture = (new SelfHostedRunnerScanner)->runtimeEvidencePosture();
    } finally {
        @unlink(base_path($rel));
    }

    expect($posture['ok'])->toBeFalse()
        ->and($posture['forbidden_present'])->toContain('engine=rootless podman');
});

it('keeps the self-hosted runner governance decision GO', function () {
    $result = app(SelfHostedRunnerGovernanceService::class)->collect();

    expect($result['decision'])->toBe('GO')
        ->and($result['pipeline_exit_ok'])->toBeTrue()
        ->and($result['runtime_evidence_ok'])->toBeTrue();
});
