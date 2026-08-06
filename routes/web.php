<?php

use App\Http\Controllers\DeveloperConsoleController;
use App\Http\Controllers\FiveBranchRolloutReadinessController;
use App\Http\Controllers\FoundationMonitoringController;
use App\Http\Controllers\HealthCheckController;
use App\Http\Controllers\HomeDashboardController;
use App\Http\Controllers\LoadBalancerHealthController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\ProfileController;
use App\Modules\AccessControl\Controllers\PermissionController;
use App\Modules\AccessControl\Controllers\RoleController;
use App\Modules\Branch\Controllers\BranchController;
use App\Modules\Clinic\Controllers\ClinicController;
use App\Modules\ClinicRoom\Controllers\ClinicRoomController;
use App\Modules\ClinicVisit\Controllers\ClinicVisitController;
use App\Modules\Delivery\Controllers\DeliveryController;
use App\Modules\Doctor\Controllers\DoctorController;
use App\Modules\Inventory\Controllers\GoodsReceiptController;
use App\Modules\Inventory\Controllers\InventoryActivityLogController;
use App\Modules\Inventory\Controllers\InventoryAlertController;
use App\Modules\Inventory\Controllers\InventoryAnalyticsController;
use App\Modules\Inventory\Controllers\InventoryBatchActionLogController;
use App\Modules\Inventory\Controllers\InventoryBatchController;
use App\Modules\Inventory\Controllers\InventoryBatchDisposalReportController;
use App\Modules\Inventory\Controllers\InventoryBatchDisposalRequestController;
use App\Modules\Inventory\Controllers\InventoryBatchMonthlyClosingPackController;
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
use App\Modules\Inventory\Controllers\PurchaseRequestWorkflowController;
use App\Modules\Inventory\Controllers\StockCardController;
use App\Modules\Inventory\Controllers\StockOpnameController;
use App\Modules\Inventory\Controllers\StockTransferController;
use App\Modules\Inventory\Controllers\SupplierController as InventorySupplierController;
use App\Modules\Invoice\Controllers\InvoiceController;
use App\Modules\Invoice\Controllers\PaymentController;
use App\Modules\LabCapacity\Controllers\LabCapacityConfigController;
use App\Modules\LabCapacity\Controllers\LabTechnicianCapacityController;
use App\Modules\LabOrder\Controllers\AttachmentController;
use App\Modules\LabOrder\Controllers\ExternalLabController;
use App\Modules\LabOrder\Controllers\LabCaseCandidateController;
use App\Modules\LabOrder\Controllers\LabDeliveryTaskController;
use App\Modules\LabOrder\Controllers\LabOperationalAnalyticsController;
use App\Modules\LabOrder\Controllers\LabOrderController;
use App\Modules\LabOrder\Controllers\LabPickupTaskController;
use App\Modules\LabOrder\Controllers\LabV2OrderController;
use App\Modules\LabOrder\Controllers\LabWorkflowEvidenceController;
use App\Modules\LabOrder\Controllers\LabWorkflowOperationalDashboardController;
use App\Modules\LabOrder\Controllers\LabWorkflowRequestController;
use App\Modules\LabService\Controllers\LabServiceController;
use App\Modules\LegacyRme\Controllers\LegacyRmeImportController;
use App\Modules\MedicalRecord\Controllers\ClinicalDiagnosisController;
use App\Modules\MedicalRecord\Controllers\DiagnosisRolloutController;
use App\Modules\MedicalRecord\Controllers\MedicalRecordController;
use App\Modules\MedicalRecord\Controllers\MedicalRecordDiagnosisController;
use App\Modules\MedicalRecord\Controllers\MedicalRecordHandwritingController;
use App\Modules\Odontogram\Controllers\OdontogramController;
use App\Modules\Patient\Controllers\LegacyPatientImportController;
use App\Modules\Patient\Controllers\PatientAuditController;
use App\Modules\Patient\Controllers\PatientController;
use App\Modules\Patient\Controllers\PatientDocumentController;
use App\Modules\PaymentMethod\Controllers\PaymentMethodController;
use App\Modules\Prescription\Controllers\RmePrescriptionController;
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
use App\Modules\RmeDashboard\Controllers\RmeDashboardController;
use App\Modules\RmeInvoice\Controllers\DoctorPerformanceReportController;
use App\Modules\RmeInvoice\Controllers\RmeInvoiceController;
use App\Modules\RmeInvoice\Controllers\RmePaymentController;
use App\Modules\RmeInvoice\Controllers\RmeReceivableFollowUpController;
use App\Modules\RmeInvoice\Controllers\RmeReportController;
use App\Modules\RmeOnlineContext\Controllers\OnlineContextController;
use App\Modules\Satusehat\Controllers\SatusehatBranchGovernanceController;
use App\Modules\Satusehat\Controllers\SatusehatBranchReadinessController;
use App\Modules\Satusehat\Controllers\SatusehatChangeControlController;
use App\Modules\Satusehat\Controllers\SatusehatDentalController;
use App\Modules\Satusehat\Controllers\SatusehatDiagnosisAdoptionController;
use App\Modules\Satusehat\Controllers\SatusehatIdentifierController;
use App\Modules\Satusehat\Controllers\SatusehatInternalPilotController;
use App\Modules\Satusehat\Controllers\SatusehatMappingController;
use App\Modules\Satusehat\Controllers\SatusehatMultiBranchReadinessController;
use App\Modules\Satusehat\Controllers\SatusehatReadinessController;
use App\Modules\Satusehat\Controllers\SatusehatRemediationController;
use App\Modules\Satusehat\Controllers\SatusehatRolloutWaveController;
use App\Modules\Satusehat\Controllers\SatusehatSubmissionController;
use App\Modules\Satusehat\Controllers\SatusehatUatController;
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

// LB-1 — minimal, unauthenticated health endpoint for load balancer checks.
if (config('load_balancer.health_endpoint_enabled')) {
    Route::get(config('load_balancer.health_endpoint_path', '/health/lb'), LoadBalancerHealthController::class)
        ->name('health.lb');
}

// ENT-8 — Observability & Health Check Pack: minimal, unauthenticated,
// non-sensitive liveness/readiness endpoints. GET only; no PII/secret output.
if (config('health_check.enabled', true)) {
    if (config('health_check.liveness.enabled', true)) {
        Route::get(config('health_check.liveness.path', '/health/live'), [HealthCheckController::class, 'live'])
            ->name('health.live');
    }

    if (config('health_check.readiness.enabled', true)) {
        Route::get(config('health_check.readiness.path', '/health/ready'), [HealthCheckController::class, 'ready'])
            ->name('health.ready');
    }
}

Route::get('/dashboard', [HomeDashboardController::class, 'index'])
    ->middleware(['auth', 'verified', 'permission:view dashboard|view_owner_dashboard'])
    ->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    // LAB-WORKFLOW-V2 Phase 5 — in-app notification inbox (strictly self-scoped).
    Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::post('/notifications/read-all', [NotificationController::class, 'readAll'])->name('notifications.read-all');
    Route::post('/notifications/{notification}/read', [NotificationController::class, 'read'])->name('notifications.read');
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
        // Sprint 62.3 — Legacy RME Patient Batch Import (staging + preview + commit).
        // Declared before the resource so the static `import` segment is not
        // shadowed by any wildcard patient route.
        Route::prefix('patients/import')->name('patients.import.')->group(function () {
            Route::get('/', [LegacyPatientImportController::class, 'index'])->name('index');
            Route::get('template', [LegacyPatientImportController::class, 'template'])->name('template');
            Route::post('/', [LegacyPatientImportController::class, 'store'])->name('store');
            Route::get('{batch}', [LegacyPatientImportController::class, 'show'])->name('show');
            Route::get('{batch}/errors', [LegacyPatientImportController::class, 'errors'])->name('errors');
            Route::post('{batch}/commit', [LegacyPatientImportController::class, 'commit'])->name('commit');
            Route::post('{batch}/rollback', [LegacyPatientImportController::class, 'rollback'])->name('rollback');
            Route::delete('{batch}', [LegacyPatientImportController::class, 'destroy'])->name('destroy');
        });

        Route::resource('patients', PatientController::class)->except(['show']);
        Route::patch('patients/{patient}/activate', [PatientController::class, 'activate'])->name('patients.activate');
        Route::patch('patients/{patient}/deactivate', [PatientController::class, 'deactivate'])->name('patients.deactivate');

        // Sprint 61.1 — Direct KTP Scanner Capture & Compression.
        // Temp upload (patient may not exist yet) + private document access.
        Route::post('patients/ktp-scan/upload-temp', [PatientDocumentController::class, 'uploadTemp'])
            ->name('patients.ktp-scan.upload-temp');
        Route::get('patients/{patient}/documents/{document}', [PatientDocumentController::class, 'show'])
            ->name('patients.documents.show');
        Route::delete('patients/{patient}/documents/{document}', [PatientDocumentController::class, 'destroy'])
            ->name('patients.documents.destroy');
    });

    // LEGACY-RME-PDF-1B — Impor Arsip RME Lama (historical PDF archive).
    //
    // Deliberately its OWN group rather than nested inside `manage patients`:
    // 1A defines the five named legacy permissions as THE boundary for this
    // capability, and inheriting a second, unrelated requirement would make
    // those permissions insufficient on their own.
    //
    // The controller independently re-checks the feature flag (OFF by default,
    // so the surface 404s) and the policy (which adds the per-row branch scope)
    // on every action. `create` is declared before the numeric `{import}`
    // routes so the static segment is never captured as an id.
    Route::prefix('rme/legacy-imports')->name('rme.legacy-imports.')->group(function () {
        Route::get('/', [LegacyRmeImportController::class, 'index'])
            ->name('index')
            ->middleware('permission:view_legacy_rme_imports|create_legacy_rme_imports');

        Route::get('create', [LegacyRmeImportController::class, 'create'])
            ->name('create')
            ->middleware('permission:create_legacy_rme_imports');

        Route::post('/', [LegacyRmeImportController::class, 'store'])
            ->name('store')
            ->middleware('permission:create_legacy_rme_imports');

        Route::middleware('permission:view_legacy_rme_imports|create_legacy_rme_imports')->group(function () {
            Route::get('{import}', [LegacyRmeImportController::class, 'show'])->name('show')->whereNumber('import');
            Route::get('{import}/status', [LegacyRmeImportController::class, 'status'])->name('status')->whereNumber('import');
            Route::get('{import}/source', [LegacyRmeImportController::class, 'source'])->name('source')->whereNumber('import');
            Route::get('{import}/pages/{page}', [LegacyRmeImportController::class, 'page'])->name('pages.show')->whereNumber('import')->whereNumber('page');
        });

        Route::middleware('permission:create_legacy_rme_imports')->group(function () {
            Route::post('{import}/retry', [LegacyRmeImportController::class, 'retry'])->name('retry')->whereNumber('import');
            Route::post('{import}/cancel', [LegacyRmeImportController::class, 'cancel'])->name('cancel')->whereNumber('import');
        });
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

    /*
    |----------------------------------------------------------------------
    | Sprint 23 Phase 23.7 — Master Data Cabang (RME + Inventory branches)
    |----------------------------------------------------------------------
    | Read access via view_branch_master_data; write actions are further
    | restricted to manage_branch_master_data via BranchPolicy. Lab is global
    | and is intentionally not represented in branch master data.
    */
    Route::middleware('permission:view_branch_master_data|manage_branch_master_data')->group(function () {
        Route::resource('branches', BranchController::class)
            ->except(['show']);
    });
});

/*
|--------------------------------------------------------------------------
| Sprint 20 — RME: Clinic Visit Queue
|--------------------------------------------------------------------------
*/
Route::middleware('auth')->prefix('rme')->name('rme.')->group(function () {
    // Sprint 66.0 — Doctor/Admin online context (before permission gates).
    Route::get('online-context/select', [OnlineContextController::class, 'select'])
        ->name('online-context.select');
    Route::get('online-context/rooms', [OnlineContextController::class, 'rooms'])
        ->name('online-context.rooms');
    Route::post('online-context/doctor', [OnlineContextController::class, 'storeDoctor'])
        ->name('online-context.doctor');
    Route::post('online-context/admin-clinic', [OnlineContextController::class, 'storeAdminClinic'])
        ->name('online-context.admin-clinic');
    // RME-BRANCH-SUN4 — Perawat picks a Cabang RME the same way as Admin Klinik.
    Route::post('online-context/perawat', [OnlineContextController::class, 'storePerawat'])
        ->name('online-context.perawat');
    Route::post('online-context/offline', [OnlineContextController::class, 'offline'])
        ->name('online-context.offline');

    Route::middleware('permission:view_clinic_visits|manage_clinic_visits')->group(function () {
        // Sprint 58.4 — Standalone RME dashboard (replaces the Sprint 58.3
        // availability redirect). Aggregate KPI cards + RME shortcuts.
        Route::get('dashboard', [RmeDashboardController::class, 'index'])
            ->name('dashboard');

        Route::get('medical-records', [MedicalRecordController::class, 'index'])
            ->name('medical-records.index');

        // Sprint 58.6 — Doctor/Perawat treatment room worklist (room-assigned patients only).
        Route::middleware('permission:view_treatment_worklist')
            ->get('treatment-room-worklist', [ClinicVisitController::class, 'roomWorklist'])
            ->name('treatment-room-worklist.index');

        // Sprint 58.7 — Admin Klinik registered-patient queue (Antrian Pasien).
        Route::get('patient-queue', [ClinicVisitController::class, 'patientQueue'])
            ->name('patient-queue.index');

        Route::get('visits/patient-options', [ClinicVisitController::class, 'patientVisitOptions'])
            ->name('visits.patient-options');

        Route::get('visits/online-doctors', [ClinicVisitController::class, 'onlineDoctors'])
            ->name('visits.online-doctors');

        Route::resource('visits', ClinicVisitController::class)
            ->only(['index', 'create', 'store', 'show', 'edit', 'update'])
            ->parameters(['visits' => 'clinicVisit']);

        Route::middleware('permission:manage_clinic_visits')
            ->post('visits/{clinicVisit}/transition', [ClinicVisitController::class, 'transitionStatus'])
            ->name('visits.transition');

        // Sprint 58.6 — Admin Klinik assigns a treatment room to a queued visit.
        Route::middleware('permission:manage_clinic_visits')
            ->patch('visits/{clinicVisit}/room', [ClinicVisitController::class, 'assignRoom'])
            ->name('visits.assign-room');

        // Hotfix Sprint 60.8 — doctor examination requires an assigned treatment
        // room. The `visit.room` gate blocks RM input on a roomless active visit.
        Route::middleware('visit.room')
            ->get('visits/{clinicVisit}/medical-record', [MedicalRecordController::class, 'show'])
            ->name('visits.medical-record.show');

        Route::middleware(['permission:manage_clinic_visits', 'visit.room'])->group(function () {
            Route::post('visits/{clinicVisit}/medical-record', [MedicalRecordController::class, 'store'])
                ->name('visits.medical-record.store');
            Route::patch('visits/{clinicVisit}/medical-record/{medicalRecord}', [MedicalRecordController::class, 'update'])
                ->name('visits.medical-record.update');
            Route::post('visits/{clinicVisit}/medical-record/{medicalRecord}/finalize', [MedicalRecordController::class, 'finalize'])
                ->name('visits.medical-record.finalize');
            // Sprint 20 Phase 1.8 — Handwriting RME
            Route::post('visits/{clinicVisit}/medical-record/{medicalRecord}/handwriting', [MedicalRecordHandwritingController::class, 'store'])
                ->name('visits.medical-record.handwriting.store');
            // SATUSEHAT-4A — structured diagnosis entry (never auto-created;
            // reuses MedicalRecordPolicy::update inside the controller).
            Route::post('visits/{clinicVisit}/medical-record/{medicalRecord}/diagnoses', [MedicalRecordDiagnosisController::class, 'store'])
                ->name('visits.medical-record.diagnoses.store');
            Route::delete('visits/{clinicVisit}/medical-record/{medicalRecord}/diagnoses/{diagnosis}', [MedicalRecordDiagnosisController::class, 'destroy'])
                ->name('visits.medical-record.diagnoses.destroy');
            // SATUSEHAT-4B — explicit primary swap (never silent, audited).
            Route::post('visits/{clinicVisit}/medical-record/{medicalRecord}/diagnoses/{diagnosis}/make-primary', [MedicalRecordDiagnosisController::class, 'makePrimary'])
                ->name('visits.medical-record.diagnoses.make-primary');
            // SATUSEHAT-4B — reasoned emergency override of the pilot-enforced
            // diagnosis requirement (dedicated permission + policy re-check).
            Route::middleware('permission:override_diagnosis_requirement')
                ->post('visits/{clinicVisit}/medical-record/{medicalRecord}/diagnosis-override', [DiagnosisRolloutController::class, 'override'])
                ->name('visits.medical-record.diagnosis-override');
        });

        // SATUSEHAT-4A — bounded ACTIVE-only master diagnosis autocomplete for
        // the RME page (JSON; no PII).
        Route::middleware('permission:view_clinic_visits|manage_clinic_visits')
            ->get('diagnoses/search', [MedicalRecordDiagnosisController::class, 'search'])
            ->name('diagnoses.search');

        // Sprint 20 Phase 1.3.1 — Odontogram Placeholder Foundation
        // Hotfix Sprint 60.8 — odontogram input is gated behind room assignment.
        Route::middleware('visit.room')
            ->get('visits/{clinicVisit}/odontogram', [OdontogramController::class, 'show'])
            ->name('visits.odontogram.show');

        // Resep Dokter — doctor prescription canvas per visit.
        Route::middleware('visit.room')
            ->get('visits/{clinicVisit}/prescription', [RmePrescriptionController::class, 'show'])
            ->name('visits.prescription.show');

        Route::middleware(['permission:manage_clinic_visits', 'visit.room'])->group(function () {
            Route::post('visits/{clinicVisit}/prescription', [RmePrescriptionController::class, 'store'])
                ->name('visits.prescription.store');
            Route::patch('prescriptions/{rmePrescription}', [RmePrescriptionController::class, 'update'])
                ->name('prescriptions.update');
        });

        Route::get('prescriptions/{rmePrescription}/print', [RmePrescriptionController::class, 'print'])
            ->name('prescriptions.print');

        // Sprint 20 Phase 1.6 — Odontogram Print View
        Route::get('odontograms/{odontogram}/print', [OdontogramController::class, 'print'])
            ->name('odontograms.print');

        // Sprint 20 Phase 1.7 — RME Visit Print Bundle
        Route::get('visits/{clinicVisit}/print', [ClinicVisitController::class, 'print'])
            ->name('visits.print');

        // Sprint 21 Phase 21.6 — RME Visit PDF Export
        Route::get('visits/{clinicVisit}/pdf', [ClinicVisitController::class, 'pdf'])
            ->name('visits.pdf');

        Route::middleware(['permission:manage_clinic_visits', 'visit.room'])->group(function () {
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
        // Hotfix Sprint 60.7 — Doctor → Cashier sync queue (read-only visibility).
        Route::get('cashier/handoff', [RmeInvoiceController::class, 'handoff'])->name('cashier.handoff');
        Route::get('cashier/receivables', [RmeInvoiceController::class, 'receivables'])->name('cashier.receivables');
        Route::get('cashier/receivables/export', [RmeInvoiceController::class, 'exportReceivables'])->name('cashier.receivables.export');
        // Sprint 24 Phase 24.8 — RME receivable follow-up / reminder foundation
        Route::get('cashier/receivables/{rmeInvoice}/follow-ups/create', [RmeReceivableFollowUpController::class, 'create'])->name('cashier.receivables.follow-ups.create');
        Route::post('cashier/receivables/{rmeInvoice}/follow-ups', [RmeReceivableFollowUpController::class, 'store'])->name('cashier.receivables.follow-ups.store');
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
    Route::get('reports/patients/export', [RmeReportController::class, 'patientsExport'])
        ->name('reports.patients.export')->middleware('permission:view_rme_patient_reports');
    Route::get('reports/patients/print', [RmeReportController::class, 'patientsPrint'])
        ->name('reports.patients.print')->middleware('permission:view_rme_patient_reports');
    Route::get('reports/payments', [RmeReportController::class, 'payments'])
        ->name('reports.payments')->middleware('permission:view_rme_payment_reports');
    Route::get('reports/payments/export', [RmeReportController::class, 'paymentsExport'])
        ->name('reports.payments.export')->middleware('permission:view_rme_payment_reports');
    Route::get('reports/payments/print', [RmeReportController::class, 'paymentsPrint'])
        ->name('reports.payments.print')->middleware('permission:view_rme_payment_reports');

    // FIX-PRE-68-45 Scope C — Doctor Performance / Income report. Read-only.
    // Executive tier (view_doctor_performance_report) sees all doctors + RME
    // branches; a linked doctor (view_own_doctor_performance_report) is forced to
    // their own doctor_id server-side. Sources RME invoice/payment truth only.
    Route::get('reports/doctor-performance', [DoctorPerformanceReportController::class, 'index'])
        ->name('reports.doctor-performance')
        ->middleware('permission:view_doctor_performance_report|view_own_doctor_performance_report');

    // Sprint 61.0 — Patient Data Completeness Audit & RM Gap Review (read-only).
    // Gated to RME report viewers (Owner) OR patient managers (FO/Admin); doctors
    // and cashiers are excluded. Full KTP is never exposed.
    Route::get('patients/audit', [PatientAuditController::class, 'index'])
        ->name('patients.audit')->middleware('permission:view_rme_patient_reports|manage patients');
    Route::get('patients/audit/export', [PatientAuditController::class, 'export'])
        ->name('patients.audit.export')->middleware('permission:view_rme_patient_reports|manage patients');
});

// SATUSEHAT-1 — Controlled submission filter/review + mapping/identifier
// governance. Readiness foundation only: server-side branch scope, separate
// view/review/send + mapping/settings permissions, and NO external network call
// while the integration is disabled. URL /rme/satusehat; route name satusehat.*
Route::middleware('auth')->prefix('rme/satusehat')->name('satusehat.')->group(function () {
    Route::middleware('permission:view_satusehat_submissions|review_satusehat_submissions|send_satusehat_submissions')->group(function () {
        Route::get('submissions', [SatusehatSubmissionController::class, 'index'])->name('submissions.index');
        Route::post('submissions/bulk', [SatusehatSubmissionController::class, 'bulk'])->name('submissions.bulk');
        Route::get('submissions/{candidate}', [SatusehatSubmissionController::class, 'show'])->name('submissions.show');
        Route::get('submissions/{candidate}/preview', [SatusehatSubmissionController::class, 'preview'])->name('submissions.preview');
        Route::post('submissions/{candidate}/refresh', [SatusehatSubmissionController::class, 'refresh'])->name('submissions.refresh');
        Route::post('submissions/{candidate}/approve', [SatusehatSubmissionController::class, 'approve'])->name('submissions.approve');
        Route::post('submissions/{candidate}/exclude', [SatusehatSubmissionController::class, 'exclude'])->name('submissions.exclude');

        // SATUSEHAT-2 — outbound submission batches. Queuing requires the send
        // permission (re-checked server-side) AND the runtime gateway enabled;
        // while disabled the queue action is refused (fail-closed).
        Route::get('batches', [SatusehatSubmissionController::class, 'batchIndex'])->name('batches.index');
        Route::get('batches/{batch}', [SatusehatSubmissionController::class, 'batchShow'])->name('batches.show');
        Route::post('batches/{batch}/queue', [SatusehatSubmissionController::class, 'queue'])->name('batches.queue');

        // SATUSEHAT-3 — read-only dental coverage matrix (governance visibility).
        Route::get('dental/coverage', [SatusehatDentalController::class, 'coverage'])->name('dental.coverage');
    });

    Route::middleware('permission:manage_satusehat_mappings')->group(function () {
        Route::get('mappings', [SatusehatMappingController::class, 'index'])->name('mappings.index');
        Route::get('mappings/create', [SatusehatMappingController::class, 'create'])->name('mappings.create');
        Route::post('mappings', [SatusehatMappingController::class, 'store'])->name('mappings.store');
        Route::get('mappings/{mapping}', [SatusehatMappingController::class, 'show'])->name('mappings.show');
        Route::post('mappings/{mapping}/review', [SatusehatMappingController::class, 'review'])->name('mappings.review');
        // SATUSEHAT-3 — human verification stamp (required before profile-family activate).
        Route::post('mappings/{mapping}/verify', [SatusehatMappingController::class, 'verify'])->name('mappings.verify');
        Route::post('mappings/{mapping}/activate', [SatusehatMappingController::class, 'activate'])->name('mappings.activate');
        Route::post('mappings/{mapping}/deprecate', [SatusehatMappingController::class, 'deprecate'])->name('mappings.deprecate');
    });

    Route::middleware('permission:manage_satusehat_settings')->group(function () {
        // SATUSEHAT-3 — read-only production-readiness (production stays blocked).
        Route::get('production-readiness', [SatusehatDentalController::class, 'productionReadiness'])->name('production-readiness');
    });

    Route::middleware('permission:manage_satusehat_settings')->group(function () {
        Route::get('identifiers', [SatusehatIdentifierController::class, 'index'])->name('identifiers.index');
        Route::post('identifiers', [SatusehatIdentifierController::class, 'store'])->name('identifiers.store');
        Route::post('identifiers/{identifier}/deactivate', [SatusehatIdentifierController::class, 'deactivate'])->name('identifiers.deactivate');
        // SATUSEHAT-2 — verify an existing IHS identifier against the sandbox
        // (GET-by-id; refused while the integration is disabled).
        Route::post('identifiers/{identifier}/verify', [SatusehatIdentifierController::class, 'verify'])->name('identifiers.verify');
    });

    // SATUSEHAT-4A — credential-independent operational readiness & data-quality
    // workspace. Read side (dashboard + issues) is view-or-manage; every write
    // action needs the remediation permission; waivers need their own
    // permission. Branch scope resolves server-side — never from the request.
    Route::middleware('permission:view_satusehat_readiness|manage_satusehat_remediation')->group(function () {
        Route::get('readiness', [SatusehatReadinessController::class, 'index'])->name('readiness.index');
        Route::get('readiness/issues', [SatusehatReadinessController::class, 'issues'])->name('readiness.issues');
        Route::get('readiness/issues/{issue}', [SatusehatReadinessController::class, 'issueShow'])->name('readiness.issues.show');
    });

    Route::middleware('permission:manage_satusehat_remediation')->group(function () {
        Route::post('readiness/recalculate', [SatusehatReadinessController::class, 'recalculate'])->name('readiness.recalculate');
        Route::post('readiness/issues/{issue}/acknowledge', [SatusehatRemediationController::class, 'acknowledge'])->name('readiness.issues.acknowledge');
        Route::post('readiness/issues/{issue}/assign', [SatusehatRemediationController::class, 'assign'])->name('readiness.issues.assign');
        Route::post('readiness/issues/{issue}/start', [SatusehatRemediationController::class, 'start'])->name('readiness.issues.start');
        Route::post('readiness/issues/{issue}/request-review', [SatusehatRemediationController::class, 'requestReview'])->name('readiness.issues.request-review');
        Route::post('readiness/issues/{issue}/resolve', [SatusehatRemediationController::class, 'resolve'])->name('readiness.issues.resolve');
        Route::post('readiness/issues/{issue}/reopen', [SatusehatRemediationController::class, 'reopen'])->name('readiness.issues.reopen');
    });

    Route::middleware('permission:manage_satusehat_readiness_waivers')
        ->post('readiness/issues/{issue}/waive', [SatusehatRemediationController::class, 'waive'])
        ->name('readiness.issues.waive');

    // SATUSEHAT-4A — master clinical diagnosis governance (clinical reference
    // data; SATUSEHAT mapping stays a separate reviewed lifecycle).
    // SATUSEHAT-4B — the review actions (approve/reject/activate/deprecate)
    // require the dedicated review_clinical_terminology permission; separation
    // of duties (no self-approval) is re-enforced in the service layer.
    Route::middleware('permission:manage_structured_diagnoses|review_clinical_terminology')->group(function () {
        Route::get('diagnoses', [ClinicalDiagnosisController::class, 'index'])->name('diagnoses.index');
    });
    Route::middleware('permission:manage_structured_diagnoses')->group(function () {
        Route::post('diagnoses', [ClinicalDiagnosisController::class, 'store'])->name('diagnoses.store');
        Route::post('diagnoses/{diagnosis}/submit-review', [ClinicalDiagnosisController::class, 'submitReview'])->name('diagnoses.submit-review');
    });
    Route::middleware('permission:review_clinical_terminology')->group(function () {
        Route::post('diagnoses/{diagnosis}/approve', [ClinicalDiagnosisController::class, 'approve'])->name('diagnoses.approve');
        Route::post('diagnoses/{diagnosis}/reject', [ClinicalDiagnosisController::class, 'reject'])->name('diagnoses.reject');
        Route::post('diagnoses/{diagnosis}/activate', [ClinicalDiagnosisController::class, 'activate'])->name('diagnoses.activate');
        Route::post('diagnoses/{diagnosis}/deprecate', [ClinicalDiagnosisController::class, 'deprecate'])->name('diagnoses.deprecate');
    });

    // SATUSEHAT-4B — branch-scoped rollout configuration (no global switch).
    Route::middleware('permission:configure_diagnosis_rollout')->group(function () {
        Route::get('rollout', [DiagnosisRolloutController::class, 'index'])->name('rollout.index');
        Route::post('rollout/{branch}', [DiagnosisRolloutController::class, 'update'])->name('rollout.update');
    });

    // SATUSEHAT-4B — structured diagnosis adoption dashboard (read-only, PII-free).
    Route::middleware('permission:view_diagnosis_adoption')
        ->get('adoption', [SatusehatDiagnosisAdoptionController::class, 'index'])
        ->name('adoption.index');

    // SATUSEHAT-4C — branch readiness remediation & internal pilot operations.
    // Read side is branch-scoped server-side; every write action maps to a
    // dedicated least-privilege permission. Nothing here enables external
    // submission or production — pilot readiness is INTERNAL only.
    Route::middleware('permission:view_satusehat_branch_readiness|view_satusehat_pilot_metrics|manage_satusehat_branch_remediation')->group(function () {
        Route::get('branches', [SatusehatBranchReadinessController::class, 'index'])->name('branches.index');
        Route::get('branches/pilot-operations', [SatusehatBranchReadinessController::class, 'pilotOperations'])->name('branches.pilot-operations');
        Route::get('branches/{branch}', [SatusehatBranchReadinessController::class, 'show'])->whereNumber('branch')->name('branches.show');
    });

    Route::middleware('permission:manage_satusehat_branch_remediation')->group(function () {
        Route::post('branches/{branch}/recalculate', [SatusehatBranchReadinessController::class, 'recalculate'])->whereNumber('branch')->name('branches.recalculate');
        Route::post('branches/issues/{issue}/assign', [SatusehatBranchReadinessController::class, 'assignIssue'])->whereNumber('issue')->name('branches.issues.assign');
        Route::post('branches/issues/{issue}/escalate', [SatusehatBranchReadinessController::class, 'escalateIssue'])->whereNumber('issue')->name('branches.issues.escalate');
        Route::post('branches/issues/{issue}/review', [SatusehatBranchReadinessController::class, 'reviewIssue'])->whereNumber('issue')->name('branches.issues.review');
    });

    Route::middleware('permission:configure_satusehat_internal_pilot')->group(function () {
        Route::post('branches/{branch}/pilot/select', [SatusehatInternalPilotController::class, 'select'])->whereNumber('branch')->name('branches.pilot.select');
        Route::post('branches/{branch}/pilot/suspend', [SatusehatInternalPilotController::class, 'suspend'])->whereNumber('branch')->name('branches.pilot.suspend');
        Route::post('branches/{branch}/pilot/resume', [SatusehatInternalPilotController::class, 'resume'])->whereNumber('branch')->name('branches.pilot.resume');
        Route::post('branches/{branch}/pilot/thresholds', [SatusehatInternalPilotController::class, 'setThresholds'])->whereNumber('branch')->name('branches.pilot.thresholds');
    });

    Route::middleware('permission:approve_satusehat_internal_pilot')
        ->post('branches/{branch}/pilot/approve', [SatusehatInternalPilotController::class, 'approve'])
        ->whereNumber('branch')->name('branches.pilot.approve');

    Route::middleware('permission:run_satusehat_pilot_rehearsal')
        ->post('branches/{branch}/pilot/rehearse', [SatusehatInternalPilotController::class, 'rehearse'])
        ->whereNumber('branch')->name('branches.pilot.rehearse');

    // SATUSEHAT-4D — multi-branch readiness scale-up & operational governance.
    // Read side is branch-scoped server-side; every write maps to a dedicated
    // least-privilege permission. Nothing here enables external submission or
    // production — readiness is INTERNAL only; the credential blocker is separate.

    // Comparative multi-branch readiness matrix (read-only).
    Route::middleware('permission:view_satusehat_multi_branch_readiness')
        ->get('multi-branch', [SatusehatMultiBranchReadinessController::class, 'index'])
        ->name('multi-branch.index');

    // Executive / owner aggregate dashboard (read-only, PII-free).
    Route::middleware('permission:view_satusehat_executive_readiness')
        ->get('executive', [SatusehatMultiBranchReadinessController::class, 'executive'])
        ->name('executive.index');

    // Rollout waves.
    Route::middleware('permission:view_satusehat_multi_branch_readiness|manage_satusehat_rollout_waves')->group(function () {
        Route::get('waves', [SatusehatRolloutWaveController::class, 'index'])->name('waves.index');
        Route::get('waves/{wave}', [SatusehatRolloutWaveController::class, 'show'])->whereNumber('wave')->name('waves.show');
    });
    Route::middleware('permission:manage_satusehat_rollout_waves')->group(function () {
        Route::post('waves', [SatusehatRolloutWaveController::class, 'store'])->name('waves.store');
        Route::post('waves/{wave}/enroll', [SatusehatRolloutWaveController::class, 'enroll'])->whereNumber('wave')->name('waves.enroll');
        Route::post('waves/{wave}/remove-branch', [SatusehatRolloutWaveController::class, 'removeBranch'])->whereNumber('wave')->name('waves.remove-branch');
        Route::post('waves/{wave}/status', [SatusehatRolloutWaveController::class, 'changeStatus'])->whereNumber('wave')->name('waves.status');
        Route::post('waves/{wave}/suspend', [SatusehatRolloutWaveController::class, 'suspend'])->whereNumber('wave')->name('waves.suspend');
        Route::post('waves/{wave}/resume', [SatusehatRolloutWaveController::class, 'resume'])->whereNumber('wave')->name('waves.resume');
        Route::post('waves/{wave}/close', [SatusehatRolloutWaveController::class, 'close'])->whereNumber('wave')->name('waves.close');
    });
    Route::middleware('permission:approve_satusehat_rollout_wave')
        ->post('waves/{wave}/approve', [SatusehatRolloutWaveController::class, 'approve'])
        ->whereNumber('wave')->name('waves.approve');
    Route::middleware('permission:run_satusehat_pilot_rehearsal')
        ->post('waves/{wave}/rehearse', [SatusehatRolloutWaveController::class, 'rehearse'])
        ->whereNumber('wave')->name('waves.rehearse');

    // Branch readiness promotion / demotion + cross-branch bulk issue governance.
    Route::middleware('permission:promote_satusehat_branch')->group(function () {
        Route::post('branches/{branch}/promote', [SatusehatBranchGovernanceController::class, 'promote'])->whereNumber('branch')->name('branches.promote');
        Route::post('branches/{branch}/demote', [SatusehatBranchGovernanceController::class, 'demote'])->whereNumber('branch')->name('branches.demote');
        Route::post('branches/{branch}/readiness-suspend', [SatusehatBranchGovernanceController::class, 'suspend'])->whereNumber('branch')->name('branches.readiness-suspend');
        Route::post('branches/{branch}/readiness-resume', [SatusehatBranchGovernanceController::class, 'resume'])->whereNumber('branch')->name('branches.readiness-resume');
    });
    Route::middleware('permission:manage_satusehat_branch_remediation')
        ->post('issues/bulk-assign', [SatusehatBranchGovernanceController::class, 'bulkAssignIssues'])
        ->name('issues.bulk-assign');

    // Change-control governance.
    Route::middleware('permission:manage_satusehat_change_control')->group(function () {
        Route::get('change-control', [SatusehatChangeControlController::class, 'index'])->name('change-control.index');
        Route::post('change-control', [SatusehatChangeControlController::class, 'store'])->name('change-control.store');
        Route::post('change-control/{changeRequest}/review', [SatusehatChangeControlController::class, 'review'])->whereNumber('changeRequest')->name('change-control.review');
        Route::post('change-control/{changeRequest}/approve', [SatusehatChangeControlController::class, 'approve'])->whereNumber('changeRequest')->name('change-control.approve');
        Route::post('change-control/{changeRequest}/reject', [SatusehatChangeControlController::class, 'reject'])->whereNumber('changeRequest')->name('change-control.reject');
        Route::post('change-control/{changeRequest}/apply', [SatusehatChangeControlController::class, 'apply'])->whereNumber('changeRequest')->name('change-control.apply');
    });

    // Human operator UAT workflow.
    Route::middleware('permission:record_satusehat_uat_signoff')->group(function () {
        Route::get('uat', [SatusehatUatController::class, 'index'])->name('uat.index');
        Route::get('uat/{run}', [SatusehatUatController::class, 'show'])->whereNumber('run')->name('uat.show');
        Route::post('uat', [SatusehatUatController::class, 'store'])->name('uat.store');
        Route::post('uat/{run}/scenario', [SatusehatUatController::class, 'scenario'])->whereNumber('run')->name('uat.scenario');
        Route::post('uat/{run}/signoff', [SatusehatUatController::class, 'signoff'])->whereNumber('run')->name('uat.signoff');
        Route::post('uat/{run}/finalize', [SatusehatUatController::class, 'finalize'])->whereNumber('run')->name('uat.finalize');
        Route::post('uat/{run}/reject', [SatusehatUatController::class, 'reject'])->whereNumber('run')->name('uat.reject');
    });
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
| LAB-WORKFLOW-V2 Phase 2 — Cabang request, courier pickup, lab receive
|--------------------------------------------------------------------------
| Cabang (branch) V2 request workspace, courier pickup queue, and the
| authorized private evidence stream. Every mutation is additionally guarded
| server-side by the workflow services + state machine (branch/ownership/
| status/evidence) — route permissions are only the first layer.
*/
Route::middleware('auth')->prefix('lab')->group(function () {
    // Cabang (branch nurse / branch admin) workspace
    Route::middleware('permission:create_lab_branch_requests|manage_lab_orders')->group(function () {
        Route::get('workflow-requests', [LabWorkflowRequestController::class, 'index'])
            ->name('lab-workflow-requests.index');
        Route::get('workflow-requests/create', [LabWorkflowRequestController::class, 'create'])
            ->name('lab-workflow-requests.create');
        Route::post('workflow-requests', [LabWorkflowRequestController::class, 'store'])
            ->name('lab-workflow-requests.store');
        Route::get('workflow-requests/{labWorkflowRequest}', [LabWorkflowRequestController::class, 'show'])
            ->name('lab-workflow-requests.show');
        Route::post('workflow-requests/{labWorkflowRequest}/evidence', [LabWorkflowRequestController::class, 'storeEvidence'])
            ->name('lab-workflow-requests.evidence.store');
        Route::post('workflow-requests/{labWorkflowRequest}/submit-pickup', [LabWorkflowRequestController::class, 'submitPickup'])
            ->name('lab-workflow-requests.submit-pickup');
    });

    // Courier pickup queue + lab receive (policy-enforced per action)
    Route::middleware('permission:manage_lab_pickups|manage_lab_orders')->group(function () {
        Route::get('pickup-tasks', [LabPickupTaskController::class, 'index'])
            ->name('lab-pickup-tasks.index');
        Route::get('pickup-tasks/{pickupTask}', [LabPickupTaskController::class, 'show'])
            ->name('lab-pickup-tasks.show');
        Route::post('pickup-tasks/{pickupTask}/accept', [LabPickupTaskController::class, 'accept'])
            ->name('lab-pickup-tasks.accept');
        Route::post('pickup-tasks/{pickupTask}/picked-up', [LabPickupTaskController::class, 'pickedUp'])
            ->name('lab-pickup-tasks.picked-up');
        Route::post('pickup-tasks/{pickupTask}/start-transit', [LabPickupTaskController::class, 'startTransit'])
            ->name('lab-pickup-tasks.start-transit');
        Route::post('pickup-tasks/{pickupTask}/receive', [LabPickupTaskController::class, 'receive'])
            ->name('lab-pickup-tasks.receive');
    });

    // Private evidence stream — policy-authorized (LabWorkflowEvidencePolicy).
    Route::get('workflow-evidence/{evidence}', [LabWorkflowEvidenceController::class, 'show'])
        ->name('lab-workflow-evidence.show');

    // --- LAB-WORKFLOW-V2 Phase 3: lab-side pipeline hub -------------------
    // Analysis / production / QC / external-lab actions. Route permission is
    // layer 1; the state machine + workflow services re-validate everything.
    Route::get('v2-orders', [LabV2OrderController::class, 'index'])
        ->name('lab-v2-orders.index')
        ->middleware('permission:view_lab_orders|manage_lab_orders');
    Route::get('v2-orders/{labV2Order}', [LabV2OrderController::class, 'show'])
        ->name('lab-v2-orders.show')
        ->middleware('permission:view_lab_orders|manage_lab_orders');

    Route::post('v2-orders/{labV2Order}/register', [LabV2OrderController::class, 'registerModel'])
        ->name('lab-v2-orders.register')
        ->middleware('permission:manage_lab_orders');
    Route::post('v2-orders/{labV2Order}/analyze', [LabV2OrderController::class, 'analyze'])
        ->name('lab-v2-orders.analyze')
        ->middleware('permission:manage_lab_orders');

    Route::post('v2-orders/{labV2Order}/assign-technician', [LabV2OrderController::class, 'assignTechnician'])
        ->name('lab-v2-orders.assign-technician')
        ->middleware('permission:assign_technicians|manage_production');
    Route::post('v2-orders/{labV2Order}/steps/start', [LabV2OrderController::class, 'startStep'])
        ->name('lab-v2-orders.steps.start')
        ->middleware('permission:start_production_work|manage_production');
    Route::post('v2-orders/{labV2Order}/steps/complete', [LabV2OrderController::class, 'completeStep'])
        ->name('lab-v2-orders.steps.complete')
        ->middleware('permission:complete_production_work|manage_production');
    Route::post('v2-orders/{labV2Order}/send-to-qc', [LabV2OrderController::class, 'sendToQc'])
        ->name('lab-v2-orders.send-to-qc')
        ->middleware('permission:send_to_qc|manage_production');

    Route::post('v2-orders/{labV2Order}/qc/pass', [LabV2OrderController::class, 'qcPass'])
        ->name('lab-v2-orders.qc-pass')
        ->middleware('permission:pass_qc|manage_quality_control');
    Route::post('v2-orders/{labV2Order}/qc/fail', [LabV2OrderController::class, 'qcFail'])
        ->name('lab-v2-orders.qc-fail')
        ->middleware('permission:reject_qc|manage_quality_control');

    Route::post('v2-orders/{labV2Order}/external/dispatch', [LabV2OrderController::class, 'externalDispatch'])
        ->name('lab-v2-orders.external-dispatch')
        ->middleware('permission:manage_lab_orders');
    Route::post('v2-orders/{labV2Order}/external/sent', [LabV2OrderController::class, 'externalSent'])
        ->name('lab-v2-orders.external-sent')
        ->middleware('permission:manage_lab_orders');
    Route::post('v2-orders/{labV2Order}/external/in-progress', [LabV2OrderController::class, 'externalInProgress'])
        ->name('lab-v2-orders.external-in-progress')
        ->middleware('permission:manage_lab_orders');
    Route::post('v2-orders/{labV2Order}/external/returned', [LabV2OrderController::class, 'externalReturned'])
        ->name('lab-v2-orders.external-returned')
        ->middleware('permission:manage_lab_orders');
    Route::post('v2-orders/{labV2Order}/external/review', [LabV2OrderController::class, 'externalReview'])
        ->name('lab-v2-orders.external-review')
        ->middleware('permission:manage_lab_orders');

    // External lab master data (Admin Lab).
    Route::get('external-labs', [ExternalLabController::class, 'index'])
        ->name('lab-external-labs.index')
        ->middleware('permission:manage_lab_orders');
    Route::post('external-labs', [ExternalLabController::class, 'store'])
        ->name('lab-external-labs.store')
        ->middleware('permission:manage_lab_orders');

    // --- LAB-WORKFLOW-V2 Phase 4: delivery with mandatory proof gates ------
    // Policy-enforced per action; the photo/signature gates are re-verified in
    // LabDeliveryWorkflowService + the state machine (server-side).
    Route::middleware('permission:view_delivery|manage_delivery|manage_lab_orders')->group(function () {
        Route::get('delivery-tasks', [LabDeliveryTaskController::class, 'index'])
            ->name('lab-delivery-tasks.index');
        Route::get('delivery-tasks/{deliveryTask}', [LabDeliveryTaskController::class, 'show'])
            ->name('lab-delivery-tasks.show');
    });
    Route::post('v2-orders/{labV2Order}/delivery-task', [LabDeliveryTaskController::class, 'store'])
        ->name('lab-v2-orders.delivery-task.store')
        ->middleware('permission:create_delivery|manage_delivery|manage_lab_orders');
    Route::post('delivery-tasks/{deliveryTask}/accept', [LabDeliveryTaskController::class, 'accept'])
        ->name('lab-delivery-tasks.accept')
        ->middleware('permission:start_delivery|manage_delivery');
    Route::post('delivery-tasks/{deliveryTask}/handover', [LabDeliveryTaskController::class, 'submitHandover'])
        ->name('lab-delivery-tasks.handover')
        ->middleware('permission:start_delivery|manage_delivery');
    Route::post('delivery-tasks/{deliveryTask}/start-transit', [LabDeliveryTaskController::class, 'startTransit'])
        ->name('lab-delivery-tasks.start-transit')
        ->middleware('permission:start_delivery|manage_delivery');
    Route::post('delivery-tasks/{deliveryTask}/arrived', [LabDeliveryTaskController::class, 'markArrived'])
        ->name('lab-delivery-tasks.arrived')
        ->middleware('permission:start_delivery|manage_delivery');
    Route::post('delivery-tasks/{deliveryTask}/complete', [LabDeliveryTaskController::class, 'complete'])
        ->name('lab-delivery-tasks.complete')
        ->middleware('permission:mark_delivered|manage_delivery');

    // --- LAB-WORKFLOW-V2 Phase 8/9: read-only operational dashboard + SLA ---
    // Branch/role scope is enforced server-side inside the dashboard service.
    Route::get('operational-dashboard', [LabWorkflowOperationalDashboardController::class, 'index'])
        ->name('lab-workflow-dashboard.index')
        ->middleware('permission:view_lab_orders|manage_lab_orders');

    // --- LAB-PROD-2: Operational Analytics & KPI (read-only) ---
    // Tier resolved server-side (full management / own technician). Owner/Admin
    // Lab/Supervisor see all-branch analytics; a linked technician is forced to
    // their own data. No PII in any KPI/drilldown/export.
    Route::get('analytics/operational-kpi', [LabOperationalAnalyticsController::class, 'index'])
        ->name('lab-analytics.operational-kpi.index')
        ->middleware('permission:view_lab_operational_analytics|view_own_lab_operational_analytics|manage_lab_orders');
    Route::get('analytics/operational-kpi/export', [LabOperationalAnalyticsController::class, 'export'])
        ->name('lab-analytics.operational-kpi.export')
        ->middleware('permission:view_lab_operational_analytics|view_own_lab_operational_analytics|manage_lab_orders');

    // --- LAB-PROD-3: Technician Capacity Planning (read-only decision-support) ---
    // Tier resolved server-side (full / own technician). Owner/Admin Lab see all;
    // a linked technician is forced to own data. No PII. No auto-assignment.
    Route::get('capacity-planning', [LabTechnicianCapacityController::class, 'index'])
        ->name('lab-capacity-planning.index')
        ->middleware('permission:view_lab_technician_capacity|view_own_lab_technician_capacity|manage_lab_technician_capacity');
    Route::get('capacity-planning/export', [LabTechnicianCapacityController::class, 'export'])
        ->name('lab-capacity-planning.export')
        ->middleware('permission:view_lab_technician_capacity|view_own_lab_technician_capacity|manage_lab_technician_capacity');

    // Capacity configuration management (manage only).
    Route::middleware('permission:manage_lab_technician_capacity')->group(function () {
        Route::get('capacity-planning/configuration', [LabCapacityConfigController::class, 'index'])
            ->name('lab-capacity-planning.configuration');
        Route::post('capacity-planning/capacity-profiles', [LabCapacityConfigController::class, 'storeCapacityProfile'])
            ->name('lab-capacity-planning.capacity-profiles.store');
        Route::put('capacity-planning/capacity-profiles/{capacityProfile}', [LabCapacityConfigController::class, 'updateCapacityProfile'])
            ->name('lab-capacity-planning.capacity-profiles.update');
        Route::delete('capacity-planning/capacity-profiles/{capacityProfile}', [LabCapacityConfigController::class, 'deactivateCapacityProfile'])
            ->name('lab-capacity-planning.capacity-profiles.deactivate');
        Route::post('capacity-planning/workload-profiles', [LabCapacityConfigController::class, 'storeWorkloadProfile'])
            ->name('lab-capacity-planning.workload-profiles.store');
        Route::put('capacity-planning/workload-profiles/{workloadProfile}', [LabCapacityConfigController::class, 'updateWorkloadProfile'])
            ->name('lab-capacity-planning.workload-profiles.update');
        Route::delete('capacity-planning/workload-profiles/{workloadProfile}', [LabCapacityConfigController::class, 'deactivateWorkloadProfile'])
            ->name('lab-capacity-planning.workload-profiles.deactivate');
        Route::post('capacity-planning/capabilities', [LabCapacityConfigController::class, 'storeCapability'])
            ->name('lab-capacity-planning.capabilities.store');
        Route::delete('capacity-planning/capabilities/{capability}', [LabCapacityConfigController::class, 'removeCapability'])
            ->name('lab-capacity-planning.capabilities.remove');
        Route::post('capacity-planning/availability-overrides', [LabCapacityConfigController::class, 'storeAvailabilityOverride'])
            ->name('lab-capacity-planning.availability.store');
        Route::delete('capacity-planning/availability-overrides/{availabilityOverride}', [LabCapacityConfigController::class, 'removeAvailabilityOverride'])
            ->name('lab-capacity-planning.availability.remove');
    });
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
    Route::get('reports/batch-disposals', [InventoryBatchDisposalReportController::class, 'index'])->name('reports.batch-disposals.index');
    Route::get('reports/batch-disposals/export', [InventoryBatchDisposalReportController::class, 'export'])->name('reports.batch-disposals.export');
    Route::get('reports/batch-disposals/print', [InventoryBatchDisposalReportController::class, 'print'])->name('reports.batch-disposals.print');
    Route::get('reports/batch-monthly-closing', [InventoryBatchMonthlyClosingPackController::class, 'index'])->name('reports.batch-monthly-closing.index');
    Route::get('reports/batch-monthly-closing/export', [InventoryBatchMonthlyClosingPackController::class, 'export'])->name('reports.batch-monthly-closing.export');
    Route::get('reports/batch-monthly-closing/print', [InventoryBatchMonthlyClosingPackController::class, 'print'])->name('reports.batch-monthly-closing.print');
    Route::get('reports/room-stock/refill-checklist', [InventoryReportController::class, 'downloadRoomStockRefillChecklist'])->name('reports.room-stock.refill-checklist');

    Route::get('stock', [InventoryStockController::class, 'index'])->name('stock.index');

    Route::get('batches', [InventoryBatchController::class, 'index'])->name('batches.index');
    Route::get('batches/{inventoryBatch}', [InventoryBatchController::class, 'show'])->name('batches.show');
    Route::post('batches/{inventoryBatch}/action-logs', [InventoryBatchActionLogController::class, 'store'])->name('batches.action-logs.store');
    Route::post('batches/{inventoryBatch}/disposal-requests', [InventoryBatchDisposalRequestController::class, 'store'])->name('batches.disposal-requests.store');

    Route::get('batch-disposal-requests', [InventoryBatchDisposalRequestController::class, 'index'])->name('batch-disposal-requests.index');
    Route::get('batch-disposal-requests/{batchDisposalRequest}', [InventoryBatchDisposalRequestController::class, 'show'])->name('batch-disposal-requests.show');
    Route::post('batch-disposal-requests/{batchDisposalRequest}/approve', [InventoryBatchDisposalRequestController::class, 'approve'])->name('batch-disposal-requests.approve');
    Route::post('batch-disposal-requests/{batchDisposalRequest}/reject', [InventoryBatchDisposalRequestController::class, 'reject'])->name('batch-disposal-requests.reject');
    Route::post('batch-disposal-requests/{batchDisposalRequest}/finalize-adjustment', [InventoryBatchDisposalRequestController::class, 'finalizeAdjustment'])->name('batch-disposal-requests.finalize-adjustment');
    Route::post('batch-disposal-requests/{batchDisposalRequest}/cancel', [InventoryBatchDisposalRequestController::class, 'cancel'])->name('batch-disposal-requests.cancel');

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

    // FIX-PRE-68-45 Scope G — branch PR workflow board (Kepala Cabang → Admin
    // Warehouse). Registered BEFORE the resource so "workflow" is not captured as a
    // {purchaseRequest} id. Read-only board; PR-create-only for Kepala Cabang.
    Route::get('purchase-requests/workflow', [PurchaseRequestWorkflowController::class, 'index'])
        ->name('purchase-requests.workflow');
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
    Route::get('purchase-orders/{purchaseOrder}/supplier/{supplier}/pdf', [PurchaseOrderController::class, 'supplierPdf'])->name('purchase-orders.supplier-pdf');

    Route::resource('goods-receipts', GoodsReceiptController::class)->only(['index', 'create', 'store', 'show', 'edit', 'update']);
    Route::post('goods-receipts/{goodsReceipt}/submit', [GoodsReceiptController::class, 'submit'])->name('goods-receipts.submit');
    Route::post('goods-receipts/{goodsReceipt}/post', [GoodsReceiptController::class, 'post'])->name('goods-receipts.post');
    Route::post('goods-receipts/{goodsReceipt}/cancel', [GoodsReceiptController::class, 'cancel'])->name('goods-receipts.cancel');
    Route::post('goods-receipts/{goodsReceipt}/void', [GoodsReceiptController::class, 'void'])->name('goods-receipts.void');
});

/*
|--------------------------------------------------------------------------
| ENT-7 — Developer Assistance Console
|--------------------------------------------------------------------------
| Read-only, Super-Admin/permission-gated, audited, PII-masked. GET only —
| the console must never expose a mutating route (ENT7-DC001).
*/
if (config('developer_console.enabled', true)) {
    Route::middleware(['auth', 'permission:view_developer_console'])
        ->get('/dev-console', [DeveloperConsoleController::class, 'index'])
        ->name('developer-console.index');

    // UIX-1 component catalog — dev-only, read-only, same Super-Admin/permission gate.
    Route::middleware(['auth', 'permission:view_developer_console'])
        ->get('/dev/ui-catalog', fn () => view('dev.ui-catalog'))
        ->name('developer-console.ui-catalog');
}

/*
| MON-1 — Foundation Monitoring & Observability (read-only consolidation).
| GET only; reuses the ENT-7 Developer Console permission (Super Admin only via
| Gate::before) — no new permission. Surfaces a single GO/WATCH/FAIL/UNKNOWN
| decision across existing health/deploy/queue/storage/audit signals. Never
| mutates runtime state and never runs heavy audits on a web request.
*/
if (config('foundation_monitoring.enabled', true)) {
    Route::middleware(['auth', 'permission:'.config('foundation_monitoring.ui.permission', 'view_developer_console')])
        ->get('/foundation/monitoring', [FoundationMonitoringController::class, 'index'])
        ->name('foundation.monitoring.index');
}

/*
| ROLL-5-1 — Five Branch Controlled Production Rollout Readiness (read-only).
| GET only; reuses the ENT-7 Developer Console permission (Super Admin only via
| Gate::before) — no new permission. Surfaces one GO/WATCH/FAIL/UNKNOWN decision
| plus per-stage (1 -> 3 -> 5 branch) readiness across app health, branch/role,
| RME/cashier/inventory, backup, restore-drill, monitoring, and deploy signals.
| Never mutates runtime state; never runs heavy audits or the capacity smoke on
| a web request. Certifies CONTROLLED 5-branch rollout only, not national scale.
*/
if (config('rollout_readiness.enabled', true)) {
    Route::middleware(['auth', 'permission:'.config('rollout_readiness.ui.permission', 'view_developer_console')])
        ->get('/foundation/rollout/five-branch-readiness', [FiveBranchRolloutReadinessController::class, 'index'])
        ->name('foundation.rollout.five-branch-readiness');
}

require __DIR__.'/auth.php';
