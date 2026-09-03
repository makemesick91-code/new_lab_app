<?php

use App\Modules\DoctorDevice\Api\DoctorDeviceApiController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| FEATURE-DOCTOR-TRUSTED-ANDROID-DEVICE-LOCK-1 Phase 3 — device channel
|--------------------------------------------------------------------------
|
| The stateless API the Android Clinic App speaks. Deliberately NOT in
| routes/web.php: these endpoints must not carry a session cookie or CSRF
| token, because the caller is hardware proving possession of a key, not a
| logged-in human.
|
| NOTHING HERE AUTHENTICATES A DOCTOR. A successful proof means only "this is
| registered clinic hardware". Doctor login is untouched, and Phase 3
| enforcement is OFF.
|
| Every route is rate limited: enrolment and proof are the two surfaces an
| attacker would grind against.
*/

Route::prefix('v1')->name('device-api.v1.')->group(function () {
    // Pairing: an install asks to be enrolled and is handed a one-time code to
    // show the administrator. Tightest limit — this creates rows.
    Route::middleware('throttle:10,1')->group(function () {
        Route::post('enrollment/request', [DoctorDeviceApiController::class, 'requestEnrollment'])
            ->name('enrollment.request');
    });

    // Polling + proof. The enrolment uuid is the device's own unguessable
    // handle; it reveals only that device's own coarse state.
    Route::middleware('throttle:60,1')->group(function () {
        Route::get('enrollment/{uuid}/status', [DoctorDeviceApiController::class, 'enrollmentStatus'])
            ->name('enrollment.status');

        Route::post('challenge', [DoctorDeviceApiController::class, 'challenge'])
            ->name('challenge');

        Route::post('proof', [DoctorDeviceApiController::class, 'proof'])
            ->name('proof');
    });
});
