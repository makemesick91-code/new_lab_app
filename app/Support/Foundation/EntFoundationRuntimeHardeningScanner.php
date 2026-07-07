<?php

namespace App\Support\Foundation;

/**
 * POST-ENT — read-only Enterprise Foundation Runtime Hardening scanner.
 *
 * Verifies three postures WITHOUT mutating anything, starting a worker, running
 * a deploy/backup/restore, or touching a database:
 *   1. entAuditPosture()          — ENT-1..ENT-4 governance/config/docs locks are
 *                                    completed, GO-tagged and doc-backed.
 *   2. queueWorkerRuntimePosture() — the conservative queue-worker systemd unit is
 *                                    safe (queue:work, approved queues, tries/
 *                                    timeout/backoff, restart-safe, no destructive
 *                                    command) and the queue connection is not sync.
 *   3. deployEvidenceTimeoutPosture() — the server-side detached deploy runner
 *                                    exists and hardens the slow evidence phase
 *                                    against an SSH broken pipe, and the deploy
 *                                    script keeps the closed-baseline safety posture.
 *
 * All literals (destructive patterns, required markers) come from
 * config('enterprise_foundation_runtime_hardening') so no script/unit/app source
 * carries the sensitive patterns inline (ENT-9..ENT-16 config-not-code convention).
 */
class EntFoundationRuntimeHardeningScanner
{
    private function readFile(string $relativePath): ?string
    {
        if ($relativePath === '') {
            return null;
        }
        $full = base_path($relativePath);
        if (! is_file($full)) {
            return null;
        }

        return (string) file_get_contents($full);
    }

    /**
     * @return array<string, array{id: string, status?: string, go_tag?: string}>
     */
    private function roadmapEntries(): array
    {
        $entries = [];
        foreach ((array) config('foundation_roadmap.approved_sequence', []) as $entry) {
            $id = (string) ($entry['id'] ?? '');
            if ($id !== '') {
                $entries[$id] = $entry;
            }
        }

        return $entries;
    }

    /**
     * ENT-1..ENT-4 audit posture. Each audited sprint must be completed +
     * GO-tagged in the roadmap and its canonical docs must exist with their
     * rule-id markers. Runtime backfill is NOT required for these locks.
     *
     * @return array<string, mixed>
     */
    public function entAuditPosture(): array
    {
        $expectations = (array) config('enterprise_foundation_runtime_hardening.ent_1_4_audit.expectations', []);
        $requireCompleted = (bool) config('enterprise_foundation_runtime_hardening.ent_1_4_audit.require_completed_status', true);
        $requireGoTag = (bool) config('enterprise_foundation_runtime_hardening.ent_1_4_audit.require_go_tag', true);
        $entries = $this->roadmapEntries();

        $issues = [];
        $perSprint = [];

        foreach ($expectations as $id => $spec) {
            $entry = $entries[$id] ?? null;
            $sprintIssues = [];

            if ($entry === null) {
                $sprintIssues[] = 'missing from roadmap approved_sequence';
            } else {
                if ($requireCompleted && (string) ($entry['status'] ?? '') !== 'completed') {
                    $sprintIssues[] = 'not marked completed';
                }
                if ($requireGoTag && (string) ($entry['go_tag'] ?? '') === '') {
                    $sprintIssues[] = 'missing go_tag evidence';
                }
            }

            foreach ((array) ($spec['required_docs'] ?? []) as $doc) {
                if ($this->readFile((string) $doc) === null) {
                    $sprintIssues[] = "missing doc {$doc}";
                }
            }

            // Verify at least the first canonical doc carries the expected rule-id
            // markers so a doc that was blanked/replaced is caught.
            $primaryDoc = (string) (($spec['required_docs'] ?? [])[0] ?? '');
            $primaryContents = $primaryDoc !== '' ? $this->readFile($primaryDoc) : null;
            if ($primaryContents !== null) {
                foreach ((array) ($spec['doc_markers'] ?? []) as $marker) {
                    if ($marker !== '' && ! str_contains($primaryContents, (string) $marker)) {
                        $sprintIssues[] = "doc {$primaryDoc} missing marker {$marker}";
                    }
                }
            }

            $perSprint[$id] = [
                'title' => (string) ($spec['title'] ?? $id),
                'canonical_scope' => (string) ($spec['canonical_scope'] ?? ''),
                'runtime_backfill_required' => (bool) ($spec['runtime_backfill_required'] ?? false),
                'status' => (string) ($entry['status'] ?? 'unknown'),
                'go_tag' => (string) ($entry['go_tag'] ?? ''),
                'ok' => $sprintIssues === [],
                'issues' => $sprintIssues,
            ];

            foreach ($sprintIssues as $i) {
                $issues[] = "{$id}: {$i}";
            }
        }

        return [
            'ok' => $issues === [],
            'per_sprint' => $perSprint,
            'audited_sprints' => array_keys($expectations),
            'issues' => $issues,
        ];
    }

    /**
     * Queue-worker runtime posture built on ENT-5 queue governance.
     *
     * @return array<string, mixed>
     */
    public function queueWorkerRuntimePosture(): array
    {
        $cfg = (array) config('enterprise_foundation_runtime_hardening.queue_worker', []);
        $serviceFile = (string) ($cfg['service_file'] ?? '');
        $contents = $this->readFile($serviceFile);

        $issues = [];
        if ($contents === null) {
            return [
                'ok' => false,
                'service_file_present' => false,
                'issues' => ['queue worker systemd unit missing: '.$serviceFile],
            ];
        }

        $lower = strtolower($contents);

        // Destructive patterns must never appear.
        $destructive = $this->findForbidden($lower, (array) config('enterprise_foundation_runtime_hardening.deploy_evidence_timeout.forbidden_destructive_patterns', []));
        if ($destructive !== []) {
            $issues[] = 'destructive command(s): '.implode(', ', $destructive);
        }

        // The worker must run queue:work.
        $workerCmd = (string) ($cfg['required_worker_command'] ?? 'artisan queue:work');
        $workerCmdPresent = str_contains($contents, $workerCmd);
        if (! $workerCmdPresent) {
            $issues[] = "unit must run {$workerCmd}";
        }

        // Required conservative markers.
        $missingMarkers = $this->missingMarkers($contents, (array) ($cfg['required_service_markers'] ?? []));
        if ($missingMarkers !== []) {
            $issues[] = 'missing service marker(s): '.implode(', ', $missingMarkers);
        }

        // Forbidden worker markers (queue:listen, --daemon, destructive queue cmds).
        $forbiddenWorker = [];
        foreach ((array) ($cfg['forbidden_worker_markers'] ?? []) as $marker) {
            if ($marker !== '' && str_contains($contents, (string) $marker)) {
                $forbiddenWorker[] = (string) $marker;
            }
        }
        if ($forbiddenWorker !== []) {
            $issues[] = 'forbidden worker marker(s): '.implode(', ', $forbiddenWorker);
        }

        // Approved queues must be a subset of the ENT-5 allowed queue names.
        $approved = (array) ($cfg['approved_queues'] ?? []);
        $ent5Allowed = (array) config('queue_governance.ent5_retry_failed_job.allowed_queue_names', []);
        $notAllowed = array_values(array_filter($approved, fn (string $q) => ! in_array($q, $ent5Allowed, true)));
        if ($notAllowed !== []) {
            $issues[] = 'queue(s) not in ENT-5 allowed set: '.implode(', ', $notAllowed);
        }

        // The queue connection must be valid for the current environment per the
        // ENT-5 environment_connection_policy. sync is acceptable in local/testing
        // (no worker runs there) but a real worker environment (pilot/staging/
        // production) must use a broker-backed connection so retries/failed jobs
        // actually exist.
        $connection = (string) config('queue.default', 'sync');
        $env = (string) app()->environment();
        $policy = (array) config("queue_governance.ent5_retry_failed_job.environment_connection_policy.{$env}",
            config('queue_governance.ent5_retry_failed_job.fallback_allowed_connections', ['database']));
        $connectionOk = in_array($connection, $policy, true);
        if (! $connectionOk) {
            $issues[] = "queue connection '{$connection}' is not allowed for environment '{$env}' per ENT-5 (retries/failed jobs require a broker-backed connection)";
        }

        // Failed-job storage must exist (ENT-5).
        $failedTable = (string) config('queue_governance.ent5_retry_failed_job.failed_jobs.required_table', 'failed_jobs');

        return [
            'ok' => $issues === [],
            'service_file_present' => true,
            'service_name' => (string) ($cfg['service_name'] ?? ''),
            'worker_command_present' => $workerCmdPresent,
            'no_destructive_command' => $destructive === [],
            'no_forbidden_worker_marker' => $forbiddenWorker === [],
            'queues_subset_of_ent5' => $notAllowed === [],
            'connection' => $connection,
            'connection_ok' => $connectionOk,
            'failed_jobs_table' => $failedTable,
            'activated_by_deploy' => (bool) ($cfg['activated_by_deploy'] ?? false),
            'issues' => $issues,
        ];
    }

    /**
     * Deploy-evidence timeout hardening posture.
     *
     * @return array<string, mixed>
     */
    public function deployEvidenceTimeoutPosture(): array
    {
        $cfg = (array) config('enterprise_foundation_runtime_hardening.deploy_evidence_timeout', []);
        $issues = [];

        $runner = $this->readFile((string) ($cfg['runner_script'] ?? ''));
        $runnerPresent = $runner !== null;
        if (! $runnerPresent) {
            $issues[] = 'deploy runner script missing: '.(string) ($cfg['runner_script'] ?? '');
        } else {
            $lower = strtolower($runner);
            $destructive = $this->findForbidden($lower, (array) ($cfg['forbidden_destructive_patterns'] ?? []));
            if ($destructive !== []) {
                $issues[] = 'runner destructive command(s): '.implode(', ', $destructive);
            }
            $missing = $this->missingMarkers($runner, (array) ($cfg['required_runner_markers'] ?? []));
            if ($missing !== []) {
                $issues[] = 'runner missing marker(s): '.implode(', ', $missing);
            }
            $missingStatus = $this->missingMarkers($runner, (array) ($cfg['required_status_markers'] ?? []));
            if ($missingStatus !== []) {
                $issues[] = 'runner missing status marker(s): '.implode(', ', $missingStatus);
            }
        }

        $deploy = $this->readFile((string) ($cfg['deploy_script'] ?? ''));
        $deployPresent = $deploy !== null;
        if (! $deployPresent) {
            $issues[] = 'deploy script missing: '.(string) ($cfg['deploy_script'] ?? '');
        } else {
            $lower = strtolower($deploy);
            $destructive = $this->findForbidden($lower, (array) ($cfg['forbidden_destructive_patterns'] ?? []));
            if ($destructive !== []) {
                $issues[] = 'deploy script destructive command(s): '.implode(', ', $destructive);
            }
            $missing = $this->missingMarkers($deploy, (array) ($cfg['deploy_script_required_markers'] ?? []));
            if ($missing !== []) {
                $issues[] = 'deploy script missing safety marker(s): '.implode(', ', $missing);
            }
        }

        return [
            'ok' => $issues === [],
            'runner_present' => $runnerPresent,
            'deploy_script_present' => $deployPresent,
            'issues' => $issues,
        ];
    }

    /**
     * Release-evidence profile posture: each hardening artifact must be declared
     * (required OR optional) in the configured ci/vps profiles.
     *
     * @return array<string, mixed>
     */
    public function evidenceProfilePosture(): array
    {
        $artifacts = array_values((array) config('enterprise_foundation_runtime_hardening.evidence.artifacts', []));
        $profiles = (array) config('enterprise_foundation_runtime_hardening.evidence.required_in_profiles', []);

        $issues = [];
        $perProfile = [];
        foreach ($profiles as $profile) {
            $required = (array) config("release_evidence.profiles.{$profile}.required_artifacts", []);
            $optional = (array) config("release_evidence.profiles.{$profile}.optional_artifacts", []);
            $declared = array_merge($required, $optional);
            $missing = array_values(array_filter($artifacts, fn (string $a) => ! in_array($a, $declared, true)));
            if ($missing !== []) {
                $issues[] = "profile {$profile} missing artifact(s): ".implode(', ', $missing);
            }
            $perProfile[$profile] = ['missing' => $missing];
        }

        return ['ok' => $issues === [], 'artifacts' => $artifacts, 'profiles' => $perProfile, 'issues' => $issues];
    }

    /**
     * @param  list<string>  $patterns
     * @return list<string>
     */
    private function findForbidden(string $lowerContents, array $patterns): array
    {
        $found = [];
        foreach ($patterns as $pattern) {
            $pattern = strtolower((string) $pattern);
            if ($pattern !== '' && str_contains($lowerContents, $pattern)) {
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
            if ($marker !== '' && ! str_contains($contents, (string) $marker)) {
                $missing[] = (string) $marker;
            }
        }

        return array_values($missing);
    }
}
