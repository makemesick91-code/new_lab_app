<?php

/**
 * CACHE-1 — Cache strategy, Redis readiness & invalidation governance.
 *
 * Read-only source of truth consumed by App\Services\Foundation\CacheGovernanceService
 * via foundation:cache-governance-check. Readiness/governance only — does NOT enable
 * Redis production runtime or cache critical mutable domain data.
 */
return [
    'metadata' => [
        'sprint' => 'CACHE-1',
        'status' => 'implemented',
        'owner' => 'Foundation',
        'decision' => 'readiness_governance_only',
        'production_default_cache_store_policy' => 'file_or_array_default; redis_not_production_default',
    ],

    'global_rules' => [
        'no_pii_in_cache_keys' => true,
        'no_pii_in_cache_values' => true,
        'no_secrets_in_cache' => true,
        'branch_scoped_keys_required' => true,
        'global_key_allowlist_required' => true,
        'invalidation_required_before_runtime_cache' => true,
        'risky_cache_requires_feature_flag' => true,
        'critical_mutable_data_cache_denied_by_default' => true,
    ],

    'key_naming' => [
        'prefix' => 'daengtisiams',
        'environment_segment_required' => true,
        'branch_segment_required_for_branch_scoped_data' => true,
        'module_segment_required' => true,
        'resource_segment_required' => true,
        'version_segment_recommended' => true,
        'forbidden_raw_identifiers' => [
            'patient_name',
            'ktp',
            'nik',
            'phone',
            'address',
            'email',
            'invoice_number',
            'rm_number',
        ],
        'forbidden_raw_identifiers_policy' => 'never_in_key_unless_hashed_and_approved',
        'template' => '{prefix}:{env}:{branch?}:{module}:{resource}:{version?}',
    ],

    'redis_readiness' => [
        'default_status' => 'readiness_only',
        'production_default_enabled' => false,
        'redis_probe_required_before_enable' => true,
        'env_keys_allowed' => [
            'CACHE_STORE',
            'REDIS_CLIENT',
            'REDIS_HOST',
            'REDIS_PORT',
            'REDIS_DB',
            'REDIS_CACHE_DB',
        ],
        'env_keys_never_print' => [
            'REDIS_PASSWORD',
            'REDIS_USERNAME',
            'APP_KEY',
            'DB_PASSWORD',
        ],
        'probe_key_template' => 'daengtisiams:{env}:foundation:cache_governance:probe',
        'probe_ttl_seconds' => 30,
    ],

    'global_key_allowlist' => [
        'foundation.static_config',
        'foundation.feature_flags',
        'foundation.roadmap_summary',
        'foundation.release_evidence_summary',
        'master_data.global_reference',
    ],

    'allowed_cache_categories' => [
        'foundation.static_config' => [
            'scope' => 'global',
            'ttl_seconds' => 3600,
            'allowed_store' => 'file',
            'requires_invalidation' => true,
            'invalidation_events' => ['config:cache', 'deploy'],
            'feature_flag' => null,
            'pii_allowed' => false,
            'branch_scope_required' => false,
            'invalidation' => [
                'trigger' => 'config:cache or deploy',
                'scope' => 'global',
                'affected_key_pattern' => 'daengtisiams:{env}:foundation:static_config:*',
                'fallback' => 'php artisan optimize:clear',
                'owner' => 'platform-foundation',
                'tests_required' => true,
            ],
        ],
        'foundation.feature_flags' => [
            'scope' => 'global',
            'ttl_seconds' => 300,
            'allowed_store' => 'file',
            'requires_invalidation' => true,
            'invalidation_events' => ['feature_flags:changed', 'config:cache'],
            'feature_flag' => null,
            'pii_allowed' => false,
            'branch_scope_required' => false,
            'invalidation' => [
                'trigger' => 'feature flag registry change',
                'scope' => 'global',
                'affected_key_pattern' => 'daengtisiams:{env}:foundation:feature_flags:*',
                'fallback' => 'php artisan config:clear',
                'owner' => 'platform-foundation',
                'tests_required' => true,
            ],
        ],
        'foundation.roadmap_summary' => [
            'scope' => 'global',
            'ttl_seconds' => 600,
            'allowed_store' => 'file',
            'requires_invalidation' => true,
            'invalidation_events' => ['roadmap:updated', 'deploy'],
            'feature_flag' => null,
            'pii_allowed' => false,
            'branch_scope_required' => false,
            'invalidation' => [
                'trigger' => 'foundation_roadmap config change',
                'scope' => 'global',
                'affected_key_pattern' => 'daengtisiams:{env}:foundation:roadmap_summary:*',
                'fallback' => 'php artisan config:clear',
                'owner' => 'platform-foundation',
                'tests_required' => true,
            ],
        ],
        'foundation.release_evidence_summary' => [
            'scope' => 'global',
            'ttl_seconds' => 300,
            'allowed_store' => 'file',
            'requires_invalidation' => true,
            'invalidation_events' => ['release:evidence-capture', 'deploy'],
            'feature_flag' => null,
            'pii_allowed' => false,
            'branch_scope_required' => false,
            'invalidation' => [
                'trigger' => 'release evidence capture/check',
                'scope' => 'global',
                'affected_key_pattern' => 'daengtisiams:{env}:foundation:release_evidence_summary:*',
                'fallback' => 're-run release:evidence-capture',
                'owner' => 'release-safety',
                'tests_required' => true,
            ],
        ],
        'master_data.global_reference' => [
            'scope' => 'global',
            'ttl_seconds' => 1800,
            'allowed_store' => 'file',
            'requires_invalidation' => true,
            'invalidation_events' => ['master_data:updated'],
            'feature_flag' => null,
            'pii_allowed' => false,
            'branch_scope_required' => false,
            'invalidation' => [
                'trigger' => 'global master data mutation',
                'scope' => 'global',
                'affected_key_pattern' => 'daengtisiams:{env}:master_data:global_reference:*',
                'fallback' => 'module-scoped cache clear',
                'owner' => 'master-data',
                'tests_required' => true,
            ],
        ],
        'master_data.branch_reference' => [
            'scope' => 'branch',
            'ttl_seconds' => 900,
            'allowed_store' => 'file',
            'requires_invalidation' => true,
            'invalidation_events' => ['master_data:updated', 'branch:switched'],
            'feature_flag' => 'foundation.cache.invalidation_governance',
            'pii_allowed' => false,
            'branch_scope_required' => true,
            'invalidation' => [
                'trigger' => 'branch master data mutation',
                'scope' => 'branch',
                'affected_key_pattern' => 'daengtisiams:{env}:{branch_id}:master_data:branch_reference:*',
                'fallback' => 'branch-scoped cache clear',
                'owner' => 'master-data',
                'tests_required' => true,
            ],
        ],
        'reporting.precomputed_summary_readiness' => [
            'scope' => 'branch',
            'ttl_seconds' => 600,
            'allowed_store' => 'file',
            'requires_invalidation' => true,
            'invalidation_events' => ['reporting:summary_refresh', 'deploy'],
            'feature_flag' => 'foundation.reporting.materialized_summary_readiness',
            'pii_allowed' => false,
            'branch_scope_required' => true,
            'invalidation' => [
                'trigger' => 'reporting summary refresh or source data change',
                'scope' => 'branch',
                'affected_key_pattern' => 'daengtisiams:{env}:{branch_id}:reporting:precomputed_summary:*',
                'fallback' => 'invalidate branch reporting keys',
                'owner' => 'reporting',
                'tests_required' => true,
            ],
        ],
    ],

    'denied_cache_categories' => [
        'inventory.current_stock_mutable' => [
            'reason' => 'Stock is ledger-derived; mutable stock cache violates Sprint 12 inventory rules.',
            'owner' => 'inventory',
        ],
        'inventory.ledger_movements_raw' => [
            'reason' => 'Raw ledger movements must remain authoritative in PostgreSQL.',
            'owner' => 'inventory',
        ],
        'cashier.payment_state' => [
            'reason' => 'Payment/invoice state is critical mutable financial data.',
            'owner' => 'rme-invoice',
        ],
        'cashier.receivable_remaining' => [
            'reason' => 'Receivable remaining must be computed from invoice ledger, not cached.',
            'owner' => 'rme-invoice',
        ],
        'rme.medical_record_draft' => [
            'reason' => 'Medical record draft/finalization state must not be cached without explicit governance.',
            'owner' => 'medical-record',
        ],
        'rme.finalization_state' => [
            'reason' => 'RME finalization gates must remain authoritative in DB.',
            'owner' => 'medical-record',
        ],
        'rme.consent_state' => [
            'reason' => 'Consent state gates billing and must not be cached.',
            'owner' => 'clinic-visit',
        ],
        'auth.permission_decision_runtime' => [
            'reason' => 'Authorization decisions must not be cached without invalidation tests.',
            'owner' => 'access-control',
        ],
        'branch_context.current_branch' => [
            'reason' => 'Active branch context must come from BranchContext, not cache.',
            'owner' => 'branch',
        ],
        'patient.identity_pii' => [
            'reason' => 'Patient identity/PII must never be cached.',
            'owner' => 'patient',
        ],
        'document.scan_private_file' => [
            'reason' => 'Private scanned documents must never be cached.',
            'owner' => 'patient',
        ],
    ],

    'invalidation_policy' => [
        'event_based_preferred' => true,
        'manual_clear_fallback' => true,
        'deploy_cache_clear' => true,
        'config_cache_clear' => true,
        'branch_scoped_invalidation' => true,
        'module_scoped_invalidation' => true,
        'emergency_full_cache_clear' => [
            'command' => 'php artisan cache:clear',
            'owner' => 'platform-foundation',
            'requires_incident_ticket' => true,
            'rollback_plan_required' => true,
        ],
    ],

    'feature_flags' => [
        'foundation.cache.redis_readiness',
        'foundation.cache.invalidation_governance',
    ],

    'go_criteria' => [
        'cache_governance config complete',
        'denied critical mutable categories documented',
        'allowed categories have TTL, scope, store, invalidation',
        'branch-scoped categories require branch key segment',
        'global categories explicitly allowlisted',
        'redis readiness-only by default',
        'foundation GO preserved',
    ],

    'watch_criteria' => [
        'redis probe requested but redis unavailable while runtime disabled',
        'non-critical cache candidates deferred to future sprint',
    ],

    'no_go_criteria' => [
        'critical mutable category allowed without invalidation',
        'pii or secrets permitted in cache keys/values',
        'redis runtime enabled but probe fails',
        'foundation GO regressed',
    ],
];
