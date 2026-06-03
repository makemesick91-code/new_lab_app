<?php

use App\Http\Controllers\ProfileController;
use App\Modules\AccessControl\Controllers\PermissionController;
use App\Modules\AccessControl\Controllers\RoleController;
use App\Modules\Clinic\Controllers\ClinicController;
use App\Modules\Delivery\Controllers\DeliveryController;
use App\Modules\Doctor\Controllers\DoctorController;
use App\Modules\LabOrder\Controllers\AttachmentController;
use App\Modules\LabOrder\Controllers\LabOrderController;
use App\Modules\LabService\Controllers\LabServiceController;
use App\Modules\Patient\Controllers\PatientController;
use App\Modules\Production\Controllers\AssignmentController as ProductionAssignmentController;
use App\Modules\Production\Controllers\ProductionStepController;
use App\Modules\Production\Controllers\ProductionWorkflowController;
use App\Modules\Production\Controllers\WorkLogController;
use App\Modules\QualityControl\Controllers\ChecklistController as QcChecklistController;
use App\Modules\QualityControl\Controllers\QualityControlController;
use App\Modules\QualityControl\Controllers\RemakeController as QcRemakeController;
use App\Modules\Technician\Controllers\TechnicianController;
use App\Modules\User\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/dashboard', function () {
    return view('dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

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

require __DIR__.'/auth.php';
