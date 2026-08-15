<?php

declare(strict_types=1);

use App\Support\Clinical\ClinicalTimezone;

return [

    /*
    |--------------------------------------------------------------------------
    | Clinical calendar timezone — LEGACY-RME-DATE-TZ-1
    |--------------------------------------------------------------------------
    |
    | THE SINGLE AUTHORITY for "what clinical day is it?". Every clinical
    | eligibility rule resolves this through App\Support\Clinical\ClinicalClock
    | and nothing else — not config('app.timezone'), not the PHP/OS default
    | timezone, and never a browser, request, cookie or header value.
    |
    | NOT the storage clock. Technical instants (created_at, updated_at, queue
    | timestamps, audit event times, deployment logs) keep the application's
    | existing UTC architecture. `config/app.php` therefore stays 'UTC' on
    | purpose: an absolute moment and a clinical calendar day are two different
    | things, and this file only defines the second.
    |
    | FAIL CLOSED. An invalid or blank identifier throws
    | InvalidClinicalTimezoneException — it does NOT degrade to UTC. Silent
    | degradation is the exact defect this sprint closes: a production typo
    | such as "Asia/Makasar" must stop the clinical decision, not quietly move
    | the date boundary eight hours.
    |
    | IANA identifiers only. "WITA", "UTC+8", "GMT+8" and "+08:00" are rejected.
    |
    | ONE GLOBAL VALUE. Every branch DaengtisiaMS currently serves runs on this
    | wall clock. A per-branch clinical timezone is a separate product decision
    | and must not be introduced by inference.
    |
    */

    'timezone' => env(ClinicalTimezone::ENV_KEY, ClinicalTimezone::DEFAULT),

];
