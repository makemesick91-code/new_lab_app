<?php

namespace App\Support\Cicd;

/**
 * CICD-CTRL-1 — Safe CI Runtime Control scanner.
 *
 * Read-only. Verifies the classifier script (scripts/ci/resolve-gates.sh) and
 * the Foundation Evidence Gates workflow honour the safe gate-control contract
 * declared in config/ci_runtime_control.php:
 *
 *  - The classifier exists and carries the default-strong safety markers.
 *  - The workflow wires the classifier in, gates only the expensive critical
 *    step on the classifier output, keeps the always-on security/governance/
 *    release-safety/evidence gates unconditional, preserves the full-suite
 *    fallback, and never adds unsafe path filtering.
 *  - The safety invariant is intact: docs_only is the only skip-critical
 *    profile and the default profile is the strongest.
 *
 * The scanner only reads files and config; it never runs a command or writes.
 */
class CiRuntimeControlScanner
{
    private function readFile(string $key): ?string
    {
        $path = (string) config("ci_runtime_control.files.{$key}", '');
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
     * @return array{ok: bool, exists: bool, missing_markers: list<string>, issues: list<string>}
     */
    public function classifierScriptPosture(): array
    {
        $issues = [];
        $contents = $this->readFile('classifier_script');

        if ($contents === null) {
            return [
                'ok' => false,
                'exists' => false,
                'missing_markers' => [],
                'issues' => ['classifier script scripts/ci/resolve-gates.sh is missing'],
            ];
        }

        $missing = [];
        foreach ((array) config('ci_runtime_control.required_classifier_markers', []) as $marker) {
            if ($marker !== '' && ! str_contains($contents, (string) $marker)) {
                $missing[] = (string) $marker;
            }
        }
        if ($missing !== []) {
            $issues[] = 'classifier script missing safety markers: '.implode(', ', $missing);
        }

        return [
            'ok' => $issues === [],
            'exists' => true,
            'missing_markers' => $missing,
            'issues' => $issues,
        ];
    }

    /**
     * @return array{ok: bool, exists: bool, missing_markers: list<string>, forbidden_present: list<string>, missing_always_on: list<string>, full_suite_fallback: bool, issues: list<string>}
     */
    public function workflowPosture(): array
    {
        $issues = [];
        $contents = $this->readFile('ci_workflow');

        if ($contents === null) {
            return [
                'ok' => false,
                'exists' => false,
                'missing_markers' => [],
                'forbidden_present' => [],
                'missing_always_on' => [],
                'full_suite_fallback' => false,
                'issues' => ['CI workflow .github/workflows/foundation-evidence-gates.yml is missing'],
            ];
        }

        $missing = [];
        foreach ((array) config('ci_runtime_control.required_workflow_markers', []) as $marker) {
            if ($marker !== '' && ! str_contains($contents, (string) $marker)) {
                $missing[] = (string) $marker;
            }
        }
        if ($missing !== []) {
            $issues[] = 'workflow missing required markers: '.implode(', ', $missing);
        }

        $forbidden = [];
        foreach ((array) config('ci_runtime_control.forbidden_workflow_markers', []) as $marker) {
            if ($marker !== '' && str_contains($contents, (string) $marker)) {
                $forbidden[] = (string) $marker;
            }
        }
        if ($forbidden !== []) {
            $issues[] = 'workflow contains unsafe markers (blanket skip / path filtering): '.implode(', ', $forbidden);
        }

        $missingAlwaysOn = [];
        foreach ((array) config('ci_runtime_control.always_on_jobs', []) as $job) {
            if ($job !== '' && ! str_contains($contents, (string) $job)) {
                $missingAlwaysOn[] = (string) $job;
            }
        }
        if ($missingAlwaysOn !== []) {
            $issues[] = 'workflow missing always-on gate job(s): '.implode(', ', $missingAlwaysOn);
        }

        $fallbackTriggers = (array) config('ci_runtime_control.full_suite_fallback_triggers', []);
        $fullSuiteFallback = collect($fallbackTriggers)
            ->filter(fn (string $t) => $t !== '')
            ->contains(fn (string $t) => str_contains($contents, $t));
        if (! $fullSuiteFallback) {
            $issues[] = 'workflow missing full-suite fallback trigger (schedule / workflow_dispatch)';
        }

        return [
            'ok' => $issues === [],
            'exists' => true,
            'missing_markers' => $missing,
            'forbidden_present' => $forbidden,
            'missing_always_on' => $missingAlwaysOn,
            'full_suite_fallback' => $fullSuiteFallback,
            'issues' => $issues,
        ];
    }

    /**
     * CI-TEMP-FULL-SUITE-SCHEDULE-GATE — GLOBAL TEMPORARY FULL-SUITE POLICY.
     *
     * Proves the CI layer really enforces the documented policy, rather than the
     * policy existing only as prose. Verifies that:
     *
     *  - a canonical machine-readable state file exists and carries exactly one
     *    recognised status (ACTIVE / RETIRED) — so the workflow and the docs
     *    cannot drift apart;
     *  - the resolver exists, is fail-closed, and never runs tests or mutates;
     *  - the workflow gates full_suite_gate on the resolver's decision;
     *  - while the policy is ACTIVE, BOTH automatic events (the weekly schedule
     *    and the post-merge push to base) are deferred, and the explicit
     *    dispatch override input still exists so the consolidated closure run
     *    stays possible.
     *
     * Read-only: reads files and config, runs nothing.
     *
     * @return array{ok: bool, exists: bool, status: string, active: bool, missing_reason_codes: list<string>, missing_resolver_markers: list<string>, forbidden_resolver_present: list<string>, workflow_wired: bool, issues: list<string>}
     */
    public function temporaryFullSuitePolicyPosture(): array
    {
        $issues = [];
        $allowed = array_values((array) config('ci_runtime_control.temporary_full_suite_policy.allowed_statuses', ['ACTIVE', 'RETIRED']));

        $state = $this->readFile('full_suite_policy_state');
        $status = 'UNRESOLVED';

        if ($state === null) {
            $issues[] = 'canonical Full Suite policy state file is missing';
        } else {
            $found = [];
            foreach ($allowed as $candidate) {
                if (preg_match('/"status"\s*:\s*"'.preg_quote((string) $candidate, '/').'"/', $state) === 1) {
                    $found[] = (string) $candidate;
                }
            }

            if (count($found) === 1) {
                $status = $found[0];
            } else {
                // Zero or several statuses is ambiguous. Fail closed: report it
                // and leave the status UNRESOLVED so nothing reads it as RETIRED.
                $issues[] = 'policy state file must declare exactly one recognised status ('.implode(' / ', $allowed).'); found '.count($found);
            }
        }

        // UNRESOLVED is treated as ACTIVE everywhere — the fail-closed direction.
        $active = $status !== 'RETIRED';

        $resolver = $this->readFile('full_suite_policy_resolver');
        $missingResolver = [];
        $forbiddenResolver = [];

        if ($resolver === null) {
            $issues[] = 'Full Suite policy resolver scripts/ci/resolve-full-suite-policy.sh is missing';
        } else {
            foreach ((array) config('ci_runtime_control.temporary_full_suite_policy.required_resolver_markers', []) as $marker) {
                if ($marker !== '' && ! str_contains($resolver, (string) $marker)) {
                    $missingResolver[] = (string) $marker;
                }
            }
            if ($missingResolver !== []) {
                $issues[] = 'resolver missing fail-closed markers: '.implode(', ', $missingResolver);
            }

            foreach ((array) config('ci_runtime_control.temporary_full_suite_policy.forbidden_resolver_markers', []) as $marker) {
                if ($marker !== '' && str_contains($resolver, (string) $marker)) {
                    $forbiddenResolver[] = (string) $marker;
                }
            }
            if ($forbiddenResolver !== []) {
                $issues[] = 'resolver must never execute the suite; found: '.implode(', ', $forbiddenResolver);
            }
        }

        $missingCodes = [];
        foreach ((array) config('ci_runtime_control.temporary_full_suite_policy.required_reason_codes', []) as $code) {
            if ($code !== '' && ($resolver === null || ! str_contains($resolver, (string) $code))) {
                $missingCodes[] = (string) $code;
            }
        }
        if ($missingCodes !== []) {
            $issues[] = 'resolver missing machine-readable reason code(s): '.implode(', ', $missingCodes);
        }

        // The workflow must actually consume the decision, and must keep the
        // explicit override input so the consolidated closure remains runnable.
        $workflow = $this->readFile('ci_workflow');
        $workflowWired = false;

        if ($workflow === null) {
            $issues[] = 'CI workflow is missing; the Full Suite policy cannot be enforced';
        } else {
            $wiring = [
                "needs.classify.outputs.full_suite_authorized == 'true'",
                'resolve-full-suite-policy.sh',
                'full_suite_policy_override',
            ];
            $missingWiring = [];
            foreach ($wiring as $marker) {
                if (! str_contains($workflow, $marker)) {
                    $missingWiring[] = $marker;
                }
            }
            $workflowWired = $missingWiring === [];
            if (! $workflowWired) {
                $issues[] = 'workflow does not gate the Full Suite on the policy decision; missing: '.implode(', ', $missingWiring);
            }
        }

        return [
            'ok' => $issues === [],
            'exists' => $state !== null,
            'status' => $status,
            'active' => $active,
            'missing_reason_codes' => $missingCodes,
            'missing_resolver_markers' => $missingResolver,
            'forbidden_resolver_present' => $forbiddenResolver,
            'workflow_wired' => $workflowWired,
            'issues' => $issues,
        ];
    }

    /**
     * @return array{ok: bool, skip_critical_profiles: list<string>, default_profile: string, profiles: list<string>, issues: list<string>}
     */
    public function safetyInvariantPosture(): array
    {
        $issues = [];

        $skip = array_values((array) config('ci_runtime_control.skip_critical_profiles', []));
        if ($skip !== ['docs_only']) {
            $issues[] = 'skip_critical_profiles must be exactly [docs_only]; found ['.implode(', ', $skip).']';
        }

        $default = (string) config('ci_runtime_control.default_profile', '');
        if ($default !== 'unknown_high_risk') {
            $issues[] = "default_profile must be unknown_high_risk; found '{$default}'";
        }

        $profiles = array_values((array) config('ci_runtime_control.profiles', []));
        $expected = ['unknown_high_risk', 'ci_workflow', 'dependency_or_build', 'permissions_security', 'runtime_app', 'ui_only', 'docs_only'];
        $missingProfiles = array_values(array_diff($expected, $profiles));
        if ($missingProfiles !== []) {
            $issues[] = 'profiles registry missing: '.implode(', ', $missingProfiles);
        }

        return [
            'ok' => $issues === [],
            'skip_critical_profiles' => $skip,
            'default_profile' => $default,
            'profiles' => $profiles,
            'issues' => $issues,
        ];
    }
}
