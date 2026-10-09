<?php

/*
|--------------------------------------------------------------------------
| PHASE-1-PATIENT-KTP-CAMERA-OCR-SUPERVISED-PILOT — pilot scope
|--------------------------------------------------------------------------
|
| The capability flag `patient.ktp_camera_ocr` is GLOBAL: on its own it would
| turn KTP camera OCR on for every operator at every branch. This file is the
| second, server-side gate that confines it to a supervised pilot. BOTH must
| hold — see App\Modules\Patient\Services\KtpCameraOcrPilotGate, the only
| reader of these keys.
|
| There is deliberately NO "everyone" mode. An empty or invalid cohort covers
| NOBODY, so switching the flag on without a cohort enables nothing. Widening
| beyond a pilot is a separate, explicit decision and a code change.
|
| The per-deployment values (who, where, which tablet, when) come from the
| environment because user ids and device ids are not portable between
| environments. The CEILINGS below are committed and cannot be raised from the
| environment: a bound an operator can move on the host is not a bound.
|
*/

return [

    // Comma-separated users.id values of the approved operators.
    'operator_user_ids' => (string) env('PATIENT_KTP_OCR_PILOT_USER_IDS', ''),

    // Comma-separated branch codes (canonical or historical alias). Exactly one.
    'branch_codes' => (string) env('PATIENT_KTP_OCR_PILOT_BRANCH_CODES', ''),

    // Comma-separated mst_doctor_devices.id values of the approved clinic tablets.
    'device_ids' => (string) env('PATIENT_KTP_OCR_PILOT_DEVICE_IDS', ''),

    // Pilot period, inclusive, as Y-m-d on the CLINICAL calendar (Asia/Makassar).
    'starts_on' => env('PATIENT_KTP_OCR_PILOT_STARTS_ON'),
    'ends_on' => env('PATIENT_KTP_OCR_PILOT_ENDS_ON'),

    /*
     * Require the session to be bound to an approved clinic tablet through the
     * front-office trusted-device lock. FAILS SAFE: unset, blank, misspelled or
     * unparseable all mean TRUE. Only an explicit false/0/off/no disables it,
     * and disabling it must be a recorded owner decision.
     */
    //
    // env() already turns the literal "false" into boolean false, and
    // (string) false is '' — so the boolean must be checked BEFORE the string
    // cast, or an explicit owner "false" would be silently read as "unset".
    'require_bound_device' => (static function (mixed $value): bool {
        if ($value === false) {
            return false;
        }

        return ! in_array(strtolower(trim((string) $value)), ['false', '0', 'off', 'no'], true);
    })(env('PATIENT_KTP_OCR_PILOT_REQUIRE_BOUND_DEVICE')),

    // Committed ceilings — never read from the environment.
    'max_operators' => 5,
    'max_branches' => 1,
    'max_devices' => 3,
    'max_period_days' => 31,
];
