<?php

/*
|--------------------------------------------------------------------------
| SATUSEHAT-4B — structured diagnosis adoption & clinical terminology rollout
|--------------------------------------------------------------------------
| Canonical registry for the branch-scoped structured diagnosis rollout modes,
| the emergency-override policy, and the terminology-audit expectations.
|
| Everything here is credential-independent and internal-only: nothing in this
| file enables an external SATUSEHAT request (that stays governed by
| config/satusehat.php — SATUSEHAT_ENABLED / SATUSEHAT_SEND_ENABLED, both OFF —
| and the SATUSEHAT-3 production activation guard).
|
| SAFETY INVARIANTS
|  - There is NO global enforcement switch. Enforcement (pilot_enforced) can
|    only be configured branch-by-branch, with a reason, by a user holding
|    configure_diagnosis_rollout, and every change is audited.
|  - The config default applies to branches WITHOUT an explicit row and must
|    stay a non-blocking mode (disabled|informational|warning).
*/

return [

    /*
    | Rollout modes, weakest → strongest. pilot_enforced blocks RME
    | finalization server-side until an active primary structured diagnosis
    | exists (or a reasoned, audited emergency override is granted).
    */
    'modes' => [
        'disabled',
        'informational',
        'warning',
        'pilot_enforced',
    ],

    /*
    | Default mode for branches without an explicit setting. MUST be
    | non-blocking; the rollout service refuses to treat a blocking default.
    */
    'default_mode' => env('CLINICAL_DIAGNOSIS_ROLLOUT_DEFAULT_MODE', 'informational'),

    /*
    | Emergency override policy (pilot_enforced branches only). Overrides are
    | append-only, reasoned, audited, time-boxed, and NEVER mark the SATUSEHAT
    | candidate ready — the missing-diagnosis issue stays open.
    */
    'override' => [
        'ttl_hours' => (int) env('CLINICAL_DIAGNOSIS_OVERRIDE_TTL_HOURS', 24),
        'min_reason_length' => 10,
    ],

    /*
    | Official code-format expectations per code system, used by the
    | diagnosis_code_invalid data-quality rule and satusehat:terminology-audit.
    | Unknown code systems are never guessed — they are simply not format-checked.
    */
    'code_patterns' => [
        'ICD-10' => '/^[A-TV-Z][0-9]{2}(\.[0-9A-Z]{1,4})?$/i',
    ],

    /*
    | Adoption analytics guardrails (bounded queries, PII-free output).
    */
    'adoption' => [
        'max_period_days' => 366,
        'max_doctor_rows' => 50,
        'max_branch_rows' => 50,
    ],
];
