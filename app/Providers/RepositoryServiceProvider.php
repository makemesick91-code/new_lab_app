<?php

namespace App\Providers;

use App\Models\User;
use App\Modules\AccessControl\Interfaces\PermissionRepositoryInterface;
use App\Modules\AccessControl\Interfaces\RoleRepositoryInterface;
use App\Modules\AccessControl\Policies\RolePolicy;
use App\Modules\AccessControl\Repositories\PermissionRepository;
use App\Modules\AccessControl\Repositories\RoleRepository;
use App\Modules\Branch\Interfaces\BranchRepositoryInterface;
use App\Modules\Branch\Models\Branch;
use App\Modules\Branch\Policies\BranchPolicy;
use App\Modules\Branch\Repositories\BranchRepository;
use App\Modules\Clinic\Interfaces\ClinicRepositoryInterface;
use App\Modules\Clinic\Models\Clinic;
use App\Modules\Clinic\Policies\ClinicPolicy;
use App\Modules\Clinic\Repositories\ClinicRepository;
use App\Modules\ClinicRoom\Interfaces\ClinicRoomRepositoryInterface;
use App\Modules\ClinicRoom\Models\ClinicRoom;
use App\Modules\ClinicRoom\Policies\ClinicRoomPolicy;
use App\Modules\ClinicRoom\Repositories\ClinicRoomRepository;
use App\Modules\ClinicVisit\Interfaces\ClinicVisitRepositoryInterface;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\ClinicVisit\Policies\ClinicVisitPolicy;
use App\Modules\ClinicVisit\Repositories\ClinicVisitRepository;
use App\Modules\Consent\Interfaces\RmeVisitConsentRepositoryInterface;
use App\Modules\Consent\Models\RmeVisitConsent;
use App\Modules\Consent\Policies\RmeVisitConsentPolicy;
use App\Modules\Consent\Repositories\RmeVisitConsentRepository;
use App\Modules\Delivery\Interfaces\DeliveryRepositoryInterface;
use App\Modules\Delivery\Models\Delivery;
use App\Modules\Delivery\Policies\DeliveryPolicy;
use App\Modules\Delivery\Repositories\DeliveryRepository;
use App\Modules\Doctor\Interfaces\DoctorRepositoryInterface;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\Doctor\Policies\DoctorPolicy;
use App\Modules\Doctor\Repositories\DoctorRepository;
use App\Modules\Inventory\Interfaces\GoodsReceiptRepositoryInterface;
use App\Modules\Inventory\Interfaces\InventoryActivityLogRepositoryInterface;
use App\Modules\Inventory\Interfaces\InventoryAnalyticsRepositoryInterface;
use App\Modules\Inventory\Interfaces\InventoryBatchActionLogRepositoryInterface;
use App\Modules\Inventory\Interfaces\InventoryBatchDisposalRequestRepositoryInterface;
use App\Modules\Inventory\Interfaces\InventoryBatchRepositoryInterface;
use App\Modules\Inventory\Interfaces\InventoryLocationRepositoryInterface;
use App\Modules\Inventory\Interfaces\InventoryMovementRepositoryInterface;
use App\Modules\Inventory\Interfaces\LocationProductMinimumRepositoryInterface;
use App\Modules\Inventory\Interfaces\ProductCategoryRepositoryInterface;
use App\Modules\Inventory\Interfaces\ProductRepositoryInterface;
use App\Modules\Inventory\Interfaces\ProductUnitRepositoryInterface;
use App\Modules\Inventory\Interfaces\PurchaseOrderRepositoryInterface;
use App\Modules\Inventory\Interfaces\PurchaseRequestRepositoryInterface;
use App\Modules\Inventory\Interfaces\StockOpnameRepositoryInterface;
use App\Modules\Inventory\Interfaces\StockTransferRepositoryInterface;
use App\Modules\Inventory\Interfaces\SupplierRepositoryInterface;
use App\Modules\Inventory\Models\GoodsReceipt;
use App\Modules\Inventory\Models\InventoryActivityLog;
use App\Modules\Inventory\Models\InventoryBatch;
use App\Modules\Inventory\Models\InventoryBatchDisposalRequest;
use App\Modules\Inventory\Models\InventoryLocation;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Models\LocationProductMinimum;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductCategory;
use App\Modules\Inventory\Models\ProductUnit;
use App\Modules\Inventory\Models\PurchaseOrder;
use App\Modules\Inventory\Models\PurchaseRequest;
use App\Modules\Inventory\Models\StockOpname;
use App\Modules\Inventory\Models\StockTransfer;
use App\Modules\Inventory\Models\Supplier;
use App\Modules\Inventory\Policies\GoodsReceiptPolicy;
use App\Modules\Inventory\Policies\InventoryActivityLogPolicy;
use App\Modules\Inventory\Policies\InventoryBatchDisposalRequestPolicy;
use App\Modules\Inventory\Policies\InventoryBatchPolicy;
use App\Modules\Inventory\Policies\InventoryLocationPolicy;
use App\Modules\Inventory\Policies\InventoryMovementPolicy;
use App\Modules\Inventory\Policies\LocationProductMinimumPolicy;
use App\Modules\Inventory\Policies\ProductCategoryPolicy;
use App\Modules\Inventory\Policies\ProductPolicy;
use App\Modules\Inventory\Policies\ProductUnitPolicy;
use App\Modules\Inventory\Policies\PurchaseOrderPolicy;
use App\Modules\Inventory\Policies\PurchaseRequestPolicy;
use App\Modules\Inventory\Policies\StockOpnamePolicy;
use App\Modules\Inventory\Policies\StockTransferPolicy;
use App\Modules\Inventory\Policies\SupplierPolicy;
use App\Modules\Inventory\Repositories\GoodsReceiptRepository;
use App\Modules\Inventory\Repositories\InventoryActivityLogRepository;
use App\Modules\Inventory\Repositories\InventoryAnalyticsRepository;
use App\Modules\Inventory\Repositories\InventoryBatchActionLogRepository;
use App\Modules\Inventory\Repositories\InventoryBatchDisposalRequestRepository;
use App\Modules\Inventory\Repositories\InventoryBatchRepository;
use App\Modules\Inventory\Repositories\InventoryLocationRepository;
use App\Modules\Inventory\Repositories\InventoryMovementRepository;
use App\Modules\Inventory\Repositories\InventorySummaryAnalyticsRepository;
use App\Modules\Inventory\Repositories\LocationProductMinimumRepository;
use App\Modules\Inventory\Repositories\ProductCategoryRepository;
use App\Modules\Inventory\Repositories\ProductRepository;
use App\Modules\Inventory\Repositories\ProductUnitRepository;
use App\Modules\Inventory\Repositories\PurchaseOrderRepository;
use App\Modules\Inventory\Repositories\PurchaseRequestRepository;
use App\Modules\Inventory\Repositories\StockOpnameRepository;
use App\Modules\Inventory\Repositories\StockTransferRepository;
use App\Modules\Inventory\Repositories\SupplierRepository;
use App\Modules\Invoice\Interfaces\InvoiceRepositoryInterface;
use App\Modules\Invoice\Interfaces\PaymentRepositoryInterface;
use App\Modules\Invoice\Models\Invoice;
use App\Modules\Invoice\Models\Payment;
use App\Modules\Invoice\Policies\InvoicePolicy;
use App\Modules\Invoice\Policies\PaymentPolicy;
use App\Modules\Invoice\Repositories\InvoiceRepository;
use App\Modules\Invoice\Repositories\PaymentRepository;
use App\Modules\LabCapacity\Interfaces\LabTechnicianCapacityRepositoryInterface;
use App\Modules\LabCapacity\Repositories\LabTechnicianCapacityRepository;
use App\Modules\LabOrder\Interfaces\AttachmentRepositoryInterface;
use App\Modules\LabOrder\Interfaces\AuditLogRepositoryInterface;
use App\Modules\LabOrder\Interfaces\LabOperationalAnalyticsRepositoryInterface;
use App\Modules\LabOrder\Interfaces\LabOrderRepositoryInterface;
use App\Modules\LabOrder\Interfaces\LabPickupTaskRepositoryInterface;
use App\Modules\LabOrder\Interfaces\StatusLogRepositoryInterface;
use App\Modules\LabOrder\Models\Attachment;
use App\Modules\LabOrder\Models\LabCaseCandidate;
use App\Modules\LabOrder\Models\LabDeliveryTask;
use App\Modules\LabOrder\Models\LabOrder;
use App\Modules\LabOrder\Models\LabPickupTask;
use App\Modules\LabOrder\Models\LabWorkflowEvidence;
use App\Modules\LabOrder\Policies\AttachmentPolicy;
use App\Modules\LabOrder\Policies\LabCaseCandidatePolicy;
use App\Modules\LabOrder\Policies\LabDeliveryTaskPolicy;
use App\Modules\LabOrder\Policies\LabOrderPolicy;
use App\Modules\LabOrder\Policies\LabPickupTaskPolicy;
use App\Modules\LabOrder\Policies\LabWorkflowEvidencePolicy;
use App\Modules\LabOrder\Repositories\AttachmentRepository;
use App\Modules\LabOrder\Repositories\AuditLogRepository;
use App\Modules\LabOrder\Repositories\LabOperationalAnalyticsRepository;
use App\Modules\LabOrder\Repositories\LabOrderRepository;
use App\Modules\LabOrder\Repositories\LabPickupTaskRepository;
use App\Modules\LabOrder\Repositories\StatusLogRepository;
use App\Modules\LabService\Interfaces\LabServiceRepositoryInterface;
use App\Modules\LabService\Models\LabService;
use App\Modules\LabService\Policies\LabServicePolicy;
use App\Modules\LabService\Repositories\LabServiceRepository;
use App\Modules\LegacyOdontogram\Interfaces\LegacyOdontogramImportRepositoryInterface;
use App\Modules\LegacyOdontogram\Interfaces\LegacyOdontogramNativeReferenceRepositoryInterface;
use App\Modules\LegacyOdontogram\Interfaces\LegacyOdontogramRecordRepositoryInterface;
use App\Modules\LegacyOdontogram\Models\LegacyOdontogramImport;
use App\Modules\LegacyOdontogram\Models\LegacyOdontogramRecord;
use App\Modules\LegacyOdontogram\Policies\LegacyOdontogramImportPolicy;
use App\Modules\LegacyOdontogram\Policies\LegacyOdontogramRecordPolicy;
use App\Modules\LegacyOdontogram\Repositories\LegacyOdontogramImportRepository;
use App\Modules\LegacyOdontogram\Repositories\LegacyOdontogramNativeReferenceRepository;
use App\Modules\LegacyOdontogram\Repositories\LegacyOdontogramRecordRepository;
use App\Modules\LegacyOdontogram\Services\LegacyOdontogramAuditService;
use App\Modules\LegacyRme\Interfaces\LegacyRmeImportRepositoryInterface;
use App\Modules\LegacyRme\Interfaces\LegacyRmeMalwareScannerInterface;
use App\Modules\LegacyRme\Interfaces\LegacyRmePdfInspectorInterface;
use App\Modules\LegacyRme\Interfaces\LegacyRmePdfRasterizerInterface;
use App\Modules\LegacyRme\Interfaces\LegacyRmeRecordRepositoryInterface;
use App\Modules\LegacyRme\Models\LegacyRmeImport;
use App\Modules\LegacyRme\Models\LegacyRmeMigrationWave;
use App\Modules\LegacyRme\Models\LegacyRmeRecord;
use App\Modules\LegacyRme\Policies\LegacyRmeImportPolicy;
use App\Modules\LegacyRme\Policies\LegacyRmeMigrationWavePolicy;
use App\Modules\LegacyRme\Policies\LegacyRmeRecordPolicy;
use App\Modules\LegacyRme\Repositories\LegacyRmeImportRepository;
use App\Modules\LegacyRme\Repositories\LegacyRmeRecordRepository;
use App\Modules\LegacyRme\Services\LegacyRmeAuditService;
use App\Modules\LegacyRme\Services\Pdf\ConfiguredLegacyRmeMalwareScanner;
use App\Modules\LegacyRme\Services\Pdf\PopplerLegacyRmePdfInspector;
use App\Modules\LegacyRme\Services\Pdf\PopplerLegacyRmePdfRasterizer;
use App\Modules\MedicalRecord\Interfaces\ClinicalDiagnosisRepositoryInterface;
use App\Modules\MedicalRecord\Interfaces\MedicalRecordHandwritingRepositoryInterface;
use App\Modules\MedicalRecord\Interfaces\MedicalRecordRepositoryInterface;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\MedicalRecord\Policies\MedicalRecordPolicy;
use App\Modules\MedicalRecord\Repositories\ClinicalDiagnosisRepository;
use App\Modules\MedicalRecord\Repositories\MedicalRecordHandwritingRepository;
use App\Modules\MedicalRecord\Repositories\MedicalRecordRepository;
use App\Modules\Odontogram\Interfaces\OdontogramRepositoryInterface;
use App\Modules\Odontogram\Models\Odontogram;
use App\Modules\Odontogram\Policies\OdontogramPolicy;
use App\Modules\Odontogram\Repositories\OdontogramRepository;
use App\Modules\Patient\Interfaces\PatientRepositoryInterface;
use App\Modules\Patient\Models\Patient;
use App\Modules\Patient\Policies\PatientPolicy;
use App\Modules\Patient\Repositories\PatientRepository;
use App\Modules\PaymentMethod\Interfaces\PaymentMethodRepositoryInterface;
use App\Modules\PaymentMethod\Models\PaymentMethod;
use App\Modules\PaymentMethod\Policies\PaymentMethodPolicy;
use App\Modules\PaymentMethod\Repositories\PaymentMethodRepository;
use App\Modules\Prescription\Interfaces\RmePrescriptionRepositoryInterface;
use App\Modules\Prescription\Models\RmePrescription;
use App\Modules\Prescription\Policies\RmePrescriptionPolicy;
use App\Modules\Prescription\Repositories\RmePrescriptionRepository;
use App\Modules\Production\Interfaces\AssignmentRepositoryInterface;
use App\Modules\Production\Interfaces\ProductionStepRepositoryInterface;
use App\Modules\Production\Interfaces\WorkLogRepositoryInterface;
use App\Modules\Production\Policies\AssignmentPolicy;
use App\Modules\Production\Policies\ProductionPolicy;
use App\Modules\Production\Policies\ProductionStepPolicy;
use App\Modules\Production\Policies\WorkLogPolicy;
use App\Modules\Production\Repositories\AssignmentRepository;
use App\Modules\Production\Repositories\ProductionStepRepository;
use App\Modules\Production\Repositories\WorkLogRepository;
use App\Modules\QualityControl\Interfaces\ChecklistRepositoryInterface;
use App\Modules\QualityControl\Interfaces\QualityControlRepositoryInterface;
use App\Modules\QualityControl\Interfaces\RemakeRequestRepositoryInterface;
use App\Modules\QualityControl\Models\QualityControl;
use App\Modules\QualityControl\Policies\ChecklistPolicy;
use App\Modules\QualityControl\Policies\QualityControlPolicy;
use App\Modules\QualityControl\Policies\RemakePolicy;
use App\Modules\QualityControl\Repositories\ChecklistRepository;
use App\Modules\QualityControl\Repositories\QualityControlRepository;
use App\Modules\QualityControl\Repositories\RemakeRequestRepository;
use App\Modules\Reporting\Interfaces\ReportingRepositoryInterface;
use App\Modules\Reporting\Policies\ReportingPolicy;
use App\Modules\Reporting\Repositories\ReportingRepository;
use App\Modules\RmeInvoice\Interfaces\RmeInvoiceRepositoryInterface;
use App\Modules\RmeInvoice\Interfaces\RmePaymentRepositoryInterface;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use App\Modules\RmeInvoice\Policies\RmeInvoicePolicy;
use App\Modules\RmeInvoice\Repositories\RmeInvoiceRepository;
use App\Modules\RmeInvoice\Repositories\RmePaymentRepository;
use App\Modules\RmeOnlineContext\Interfaces\UserOnlineContextRepositoryInterface;
use App\Modules\RmeOnlineContext\Repositories\UserOnlineContextRepository;
use App\Modules\Tariff\Interfaces\TariffRepositoryInterface;
use App\Modules\Tariff\Models\Tariff;
use App\Modules\Tariff\Policies\TariffPolicy;
use App\Modules\Tariff\Repositories\TariffRepository;
use App\Modules\Technician\Interfaces\TechnicianRepositoryInterface;
use App\Modules\Technician\Models\Technician;
use App\Modules\Technician\Policies\TechnicianPolicy;
use App\Modules\Technician\Repositories\TechnicianRepository;
use App\Modules\Treatment\Interfaces\TreatmentRepositoryInterface;
use App\Modules\Treatment\Models\Treatment;
use App\Modules\Treatment\Policies\TreatmentPolicy;
use App\Modules\Treatment\Repositories\TreatmentRepository;
use App\Modules\TreatmentCategory\Interfaces\TreatmentCategoryRepositoryInterface;
use App\Modules\TreatmentCategory\Models\TreatmentCategory;
use App\Modules\TreatmentCategory\Policies\TreatmentCategoryPolicy;
use App\Modules\TreatmentCategory\Repositories\TreatmentCategoryRepository;
use App\Modules\User\Interfaces\UserRepositoryInterface;
use App\Modules\User\Policies\UserPolicy;
use App\Modules\User\Repositories\UserRepository;
use App\Modules\WaReminderTemplate\Interfaces\WaReminderTemplateRepositoryInterface;
use App\Modules\WaReminderTemplate\Models\WaReminderTemplate;
use App\Modules\WaReminderTemplate\Policies\WaReminderTemplatePolicy;
use App\Modules\WaReminderTemplate\Repositories\WaReminderTemplateRepository;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Spatie\Permission\Models\Role;

/**
 * Wires the modular monolith together:
 *  - binds repository interfaces to concrete implementations (ADR-004),
 *  - registers module policies,
 *  - grants Super Admin an implicit bypass for every ability.
 */
class RepositoryServiceProvider extends ServiceProvider
{
    /**
     * @var array<class-string, class-string>
     */
    private array $repositories = [
        UserRepositoryInterface::class => UserRepository::class,
        RoleRepositoryInterface::class => RoleRepository::class,
        PermissionRepositoryInterface::class => PermissionRepository::class,
        ClinicRepositoryInterface::class => ClinicRepository::class,
        // Sprint 19 — Clinic Master Data
        ClinicRoomRepositoryInterface::class => ClinicRoomRepository::class,
        // Sprint 20 — RME: Clinic Visit Queue
        ClinicVisitRepositoryInterface::class => ClinicVisitRepository::class,
        TreatmentCategoryRepositoryInterface::class => TreatmentCategoryRepository::class,
        TreatmentRepositoryInterface::class => TreatmentRepository::class,
        TariffRepositoryInterface::class => TariffRepository::class,
        PaymentMethodRepositoryInterface::class => PaymentMethodRepository::class,
        WaReminderTemplateRepositoryInterface::class => WaReminderTemplateRepository::class,
        DoctorRepositoryInterface::class => DoctorRepository::class,
        PatientRepositoryInterface::class => PatientRepository::class,
        LabServiceRepositoryInterface::class => LabServiceRepository::class,
        TechnicianRepositoryInterface::class => TechnicianRepository::class,
        LabOrderRepositoryInterface::class => LabOrderRepository::class,
        LabOperationalAnalyticsRepositoryInterface::class => LabOperationalAnalyticsRepository::class,
        LabTechnicianCapacityRepositoryInterface::class => LabTechnicianCapacityRepository::class,
        LabPickupTaskRepositoryInterface::class => LabPickupTaskRepository::class,
        AttachmentRepositoryInterface::class => AttachmentRepository::class,
        AuditLogRepositoryInterface::class => AuditLogRepository::class,
        StatusLogRepositoryInterface::class => StatusLogRepository::class,
        AssignmentRepositoryInterface::class => AssignmentRepository::class,
        WorkLogRepositoryInterface::class => WorkLogRepository::class,
        ProductionStepRepositoryInterface::class => ProductionStepRepository::class,
        QualityControlRepositoryInterface::class => QualityControlRepository::class,
        ChecklistRepositoryInterface::class => ChecklistRepository::class,
        RemakeRequestRepositoryInterface::class => RemakeRequestRepository::class,
        DeliveryRepositoryInterface::class => DeliveryRepository::class,
        InvoiceRepositoryInterface::class => InvoiceRepository::class,
        PaymentRepositoryInterface::class => PaymentRepository::class,
        ReportingRepositoryInterface::class => ReportingRepository::class,
        // Sprint 9 — Multi Branch Foundation
        BranchRepositoryInterface::class => BranchRepository::class,
        // Sprint 12 - Inventory Core
        InventoryBatchActionLogRepositoryInterface::class => InventoryBatchActionLogRepository::class,
        InventoryBatchDisposalRequestRepositoryInterface::class => InventoryBatchDisposalRequestRepository::class,
        InventoryBatchRepositoryInterface::class => InventoryBatchRepository::class,
        InventoryLocationRepositoryInterface::class => InventoryLocationRepository::class,
        ProductCategoryRepositoryInterface::class => ProductCategoryRepository::class,
        ProductUnitRepositoryInterface::class => ProductUnitRepository::class,
        ProductRepositoryInterface::class => ProductRepository::class,
        SupplierRepositoryInterface::class => SupplierRepository::class,
        InventoryMovementRepositoryInterface::class => InventoryMovementRepository::class,
        // Sprint 13 - Stock Opname
        StockOpnameRepositoryInterface::class => StockOpnameRepository::class,
        // Sprint 14 - Stock Transfer
        StockTransferRepositoryInterface::class => StockTransferRepository::class,
        // Sprint 16.1 - Purchase Request
        PurchaseRequestRepositoryInterface::class => PurchaseRequestRepository::class,
        // Sprint 16.2 - Purchase Order
        PurchaseOrderRepositoryInterface::class => PurchaseOrderRepository::class,
        // Sprint 16.3 - Goods Receipt
        GoodsReceiptRepositoryInterface::class => GoodsReceiptRepository::class,
        // Sprint 16.6 - Inventory Activity Log
        InventoryActivityLogRepositoryInterface::class => InventoryActivityLogRepository::class,
        // Sprint 17.8 - Minimum Stock per Room
        LocationProductMinimumRepositoryInterface::class => LocationProductMinimumRepository::class,
        // Sprint 20 Phase 1.2.2
        MedicalRecordRepositoryInterface::class => MedicalRecordRepository::class,
        // SATUSEHAT-4A — structured diagnosis master
        ClinicalDiagnosisRepositoryInterface::class => ClinicalDiagnosisRepository::class,
        // Sprint 20 Phase 1.3.1
        OdontogramRepositoryInterface::class => OdontogramRepository::class,
        RmePrescriptionRepositoryInterface::class => RmePrescriptionRepository::class,
        // FIX-RME-CONSENT-WORKFLOW-PRINT-UX-2 / FIX-01 — signed consent as the payment gate
        RmeVisitConsentRepositoryInterface::class => RmeVisitConsentRepository::class,
        // Sprint 66.0 — RME online context
        UserOnlineContextRepositoryInterface::class => UserOnlineContextRepository::class,
        // Sprint 20 Phase 1.8
        MedicalRecordHandwritingRepositoryInterface::class => MedicalRecordHandwritingRepository::class,
        // Sprint 20 Phase 1.10 — RME Cashier Billing
        RmeInvoiceRepositoryInterface::class => RmeInvoiceRepository::class,
        // Sprint 20 Phase 1.11 — RME Payment
        RmePaymentRepositoryInterface::class => RmePaymentRepository::class,
        // LEGACY-RME-PDF-1A — legacy (historical) RME PDF archive
        LegacyRmeImportRepositoryInterface::class => LegacyRmeImportRepository::class,
        LegacyRmeRecordRepositoryInterface::class => LegacyRmeRecordRepository::class,

        // FIX-04b — legacy ODONTOGRAM chart archive. Its own repositories,
        // deliberately not shared with the legacy RME archive above.
        LegacyOdontogramImportRepositoryInterface::class => LegacyOdontogramImportRepository::class,
        LegacyOdontogramRecordRepositoryInterface::class => LegacyOdontogramRecordRepository::class,
        LegacyOdontogramNativeReferenceRepositoryInterface::class => LegacyOdontogramNativeReferenceRepository::class,
        // LEGACY-RME-PDF-1B — PDF inspection, rendering and optional scanning.
        // Bound as interfaces so a test can swap in a deterministic fake and the
        // suite never depends on Poppler being installed.
        LegacyRmePdfInspectorInterface::class => PopplerLegacyRmePdfInspector::class,
        LegacyRmePdfRasterizerInterface::class => PopplerLegacyRmePdfRasterizer::class,
        LegacyRmeMalwareScannerInterface::class => ConfiguredLegacyRmeMalwareScanner::class,
    ];

    /**
     * Production workflow gate abilities (LabOrder already has LabOrderPolicy,
     * so production checks are registered as named gates instead of model policies).
     *
     * @var array<string, array{0: class-string, 1: string}>
     */
    private array $gates = [
        'production.viewAny' => [ProductionPolicy::class, 'viewAny'],
        'production.view' => [ProductionPolicy::class, 'view'],
        'production.start' => [ProductionPolicy::class, 'start'],
        'production.pause' => [ProductionPolicy::class, 'pause'],
        'production.resume' => [ProductionPolicy::class, 'resume'],
        'production.complete' => [ProductionPolicy::class, 'complete'],
        'production.sendToQc' => [ProductionPolicy::class, 'sendToQc'],
        'production.assign' => [AssignmentPolicy::class, 'assign'],
        'production.reassign' => [AssignmentPolicy::class, 'reassign'],
        'production.cancelAssignment' => [AssignmentPolicy::class, 'cancel'],
        'production.steps.update' => [ProductionStepPolicy::class, 'update'],
        'production.worklogs.view' => [WorkLogPolicy::class, 'view'],
        // Sprint 5 — Quality Control
        'qc.viewAny' => [QualityControlPolicy::class, 'viewAny'],
        'qc.view' => [QualityControlPolicy::class, 'view'],
        'qc.start' => [QualityControlPolicy::class, 'start'],
        'qc.pass' => [QualityControlPolicy::class, 'pass'],
        'qc.reject' => [QualityControlPolicy::class, 'reject'],
        'qc.uploadEvidence' => [QualityControlPolicy::class, 'uploadEvidence'],
        'qc.checklists.update' => [ChecklistPolicy::class, 'updateChecklist'],
        'qc.requestRemake' => [RemakePolicy::class, 'requestRemake'],
        // Sprint 6 - Delivery & POD
        'delivery.viewAny' => [DeliveryPolicy::class, 'viewAny'],
        'delivery.view' => [DeliveryPolicy::class, 'view'],
        'delivery.create' => [DeliveryPolicy::class, 'create'],
        'delivery.assignCourier' => [DeliveryPolicy::class, 'assignCourier'],
        'delivery.startDelivery' => [DeliveryPolicy::class, 'startDelivery'],
        'delivery.markDelivered' => [DeliveryPolicy::class, 'markDelivered'],
        'delivery.completeDelivery' => [DeliveryPolicy::class, 'completeDelivery'],
        'delivery.uploadPod' => [DeliveryPolicy::class, 'uploadPod'],
        // Sprint 8 - Reporting & Dashboard
        'reporting.dashboard' => [ReportingPolicy::class, 'viewDashboard'],
        'reporting.orders' => [ReportingPolicy::class, 'viewOrderReport'],
        'reporting.production' => [ReportingPolicy::class, 'viewProductionReport'],
        'reporting.qc' => [ReportingPolicy::class, 'viewQcReport'],
        'reporting.delivery' => [ReportingPolicy::class, 'viewDeliveryReport'],
        'reporting.invoices' => [ReportingPolicy::class, 'viewInvoiceReport'],
        'reporting.payments' => [ReportingPolicy::class, 'viewPaymentReport'],
        'reporting.export' => [ReportingPolicy::class, 'exportReport'],
    ];

    /**
     * @var array<class-string, class-string>
     */
    private array $policies = [
        User::class => UserPolicy::class,
        Role::class => RolePolicy::class,
        Clinic::class => ClinicPolicy::class,
        // Sprint 19 — Clinic Master Data
        ClinicRoom::class => ClinicRoomPolicy::class,
        // Sprint 20 — RME: Clinic Visit Queue
        ClinicVisit::class => ClinicVisitPolicy::class,
        MedicalRecord::class => MedicalRecordPolicy::class,
        // Sprint 20 Phase 1.3.1 — Odontogram Placeholder
        Odontogram::class => OdontogramPolicy::class,
        RmePrescription::class => RmePrescriptionPolicy::class,
        // FIX-RME-CONSENT-WORKFLOW-PRINT-UX-2 / FIX-01
        RmeVisitConsent::class => RmeVisitConsentPolicy::class,
        // Sprint 20 Phase 1.10 — RME Cashier Billing
        RmeInvoice::class => RmeInvoicePolicy::class,
        TreatmentCategory::class => TreatmentCategoryPolicy::class,
        Treatment::class => TreatmentPolicy::class,
        Tariff::class => TariffPolicy::class,
        PaymentMethod::class => PaymentMethodPolicy::class,
        WaReminderTemplate::class => WaReminderTemplatePolicy::class,
        Doctor::class => DoctorPolicy::class,
        Patient::class => PatientPolicy::class,
        LabService::class => LabServicePolicy::class,
        Technician::class => TechnicianPolicy::class,
        LabOrder::class => LabOrderPolicy::class,
        LabPickupTask::class => LabPickupTaskPolicy::class,
        LabDeliveryTask::class => LabDeliveryTaskPolicy::class,
        LabWorkflowEvidence::class => LabWorkflowEvidencePolicy::class,
        LabCaseCandidate::class => LabCaseCandidatePolicy::class,
        Attachment::class => AttachmentPolicy::class,
        Delivery::class => DeliveryPolicy::class,
        Invoice::class => InvoicePolicy::class,
        Payment::class => PaymentPolicy::class,
        // Sprint 9 — Multi Branch Foundation (skeleton; no routes authorize against it yet)
        Branch::class => BranchPolicy::class,
        InventoryBatch::class => InventoryBatchPolicy::class,
        InventoryBatchDisposalRequest::class => InventoryBatchDisposalRequestPolicy::class,
        InventoryLocation::class => InventoryLocationPolicy::class,
        Product::class => ProductPolicy::class,
        ProductCategory::class => ProductCategoryPolicy::class,
        ProductUnit::class => ProductUnitPolicy::class,
        Supplier::class => SupplierPolicy::class,
        InventoryMovement::class => InventoryMovementPolicy::class,
        StockOpname::class => StockOpnamePolicy::class,
        StockTransfer::class => StockTransferPolicy::class,
        PurchaseRequest::class => PurchaseRequestPolicy::class,
        PurchaseOrder::class => PurchaseOrderPolicy::class,
        GoodsReceipt::class => GoodsReceiptPolicy::class,
        InventoryActivityLog::class => InventoryActivityLogPolicy::class,
        // Sprint 17.8 — per-room minimum/maximum stock threshold configuration
        LocationProductMinimum::class => LocationProductMinimumPolicy::class,
        // LEGACY-RME-PDF-1A — legacy (historical) RME PDF archive
        LegacyRmeImport::class => LegacyRmeImportPolicy::class,
        LegacyRmeRecord::class => LegacyRmeRecordPolicy::class,
        LegacyOdontogramImport::class => LegacyOdontogramImportPolicy::class,
        LegacyOdontogramRecord::class => LegacyOdontogramRecordPolicy::class,
        // LEGACY-RME-PDF-ROLL-4 — migration operations control plane
        LegacyRmeMigrationWave::class => LegacyRmeMigrationWavePolicy::class,
    ];

    public function register(): void
    {
        foreach ($this->repositories as $interface => $concrete) {
            $this->app->bind($interface, $concrete);
        }

        // LEGACY-RME-OPS-CLI-1 — the legacy audit facade is a SINGLETON.
        //
        // It carries one piece of scoped state: the surface (HTTP or CLI) that
        // asked for the lifecycle operation currently running. The lifecycle
        // service sets that around a canonical service call — and those canonical
        // services receive their own injected copy of the facade. Without a
        // shared instance the channel would be set on one object and read from
        // another, so every audit row would silently lose the tag. The state is
        // always scoped by `withChannel()` and restored in a `finally`, so a
        // long-lived queue worker cannot inherit a stale value.
        $this->app->singleton(LegacyRmeAuditService::class);

        // FIX-04b — one audit facade per request, so the payload allow-list is
        // applied by a single instance rather than by several that could drift.
        $this->app->singleton(LegacyOdontogramAuditService::class);

        // Sprint 16.8.4 — analytics repository swap via feature flag (default: live ledger)
        $this->app->bind(InventoryAnalyticsRepositoryInterface::class, function ($app) {
            if (config('inventory.analytics_summary_enabled')) {
                return $app->make(InventorySummaryAnalyticsRepository::class);
            }

            return $app->make(InventoryAnalyticsRepository::class);
        });
    }

    public function boot(): void
    {
        // Polymorphic alias so sys_attachments/sys_audit_logs store entity_type
        // as the table name (e.g. "trx_lab_orders") instead of the FQCN.
        // Non-enforcing: other morphs (e.g. Spatie roles) keep their defaults.
        Relation::morphMap([
            LabOrder::ENTITY_TYPE => LabOrder::class,
            QualityControl::ENTITY_TYPE => QualityControl::class,
            Delivery::ENTITY_TYPE => Delivery::class,
            Invoice::ENTITY_TYPE => Invoice::class,
            Payment::ENTITY_TYPE => Payment::class,
            // Sprint 16.6 — inventory activity log subjects (table-name keys)
            (new PurchaseRequest)->getTable() => PurchaseRequest::class,
            (new PurchaseOrder)->getTable() => PurchaseOrder::class,
            (new GoodsReceipt)->getTable() => GoodsReceipt::class,
            (new StockTransfer)->getTable() => StockTransfer::class,
            (new StockOpname)->getTable() => StockOpname::class,
            (new InventoryMovement)->getTable() => InventoryMovement::class,
            (new InventoryBatch)->getTable() => InventoryBatch::class,
        ]);

        foreach ($this->policies as $model => $policy) {
            Gate::policy($model, $policy);
        }

        foreach ($this->gates as $ability => $callback) {
            Gate::define($ability, $callback);
        }

        // FIX-CLINIC-OPS-BRANCH-CONTEXT-WA-1 (FIX-08) — SATUSEHAT is a Super
        // Admin-only module. Defined once here and consumed by BOTH the route
        // group (`can:satusehat.access`) and the sidebar, so the menu and the
        // server-side boundary can never drift apart. The per-feature SATUSEHAT
        // permissions are intentionally left in place: they still tier what a
        // Super Admin may do inside the module, and they gate the non-SATUSEHAT
        // clinical-diagnosis routes that share some of them.
        Gate::define('satusehat.access', fn (User $user) => $user->hasRole('Super Admin'));

        // Super Admin can do everything (PRD §5).
        Gate::before(function (User $user, string $ability) {
            return $user->hasRole('Super Admin') ? true : null;
        });
    }
}
