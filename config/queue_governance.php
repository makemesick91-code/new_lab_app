<?php

use App\Jobs\Foundation\QueueWorkerSmokeJob;
use App\Modules\LegacyOdontogram\Jobs\ProcessLegacyOdontogramPdfImport;
use App\Modules\LegacyRme\Jobs\ProcessLegacyRmePdfImport;
use App\Modules\Satusehat\Jobs\PrepareSatusehatSubmissionBatchJob;
use App\Modules\Satusehat\Jobs\ReconcileSatusehatItemJob;
use App\Modules\Satusehat\Jobs\SubmitSatusehatItemJob;

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

    /*
     * ENT-6 — Idempotency & Outbox Foundation.
     *
     * Hardens the shipped QUEUE-1 idempotency/outbox runtime primitives with
     * an enterprise governance section and readiness command. This does not
     * enable external dispatch, auto-send WhatsApp, or auto-create LabOrder.
     */
    'ent6_idempotency_outbox' => [
        'metadata' => [
            'sprint' => 'ENT-6',
            'status' => 'implemented',
            'policy_doc' => 'docs/architecture/idempotency-outbox-foundation-governance.md',
        ],
        'governance_section' => 'idempotency_outbox_governance',
        'readiness_command' => 'foundation:idempotency-outbox-check',
        'external_dispatch_enabled' => false,
        'required_commands' => [
            'foundation:idempotency-outbox-check',
            'foundation:idempotency-audit',
            'foundation:outbox-audit',
            'foundation:queue-governance-check',
            'foundation:queue-retry-failed-job-check',
        ],
        'critical_integration_domains' => [
            'payments',
            'invoices',
            'inventory',
            'lab_candidate_generation',
            'notifications',
        ],
        'denied_runtime_behaviors' => [
            'direct external send from request path',
            'auto-create LabOrder from RME payment',
            'raw idempotency key storage',
            'PII or secret in outbox payload',
            'external outbox dispatch before later approved sprint',
        ],
    ],

    'go_criteria_ent6' => [
        'idempotency/outbox governance command passes',
        'QUEUE-1 queue governance remains GO',
        'ENT-5 queue retry governance remains GO',
        'raw idempotency key storage remains banned',
        'outbox payload classification and schema version remain required',
        'external dispatch remains disabled',
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
            // LEGACY-RME-PDF-ROLL-2: dedicated queue for legacy archive page
            // rasterization, so a multi-minute render never blocks default
            // work. A queue the worker does not consume is a silently stuck
            // pipeline, so this list and the worker unit must stay in step.
            'legacy-rme-documents',
            // BUGFIX-LEGACY-ODONTOGRAM-QUEUE-CONSUMER-1: the same dedicated
            // treatment for legacy odontogram charts. Declaring it here is only
            // half the contract — see producer_consumer_contract below, which is
            // what actually proves a worker drains it.
            'legacy-odontogram-documents',
            // SATUSEHAT submission jobs resolve this name. It is declared so the
            // set of names the application can dispatch to is complete and
            // honest; it is deliberately NOT consumed by the worker while
            // SATUSEHAT is disabled, and the contract below suspends the
            // consumer requirement against that same flag rather than by
            // hard-coding an exception.
            'satusehat',
        ],

        /*
         * BUGFIX-LEGACY-ODONTOGRAM-QUEUE-CONSUMER-1 — the producer ↔ consumer
         * contract.
         *
         * allowed_queue_names above says which queue names are permitted. It
         * never said whether anything CONSUMES them, and that gap is not
         * theoretical: it stalled a production pipeline twice. Legacy RME hit
         * it first; Legacy Odontogram shipped its own dedicated queue, no
         * worker took it, and a real clinical document sat at QUEUED with
         * attempts=0 and an empty failed_jobs table — indistinguishable from
         * work still in progress.
         *
         * This registry is what turns "declared" into "actually drained". Each
         * entry names the config key that RESOLVES the queue at runtime, so an
         * environment override is followed rather than a source literal that
         * production may not use. Entries are held to the tracked worker unit's
         * ExecStart --queue list, and the registry itself is checked for
         * completeness against the source tree, so a future module cannot add a
         * dedicated queue and quietly escape the check.
         */
        'producer_consumer_contract' => [
            'contract_doc' => 'docs/architecture/queue-producer-consumer-contract.md',

            // The repository-tracked production worker. This is the only unit
            // CI can verify, so it is the one the contract is enforced against.
            'worker_unit_file' => 'deploy/systemd/daengtisiams-queue-worker.service',

            // What systemd actually runs. Present only on the production host;
            // drift against the tracked unit is reported as a WARNING, never a
            // failure, because the deploy is forbidden from installing or
            // starting a worker (ENT-5) and activation is a later operator step.
            'installed_worker_unit_file' => '/etc/systemd/system/daengtisiams-queue-worker.service',

            'produced_queues' => [
                [
                    'id' => 'legacy-rme-render',
                    'jobs' => [ProcessLegacyRmePdfImport::class],
                    'config_key' => 'legacy_rme.processing.queue',
                    'fallback' => 'legacy-rme-documents',
                ],
                [
                    'id' => 'legacy-odontogram-render',
                    'jobs' => [ProcessLegacyOdontogramPdfImport::class],
                    'config_key' => 'legacy_odontogram.processing.queue',
                    'fallback' => 'legacy-odontogram-documents',
                ],
                [
                    // SATUSEHAT submission is built but deliberately switched
                    // off: SATUSEHAT-2 is WATCH, no credentials exist, and the
                    // submission service is fail-closed. Requiring a worker for
                    // a queue nothing can dispatch to would be noise, so the
                    // requirement is SUSPENDED — dynamically, against the same
                    // flag the runtime checks. Enable SATUSEHAT and the
                    // requirement returns by itself; this is not an exemption
                    // list someone can park a queue on.
                    'id' => 'satusehat-submission',
                    'jobs' => [
                        PrepareSatusehatSubmissionBatchJob::class,
                        SubmitSatusehatItemJob::class,
                        ReconcileSatusehatItemJob::class,
                    ],
                    'config_key' => 'satusehat.queue',
                    'fallback' => 'satusehat',
                    'consumer_required_when' => 'satusehat.enabled',
                ],
                [
                    'id' => 'foundation-worker-smoke',
                    'jobs' => [QueueWorkerSmokeJob::class],
                    'literal' => 'maintenance',
                ],
            ],

            /*
             * Completeness scan — the reason this contract is generic rather
             * than a list of the queues someone happened to remember.
             *
             * Any source file that routes work to a named queue must be covered
             * by an entry above. The patterns live here, in config, so the
             * scanner's own source never carries the literals it searches for —
             * the same discipline unsafe_command_scan uses.
             */
            'queue_assignment_scan' => [
                'paths' => ['app'],
                'patterns' => [
                    'onQueue(',
                    'pushOn(',
                    'laterOn(',
                    'public $queue',
                    'protected $queue',
                ],
            ],

            /*
             * Directives systemd only honours in one section.
             *
             * The production unit declared StartLimitIntervalSec under
             * [Service]; systemd logged "Unknown key name ... ignoring" and
             * applied its own default. The file and the running service
             * disagreed, and nothing caught it.
             */
            'unit_directive_sections' => [
                'StartLimitIntervalSec' => 'Unit',
                'StartLimitBurst' => 'Unit',
            ],
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
