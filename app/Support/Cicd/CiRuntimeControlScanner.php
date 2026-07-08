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
