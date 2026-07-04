<?php

use App\Modules\ClinicVisit\Services\ClinicVisitService;
use App\Modules\Inventory\Services\GoodsReceiptService;
use App\Modules\Inventory\Services\InventoryStockService;
use App\Modules\Inventory\Services\PurchaseOrderService;
use App\Modules\Inventory\Services\PurchaseRequestService;
use App\Modules\Inventory\Services\StockOpnameService;
use App\Modules\Inventory\Services\StockTransferService;
use App\Modules\LabOrder\Services\LabCaseCandidateConversionService;
use App\Modules\LabOrder\Services\LabOrderService;
use App\Modules\MedicalRecord\Services\MedicalRecordService;
use App\Modules\Odontogram\Services\OdontogramService;
use App\Modules\Patient\Services\LegacyPatientImportService;
use App\Modules\RmeInvoice\Services\RmeInvoiceService;
use App\Modules\RmeInvoice\Services\RmeLabIntegrationService;
use App\Modules\RmeInvoice\Services\RmePaymentService;

/**
 * DQ-1 ACID, constraint, and data quality audit registry.
 * Validated by data-quality:dq1-audit (read-only).
 */
return [
    'version' => 'DQ-1',
    'sprint' => 'DQ-1',

    'acid_services' => [
        'DQ1-ACID-001' => [
            'title' => 'Critical multi-write services use DB::transaction',
            'severity' => 'error',
            'services' => [
                ClinicVisitService::class,
                MedicalRecordService::class,
                OdontogramService::class,
                RmeInvoiceService::class,
                LabCaseCandidateConversionService::class,
                LabOrderService::class,
            ],
            'documented_exceptions' => [
                RmeLabIntegrationService::class => 'Post-commit idempotent firstOrCreate; not a multi-write transaction boundary.',
            ],
        ],
        'DQ1-ACID-002' => [
            'title' => 'Payment and receivable operations are transactional',
            'severity' => 'error',
            'services' => [
                RmePaymentService::class,
                RmeInvoiceService::class,
            ],
        ],
        'DQ1-ACID-003' => [
            'title' => 'Inventory movement-producing flows are transactional',
            'severity' => 'error',
            'services' => [
                InventoryStockService::class,
                GoodsReceiptService::class,
                StockTransferService::class,
                StockOpnameService::class,
                PurchaseOrderService::class,
                PurchaseRequestService::class,
            ],
        ],
        'DQ1-ACID-004' => [
            'title' => 'Import and commit flows are transactional',
            'severity' => 'error',
            'services' => [
                LegacyPatientImportService::class,
            ],
        ],
    ],

    'constraint_checks' => [
        'DQ1-CONSTRAINT-001' => [
            'title' => 'Critical foreign key constraints present',
            'severity' => 'error',
            'foreign_keys' => [
                ['table' => 'trx_rme_invoices', 'column' => 'branch_id', 'references' => 'mst_branches'],
                ['table' => 'trx_rme_invoices', 'column' => 'clinic_visit_id', 'references' => 'trx_clinic_visits'],
                ['table' => 'trx_rme_payments', 'column' => 'rme_invoice_id', 'references' => 'trx_rme_invoices'],
                ['table' => 'trx_medical_records', 'column' => 'clinic_visit_id', 'references' => 'trx_clinic_visits'],
                ['table' => 'trx_inventory_movements', 'column' => 'branch_id', 'references' => 'mst_branches'],
                ['table' => 'trx_inventory_movements', 'column' => 'product_id', 'references' => 'inv_products'],
            ],
        ],
        'DQ1-CONSTRAINT-002' => [
            'title' => 'Unique medical record number guard exists',
            'severity' => 'error',
            'table' => 'mst_patients',
            'column' => 'medical_record_number',
        ],
        'DQ1-CONSTRAINT-003' => [
            'title' => 'Unique KTP guard exists (nullable unique)',
            'severity' => 'warning',
            'table' => 'mst_patients',
            'column' => 'ktp_number',
            'app_level_note' => 'KTP uniqueness enforced at DB nullable-unique and PatientService validation.',
        ],
        'DQ1-CONSTRAINT-004' => [
            'title' => 'Non-negative monetary fields guarded',
            'severity' => 'error',
            'tables' => [
                ['table' => 'trx_rme_invoices', 'columns' => ['subtotal', 'discount_total', 'grand_total']],
                ['table' => 'trx_rme_payments', 'columns' => ['amount']],
            ],
        ],
        'DQ1-CONSTRAINT-005' => [
            'title' => 'Inventory quantity direction guarded at application level',
            'severity' => 'warning',
            'table' => 'trx_inventory_movements',
            'note' => 'Ledger rule: quantity_in and quantity_out are non-negative; not both positive.',
        ],
        'DQ1-CONSTRAINT-006' => [
            'title' => 'Patient-centric RM visit uniqueness guarded',
            'severity' => 'error',
            'table' => 'trx_medical_records',
            'column' => 'clinic_visit_id',
        ],
    ],

    'branch_required_tables' => [
        'trx_clinic_visits',
        'trx_rme_invoices',
        'trx_rme_payments',
        'trx_inventory_movements',
    ],

    'rme_invoice_statuses' => ['DRAFT', 'UNPAID', 'PARTIAL', 'PAID', 'VOID'],

    'documentation' => [
        'primary' => 'docs/architecture/dq-1-acid-constraint-data-quality-audit.md',
        'deploy_gates' => 'docs/architecture/nsf-governance-deploy-gates.md',
    ],
];
