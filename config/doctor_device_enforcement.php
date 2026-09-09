<?php

/*
|--------------------------------------------------------------------------
| Doctor device enforcement — runtime scope
|--------------------------------------------------------------------------
|
| PHASE4A-DOCTOR-ANDROID-PILOT-PREPARATION-1.
|
| WHY THIS FILE EXISTS SEPARATELY FROM config/android_release.php
|
| `android_release.php` is the governance record, and the release governance
| suite asserts that it never reads the environment. That is not fussiness: the
| readiness gate has to be safe to run on a fork pull request, and the way that
| property is held is a bright line rather than a per-key argument about which
| environment reads are harmless. So the POLICY lives there — which modes exist,
| what the default is, and whether fleet-wide denial is permitted at all — and
| the two values a HOST legitimately supplies live here.
|
| WHAT MAY AND MAY NOT BE SET HERE
|
| Two things, both non-secret: which scope mode this deployment is in, and which
| doctor the pilot is for. No credential, no token, no key, no path to key
| material. Nothing in this file is read by the release readiness gate.
|
| Notably ABSENT: fleet-wide permission. Denying browser login to every doctor in
| every branch is a clinical-scale action, and it is not reachable from here by
| design — `android_release.enforcement.scope.global_permitted` is a
| source-controlled false, changed only through review.
|
| WHY THE PILOT DOCTOR IS AN ENVIRONMENT VALUE AND NOT A COMMITTED ONE
|
| It is a user id, and user ids are not portable between this repository and
| production. A committed id would point at whoever happens to hold it locally,
| which is the wrong doctor everywhere except by accident. The committed value is
| therefore null, and a null target enforces for nobody.
|
*/

return [

    'scope' => [

        // 'pilot'    — enforcement applies only to `pilot.doctor_user_id`.
        // 'unscoped' — fleet-wide, and additionally requires the source-controlled
        //              `android_release.enforcement.scope.global_permitted`.
        //
        // Anything unrecognised covers nobody. Falls back to the policy default.
        'mode' => env(
            'ANDROID_DOCTOR_ENFORCEMENT_SCOPE_MODE',
            config('android_release.enforcement.scope.default_mode', 'pilot'),
        ),

        'pilot' => [
            // Set on the host at activation time, from an id verified to belong
            // to the approved pilot doctor. Null here, and null enforces nobody.
            'doctor_user_id' => env('ANDROID_PILOT_ENFORCEMENT_DOCTOR_USER_ID'),

            // DOCTOR-PWA-MULTI-DOCTOR-PILOT-1 — the cohort, as a comma-separated
            // list of user ids.
            //
            // The singular key above still works and still means what it always
            // meant. This is deliberate rather than tidy: production is running
            // a live pilot whose scope is set by that variable, and a rename
            // would have made deploying this change an act that altered who is
            // enforced. The two are UNIONED, so a deployment that sets only the
            // old one resolves to exactly the cohort it resolved to before, and
            // shipping the cohort mechanism changes nobody's access.
            //
            // Parsing is strict and fails CLOSED as a whole: one unusable entry
            // voids the entire cohort rather than being dropped from it. A
            // dropped entry would leave a doctor silently unenforced behind a
            // list that still looks right; a voided cohort covers nobody and
            // trips `armed_but_covers_nobody`, which is loud. The ceiling on
            // how many ids may appear here is NOT in this file — it is
            // `android_release.enforcement.scope.pilot_cohort_maximum`, because
            // a bound an operator can raise is not a bound.
            'doctor_user_ids' => (string) env('ANDROID_PILOT_ENFORCEMENT_DOCTOR_USER_IDS', ''),

            // Advisory: it appears in the readiness report and the audit trail so
            // an operator can see which branch was intended. It is NOT an
            // authorization input. The authority on which branch a doctor is
            // working in is BranchContext, never this string and never a request.
            'branch_code' => (string) env('ANDROID_PILOT_ENFORCEMENT_BRANCH_CODE', ''),
        ],
    ],

];
