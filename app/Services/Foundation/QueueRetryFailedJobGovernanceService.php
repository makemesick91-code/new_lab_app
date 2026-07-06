<?php

namespace App\Services\Foundation;

use App\Support\Queue\QueueRetryFailedJobReadinessService;

/**
 * ENT-5 — Queue, Retry & Failed Job governance rule catalog.
 *
 * Publishes ENT5-Q001..Q008 into the foundation governance summary as the
 * queue_retry_governance section and reports the current runtime readiness
 * signal. Informational only — never wired into the blocking
 * combinedDecision, matching every sibling foundation governance section.
 * Deliberately separate from QueueGovernanceService (QUEUE-1) so the shipped
 * QUEUE-1 checks and tests stay untouched — the same separation pattern as
 * cache_governance vs cache_redis_governance.
 */
class QueueRetryFailedJobGovernanceService
{
    public function __construct(private readonly QueueRetryFailedJobReadinessService $readiness) {}

    /**
     * @return list<array{id: string, title: string, description: string}>
     */
    public static function rules(): array
    {
        return [
            [
                'id' => 'ENT5-Q001',
                'title' => 'Queue connection must match the per-environment policy',
                'description' => 'The active queue connection must be allowed for the current environment; sync is a local/testing convenience only and is forbidden in pilot/staging/production, where a broker-backed connection (database or redis) is required so retries and failed jobs actually exist.',
            ],
            [
                'id' => 'ENT5-Q002',
                'title' => 'Failed job storage must be configured and inspectable',
                'description' => 'Failed jobs must be stored via the database-uuids driver in the failed_jobs table so they can be listed, retried, and forgotten individually by an operator; failed-job storage must never be silently disabled.',
            ],
            [
                'id' => 'ENT5-Q003',
                'title' => 'Central retry/backoff/timeout standard is the single source of truth',
                'description' => 'Default tries, backoff steps, and timeout for queued work are defined centrally in the queue governance config with hard caps; per-job overrides are allowed but must stay within the caps and be justified in review.',
            ],
            [
                'id' => 'ENT5-Q004',
                'title' => 'Critical-domain jobs must be idempotent before queueing',
                'description' => 'Jobs touching payments, invoices, inventory, lab candidate generation, or notifications must be idempotent (QUEUE-1 idempotency foundation) and must never roll back a committed payment because a secondary job failed.',
            ],
            [
                'id' => 'ENT5-Q005',
                'title' => 'Destructive queue commands are never automated in app code',
                'description' => 'Commands that flush or clear queues or bulk-delete failed jobs stay manual and operator-run per the worker runbook; app code, schedulers, and deploy scripts must never invoke them automatically.',
            ],
            [
                'id' => 'ENT5-Q006',
                'title' => 'Worker operations follow the runbook; deploy never starts workers',
                'description' => 'Queue workers run under an explicit operator/supervisor setup documented in the worker operations runbook; deploys must never start, restart into existence, or enable a long-running worker as a side effect.',
            ],
            [
                'id' => 'ENT5-Q007',
                'title' => 'Queue retry governance is visible in the foundation summary',
                'description' => 'The queue_retry_governance section must appear in the foundation governance summary as an informational, non-blocking section and must never weaken or replace the QUEUE-1 queue_governance section.',
            ],
            [
                'id' => 'ENT5-Q008',
                'title' => 'Every new ShouldQueue class inherits or declares an approved retry policy',
                'description' => 'Any new job or listener implementing ShouldQueue must extend EnterpriseQueueJob, use EnterpriseQueueRetryDefaults, or explicitly declare tries, backoff, and timeout — and must stay covered by foundation:queue-retry-failed-job-check.',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function collect(): array
    {
        $readiness = $this->readiness->collect();

        return [
            'sprint' => 'ENT-5',
            'decision' => $readiness['decision'] ?? 'FAIL',
            'readiness_status' => $readiness['readiness_status'] ?? 'fail',
            'queue_connection' => $readiness['queue_connection'] ?? null,
            'failed_driver' => $readiness['failed_driver'] ?? null,
            'failed_jobs_table_exists' => $readiness['failed_jobs_table_exists'] ?? false,
            'retry_standards' => $readiness['retry_standards'] ?? [],
            'queued_classes_total' => $readiness['queued_classes_total'] ?? 0,
            'queued_classes_non_compliant' => $readiness['queued_classes_non_compliant'] ?? [],
            'warnings' => $readiness['warnings'] ?? [],
            'checks' => $readiness['checks'] ?? [],
            'summary' => $readiness['summary'] ?? [],
            'rules' => self::rules(),
        ];
    }
}
