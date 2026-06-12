<?php

use App\Http\Controllers\HomeDashboardController;
use App\Http\Controllers\ProfileController;
use App\Modules\AccessControl\Controllers\PermissionController;
use App\Modules\AccessControl\Controllers\RoleController;
use App\Modules\Clinic\Controllers\ClinicController;
use App\Modules\ClinicRoom\Controllers\ClinicRoomController;
use App\Modules\ClinicVisit\Controllers\ClinicVisitController;
use App\Modules\Delivery\Controllers\DeliveryController;
use App\Modules\Doctor\Controllers\DoctorController;
use App\Modules\Inventory\Controllers\GoodsReceiptController;
use App\Modules\Inventory\Controllers\InventoryActivityLogController;
use App\Modules\Inventory\Controllers\InventoryAlertController;
use App\Modules\Inventory\Controllers\InventoryAnalyticsController;
use App\Modules\Inventory\Controllers\InventoryBatchController;
use App\Modules\Inventory\Controllers\InventoryDashboardController;
use App\Modules\Inventory\Controllers\InventoryExecutiveDashboardController;
use App\Modules\Inventory\Controllers\InventoryLocationController;
use App\Modules\Inventory\Controllers\InventoryReportController;
use App\Modules\Inventory\Controllers\InventoryStockController;
use App\Modules\Inventory\Controllers\LocationProductMinimumController;
use App\Modules\Inventory\Controllers\ProductCategoryController;
use App\Modules\Inventory\Controllers\ProductController as InventoryProductController;
use App\Modules\Inventory\Controllers\ProductImportController;
use App\Modules\Inventory\Controllers\ProductUnitController;
use App\Modules\Inventory\Controllers\PurchaseOrderController;
use App\Modules\Inventory\Controllers\PurchaseRequestController;
use App\Modules\Inventory\Controllers\StockCardController;
use App\Modules\Inventory\Controllers\StockOpnameController;
use App\Modules\Inventory\Controllers\StockTransferController;
use App\Modules\Inventory\Controllers\SupplierController as InventorySupplierController;
use App\Modules\Invoice\Controllers\InvoiceController;
use App\Modules\Invoice\Controllers\PaymentController;
use App\Modules\LabOrder\Controllers\AttachmentController;
use App\Modules\LabOrder\Controllers\LabCaseCandidateController;
use App\Modules\LabOrder\Controllers\LabOrderController;
use App\Modules\LabService\Controllers\LabServiceController;
use App\Modules\MedicalRecord\Controllers\MedicalRecordController;
use App\Modules\MedicalRecord\Controllers\MedicalRecordHandwritingController;
use App\Modules\Odontogram\Controllers\OdontogramController;
use App\Modules\Patient\Controllers\PatientController;
use App\Modules\PaymentMethod\Controllers\PaymentMethodController;
use App\Modules\Production\Controllers\AssignmentController as ProductionAssignmentController;
use App\Modules\Production\Controllers\ProductionStepController;
use App\Modules\Production\Controllers\ProductionWorkflowController;
use App\Modules\Production\Controllers\WorkLogController;
use App\Modules\QualityControl\Controllers\ChecklistController as QcChecklistController;
use App\Modules\QualityControl\Controllers\QualityControlController;
use App\Modules\QualityControl\Controllers\RemakeController as QcRemakeController;
use App\Modules\Reporting\Controllers\DashboardController as ReportingDashboardController;
use App\Modules\Reporting\Controllers\ExportReportController;
use App\Modules\Reporting\Controllers\ReportController;
use App\Modules\RmeInvoice\Controllers\RmeInvoiceController;
use App\Modules\RmeInvoice\Controllers\RmePaymentController;
use App\Modules\RmeInvoice\Controllers\RmeReportController;
use App\Modules\Tariff\Controllers\TariffController;
use App\Modules\Technician\Controllers\TechnicianController;
use App\Modules\Treatment\Controllers\TreatmentController;
use App\Modules\TreatmentCategory\Controllers\TreatmentCategoryController;
use App\Modules\User\Controllers\UserController;
use App\Modules\WaReminderTemplate\Controllers\WaReminderTemplateController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->route('login');
});

Route::get('/dashboard', [HomeDashboardController::class, 'index'])
    ->middleware(['auth', 'verified', 'permission:view dashboard|view_owner_dashboard'])
    ->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

/*
|--------------------------------------------------------------------------
| Sprint 1 — User & Access Management (Settings)
|--------------------------------------------------------------------------
| Access is gated per-permission via Spatie's permission middleware.
*/
Route::middleware('auth')->prefix('settings')->name('settings.')->group(function () {
    // User Management (TASK-0101, TASK-0104)
    Route::middleware('permission:manage users')->group(function () {
        Route::resource('users', UserController::class)->except(['show']);
        Route::patch('users/{user}/activate', [UserController::class, 'activate'])->name('users.activate');
        Route::patch('users/{user}/deactivate', [UserController::class, 'deactivate'])->name('users.deactivate');
    });

    // Role Management + Permission Assignment (TASK-0102, TASK-0103)
    Route::resource('roles', RoleController::class)
        ->except(['show'])
        ->middleware('permission:manage roles');

    // Permission listing (TASK-0103)
    Route::get('permissions', [PermissionController::class, 'index'])
        ->name('permissions.index')
        ->middleware('permission:manage permissions');

    /*
    |----------------------------------------------------------------------
    | Sprint 2 — Master Data
    |----------------------------------------------------------------------
    */
    // Clinics (TASK-0201)
    Route::middleware('permission:manage clinics')->group(function () {
        Route::resource('clinics', ClinicController::class)->except(['show']);
        Route::patch('clinics/{clinic}/activate', [ClinicController::class, 'activate'])->name('clinics.activate');
        Route::patch('clinics/{clinic}/deactivate', [ClinicController::class, 'deactivate'])->name('clinics.deactivate');
    });

    // Doctors (TASK-0202)
    Route::middleware('permission:manage doctors')->group(function () {
        Route::resource('doctors', DoctorController::class)->except(['show']);
        Route::patch('doctors/{doctor}/activate', [DoctorController::class, 'activate'])->name('doctors.activate');
        Route::patch('doctors/{doctor}/deactivate', [DoctorController::class, 'deactivate'])->name('doctors.deactivate');
    });

    // Patients (TASK-0203)
    Route::middleware('permission:manage patients')->group(function () {
        Route::resource('patients', PatientController::class)->except(['show']);
        Route::patch('patients/{patient}/activate', [PatientController::class, 'activate'])->name('patients.activate');
        Route::patch('patients/{patient}/deactivate', [PatientController::class, 'deactivate'])->name('patients.deactivate');
    });

    // Lab Services (TASK-0204)
    Route::middleware('permission:manage lab services')->group(function () {
        Route::resource('lab-services', LabServiceController::class)
            ->except(['show'])
            ->parameters(['lab-services' => 'labService']);
        Route::patch('lab-services/{labService}/activate', [LabServiceController::class, 'activate'])->name('lab-services.activate');
        Route::patch('lab-services/{labService}/deactivate', [LabServiceController::class, 'deactivate'])->name('lab-services.deactivate');
    });

    // Technicians (TASK-0205)
    Route::middleware('permission:manage technicians')->group(function () {
        Route::resource('technicians', TechnicianController::class)->except(['show']);
        Route::patch('technicians/{technician}/activate', [TechnicianController::class, 'activate'])->name('technicians.activate');
        Route::patch('technicians/{technician}/deactivate', [TechnicianController::class, 'deactivate'])->name('technicians.deactivate');
    });

    /*
    |----------------------------------------------------------------------
    | Sprint 19 — Clinic Master Data: Rooms (branch-scoped)
    |----------------------------------------------------------------------
    | Group gated by either permission; write actions are further restricted
    | to manage_clinic_master_data via ClinicRoomPolicy.
    */
    Route::middleware('permission:view_clinic_master_data|manage_clinic_master_data')->group(function () {
        Route::resource('clinic-rooms', ClinicRoomController::class)
            ->except(['show'])
            ->parameters(['clinic-rooms' => 'clinicRoom']);

        // Sprint 19 Phase 2 — Treatment master data (global, not branch-scoped).
        Route::resource('treatment-categories', TreatmentCategoryController::class)
            ->except(['show'])
            ->parameters(['treatment-categories' => 'treatmentCategory']);
        Route::resource('treatments', TreatmentController::class)
            ->except(['show']);

        // Sprint 19 Phase 3 — Tariff master data (branch-scoped pricing for treatments).
        Route::resource('tariffs', TariffController::class)
            ->except(['show']);

        // Sprint 19 Phase 4 — Payment Method master data (global, not branch-scoped).
        Route::resource('payment-methods', PaymentMethodController::class)
            ->except(['show'])
            ->parameters(['payment-methods' => 'paymentMethod']);

        // Sprint 19 Phase 5 — WA Reminder Template master data (global, not branch-scoped).
        Route::resource('wa-reminder-templates', WaReminderTemplateController::class)
            ->except(['show'])
            ->parameters(['wa-reminder-templates' => 'waReminderTemplate']);
    });
});

/*
|--------------------------------------------------------------------------
| Sprint 20 — RME: Clinic Visit Queue
|--------------------------------------------------------------------------
*/
Route::middleware('auth')->prefix('rme')->name('rme.')->group(function () {
    Route::middleware('permission:view_clinic_visits|manage_clinic_visits')->group(function () {
        Route::get('medical-records', [MedicalRecordController::class, 'index'])
            ->name('medical-records.index');

        Route::resource('visits', ClinicVisitController::class)
            ->only(['index', 'create', 'store', 'show', 'edit', 'update'])
            ->parameters(['visits' => 'clinicVisit']);

        Route::middleware('permission:manage_clinic_visits')
            ->post('visits/{clinicVisit}/transition', [ClinicVisitController::class, 'transitionStatus'])
            ->name('visits.transition');

        Route::get('visits/{clinicVisit}/medical-record', [MedicalRecordController::class, 'show'])
            ->name('visits.medical-record.show');

        Route::middleware('permission:manage_clinic_visits')->group(function () {
            Route::post('visits/{clinicVisit}/medical-record', [MedicalRecordController::class, 'store'])
                ->name('visits.medical-record.store');
            Route::patch('visits/{clinicVisit}/medical-record/{medicalRecord}', [MedicalRecordController::class, 'update'])
                ->name('visits.medical-record.update');
            Route::post('visits/{clinicVisit}/medical-record/{medicalRecord}/finalize', [MedicalRecordController::class, 'finalize'])
                ->name('visits.medical-record.finalize');
            // Sprint 20 Phase 1.8 — Handwriting RME
            Route::post('visits/{clinicVisit}/medical-record/{medicalRecord}/handwriting', [MedicalRecordHandwritingController::class, 'store'])
                ->name('visits.medical-record.handwriting.store');
        });

        // Sprint 20 Phase 1.3.1 — Odontogram Placeholder Foundation
        Route::get('visits/{clinicVisit}/odontogram', [OdontogramController::class, 'show'])
            ->name('visits.odontogram.show');

        // Sprint 20 Phase 1.6 — Odontogram Print View
        Route::get('odontograms/{odontogram}/print', [OdontogramController::class, 'print'])
            ->name('odontograms.print');

        // Sprint 20 Phase 1.7 — RME Visit Print Bundle
        Route::get('visits/{clinicVisit}/print', [ClinicVisitController::class, 'print'])
            ->name('visits.print');

        // Sprint 21 Phase 21.6 — RME Visit PDF Export
        Route::get('visits/{clinicVisit}/pdf', [ClinicVisitController::class, 'pdf'])
            ->name('visits.pdf');

        Route::middleware('permission:manage_clinic_visits')->group(function () {
            Route::patch('odontograms/{odontogram}', [OdontogramController::class, 'update'])
                ->name('odontograms.update');

            // Sprint 20 Phase 1.3.3 — Odontogram Finalize
            Route::post('odontograms/{odontogram}/finalize', [OdontogramController::class, 'finalize'])
                ->name('odontograms.finalize');
        });
    });

    // Sprint 20 Phase 1.10 — Cashier RME Billing
    Route::middleware('permission:manage_rme_billing')->group(function () {
        Route::get('cashier', [RmeInvoiceController::class, 'index'])->name('cashier.index');
        Route::get('cashier/{clinicVisit}/billing/create', [RmeInvoiceController::class, 'create'])->name('cashier.create');
        Route::post('cashier/{clinicVisit}/billing', [RmeInvoiceController::class, 'store'])->name('cashier.store');
        Route::get('cashier/{clinicVisit}/billing/{rmeInvoice}', [RmeInvoiceController::class, 'show'])->name('cashier.show');
        Route::get('cashier/{clinicVisit}/billing/{rmeInvoice}/payment/create', [RmePaymentController::class, 'create'])->name('cashier.payment.create');
        Route::post('cashier/{clinicVisit}/billing/{rmeInvoice}/payment', [RmePaymentController::class, 'store'])->name('cashier.payment.store');
        Route::get('cashier/{clinicVisit}/billing/{rmeInvoice}/receipt', [RmePaymentController::class, 'receipt'])->name('cashier.receipt.show');
    });

    // Sprint 23 Phase 23.5 — Separated RME reports (branch-aware, RME is multi-branch)
    Route::get('reports/patients', [RmeReportController::class, 'patients'])
        ->name('reports.patients')->middleware('permission:view_rme_patient_reports');
    Route::get('reports/payments', [RmeReportController::class, 'payments'])
        ->name('reports.payments')->middleware('permission:view_rme_payment_reports');
});

/*
|--------------------------------------------------------------------------
| Sprint 3 — Lab Order Core
|--------------------------------------------------------------------------
*/
Route::middleware('auth')->group(function () {
    Route::get('lab-orders', [LabOrderController::class, 'index'])
        ->name('lab-orders.index')->middleware('permission:view_lab_orders|manage_lab_orders');
    Route::get('lab-orders/create', [LabOrderController::class, 'create'])
        ->name('lab-orders.create')->middleware('permission:create_lab_orders|manage_lab_orders');
    Route::post('lab-orders', [LabOrderController::class, 'store'])
        ->name('lab-orders.store')->middleware('permission:create_lab_orders|manage_lab_orders');
    Route::get('lab-orders/{labOrder}', [LabOrderController::class, 'show'])
        ->name('lab-orders.show')->middleware('permission:view_lab_orders|manage_lab_orders');
    Route::get('lab-orders/{labOrder}/edit', [LabOrderController::class, 'edit'])
        ->name('lab-orders.edit')->middleware('permission:update_lab_orders|manage_lab_orders');
    Route::put('lab-orders/{labOrder}', [LabOrderController::class, 'update'])
        ->name('lab-orders.update')->middleware('permission:update_lab_orders|manage_lab_orders');
    Route::post('lab-orders/{labOrder}/cancel', [LabOrderController::class, 'cancel'])
        ->name('lab-orders.cancel')->middleware('permission:cancel_lab_orders|manage_lab_orders');

    // Attachments — authorization enforced by LabOrderPolicy in the controller.
    Route::post('lab-orders/{labOrder}/attachments', [AttachmentController::class, 'upload'])
        ->name('lab-orders.attachments.upload');
    Route::delete('lab-orders/{labOrder}/attachments/{attachment}', [AttachmentController::class, 'destroy'])
        ->name('lab-orders.attachments.destroy');
});

/*
|--------------------------------------------------------------------------
| Sprint 21 Phase 21.3 — Lab Case Candidate Queue (read-only)
|--------------------------------------------------------------------------
| Admin Lab queue of LabCaseCandidate records generated from paid RME invoices.
| Phase 21.4 adds explicit conversion to LabOrder.
*/
Route::middleware('auth')->prefix('lab')->name('lab-')->group(function () {
    Route::get('case-candidates', [LabCaseCandidateController::class, 'index'])
        ->name('case-candidates.index')
        ->middleware('permission:view_lab_orders|manage_lab_orders');
    Route::get('case-candidates/{candidate}', [LabCaseCandidateController::class, 'show'])
        ->name('case-candidates.show')
        ->middleware('permission:view_lab_orders|manage_lab_orders');
    Route::post('case-candidates/{candidate}/convert', [LabCaseCandidateController::class, 'convert'])
        ->name('case-candidates.convert')
        ->middleware('permission:create_lab_orders|manage_lab_orders');
});

/*
|--------------------------------------------------------------------------
| Sprint 4 — Production Workflow
|--------------------------------------------------------------------------
*/
Route::middleware('auth')->prefix('production')->name('production.')->group(function () {
    Route::get('/', [ProductionWorkflowController::class, 'board'])
        ->name('board')->middleware('permission:view_production|manage_production');
    Route::get('/{labOrder}', [ProductionWorkflowController::class, 'show'])
        ->name('show')->middleware('permission:view_production|manage_production');

    Route::post('/{labOrder}/assign', [ProductionAssignmentController::class, 'store'])
        ->name('assign')->middleware('permission:assign_technicians|manage_production');
    Route::post('/{labOrder}/reassign', [ProductionAssignmentController::class, 'reassign'])
        ->name('reassign')->middleware('permission:reassign_technicians|manage_production');

    Route::post('/{labOrder}/start', [ProductionWorkflowController::class, 'start'])
        ->name('start')->middleware('permission:start_production_work|manage_production');
    Route::post('/{labOrder}/pause', [ProductionWorkflowController::class, 'pause'])
        ->name('pause')->middleware('permission:pause_production_work|manage_production');
    Route::post('/{labOrder}/resume', [ProductionWorkflowController::class, 'resume'])
        ->name('resume')->middleware('permission:resume_production_work|manage_production');
    Route::post('/{labOrder}/complete', [ProductionWorkflowController::class, 'complete'])
        ->name('complete')->middleware('permission:complete_production_work|manage_production');
    Route::post('/{labOrder}/send-to-qc', [ProductionWorkflowController::class, 'sendToQc'])
        ->name('send-to-qc')->middleware('permission:send_to_qc|manage_production');

    Route::get('/{labOrder}/work-logs', [WorkLogController::class, 'index'])
        ->name('work-logs.index')->middleware('permission:view_production|manage_production');
    Route::patch('/{labOrder}/steps/{step}', [ProductionStepController::class, 'update'])
        ->name('steps.update');
});

/*
|--------------------------------------------------------------------------
| Sprint 5 — Quality Control
|--------------------------------------------------------------------------
*/
Route::middleware('auth')->prefix('quality-control')->name('quality-control.')->group(function () {
    Route::get('/', [QualityControlController::class, 'queue'])
        ->name('queue')->middleware('permission:view_quality_control|manage_quality_control');

    // Static segment before the {labOrder} catch-all.
    Route::patch('/checklists/{checklist}', [QcChecklistController::class, 'update'])
        ->name('checklists.update')->middleware('permission:update_qc_checklist|manage_quality_control');

    Route::get('/{labOrder}', [QualityControlController::class, 'show'])
        ->name('show')->middleware('permission:view_quality_control|manage_quality_control');
    Route::post('/{labOrder}/start', [QualityControlController::class, 'start'])
        ->name('start')->middleware('permission:start_qc|manage_quality_control');
    Route::post('/{labOrder}/pass', [QualityControlController::class, 'pass'])
        ->name('pass')->middleware('permission:pass_qc|manage_quality_control');
    Route::post('/{labOrder}/reject', [QualityControlController::class, 'reject'])
        ->name('reject')->middleware('permission:reject_qc|manage_quality_control');
    Route::post('/{labOrder}/remake', [QcRemakeController::class, 'store'])
        ->name('remake')->middleware('permission:request_remake|manage_quality_control');
    Route::post('/{labOrder}/evidence', [QualityControlController::class, 'evidence'])
        ->name('evidence.store')->middleware('permission:upload_qc_evidence|manage_quality_control');
});

/*
|--------------------------------------------------------------------------
| Sprint 6 - Delivery & POD
|--------------------------------------------------------------------------
*/
Route::middleware('auth')->prefix('deliveries')->name('deliveries.')->group(function () {
    Route::get('/', [DeliveryController::class, 'index'])
        ->name('index')->middleware('permission:view_delivery|manage_delivery');
    Route::post('/', [DeliveryController::class, 'store'])
        ->name('store')->middleware('permission:create_delivery|manage_delivery');
    Route::get('/{delivery}', [DeliveryController::class, 'show'])
        ->name('show')->middleware('permission:view_delivery|manage_delivery');
    Route::post('/{delivery}/assign-courier', [DeliveryController::class, 'assignCourier'])
        ->name('assign-courier')->middleware('permission:assign_courier|manage_delivery');
    Route::post('/{delivery}/reassign-courier', [DeliveryController::class, 'reassignCourier'])
        ->name('reassign-courier')->middleware('permission:assign_courier|manage_delivery');
    Route::post('/{delivery}/start', [DeliveryController::class, 'startDelivery'])
        ->name('start')->middleware('permission:start_delivery|manage_delivery');
    Route::post('/{delivery}/mark-delivered', [DeliveryController::class, 'markDelivered'])
        ->name('mark-delivered')->middleware('permission:mark_delivered|manage_delivery');
    Route::post('/{delivery}/complete', [DeliveryController::class, 'completeDelivery'])
        ->name('complete')->middleware('permission:complete_delivery|manage_delivery');
    Route::post('/{delivery}/pod', [DeliveryController::class, 'uploadPod'])
        ->name('pod')->middleware('permission:upload_pod|manage_delivery');
});

/*
|--------------------------------------------------------------------------
| Sprint 7 - Invoice & Payment
|--------------------------------------------------------------------------
*/
Route::middleware('auth')->group(function () {
    Route::get('invoices', [InvoiceController::class, 'index'])
        ->name('invoices.index')->middleware('permission:view_invoice|manage_invoice');
    Route::get('invoices/create', [InvoiceController::class, 'create'])
        ->name('invoices.create')->middleware('permission:create_invoice|manage_invoice');
    Route::post('invoices', [InvoiceController::class, 'store'])
        ->name('invoices.store')->middleware('permission:create_invoice|manage_invoice');
    Route::get('invoices/{invoice}', [InvoiceController::class, 'show'])
        ->name('invoices.show')->middleware('permission:view_invoice|manage_invoice');
    Route::post('invoices/{invoice}/issue', [InvoiceController::class, 'issue'])
        ->name('invoices.issue')->middleware('permission:issue_invoice|manage_invoice');
    Route::post('invoices/{invoice}/void', [InvoiceController::class, 'void'])
        ->name('invoices.void')->middleware('permission:void_invoice|manage_invoice');
    Route::post('invoices/{invoice}/payments', [PaymentController::class, 'store'])
        ->name('invoices.payments.store')->middleware('permission:create_payment|manage_payment');
});

/*
|--------------------------------------------------------------------------
| Sprint 8 — Reporting & Dashboard (read only)
|--------------------------------------------------------------------------
*/
Route::middleware('auth')->prefix('reports')->name('reports.')->group(function () {
    Route::get('dashboard', [ReportingDashboardController::class, 'index'])
        ->name('dashboard')->middleware('permission:view_dashboard|manage_report');

    Route::get('orders', [ReportController::class, 'orders'])
        ->name('orders')->middleware('permission:view_order_report|manage_report');
    Route::get('production', [ReportController::class, 'production'])
        ->name('production')->middleware('permission:view_production_report|manage_report');
    Route::get('qc', [ReportController::class, 'qualityControl'])
        ->name('qc')->middleware('permission:view_qc_report|manage_report');
    Route::get('delivery', [ReportController::class, 'delivery'])
        ->name('delivery')->middleware('permission:view_delivery_report|manage_report');
    Route::get('invoices', [ReportController::class, 'invoices'])
        ->name('invoices')->middleware('permission:view_invoice_report|manage_report');
    Route::get('payments', [ReportController::class, 'payments'])
        ->name('payments')->middleware('permission:view_payment_report|manage_report');
    Route::get('outstanding', [ReportController::class, 'outstanding'])
        ->name('outstanding')->middleware('permission:view_invoice_report|manage_report');
    Route::get('revenue', [ReportController::class, 'revenue'])
        ->name('revenue')->middleware('permission:view_invoice_report|manage_report');

    // Exports (require export_report + the report's own permission via controller).
    Route::middleware('permission:export_report|manage_report')->group(function () {
        Route::get('orders/export', [ExportReportController::class, 'exportOrders'])->name('orders.export');
        Route::get('production/export', [ExportReportController::class, 'exportProduction'])->name('production.export');
        Route::get('qc/export', [ExportReportController::class, 'exportQualityControl'])->name('qc.export');
        Route::get('delivery/export', [ExportReportController::class, 'exportDelivery'])->name('delivery.export');
        Route::get('invoices/export', [ExportReportController::class, 'exportInvoices'])->name('invoices.export');
        Route::get('payments/export', [ExportReportController::class, 'exportPayments'])->name('payments.export');
        Route::get('outstanding/export', [ExportReportController::class, 'exportOutstanding'])->name('outstanding.export');
        Route::get('revenue/export', [ExportReportController::class, 'exportRevenue'])->name('revenue.export');
    });
});

/*
|--------------------------------------------------------------------------
| Sprint 12 - Inventory Core
|--------------------------------------------------------------------------
*/
Route::middleware('auth')->prefix('inventory')->name('inventory.')->group(function () {
    Route::get('dashboard', [InventoryDashboardController::class, 'index'])->name('dashboard');

    Route::get('alerts', [InventoryAlertController::class, 'index'])->name('alerts.index');

    Route::get('analytics', [InventoryAnalyticsController::class, 'index'])->name('analytics.index');

    Route::get('executive-dashboard', [InventoryExecutiveDashboardController::class, 'index'])->name('executive-dashboard');

    Route::get('activity-logs', [InventoryActivityLogController::class, 'index'])->name('activity-logs.index');
    Route::get('activity-logs/{inventoryActivityLog}', [InventoryActivityLogController::class, 'show'])->name('activity-logs.show');

    Route::get('reports', [InventoryReportController::class, 'index'])->name('reports.index');
    Route::get('reports/export', [InventoryReportController::class, 'export'])->name('reports.export');
    Route::get('reports/room-stock/refill-checklist', [InventoryReportController::class, 'downloadRoomStockRefillChecklist'])->name('reports.room-stock.refill-checklist');

    Route::get('stock', [InventoryStockController::class, 'index'])->name('stock.index');

    Route::get('batches', [InventoryBatchController::class, 'index'])->name('batches.index');
    Route::get('batches/{inventoryBatch}', [InventoryBatchController::class, 'show'])->name('batches.show');

    Route::get('products/{product}/stock-card', [StockCardController::class, 'show'])->name('products.stock-card');
    Route::get('products/{product}/opening-stock', [InventoryStockController::class, 'openingStock'])->name('products.opening-stock.create');
    Route::post('products/{product}/opening-stock', [InventoryStockController::class, 'storeOpeningStock'])->name('products.opening-stock.store');
    Route::get('products/{product}/receive-stock', [InventoryStockController::class, 'receiveStock'])->name('products.receive-stock.create');
    Route::post('products/{product}/receive-stock', [InventoryStockController::class, 'storeReceiveStock'])->name('products.receive-stock.store');
    Route::get('products/{product}/adjust-in', [InventoryStockController::class, 'adjustIn'])->name('products.adjust-in.create');
    Route::post('products/{product}/adjust-in', [InventoryStockController::class, 'storeAdjustIn'])->name('products.adjust-in.store');
    Route::get('products/{product}/adjust-out', [InventoryStockController::class, 'adjustOut'])->name('products.adjust-out.create');
    Route::post('products/{product}/adjust-out', [InventoryStockController::class, 'storeAdjustOut'])->name('products.adjust-out.store');

    Route::resource('locations', InventoryLocationController::class);
    Route::resource('product-categories', ProductCategoryController::class)
        ->except(['show'])
        ->parameters(['product-categories' => 'productCategory']);
    Route::resource('product-units', ProductUnitController::class)
        ->except(['show'])
        ->parameters(['product-units' => 'productUnit']);
    Route::resource('location-minimums', LocationProductMinimumController::class)
        ->except(['show'])
        ->names('location-minimums')
        ->parameters(['location-minimums' => 'locationProductMinimum']);
    Route::get('products/import/template', [ProductImportController::class, 'template'])->name('products.import.template');
    Route::get('products/import', [ProductImportController::class, 'create'])->name('products.import');
    Route::post('products/import', [ProductImportController::class, 'store'])->name('products.import.store');
    Route::resource('products', InventoryProductController::class);
    Route::resource('suppliers', InventorySupplierController::class);

    Route::resource('stock-opnames', StockOpnameController::class)->only(['index', 'create', 'store', 'show']);
    Route::get('stock-opnames/{stockOpname}/review', [StockOpnameController::class, 'reviewScreen'])->name('stock-opnames.review-screen');
    Route::post('stock-opnames/{stockOpname}/review', [StockOpnameController::class, 'review'])->name('stock-opnames.review');
    Route::post('stock-opnames/{stockOpname}/finalize', [StockOpnameController::class, 'finalize'])->name('stock-opnames.finalize');
    Route::post('stock-opnames/{stockOpname}/cancel', [StockOpnameController::class, 'cancel'])->name('stock-opnames.cancel');
    Route::post('stock-opnames/{stockOpname}/products/{productId}/counted-quantity', [StockOpnameController::class, 'updateCountedQuantity'])->name('stock-opnames.update-counted-quantity');

    Route::resource('stock-transfers', StockTransferController::class)->only(['index', 'create', 'store', 'show', 'edit', 'update']);
    Route::get('stock-transfers/{stockTransfer}/checklist', [StockTransferController::class, 'downloadChecklist'])->name('stock-transfers.checklist');
    Route::post('stock-transfers/{stockTransfer}/submit', [StockTransferController::class, 'submit'])->name('stock-transfers.submit');
    Route::post('stock-transfers/{stockTransfer}/ship', [StockTransferController::class, 'ship'])->name('stock-transfers.ship');
    Route::post('stock-transfers/{stockTransfer}/receive', [StockTransferController::class, 'receive'])->name('stock-transfers.receive');
    Route::post('stock-transfers/{stockTransfer}/cancel', [StockTransferController::class, 'cancel'])->name('stock-transfers.cancel');

    Route::resource('purchase-requests', PurchaseRequestController::class)->only(['index', 'create', 'store', 'show', 'edit', 'update']);
    Route::post('purchase-requests/{purchaseRequest}/submit', [PurchaseRequestController::class, 'submit'])->name('purchase-requests.submit');
    Route::post('purchase-requests/{purchaseRequest}/approve', [PurchaseRequestController::class, 'approve'])->name('purchase-requests.approve');
    Route::post('purchase-requests/{purchaseRequest}/reject', [PurchaseRequestController::class, 'reject'])->name('purchase-requests.reject');
    Route::post('purchase-requests/{purchaseRequest}/cancel', [PurchaseRequestController::class, 'cancel'])->name('purchase-requests.cancel');

    Route::resource('purchase-orders', PurchaseOrderController::class)->only(['index', 'create', 'store', 'show', 'edit', 'update']);
    Route::post('purchase-orders/{purchaseOrder}/submit', [PurchaseOrderController::class, 'submit'])->name('purchase-orders.submit');
    Route::post('purchase-orders/{purchaseOrder}/approve', [PurchaseOrderController::class, 'approve'])->name('purchase-orders.approve');
    Route::post('purchase-orders/{purchaseOrder}/send', [PurchaseOrderController::class, 'send'])->name('purchase-orders.send');
    Route::post('purchase-orders/{purchaseOrder}/cancel', [PurchaseOrderController::class, 'cancel'])->name('purchase-orders.cancel');

    Route::resource('goods-receipts', GoodsReceiptController::class)->only(['index', 'create', 'store', 'show', 'edit', 'update']);
    Route::post('goods-receipts/{goodsReceipt}/submit', [GoodsReceiptController::class, 'submit'])->name('goods-receipts.submit');
    Route::post('goods-receipts/{goodsReceipt}/post', [GoodsReceiptController::class, 'post'])->name('goods-receipts.post');
    Route::post('goods-receipts/{goodsReceipt}/cancel', [GoodsReceiptController::class, 'cancel'])->name('goods-receipts.cancel');
    Route::post('goods-receipts/{goodsReceipt}/void', [GoodsReceiptController::class, 'void'])->name('goods-receipts.void');
});

require __DIR__.'/auth.php';
