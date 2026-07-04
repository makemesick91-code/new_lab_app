<?php

namespace App\Services\Architecture;

/**
 * Static canonical entity and workflow registry for NSF-4.
 * Read-only metadata — no row-level data.
 */
class CanonicalEntityRegistry
{
    /** @return list<string> */
    public static function domains(): array
    {
        return ['foundation', 'rme', 'cashier', 'inventory', 'lab', 'owner', 'telemetry'];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function entities(): array
    {
        return [
            // Foundation
            self::entity('Branch', 'foundation', 'mst_branches', 'App\\Modules\\Branch\\Models\\Branch', 'global', 'source_of_truth', [], ['internal'], 'ready'),
            self::entity('User', 'foundation', 'users', 'App\\Models\\User', 'global', 'source_of_truth', [], ['internal', 'PII'], 'ready'),
            self::entity('Role', 'foundation', 'roles', 'Spatie\\Permission\\Models\\Role', 'global', 'source_of_truth', [], ['internal'], 'ready'),
            self::entity('Permission', 'foundation', 'permissions', 'Spatie\\Permission\\Models\\Permission', 'global', 'source_of_truth', [], ['internal'], 'ready'),
            self::entity('Clinic Room', 'foundation', 'mst_clinic_rooms', 'App\\Modules\\ClinicRoom\\Models\\ClinicRoom', 'branch_scoped', 'source_of_truth', ['branch_id'], ['internal'], 'ready'),
            self::entity('Doctor', 'foundation', 'mst_doctors', 'App\\Modules\\Doctor\\Models\\Doctor', 'branch_scoped', 'source_of_truth', ['branch_id', 'user_id'], ['internal'], 'ready'),
            self::entity('Treatment Category', 'foundation', 'mst_treatment_categories', 'App\\Modules\\TreatmentCategory\\Models\\TreatmentCategory', 'global', 'source_of_truth', [], ['internal'], 'ready'),
            self::entity('Treatment', 'foundation', 'mst_treatments', 'App\\Modules\\Treatment\\Models\\Treatment', 'global', 'source_of_truth', ['treatment_category_id'], ['internal'], 'ready'),
            self::entity('Tariff', 'foundation', 'mst_tariffs', 'App\\Modules\\Tariff\\Models\\Tariff', 'branch_scoped', 'source_of_truth', ['branch_id', 'treatment_id'], ['financial'], 'ready'),
            self::entity('Payment Method', 'foundation', 'mst_payment_methods', 'App\\Modules\\PaymentMethod\\Models\\PaymentMethod', 'global', 'source_of_truth', [], ['internal'], 'ready'),
            self::entity('WhatsApp Reminder Template', 'foundation', 'mst_wa_reminder_templates', 'App\\Modules\\WaReminderTemplate\\Models\\WaReminderTemplate', 'branch_scoped', 'source_of_truth', ['branch_id'], ['internal'], 'ready'),
            self::entity('Clinic', 'foundation', 'mst_clinics', 'App\\Modules\\Clinic\\Models\\Clinic', 'branch_scoped', 'source_of_truth', ['branch_id'], ['internal'], 'ready'),

            // RME / Clinical
            self::entity('Patient', 'rme', 'mst_patients', 'App\\Modules\\Patient\\Models\\Patient', 'branch_scoped', 'source_of_truth', ['branch_id', 'doctor_id', 'clinic_id'], ['PII'], 'ready', 'KTP masked in UI/export; no full NIK in reports'),
            self::entity('Clinic Visit', 'rme', 'trx_clinic_visits', 'App\\Modules\\ClinicVisit\\Models\\ClinicVisit', 'branch_scoped', 'source_of_truth', ['branch_id', 'patient_id', 'clinic_room_id'], ['PII', 'PHI'], 'ready', 'Consent on visit row; room gate before exam'),
            self::entity('Medical Record', 'rme', 'trx_medical_records', 'App\\Modules\\MedicalRecord\\Models\\MedicalRecord', 'branch_scoped', 'source_of_truth', ['clinic_visit_id', 'patient_id'], ['PHI'], 'ready', 'Handwriting PNG mandatory before finalize; SOAP hidden in doctor UI'),
            self::entity('Medical Record Handwriting', 'rme', 'trx_medical_record_handwritings', 'App\\Modules\\MedicalRecord\\Models\\MedicalRecordHandwriting', 'branch_scoped', 'source_of_truth', ['medical_record_id'], ['PHI'], 'ready'),
            self::entity('Medical Record Handwriting Page', 'rme', 'trx_medical_record_handwriting_pages', 'App\\Modules\\MedicalRecord\\Models\\MedicalRecordHandwritingPage', 'branch_scoped', 'source_of_truth', ['medical_record_id'], ['PHI'], 'ready'),
            self::entity('Odontogram', 'rme', 'trx_odontograms', 'App\\Modules\\Odontogram\\Models\\Odontogram', 'branch_scoped', 'source_of_truth', ['clinic_visit_id'], ['PHI'], 'ready', 'tooth_map_payload JSON; structured print only'),
            self::entity('Patient Document', 'rme', 'mst_patient_documents', 'App\\Modules\\Patient\\Models\\PatientDocument', 'branch_scoped', 'source_of_truth', ['patient_id'], ['PII', 'PHI'], 'needs_review', 'Scanned docs — access gated; never in inventory command output'),
            self::entity('RME Prescription', 'rme', 'trx_rme_prescriptions', 'App\\Modules\\Prescription\\Models\\RmePrescription', 'branch_scoped', 'source_of_truth', ['clinic_visit_id'], ['PHI'], 'needs_review'),
            self::entity('Patient Doctor Assignment', 'rme', 'trx_rme_patient_doctor_assignments', 'App\\Modules\\RME\\Models\\PatientDoctorAssignment', 'branch_scoped', 'source_of_truth', ['patient_id', 'doctor_id'], ['internal'], 'ready'),
            self::entity('Legacy Patient Import Batch', 'rme', 'stg_legacy_patient_import_batches', 'App\\Modules\\Patient\\Models\\LegacyPatientImportBatch', 'global', 'source_of_truth', [], ['PII'], 'ready', 'Staging only; KTP in payload column never exported'),

            // Cashier / Receivable
            self::entity('RME Invoice', 'cashier', 'trx_rme_invoices', 'App\\Modules\\RmeInvoice\\Models\\RmeInvoice', 'branch_scoped', 'source_of_truth', ['branch_id', 'clinic_visit_id', 'patient_id'], ['financial', 'PII'], 'ready'),
            self::entity('RME Invoice Item', 'cashier', 'trx_rme_invoice_items', 'App\\Modules\\RmeInvoice\\Models\\RmeInvoiceItem', 'branch_scoped', 'source_of_truth', ['rme_invoice_id', 'treatment_id'], ['financial'], 'ready'),
            self::entity('RME Payment', 'cashier', 'trx_rme_payments', 'App\\Modules\\RmeInvoice\\Models\\RmePayment', 'branch_scoped', 'source_of_truth', ['rme_invoice_id'], ['financial'], 'ready', 'payment_batch_uuid for carry-over allocation'),
            self::entity('Receivable', 'cashier', 'trx_rme_invoices', 'App\\Modules\\RmeInvoice\\Models\\RmeInvoice', 'branch_scoped', 'derived', ['branch_id', 'patient_id'], ['financial', 'PII'], 'ready', 'Derived view: UNPAID/PARTIAL invoices with remaining>0'),
            self::entity('Receivable Follow-up', 'cashier', 'trx_rme_receivable_follow_ups', 'App\\Modules\\RmeInvoice\\Models\\RmeReceivableFollowUp', 'branch_scoped', 'source_of_truth', ['rme_invoice_id'], ['financial'], 'ready'),
            self::entity('Lab Invoice', 'cashier', 'trx_invoices', 'App\\Modules\\Invoice\\Models\\Invoice', 'branch_scoped', 'source_of_truth', ['branch_id'], ['financial'], 'ready', 'Lab billing separate from RME'),

            // Inventory
            self::entity('Inventory Product', 'inventory', 'inv_products', 'App\\Modules\\Inventory\\Models\\Product', 'branch_scoped', 'source_of_truth', ['branch_id', 'product_category_id', 'product_unit_id'], ['internal'], 'ready'),
            self::entity('Product Category', 'inventory', 'inv_product_categories', 'App\\Modules\\Inventory\\Models\\ProductCategory', 'branch_scoped', 'source_of_truth', ['branch_id'], ['internal'], 'ready'),
            self::entity('Product Unit', 'inventory', 'inv_product_units', 'App\\Modules\\Inventory\\Models\\ProductUnit', 'global', 'source_of_truth', [], ['internal'], 'ready'),
            self::entity('Inventory Location', 'inventory', 'inv_inventory_locations', 'App\\Modules\\Inventory\\Models\\InventoryLocation', 'branch_scoped', 'source_of_truth', ['branch_id'], ['internal'], 'ready'),
            self::entity('Inventory Movement', 'inventory', 'trx_inventory_movements', 'App\\Modules\\Inventory\\Models\\InventoryMovement', 'branch_scoped', 'source_of_truth', ['branch_id', 'product_id', 'inventory_location_id', 'inventory_batch_id'], ['internal', 'financial'], 'ready', 'Ledger-only stock; SUM(in)-SUM(out)'),
            self::entity('Inventory Batch', 'inventory', 'inv_inventory_batches', 'App\\Modules\\Inventory\\Models\\InventoryBatch', 'branch_scoped', 'source_of_truth', ['branch_id', 'product_id'], ['internal'], 'ready'),
            self::entity('Batch Disposal Request', 'inventory', 'trx_inventory_batch_disposal_requests', 'App\\Modules\\Inventory\\Models\\InventoryBatchDisposalRequest', 'branch_scoped', 'source_of_truth', ['branch_id', 'inventory_batch_id'], ['internal'], 'ready'),
            self::entity('Purchase Request', 'inventory', 'trx_purchase_requests', 'App\\Modules\\Inventory\\Models\\PurchaseRequest', 'branch_scoped', 'source_of_truth', ['branch_id'], ['internal'], 'ready'),
            self::entity('Purchase Order', 'inventory', 'trx_purchase_orders', 'App\\Modules\\Inventory\\Models\\PurchaseOrder', 'branch_scoped', 'source_of_truth', ['branch_id', 'purchase_request_id'], ['internal', 'financial'], 'ready'),
            self::entity('Goods Receipt', 'inventory', 'trx_goods_receipts', 'App\\Modules\\Inventory\\Models\\GoodsReceipt', 'branch_scoped', 'source_of_truth', ['branch_id', 'purchase_order_id'], ['internal', 'financial'], 'ready'),
            self::entity('Stock Transfer', 'inventory', 'trx_stock_transfers', 'App\\Modules\\Inventory\\Models\\StockTransfer', 'branch_scoped', 'source_of_truth', ['branch_id'], ['internal'], 'ready'),
            self::entity('Stock Opname', 'inventory', 'trx_stock_opnames', 'App\\Modules\\Inventory\\Models\\StockOpname', 'branch_scoped', 'source_of_truth', ['branch_id'], ['internal'], 'ready'),
            self::entity('Stock Opname Item', 'inventory', 'trx_stock_opname_items', 'App\\Modules\\Inventory\\Models\\StockOpnameItem', 'branch_scoped', 'source_of_truth', ['stock_opname_id', 'inventory_batch_id'], ['internal'], 'ready'),
            self::entity('Inventory Activity Log', 'inventory', 'inv_inventory_activity_logs', 'App\\Modules\\Inventory\\Models\\InventoryActivityLog', 'branch_scoped', 'source_of_truth', ['branch_id'], ['internal'], 'ready'),
            self::entity('Location Product Minimum', 'inventory', 'inv_location_product_minimums', 'App\\Modules\\Inventory\\Models\\LocationProductMinimum', 'branch_scoped', 'source_of_truth', ['branch_id', 'inventory_location_id', 'product_id'], ['internal'], 'ready'),
            self::entity('Supplier', 'inventory', 'inv_suppliers', 'App\\Modules\\Inventory\\Models\\Supplier', 'branch_scoped', 'source_of_truth', ['branch_id'], ['internal'], 'ready'),

            // Lab / Production
            self::entity('Lab Order', 'lab', 'trx_lab_orders', 'App\\Modules\\LabOrder\\Models\\LabOrder', 'branch_scoped', 'source_of_truth', ['branch_id'], ['internal'], 'ready'),
            self::entity('Lab Order Item', 'lab', 'trx_lab_order_items', 'App\\Modules\\LabOrder\\Models\\LabOrderItem', 'branch_scoped', 'source_of_truth', ['lab_order_id', 'lab_service_id'], ['internal'], 'ready'),
            self::entity('Lab Case Candidate', 'lab', 'trx_lab_case_candidates', 'App\\Modules\\LabOrder\\Models\\LabCaseCandidate', 'branch_scoped', 'source_of_truth', ['branch_id', 'rme_invoice_item_id'], ['internal'], 'ready'),
            self::entity('Lab Service', 'lab', 'mst_lab_services', 'App\\Modules\\LabService\\Models\\LabService', 'global', 'source_of_truth', [], ['internal'], 'ready'),
            self::entity('Quality Control', 'lab', 'trx_lab_quality_controls', 'App\\Modules\\QualityControl\\Models\\QualityControl', 'branch_scoped', 'source_of_truth', ['lab_order_id'], ['internal'], 'ready'),
            self::entity('Delivery', 'lab', 'trx_lab_deliveries', 'App\\Modules\\Delivery\\Models\\Delivery', 'branch_scoped', 'source_of_truth', ['branch_id', 'lab_order_id'], ['internal'], 'ready'),
            self::entity('Production Assignment', 'lab', 'trx_lab_order_assignments', 'App\\Modules\\Production\\Models\\LabOrderAssignment', 'branch_scoped', 'source_of_truth', ['lab_order_id'], ['internal'], 'ready'),

            // Owner / Reporting
            self::entity('Inventory Analytics Summary', 'owner', 'rpt_inventory_branch_summaries', null, 'branch_scoped', 'reporting', ['branch_id'], ['internal'], 'ready', 'Derived snapshot tables'),
            self::entity('Procurement Daily Summary', 'owner', 'rpt_procurement_daily_summaries', null, 'branch_scoped', 'reporting', ['branch_id'], ['internal'], 'ready'),
            self::entity('Owner Dashboard KPI', 'owner', null, null, 'branch_scoped', 'derived', [], ['financial'], 'ready', 'Computed via OwnerDashboardKpiService'),

            // Telemetry
            self::entity('Performance Runtime Evidence', 'telemetry', null, null, 'system', 'telemetry', [], ['internal'], 'ready', 'storage/app/performance/*.json'),
            self::entity('Audit Log', 'telemetry', 'sys_audit_logs', 'App\\Modules\\LabOrder\\Models\\AuditLog', 'branch_scoped', 'source_of_truth', ['branch_id'], ['internal'], 'ready'),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function workflows(): array
    {
        return [
            [
                'name' => 'RME Visit Lifecycle',
                'domain' => 'rme',
                'entities' => ['Clinic Visit', 'Medical Record', 'Odontogram', 'Clinic Room'],
                'status_flow' => ['registered', 'waiting', 'in_progress', 'cashier_pending', 'completed', 'cancelled'],
                'branch_scope' => true,
                'critical_rules' => [
                    'Room assignment required before doctor examination',
                    'Doctor advances to cashier_pending only; completed via payment',
                    'BranchContext::requireId() for all writes',
                ],
            ],
            [
                'name' => 'RME Billing and Payment',
                'domain' => 'cashier',
                'entities' => ['RME Invoice', 'RME Payment', 'Clinic Visit', 'Receivable'],
                'status_flow' => ['DRAFT', 'UNPAID', 'PARTIAL', 'PAID', 'VOID'],
                'branch_scope' => true,
                'critical_rules' => [
                    'Requires finalized RM + consent + cashier_pending visit',
                    'Any payment > 0 completes visit; partial creates piutang',
                    'Optional receivable carry-over via payment_batch_uuid',
                ],
            ],
            [
                'name' => 'Receivable Follow-up',
                'domain' => 'cashier',
                'entities' => ['Receivable', 'Receivable Follow-up', 'RME Invoice'],
                'status_flow' => ['NEW', 'CONTACTED', 'PROMISED', 'FOLLOW_UP_LATER', 'ESCALATED', 'CLOSED'],
                'branch_scope' => true,
                'critical_rules' => ['Derived from UNPAID/PARTIAL invoices with remaining balance'],
            ],
            [
                'name' => 'Inventory Procurement',
                'domain' => 'inventory',
                'entities' => ['Purchase Request', 'Purchase Order', 'Goods Receipt', 'Inventory Movement'],
                'status_flow' => ['PR: draft→submitted→approved', 'PO: draft→submitted→approved→sent→received', 'GR: draft→submitted→posted'],
                'branch_scope' => true,
                'critical_rules' => ['Goods receipt posts ledger movements; no mutable stock columns'],
            ],
            [
                'name' => 'Stock Transfer',
                'domain' => 'inventory',
                'entities' => ['Stock Transfer', 'Inventory Movement'],
                'status_flow' => ['draft', 'submitted', 'in_transit', 'received', 'completed', 'cancelled'],
                'branch_scope' => true,
                'critical_rules' => ['Inter-location within branch; ledger out/in pairs'],
            ],
            [
                'name' => 'Stock Opname',
                'domain' => 'inventory',
                'entities' => ['Stock Opname', 'Stock Opname Item', 'Inventory Movement'],
                'status_flow' => ['DRAFT', 'COUNTING', 'COMPLETED', 'CANCELLED'],
                'branch_scope' => true,
                'critical_rules' => ['Variance posts adjustment movements'],
            ],
            [
                'name' => 'Batch Disposal',
                'domain' => 'inventory',
                'entities' => ['Batch Disposal Request', 'Inventory Batch', 'Inventory Movement'],
                'status_flow' => ['pending', 'approved', 'rejected', 'completed'],
                'branch_scope' => true,
                'critical_rules' => ['Approval workflow before ledger disposal movement'],
            ],
            [
                'name' => 'Lab Order Lifecycle',
                'domain' => 'lab',
                'entities' => ['Lab Order', 'Lab Order Item', 'Quality Control', 'Delivery'],
                'status_flow' => ['DRAFT', 'RECEIVED', 'ASSIGNED', 'IN_PRODUCTION', 'QC_PENDING', 'QC_PASSED', 'READY_FOR_DELIVERY', 'IN_DELIVERY', 'DELIVERED', 'COMPLETED', 'CANCELLED'],
                'branch_scope' => true,
                'critical_rules' => ['Separate from RME billing; branch-scoped'],
            ],
            [
                'name' => 'RME to Lab Integration',
                'domain' => 'lab',
                'entities' => ['Lab Case Candidate', 'RME Invoice Item', 'Lab Order'],
                'status_flow' => ['pending_review', 'converted_to_lab_order', 'rejected', 'cancelled'],
                'branch_scope' => true,
                'critical_rules' => ['Post-payment candidate generation; manual conversion to LabOrder'],
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function gaps(): array
    {
        return [
            ['id' => 'DMO-001', 'area' => 'rme', 'gap' => 'Patient Document sensitivity classification needs formal DMO policy', 'severity' => 'medium'],
            ['id' => 'DMO-002', 'area' => 'lab', 'gap' => 'Lab QC checklist vs quality control entity naming alignment for DMO', 'severity' => 'low'],
            ['id' => 'DMO-003', 'area' => 'cashier', 'gap' => 'Receivable is derived not a separate table — document for metrics layer', 'severity' => 'low'],
            ['id' => 'DMO-004', 'area' => 'inventory', 'gap' => 'Expiry alert is computed service not persisted entity', 'severity' => 'low'],
            ['id' => 'DMO-005', 'area' => 'owner', 'gap' => 'Owner KPI sources span multiple services — needs unified metric registry in DMO-1', 'severity' => 'medium'],
            ['id' => 'DMO-006', 'area' => 'foundation', 'gap' => 'Treatment/tariff branch vs global scope boundary for multi-branch pricing', 'severity' => 'medium'],
        ];
    }

    /**
     * @return list<string>
     */
    public static function dmoRecommendations(): array
    {
        return [
            'Adopt canonical_name from this registry as DMO-1 ontology seed',
            'Map branch_scoped entities to BranchContext dimension in metrics layer',
            'Treat Receivable and Owner KPI as derived metrics with explicit source tables',
            'Never materialize PHI/PII sample values in reporting or telemetry layers',
            'Use Inventory Movement as sole stock truth — block mutable stock in DMO schemas',
            'Resolve Patient Document and QC model ambiguities before canonical metric definitions',
        ];
    }

    /**
     * @param  list<string>  $relationships
     * @param  list<string>  $sensitivity
     */
    private static function entity(
        string $canonicalName,
        string $domain,
        ?string $table,
        ?string $model,
        string $scope,
        string $sourceType,
        array $relationships,
        array $sensitivity,
        string $dmoReadiness,
        ?string $privacyNotes = null,
    ): array {
        return [
            'canonical_name' => $canonicalName,
            'domain' => $domain,
            'primary_table' => $table,
            'model' => $model,
            'scope' => $scope,
            'source_type' => $sourceType,
            'lifecycle_status_fields' => [],
            'relationships' => $relationships,
            'sensitivity' => $sensitivity,
            'privacy_notes' => $privacyNotes,
            'downstream_consumers' => [],
            'dmo_readiness' => $dmoReadiness,
            'gaps' => [],
        ];
    }
}
