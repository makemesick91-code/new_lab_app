<?php

/*
|--------------------------------------------------------------------------
| Half-B (global doctor device/browser enforcement) readiness
|--------------------------------------------------------------------------
|
| DOCTOR-ACCESS-GLOBAL-DEVICE-ENFORCEMENT-READINESS-1.
|
| WHY THIS FILE IS NOT PART OF config/android_release.php
|
| Same bright line that put `doctor_device_enforcement.php` in its own file:
| `android_release.php` is the governance record and the release suite asserts
| that it never reads the environment. This file holds the inputs a MEASUREMENT
| needs — where rehearsal evidence lives, how stale it may be — and it is read
| by the readiness engine, never by the release readiness gate.
|
| NOTHING HERE ENABLES ANYTHING.
|
| There is no flag in this file, no cohort, no permission and no path by which
| global enforcement could be switched on. Half B is armed by
| `android_release.enforcement.scope.global_permitted` (a source-controlled
| false), a governance-phase move, and the enforcement flag — none of which are
| reachable from here, and the readiness test asserts this file never grows one.
|
| WHAT THE ENGINE THIS FILE FEEDS IS FOR
|
| `global_prerequisites_attested` asks whether somebody SIGNED for each of the
| five Half-B prerequisites. It reads no measurement, so a signature recorded
| against an untrue fact passes it. The engine this file configures measures
| the same five, so a signature can be CONTRADICTED. Measurement wins.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | device_loss_runbook_rehearsed
    |--------------------------------------------------------------------------
    |
    | "The device-loss runbook has been rehearsed" is a fact about an afternoon
    | in a clinic, and no query establishes it. It is therefore evidenced the
    | way ROLL-5-1A evidences a restore drill: a canonical artifact written by
    | whoever ran the rehearsal, validated here, and honestly UNVERIFIED when
    | absent.
    |
    | ABSENT IS NOT FAIL AND IT IS NOT PASS. Nobody having rehearsed yet is the
    | ordinary state of a programme that has not reached this rung; reporting
    | it FAIL would redden a gate for months, which is how gates get deleted.
    | It is UNVERIFIED — which still CONTRADICTS a signature recorded `true`,
    | and that is the property that matters.
    |
    */
    'device_loss_rehearsal' => [

        'evidence_path' => 'readiness/device-loss-drills/latest.json',

        'schema_version' => 1,

        /*
        | Every key a rehearsal record must carry to be a record rather than a
        | note. `outcome` and `device_recovered` are separate deliberately: a
        | rehearsal that ran to completion and FAILED is evidence, and it is
        | evidence of the opposite thing.
        */
        'required_keys' => [
            'schema_version',
            'drill_id',
            'environment',
            'performed_at',
            'runbook',
            'outcome',
            'clinician_regained_access',
        ],

        /*
        | A rehearsal from two years ago describes a fleet that no longer
        | exists. Past this age the evidence is reported UNVERIFIED with its
        | date, never silently honoured.
        */
        'max_age_days' => 365,

        /*
        | The outcome that counts. Anything else — including a value nobody
        | recognises — is not a pass, by allow-list rather than by exclusion.
        */
        'passing_outcome' => 'passed',

        /*
        | A placeholder written by `--create-template` is not a rehearsal.
        | ROLL-5-1A learned this one: a template that validates is a template
        | that gets signed.
        */
        'template_marker' => 'TEMPLATE',
    ],

    /*
    |--------------------------------------------------------------------------
    | real_device_pilot_passed
    |--------------------------------------------------------------------------
    |
    | Measured from the fleet engine, over the CURRENT pilot cohort — the
    | doctors this deployment actually enforces today. Naming a count here
    | instead would be the mistake rule 156 FR-R2 was restated to avoid: the
    | authorization target has already moved 45 -> 60 once, and a number in
    | config outlives the estate it described.
    |
    | An EMPTY cohort is UNVERIFIED, never PASS. A pilot nobody is in has not
    | passed; it has not run.
    |
    */
    'pilot' => [
        'minimum_cohort_size' => 1,
    ],

];
