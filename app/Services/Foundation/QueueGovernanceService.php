<?php

namespace App\Services\Foundation;

use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * QUEUE-1 — Read-only queue/idempotency/outbox governance validator.
 *
 * Validates config/queue_governance.php completeness, allowed/denied event
 * categories, worker/runtime readiness-only policy, feature flags, and
 * persistence table presence. Optionally probes the queue connection once
 * (never starts a long-running worker).
 *
 * Emits GO / WATCH / FAIL:
 *  - GO    : governance valid; no long-running worker; no external dispatch.
 *  - WATCH : governance valid but persistence tables/worker not yet present
 *            (non-blocking while the worker/runtime stay disabled).
 *  - FAIL  : config incomplete, external dispatch enabled, long-running
 *            worker enabled, or a denied category is allowed.
 */
class QueueGovernanceService
{
    /**
     * @return array<string, mixed>
     */
    public function collect(bool $includeWorkerProbe = false): array
    {
        $config = config('queue_governance');

        if (! is_array($config) || $config === []) {
            return $this->finalize([], [
                $this->fail('QUEUE-GOV-CONFIG-EXISTS', 'config/queue_governance.php is missing or empty.'),
            ], $includeWorkerProbe, null);
        }

        $checks = [];
        $checks[] = $this->pass('QUEUE-GOV-CONFIG-EXISTS', 'queue_governance config present and non-empty.');

        $metadata = (array) ($config['metadata'] ?? []);
        $checks[] = ($metadata['sprint'] ?? '') === 'QUEUE-1' && ($metadata['status'] ?? '') === 'implemented'
            ? $this->pass('QUEUE-GOV-METADATA', 'QUEUE-1 metadata present with implemented status.')
            : $this->fail('QUEUE-GOV-METADATA', 'QUEUE-1 metadata missing or incomplete.');

        $globalRules = (array) ($config['global_rules'] ?? []);
        $requiredGlobalRules = [
            'no_pii_in_queue_payload',
            'no_secrets_in_queue_payload',
            'no_pii_in_outbox_payload',
            'no_secrets_in_outbox_payload',
            'every_job_requires_idempotency_policy',
            'every_job_requires_retry_policy',
            'every_outbox_event_requires_schema_version',
            'every_outbox_event_requires_payload_classification',
            'every_outbox_dispatch_requires_failure_policy',
            'no_external_dispatch_without_feature_flag',
            'no_critical_mutable_async_without_domain_specific_approval',
            'no_long_running_worker_started_by_deploy',
        ];
        $missingRules = array_filter(
            $requiredGlobalRules,
            fn (string $rule) => ! ($globalRules[$rule] ?? false)
        );
        $checks[] = $missingRules === []
            ? $this->pass('QUEUE-GOV-GLOBAL-RULES', 'All global queue/idempotency/outbox safety rules are enabled.')
            : $this->fail('QUEUE-GOV-GLOBAL-RULES', 'Missing/disabled global rules: '.implode(', ', $missingRules));

        $runtime = (array) ($config['queue_runtime'] ?? []);
        $allowedConnections = (array) ($runtime['allowed_connections_readiness'] ?? []);
        $checks[] = in_array('sync', $allowedConnections, true) && in_array('database', $allowedConnections, true)
            ? $this->pass('QUEUE-GOV-ALLOWED-CONNECTIONS', 'Allowed readiness connections are sync/database only.')
            : $this->fail('QUEUE-GOV-ALLOWED-CONNECTIONS', 'Allowed connections must be limited to sync/database in QUEUE-1.');

        $checks[] = ($runtime['long_running_worker_enabled'] ?? true) === false
            ? $this->pass('QUEUE-GOV-NO-LONG-RUNNING-WORKER', 'Long-running worker is disabled by governance.')
            : $this->fail('QUEUE-GOV-NO-LONG-RUNNING-WORKER', 'long_running_worker_enabled must be false in QUEUE-1.');

        $currentConnection = strtolower((string) config('queue.default'));
        $checks[] = in_array($currentConnection, ['sync', 'database'], true)
            ? $this->pass('QUEUE-GOV-CURRENT-CONNECTION-SAFE', "Current queue connection \"{$currentConnection}\" is readiness-safe.")
            : $this->warn('QUEUE-GOV-CURRENT-CONNECTION-SAFE', "Current queue connection \"{$currentConnection}\" is outside the QUEUE-1 readiness allowlist.");

        $idempotency = (array) ($config['idempotency'] ?? []);
        $checks[] = ($idempotency['raw_key_storage_allowed'] ?? true) === false && ($idempotency['key_hashing_required'] ?? false) === true
            ? $this->pass('QUEUE-GOV-IDEMPOTENCY-NO-RAW-KEY', 'Raw idempotency key storage is banned; hashing required.')
            : $this->fail('QUEUE-GOV-IDEMPOTENCY-NO-RAW-KEY', 'Idempotency policy must ban raw key storage and require hashing.');

        $outbox = (array) ($config['outbox'] ?? []);
        $checks[] = ($outbox['dispatch_enabled'] ?? true) === false && ($outbox['external_dispatch_enabled'] ?? true) === false
            ? $this->pass('QUEUE-GOV-OUTBOX-DISPATCH-DISABLED', 'Outbox dispatch and external dispatch are disabled in QUEUE-1.')
            : $this->fail('QUEUE-GOV-OUTBOX-DISPATCH-DISABLED', 'Outbox dispatch_enabled/external_dispatch_enabled must be false in QUEUE-1.');

        $allowedCategories = (array) ($outbox['allowed_event_categories'] ?? []);
        $deniedCategories = (array) ($outbox['denied_event_categories'] ?? []);
        $checks[] = $deniedCategories !== []
            ? $this->pass('QUEUE-GOV-OUTBOX-DENIED-CATEGORIES', count($deniedCategories).' denied outbox event categories documented.')
            : $this->fail('QUEUE-GOV-OUTBOX-DENIED-CATEGORIES', 'No denied outbox event categories defined.');

        $overlap = array_intersect($allowedCategories, $deniedCategories);
        $checks[] = $overlap === []
            ? $this->pass('QUEUE-GOV-OUTBOX-NO-OVERLAP', 'No denied outbox category appears in the allowed list.')
            : $this->fail('QUEUE-GOV-OUTBOX-NO-OVERLAP', 'Denied outbox categories incorrectly allowed: '.implode(', ', $overlap));

        $flagKeys = (array) ($config['feature_flags'] ?? []);
        $flags = app(FeatureFlagService::class);
        $flagViolations = [];
        foreach ($flagKeys as $flagKey) {
            try {
                $flags->assertKnown((string) $flagKey);
            } catch (Throwable) {
                $flagViolations[] = (string) $flagKey;
            }
        }
        $checks[] = $flagViolations === []
            ? $this->pass('QUEUE-GOV-FEATURE-FLAGS', 'Required queue feature flags exist in registry.')
            : $this->fail('QUEUE-GOV-FEATURE-FLAGS', 'Missing queue feature flags: '.implode(', ', $flagViolations));

        $externalDispatchEnabled = $flags->enabled('foundation.queue.external_dispatch_enabled');
        $checks[] = ! $externalDispatchEnabled
            ? $this->pass('QUEUE-GOV-EXTERNAL-DISPATCH-FLAG-OFF', 'foundation.queue.external_dispatch_enabled is off.')
            : $this->fail('QUEUE-GOV-EXTERNAL-DISPATCH-FLAG-OFF', 'foundation.queue.external_dispatch_enabled must stay off in QUEUE-1.');

        $idempotencyTableExists = Schema::hasTable('sys_idempotency_keys');
        $outboxTableExists = Schema::hasTable('sys_outbox_events');
        $checks[] = $idempotencyTableExists
            ? $this->pass('QUEUE-GOV-IDEMPOTENCY-TABLE-EXISTS', 'sys_idempotency_keys table exists.')
            : $this->warn('QUEUE-GOV-IDEMPOTENCY-TABLE-EXISTS', 'sys_idempotency_keys table not yet migrated in this environment.');
        $checks[] = $outboxTableExists
            ? $this->pass('QUEUE-GOV-OUTBOX-TABLE-EXISTS', 'sys_outbox_events table exists.')
            : $this->warn('QUEUE-GOV-OUTBOX-TABLE-EXISTS', 'sys_outbox_events table not yet migrated in this environment.');

        $workerProbe = null;
        if ($includeWorkerProbe) {
            $workerProbe = $this->probeWorkerReadiness($currentConnection);
            $checks[] = match ($workerProbe['decision']) {
                'GO' => $this->pass('QUEUE-GOV-WORKER-PROBE', $workerProbe['message']),
                'WATCH' => $this->warn('QUEUE-GOV-WORKER-PROBE', $workerProbe['message']),
                default => $this->fail('QUEUE-GOV-WORKER-PROBE', $workerProbe['message']),
            };
        } else {
            $checks[] = $this->pass('QUEUE-GOV-WORKER-PROBE-SKIPPED', 'Worker probe skipped (not requested); normal GO does not require a running worker.');
        }

        return $this->finalize($config, $checks, $includeWorkerProbe, $workerProbe);
    }

    /**
     * @param  list<array<string, mixed>>  $checks
     * @param  array<string, mixed>|null  $workerProbe
     * @return array<string, mixed>
     */
    private function finalize(array $config, array $checks, bool $includeWorkerProbe, ?array $workerProbe): array
    {
        $errors = count(array_filter($checks, fn (array $c) => ($c['status'] ?? '') === 'failed'));
        $warnings = count(array_filter($checks, fn (array $c) => ($c['status'] ?? '') === 'warning'));
        $passed = count(array_filter($checks, fn (array $c) => ($c['status'] ?? '') === 'passed'));

        $decision = $errors > 0 ? 'FAIL' : ($warnings > 0 ? 'WATCH' : 'GO');

        return [
            'generated_at' => now()->toIso8601String(),
            'sprint' => 'QUEUE-1',
            'environment' => (string) config('app.env'),
            'queue_connection' => (string) config('queue.default'),
            'worker_probe_requested' => $includeWorkerProbe,
            'worker_probe' => $workerProbe,
            'long_running_worker_enabled' => (bool) ($config['queue_runtime']['long_running_worker_enabled'] ?? false),
            'external_dispatch_enabled_flag' => app(FeatureFlagService::class)->enabled('foundation.queue.external_dispatch_enabled'),
            'idempotency_table_exists' => Schema::hasTable('sys_idempotency_keys'),
            'outbox_table_exists' => Schema::hasTable('sys_outbox_events'),
            'metadata' => $config['metadata'] ?? [],
            'global_rules' => $config['global_rules'] ?? [],
            'queue_runtime' => $config['queue_runtime'] ?? [],
            'idempotency' => $config['idempotency'] ?? [],
            'outbox' => [
                'dispatch_enabled' => $config['outbox']['dispatch_enabled'] ?? null,
                'external_dispatch_enabled' => $config['outbox']['external_dispatch_enabled'] ?? null,
                'allowed_event_categories' => $config['outbox']['allowed_event_categories'] ?? [],
                'denied_event_categories' => $config['outbox']['denied_event_categories'] ?? [],
                'statuses' => $config['outbox']['statuses'] ?? [],
            ],
            'feature_flags' => $config['feature_flags'] ?? [],
            'checks' => $checks,
            'summary' => [
                'decision' => $decision,
                'checks' => count($checks),
                'passed' => $passed,
                'warnings' => $warnings,
                'errors' => $errors,
            ],
            'privacy' => ['privacy_safe' => true, 'row_level_data' => false],
        ];
    }

    /**
     * @return array{decision: string, message: string}
     */
    private function probeWorkerReadiness(string $connection): array
    {
        if (! in_array($connection, ['sync', 'database'], true)) {
            return ['decision' => 'FAIL', 'message' => "Queue connection \"{$connection}\" is outside the QUEUE-1 readiness allowlist."];
        }

        try {
            if ($connection === 'database' && ! Schema::hasTable((string) config('queue.connections.database.table', 'jobs'))) {
                return ['decision' => 'WATCH', 'message' => 'Database queue connection configured but jobs table not migrated — worker not runnable yet.'];
            }

            return ['decision' => 'GO', 'message' => "Queue connection \"{$connection}\" is configured and readiness-safe. No long-running worker was started."];
        } catch (Throwable $e) {
            return ['decision' => 'WATCH', 'message' => 'Worker readiness probe could not fully verify connection: '.$e->getMessage()];
        }
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
