<?php

use App\Http\Controllers\ProfileController;
use App\Modules\AccessControl\Controllers\PermissionController;
use App\Modules\AccessControl\Controllers\RoleController;
use App\Modules\Clinic\Controllers\ClinicController;
use App\Modules\Doctor\Controllers\DoctorController;
use App\Modules\LabService\Controllers\LabServiceController;
use App\Modules\Patient\Controllers\PatientController;
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

require __DIR__.'/auth.php';
