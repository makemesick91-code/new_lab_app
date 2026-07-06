<?php

namespace App\Support\Queue;

use Illuminate\Support\Facades\Schema;
use Symfony\Component\Finder\Finder;
use Throwable;

/**
 * ENT-5 — Read-only queue/retry/failed-job runtime readiness inspector.
 *
 * Verifies, without mutating anything:
 *  - the current queue connection is allowed for the current environment
 *    (sync is local/testing only);
 *  - failed-job storage is configured (database-uuids driver) and the
 *    failed_jobs table exists;
 *  - the central retry/backoff/timeout standard is complete and sane;
 *  - every ShouldQueue class in app/ inherits the approved defaults
 *    (EnterpriseQueueJob / EnterpriseQueueRetryDefaults) or declares
 *    explicit tries/backoff/timeout values;
 *  - no destructive queue command is automated inside app code;
 *  - the worker operations runbook exists and workers are never started
 *    by deploy.
 */
class QueueRetryFailedJobReadinessService
{
    /**
     * @return array<string, mixed>
     */
    public function collect(): array
    {
        $config = (array) config('queue_governance.ent5_retry_failed_job', []);
        $checks = [];
        $warnings = [];

        if ($config === []) {
            $checks[] = $this->fail('ENT5-Q-CONFIG-EXISTS', 'ent5_retry_failed_job section missing from queue_governance config.');

            return $this->finalize($config, $checks, $warnings, []);
        }

        $checks[] = $this->pass('ENT5-Q-CONFIG-EXISTS', 'ent5_retry_failed_job governance config present.');

        // ENT5-Q001 — queue connection allowed for this environment.
        $environment = strtolower((string) config('app.env'));
        $connection = strtolower((string) config('queue.default'));
        $policy = (array) ($config['environment_connection_policy'] ?? []);
        $allowed = (array) ($policy[$environment] ?? ($config['fallback_allowed_connections'] ?? []));
        $checks[] = in_array($connection, $allowed, true)
            ? $this->pass('ENT5-Q001-CONNECTION-POLICY', "Queue connection \"{$connection}\" is allowed in environment \"{$environment}\".")
            : $this->fail('ENT5-Q001-CONNECTION-POLICY', "Queue connection \"{$connection}\" is not allowed in environment \"{$environment}\" (allowed: ".implode(', ', $allowed).').');

        // ENT5-Q002 — failed-job storage readiness.
        $requiredDriver = (string) ($config['failed_jobs']['required_driver'] ?? 'database-uuids');
        $requiredTable = (string) ($config['failed_jobs']['required_table'] ?? 'failed_jobs');
        $failedDriver = (string) config('queue.failed.driver');
        $checks[] = $failedDriver === $requiredDriver
            ? $this->pass('ENT5-Q002-FAILED-DRIVER', "Failed job driver \"{$failedDriver}\" matches the required driver.")
            : $this->fail('ENT5-Q002-FAILED-DRIVER', "Failed job driver \"{$failedDriver}\" does not match required \"{$requiredDriver}\".");

        $failedTableExists = $this->tableExists($requiredTable);
        if ($failedTableExists) {
            $checks[] = $this->pass('ENT5-Q002-FAILED-TABLE', "Failed job table \"{$requiredTable}\" exists.");
        } else {
            $checks[] = $this->warn('ENT5-Q002-FAILED-TABLE', "Failed job table \"{$requiredTable}\" not migrated in this environment yet.");
            $warnings[] = "failed job table \"{$requiredTable}\" missing";
        }

        // ENT5-Q003 — central retry/backoff/timeout standard sanity.
        $standards = (array) ($config['retry_standards'] ?? []);
        $standardIssues = $this->validateRetryStandards($standards);
        $checks[] = $standardIssues === []
            ? $this->pass('ENT5-Q003-RETRY-STANDARD', 'Central retry/backoff/timeout standard is complete and within caps.')
            : $this->fail('ENT5-Q003-RETRY-STANDARD', 'Retry standard invalid: '.implode('; ', $standardIssues));

        // ENT5-Q008 — every ShouldQueue class follows the approved convention.
        $jobScan = $this->scanQueuedClasses($config);
        if ($jobScan['non_compliant'] === []) {
            $checks[] = $this->pass(
                'ENT5-Q008-JOB-CONVENTION',
                $jobScan['total'] === 0
                    ? 'No ShouldQueue class in app code yet — convention holds trivially.'
                    : $jobScan['total'].' ShouldQueue class(es) all inherit or declare an approved retry/backoff/timeout policy.'
            );
        } else {
            $checks[] = $this->fail(
                'ENT5-Q008-JOB-CONVENTION',
                'ShouldQueue class(es) without approved retry/backoff/timeout policy: '.implode(', ', $jobScan['non_compliant'])
            );
        }

        // ENT5-Q005 — destructive queue commands are never automated in app code.
        $unsafe = $this->scanUnsafeCommands($config);
        $checks[] = $unsafe === []
            ? $this->pass('ENT5-Q005-NO-UNSAFE-COMMANDS', 'No destructive queue command automated in app code.')
            : $this->fail('ENT5-Q005-NO-UNSAFE-COMMANDS', 'Destructive queue command usage found in: '.implode(', ', $unsafe));

        // ENT5-Q006 — worker runbook present, workers never started by deploy.
        $runbook = (string) ($config['worker']['runbook_doc'] ?? '');
        $checks[] = $runbook !== '' && is_file(base_path($runbook))
            ? $this->pass('ENT5-Q006-WORKER-RUNBOOK', "Worker operations runbook present at {$runbook}.")
            : $this->fail('ENT5-Q006-WORKER-RUNBOOK', 'Worker operations runbook missing.');
        $checks[] = ($config['worker']['started_by_deploy'] ?? true) === false
            ? $this->pass('ENT5-Q006-WORKER-NOT-DEPLOY-STARTED', 'Workers are not started by deploy (operator/supervisor concern by policy).')
            : $this->fail('ENT5-Q006-WORKER-NOT-DEPLOY-STARTED', 'worker.started_by_deploy must be false.');

        // ENT5-Q004 — critical domains require idempotency before queueing.
        $domains = (array) ($config['critical_idempotency_domains'] ?? []);
        $checks[] = $domains !== []
            ? $this->pass('ENT5-Q004-IDEMPOTENCY-DOMAINS', count($domains).' critical domain(s) require idempotent jobs before queueing.')
            : $this->fail('ENT5-Q004-IDEMPOTENCY-DOMAINS', 'critical_idempotency_domains must not be empty.');

        return $this->finalize($config, $checks, $warnings, $jobScan);
    }

    /**
     * @param  array<string, mixed>  $standards
     * @return list<string>
     */
    private function validateRetryStandards(array $standards): array
    {
        $issues = [];

        $tries = $standards['default_tries'] ?? null;
        $maxTries = $standards['max_tries'] ?? null;
        if (! is_int($tries) || $tries < 1 || ! is_int($maxTries) || $tries > $maxTries) {
            $issues[] = 'default_tries must be a positive integer not exceeding max_tries';
        }

        $backoff = $standards['default_backoff_seconds'] ?? null;
        if (! is_array($backoff) || $backoff === [] || array_filter($backoff, fn ($v) => ! is_int($v) || $v < 1) !== []) {
            $issues[] = 'default_backoff_seconds must be a non-empty list of positive integers';
        }

        $timeout = $standards['default_timeout_seconds'] ?? null;
        $maxTimeout = $standards['max_timeout_seconds'] ?? null;
        if (! is_int($timeout) || $timeout < 1 || ! is_int($maxTimeout) || $timeout > $maxTimeout) {
            $issues[] = 'default_timeout_seconds must be a positive integer not exceeding max_timeout_seconds';
        }

        return $issues;
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array{total: int, compliant: list<string>, non_compliant: list<string>}
     */
    private function scanQueuedClasses(array $config): array
    {
        $paths = (array) ($config['job_scan']['paths'] ?? ['app']);
        $baseClass = class_basename((string) ($config['job_scan']['approved_base_class'] ?? 'EnterpriseQueueJob'));
        $trait = class_basename((string) ($config['job_scan']['approved_trait'] ?? 'EnterpriseQueueRetryDefaults'));

        $compliant = [];
        $nonCompliant = [];
        $total = 0;

        foreach ($this->phpFiles($paths) as $file) {
            $source = (string) file_get_contents($file);
            if (! preg_match('/\b(?:class|enum)\s+\w+[^;{]*implements[^;{]*ShouldQueue/s', $source)) {
                continue;
            }

            $total++;
            $relative = str_replace(base_path().'/', '', $file);

            $usesApprovedDefaults = str_contains($source, $baseClass) || str_contains($source, $trait);
            $declaresExplicitPolicy = preg_match('/\$tries\b/', $source) === 1
                && (preg_match('/\$backoff\b/', $source) === 1 || preg_match('/function\s+backoff\s*\(/', $source) === 1)
                && preg_match('/\$timeout\b/', $source) === 1;

            if ($usesApprovedDefaults || $declaresExplicitPolicy) {
                $compliant[] = $relative;
            } else {
                $nonCompliant[] = $relative;
            }
        }

        return ['total' => $total, 'compliant' => $compliant, 'non_compliant' => $nonCompliant];
    }

    /**
     * @param  array<string, mixed>  $config
     * @return list<string>
     */
    private function scanUnsafeCommands(array $config): array
    {
        $paths = (array) ($config['unsafe_command_scan']['paths'] ?? ['app']);
        $patterns = array_values(array_filter(array_map('strval', (array) ($config['unsafe_command_scan']['patterns'] ?? []))));
        if ($patterns === []) {
            return [];
        }

        $hits = [];
        foreach ($this->phpFiles($paths) as $file) {
            $source = (string) file_get_contents($file);
            foreach ($patterns as $pattern) {
                if (str_contains($source, $pattern)) {
                    $hits[] = str_replace(base_path().'/', '', $file);
                    break;
                }
            }
        }

        return $hits;
    }

    /**
     * @param  list<string>  $paths
     * @return list<string>
     */
    private function phpFiles(array $paths): array
    {
        $existing = array_values(array_filter(
            array_map(fn (string $path) => base_path($path), $paths),
            'is_dir'
        ));
        if ($existing === []) {
            return [];
        }

        $files = [];
        foreach (Finder::create()->files()->in($existing)->name('*.php') as $file) {
            $files[] = $file->getRealPath();
        }

        return $files;
    }

    private function tableExists(string $table): bool
    {
        try {
            return Schema::hasTable($table);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  list<array<string, mixed>>  $checks
     * @param  list<string>  $warnings
     * @param  array<string, mixed>  $jobScan
     * @return array<string, mixed>
     */
    private function finalize(array $config, array $checks, array $warnings, array $jobScan): array
    {
        $errors = count(array_filter($checks, fn (array $c) => $c['status'] === 'failed'));
        $warningCount = count(array_filter($checks, fn (array $c) => $c['status'] === 'warning'));
        $decision = $errors > 0 ? 'FAIL' : ($warningCount > 0 ? 'WATCH' : 'GO');

        return [
            'generated_at' => now()->toIso8601String(),
            'sprint' => 'ENT-5',
            'environment' => (string) config('app.env'),
            'decision' => $decision,
            'readiness_status' => $errors > 0 ? 'fail' : ($warningCount > 0 ? 'warning' : 'queue_retry_ready'),
            'queue_connection' => (string) config('queue.default'),
            'failed_driver' => (string) config('queue.failed.driver'),
            'failed_jobs_table_exists' => $this->tableExists((string) ($config['failed_jobs']['required_table'] ?? 'failed_jobs')),
            'retry_standards' => (array) ($config['retry_standards'] ?? []),
            'allowed_queue_names' => (array) ($config['allowed_queue_names'] ?? []),
            'critical_idempotency_domains' => (array) ($config['critical_idempotency_domains'] ?? []),
            'queued_classes_total' => (int) ($jobScan['total'] ?? 0),
            'queued_classes_compliant' => (array) ($jobScan['compliant'] ?? []),
            'queued_classes_non_compliant' => (array) ($jobScan['non_compliant'] ?? []),
            'worker' => [
                'runbook_doc' => (string) ($config['worker']['runbook_doc'] ?? ''),
                'supervisor_required_in' => (array) ($config['worker']['supervisor_required_in'] ?? []),
                'started_by_deploy' => (bool) ($config['worker']['started_by_deploy'] ?? false),
            ],
            'warnings' => $warnings,
            'checks' => $checks,
            'summary' => [
                'decision' => $decision,
                'checks' => count($checks),
                'passed' => count(array_filter($checks, fn (array $c) => $c['status'] === 'passed')),
                'warnings' => $warningCount,
                'errors' => $errors,
            ],
            'privacy' => ['privacy_safe' => true, 'row_level_data' => false],
        ];
    }

    /**
     * @return array{check_id: string, status: string, blocking: bool, message: string}
     */
    private function pass(string $id, string $message): array
    {
        return ['check_id' => $id, 'status' => 'passed', 'blocking' => false, 'message' => $message];
    }

    /**
     * @return array{check_id: string, status: string, blocking: bool, message: string}
     */
    private function warn(string $id, string $message): array
    {
        return ['check_id' => $id, 'status' => 'warning', 'blocking' => false, 'message' => $message];
    }

    /**
     * @return array{check_id: string, status: string, blocking: bool, message: string}
     */
    private function fail(string $id, string $message): array
    {
        return ['check_id' => $id, 'status' => 'failed', 'blocking' => true, 'message' => $message];
    }
}
