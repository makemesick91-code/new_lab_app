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

    /*
    | REVISION-DOCTOR-AUTO-DEVICE-APPROVAL-APP-ONLY-LOGIN-1 — doctor login.
    |
    | The Clinic App collects credentials natively and proves its key here.
    | NOTHING on this channel establishes a session: the only thing that can is
    | redeeming a ticket at `device-login/{ticket}`, and no ticket is minted
    | while enforcement is off.
    |
    | These use NAMED rate limiters (see AppServiceProvider), not `throttle:n,1`.
    | An anonymous throttle signature is derived from the domain and the IP, not
    | the URI, so every anonymously-throttled route shares one counter per caller
    | and the strictest limit governs them all. A strict inline limit here would
    | therefore have tightened enrolment, polling, challenge and proof to the
    | same budget — an outage for a clinic behind one NAT address, not a control.
    |
    | `doctor/login` is the one surface that both accepts a password and can
    | create a row an approver has to look at, so it is what a credential stuffer
    | would grind and what would be used to flood the approval inbox. Its own
    | bucket is the tight one.
    */
    Route::middleware('throttle:doctor-app-login-challenge')->group(function () {
        Route::post('doctor/challenge', [DoctorDeviceApiController::class, 'loginChallenge'])
            ->name('doctor.challenge');

        Route::get('doctor/authorization/{uuid}/status',
            [DoctorDeviceApiController::class, 'doctorAuthorizationStatus'])
            ->name('doctor.authorization.status');
    });

    Route::middleware('throttle:doctor-app-login')->group(function () {
        Route::post('doctor/login', [DoctorDeviceApiController::class, 'doctorLogin'])
            ->name('doctor.login');
    });
});
