<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| LEGACY-RME-PDF-ROLL-4 — production migration operations contract
|--------------------------------------------------------------------------
|
| ROLL-3 answered "may THIS branch start new migration work?" with a config
| allowlist plus the wave's own approval record. That is the ADMISSION gate and
| it is unchanged here — ROLL-4 never rewrites it, never widens it, and never
| substitutes for it.
|
| What ROLL-3 could not answer is the OPERATIONS question a real multi-branch
| migration raises the moment more than one person starts uploading:
|
|   WHO may migrate this branch?      → operator assignment
|   HOW MUCH may they migrate today?  → quota
|   Can we stop without losing work?  → pause / drain, per wave and per branch
|   Did anything go missing?          → reconciliation
|   Is this branch actually finished? → completion sign-off
|
| THE COMPOSITION RULE, AND IT IS THE WHOLE SAFETY ARGUMENT:
|
|   ROLL-4 CAN ONLY NARROW. NEVER WIDEN.
|
| The ingestion path evaluates ROLL-3's capability + admission + capacity gates
| FIRST and unchanged. Only if all of them admit does the ROLL-4 layer get to
| speak, and the only thing it can say is "no". There is no ROLL-4 decision that
| turns a ROLL-3 denial into an acceptance. A reader auditing whether ROLL-4
| weakened the rollout only has to check that one property.
|
| THE LAYER IS REQUIRED, NOT OPTIONAL. When the capability is ON and at least
| one branch is admitted, a matching ACTIVE wave record must exist or ingestion
| is refused with WAVE_NOT_REGISTERED. Making the layer opt-in would repeat the
| exact defect ROLL-3 was written to remove: a control that only applies when
| the operator remembers to apply it is not a control.
|
| PII POLICY. Everything in this file, everything the operations services
| return, and everything the dashboard renders is counts, codes, branch labels
| and timings. Never a patient name, a Nomor RM, a KTP/NIK, a filename or a
| document path.
*/

/*
| Read an optional integer environment value at CONFIG-BUILD time (the ROLL-1
| capture rule: env() is read only while this file is built, so the value
| survives `config:cache` and nothing reads the environment at runtime).
|
| An unset or blank value yields NULL — "no ceiling declared" — which is
| deliberately different from 0, a ceiling that admits nothing. A closure rather
| than a named function because a config file is re-required on every app boot,
| and a global function declaration would fatal on the second one.
*/
$legacyRmeOptionalInt = static function (mixed $value): ?int {
    if ($value === null || $value === '' || $value === false) {
        return null;
    }

    return (int) $value;
};

return [

    'sprint' => 'LEGACY-RME-PDF-ROLL-4',

    /*
    | Enforcement of the ROLL-4 operations layer.
    |
    | Like ROLL-3's `admission.enforced`, this switch exists so a local or CI
    | test can exercise the pre-ROLL-4 path, never so a production deployment
    | can opt out. Turning it off where a real worker is expected is reported by
    | the readiness gate as a FAIL, not a warning.
    */
    'enforced' => (bool) env('LEGACY_RME_OPERATIONS_ENFORCED', true),

    /*
    | Quota — the ceiling on NEW accepted documents.
    |
    | WHAT COUNTS. One unit is consumed when a document is ACCEPTED INTO STAGING
    | — the same transaction that creates the staging row. Deliberately not the
    | client's attempt: a refused upload, a failed validation, a duplicate or a
    | rolled-back transaction consumes nothing, because the counter increment
    | lives inside the same transaction as the row it is counting and dies with
    | it.
    |
    | WHAT DOES NOT COUNT. A retry re-queues render work for a document that was
    | already accepted and already counted. Charging it again would mean a
    | branch that hit a transient Poppler failure silently lost quota it never
    | used. Render load is governed by ROLL-3's capacity backpressure, which is
    | the gate that actually protects the worker.
    |
    | NULL means "no ceiling declared". That is not the same as zero: zero
    | admits nothing, NULL declines to limit. A wave or branch row may override
    | either value; the branch override wins over the wave default.
    */
    'quota' => [
        // Wave-wide documents per day, across every branch in the wave.
        'default_wave_daily' => $legacyRmeOptionalInt(env('LEGACY_RME_WAVE_DAILY_QUOTA')),

        // Documents per day for ONE branch in the wave.
        'default_branch_daily' => $legacyRmeOptionalInt(env('LEGACY_RME_BRANCH_DAILY_QUOTA')),

        /*
        | Upper bound an operator may declare through the UI or the CLI. A quota
        | is a safety rail; letting someone type 1,000,000 into it turns the
        | rail into decoration. Refused server-side, not merely absent from the
        | form.
        */
        'max_declarable_daily' => (int) env('LEGACY_RME_MAX_DECLARABLE_DAILY_QUOTA', 500),
    ],

    /*
    | Governance separation of duties.
    |
    | ASSESSED RISK, RECORDED DELIBERATELY. With this off, one holder of
    | `manage_legacy_rme_migration_operations` plus
    | `approve_legacy_rme_migration_wave` can create, approve and activate a
    | wave alone. That is a real concentration of authority and it is accepted
    | for the pilot for one reason: the wave still cannot admit a branch by
    | itself. Admission remains ROLL-3's config allowlist plus the owner's
    | approval reference, which is a deploy-time change on the server, outside
    | this application's write path. A lone operator can therefore shape a wave
    | but cannot open a branch the owner did not already approve.
    |
    | Turn this on once two staffed accounts exist; the approver-is-not-creator
    | rule is enforced server-side, not by hiding a button.
    */
    'require_separate_approver' => (bool) env('LEGACY_RME_REQUIRE_SEPARATE_APPROVER', false),

    /*
    | Operational thresholds for the read-only dashboard and the reconciliation
    | report. These change what is REPORTED, never what is permitted — nothing
    | here can admit or refuse a document.
    */
    'monitoring' => [
        // A staging row sitting in PROCESSING longer than this has almost
        // certainly lost its worker. It is SURFACED, never auto-mutated: a
        // status is clinical bookkeeping, and rewriting one from a clock is how
        // evidence quietly becomes wrong.
        'stale_processing_seconds' => (int) env('LEGACY_RME_STALE_PROCESSING_SECONDS', 3600),

        // How long a document may wait for a human reviewer before the wave is
        // reported as outrunning its reviewers.
        'review_backlog_warning_hours' => (int) env('LEGACY_RME_REVIEW_BACKLOG_WARNING_HOURS', 48),

        // Sample size for the per-wave QA pass. Deterministic (oldest first) so
        // two operators auditing the same wave audit the same documents.
        'qa_sample_size' => (int) env('LEGACY_RME_QA_SAMPLE_SIZE', 5),
    ],

    /*
    | Completion — what "this branch is finished" is allowed to mean.
    |
    | DOCUMENTATION OF INVARIANTS, NOT TOGGLES. Every one is enforced in
    | LegacyRmeWaveGovernanceService and asserted by tests. An empty queue is
    | not completion; neither is `failed_jobs = 0`. Completion is a
    | reconciliation that balances plus a human who signed for it.
    */
    'completion_invariants' => [
        // Nothing may still be moving through the pipeline.
        'requires_zero_in_flight' => true,

        // Every failure is either retried to success or explicitly cancelled.
        'requires_zero_unresolved_failures' => true,

        // Accepted must equal the sum of its terminal and in-flight parts. A
        // non-zero remainder means a status exists that this report does not
        // know about, and a migration must never be signed off on a count it
        // cannot explain.
        'requires_zero_unexplained' => true,

        // The quota ledger must agree with the documents actually accepted.
        'requires_zero_quota_drift' => true,

        // A human writes why, and that text is kept.
        'requires_signoff_note' => true,

        // A wave closes only when every enrolled branch is COMPLETED or was
        // explicitly CANCELLED through governance. Silently ignoring a branch
        // that never finished is exactly the outcome this exists to prevent.
        'wave_requires_all_branches_accounted' => true,
    ],

    /*
    | Minimum length of a governance free-text reason (pause, drain, cancel,
    | completion sign-off). Short enough not to be a chore, long enough that
    | "ok" is not an audit trail.
    */
    'min_reason_length' => (int) env('LEGACY_RME_MIN_REASON_LENGTH', 10),
];
