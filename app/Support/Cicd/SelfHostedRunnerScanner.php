<?php

namespace App\Support\Cicd;

/**
 * CICD-CTRL-3 — Dedicated Self-Hosted CI Runner scanner.
 *
 * Read-only. Verifies the Foundation Evidence Gates workflow, the deploy
 * workflow, the runner health script, and the production-database guard honour
 * the contract declared in config/ci_runner.php:
 *
 *  - Heavy self-hosted jobs target the full, project-specific label set; a bare
 *    `self-hosted` runs-on (which any runner on the account could satisfy) is
 *    forbidden.
 *  - The classifier stays on GitHub-hosted infrastructure so a dead self-hosted
 *    runner can never stop the routing decision from being made.
 *  - Exactly one critical-gate variant runs for any routing combination, so an
 *    outage queues the gate instead of letting it silently pass.
 *  - Every DB-heavy job asserts a non-production database BEFORE migrating.
 *  - Deployment never runs on a general CI runner.
 *  - The runner health script is non-destructive and checks production
 *    isolation.
 *
 * The scanner only reads files and config; it never runs a command or writes.
 */
class SelfHostedRunnerScanner
{
    private function readFile(string $key): ?string
    {
        $path = (string) config("ci_runner.files.{$key}", '');
        if ($path === '') {
            return null;
        }

        $full = base_path($path);
        if (! is_file($full)) {
            return null;
        }

        return (string) file_get_contents($full);
    }

    /**
     * The declared contract is internally coherent.
     *
     * @return array{ok: bool, issues: list<string>, required_labels: list<string>, default_mode: string}
     */
    public function contractPosture(): array
    {
        $issues = [];

        $labels = array_values((array) config('ci_runner.required_labels', []));
        $custom = (string) config('ci_runner.custom_label', '');

        if ($labels === []) {
            $issues[] = 'no required runner labels are declared';
        }

        if ($custom === '') {
            $issues[] = 'no project-specific custom label is declared';
        } elseif (! in_array($custom, $labels, true)) {
            $issues[] = "custom label '{$custom}' is missing from the required label set";
        }

        if (! in_array('self-hosted', $labels, true)) {
            $issues[] = "required label set is missing 'self-hosted'";
        }

        $defaultMode = (string) config('ci_runner.runner_mode.default', '');
        if ($defaultMode !== 'github-hosted') {
            $issues[] = "runner_mode default must be 'github-hosted' (fail-safe), got '{$defaultMode}'";
        }

        $alwaysGithub = (array) config('ci_runner.always_github_hosted_jobs', []);
        foreach (['classify', 'deploy'] as $mustStay) {
            if (! in_array($mustStay, $alwaysGithub, true)) {
                $issues[] = "job '{$mustStay}' must be pinned to GitHub-hosted infrastructure";
            }
        }

        return [
            'ok' => $issues === [],
            'issues' => $issues,
            'required_labels' => $labels,
            'default_mode' => $defaultMode,
        ];
    }

    /**
     * The CI workflow wires the routing safely.
     *
     * @return array{ok: bool, exists: bool, issues: list<string>, missing_markers: list<string>, forbidden_present: list<string>}
     */
    public function workflowPosture(): array
    {
        $issues = [];
        $missing = [];
        $forbidden = [];

        $workflow = $this->readFile('ci_workflow');
        if ($workflow === null) {
            return [
                'ok' => false,
                'exists' => false,
                'issues' => ['CI workflow file is missing'],
                'missing_markers' => [],
                'forbidden_present' => [],
            ];
        }

        foreach ((array) config('ci_runner.required_workflow_markers', []) as $marker) {
            if (! is_string($marker) || $marker === '') {
                continue;
            }
            if (! str_contains($workflow, $marker)) {
                $missing[] = $marker;
                $issues[] = "required workflow marker missing: {$marker}";
            }
        }

        foreach ((array) config('ci_runner.forbidden_workflow_markers', []) as $marker) {
            if (! is_string($marker) || $marker === '') {
                continue;
            }
            if (str_contains($workflow, $marker)) {
                $forbidden[] = $marker;
                $issues[] = "forbidden workflow marker present: {$marker}";
            }
        }

        // The heavy job must target the complete label set, not a bare label.
        $labels = (array) config('ci_runner.required_labels', []);
        $labelLine = '['.implode(', ', $labels).']';
        if (! str_contains($workflow, $labelLine)) {
            $issues[] = "no job targets the full required label set {$labelLine}";
        }

        // The classifier must never move off GitHub-hosted infrastructure.
        if (preg_match('/^\s{2}classify:\s*$.*?^\s{4}runs-on:\s*(.+)$/ms', $workflow, $m) === 1) {
            if (! str_contains($m[1], 'ubuntu-')) {
                $issues[] = 'the classify job must stay on a GitHub-hosted runner';
            }
        } else {
            $issues[] = 'unable to determine the classify job runner';
        }

        // Deployment / rollback must never be invoked from CI.
        foreach ((array) config('ci_runner.forbidden_ci_production_commands', []) as $command) {
            if (is_string($command) && $command !== '' && str_contains($workflow, $command)) {
                $issues[] = "production command '{$command}' must never run from the CI workflow";
            }
        }

        // Every job that migrates must guard the database first.
        foreach ($this->jobsMigratingWithoutGuard($workflow) as $job) {
            $issues[] = "job '{$job}' runs migrations without asserting a non-production database first";
        }

        /*
         * Exactly one critical-gate variant runs and the other is skipped.
         * GitHub skips any job whose `needs` were skipped, so a downstream gate
         * that depends on a single variant would silently disappear under one
         * routing mode. Downstream gates must therefore depend on both variants
         * and tolerate a skipped sibling.
         */
        if (str_contains($workflow, 'critical_test_gate_self_hosted')) {
            foreach (['release_safety_gate', 'nsf10_release_evidence_gate'] as $downstream) {
                if (! str_contains($workflow, "{$downstream}:")) {
                    continue;
                }
                if (! str_contains($workflow, '!cancelled()')) {
                    $issues[] = "downstream gate '{$downstream}' can be silently skipped when a critical-gate variant is skipped";
                    break;
                }
            }
        }

        return [
            'ok' => $issues === [],
            'exists' => true,
            'issues' => $issues,
            'missing_markers' => $missing,
            'forbidden_present' => $forbidden,
        ];
    }

    /**
     * Deployment stays off the general CI runner.
     *
     * @return array{ok: bool, issues: list<string>}
     */
    public function deployIsolationPosture(): array
    {
        $issues = [];

        $deploy = $this->readFile('deploy_workflow');
        if ($deploy === null) {
            return ['ok' => false, 'issues' => ['deploy workflow file is missing']];
        }

        $custom = (string) config('ci_runner.custom_label', 'daengtisia-ci');
        if (str_contains($deploy, $custom)) {
            $issues[] = "the deploy workflow must never target the '{$custom}' CI runner";
        }

        if (str_contains($deploy, 'self-hosted')) {
            $issues[] = 'the deploy workflow must never run on a self-hosted runner';
        }

        return ['ok' => $issues === [], 'issues' => $issues];
    }

    /**
     * The runner health script exists, is non-destructive, and checks isolation.
     *
     * @return array{ok: bool, exists: bool, issues: list<string>}
     */
    /**
     * Safety-critical steps must not let a pipe swallow a failure.
     *
     * `producer | tee evidence` reports tee's status, so a producer that exits
     * non-zero renders as a green step. The runner health check shipped that
     * way and a real run proved it: `DECISION: NO-GO`, exit 1, step success,
     * migrations and Pest executed anyway.
     *
     * A step is considered protected when it declares `shell: bash` (adding
     * `-o pipefail`) or captures PIPESTATUS explicitly. Scoped to the configured
     * safety-critical steps so this reports a real gating defect rather than
     * every incidental log pipe in the workflow.
     *
     * @return array{ok: bool, issues: list<string>, unprotected: list<string>, checked: list<string>}
     */
    public function pipelineExitPosture(): array
    {
        $issues = [];
        $unprotected = [];
        $checked = [];

        $workflow = $this->readFile('ci_workflow');
        if ($workflow === null) {
            return ['ok' => false, 'issues' => ['CI workflow file is missing'], 'unprotected' => [], 'checked' => []];
        }

        foreach ($this->workflowSteps($workflow) as $step) {
            if (! in_array($step['name'], (array) config('ci_runner.strict_pipeline_steps', []), true)) {
                continue;
            }

            $checked[] = $step['name'];

            if (! str_contains($step['body'], '| tee')) {
                // Nothing piped, nothing to mask.
                continue;
            }

            $declaresBashShell = preg_match('/^\s*shell:\s*bash\s*$/m', $step['body']) === 1;
            $capturesPipeStatus = str_contains($step['body'], 'PIPESTATUS');

            if (! $declaresBashShell && ! $capturesPipeStatus) {
                $unprotected[] = $step['name'];
                $issues[] = "step '{$step['name']}' pipes into tee without propagating the producer exit status; "
                    .'declare `shell: bash` or capture PIPESTATUS';
            }
        }

        foreach ((array) config('ci_runner.strict_pipeline_steps', []) as $required) {
            if (is_string($required) && $required !== '' && ! in_array($required, $checked, true)) {
                $issues[] = "strict-pipeline step '{$required}' was not found in the workflow";
            }
        }

        return ['ok' => $issues === [], 'issues' => $issues, 'unprotected' => $unprotected, 'checked' => $checked];
    }

    /**
     * Runtime evidence must be derived from the resolved mode, never asserted.
     *
     * @return array{ok: bool, issues: list<string>, forbidden_present: list<string>}
     */
    public function runtimeEvidencePosture(): array
    {
        $issues = [];
        $forbiddenPresent = [];

        $workflow = $this->readFile('ci_workflow');
        if ($workflow === null) {
            return ['ok' => false, 'issues' => ['CI workflow file is missing'], 'forbidden_present' => []];
        }

        $contract = (array) config('ci_runner.runtime_evidence', []);

        $required = (string) ($contract['required_marker'] ?? '');
        if ($required !== '' && ! str_contains($workflow, $required)) {
            $issues[] = "runtime evidence must be derived from the wrapper via '{$required}'";
        }

        foreach ((array) ($contract['forbidden_markers'] ?? []) as $marker) {
            if (is_string($marker) && $marker !== '' && str_contains($workflow, $marker)) {
                $forbiddenPresent[] = $marker;
                $issues[] = "runtime evidence asserts a fixed engine: '{$marker}'";
            }
        }

        // The wrapper has to be able to answer the question the workflow asks.
        $wrapper = $this->readFile('ci_runtime_wrapper');
        if ($wrapper === null) {
            $issues[] = 'the CI runtime wrapper is missing';
        } elseif ($required !== '' && ! str_contains($wrapper, $required)) {
            $issues[] = "the CI runtime wrapper does not implement '{$required}'";
        }

        return ['ok' => $issues === [], 'issues' => $issues, 'forbidden_present' => $forbiddenPresent];
    }

    /**
     * The critical gate selects the CI system's own regression tests.
     *
     * CICD-CTRL-3D. The critical filter is a fixed allowlist, so a test suite
     * that is not named in it never runs in any required gate — it waits for
     * the three-and-a-half-hour full suite. That is how a broken
     * tests/Feature/Cicd assertion reached the base branch with every required
     * gate green. Both critical variants are checked: the coverage must hold
     * whichever runner the job routes to.
     *
     * @return array{ok: bool, exists: bool, issues: list<string>, required: list<string>, critical_filters: list<string>}
     */
    public function criticalGateSelfTestPosture(): array
    {
        $required = array_values(array_filter(array_map(
            'strval',
            (array) config('ci_runner.critical_gate_required_filters', [])
        )));

        $workflow = $this->readFile('ci_workflow');

        if ($workflow === null) {
            return [
                'ok' => false,
                'exists' => false,
                'issues' => ['the CI workflow is missing'],
                'required' => $required,
                'critical_filters' => [],
            ];
        }

        $issues = [];

        if ($required === []) {
            $issues[] = 'no critical-gate filter coverage is declared';
        }

        // Every `--filter='...'` in the workflow, narrowed to the critical
        // gate's own filter by its anchor token. Matching on the anchor rather
        // than on job names keeps this working if a variant is renamed.
        preg_match_all('/--filter=\'([^\']+)\'/', $workflow, $matches);
        $allFilters = $matches[1] ?? [];
        $criticalFilters = array_values(array_filter(
            $allFilters,
            static fn (string $filter): bool => str_contains($filter, 'FoundationGovernance')
        ));

        $variants = count((array) config('ci_runner.self_hosted_heavy_jobs', [])) + 1;

        if (count($criticalFilters) < $variants) {
            $issues[] = sprintf(
                'expected %d critical gate filter(s), found %d — a variant is missing its test filter',
                $variants,
                count($criticalFilters)
            );
        }

        foreach ($criticalFilters as $index => $filter) {
            foreach ($required as $token) {
                if (! str_contains($filter, $token)) {
                    $issues[] = "critical gate filter #{$index} does not select '{$token}'";
                }
            }
        }

        return [
            'ok' => $issues === [],
            'exists' => true,
            'issues' => $issues,
            'required' => $required,
            'critical_filters' => $criticalFilters,
        ];
    }

    /**
     * Split a workflow into its named steps.
     *
     * @return list<array{name: string, body: string}>
     */
    private function workflowSteps(string $workflow): array
    {
        $lines = preg_split('/\r?\n/', $workflow) ?: [];
        $steps = [];
        $current = null;

        foreach ($lines as $line) {
            if (preg_match('/^\s+- name:\s*(.+?)\s*$/', $line, $m) === 1) {
                if ($current !== null) {
                    $steps[] = $current;
                }
                $current = ['name' => trim($m[1]), 'body' => ''];

                continue;
            }

            if ($current !== null) {
                $current['body'] .= $line."\n";
            }
        }

        if ($current !== null) {
            $steps[] = $current;
        }

        return $steps;
    }

    public function healthScriptPosture(): array
    {
        $issues = [];

        $script = $this->readFile('runner_health_script');
        if ($script === null) {
            return ['ok' => false, 'exists' => false, 'issues' => ['runner health script is missing']];
        }

        if (! str_contains($script, 'set -euo pipefail')) {
            $issues[] = 'health script must fail fast (set -euo pipefail)';
        }

        foreach (['production_isolation', 'ci_database', 'runtime_user', 'disk_free', 'ram_available'] as $check) {
            if (! str_contains($script, $check)) {
                $issues[] = "health script is missing the '{$check}' check";
            }
        }

        /*
         * Mandatory root-equivalence checks, and — critically — proof that they
         * are evaluated unconditionally.
         *
         * A previous revision nested the docker-group check inside
         * `if have podman`, so a host without Podman emitted no group finding at
         * all. A missing tool must never suppress a security finding, so every
         * mandatory group check has to appear BEFORE the first container-runtime
         * probe in the script, outside any runtime branch.
         */
        /*
         * Locate the container-runtime probe as an EXECUTABLE line, not a raw
         * substring: the script documents the original defect in its own
         * comments, and a comment mentioning the probe would otherwise skew
         * every ordering comparison below.
         */
        $runtimeProbe = preg_match('/^[ \t]*if\b[^\n]*podman/m', $script, $probeMatch, PREG_OFFSET_CAPTURE) === 1
            ? $probeMatch[0][1]
            : false;

        $mandatoryGroups = ['docker', 'sudo', 'lxd'];

        // The emission loop itself must sit ahead of any container-runtime probe.
        $groupLoop = strpos($script, 'for group in $FORBIDDEN_GROUPS');

        if ($groupLoop === false) {
            $issues[] = 'health script does not evaluate forbidden-group membership in an unconditional loop';
        } elseif ($runtimeProbe !== false && $groupLoop > $runtimeProbe) {
            $issues[] = 'health script evaluates forbidden-group membership behind a container-runtime probe; '
                .'root-equivalence checks must run unconditionally';
        }

        // The loop names its groups through a variable, so the mandatory set is
        // verified against the declared default rather than by literal search.
        if (preg_match('/FORBIDDEN_GROUPS="\$\{CI_RUNNER_FORBIDDEN_GROUPS:-([^}]*)\}"/', $script, $matches) === 1) {
            $declared = preg_split('/\s+/', trim($matches[1])) ?: [];

            foreach ($mandatoryGroups as $group) {
                if (! in_array($group, $declared, true)) {
                    $issues[] = "health script default forbidden-group list is missing '{$group}'";
                }
            }
        } else {
            $issues[] = 'health script does not declare a default forbidden-group list';
        }

        // Belt and braces: catch the original defect shape too, where a group
        // check was written literally inside the container-runtime branch.
        foreach ($mandatoryGroups as $group) {
            $literal = strpos($script, "group_{$group}");

            if ($literal !== false && $runtimeProbe !== false && $literal > $runtimeProbe) {
                $issues[] = "health script evaluates 'group_{$group}' behind a container-runtime probe; "
                    .'root-equivalence checks must run unconditionally';
            }
        }

        // The health check reports; it never repairs.
        foreach (['rm -rf /', 'chmod 777', 'migrate:fresh', 'db:wipe', 'DROP DATABASE'] as $destructive) {
            if (str_contains($script, $destructive)) {
                $issues[] = "health script must be non-destructive; found '{$destructive}'";
            }
        }

        return ['ok' => $issues === [], 'exists' => true, 'issues' => $issues];
    }

    /**
     * The authoritative CI runtime is pinned, rootless, and complete.
     *
     * The runner host cannot supply the authoritative PHP version, so the
     * self-hosted variant runs inside a pinned container image. This verifies
     * the image definition and its wrapper honour the contract.
     *
     * @return array{ok: bool, issues: list<string>, digest_pinned: bool}
     */
    public function ciRuntimePosture(): array
    {
        $issues = [];
        $runtime = (array) config('ci_runner.ci_runtime', []);

        /*
         * The contract is semantic runtime equivalence, not a specific engine.
         * A host whose OS ships the authoritative PHP satisfies it natively; a
         * host whose OS does not needs the pinned container image. Both are
         * legitimate, so the posture check validates the declared mode set
         * rather than demanding one engine.
         */
        $allowedModes = array_values(array_filter(
            (array) ($runtime['allowed_modes'] ?? []),
            fn ($mode): bool => is_string($mode) && $mode !== ''
        ));
        $mode = (string) ($runtime['mode'] ?? '');

        if ($allowedModes === []) {
            $issues[] = 'the CI runtime must declare its allowed modes';
        }

        if ($mode !== '' && $mode !== 'auto' && ! in_array($mode, $allowedModes, true)) {
            $issues[] = "the configured CI runtime mode '{$mode}' is not in the allowed mode set";
        }

        // Docker is root-equivalent and is forbidden however the runtime is supplied.
        if (in_array('docker', $allowedModes, true)) {
            $issues[] = 'docker must never be an allowed CI runtime mode';
        }

        // When a container mode is offered it must be rootless Podman.
        if (in_array('podman', $allowedModes, true)) {
            if (($runtime['engine'] ?? '') !== 'podman') {
                $issues[] = 'the container CI runtime engine must be podman';
            }

            if (($runtime['rootless'] ?? false) !== true) {
                $issues[] = 'the container CI runtime must be declared rootless';
            }
        }

        // docker and lxd both grant root-equivalent control of the host; sudo is
        // direct escalation. None may ever be held by the runner service user.
        foreach (['docker', 'sudo', 'lxd'] as $group) {
            if (! in_array($group, (array) ($runtime['forbidden_service_user_groups'] ?? []), true)) {
                $issues[] = "group '{$group}' must be forbidden for the runner service user";
            }
        }

        $containerfilePath = (string) ($runtime['containerfile'] ?? '');
        $containerfile = $containerfilePath !== '' && is_file(base_path($containerfilePath))
            ? (string) file_get_contents(base_path($containerfilePath))
            : null;

        $digestPinned = false;

        if ($containerfile === null) {
            $issues[] = 'the CI runtime Containerfile is missing';
        } else {
            // A floating tag would let the authoritative runtime drift silently.
            $digestPinned = preg_match('/^FROM\s+\S+@sha256:[0-9a-f]{64}/mi', $containerfile) === 1;
            if (($runtime['require_digest_pin'] ?? true) && ! $digestPinned) {
                $issues[] = 'the CI runtime base image must be pinned by digest, not a floating tag';
            }

            $phpVersion = (string) config('ci_runner.required_php_version', '');
            if ($phpVersion !== '' && ! str_contains($containerfile, "php:{$phpVersion}")) {
                $issues[] = "the CI runtime image must be built from PHP {$phpVersion}";
            }

            foreach ((array) ($runtime['required_extensions'] ?? []) as $extension) {
                if (! str_contains($containerfile, (string) $extension)) {
                    $issues[] = "the CI runtime image does not declare required extension '{$extension}'";
                }
            }

            foreach ((array) ($runtime['required_binaries'] ?? []) as $binary) {
                if (! str_contains($containerfile, (string) $binary)) {
                    $issues[] = "the CI runtime image does not declare required binary '{$binary}'";
                }
            }
        }

        $wrapperPath = (string) ($runtime['wrapper_script'] ?? '');
        $wrapper = $wrapperPath !== '' && is_file(base_path($wrapperPath))
            ? (string) file_get_contents(base_path($wrapperPath))
            : null;

        if ($wrapper === null) {
            $issues[] = 'the CI runtime wrapper script is missing';
        } else {
            if (! str_contains($wrapper, 'set -euo pipefail')) {
                $issues[] = 'the CI runtime wrapper must fail fast (set -euo pipefail)';
            }
            // keep-id maps the container user to the host service user, which is
            // what stops the workspace accumulating root-owned residue.
            if (! str_contains($wrapper, '--userns=keep-id')) {
                $issues[] = 'the CI runtime wrapper must map the container user to the host service user (--userns=keep-id)';
            }
            if (! str_contains($wrapper, 'podman run')) {
                $issues[] = 'the CI runtime wrapper must execute the container mode through podman';
            }
            if (preg_match('/^\s*(exec\s+)?docker\s/m', $wrapper) === 1) {
                $issues[] = 'the CI runtime wrapper must never invoke docker';
            }

            // Every declared mode must actually be implemented by the wrapper.
            foreach ($allowedModes as $allowedMode) {
                if (! str_contains($wrapper, $allowedMode)) {
                    $issues[] = "the CI runtime wrapper does not implement the '{$allowedMode}' mode";
                }
            }

            /*
             * Equivalence is the whole point: whichever mode runs, the wrapper
             * must prove the PHP version and extension set before executing, and
             * must never silently fall back to a mismatched PHP.
             */
            if (! str_contains($wrapper, 'REQUIRED_PHP_VERSION')) {
                $issues[] = 'the CI runtime wrapper must assert the authoritative PHP version before running';
            }

            if (! str_contains($wrapper, 'REQUIRED_EXTENSIONS')) {
                $issues[] = 'the CI runtime wrapper must assert the authoritative extension set before running';
            }
        }

        return ['ok' => $issues === [], 'issues' => $issues, 'digest_pinned' => $digestPinned];
    }

    /**
     * The production-database guard is strict enough to be worth having.
     *
     * @return array{ok: bool, issues: list<string>}
     */
    public function databaseGuardPosture(): array
    {
        $issues = [];
        $guard = (array) config('ci_runner.database_guard', []);

        $allowedEnvs = (array) ($guard['allowed_app_envs'] ?? []);
        if ($allowedEnvs !== ['testing']) {
            $issues[] = 'CI must only permit APP_ENV=testing';
        }

        $allowedHosts = (array) ($guard['allowed_hosts'] ?? []);
        if ($allowedHosts === []) {
            $issues[] = 'no allowed CI database hosts declared';
        }
        foreach ($allowedHosts as $host) {
            if (! in_array((string) $host, ['127.0.0.1', 'localhost', '::1', 'postgres'], true)) {
                $issues[] = "CI database host '{$host}' is not local; a CI database must be local";
            }
        }

        if ((array) ($guard['denied_databases'] ?? []) === []) {
            $issues[] = 'no production databases are explicitly denied';
        }

        $deniedSubstrings = array_map('strtolower', (array) ($guard['denied_database_substrings'] ?? []));
        foreach ((array) ($guard['allowed_databases'] ?? []) as $allowed) {
            foreach ($deniedSubstrings as $needle) {
                if ($needle !== '' && str_contains(strtolower((string) $allowed), $needle)) {
                    $issues[] = "allowed CI database '{$allowed}' contains the production marker '{$needle}'";
                }
            }
        }

        return ['ok' => $issues === [], 'issues' => $issues];
    }

    /**
     * Names of jobs that run migrations without a preceding guard step.
     *
     * @return list<string>
     */
    private function jobsMigratingWithoutGuard(string $workflow): array
    {
        $offenders = [];

        // Split on job headers (exactly two-space indented keys under `jobs:`).
        $parts = preg_split('/^  (?=[a-z_][a-z0-9_]*:\s*$)/m', $workflow) ?: [];

        foreach ($parts as $part) {
            if (preg_match('/^([a-z_][a-z0-9_]*):\s*$/m', $part, $m) !== 1) {
                continue;
            }
            $job = $m[1];

            $migratePos = strpos($part, 'php artisan migrate --force');
            if ($migratePos === false) {
                continue;
            }

            $guardPos = strpos($part, 'ci:assert-non-production-database');
            if ($guardPos === false || $guardPos > $migratePos) {
                $offenders[] = $job;
            }
        }

        return $offenders;
    }
}
