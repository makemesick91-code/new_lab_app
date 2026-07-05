<?php

/**
 * DBPERF-1 — PostgreSQL index optimization & query plan audit governance.
 *
 * Read-only registry of PostgreSQL performance/index governance rules, the
 * query-family inventory this sprint audited, the index candidate decisions
 * reached, and the GO/WATCH/NO-GO criteria for foundation:db-performance-check.
 *
 * This config never runs anything itself — it only declares policy so
 * App\Services\Foundation\DbPerformanceGovernanceService and
 * foundation:db-performance-check can validate against it, and so the
 * deploy script/CI workflow have a single source of truth to check.
 *
 * DBPERF-1 is audit-first and safe-index-first: this sprint found that prior
 * sprints (NSF-2 safe index pack, and the pre-existing "Sprint 68.x" stress
 * test / read-path index migrations) already cover almost every target query
 * family with additive, evidence-backed indexes. Only one genuinely
 * uncovered, evidence-backed gap was found (sys_idempotency_keys expiry
 * sweep) and is added here as the single DBPERF-1 migration.
 */
return [
    'metadata' => [
        'sprint' => 'DBPERF-1',
        'status' => 'implemented',
        'owner' => 'Foundation',
        'decision' => 'query_plan_audit_and_safe_index_governance',
    ],

    'global_rules' => [
        'query_plan_evidence_required' => true,
        'no_destructive_index_changes' => true,
        'no_drop_index_in_dbperf_1' => true,
        'additive_index_only' => true,
        'concurrent_index_preferred_for_large_tables' => true,
        'no_heavy_production_explain_analyze_without_guard' => true,
        'no_pii_in_query_plan_artifacts' => true,
        'no_raw_sql_with_untrusted_request_input' => true,
        'branch_filter_required_for_branch_scoped_queries' => true,
        'inventory_ledger_queries_must_preserve_sum_movements_rule' => true,
        'index_reason_required' => true,
        'rollback_note_required' => true,
    ],

    'allowed_actions' => [
        'query_inventory',
        'explain_without_analyze',
        'safe_local_explain_analyze',
        'additive_btree_index',
        'additive_partial_index_if_predicate_stable',
        'additive_composite_index_if_query_pattern_evidence_exists',
        'pg_stat_user_indexes_readonly',
        'pg_stat_statements_readonly_if_available',
    ],

    'denied_actions' => [
        'drop_index',
        'reindex_production',
        'vacuum_full_production',
        'partition_production_tables',
        'enable_pgbouncer',
        'alter_postgresql_runtime_settings',
        'redirect_reads_to_replica',
        'cache_query_results_without_cache_governance',
    ],

    // Known DaengtisiaMS query groups this sprint audited against existing indexes.
    'target_query_families' => [
        'rme.patient_lookup',
        'rme.visit_queue',
        'rme.medical_record_list',
        'cashier.receivable_list',
        'cashier.payment_report',
        'inventory.current_stock_ledger_sum',
        'inventory.stock_card',
        'inventory.batch_expiry',
        'inventory.source_document_batch_governance',
        'procurement.pr_po_gr_lists',
        'stock_transfer.workflow_lists',
        'reporting.owner_dashboard',
        'reporting.dmo_metrics',
        'foundation.audit_governance_commands',
    ],

    // Every candidate considered during DBPERF-1 discovery, with an explicit
    // decision. `add_index_now` entries have a corresponding migration;
    // everything else is audit-only / deferred by design (not silently
    // skipped) so future sprints know these were reviewed.
    'index_candidate_policy' => [
        [
            'table' => 'sys_idempotency_keys',
            'columns' => ['status', 'expires_at'],
            'index_type' => 'btree_composite',
            'predicate' => null,
            'query_family' => 'foundation.audit_governance_commands',
            'reason' => 'IdempotencyService::expireOld() sweeps WHERE status = reserved AND expires_at < now() to expire stale reservations; status and expires_at previously only had separate single-column indexes.',
            'expected_benefit' => 'Composite index scan instead of single-column index scan + filter for the periodic expiry sweep, relevant as sys_idempotency_keys grows with QUEUE-1 usage.',
            'risk' => 'low',
            'migration_name' => '2026_07_05_200001_add_dbperf1_idempotency_status_expires_at_index',
            'rollback_note' => 'DROP INDEX CONCURRENTLY IF EXISTS idx_dbperf1_idempotency_status_expires_at; safe at any time, table is governance-only (no domain FK depends on this index).',
            'evidence_required' => true,
            'decision' => 'add_index_now',
        ],
        [
            'table' => 'mst_patients',
            'columns' => ['branch_id', 'is_active'],
            'index_type' => 'btree_composite',
            'predicate' => null,
            'query_family' => 'rme.patient_lookup',
            'reason' => 'Already added by NSF-2 (idx_nsf2_patients_branch_is_active) for PatientRepository::forAudit() / CrossBranchPatientLookupService branch+active filters.',
            'expected_benefit' => 'n/a — already realized in NSF-2.',
            'risk' => 'none',
            'migration_name' => '2026_07_03_200001_add_nsf2_safe_performance_indexes',
            'rollback_note' => 'Owned by NSF-2 migration; out of scope to touch in DBPERF-1.',
            'evidence_required' => true,
            'decision' => 'no_action',
        ],
        [
            'table' => 'trx_inventory_movements',
            'columns' => ['branch_id', 'movement_date'],
            'index_type' => 'btree_composite',
            'predicate' => null,
            'query_family' => 'inventory.stock_card',
            'reason' => 'Already covered pre-NSF-2 by trx_inventory_movements_branch_location_product_index and confirmed aliased by NSF-2 audit; movements also carry single-column branch_id/movement_date/product_id/reference indexes.',
            'expected_benefit' => 'n/a — already realized.',
            'risk' => 'none',
            'migration_name' => '2026_06_04_120000_create_inventory_core_tables',
            'rollback_note' => 'Out of scope; pre-existing.',
            'evidence_required' => true,
            'decision' => 'no_action',
        ],
        [
            'table' => 'trx_clinic_visits',
            'columns' => ['branch_id', 'visit_date', 'status'],
            'index_type' => 'btree_composite',
            'predicate' => null,
            'query_family' => 'rme.visit_queue',
            'reason' => 'trx_clinic_visits_branch_date_status_index already exists at table creation and is confirmed heavily used (stage8 pg_stat_user_indexes baseline: ~240K index scans).',
            'expected_benefit' => 'n/a — already realized.',
            'risk' => 'none',
            'migration_name' => '2026_06_09_000001_create_trx_clinic_visits_table',
            'rollback_note' => 'Out of scope; pre-existing.',
            'evidence_required' => true,
            'decision' => 'no_action',
        ],
        [
            'table' => 'trx_medical_records',
            'columns' => ['branch_id', 'status'],
            'index_type' => 'btree_composite',
            'predicate' => null,
            'query_family' => 'rme.medical_record_list',
            'reason' => 'trx_medical_records_branch_status_index already exists at table creation; clinic_visit_id already unique (1:1), patient_id already indexed.',
            'expected_benefit' => 'n/a — already realized.',
            'risk' => 'none',
            'migration_name' => '2026_06_10_100001_create_trx_medical_records_table',
            'rollback_note' => 'Out of scope; pre-existing.',
            'evidence_required' => true,
            'decision' => 'no_action',
        ],
        [
            'table' => 'trx_rme_invoices',
            'columns' => ['branch_id', 'status'],
            'index_type' => 'btree_composite_and_partial',
            'predicate' => "deleted_at IS NULL AND status IN ('UNPAID','PARTIAL') AND grand_total > 0",
            'query_family' => 'cashier.receivable_list',
            'reason' => 'trx_rme_invoices_branch_status_index (composite) plus the Sprint 68.x partial index trx_rme_invoices_active_receivable_order_idx already target the exact receivable-list predicate.',
            'expected_benefit' => 'n/a — already realized.',
            'risk' => 'none',
            'migration_name' => '2026_06_30_153729_add_rme_receivables_performance_indexes',
            'rollback_note' => 'Out of scope; pre-existing.',
            'evidence_required' => true,
            'decision' => 'no_action',
        ],
        [
            'table' => 'trx_rme_payments',
            'columns' => ['branch_id', 'paid_at'],
            'index_type' => 'btree_composite_covering',
            'predicate' => null,
            'query_family' => 'cashier.payment_report',
            'reason' => 'trx_rme_payments_branch_paid_at_idx (branch_id, paid_at DESC) INCLUDE (amount) already exists (Sprint 68.2) for OwnerDashboardKpiService per-branch revenue and RmeReportController payment report listing.',
            'expected_benefit' => 'n/a — already realized.',
            'risk' => 'none',
            'migration_name' => '2026_07_01_680200_add_read_path_indexes_for_rme_payments',
            'rollback_note' => 'Out of scope; pre-existing.',
            'evidence_required' => true,
            'decision' => 'no_action',
        ],
        [
            'table' => 'sys_outbox_events',
            'columns' => ['status', 'available_at'],
            'index_type' => 'btree_composite',
            'predicate' => null,
            'query_family' => 'foundation.audit_governance_commands',
            'reason' => 'QUEUE-1 already created this composite index for the dispatch-polling pattern.',
            'expected_benefit' => 'n/a — already realized.',
            'risk' => 'none',
            'migration_name' => '2026_07_05_100002_create_sys_outbox_events_table',
            'rollback_note' => 'Out of scope; pre-existing.',
            'evidence_required' => true,
            'decision' => 'no_action',
        ],
        [
            'table' => 'reporting.owner_dashboard_daily_payment_trend',
            'columns' => [],
            'index_type' => 'n/a',
            'predicate' => null,
            'query_family' => 'reporting.owner_dashboard',
            'reason' => 'Sprint 68.13 closure documented one remaining WATCH item: the daily payment trend aggregate runs ~118ms at 500K-payment scale. Not a correctness or index gap — a candidate for RPT-1 materialized/summary rollup, not a DBPERF-1 index.',
            'expected_benefit' => 'Deferred — belongs to RPT-1 (materialized view / rpt_* summary foundation), not additive indexing.',
            'risk' => 'low',
            'migration_name' => null,
            'rollback_note' => 'n/a — no migration.',
            'evidence_required' => true,
            'decision' => 'defer_to_rpt_1',
        ],
        [
            'table' => 'trx_purchase_requests|trx_purchase_orders|trx_goods_receipts',
            'columns' => ['branch_id', 'status', 'date'],
            'index_type' => 'btree_composite',
            'predicate' => null,
            'query_family' => 'procurement.pr_po_gr_lists',
            'reason' => 'Already fully covered: each table has its own (branch_id, status) and (branch_id, date) composite indexes plus supplier/PR/PO linkage indexes.',
            'expected_benefit' => 'n/a — already realized.',
            'risk' => 'none',
            'migration_name' => '2026_06_07_100000_create_trx_purchase_requests_table + siblings',
            'rollback_note' => 'Out of scope; pre-existing.',
            'evidence_required' => true,
            'decision' => 'no_action',
        ],
        [
            'table' => 'trx_stock_transfers',
            'columns' => ['branch_id', 'status', 'transfer_date'],
            'index_type' => 'btree_composite',
            'predicate' => null,
            'query_family' => 'stock_transfer.workflow_lists',
            'reason' => 'Already covered by trx_stock_transfers_branch_status_index and trx_stock_transfers_branch_date_index plus source/destination location indexes.',
            'expected_benefit' => 'n/a — already realized.',
            'risk' => 'none',
            'migration_name' => '2026_06_06_140200_create_trx_stock_transfers_table',
            'rollback_note' => 'Out of scope; pre-existing.',
            'evidence_required' => true,
            'decision' => 'no_action',
        ],
    ],

    // Where governance/evidence artifacts must be written and what they may
    // never contain. Consumed by App\Services\Foundation\DbPerformanceGovernanceService.
    'artifact_policy' => [
        'artifacts' => [
            'storage/ci-evidence/db-performance-check.json',
            'storage/release-evidence/latest/db-performance-check.json',
            'docs/sprints/dbperf-1-postgresql-index-optimization-query-plan-audit-evidence.md',
        ],
        'forbidden' => [
            'no_raw_pii',
            'no_db_credentials',
            'no_full_result_sets',
        ],
    ],

    'go_criteria' => [
        'config/db_performance_governance.php is present and complete',
        'no denied_actions detected in migrations/docs',
        'every index_candidate_policy entry with decision=add_index_now has a matching migration and rollback_note',
        'pgsql index/table audit reads succeed (or command reports not_applicable on non-pgsql connections)',
    ],

    'watch_criteria' => [
        'pg_stat_user_indexes or pg_stat_statements not available in this environment',
        'running on a non-pgsql connection (local/CI sqlite) — config-only checks apply',
    ],

    'no_go_criteria' => [
        'a denied_action (drop index / partition / pgbouncer / runtime tuning) is detected',
        'an index_candidate_policy entry with decision=add_index_now has no migration or no rollback_note',
        'a duplicate/unsafe index name collides with an existing index',
    ],
];
