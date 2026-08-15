<?php

namespace App\Support\Deploy;

/**
 * DEPLOY-HARDEN-1 — read-only Immutable Deployment Entrypoint scanner.
 *
 * Proves, without running anything, that the deployment entrypoint upholds:
 *
 *     RUNNING_DEPLOY_PROGRAM != MUTABLE_REPOSITORY_FILE
 *
 * i.e. the trusted bootstrap takes an exclusive host lock, pins an exact target
 * commit, exports an immutable execution snapshot from the git object, verifies
 * the snapshot trust boundary and always cleans up with the real exit code; and
 * that the deploy + rollback scripts hand over to it before they touch the
 * repository, refuse to run outside the snapshot, invoke every post-mutation
 * helper from the snapshot, and verify HEAD landed on the pinned target.
 *
 * All literals come from config('deployment_entrypoint') so the deployment
 * scripts and the app code never carry the contract inline.
 */
class DeploymentEntrypointScanner
{
    /**
     * Read a configured entrypoint file. Null when absent so callers can flag it.
     */
    private function readEntrypoint(string $key): ?string
    {
        $path = (string) config("deployment_entrypoint.entrypoint_files.{$key}", '');
        if ($path === '') {
            return null;
        }

        $full = base_path($path);

        return is_file($full) ? (string) file_get_contents($full) : null;
    }

    /**
     * The trusted bootstrap: lock, pin, snapshot, trust boundary, cleanup.
     *
     * @return array<string, mixed>
     */
    public function bootstrapPosture(): array
    {
        $contents = $this->readEntrypoint('bootstrap');
        if ($contents === null) {
            return [
                'file_present' => false,
                'ok' => false,
                'issues' => ['immutable execution bootstrap missing — no safe deployment path exists'],
            ];
        }

        $expect = (array) config('deployment_entrypoint.bootstrap_expectations', []);
        $issues = [];

        $failFast = (string) ($expect['required_fail_fast_marker'] ?? 'set -euo pipefail');
        $failFastPresent = str_contains($contents, $failFast);
        if (! $failFastPresent) {
            $issues[] = "bootstrap is not fail-fast (missing {$failFast})";
        }

        $groups = [
            'required_lock_markers' => 'exclusive deployment lock',
            'required_pin_markers' => 'exact target pinning',
            'required_snapshot_markers' => 'immutable execution snapshot',
            'required_trust_markers' => 'snapshot trust boundary',
            'required_cleanup_markers' => 'guaranteed snapshot cleanup',
            'required_hash_markers' => 'source/snapshot hash proof',
        ];
        $results = [];
        foreach ($groups as $key => $label) {
            $missing = $this->missingMarkers($contents, (array) ($expect[$key] ?? []));
            $results[$key] = $missing === [];
            if ($missing !== []) {
                $issues[] = "{$label}: missing ".implode(', ', $missing);
            }
        }

        // The lock/snapshot root must be a trusted path. At least one of the
        // declared roots must appear; none of them may live under the
        // application directory, which is writable by the runtime user.
        $trustedRoots = (array) ($expect['required_trusted_root_markers'] ?? []);
        $trustedRootPresent = false;
        foreach ($trustedRoots as $root) {
            if (str_contains($contents, (string) $root)) {
                $trustedRootPresent = true;
                break;
            }
        }
        if (! $trustedRootPresent) {
            $issues[] = 'bootstrap does not use a trusted (non-runtime-writable) lock/snapshot root';
        }

        $runIdMarker = (string) ($expect['required_run_id_marker'] ?? 'DEPLOY_RUN_ID');
        $runIdPresent = str_contains($contents, $runIdMarker);
        if (! $runIdPresent) {
            $issues[] = "bootstrap does not emit a unique run id ({$runIdMarker})";
        }

        return [
            'file_present' => true,
            'ok' => $issues === [],
            'fail_fast' => $failFastPresent,
            'lock_present' => $results['required_lock_markers'] ?? false,
            'target_pinning_present' => $results['required_pin_markers'] ?? false,
            'snapshot_present' => $results['required_snapshot_markers'] ?? false,
            'trust_boundary_present' => $results['required_trust_markers'] ?? false,
            'cleanup_present' => $results['required_cleanup_markers'] ?? false,
            'hash_proof_present' => $results['required_hash_markers'] ?? false,
            'trusted_root_present' => $trustedRootPresent,
            'run_id_present' => $runIdPresent,
            'issues' => $issues,
        ];
    }

    /**
     * A mutating entrypoint (deploy or rollback): hands over to the bootstrap
     * before mutating, refuses to run in-tree, pins an exact SHA, verifies HEAD,
     * guards a dirty checkout, and runs post-mutation helpers from the snapshot.
     *
     * @return array<string, mixed>
     */
    public function mutatingEntrypointPosture(string $key): array
    {
        $contents = $this->readEntrypoint($key);
        if ($contents === null) {
            return ['file_present' => false, 'ok' => false, 'issues' => ["{$key} missing"]];
        }

        $expect = (array) config('deployment_entrypoint.mutating_entrypoint_expectations', []);
        $issues = [];

        $failFast = (string) ($expect['required_fail_fast_marker'] ?? 'set -euo pipefail');
        if (! str_contains($contents, $failFast)) {
            $issues[] = "{$key} is not fail-fast";
        }

        $checks = [
            'handover' => (array) ($expect['required_handover_markers'] ?? []),
            'refusal' => (array) ($expect['required_refusal_markers'] ?? []),
            'head_verification' => (array) ($expect['required_head_verification_markers'] ?? []),
        ];
        $flags = [];
        foreach ($checks as $label => $markers) {
            $missing = $this->missingMarkers($contents, $markers);
            $flags[$label] = $missing === [];
            if ($missing !== []) {
                $issues[] = "{$key} {$label}: missing ".implode(', ', $missing);
            }
        }

        foreach ([
            'pinned_target' => (string) ($expect['required_pin_marker'] ?? ''),
            'dirty_guard' => (string) ($expect['required_dirty_guard_marker'] ?? ''),
            'snapshot_tools' => (string) ($expect['required_snapshot_tool_marker'] ?? ''),
        ] as $label => $marker) {
            $present = $marker !== '' && str_contains($contents, $marker);
            $flags[$label] = $present;
            if (! $present) {
                $issues[] = "{$key} {$label}: missing {$marker}";
            }
        }

        // The regression that must never come back: a helper re-read out of the
        // working tree the deployment just rewrote.
        $mutableInvocations = $this->findForbiddenInvocations($contents);
        if ($mutableInvocations !== []) {
            $issues[] = "{$key} invokes helpers from the mutable working tree: ".implode(', ', $mutableInvocations);
        }

        return [
            'file_present' => true,
            'ok' => $issues === [],
            'hands_over_to_bootstrap' => $flags['handover'] ?? false,
            'refuses_mutable_execution' => $flags['refusal'] ?? false,
            'pins_exact_target' => $flags['pinned_target'] ?? false,
            'verifies_head_against_target' => $flags['head_verification'] ?? false,
            'guards_dirty_checkout' => $flags['dirty_guard'] ?? false,
            'uses_snapshot_tools' => $flags['snapshot_tools'] ?? false,
            'no_working_tree_invocation' => $mutableInvocations === [],
            'issues' => $issues,
        ];
    }

    /**
     * The execution closure: every script executed across the repository
     * mutation must be carried inside the snapshot, and the live runtime
     * identity authority must be overlaid onto it.
     *
     * @return array<string, mixed>
     */
    public function executionClosurePosture(): array
    {
        $closure = (array) config('deployment_entrypoint.execution_closure', []);
        $bootstrap = $this->readEntrypoint('bootstrap') ?? '';
        $issues = [];

        $snapshotPaths = array_map('strval', (array) ($closure['snapshot_paths'] ?? []));
        $missingPaths = array_values(array_filter(
            $snapshotPaths,
            fn (string $p) => ! str_contains($bootstrap, $p)
        ));
        if ($missingPaths !== []) {
            $issues[] = 'snapshot export does not cover: '.implode(', ', $missingPaths);
        }

        // Every declared closure member must (a) exist in the repository and
        // (b) fall under one of the exported snapshot paths, otherwise the
        // deployment would have to reach back into the mutable tree for it.
        $missingMembers = [];
        $uncoveredMembers = [];
        foreach ((array) ($closure['closure_members'] ?? []) as $member) {
            $member = (string) $member;
            if (! is_file(base_path($member))) {
                $missingMembers[] = $member;

                continue;
            }
            $covered = false;
            foreach ($snapshotPaths as $p) {
                if (str_starts_with($member, rtrim($p, '/').'/')) {
                    $covered = true;
                    break;
                }
            }
            if (! $covered) {
                $uncoveredMembers[] = $member;
            }
        }
        if ($missingMembers !== []) {
            $issues[] = 'closure member(s) absent from the repository: '.implode(', ', $missingMembers);
        }
        if ($uncoveredMembers !== []) {
            $issues[] = 'closure member(s) not covered by the snapshot export: '.implode(', ', $uncoveredMembers);
        }

        $overlay = (string) ($closure['required_overlay_marker'] ?? '');
        $overlayPresent = $overlay !== ''
            && str_contains($this->readEntrypoint('deploy_script') ?? '', $overlay)
            && str_contains($this->readEntrypoint('rollback_script') ?? '', $overlay);
        if (! $overlayPresent) {
            $issues[] = "the live runtime identity authority ({$overlay}) is not overlaid onto the snapshot";
        }

        return [
            'ok' => $issues === [],
            'snapshot_paths' => $snapshotPaths,
            'closure_complete' => $missingMembers === [] && $uncoveredMembers === [],
            'identity_overlay_present' => $overlayPresent,
            'issues' => $issues,
        ];
    }

    /**
     * Operator interface: one canonical command, and "launcher started" is
     * never reported as "deployment complete".
     *
     * @return array<string, mixed>
     */
    public function runnerPosture(): array
    {
        $contents = $this->readEntrypoint('runner_script');
        if ($contents === null) {
            return ['file_present' => false, 'ok' => false, 'issues' => ['deploy runner missing']];
        }

        $expect = (array) config('deployment_entrypoint.runner_expectations', []);
        $issues = [];

        $missing = $this->missingMarkers($contents, (array) ($expect['required_markers'] ?? []));
        if ($missing !== []) {
            $issues[] = 'runner missing marker(s): '.implode(', ', $missing);
        }

        // Manual pre-pull must not be a permanent prerequisite: the deploy path
        // has to bring a stale production checkout to the pinned target itself.
        $staleMarker = (string) config('deployment_entrypoint.stale_checkout_expectations.required_marker', '');
        $handlesStale = $staleMarker !== '' && str_contains($this->readEntrypoint('deploy_script') ?? '', $staleMarker);
        if (! $handlesStale) {
            $issues[] = 'deploy path cannot advance a stale checkout to the pinned target without a manual pre-pull';
        }

        return [
            'file_present' => true,
            'ok' => $issues === [],
            'launcher_start_is_not_completion' => $missing === [],
            'stale_checkout_handled' => $handlesStale,
            'canonical_command' => (string) ($expect['canonical_command'] ?? ''),
            'issues' => $issues,
        ];
    }

    /**
     * Release-safety posture: the pre-deploy gate list includes this gate.
     *
     * @return array<string, mixed>
     */
    public function releaseSafetyPosture(): array
    {
        $gates = (array) config('release_safety.required_pre_deploy_gates', []);
        $required = (string) config('deployment_entrypoint.required_pre_deploy_gate_command', '');
        $present = $required !== '' && collect($gates)->contains(
            fn ($gate) => str_contains((string) $gate, $required)
        );

        return [
            'ok' => $present,
            'pre_deploy_gate_ok' => $present,
            'issues' => $present ? [] : ["pre-deploy gate missing command: {$required}"],
        ];
    }

    /**
     * @return list<string>
     */
    private function findForbiddenInvocations(string $contents): array
    {
        $lower = strtolower($contents);
        $found = [];
        foreach ((array) config('deployment_entrypoint.forbidden_working_tree_invocations', []) as $pattern) {
            $pattern = strtolower((string) $pattern);
            if ($pattern !== '' && str_contains($lower, $pattern)) {
                $found[] = $pattern;
            }
        }

        return $found;
    }

    /**
     * @param  list<string>  $markers
     * @return list<string>
     */
    private function missingMarkers(string $contents, array $markers): array
    {
        $missing = [];
        foreach ($markers as $marker) {
            $marker = (string) $marker;
            if ($marker !== '' && ! str_contains($contents, $marker)) {
                $missing[] = $marker;
            }
        }

        return array_values($missing);
    }
}
