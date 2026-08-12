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
|
|--------------------------------------------------------------------------
| LEGACY-RME-PDF-ROLL-3 — controlled multi-branch migration rollout
|--------------------------------------------------------------------------
|
| ROLL-2 proved ONE branch could migrate safely. It did so with a single
| `pilot_scope.branch_code` string that only the readiness REPORT ever read —
| no runtime path consulted it. With the flag ON, every RME-enabled branch was
| therefore free to import, and single-branch confinement rested on operator
| discipline rather than on the server.
|
| ROLL-3 closes that gap by separating two concerns that ROLL-2 conflated:
|
|   CAPABILITY  — is the legacy archive runtime switched on at all?
|                 Owned by the feature flag `rme.legacy_pdf_archive`.
|
|   ADMISSION   — is THIS branch cleared to start new migration work?
|                 Owned by the `admission` block below.
|
| Both must pass. Capability ON with no admitted branch imports nothing.
|
| TWO SEPARATE OPERATIONAL CONTROLS — do not confuse them:
|
|   NORMAL DRAIN (this block). Removing a branch code from the allowlist stops
|   NEW ingestion for that branch. Imports already staged and human-reviewed
|   keep their existing lifecycle and may still be published — publish runs the
|   full canonical revalidation (permission, patient, RM-derived branch, date
|   range, native boundary, state machine) every time. Nothing is deleted and
|   no queued job is corrupted. This is the routine wave-rollback control.
|
|   EMERGENCY STOP. If an incident requires ALL legacy mutations to cease at
|   once, use the global capability switch (feature flag OFF) — that is the
|   emergency control, and it withdraws publish too. Draining a branch is NOT
|   an incident-containment mechanism and must never be used as one.
|
| The allowlist is normalized ONCE, here, at config-build time (the ROLL-1
| capture rule: `env()` is read only while this file is built, so the value
| survives `config:cache`; nothing reads the environment at runtime).
*/

/*
| Normalize the declared allowlist into exact, canonical, upper-case branch-code
| tokens. Splitting happens here and nowhere else, so no service ever re-parses
| an operator string.
|
| Matching is EXACT-TOKEN by construction: `TKM1` admits `TKM1` and nothing
| else. `TKM` does not admit `TKM1`, and `TKM1-EXTRA` is a different token that
| admits neither — a substring or prefix must never widen a clinical rollout.
|
| An unset or blank declaration yields an EMPTY list, which admits no branch at
| all. That is the documented fail-closed default: capability without admission
| migrates nothing.
*/
$legacyRmeNormalizeBranchCodes = static function (string $declared): array {
    return array_values(array_unique(array_filter(
        array_map(
            static fn (string $code): string => strtoupper(trim($code)),
            preg_split('/[\s,;]+/', $declared) ?: [],
        ),
        static fn (string $code): bool => $code !== '',
    )));
};

$legacyRmeAdmittedBranchCodes = $legacyRmeNormalizeBranchCodes(
    (string) env('LEGACY_RME_ADMITTED_BRANCH_CODES', '')
);

/*
| The exact branch set the current approval covers. Normalized the same way, so
| the approved and admitted sets are compared as canonical tokens.
*/
$legacyRmeApprovedBranchCodes = $legacyRmeNormalizeBranchCodes(
    (string) env('LEGACY_RME_ADMISSION_APPROVED_BRANCH_CODES', '')
);

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

    /*
    | LEGACY-RME-PDF-ROLL-3 — BRANCH ADMISSION.
    |
    | The server-side answer to "may THIS branch start new migration work?".
    | Enforced in the ingestion path, not merely reported: see
    | App\Modules\LegacyRme\Services\LegacyRmeBranchAdmissionService.
    |
    | The value checked is ALWAYS the branch DERIVED from the patient's Nomor RM
    | (FIX-ROLL2-1). A request body, a query string, a session, a BranchContext
    | selection or an operator's own branch can never influence admission — if
    | they could, admission would be advisory again, which is the exact defect
    | ROLL-3 exists to remove.
    */
    'admission' => [
        /*
        | Enforcement is ON by default and stays on. The switch exists so a
        | local or CI environment can exercise the pre-ROLL-3 path in a test,
        | never so a production deployment can opt out of the gate. Turning it
        | off in a `worker_required_environments` deployment is reported by the
        | readiness gate as a FAIL, not a warning.
        */
        'enforced' => (bool) env('LEGACY_RME_ADMISSION_ENFORCED', true),

        /*
        | Exact canonical branch-code tokens cleared for new ingestion,
        | normalized above at config-build time. EMPTY = no branch admitted.
        */
        'admitted_branch_codes' => $legacyRmeAdmittedBranchCodes,

        /*
        | A non-PHI label for the wave these branches belong to (for example
        | `WAVE-1`). It is recorded in evidence and audit context so an import
        | can be attributed to the rollout stage that authorized it. Never a
        | patient identifier.
        */
        'wave' => strtoupper(trim((string) env('LEGACY_RME_WAVE', ''))),

        /*
        | THE WAVE'S OWN APPROVAL RECORD.
        |
        | ROLL-2's `pilot_scope` records a SINGLE approved branch. A ROLL-3 wave
        | admits several, so pilot_scope cannot describe it: admitting three
        | branches while the approval record still named ROLL-2's one branch
        | produced a GREEN readiness report that misdescribed what was actually
        | authorized — the exact "config nobody enforces" failure ROLL-3 exists
        | to remove, resurfacing one level up.
        |
        | So an admitted set now requires its own non-PHI governance reference
        | (a ticket or decision id, never a patient identifier). Admitting a
        | branch without one FAILS the readiness gate AND is refused at runtime:
        | an unrecorded approval is not an approval.
        |
        | ROLL-2's pilot_scope is left untouched as the historical record of the
        | single-branch pilot; the two are deliberately separate.
        */
        'approval_reference' => trim((string) env('LEGACY_RME_ADMISSION_APPROVAL_REFERENCE', '')),

        /*
        | THE SCOPE THAT APPROVAL COVERS.
        |
        | A reference alone would only prove that SOME approval exists, not that
        | it covers the branches currently admitted — so adding a fourth branch
        | to a three-branch wave would inherit the old approval untouched. The
        | approved set is therefore recorded explicitly, and every admitted
        | branch must appear in it. Widening the admitted set without widening
        | the approval fails closed, at runtime and in the readiness gate.
        */
        'approved_branch_codes' => $legacyRmeApprovedBranchCodes,

        /*
        | MAIN is an administrative branch, never a clinic that owns RME
        | history. It can never be admitted, even if it is declared.
        */
        'forbidden_branch_codes' => ['MAIN'],
    ],

    /*
    | LEGACY-RME-PDF-ROLL-3 — INGESTION CAPACITY (backpressure).
    |
    | Multi-branch migration multiplies render load on ONE worker. Without a
    | ceiling, several branches uploading at once can grow the queue faster
    | than Poppler can drain it, and a full disk during rasterization is a
    | far worse failure than a refused upload.
    |
    | So admission ALSO closes when the pipeline is saturated. This throttles
    | NEW ingestion only: work already queued keeps running to completion, and
    | no in-flight job is ever cancelled to free capacity.
    |
    | A threshold of 0 disables that individual probe.
    */
    'capacity' => [
        'enforced' => (bool) env('LEGACY_RME_CAPACITY_ENFORCED', true),

        // Pending jobs on the dedicated render queue before new uploads are
        // refused. Sized for a single worker draining one document at a time.
        'max_pending_jobs' => (int) env('LEGACY_RME_MAX_PENDING_JOBS', 25),

        // Age of the OLDEST pending job, in seconds. A queue that is not
        // draining is a stalled worker, and piling more onto it hides the
        // stall instead of surfacing it.
        'max_oldest_pending_seconds' => (int) env('LEGACY_RME_MAX_OLDEST_PENDING_SECONDS', 1800),

        // Free space the private disk must retain. Rendering a large document
        // can add hundreds of MiB, so ingestion stops well before exhaustion.
        'min_free_disk_bytes' => (int) env('LEGACY_RME_MIN_FREE_DISK_BYTES', 2147483648), // 2 GiB
    ],
];
