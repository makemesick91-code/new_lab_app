<?php

/*
|--------------------------------------------------------------------------
| Front Office branch-device lock — policy and runtime scope
|--------------------------------------------------------------------------
|
| REVISION-FRONT-OFFICE-BRANCH-DEVICE-LOCK-1.
|
| WHY THE POLICY IS COMMITTED AND THE COHORT IS NOT.
|
| The cohort is a list of USER IDS, and user ids are not portable between this
| repository and production. A committed id points at whoever happens to hold it
| locally, which is the wrong person everywhere except by accident — the same
| reasoning `config/doctor_device_enforcement.php` records for the Android pilot
| doctor. So the committed cohort is EMPTY, and an empty cohort enforces for
| NOBODY.
|
| The POLICY, by contrast, is a scope ceiling and belongs under review:
|
|   - `max_cohort_size` is 4, because the owner approved exactly four accounts.
|     A fifth id added to the environment does not quietly widen the lock; it
|     makes the cohort OVERSIZED, and an oversized cohort fails CLOSED for the
|     armed accounts rather than silently admitting the extra one.
|   - `allowed_branch_codes` is the four canonical RME branch codes, verified
|     against production on 2026-09-21. `MAIN` is deliberately absent: it is not
|     RME-enabled and is the fallback a branchless account lands on.
|   - `role_wide_permitted` is a source-controlled FALSE. Denying login to every
|     Front Office account is a clinical-scale action — there are eight of them
|     and four are out of scope — and it is not reachable from the environment
|     by design.
|
| WHAT MAY NOT BE SET HERE: no credential, no key, no secret. Two non-secret
| values only — whether this deployment arms the lock at all, and which
| (account, branch) pairs are armed.
|
| ENVIRONMENT KEYS
|
|   FRONT_OFFICE_BRANCH_DEVICE_LOCK_COHORT
|       Comma-separated `<user_id>:<BRANCH_CODE>` pairs, e.g.
|       "29:SPN4,30:LDK2". Empty (the committed default) arms nobody. Parsing is
|       strict and fail-closed — see FrontOfficeBranchDeviceCohort.
|
| The ENFORCEMENT switch itself is NOT here: it is the feature flag
| `front_office.branch_device_lock`, so it inherits the governance registry,
| the risk level and the recorded rollback action that every other enforcement
| capability in this codebase is held to.
*/

return [
    'scope' => [
        /*
         * The armed (account, branch) pairs. Read ONLY through
         * FrontOfficeBranchDeviceCohort, never parsed at a call site.
         */
        'cohort' => (string) env('FRONT_OFFICE_BRANCH_DEVICE_LOCK_COHORT', ''),
    ],

    'policy' => [
        /*
         * The owner approved four accounts. This is the ceiling, not a hint:
         * exceeding it fails closed for everyone armed.
         */
        'max_cohort_size' => 4,

        /*
         * A cohort entry naming any other branch code is ACCOUNT_BRANCH_INVALID
         * and denies. Verified against production `mst_branches` on 2026-09-21:
         * TLK1=Cabang Telkomas(1), LDK2=Cabang Landak(2), ATG3=Cabang Antang(3),
         * SPN4=Cabang Sunu(5). Note TLK1 — not the retired `TKM1` alias — and
         * SPN4, not `SUN4`.
         */
        'allowed_branch_codes' => ['TLK1', 'LDK2', 'ATG3', 'SPN4'],

        /*
         * The lock applies only to holders of this role. A mistyped id that
         * lands on a doctor therefore does NOT device-lock that doctor: the
         * account falls out of scope instead, because widening this lock to
         * another role is exactly what it must never do.
         */
        'required_role' => 'Front Office',

        /*
         * Never role-wide. Changed only through review, and nothing reads it as
         * true today.
         */
        'role_wide_permitted' => false,
    ],
];
