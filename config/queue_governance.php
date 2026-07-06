<?php

/**
 * QUEUE-1 — Queue, idempotency & outbox foundation governance.
 *
 * Read-only source of truth consumed by App\Services\Foundation\QueueGovernanceService
 * (via foundation:queue-governance-check), App\Services\Foundation\IdempotencyService,
 * and App\Services\Foundation\OutboxService. Readiness/governance only — does NOT
 * enable a long-running queue worker, Redis/SQS/broker production runtime, or
 * external outbox dispatch.
 */
return [
    'metadata' => [
        'sprint' => 'QUEUE-1',
        'status' => 'implemented',
        'owner' => 'Foundation',
        'decision' => 'readiness_governance_only',
        'production_worker_policy' => 'no_long_running_worker_in_queue_1',
    ],

    'global_rules' => [
        'no_pii_in_queue_payload' => true,
        'no_secrets_in_queue_payload' => true,
        'no_pii_in_outbox_payload' => true,
        'no_secrets_in_outbox_payload' => true,
        'every_job_requires_idempotency_policy' => true,
        'every_job_requires_retry_policy' => true,
        'every_outbox_event_requires_schema_version' => true,
        'every_outbox_event_requires_payload_classification' => true,
        'every_outbox_dispatch_requires_failure_policy' => true,
        'no_external_dispatch_without_feature_flag' => true,
        'no_critical_mutable_async_without_domain_specific_approval' => true,
        'no_long_running_worker_started_by_deploy' => true,
    ],

    'queue_runtime' => [
        'default_connection_policy' => 'database_or_sync_only_in_queue_1',
        'allowed_connections_readiness' => [
            'sync',
            'database',
        ],
        'denied_for_queue_1' => [
            'redis production runtime',
            'sqs production runtime',
            'external broker production runtime',
        ],
        'worker_readiness_only' => true,
        'worker_probe_allowed' => 'once_only',
        'long_running_worker_enabled' => false,
    ],

    'idempotency' => [
        'enabled_foundation' => true,
        'persistence_strategy' => 'database_additive_if_implemented',
        'key_policy' => 'raw_key_hashed_with_sha256_before_storage_or_comparison',
        'key_hashing_required' => true,
        'raw_key_storage_allowed' => false,
        'ttl_policy' => 'reserved_rows_expire_via_expires_at_and_are_swept_by_expireOld',
        'scopes' => [
            'queue_job',
            'outbox_event',
            'api_request_future',
        ],
        'states' => [
            'reserved',
            'completed',
            'failed',
            'expired',
        ],
        'conflict_policy' => 'duplicate_reserve_within_ttl_returns_existing_reservation_without_reprocessing',
        'replay_policy' => 'completed_key_replay_returns_stored_response_fingerprint_without_reexecution',
    ],

    'outbox' => [
        'enabled_foundation' => true,
        'persistence_strategy' => 'database_additive_if_implemented',
        'dispatch_enabled' => false,
        'external_dispatch_enabled' => false,
        'schema_version_required' => true,
        'payload_classification_required' => true,
        'safe_payload_only' => true,
        'retry_policy' => 'max_attempts_configurable_default_5_with_locked_until_backoff',
        'failure_policy' => 'exceeding_max_attempts_marks_event_failed_with_last_error_code_and_summary',
        'statuses' => [
            'pending',
            'processing',
            'dispatched',
            'failed',
            'cancelled',
        ],
        'allowed_event_categories' => [
            'foundation.internal_test',
            'release.evidence_summary',
            'audit.future_safe_event',
        ],
        'denied_event_categories' => [
            'patient.identity_pii',
            'rme.medical_record_content',
            'cashier.payment_sensitive_state',
            'inventory.ledger_raw_payload',
            'auth.permission_decision',
            'branch_context.runtime_context',
        ],
    ],

    'feature_flags' => [
        'foundation.queue.outbox_readiness',
        'foundation.queue.idempotency_required',
        'foundation.queue.worker_readiness',
        'foundation.queue.external_dispatch_enabled',
    ],

    'go_criteria' => [
        'queue_governance config complete',
        'allowed queue connections are readiness-only (sync/database)',
        'no long-running worker enabled',
        'no external outbox dispatch enabled',
        'idempotency raw key storage banned',
        'outbox denied event categories documented and not allowed',
        'feature flags exist and risky flags default false',
        'foundation GO preserved',
    ],

    'watch_criteria' => [
        'worker readiness probe not run / no worker configured (non-blocking while worker stays disabled)',
        'idempotency/outbox tables not yet migrated on this environment',
    ],

    'no_go_criteria' => [
        'long-running worker enabled',
        'redis/sqs/external broker production runtime enabled',
        'external outbox dispatch enabled without governance GO',
        'raw idempotency key stored',
        'pii or secrets permitted in queue/outbox payload',
        'foundation GO regressed',
    ],

    /*
     * ENT-5 — Queue, Retry & Failed Job Governance.
     *
     * Operationalizes the QUEUE-1 design: central retry/backoff/timeout
     * standard, failed-job storage requirements, per-environment queue
     * connection policy, and static conventions every future ShouldQueue
     * class must follow. Consumed by
     * App\Support\Queue\QueueRetryFailedJobReadinessService via
     * foundation:queue-retry-failed-job-check.
     */
    'ent5_retry_failed_job' => [
        'metadata' => [
            'sprint' => 'ENT-5',
            'status' => 'implemented',
            'policy_doc' => 'docs/architecture/queue-retry-failed-job-governance.md',
        ],

        'retry_standards' => [
            'default_tries' => 3,
            'max_tries' => 5,
            'default_backoff_seconds' => [10, 60, 180],
            'default_timeout_seconds' => 120,
            'max_timeout_seconds' => 600,
        ],

        'allowed_queue_names' => [
            'default',
            'reports',
            'notifications',
            'maintenance',
        ],

        // Per-environment queue connection policy. sync is a local/testing
        // convenience only; pilot/production must use a real broker-backed
        // connection so retries and failed jobs actually exist.
        'environment_connection_policy' => [
            'local' => ['sync', 'database'],
            'testing' => ['sync', 'database'],
            'pilot' => ['database', 'redis'],
            'staging' => ['database', 'redis'],
            'production' => ['database', 'redis'],
        ],
        'fallback_allowed_connections' => ['database'],

        'failed_jobs' => [
            'required_driver' => 'database-uuids',
            'required_table' => 'failed_jobs',
        ],

        'job_scan' => [
            'paths' => ['app'],
            'approved_base_class' => 'App\\Support\\Queue\\EnterpriseQueueJob',
            'approved_trait' => 'App\\Support\\Queue\\EnterpriseQueueRetryDefaults',
        ],

        // Destructive queue commands must never be automated inside app
        // code — they stay manual, operator-run, and documented in the
        // worker runbook. Patterns live here (config) so app/ source never
        // has to contain the literal strings the scanner looks for.
        'unsafe_command_scan' => [
            'paths' => ['app'],
            'patterns' => [
                'queue:flush',
                'queue:clear',
                'flushFailed(',
            ],
        ],

        'worker' => [
            'runbook_doc' => 'docs/architecture/queue-worker-operations-runbook.md',
            'supervisor_required_in' => ['pilot', 'staging', 'production'],
            'started_by_deploy' => false,
        ],

        // Jobs in these domains must be idempotent (QUEUE-1 idempotency
        // foundation) before they may be queued.
        'critical_idempotency_domains' => [
            'payments',
            'invoices',
            'inventory',
            'lab_candidate_generation',
            'notifications',
        ],
    ],
];
