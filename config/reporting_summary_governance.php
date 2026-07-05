<?php

/**
 * RPT-1 — Reporting summary + materialized view readiness governance.
 *
 * Source of truth for future rpt_* summary tables and materialized views.
 * RPT-1 is governance/readiness only: runtime reads stay on transactional
 * source queries, auto refresh stays disabled, and physical summary additions
 * require separate evidence, reconciliation tests, and feature flags.
 */
return [
    'metadata' => [
        'sprint' => 'RPT-1',
        'status' => 'implemented',
        'owner' => 'Foundation',
        'decision' => 'summary_foundation_and_materialized_view_readiness',
        'production_auto_refresh_enabled' => false,
        'physical_summary_created_in_rpt_1' => false,
    ],

    'global_rules' => [
        'no_summary_without_source_of_truth' => true,
        'no_summary_without_refresh_policy' => true,
        'no_summary_without_staleness_policy' => true,
        'no_summary_without_branch_scope_policy' => true,
        'no_summary_without_pii_policy' => true,
        'no_summary_without_reconciliation_check' => true,
        'no_materialized_view_concurrent_refresh_without_unique_index' => true,
        'no_heavy_refresh_during_clinic_hours' => true,
        'no_auto_schedule_in_rpt_1' => true,
        'no_source_switch_without_feature_flag' => true,
        'no_financial_semantic_change' => true,
        'no_inventory_stock_mutable_summary_as_source_of_truth' => true,
        'no_rme_medical_record_content_summary_with_pii' => true,
        'summary_artifacts_must_be_safe' => true,
        'refresh_command_must_support_dry_run' => true,
    ],

    'summary_runtime_policy' => [
        'runtime_reads_from_summary_enabled' => false,
        'auto_refresh_enabled' => false,
        'manual_refresh_allowed' => 'dry_run_only_by_default',
        'scheduled_refresh_allowed' => false,
        'queue_refresh_allowed' => false,
        'materialized_view_refresh_allowed' => 'manual_only',
        'concurrent_refresh_requires_unique_index' => true,
        'stale_data_label_required' => true,
    ],

    'materialized_view_readiness' => [
        'enabled_foundation' => true,
        'create_materialized_views_allowed' => 'evidence_based_only',
        'refresh_concurrently_default' => false,
        'unique_index_required_for_concurrent' => true,
        'no_sensitive_payload' => true,
        'rollback_required' => true,
        'refresh_timeout_policy_required' => true,
    ],

    'allowed_summary_categories' => [
        'reporting.owner_daily_payment_trend' => [
            'source_tables' => ['trx_payments', 'trx_invoices', 'mst_branches'],
            'branch_scope' => 'branch_id_required',
            'date_grain' => 'day',
            'refresh_strategy' => 'manual_dry_run_default_future_table_or_mv',
            'freshness_sla' => 'next_refresh_window_after_payment_close',
            'pii_allowed' => false,
            'financial_semantics' => 'payments_total_by_branch_and_payment_date_only',
            'feature_flag' => 'foundation.reporting.summary_runtime_reads_enabled',
            'reconciliation_required' => true,
            'runtime_source_switch_allowed' => false,
        ],
        'reporting.owner_receivable_aging_snapshot' => [
            'source_tables' => ['trx_invoices', 'trx_payments', 'mst_branches'],
            'branch_scope' => 'branch_id_required',
            'date_grain' => 'snapshot_day',
            'refresh_strategy' => 'manual_dry_run_default_future_snapshot',
            'freshness_sla' => 'daily_after_clinic_close',
            'pii_allowed' => false,
            'financial_semantics' => 'invoice_remaining_aging_reconciles_to_invoice_outstanding',
            'feature_flag' => 'foundation.reporting.summary_runtime_reads_enabled',
            'reconciliation_required' => true,
            'runtime_source_switch_allowed' => false,
        ],
        'reporting.dmo_metric_snapshot' => [
            'source_tables' => ['config/foundation_governance.php', 'architecture governance services'],
            'branch_scope' => 'global_governance_no_patient_rows',
            'date_grain' => 'snapshot_day',
            'refresh_strategy' => 'command_capture_read_only',
            'freshness_sla' => 'per_release_evidence_capture',
            'pii_allowed' => false,
            'financial_semantics' => 'not_financial',
            'feature_flag' => 'foundation.reporting.rpt_summary_governance',
            'reconciliation_required' => true,
            'runtime_source_switch_allowed' => false,
        ],
        'inventory.batch_expiry_summary' => [
            'source_tables' => ['inv_inventory_batches', 'trx_inventory_movements', 'inv_products'],
            'branch_scope' => 'branch_id_required',
            'date_grain' => 'snapshot_day',
            'refresh_strategy' => 'manual_dry_run_default_future_summary',
            'freshness_sla' => 'daily_after_inventory_close',
            'pii_allowed' => false,
            'financial_semantics' => 'inventory_only_ledger_derived',
            'feature_flag' => 'foundation.reporting.summary_runtime_reads_enabled',
            'reconciliation_required' => true,
            'runtime_source_switch_allowed' => false,
        ],
        'inventory.low_stock_summary' => [
            'source_tables' => ['trx_inventory_movements', 'inv_products', 'inv_inventory_locations'],
            'branch_scope' => 'branch_id_required',
            'date_grain' => 'snapshot_day',
            'refresh_strategy' => 'manual_dry_run_default_future_summary',
            'freshness_sla' => 'daily_after_inventory_close',
            'pii_allowed' => false,
            'financial_semantics' => 'inventory_only_ledger_sum_minus_out',
            'feature_flag' => 'foundation.reporting.summary_runtime_reads_enabled',
            'reconciliation_required' => true,
            'runtime_source_switch_allowed' => false,
        ],
        'foundation.governance_summary' => [
            'source_tables' => ['config/foundation_roadmap.php', 'config/reporting_summary_governance.php', 'governance commands'],
            'branch_scope' => 'global_governance_no_patient_rows',
            'date_grain' => 'snapshot_day',
            'refresh_strategy' => 'release_evidence_capture_read_only',
            'freshness_sla' => 'per_ci_or_vps_release_evidence_capture',
            'pii_allowed' => false,
            'financial_semantics' => 'not_financial',
            'feature_flag' => 'foundation.reporting.rpt_summary_governance',
            'reconciliation_required' => true,
            'runtime_source_switch_allowed' => false,
        ],
    ],

    'denied_summary_categories' => [
        'patient.identity_pii',
        'rme.medical_record_content',
        'rme.draft_content',
        'cashier.raw_payment_sensitive_payload',
        'inventory.raw_ledger_payload_as_mutable_stock',
        'auth.permission_decision',
        'branch_context.runtime_context',
        'document.private_scan_payload',
    ],

    'refresh_policy' => [
        'dry_run_required_by_default' => true,
        'manual_execute_requires_confirm_flag' => true,
        'max_runtime_seconds' => 30,
        'refresh_locks_policy' => 'short_transaction_or_nonblocking_metadata_only_in_rpt_1',
        'refresh_audit_policy' => 'record_command_summary_without_row_payload_or_pii',
        'stale_data_behavior' => 'label_stale_and_fall_back_to_transactional_source',
        'emergency_disable_policy' => 'disable summary flags and keep transactional reports',
        'rollback_policy' => 'drop only additive rpt object in rollback migration after source switch disabled',
    ],

    'reconciliation_policy' => [
        'source_count_check' => true,
        'aggregate_total_check' => true,
        'branch_scope_check' => true,
        'date_range_check' => true,
        'zero_or_null_handling' => 'coalesce_to_zero_and_preserve_empty_branch_dates',
        'mismatch_status' => ['GO', 'WATCH', 'FAIL'],
    ],

    'feature_flags' => [
        'foundation.reporting.materialized_summary_readiness',
        'foundation.reporting.rpt_summary_governance',
        'foundation.reporting.summary_runtime_reads_enabled',
        'foundation.reporting.summary_auto_refresh_enabled',
    ],

    'existing_rpt_inventory' => [
        'tables' => [
            'rpt_inventory_daily_summaries',
            'rpt_inventory_branch_summaries',
            'rpt_inventory_product_summaries',
            'rpt_procurement_daily_summaries',
        ],
        'materialized_views' => [],
        'source_migration' => '2026_06_10_100000_create_rpt_inventory_analytics_summary_tables.php',
        'source_of_truth' => 'Inventory ledger remains trx_inventory_movements; summaries are derived read models only.',
    ],

    'deferred_candidates' => [
        [
            'key' => 'reporting.owner_daily_payment_trend',
            'source' => 'DBPERF-1 deferred candidate',
            'reason' => 'Observed roughly 118ms at 500K-payment scale; needs dedicated RPT-2 design and reconciliation before physical object or read-path switch.',
        ],
        [
            'key' => 'reporting.owner_receivable_aging_snapshot',
            'source' => 'owner dashboard branch receivable summary',
            'reason' => 'Financial semantics and aging windows require fixture-backed reconciliation.',
        ],
        [
            'key' => 'inventory.low_stock_summary',
            'source' => 'existing inventory analytics summaries',
            'reason' => 'Already has rpt_* inventory read models; future work may standardize under RPT governance without replacing ledger truth.',
        ],
    ],

    'go_criteria' => [
        'governance_config_complete',
        'feature_flags_registered_and_risky_runtime_flags_default_false',
        'check_command_go',
        'release_evidence_captures_reporting_summary_check',
        'foundation_summary_includes_reporting_summary',
        'no_runtime_read_switch',
        'no_auto_refresh',
        'no_pii_payload',
    ],

    'watch_criteria' => [
        'optional_materialized_views_absent_readiness_only',
        'db_inventory_unavailable_in_non_pgsql_or_test_environment',
        'physical_summary_deferred_with_reason',
    ],

    'no_go_criteria' => [
        'runtime_summary_reads_enabled_by_default',
        'auto_refresh_enabled_by_default',
        'pii_category_allowed',
        'concurrent_materialized_refresh_without_unique_index',
        'summary_without_source_refresh_staleness_branch_or_reconciliation_policy',
    ],
];
