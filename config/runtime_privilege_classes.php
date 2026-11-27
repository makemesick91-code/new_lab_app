<?php

/*
| Runtime privilege class of every table (rule 245 #6; owner decisions D-Q3-a and
| D-Q3-b; DMS-SPN4-READY-PILOT-1 R6-B2).
|
| - `historical_through`: the last migration whose tables are classified here.
|   Those migrations are never edited (CG-09). Every later migration that creates
|   a table declares its class with #[RuntimePrivilegeClass] (App\Support\Database).
| - `tables`: one entry per historical table, explicitly. FULL_DML is SELECT,
|   INSERT, UPDATE, DELETE; APPEND_ONLY is SELECT, INSERT; APPEND_ONLY_WITH_COLUMNS
|   adds UPDATE of the named columns only; READ_ONLY_RUNTIME is SELECT.
|
| A class is chosen from evidence, never by default:
| - a table is APPEND_ONLY only when the database already refuses its UPDATE and
|   DELETE (a guard trigger), or when no application path updates or deletes it
|   and its model refuses both;
| - a table the runtime row-locks keeps UPDATE on at least one column (rule 245 #6);
| - every other table keeps the full DML it has today.
|
| Recording a class grants nothing. Grants are applied only by the authorised
| R6-B3 provisioning, with a dry run, verification and rollback; never by a
| migration. FoundationGovernanceRuntimePrivilegeRegistryTest compares this file
| with the PostgreSQL 16 schema and fails on an unclassified table, a stale
| entry, an unknown class or a missing column.
*/
return [
    /*
    | R6-B3: the roles the grants (foundation:db-privilege-apply) and the check
    | (foundation:db-privilege-check) refer to. The names come from the
    | environment only, never from the repository (OD-R6-6), and each is
    | validated as a plain role name before it reaches any SQL.
    | - runtime: the login that receives the class grants; by default the
    |   application's own database login.
    | - owner: the NOLOGIN owner role; the same value as the startup role of
    |   the `pgsql_migrate` connection.
    */
    'roles' => [
        'runtime' => env('DB_PRIVILEGE_RUNTIME_ROLE', env('DB_USERNAME')),
        'owner' => env('DB_MIGRATE_OWNER_ROLE'),
    ],

    /*
    | R6-B3 review round 1, finding 6: every guard trigger the migrated schema
    | must carry, as pg_get_triggerdef() prints it (rule 240, rule 244, rule 248
    | layer 1). foundation:db-privilege-check fails when one is missing or
    | changed, or when any other trigger exists on a `public` table; a migration
    | that adds or changes a guard trigger updates this list in the same PR.
    | FoundationGovernanceDbPrivilegeTest compares it with the PostgreSQL 16
    | schema.
    */
    'guard_triggers' => [
        'CREATE TRIGGER sys_audit_logs_append_only BEFORE DELETE OR UPDATE ON public.sys_audit_logs FOR EACH ROW EXECUTE FUNCTION dms_append_only_guard()',
        'CREATE TRIGGER sys_audit_logs_append_only_trunc BEFORE TRUNCATE ON public.sys_audit_logs FOR EACH STATEMENT EXECUTE FUNCTION dms_append_only_guard()',
        'CREATE TRIGGER trx_inventory_movements_ledger_guard BEFORE DELETE OR UPDATE ON public.trx_inventory_movements FOR EACH ROW EXECUTE FUNCTION dms_inventory_ledger_guard()',
        'CREATE TRIGGER trx_inventory_movements_ledger_guard_trunc BEFORE TRUNCATE ON public.trx_inventory_movements FOR EACH STATEMENT EXECUTE FUNCTION dms_inventory_ledger_guard()',
        'CREATE TRIGGER trx_lab_order_status_logs_append_only BEFORE DELETE OR UPDATE ON public.trx_lab_order_status_logs FOR EACH ROW EXECUTE FUNCTION dms_append_only_guard()',
        'CREATE TRIGGER trx_lab_order_status_logs_append_only_trunc BEFORE TRUNCATE ON public.trx_lab_order_status_logs FOR EACH STATEMENT EXECUTE FUNCTION dms_append_only_guard()',
        'CREATE TRIGGER trx_prpcr_no_truncate BEFORE TRUNCATE ON public.trx_patient_rm_paper_card_registrations FOR EACH STATEMENT EXECUTE FUNCTION trx_prpcr_refuse_change()',
        'CREATE TRIGGER trx_prpcr_no_update_delete BEFORE DELETE OR UPDATE ON public.trx_patient_rm_paper_card_registrations FOR EACH ROW EXECUTE FUNCTION trx_prpcr_refuse_change()',
        'CREATE TRIGGER trx_rme_amendments_guard BEFORE DELETE OR UPDATE ON public.trx_rme_amendments FOR EACH ROW EXECUTE FUNCTION trx_rme_amendments_guard()',
        'CREATE TRIGGER trx_rme_amendments_guard_truncate BEFORE TRUNCATE ON public.trx_rme_amendments FOR EACH STATEMENT EXECUTE FUNCTION trx_rme_amendments_guard()',
        'CREATE TRIGGER trx_rme_billing_dispute_events_append_only BEFORE DELETE OR UPDATE ON public.trx_rme_billing_dispute_events FOR EACH ROW EXECUTE FUNCTION dms_append_only_guard()',
        'CREATE TRIGGER trx_rme_billing_dispute_events_append_only_trunc BEFORE TRUNCATE ON public.trx_rme_billing_dispute_events FOR EACH STATEMENT EXECUTE FUNCTION dms_append_only_guard()',
        'CREATE TRIGGER trx_rme_invoice_correction_events_append_only BEFORE DELETE OR UPDATE ON public.trx_rme_invoice_correction_events FOR EACH ROW EXECUTE FUNCTION dms_append_only_guard()',
        'CREATE TRIGGER trx_rme_invoice_correction_events_append_only_trunc BEFORE TRUNCATE ON public.trx_rme_invoice_correction_events FOR EACH STATEMENT EXECUTE FUNCTION dms_append_only_guard()',
        'CREATE TRIGGER trx_rme_overpayment_obligation_events_append_only BEFORE DELETE OR UPDATE ON public.trx_rme_overpayment_obligation_events FOR EACH ROW EXECUTE FUNCTION dms_append_only_guard()',
        'CREATE TRIGGER trx_rme_overpayment_obligation_events_append_only_trunc BEFORE TRUNCATE ON public.trx_rme_overpayment_obligation_events FOR EACH STATEMENT EXECUTE FUNCTION dms_append_only_guard()',
        'CREATE TRIGGER trx_rme_record_versions_immutable BEFORE DELETE OR UPDATE ON public.trx_rme_record_versions FOR EACH ROW EXECUTE FUNCTION trx_rme_record_versions_immutable()',
        'CREATE TRIGGER trx_rme_record_versions_no_truncate BEFORE TRUNCATE ON public.trx_rme_record_versions FOR EACH STATEMENT EXECUTE FUNCTION trx_rme_record_versions_immutable()',
        'CREATE TRIGGER trx_rme_refund_request_events_append_only BEFORE DELETE OR UPDATE ON public.trx_rme_refund_request_events FOR EACH ROW EXECUTE FUNCTION dms_append_only_guard()',
        'CREATE TRIGGER trx_rme_refund_request_events_append_only_trunc BEFORE TRUNCATE ON public.trx_rme_refund_request_events FOR EACH STATEMENT EXECUTE FUNCTION dms_append_only_guard()',
        'CREATE TRIGGER trx_rme_refund_settlement_events_append_only BEFORE DELETE OR UPDATE ON public.trx_rme_refund_settlement_events FOR EACH ROW EXECUTE FUNCTION dms_append_only_guard()',
        'CREATE TRIGGER trx_rme_refund_settlement_events_append_only_trunc BEFORE TRUNCATE ON public.trx_rme_refund_settlement_events FOR EACH STATEMENT EXECUTE FUNCTION dms_append_only_guard()',
    ],

    /*
    | R6-B3 review round 2, N4: the SHA-256 of the body (prosrc) of every guard
    | function, from the migrated PostgreSQL 16 schema. A guard replaced by
    | CREATE OR REPLACE FUNCTION keeps its owner, its search_path pin and the
    | trigger text, so only its body shows the change. A migration that changes
    | a guard function updates its hash in the same PR.
    */
    'guard_functions' => [
        'dms_append_only_guard' => 'dfd6d2b61df53cf08fe2294edb1ec55f75233c9db356fb38a34548a74e5bda5f',
        'dms_inventory_ledger_guard' => 'c6854280385ed1ef96ddfeb683760285321e716fbc2d70853f850a2fc459fe88',
        'trx_prpcr_refuse_change' => 'edb5fb9704d21310aaa3d90a701f7f0918988250939c2e4a13b4af618483c8b5',
        'trx_rme_amendments_guard' => '5783c0e696cc645226f9fc80416c251496d3a30d258a1ba90447e8363fb5e89b',
        'trx_rme_record_versions_immutable' => '302b4a5d2182a5e2025edfa8582b5badc452ed46cf1aabdd710f176edf860f47',
    ],

    'historical_through' => '2026_11_18_300001_add_unassigned_reason_to_trx_rme_patient_doctor_assignments_table',

    'tables' => [
        'cache' => 'FULL_DML',
        'cache_locks' => 'FULL_DML',
        'failed_jobs' => 'FULL_DML',
        'inv_inventory_activity_logs' => 'FULL_DML',
        'inv_inventory_batches' => 'FULL_DML',
        'inv_inventory_locations' => 'FULL_DML',
        'inv_location_product_minimums' => 'FULL_DML',
        'inv_product_categories' => 'FULL_DML',
        'inv_product_units' => 'FULL_DML',
        'inv_products' => 'FULL_DML',
        'inv_suppliers' => 'FULL_DML',
        'job_batches' => 'FULL_DML',
        'jobs' => 'FULL_DML',
        // written only by the migrator (R6-B4); the runtime reads it
        'migrations' => 'READ_ONLY_RUNTIME',
        'model_has_permissions' => 'FULL_DML',
        'model_has_roles' => 'FULL_DML',
        'mst_branches' => 'FULL_DML',
        'mst_clinic_rooms' => 'FULL_DML',
        'mst_clinical_diagnoses' => 'FULL_DML',
        'mst_clinics' => 'FULL_DML',
        'mst_diagnosis_rollout_settings' => 'FULL_DML',
        'mst_doctor_branch_locks' => 'FULL_DML',
        'mst_doctor_branches' => 'FULL_DML',
        'mst_doctor_device_authorizations' => 'FULL_DML',
        'mst_doctor_devices' => 'FULL_DML',
        'mst_doctors' => 'FULL_DML',
        'mst_external_labs' => 'FULL_DML',
        'mst_lab_service_workload_profiles' => 'FULL_DML',
        'mst_lab_services' => 'FULL_DML',
        'mst_lab_technician_availability_overrides' => 'FULL_DML',
        'mst_lab_technician_capabilities' => 'FULL_DML',
        'mst_lab_technician_capacity_profiles' => 'FULL_DML',
        'mst_patient_documents' => 'FULL_DML',
        'mst_patient_rm_aliases' => 'FULL_DML',
        'mst_patient_rm_sequences' => 'FULL_DML',
        'mst_patients' => 'FULL_DML',
        'mst_payment_methods' => 'FULL_DML',
        'mst_rme_billing_dispute_reviewers' => 'FULL_DML',
        'mst_satusehat_branch_pilot_profiles' => 'FULL_DML',
        'mst_satusehat_code_mappings' => 'FULL_DML',
        'mst_satusehat_entity_identifiers' => 'FULL_DML',
        'mst_satusehat_rollout_waves' => 'FULL_DML',
        'mst_tariffs' => 'FULL_DML',
        'mst_technicians' => 'FULL_DML',
        'mst_treatment_categories' => 'FULL_DML',
        'mst_treatments' => 'FULL_DML',
        'mst_wa_reminder_templates' => 'FULL_DML',
        'notifications' => 'FULL_DML',
        'ops_legacy_import_daily_quotas' => 'FULL_DML',
        'ops_rme_legacy_migration_quotas' => 'FULL_DML',
        'ops_rme_legacy_migration_waves' => 'FULL_DML',
        'ops_rme_legacy_wave_branches' => 'FULL_DML',
        'ops_rme_legacy_wave_operators' => 'FULL_DML',
        'password_reset_tokens' => 'FULL_DML',
        'permissions' => 'FULL_DML',
        'role_has_permissions' => 'FULL_DML',
        'roles' => 'FULL_DML',
        'rpt_inventory_branch_summaries' => 'FULL_DML',
        'rpt_inventory_daily_summaries' => 'FULL_DML',
        'rpt_inventory_product_summaries' => 'FULL_DML',
        'rpt_procurement_daily_summaries' => 'FULL_DML',
        'sessions' => 'FULL_DML',
        'stg_legacy_batch_publish_items' => 'FULL_DML',
        'stg_legacy_batch_publish_runs' => 'FULL_DML',
        'stg_legacy_batch_review_decisions' => 'FULL_DML',
        'stg_legacy_batch_review_sessions' => 'FULL_DML',
        'stg_legacy_mass_upload_batches' => 'FULL_DML',
        'stg_legacy_mass_upload_items' => 'FULL_DML',
        'stg_legacy_patient_import_batches' => 'FULL_DML',
        'stg_legacy_patient_imports' => 'FULL_DML',
        'stg_legacy_review_triage' => 'FULL_DML',
        'stg_odontogram_legacy_import_pages' => 'FULL_DML',
        'stg_odontogram_legacy_imports' => 'FULL_DML',
        'stg_rme_legacy_import_pages' => 'FULL_DML',
        'stg_rme_legacy_imports' => 'FULL_DML',
        'sys_attachments' => 'FULL_DML',
        // rule 248 layer 1 (R6-B5 trigger); no application UPDATE or DELETE
        'sys_audit_logs' => 'APPEND_ONLY',
        'sys_idempotency_keys' => 'FULL_DML',
        'sys_obs_request_events' => 'FULL_DML',
        'sys_obs_slow_queries' => 'FULL_DML',
        'sys_outbox_events' => 'FULL_DML',
        'trx_branch_change_requests' => 'FULL_DML',
        'trx_clinic_visits' => 'FULL_DML',
        'trx_daily_branch_contexts' => 'FULL_DML',
        'trx_diagnosis_requirement_overrides' => 'FULL_DML',
        'trx_doctor_branch_covers' => 'FULL_DML',
        'trx_doctor_branch_lock_requests' => 'FULL_DML',
        'trx_doctor_break_glass_grants' => 'FULL_DML',
        'trx_doctor_device_challenges' => 'FULL_DML',
        'trx_doctor_device_enrollments' => 'FULL_DML',
        'trx_doctor_device_login_challenges' => 'FULL_DML',
        'trx_doctor_device_login_tickets' => 'FULL_DML',
        'trx_doctor_device_webauthn_challenges' => 'FULL_DML',
        'trx_doctor_device_webauthn_credentials' => 'FULL_DML',
        'trx_doctor_session_leases' => 'FULL_DML',
        'trx_goods_receipt_items' => 'FULL_DML',
        'trx_goods_receipts' => 'FULL_DML',
        'trx_inventory_batch_action_logs' => 'FULL_DML',
        'trx_inventory_batch_backfill_logs' => 'FULL_DML',
        'trx_inventory_batch_disposal_requests' => 'FULL_DML',
        // ledger (CG-10, D-Q4-a): governed batch backfill only; row-locked by MissingBatchBackfillService
        'trx_inventory_movements' => ['APPEND_ONLY_WITH_COLUMNS', ['inventory_batch_id', 'updated_at']],
        'trx_invoice_items' => 'FULL_DML',
        'trx_invoices' => 'FULL_DML',
        'trx_lab_case_candidates' => 'FULL_DML',
        'trx_lab_deliveries' => 'FULL_DML',
        'trx_lab_delivery_tasks' => 'FULL_DML',
        'trx_lab_external_dispatches' => 'FULL_DML',
        'trx_lab_model_analyses' => 'FULL_DML',
        'trx_lab_order_assignments' => 'FULL_DML',
        'trx_lab_order_items' => 'FULL_DML',
        // rule 248 layer 1 (R6-B5 trigger)
        'trx_lab_order_status_logs' => 'APPEND_ONLY',
        'trx_lab_orders' => 'FULL_DML',
        'trx_lab_pickup_tasks' => 'FULL_DML',
        'trx_lab_production_steps' => 'FULL_DML',
        'trx_lab_qc_checklists' => 'FULL_DML',
        'trx_lab_quality_controls' => 'FULL_DML',
        'trx_lab_remake_requests' => 'FULL_DML',
        'trx_lab_work_logs' => 'FULL_DML',
        'trx_lab_workflow_evidence' => 'FULL_DML',
        'trx_medical_record_diagnoses' => 'FULL_DML',
        'trx_medical_record_handwriting_pages' => 'FULL_DML',
        'trx_medical_record_handwritings' => 'FULL_DML',
        'trx_medical_records' => 'FULL_DML',
        'trx_odontogram_legacy_record_pages' => 'FULL_DML',
        'trx_odontogram_legacy_records' => 'FULL_DML',
        'trx_odontograms' => 'FULL_DML',
        'trx_patient_merge_cases' => 'FULL_DML',
        'trx_patient_merge_field_resolutions' => 'FULL_DML',
        // trigger trx_prpcr_no_update_delete
        'trx_patient_rm_paper_card_registrations' => 'APPEND_ONLY',
        'trx_payments' => 'FULL_DML',
        'trx_purchase_order_items' => 'FULL_DML',
        'trx_purchase_orders' => 'FULL_DML',
        'trx_purchase_request_items' => 'FULL_DML',
        'trx_purchase_requests' => 'FULL_DML',
        // trigger trx_rme_amendments_guard and RmeAmendment::REQUEST_COLUMNS: only the decision columns change; row-locked by the amendment workflow
        'trx_rme_amendments' => ['APPEND_ONLY_WITH_COLUMNS', ['status', 'cancelled_at', 'decided_by', 'decided_at', 'decision_reason', 'applied_version_id', 'applied_version', 'updated_at']],
        // rule 248 layer 1 (R6-B5 trigger); model guard
        'trx_rme_billing_dispute_events' => 'APPEND_ONLY',
        'trx_rme_billing_disputes' => 'FULL_DML',
        'trx_rme_clinical_photos' => 'FULL_DML',
        // rule 248 layer 1 (R6-B5 trigger); model guard
        'trx_rme_invoice_correction_events' => 'APPEND_ONLY',
        'trx_rme_invoice_correction_lines' => 'FULL_DML',
        'trx_rme_invoice_corrections' => 'FULL_DML',
        // model guard RmeInvoiceDocumentVersion; no application UPDATE or DELETE
        'trx_rme_invoice_document_versions' => 'APPEND_ONLY',
        'trx_rme_invoice_items' => 'FULL_DML',
        'trx_rme_invoices' => 'FULL_DML',
        'trx_rme_legacy_record_pages' => 'FULL_DML',
        'trx_rme_legacy_records' => 'FULL_DML',
        // rule 248 layer 1 (R6-B5 trigger); model guard
        'trx_rme_overpayment_obligation_events' => 'APPEND_ONLY',
        'trx_rme_overpayment_obligations' => 'FULL_DML',
        'trx_rme_owner_approval_references' => 'FULL_DML',
        'trx_rme_patient_doctor_assignments' => 'FULL_DML',
        'trx_rme_payment_submissions' => 'FULL_DML',
        // RmePayment::MUTABLE_ATTRIBUTES; patient merge re-points patient_id (PatientOwnedRecordRegistry)
        'trx_rme_payments' => ['APPEND_ONLY_WITH_COLUMNS', ['patient_id', 'updated_at']],
        'trx_rme_performed_treatments' => 'FULL_DML',
        'trx_rme_prescription_whatsapp_deliveries' => 'FULL_DML',
        'trx_rme_prescriptions' => 'FULL_DML',
        // model guard RmeReceiptSnapshot; no application UPDATE or DELETE
        'trx_rme_receipt_snapshots' => 'APPEND_ONLY',
        'trx_rme_receivable_follow_ups' => 'FULL_DML',
        // immutability trigger trx_rme_record_versions_immutable (rule 240)
        'trx_rme_record_versions' => 'APPEND_ONLY',
        'trx_rme_refund_patient_references' => 'FULL_DML',
        // rule 248 layer 1 (R6-B5 trigger); model guard
        'trx_rme_refund_request_events' => 'APPEND_ONLY',
        'trx_rme_refund_requests' => 'FULL_DML',
        // rule 248 layer 1 (R6-B5 trigger); model guard
        'trx_rme_refund_settlement_events' => 'APPEND_ONLY',
        'trx_rme_refund_settlements' => 'FULL_DML',
        'trx_rme_treatment_declarations' => 'FULL_DML',
        'trx_rme_visit_consent_doctor_signatures' => 'FULL_DML',
        'trx_rme_visit_consents' => 'FULL_DML',
        'trx_satusehat_audit_logs' => 'FULL_DML',
        'trx_satusehat_branch_score_snapshots' => 'FULL_DML',
        'trx_satusehat_branch_transitions' => 'FULL_DML',
        'trx_satusehat_candidates' => 'FULL_DML',
        'trx_satusehat_change_requests' => 'FULL_DML',
        'trx_satusehat_data_quality_issues' => 'FULL_DML',
        'trx_satusehat_incident_drill_runs' => 'FULL_DML',
        'trx_satusehat_pilot_rehearsal_runs' => 'FULL_DML',
        'trx_satusehat_submission_batches' => 'FULL_DML',
        'trx_satusehat_submission_items' => 'FULL_DML',
        'trx_satusehat_uat_runs' => 'FULL_DML',
        'trx_satusehat_uat_scenarios' => 'FULL_DML',
        'trx_satusehat_uat_signoffs' => 'FULL_DML',
        'trx_satusehat_wave_branch_memberships' => 'FULL_DML',
        'trx_stock_opname_items' => 'FULL_DML',
        'trx_stock_opnames' => 'FULL_DML',
        'trx_stock_transfer_items' => 'FULL_DML',
        'trx_stock_transfers' => 'FULL_DML',
        'trx_user_online_contexts' => 'FULL_DML',
        'users' => 'FULL_DML',
    ],
];
