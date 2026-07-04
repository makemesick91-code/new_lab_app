<?php

/**
 * NSF-9 — Feature flag foundation (release safety).
 *
 * Read-only, config-driven, env-overridable feature flag registry.
 * Consumed by:
 *  - App\Services\Foundation\FeatureFlagService
 *  - foundation:feature-flags (governance command)
 *
 * RULES:
 *  - Every flag MUST define the required metadata fields (see
 *    FeatureFlagService::REQUIRED_METADATA).
 *  - Future risky foundation infra flags (cache/queue/pgbouncer/storage/
 *    stateless/lb/replica/partitioning/search/national-architecture) MUST
 *    default to false.
 *  - Governance/safety flags introduced in NSF-9 itself may default true
 *    only because the corresponding capability is implemented in NSF-9.
 *  - This file never toggles business behavior on its own — services must
 *    explicitly call FeatureFlagService::enabled() to branch on a flag.
 */
return [
    'flags' => [

        // --- Future foundation infra readiness flags (risky, default OFF) ---

        'foundation.cache.redis_readiness' => [
            'name' => 'Redis Cache Readiness',
            'description' => 'Enables Redis-backed caching once CACHE-1 invalidation governance ships.',
            'default' => false,
            'env_key' => 'FEATURE_FOUNDATION_CACHE_REDIS_READINESS',
            'owner' => 'platform-foundation',
            'risk_level' => 'high',
            'rollout_status' => 'implemented',
            'review_target' => 'CACHE-1',
            'dependencies' => ['CACHE-1'],
            'rollback_action' => 'Set env override to false; no data migration required.',
        ],
        'foundation.cache.invalidation_governance' => [
            'name' => 'Cache Invalidation Governance',
            'description' => 'Enforces mandatory invalidation rules/tests before any critical value is cached.',
            'default' => false,
            'env_key' => 'FEATURE_FOUNDATION_CACHE_INVALIDATION_GOVERNANCE',
            'owner' => 'platform-foundation',
            'risk_level' => 'high',
            'rollout_status' => 'implemented',
            'review_target' => 'CACHE-1',
            'dependencies' => ['CACHE-1'],
            'rollback_action' => 'Set env override to false; caching stays disabled by dependency.',
        ],
        'foundation.queue.outbox_readiness' => [
            'name' => 'Queue Outbox Readiness',
            'description' => 'Enables the queue outbox pattern for reliable async dispatch once QUEUE-1 ships.',
            'default' => false,
            'env_key' => 'FEATURE_FOUNDATION_QUEUE_OUTBOX_READINESS',
            'owner' => 'platform-foundation',
            'risk_level' => 'high',
            'rollout_status' => 'not_started',
            'review_target' => 'QUEUE-1',
            'dependencies' => ['QUEUE-1'],
            'rollback_action' => 'Set env override to false; no queue jobs dispatched via outbox.',
        ],
        'foundation.queue.idempotency_required' => [
            'name' => 'Queue Idempotency Required',
            'description' => 'Enforces idempotency keys on every queue job before QUEUE-1 goes live.',
            'default' => false,
            'env_key' => 'FEATURE_FOUNDATION_QUEUE_IDEMPOTENCY_REQUIRED',
            'owner' => 'platform-foundation',
            'risk_level' => 'high',
            'rollout_status' => 'not_started',
            'review_target' => 'QUEUE-1',
            'dependencies' => ['QUEUE-1'],
            'rollback_action' => 'Set env override to false; queue foundation stays unimplemented.',
        ],
        'foundation.db.pg_bouncer_readiness' => [
            'name' => 'PgBouncer Readiness',
            'description' => 'Enables PgBouncer connection pooling once DBPERF-2 rollback plan is approved.',
            'default' => false,
            'env_key' => 'FEATURE_FOUNDATION_DB_PG_BOUNCER_READINESS',
            'owner' => 'platform-foundation',
            'risk_level' => 'critical',
            'rollout_status' => 'not_started',
            'review_target' => 'DBPERF-2',
            'dependencies' => ['DBPERF-1', 'DBPERF-2'],
            'rollback_action' => 'Set env override to false; direct DB connections remain in use.',
        ],
        'foundation.reporting.materialized_summary_readiness' => [
            'name' => 'Materialized Summary Reporting Readiness',
            'description' => 'Enables rpt_* materialized-view-backed reporting reads once RPT-1 ships.',
            'default' => false,
            'env_key' => 'FEATURE_FOUNDATION_REPORTING_MATERIALIZED_SUMMARY_READINESS',
            'owner' => 'platform-foundation',
            'risk_level' => 'medium',
            'rollout_status' => 'not_started',
            'review_target' => 'RPT-1',
            'dependencies' => ['RPT-1'],
            'rollback_action' => 'Set env override to false; reports keep reading transactional tables.',
        ],
        'foundation.storage.object_storage_readiness' => [
            'name' => 'Object Storage Readiness',
            'description' => 'Enables S3-compatible object storage for uploaded assets once STORAGE-1 ships.',
            'default' => false,
            'env_key' => 'FEATURE_FOUNDATION_STORAGE_OBJECT_STORAGE_READINESS',
            'owner' => 'platform-foundation',
            'risk_level' => 'high',
            'rollout_status' => 'not_started',
            'review_target' => 'STORAGE-1',
            'dependencies' => ['STORAGE-1'],
            'rollback_action' => 'Set env override to false; assets remain on local disk.',
        ],
        'foundation.stateless_app_readiness' => [
            'name' => 'Stateless App Readiness',
            'description' => 'Marks the app as safe to run stateless (externalized session/cache/storage).',
            'default' => false,
            'env_key' => 'FEATURE_FOUNDATION_STATELESS_APP_READINESS',
            'owner' => 'platform-foundation',
            'risk_level' => 'high',
            'rollout_status' => 'not_started',
            'review_target' => 'STATELESS-1',
            'dependencies' => ['STORAGE-1', 'STATELESS-1'],
            'rollback_action' => 'Set env override to false; keep sticky local-disk/session behavior.',
        ],
        'foundation.load_balancer_pilot' => [
            'name' => 'Load Balancer Pilot',
            'description' => 'Enables the load balancer pilot in front of multiple stateless app instances.',
            'default' => false,
            'env_key' => 'FEATURE_FOUNDATION_LOAD_BALANCER_PILOT',
            'owner' => 'platform-foundation',
            'risk_level' => 'critical',
            'rollout_status' => 'not_started',
            'review_target' => 'LB-1',
            'dependencies' => ['STATELESS-1', 'LB-1'],
            'rollback_action' => 'Set env override to false; route traffic to single instance.',
        ],
        'foundation.read_replica_readiness' => [
            'name' => 'Read Replica Readiness',
            'description' => 'Marks read replica replication/read-write-split design as ready (routing stays off).',
            'default' => false,
            'env_key' => 'FEATURE_FOUNDATION_READ_REPLICA_READINESS',
            'owner' => 'platform-foundation',
            'risk_level' => 'critical',
            'rollout_status' => 'not_started',
            'review_target' => 'REPLICA-1',
            'dependencies' => ['LB-1', 'CACHE-1', 'REPLICA-1'],
            'rollback_action' => 'Set env override to false; all reads stay on primary.',
        ],
        'foundation.partitioning_design_only' => [
            'name' => 'Partitioning Design Only',
            'description' => 'Marks table partitioning strategy as designed; production partitioning stays forbidden.',
            'default' => false,
            'env_key' => 'FEATURE_FOUNDATION_PARTITIONING_DESIGN_ONLY',
            'owner' => 'platform-foundation',
            'risk_level' => 'critical',
            'rollout_status' => 'not_started',
            'review_target' => 'PART-1',
            'dependencies' => ['DBPERF-1', 'RPT-1', 'PART-1'],
            'rollback_action' => 'Set env override to false; no partitioning behavior is ever gated by this flag alone.',
        ],
        'foundation.search_log_explorer_readiness' => [
            'name' => 'Search & Log Explorer Readiness',
            'description' => 'Enables the search engine/log explorer foundation once SEARCH-1 ships with PII redaction.',
            'default' => false,
            'env_key' => 'FEATURE_FOUNDATION_SEARCH_LOG_EXPLORER_READINESS',
            'owner' => 'platform-foundation',
            'risk_level' => 'high',
            'rollout_status' => 'not_started',
            'review_target' => 'SEARCH-1',
            'dependencies' => ['NSF-10', 'QUEUE-1', 'SEARCH-1'],
            'rollback_action' => 'Set env override to false; no search/log explorer surface exposed.',
        ],
        'foundation.national_distributed_architecture_plan' => [
            'name' => 'National Distributed Architecture Plan',
            'description' => 'Marks the national multi-region/multi-branch distributed architecture plan as ready.',
            'default' => false,
            'env_key' => 'FEATURE_FOUNDATION_NATIONAL_DISTRIBUTED_ARCHITECTURE_PLAN',
            'owner' => 'platform-foundation',
            'risk_level' => 'critical',
            'rollout_status' => 'not_started',
            'review_target' => 'NDA-1',
            'dependencies' => ['REPLICA-1', 'LB-1', 'PART-1', 'STORAGE-1', 'SEARCH-1', 'NDA-1'],
            'rollback_action' => 'Set env override to false; plan/design only, no production change to roll back.',
        ],

        // --- Release safety / governance flags (implemented in NSF-9) ---

        'release.automated_smoke_required' => [
            'name' => 'Automated Smoke Required',
            'description' => 'Requires release:automated-smoke to pass (GO) before a release is considered safe to deploy.',
            'default' => true,
            'env_key' => 'FEATURE_RELEASE_AUTOMATED_SMOKE_REQUIRED',
            'owner' => 'release-safety',
            'risk_level' => 'low',
            'rollout_status' => 'implemented',
            'review_target' => 'NSF-10',
            'dependencies' => [],
            'rollback_action' => 'Set env override to false to make smoke advisory only (not recommended).',
        ],
        'release.rollback_checklist_required' => [
            'name' => 'Rollback Checklist Required',
            'description' => 'Requires the release safety rollback checklist to be completed before a GO tag is created.',
            'default' => true,
            'env_key' => 'FEATURE_RELEASE_ROLLBACK_CHECKLIST_REQUIRED',
            'owner' => 'release-safety',
            'risk_level' => 'low',
            'rollout_status' => 'implemented',
            'review_target' => 'NSF-10',
            'dependencies' => [],
            'rollback_action' => 'Set env override to false to skip checklist enforcement (not recommended).',
        ],
        'release.feature_flag_required_for_risky_changes' => [
            'name' => 'Feature Flag Required For Risky Changes',
            'description' => 'Requires any risky future foundation infra change to ship behind a feature flag default-off.',
            'default' => true,
            'env_key' => 'FEATURE_RELEASE_FEATURE_FLAG_REQUIRED_FOR_RISKY_CHANGES',
            'owner' => 'release-safety',
            'risk_level' => 'low',
            'rollout_status' => 'implemented',
            'review_target' => 'RC-1',
            'dependencies' => [],
            'rollback_action' => 'Set env override to false to relax the requirement (not recommended).',
        ],
    ],
];
