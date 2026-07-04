<?php

namespace App\Services\Architecture;

/**
 * Static canonical KPI/metric registry for NSF-5.
 * Read-only metadata — no row-level or sample values.
 */
class CanonicalMetricRegistry
{
    /** @return list<string> */
    public static function domains(): array
    {
        return ['rme', 'cashier', 'inventory', 'lab', 'owner', 'system'];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function metrics(): array
    {
        return [
            // RME / Clinical
            self::metric('total_patients', ['Total Pasien'], 'rme', 'Patient', ['Patient'], ['mst_patients'], 'source_of_truth', 'COUNT(mst_patients.id) WHERE deleted_at IS NULL', 'per_branch', ['branch'], 'internal', 'canonical', 'ready', ['settings.patients.index'], ['PatientDataCompletenessAuditTest']),
            self::metric('active_patients', ['Pasien Aktif'], 'rme', 'Patient', ['Patient', 'Clinic Visit'], ['mst_patients', 'trx_clinic_visits'], 'computed', 'Patients with ≥1 non-cancelled visit in period (report-dependent)', 'per_branch_per_date_range', ['branch', 'date'], 'internal', 'needs_review', 'needs_review', ['rme.patients.audit'], [], 'Active patient definition varies by report; DMO-1 must fix grain'),
            self::metric('new_patients', ['Pasien Baru', 'new_patients'], 'rme', 'Patient', ['Patient'], ['mst_patients'], 'source_of_truth', 'COUNT WHERE created_at BETWEEN from AND to', 'per_branch_per_date_range', ['branch', 'date'], 'PII', 'canonical', 'ready', ['dashboard', 'settings.patients.index'], ['OwnerKpiDashboardTest'], consumers: ['OwnerDashboardKpiService::metrics', 'HomeDashboardController']),
            self::metric('unique_patients', ['Pasien Unik'], 'rme', 'ClinicVisit', ['Clinic Visit', 'Patient'], ['trx_clinic_visits'], 'computed', 'COUNT(DISTINCT patient_id) in date range excluding cancelled', 'per_branch_per_date_range', ['branch', 'date'], 'PII', 'needs_review', 'needs_review', [], [], 'Not all reports expose distinct patient count separately from visits'),
            self::metric('total_visits', ['Total Kunjungan', 'total_visits'], 'rme', 'ClinicVisit', ['Clinic Visit'], ['trx_clinic_visits'], 'source_of_truth', 'COUNT WHERE status != cancelled AND visit_date in range', 'per_branch_per_date_range', ['branch', 'date', 'status', 'doctor', 'room'], 'PII', 'canonical', 'ready', ['rme.visits.index', 'dashboard'], ['OwnerKpiDashboardTest', 'ClinicVisitTest'], consumers: ['OwnerDashboardKpiService::metrics', 'OwnerDashboardKpiService::dailyVisitTrend']),
            self::metric('new_visits', ['Kunjungan Baru'], 'rme', 'ClinicVisit', ['Clinic Visit'], ['trx_clinic_visits'], 'computed', 'First-ever visit per patient in period (derived)', 'per_branch_per_date_range', ['branch', 'date'], 'PII', 'needs_review', 'needs_review', ['rme.visits.index'], [], 'No dedicated persisted flag; derive from patient first visit_date'),
            self::metric('follow_up_visits', ['Kunjungan Kontrol'], 'rme', 'ClinicVisit', ['Clinic Visit'], ['trx_clinic_visits'], 'computed', 'Visits where patient has prior completed visit (control chain)', 'per_branch_per_date_range', ['branch', 'date', 'patient'], 'PII', 'needs_review', 'needs_review', ['rme.visits.index'], ['RmeControlVisitReceivableCarryOverPaymentTest'], 'Uses control_visit_id / patient history'),
            self::metric('completed_visits', ['Kunjungan Selesai'], 'rme', 'ClinicVisit', ['Clinic Visit'], ['trx_clinic_visits'], 'source_of_truth', 'COUNT WHERE status = completed', 'per_branch_per_date_range', ['branch', 'date', 'status'], 'PII', 'canonical', 'ready', ['rme.visits.index'], ['ClinicVisitTest']),
            self::metric('cancelled_visits', ['Kunjungan Batal'], 'rme', 'ClinicVisit', ['Clinic Visit'], ['trx_clinic_visits'], 'source_of_truth', 'COUNT WHERE status = cancelled', 'per_branch_per_date_range', ['branch', 'date', 'status'], 'PII', 'canonical', 'ready', ['rme.visits.index'], ['ClinicVisitTest']),
            self::metric('cashier_pending_visits', ['Menunggu Kasir'], 'rme', 'ClinicVisit', ['Clinic Visit'], ['trx_clinic_visits'], 'source_of_truth', 'COUNT WHERE status = cashier_pending', 'per_branch_snapshot', ['branch', 'status'], 'PII', 'canonical', 'ready', ['rme.visits.index', 'rme.cashier.index'], ['RmeDoctorCashierCompletionGateTest']),
            self::metric('finalized_medical_records', ['RM Final'], 'rme', 'MedicalRecord', ['Medical Record'], ['trx_medical_records'], 'source_of_truth', 'COUNT WHERE finalized_at IS NOT NULL', 'per_branch_per_date_range', ['branch', 'date'], 'PHI', 'canonical', 'ready', ['rme.visits.medical-record.show'], ['MedicalRecordTest']),
            self::metric('draft_medical_records', ['RM Draft'], 'rme', 'MedicalRecord', ['Medical Record'], ['trx_medical_records'], 'source_of_truth', 'COUNT WHERE finalized_at IS NULL', 'per_branch_snapshot', ['branch'], 'PHI', 'canonical', 'ready', ['rme.visits.medical-record.show'], ['MedicalRecordTest']),
            self::metric('odontogram_count', ['Odontogram'], 'rme', 'Odontogram', ['Odontogram'], ['trx_odontograms'], 'source_of_truth', 'COUNT(trx_odontograms.id)', 'per_branch_per_date_range', ['branch', 'date'], 'PHI', 'canonical', 'ready', ['rme.visits.odontogram.show'], ['OdontogramTest']),
            self::metric('follow_up_due_count', ['Follow-up Jatuh Tempo', 'follow_up_due'], 'rme', 'RmeInvoice', ['Receivable', 'Receivable Follow-up', 'RME Invoice'], ['trx_rme_invoices', 'trx_rme_receivable_follow_ups'], 'computed', 'UNPAID/PARTIAL invoices with latestFollowUp.next_follow_up_date <= today', 'per_branch_snapshot', ['branch', 'date'], 'financial', 'canonical', 'ready', ['rme.cashier.receivables', 'dashboard'], ['OwnerKpiDashboardTest'], consumers: ['OwnerDashboardKpiService::metrics']),

            // Cashier / Receivable
            self::metric('gross_revenue', ['Pendapatan Kotor'], 'cashier', 'RmeInvoice', ['RME Invoice'], ['trx_rme_invoices'], 'computed', 'SUM(grand_total) for non-VOID non-DRAFT invoices in period', 'per_branch_per_date_range', ['branch', 'date', 'status'], 'financial', 'needs_review', 'needs_review', [], [], 'Pilot uses payment sum as revenue KPI; gross may differ from collected'),
            self::metric('net_revenue', ['Pendapatan Bersih'], 'cashier', 'RmeInvoice', ['RME Invoice', 'RME Payment'], ['trx_rme_invoices', 'trx_rme_payments'], 'computed', 'Not separately materialized in pilot', 'per_branch_per_date_range', ['branch', 'date'], 'financial', 'needs_review', 'blocked', [], [], 'Deferred to DMO-1 — no canonical net revenue field'),
            self::metric('invoiced_amount', ['Total Tagihan'], 'cashier', 'RmeInvoice', ['RME Invoice'], ['trx_rme_invoices'], 'source_of_truth', 'SUM(grand_total) in period excluding VOID/DRAFT', 'per_branch_per_date_range', ['branch', 'date', 'status'], 'financial', 'canonical', 'ready', ['rme.cashier.index'], ['RmePaymentTest'], consumers: ['OwnerDashboardKpiService::metrics (billable for collection_rate)']),
            self::metric('paid_amount', ['Pembayaran', 'total_revenue'], 'cashier', 'RmePayment', ['RME Payment'], ['trx_rme_payments'], 'source_of_truth', 'SUM(trx_rme_payments.amount) WHERE paid_at in range', 'per_branch_per_date_range', ['branch', 'date', 'payment_method'], 'financial', 'canonical', 'ready', ['rme.cashier.index', 'dashboard'], ['OwnerKpiDashboardTest', 'RmePaymentTest'], consumers: ['OwnerDashboardKpiService::metrics', 'OwnerDashboardKpiService::dailyPaymentTrend']),
            self::metric('unpaid_amount', ['Belum Dibayar'], 'cashier', 'RmeInvoice', ['Receivable', 'RME Invoice'], ['trx_rme_invoices', 'trx_rme_payments'], 'derived', 'SUM(remaining) for UNPAID/PARTIAL where remaining>0', 'per_branch_snapshot', ['branch', 'status'], 'financial', 'canonical', 'ready', ['rme.cashier.receivables'], ['ReceivableTest']),
            self::metric('remaining_receivable', ['Piutang Aktif', 'active_receivable'], 'cashier', 'RmeInvoice', ['Receivable', 'RME Invoice', 'RME Payment'], ['trx_rme_invoices', 'trx_rme_payments'], 'derived', 'SUM(max(0, grand_total - payments_sum)) for UNPAID/PARTIAL grand_total>0', 'per_branch_snapshot', ['branch', 'status'], 'financial', 'duplicate', 'ready', ['rme.cashier.receivables', 'dashboard'], ['OwnerKpiDashboardTest', 'PatientOutstandingReceivableCarryOverTest'], 'Alias active_receivable in Owner KPI; same formula', consumers: ['OwnerDashboardKpiService::metrics', 'RmeControlReceivableService']),
            self::metric('receivable_count', ['Jumlah Piutang', 'unpaid_invoices'], 'cashier', 'RmeInvoice', ['Receivable', 'RME Invoice'], ['trx_rme_invoices'], 'derived', 'COUNT invoices UNPAID/PARTIAL with remaining>0', 'per_branch_snapshot', ['branch', 'status'], 'financial', 'duplicate', 'ready', ['rme.cashier.receivables', 'dashboard'], ['OwnerKpiDashboardTest']),
            self::metric('overdue_receivable_count', ['Piutang Jatuh Tempo'], 'cashier', 'RmeInvoice', ['Receivable', 'Receivable Follow-up'], ['trx_rme_invoices', 'trx_rme_receivable_follow_ups'], 'computed', 'Receivables with follow_up_filter=overdue on receivables index', 'per_branch_snapshot', ['branch', 'date'], 'financial', 'needs_review', 'needs_review', ['rme.cashier.receivables'], [], 'Overlaps follow_up_due_count — unify in DMO-1'),
            self::metric('partial_invoice_count', ['Invoice Sebagian'], 'cashier', 'RmeInvoice', ['RME Invoice'], ['trx_rme_invoices'], 'source_of_truth', 'COUNT WHERE status = PARTIAL', 'per_branch_snapshot', ['branch', 'status'], 'financial', 'canonical', 'ready', ['rme.cashier.receivables'], ['RmePaymentTest']),
            self::metric('paid_invoice_count', ['Invoice Lunas'], 'cashier', 'RmeInvoice', ['RME Invoice'], ['trx_rme_invoices'], 'source_of_truth', 'COUNT WHERE status = PAID', 'per_branch_per_date_range', ['branch', 'date', 'status'], 'financial', 'canonical', 'ready', ['rme.cashier.index'], ['RmePaymentTest']),
            self::metric('unpaid_invoice_count', ['Invoice Belum Lunas'], 'cashier', 'RmeInvoice', ['RME Invoice'], ['trx_rme_invoices'], 'source_of_truth', 'COUNT WHERE status = UNPAID', 'per_branch_snapshot', ['branch', 'status'], 'financial', 'canonical', 'ready', ['rme.cashier.receivables'], ['RmePaymentTest']),
            self::metric('payment_by_method', ['Pembayaran per Metode'], 'cashier', 'RmePayment', ['RME Payment', 'Payment Method'], ['trx_rme_payments', 'mst_payment_methods'], 'computed', 'GROUP BY payment_method_id SUM(amount)', 'per_branch_per_date_range', ['branch', 'date', 'payment_method'], 'financial', 'canonical', 'ready', ['rme.cashier.index'], ['RmePaymentTest']),
            self::metric('payment_trend', ['Tren Pembayaran'], 'cashier', 'RmePayment', ['RME Payment'], ['trx_rme_payments'], 'computed', 'Daily SUM(amount) grouped by paid_at date (PHP portable)', 'per_branch_per_day', ['branch', 'date'], 'financial', 'canonical', 'ready', ['dashboard'], ['OwnerKpiDashboardTest'], consumers: ['OwnerDashboardKpiService::dailyPaymentTrend']),
            self::metric('receivable_aging_bucket', ['Aging Piutang'], 'cashier', 'RmeInvoice', ['Receivable', 'RME Invoice'], ['trx_rme_invoices'], 'computed', 'Bucket by days since invoice/visit (report-specific)', 'per_branch_snapshot', ['branch', 'aging_bucket'], 'financial', 'needs_review', 'needs_review', ['rme.cashier.receivables'], [], 'No single canonical aging table; DMO-1'),
            self::metric('carry_over_allocation', ['Alokasi Piutang Carry-over'], 'cashier', 'RmePayment', ['RME Payment', 'RME Invoice'], ['trx_rme_payments'], 'source_of_truth', 'Payments linked via payment_batch_uuid across invoices', 'per_payment_batch', ['branch', 'patient', 'visit'], 'financial', 'canonical', 'ready', ['rme.cashier.payment.create'], ['PatientOutstandingReceivableCarryOverTest'], consumers: ['RmePaymentService::allocateVisitPayment']),
            self::metric('collection_rate', ['Tingkat Penagihan'], 'cashier', 'RmePayment', ['RME Payment', 'RME Invoice'], ['trx_rme_payments', 'trx_rme_invoices'], 'computed', 'paid_amount / billable_grand_total * 100 in period', 'per_branch_per_date_range', ['branch', 'date'], 'financial', 'canonical', 'ready', ['dashboard'], ['OwnerKpiDashboardTest'], consumers: ['OwnerDashboardKpiService::metrics']),

            // Inventory
            self::metric('current_stock_qty', ['Stok Saat Ini'], 'inventory', 'Inventory', ['Inventory Movement', 'Inventory Product'], ['trx_inventory_movements'], 'derived', 'SUM(quantity_in) - SUM(quantity_out) per product/location/batch', 'per_product_branch_location', ['branch', 'product', 'location', 'batch'], 'internal', 'canonical', 'ready', ['inventory.reports.current-stock', 'inventory.dashboard'], ['InventoryReportTest', 'InventoryDashboardTest'], consumers: ['InventoryStockService', 'InventoryReportService']),
            self::metric('stock_value', ['Nilai Persediaan', 'stock_value'], 'inventory', 'Inventory', ['Inventory Movement', 'Inventory Product'], ['trx_inventory_movements', 'inv_products'], 'derived', 'derived_stock * average_cost aggregated', 'per_branch_snapshot', ['branch', 'location', 'category'], 'financial', 'canonical', 'ready', ['inventory.analytics.index', 'inventory.reports.valuation', 'dashboard'], ['InventoryReportTest', 'OwnerKpiDashboardTest'], consumers: ['InventoryAnalyticsRepositoryInterface::getInventoryValue', 'OwnerDashboardKpiService::inventorySnapshot']),
            self::metric('low_stock_count', ['Stok Rendah', 'low_stock_items'], 'inventory', 'Inventory', ['Inventory Product', 'Inventory Movement', 'Location Product Minimum'], ['inv_products', 'trx_inventory_movements', 'inv_location_product_minimums'], 'computed', 'Products at/below reorder_point or minimum_stock', 'per_branch_snapshot', ['branch', 'location', 'product'], 'internal', 'duplicate', 'ready', ['inventory.alerts.index', 'dashboard'], ['InventoryAlertTest', 'OwnerKpiDashboardTest'], 'Owner KPI uses low_stock_items key', consumers: ['InventoryAnalyticsRepositoryInterface::getLowStockCount', 'InventoryAlertService']),
            self::metric('out_of_stock_count', ['Stok Habis'], 'inventory', 'Inventory', ['Inventory Movement', 'Inventory Product'], ['trx_inventory_movements'], 'computed', 'Products with derived stock <= 0 and alert_enabled', 'per_branch_snapshot', ['branch', 'product', 'location'], 'internal', 'canonical', 'ready', ['inventory.alerts.index'], ['InventoryAlertTest'], consumers: ['InventoryAlertService::classifyStockSeverity']),
            self::metric('reorder_alert_count', ['Alert Reorder'], 'inventory', 'Inventory', ['Inventory Product'], ['inv_products'], 'computed', 'COUNT products at reorder_point severity', 'per_branch_snapshot', ['branch', 'product'], 'internal', 'canonical', 'ready', ['inventory.alerts.index'], ['InventoryAlertTest']),
            self::metric('inventory_movement_qty', ['Mutasi Stok'], 'inventory', 'Inventory', ['Inventory Movement'], ['trx_inventory_movements'], 'source_of_truth', 'SUM(quantity_in + quantity_out) or net by type', 'per_branch_per_date_range', ['branch', 'date', 'movement_type', 'product'], 'internal', 'canonical', 'ready', ['inventory.reports.mutation'], ['InventoryReportTest']),
            self::metric('inventory_in_qty', ['Stok Masuk'], 'inventory', 'Inventory', ['Inventory Movement'], ['trx_inventory_movements'], 'source_of_truth', 'SUM(quantity_in)', 'per_branch_per_date_range', ['branch', 'date', 'movement_type'], 'internal', 'canonical', 'ready', ['inventory.reports.mutation'], ['InventoryReportTest']),
            self::metric('inventory_out_qty', ['Stok Keluar'], 'inventory', 'Inventory', ['Inventory Movement'], ['trx_inventory_movements'], 'source_of_truth', 'SUM(quantity_out)', 'per_branch_per_date_range', ['branch', 'date', 'movement_type'], 'internal', 'canonical', 'ready', ['inventory.reports.mutation'], ['InventoryReportTest']),
            self::metric('purchase_request_count', ['Purchase Request'], 'inventory', 'Inventory', ['Purchase Request'], ['trx_purchase_requests'], 'source_of_truth', 'COUNT by status filter', 'per_branch_snapshot', ['branch', 'status', 'date'], 'internal', 'canonical', 'ready', ['inventory.purchase-requests.index'], ['InventoryPermissionHardeningTest'], consumers: ['InventoryAnalyticsRepositoryInterface::getOpenPurchaseRequestCount']),
            self::metric('purchase_order_count', ['Purchase Order'], 'inventory', 'Inventory', ['Purchase Order'], ['trx_purchase_orders'], 'source_of_truth', 'COUNT by status filter', 'per_branch_snapshot', ['branch', 'status', 'date'], 'internal', 'canonical', 'ready', ['inventory.purchase-orders.index'], [], consumers: ['InventoryAnalyticsRepositoryInterface::getOpenPurchaseOrderCount']),
            self::metric('goods_receipt_count', ['Penerimaan Barang'], 'inventory', 'Inventory', ['Goods Receipt'], ['trx_goods_receipts'], 'source_of_truth', 'COUNT by status', 'per_branch_per_date_range', ['branch', 'status', 'date'], 'internal', 'canonical', 'ready', ['inventory.goods-receipts.index'], ['GoodsReceiptTest'], consumers: ['InventoryAnalyticsRepositoryInterface::getPendingGoodsReceiptCount']),
            self::metric('stock_transfer_count', ['Transfer Stok'], 'inventory', 'Inventory', ['Stock Transfer'], ['trx_stock_transfers'], 'source_of_truth', 'COUNT by status', 'per_branch_per_date_range', ['branch', 'status'], 'internal', 'canonical', 'ready', ['inventory.stock-transfers.index'], ['StockTransferTest'], consumers: ['InventoryAnalyticsRepositoryInterface::getInTransitTransferCount']),
            self::metric('stock_opname_count', ['Stock Opname'], 'inventory', 'Inventory', ['Stock Opname'], ['trx_stock_opnames'], 'source_of_truth', 'COUNT by status', 'per_branch_per_date_range', ['branch', 'status'], 'internal', 'canonical', 'ready', ['inventory.stock-opnames.index'], ['StockOpnameTest']),
            self::metric('variance_qty', ['Selisih Opname'], 'inventory', 'Inventory', ['Stock Opname Item', 'Inventory Movement'], ['trx_stock_opname_items', 'trx_inventory_movements'], 'derived', 'physical_count - system_stock on opname finalize', 'per_opname_item', ['branch', 'product', 'location', 'batch'], 'internal', 'canonical', 'ready', ['inventory.stock-opnames.show'], ['StockOpnameTest']),
            self::metric('batch_count', ['Jumlah Batch'], 'inventory', 'Inventory', ['Inventory Batch'], ['inv_inventory_batches'], 'source_of_truth', 'COUNT active batches', 'per_branch_snapshot', ['branch', 'product'], 'internal', 'canonical', 'ready', ['inventory.batches.index'], ['InventoryBatchTest']),
            self::metric('expiring_batch_count', ['Batch Segera Kedaluwarsa'], 'inventory', 'Inventory', ['Inventory Batch'], ['inv_inventory_batches'], 'computed', 'Batches with expiry within near-expiry window and stock>0', 'per_branch_snapshot', ['branch', 'expiry_date'], 'internal', 'canonical', 'ready', ['inventory.batches.index', 'inventory.alerts.index'], ['InventoryBatchTest'], consumers: ['BatchExpiryStatusService', 'InventoryBatchRepository']),
            self::metric('expired_batch_count', ['Batch Kedaluwarsa'], 'inventory', 'Inventory', ['Inventory Batch'], ['inv_inventory_batches'], 'computed', 'Batches where expiry_date < today and stock>0', 'per_branch_snapshot', ['branch', 'expiry_date'], 'internal', 'canonical', 'ready', ['inventory.batches.index', 'inventory.alerts.index'], ['InventoryAlertTest'], consumers: ['InventoryAlertService::classifyBatchSeverity']),
            self::metric('expiry_alert_count', ['Alert Kedaluwarsa'], 'inventory', 'Inventory', ['Inventory Batch'], ['inv_inventory_batches'], 'computed', 'COUNT batch severity expired + expiring_soon alerts', 'per_branch_snapshot', ['branch', 'expiry_date'], 'internal', 'needs_review', 'needs_review', ['inventory.alerts.index'], ['InventoryAlertTest'], 'DMO-004: computed not persisted', consumers: ['InventoryAlertService']),
            self::metric('room_stock_refill_count', ['Refill Ruangan'], 'inventory', 'Inventory', ['Inventory Movement', 'Inventory Location'], ['trx_inventory_movements', 'inv_inventory_locations'], 'computed', 'Room-stock refill checklist rows below minimum', 'per_branch_snapshot', ['branch', 'location', 'room'], 'internal', 'canonical', 'ready', ['inventory.reports.room-stock.refill-checklist'], ['InventoryReportTest']),

            // Lab / Production
            self::metric('lab_order_count', ['Order Lab'], 'lab', 'LabOrder', ['Lab Order'], ['trx_lab_orders'], 'source_of_truth', 'COUNT(trx_lab_orders.id) by status', 'per_branch_per_date_range', ['branch', 'status', 'date'], 'internal', 'canonical', 'ready', ['lab-orders.index'], ['LabOrderTest']),
            self::metric('lab_order_status_count', ['Order Lab per Status'], 'lab', 'LabOrder', ['Lab Order'], ['trx_lab_orders'], 'computed', 'GROUP BY status COUNT', 'per_branch_snapshot', ['branch', 'status'], 'internal', 'canonical', 'ready', ['lab-orders.index'], ['LabOrderTest']),
            self::metric('lab_orders_active', ['Order Lab Aktif', 'lab_orders_active'], 'lab', 'LabOrder', ['Lab Order'], ['trx_lab_orders'], 'computed', 'COUNT WHERE status NOT IN delivered, completed, cancelled', 'global_snapshot', ['status'], 'internal', 'canonical', 'ready', ['lab-orders.index', 'dashboard'], ['OwnerKpiDashboardTest'], 'Lab global not branch-grouped per Sprint 23.5', consumers: ['OwnerDashboardKpiService::metrics']),
            self::metric('production_pending_count', ['Produksi Pending'], 'lab', 'LabOrder', ['Lab Order', 'Production Assignment'], ['trx_lab_orders', 'trx_lab_order_assignments'], 'computed', 'Orders in IN_PRODUCTION or ASSIGNED without QC pass', 'per_branch_snapshot', ['branch', 'status'], 'internal', 'needs_review', 'needs_review', ['production.index'], [], 'Status mapping needs DMO alignment'),
            self::metric('qc_pass_count', ['QC Lulus'], 'lab', 'LabOrder', ['Quality Control'], ['trx_lab_quality_controls'], 'source_of_truth', 'COUNT QC records result=pass', 'per_branch_per_date_range', ['branch', 'date'], 'internal', 'needs_review', 'needs_review', ['quality-control.index'], [], 'DMO-002 QC entity naming'),
            self::metric('qc_fail_count', ['QC Gagal'], 'lab', 'LabOrder', ['Quality Control'], ['trx_lab_quality_controls'], 'source_of_truth', 'COUNT QC records result=fail', 'per_branch_per_date_range', ['branch', 'date'], 'internal', 'needs_review', 'needs_review', ['quality-control.index'], []),
            self::metric('delivery_count', ['Pengiriman'], 'lab', 'LabOrder', ['Delivery'], ['trx_lab_deliveries'], 'source_of_truth', 'COUNT deliveries by status/date', 'per_branch_per_date_range', ['branch', 'date', 'status'], 'internal', 'canonical', 'ready', ['deliveries.index'], []),
            self::metric('pod_count', ['POD'], 'lab', 'LabOrder', ['Delivery'], ['trx_lab_deliveries'], 'computed', 'Proof-of-delivery confirmations if captured on delivery row', 'per_branch_per_date_range', ['branch', 'date'], 'internal', 'needs_review', 'blocked', [], [], 'POD field not standardized for metrics yet'),

            // Owner / Dashboard
            self::metric('owner_total_revenue', ['Pendapatan Owner'], 'owner', 'Reporting', ['RME Payment'], ['trx_rme_payments'], 'derived', 'Alias of paid_amount across active branches for period', 'per_branch_per_date_range', ['branch', 'date'], 'financial', 'duplicate', 'ready', ['dashboard'], ['OwnerKpiDashboardTest'], consumers: ['OwnerDashboardKpiService::metrics (total_revenue)']),
            self::metric('owner_receivable_total', ['Piutang Owner'], 'owner', 'Reporting', ['Receivable', 'RME Invoice'], ['trx_rme_invoices', 'trx_rme_payments'], 'derived', 'Alias remaining_receivable / active_receivable', 'per_branch_snapshot', ['branch'], 'financial', 'duplicate', 'ready', ['dashboard', 'rme.cashier.receivables'], ['OwnerKpiDashboardTest']),
            self::metric('owner_visit_count', ['Kunjungan Owner'], 'owner', 'Reporting', ['Clinic Visit'], ['trx_clinic_visits'], 'derived', 'Alias total_visits for owner period', 'per_branch_per_date_range', ['branch', 'date'], 'PII', 'duplicate', 'ready', ['dashboard'], ['OwnerKpiDashboardTest']),
            self::metric('owner_patient_count', ['Pasien Baru Owner'], 'owner', 'Reporting', ['Patient'], ['mst_patients'], 'derived', 'Alias new_patients for owner period', 'per_branch_per_date_range', ['branch', 'date'], 'PII', 'duplicate', 'ready', ['dashboard'], ['OwnerKpiDashboardTest']),
            self::metric('owner_inventory_value', ['Nilai Inventory Owner'], 'owner', 'Reporting', ['Inventory Movement'], ['trx_inventory_movements'], 'derived', 'SUM getInventoryValue per active branch', 'per_branch_snapshot', ['branch'], 'financial', 'duplicate', 'ready', ['dashboard', 'inventory.analytics.index'], ['OwnerKpiDashboardTest']),
            self::metric('owner_low_stock_count', ['Stok Rendah Owner'], 'owner', 'Reporting', ['Inventory Product'], ['inv_products'], 'derived', 'SUM getLowStockCount per active branch', 'per_branch_snapshot', ['branch'], 'internal', 'duplicate', 'ready', ['dashboard', 'inventory.alerts.index'], ['OwnerKpiDashboardTest']),
            self::metric('owner_follow_up_count', ['Follow-up Owner'], 'owner', 'Reporting', ['Receivable Follow-up'], ['trx_rme_receivable_follow_ups'], 'derived', 'Alias follow_up_due_count', 'per_branch_snapshot', ['branch'], 'financial', 'duplicate', 'ready', ['dashboard'], ['OwnerKpiDashboardTest']),
            self::metric('visit_trend', ['Tren Kunjungan'], 'owner', 'Reporting', ['Clinic Visit'], ['trx_clinic_visits'], 'computed', 'Daily visit counts capped 31 days', 'per_branch_per_day', ['branch', 'date'], 'PII', 'canonical', 'ready', ['dashboard'], ['OwnerKpiDashboardTest'], consumers: ['OwnerDashboardKpiService::dailyVisitTrend']),
            self::metric('branch_comparison_metrics', ['Perbandingan Cabang'], 'owner', 'Reporting', ['Clinic Visit', 'RME Payment', 'RME Invoice', 'Patient'], ['trx_clinic_visits', 'trx_rme_payments', 'trx_rme_invoices', 'mst_patients'], 'computed', 'Per-branch table: visits, patients, revenue, receivable, follow_up', 'per_branch_per_date_range', ['branch', 'date'], 'financial', 'canonical', 'ready', ['dashboard'], ['OwnerKpiDashboardTest'], consumers: ['OwnerDashboardKpiService::branchPerformance']),

            // System / Monitoring
            self::metric('slow_query_count', ['Slow Query Count'], 'system', 'Telemetry', [], [], 'telemetry', 'COUNT flagged queries from performance:slow-query-audit', 'per_environment_snapshot', ['module', 'table'], 'telemetry', 'canonical', 'ready', [], ['SlowQueryAuditCommandTest'], consumers: ['SlowQueryAuditCommand']),
            self::metric('runtime_query_total_time', ['Runtime Query Total Time'], 'system', 'Telemetry', [], [], 'telemetry', 'SUM total_time from pg_stat_statements snapshot', 'per_environment_snapshot', ['query_pattern'], 'telemetry', 'canonical', 'ready', [], ['RuntimeQueryObservabilityCommandTest'], consumers: ['PerformanceRuntimeQueryObservabilityCommand']),
            self::metric('runtime_query_mean_time', ['Runtime Query Mean Time'], 'system', 'Telemetry', [], [], 'telemetry', 'mean_time per query from pg_stat_statements', 'per_query', ['query_pattern'], 'telemetry', 'canonical', 'ready', [], ['RuntimeQueryObservabilityCommandTest']),
            self::metric('pg_stat_top_query', ['Top pg_stat Query'], 'system', 'Telemetry', [], [], 'telemetry', 'Top N by total_time from pg_stat_statements', 'per_query_rank', ['sort', 'limit'], 'telemetry', 'canonical', 'ready', [], ['RuntimeQueryObservabilityCommandTest']),
            self::metric('audit_evidence_count', ['Audit Evidence Count'], 'system', 'Telemetry', ['Audit Log'], ['sys_audit_logs'], 'telemetry', 'COUNT audit rows or evidence JSON files (command-scoped)', 'per_environment_snapshot', ['branch', 'date'], 'internal', 'needs_review', 'needs_review', [], [], 'Evidence files under storage/app/architecture and storage/app/performance'),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function conflicts(): array
    {
        return [
            ['metrics' => ['remaining_receivable', 'owner_receivable_total', 'active_receivable'], 'issue' => 'Same derived formula, different display keys', 'resolution' => 'DMO-1 canonical name: remaining_receivable'],
            ['metrics' => ['paid_amount', 'owner_total_revenue', 'total_revenue'], 'issue' => 'Owner KPI total_revenue equals paid_amount SUM', 'resolution' => 'Document gross_revenue separately when invoiced vs collected'],
            ['metrics' => ['low_stock_count', 'owner_low_stock_count', 'low_stock_items'], 'issue' => 'low_stock_items is Owner KPI array key', 'resolution' => 'Unify to low_stock_count in DMO-1'],
            ['metrics' => ['follow_up_due_count', 'overdue_receivable_count', 'owner_follow_up_count'], 'issue' => 'Overlapping receivable follow-up overdue semantics', 'resolution' => 'Single follow_up_due metric with explicit filter rules'],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function gaps(): array
    {
        return [
            ['id' => 'DMO-M001', 'area' => 'cashier', 'gap' => 'net_revenue not materialized; pilot uses paid_amount as revenue KPI', 'severity' => 'medium'],
            ['id' => 'DMO-M002', 'area' => 'rme', 'gap' => 'active_patients and unique_patients lack single canonical definition', 'severity' => 'medium'],
            ['id' => 'DMO-M003', 'area' => 'cashier', 'gap' => 'receivable_aging_bucket has no persisted aging table', 'severity' => 'medium'],
            ['id' => 'DMO-M004', 'area' => 'inventory', 'gap' => 'expiry_alert_count is computed at read time (DMO-004 entity gap)', 'severity' => 'low'],
            ['id' => 'DMO-M005', 'area' => 'owner', 'gap' => 'Owner KPI aliases duplicate domain metrics (DMO-005)', 'severity' => 'medium'],
            ['id' => 'DMO-M006', 'area' => 'foundation', 'gap' => 'Treatment/tariff metrics need multi-branch price boundary (DMO-006)', 'severity' => 'medium'],
            ['id' => 'DMO-M007', 'area' => 'lab', 'gap' => 'pod_count and production_pending_count need standardized status fields', 'severity' => 'low'],
        ];
    }

    /**
     * @return list<string>
     */
    public static function dmoRecommendations(): array
    {
        return [
            'Use canonical_metric_name from this registry as DMO-1 metric ontology seed',
            'Treat remaining_receivable as canonical derived metric; deprecate active_receivable display key gradually',
            'Owner dashboard metrics must reference domain canonical names, not duplicate aliases',
            'Inventory stock metrics must always trace to trx_inventory_movements ledger',
            'Never emit PHI/PII sample values in metric command output or telemetry JSON',
            'Resolve net_revenue vs paid_amount before financial reporting DMO pack',
            'Unify date grain (visit_date vs created_at vs paid_at) per metric in DMO-1',
        ];
    }

    /**
     * @param  list<string>  $displayLabels
     * @param  list<string>  $sourceEntities
     * @param  list<string>  $sourceTables
     * @param  list<string>  $dimensions
     * @param  list<string>  $sensitivity
     * @param  list<string>  $routes
     * @param  list<string>  $tests
     * @param  list<string>  $consumers
     */
    private static function metric(
        string $canonicalName,
        array $displayLabels,
        string $domain,
        string $ownerModule,
        array $sourceEntities,
        array $sourceTables,
        string $sourceType,
        string $formulaCurrent,
        string $grain,
        array $dimensions,
        string|array $sensitivity,
        string $conflictStatus,
        string $dmoReadiness,
        array $routes = [],
        array $tests = [],
        ?string $reconciliationNotes = null,
        array $consumers = [],
    ): array {
        $sens = is_array($sensitivity) ? $sensitivity : [$sensitivity];

        return [
            'canonical_metric_name' => $canonicalName,
            'display_labels_current' => $displayLabels,
            'domain' => $domain,
            'owner_module' => $ownerModule,
            'source_entities' => $sourceEntities,
            'source_tables' => $sourceTables,
            'source_type' => $sourceType,
            'formula_current' => $formulaCurrent,
            'canonical_formula_candidate' => $formulaCurrent,
            'grain' => $grain,
            'dimensions' => $dimensions,
            'filters' => [
                'branch' => 'BranchContext::requireId() for operator views; Owner KPI may aggregate active branches',
                'date' => 'Explicit per metric: visit_date | created_at | paid_at | movement_date | expiry_date',
                'status' => 'Domain status enums exclude cancelled/VOID where noted',
                'soft_delete' => 'deleted_at IS NULL when model uses SoftDeletes',
                'active' => 'is_active flags on products/locations/branches where applicable',
            ],
            'consumers' => [
                'routes' => $routes,
                'controllers' => [],
                'reports' => [],
                'dashboards' => array_values(array_filter($routes, fn (string $r) => str_contains($r, 'dashboard') || str_contains($r, 'reports'))),
                'exports' => [],
                'services' => $consumers,
            ],
            'sensitivity' => $sens,
            'conflict_status' => $conflictStatus,
            'reconciliation_notes' => $reconciliationNotes !== null ? [$reconciliationNotes] : [],
            'dmo_readiness' => $dmoReadiness,
            'tests' => $tests,
        ];
    }
}
