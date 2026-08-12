<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| LEGACY-RME-PDF-ROLL-2 — controlled pilot enablement contract
|--------------------------------------------------------------------------
|
| LEGACY-RME-PDF-1A..1D delivered the runtime: schema and date rules, the
| private upload and Poppler rendering, the human review gate and the atomic
| publish into an immutable record, and finally VOID plus the doctor-facing
| read-only viewer with print/PDF export. LEGACY-RME-ROLL-1 made the feature
| flag's runtime override survive `config:cache`.
|
| What none of them answered is the operational question: is this deployment
| actually ready to be switched ON, and can it be switched back OFF again?
| That is what this file governs. It is a CONTRACT, not a set of toggles —
| every entry describes something the readiness gate must find already true.
|
| Two rules shape everything below:
|
|  1. The gate is READ-ONLY. It never enables the feature, never writes a
|     clinical row and never repairs what it finds broken. It reports.
|
|  2. The gate FAILS CLOSED. An unknown, an unreadable dependency or an
|     unapproved pilot scope is never treated as "probably fine".
|
| Enabling the feature is an explicit operator decision taken against a GREEN
| readiness report — it is never a side effect of a deploy.
*/

return [

    'sprint' => 'LEGACY-RME-PDF-ROLL-2',

    /*
    | The flag identifier is owned by config/legacy_rme.php. It is mirrored here
    | only so the readiness gate can cross-check that the two agree; a drift
    | between them would mean the gate is auditing a different switch from the
    | one the runtime actually reads.
    */
    'feature_flag' => 'rme.legacy_pdf_archive',

    /*
    | The last sprint that changed the Legacy RME runtime. The flag registry
    | carries a `review_target`, and a stale one is exactly how 1C's metadata
    | rotted: the code moved on and the governance record did not. The gate
    | compares the two so the drift is caught by a command instead of a reader.
    */
    'delivered_sprint' => 'LEGACY-RME-PDF-1D',

    /*
    | Every table the archive lifecycle touches. Staging carries the import
    | batch and its rendered pages; the published pair is the immutable
    | clinical evidence. A missing table means a migration never landed.
    */
    'required_tables' => [
        'stg_rme_legacy_imports',
        'stg_rme_legacy_import_pages',
        'trx_rme_legacy_records',
        'trx_rme_legacy_record_pages',
    ],

    /*
    | The named permissions that ARE the authorization boundary for the
    | archive. Five govern the import lifecycle (1A) and one governs the
    | doctor-facing read surface (1D). They must exist before the feature is
    | switched on, otherwise the operator lands on a 403 instead of a workflow.
    */
    'required_permissions' => [
        'view_legacy_rme_imports',
        'create_legacy_rme_imports',
        'review_legacy_rme_imports',
        'publish_legacy_rme_imports',
        'void_legacy_rme_imports',
        'view_legacy_rme_archive',
    ],

    /*
    | Route names, split by surface, because they fail for different reasons.
    | The operator surface lives under Master Data → Master Data RME; the
    | clinical surface is the read-only archive a doctor reaches from the
    | patient's RME history. A missing name usually means a stale route cache.
    */
    'required_routes' => [
        'operator' => [
            'settings.rme.legacy-imports.index',
            'settings.rme.legacy-imports.create',
            'settings.rme.legacy-imports.store',
            'settings.rme.legacy-imports.show',
            'settings.rme.legacy-imports.status',
            'settings.rme.legacy-imports.source',
            'settings.rme.legacy-imports.pages.show',
            'settings.rme.legacy-imports.retry',
            'settings.rme.legacy-imports.cancel',
            'settings.rme.legacy-imports.review',
            'settings.rme.legacy-imports.publish',
        ],
        'clinical' => [
            'rme.legacy-records.show',
            'rme.legacy-records.pages.show',
            'rme.legacy-records.source',
            'rme.legacy-records.print',
            'rme.legacy-records.export',
            'rme.legacy-records.void',
        ],
    ],

    /*
    | Poppler does the rasterization. Without it an import reaches PROCESSING
    | and dies there, so its absence has to be a hard readiness failure rather
    | than something discovered by a failed pilot import.
    */
    'required_binaries' => ['pdfinfo', 'pdftoppm'],

    /*
    | Rasterization ALWAYS runs in a queued job, never inside the HTTP request
    | (see config/legacy_rme.php `processing`). On a real deployment that means
    | a `sync` connection would run a multi-minute render inside the operator's
    | upload request, and no worker at all means the import never leaves
    | PROCESSING. Both are release blockers, not warnings.
    */
    'queue' => [
        'forbidden_connections' => ['sync'],
        // Environments where a real background worker is expected to exist.
        'worker_required_environments' => ['pilot', 'staging', 'production'],

        /*
        | LEGACY-RME-PDF-ROLL-2 pilot finding. Checking only that the queue
        | CONNECTION is not `sync` is not enough: rasterization is dispatched to
        | a DEDICATED queue, and a worker that does not consume that queue
        | leaves every import stuck at QUEUED forever with no failed job and no
        | error to notice. That is exactly what happened on the first pilot
        | upload — the readiness gate reported GO while the pipeline could not
        | render at all.
        |
        | So the gate now reads the deployed worker unit and asserts it actually
        | consumes the queue the job is dispatched to. The unit is tracked in
        | this repository, which makes the assertion real rather than a config
        | agreeing with itself.
        */
        'worker_unit_file' => 'deploy/systemd/daengtisiams-queue-worker.service',

        // The unit systemd ACTUALLY runs. The deploy never installs or starts a
        // worker (ENT-5), so this can lag the tracked file — an operator who
        // edits the tracked unit, deploys and restarts still runs the OLD one.
        // The gate reads this first and only falls back to the tracked file
        // when it is absent (local, CI, a host without the unit installed).
        'installed_worker_unit_file' => env(
            'LEGACY_RME_INSTALLED_WORKER_UNIT',
            '/etc/systemd/system/daengtisiams-queue-worker.service',
        ),
    ],

    /*
    | The private disk holds clinical evidence. It must be private, it must not
    | be framework-served, and it must never be one of the disks that can hand
    | out a public URL.
    */
    'storage' => [
        'expected_visibility' => 'private',
        'expected_serve' => false,
        'forbidden_disks' => ['public', 's3'],
        // Probe prefix for the non-destructive writability check. Nothing
        // clinical is ever written here and the probe removes what it writes.
        'probe_prefix' => 'rollout-readiness',
    ],

    /*
    | Rollback must be provable BEFORE the feature is switched on, never
    | discovered afterwards. The runbook is part of the contract: an operator
    | who has to improvise the OFF path under pressure does not have a rollback.
    */
    'rollback' => [
        'runbook' => 'docs/runbooks/legacy-rme-pdf-rollout-runbook.md',
        'require_documented_rollback_action' => true,
    ],

    /*
    | PILOT SCOPE — the gate nobody may bypass.
    |
    | A controlled pilot runs against a patient and a historical document that
    | a human explicitly authorized. There is deliberately NO default here: an
    | unset approval means "not approved", which fails the gate. The runtime
    | must never be able to infer authorization from the mere fact that the
    | code shipped, that a Super Admin exists, or that a previous sprint got a
    | GO tag.
    |
    | Note what is NOT stored: no patient identifier, no document path, no name.
    | Patient ownership and the historical-date rule are enforced per import by
    | the 1A date-rule service and the 1C publish revalidation, which is where
    | a per-patient decision belongs. This config only records THAT an approval
    | exists and which branch it covers.
    */
    'pilot_scope' => [
        'approved' => (bool) env('LEGACY_RME_PILOT_APPROVED', false),

        // A non-PHI governance reference for the approval (ticket, decision id,
        // or runbook section). Never a patient name or number.
        'approval_reference' => (string) env('LEGACY_RME_PILOT_APPROVAL_REFERENCE', ''),

        // Branch code the pilot is confined to, e.g. TKM1. MAIN is never a
        // clinic pilot branch and a non-RME branch cannot hold RME history.
        //
        // LEGACY-RME-PDF-FIX-ROLL2-1: the branch an archive actually lands in
        // is DERIVED from the branch code in the patient's Nomor RM and cannot
        // be overridden by the operator. This value records which branch the
        // owner APPROVED, so it must match the RM-derived branch of the
        // patients in scope — otherwise the gate would report an approval for
        // one branch while imports land in another.
        'branch_code' => (string) env('LEGACY_RME_PILOT_BRANCH_CODE', ''),

        'forbidden_branch_codes' => ['MAIN'],
    ],
];
