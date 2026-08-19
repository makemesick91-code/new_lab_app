<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| LEGACY-RME-STEADY-STATE-OPS-1 — steady-state operating contract
|--------------------------------------------------------------------------
|
| WHAT CHANGED, AND WHAT DID NOT.
|
| ROLL-1..ROLL-4, OPS-CLI-1, SOD-1, MASTERDATA-1 and SOURCE-RM-BINDING-1 built
| every CONTROL a legacy migration needs: capability, admission, capacity,
| quota, operator assignment, pause/drain, reconciliation, completion sign-off,
| source-RM binding and separation of duties. This file adds NO new control and
| weakens none of them. Every gate that could refuse a document before this
| sprint still refuses it, in the same place, for the same reason.
|
| What was missing was not a control. It was an OPERATING MODEL. Each batch was
| run as a bespoke engineering wave: an operator had to run three or four
| commands, correlate them by eye, and know from a 509-line runbook which order
| they went in. That is workable for a sprint and unworkable as routine work.
|
| So this file declares the steady-state operating contract:
|
|   What is a routine batch?        → `routine_batch`
|   How big may it be?              → `batch_sizing`
|   What is "nothing is running"?   → `resting_state`
|   Can two branches run at once?   → `multi_branch`
|   When must we have a backup?     → `backup`
|   What must stop the line?        → `stop_the_line`
|   How loud is a given finding?    → `severity`
|
| READ-ONLY BY CONSTRUCTION. Nothing here can admit a document, widen a quota,
| open a branch or bypass a gate. Every value is consumed by a REPORTING
| service (LegacyRmeSteadyStateOpsService) and by documentation. If a value in
| this file were deleted, no document would become admissible that was not
| admissible before — it would only become harder to SEE that it was.
|
| PII POLICY, inherited unchanged from ROLL-4: counts, codes, branch labels and
| timings. Never a patient name, a Nomor RM, a KTP/NIK, a filename or a
| document path.
*/

return [

    'sprint' => 'LEGACY-RME-STEADY-STATE-OPS-1',

    /*
    | ROUTINE BATCH — the unit of steady-state work.
    |
    | A routine batch is the SAME underlying wave record ROLL-4 already governs
    | (`ops_rme_legacy_migration_waves`). It is deliberately not a new table, a
    | new state machine or a new lifecycle: re-implementing those would create a
    | second, weaker path to the same clinical writes, which is the one outcome
    | this whole rollout has spent six sprints avoiding.
    |
    | "Routine batch" is therefore an OPERATING VOCABULARY over the existing
    | wave, plus the properties below that a batch must have to be routine at
    | all. A wave missing any of them is an engineering exercise, not routine
    | work, and belongs in a sprint with its own approval.
    */
    'routine_batch' => [
        /*
        | The properties that make a batch routine. Documentation of invariants
        | already enforced elsewhere (wave governance, admission, quota,
        | operator assignment, SOD) — listed here so an operator and an auditor
        | read the same definition, and so the readiness report can name which
        | one is missing.
        */
        'required_properties' => [
            'explicitly_approved',      // fresh approval reference, never inherited
            'branch_scoped',            // enrolled branches are named, not "all"
            'quota_bounded',            // an exact daily ceiling exists
            'time_bounded',             // a planned window exists
            'maker_checker_assigned',   // operators assigned; publisher is not the importer
            'source_documents_frozen',  // the candidate set is fixed before opening
            'reconciliation_required',  // closure requires a balanced ledger
        ],

        /*
        | Naming. ROLL-4 wave codes were sprint-shaped (`WAVE-1`, `WAVE-2R`),
        | which does not scale past a handful and carries no branch or date.
        | Routine batches use a predictable, sortable, PII-free scheme:
        |
        |   LRME-<BRANCH>-<YYYYMMDD>-<NN>       e.g. LRME-LDK2-20260817-01
        |
        | Historical wave codes are NEVER renamed — they are immutable evidence,
        | and `WAVE-1` / `WAVE-2R` remain exactly as they were filed.
        |
        | ADVISORY ONLY, AND DELIBERATELY NOT ASSERTED. A readiness check on this
        | pattern would fire permanently against those legitimate historical
        | codes, and a batch is not unsafe because it is badly named. Teaching
        | operators to ignore a always-on warning would cost more than the
        | consistency is worth. Use it for new batches; nothing refuses if you
        | do not.
        */
        'code_pattern' => '/^LRME-[A-Z0-9]{2,10}-\d{8}-\d{2}$/',
        'code_example' => 'LRME-LDK2-20260817-01',

        /*
        | Wave-3 was formally SKIPPED / NOT REQUIRED (ROLL-4-WAVE-3). Steady
        | state does not resurrect it, and a batch must not be named as if it
        | were the missing engineering wave.
        |
        | THIS ONE IS ASSERTED, unlike the naming pattern above: declaring a
        | retired code is not a cosmetic slip, it means someone is running work
        | under a governance identity that was explicitly closed, and its
        | approval record does not exist. There are no legitimate historical
        | rows to false-positive against, because the wave was never run.
        */
        'retired_codes' => ['WAVE-3'],
    ],

    /*
    | BATCH SIZING — recoverability first, throughput second.
    |
    | HOW THESE NUMBERS WERE DERIVED, because inventing them would make the
    | rail decoration:
    |
    |   `default_daily` = 25 is the ingestion queue's OWN declared single-worker
    |   ceiling (`legacy_rme_rollout.capacity.max_pending_jobs`). Beyond it new
    |   uploads are already refused by ROLL-3 backpressure, so a larger daily
    |   allowance cannot make documents flow faster — it can only make the
    |   refusal arrive later and less legibly.
    |
    |   `max_daily` = 100 is four times that ceiling: enough that a full day of
    |   review work is never blocked by the rail, small enough that one bad
    |   batch is reviewable and reversible in a single sitting. The binding
    |   constraint at this size is HUMAN REVIEW throughput, not the worker.
    |
    |   Above `max_daily`, a batch is not routine. It needs elevated approval
    |   naming who accepted the larger blast radius.
    |
    |   The absolute rail remains `legacy_rme_operations.quota.max_declarable_daily`
    |   (500), which is enforced server-side and is NOT changed here.
    |
    | MEASURED THROUGHPUT IS NOT YET AVAILABLE. Production evidence to date is
    | Wave-1 (1 document) and Wave-2R (4 documents) — five documents total,
    | which is far too small a sample to fit a rate to. These are therefore
    | BOUNDED DEFAULTS chosen for recoverability, explicitly revisable once a
    | real batch produces measured review-and-publish timings. They are not a
    | benchmark and must not be quoted as one.
    */
    'batch_sizing' => [
        'default_daily' => (int) env('LEGACY_RME_ROUTINE_DEFAULT_DAILY', 25),
        'max_daily' => (int) env('LEGACY_RME_ROUTINE_MAX_DAILY', 100),
        'throughput_measured' => false,
        'derivation' => 'default=queue single-worker pending ceiling; max=4x ceiling bounded by human review throughput',
    ],

    /*
    | RESTING STATE — the machine-checkable version of runbook §0.
    |
    | The runbook has described the safe resting state in prose since ROLL-4.
    | Prose cannot be asserted, so "are we actually at rest?" was answered by an
    | operator reading three reports. These are the exact conditions, so the
    | readiness command can answer it in one line.
    |
    | This is an ASSERTION SET, not a set of toggles: turning one off does not
    | make production safer, it makes the report blinder.
    */
    'resting_state' => [
        'capability_off' => true,        // feature flag effectively OFF
        'admission_empty' => true,       // no branch code admitted
        'no_active_batch' => true,       // no ACTIVE wave bound
        'zero_in_flight' => true,        // nothing mid-pipeline
        'zero_unexplained' => true,      // ledger partitions cleanly
        'zero_quota_drift' => true,      // counter agrees with rows accepted
    ],

    /*
    | MULTI-BRANCH — what the architecture actually supports, not what would be
    | convenient to claim.
    |
    | Determined by reading the code rather than by assumption:
    |
    |   ACROSS BATCHES → SEQUENTIAL. `LegacyRmeWaveBindingService` resolves the
    |   operative wave from ONE declared code (`legacy_rme_rollout.admission.wave`).
    |   Exactly one wave is operative at a time. There is no supported way to run
    |   two batches concurrently, and this sprint deliberately does not add one.
    |
    |   WITHIN ONE BATCH → CONCURRENT, BRANCH-ISOLATED. A single wave enrols many
    |   branches, and each carries its own status, quota, operator assignments and
    |   pause/drain/complete transitions (`ops_rme_legacy_wave_branches`). Several
    |   branches can therefore migrate at the same time under one approval, each
    |   isolated from the others.
    |
    | So multi-branch readiness is real, and its shape is "one batch, many
    | branches" — NOT "many simultaneous batches". Redesigning toward concurrent
    | batches would be a material scope expansion with no demonstrated need, and
    | is explicitly out of scope.
    */
    'multi_branch' => [
        'mode' => 'CONCURRENT_BRANCH_ISOLATED_WITHIN_ONE_ACTIVE_BATCH',
        'batches_concurrent' => false,
        'branches_concurrent_within_batch' => true,
        'authority' => 'LegacyRmeWaveBindingService resolves a single declared wave code',
    ],

    /*
    | BACKUP — the gate that did not exist.
    |
    | ROLL-2's readiness gate checks 18 things and none of them is "do we have a
    | recent backup?". The runbook says to take one; nothing verified it. A batch
    | that mutates clinical staging state without a verified restore point is the
    | one failure this whole programme cannot walk back, so freshness is now a
    | first-class readiness signal.
    |
    | It REUSES the ENT-12 / MON-1 backup signal rather than probing the backup
    | directory again — a second implementation of "is the backup fresh?" would
    | be free to disagree with the first.
    */
    'backup' => [
        // Age beyond which the newest verified backup is too old to open a
        // batch against. One working day: a batch opened on a backup older than
        // yesterday cannot be restored to a point the operator recognises.
        'max_age_hours' => (int) env('LEGACY_RME_BATCH_BACKUP_MAX_AGE_HOURS', 24),

        // Opening a batch without a fresh verified backup is a refusal, not a
        // warning. Set false ONLY for local/CI exercises of the pre-gate path.
        'required_before_batch' => (bool) env('LEGACY_RME_BATCH_BACKUP_REQUIRED', true),
    ],

    /*
    | STOP-THE-LINE — findings that require pausing admission immediately rather
    | than being noted and worked around.
    |
    | Every code below is a condition that means the migration's own evidence
    | can no longer be trusted. They are reported as BLOCKER severity and drive
    | the readiness decision to NO_GO.
    */
    'stop_the_line' => [
        'QUOTA_LEDGER_DRIFT',            // counter disagrees with rows accepted
        'UNEXPLAINED_RECORDS',           // a document is in no known bucket
        'SEPARATE_PUBLISHER_DISABLED',   // SOD invariant switched off in production
        'ADMITTED_BRANCH_NOT_APPROVED',  // a branch is open that no approval covers
        'BATCH_BINDING_MISMATCH',        // declared wave and wave record disagree
        'CLINICAL_TIMEZONE_WRONG',       // date boundary is not the clinic's calendar
        'BACKUP_MISSING_OR_STALE',       // no verified restore point
        'SOD_STAFFING_UNAVAILABLE',      // SOD enforced but no distinct pair of accounts can perform it
    ],

    /*
    | SEVERITY — a PII-free operational vocabulary shared by the report, the
    | runbook and the evidence template, so "is this bad?" has one answer.
    |
    |   INFO     — normal, recorded for the batch evidence.
    |   WARNING  — degraded; proceed with awareness, fix before the next batch.
    |   BLOCKER  — do not open, or pause if already open.
    |   INCIDENT — evidence integrity or a control is compromised; escalate.
    |
    | Mapped FROM the existing GO/WATCH/FAIL/UNKNOWN check vocabulary rather
    | than replacing it, so a check has one status and one severity derived from
    | it. UNKNOWN maps to BLOCKER on purpose: "we could not tell" has never been
    | a basis for opening a clinical migration.
    */
    'severity' => [
        'map' => [
            'GO' => 'INFO',
            'WATCH' => 'WARNING',
            'FAIL' => 'BLOCKER',
            'UNKNOWN' => 'BLOCKER',
        ],
        'levels' => ['INFO', 'WARNING', 'BLOCKER', 'INCIDENT'],

        // Stop-the-line findings are escalated above a plain BLOCKER because
        // they mean a control or the audit trail itself is in question.
        'incident_codes' => [
            'SEPARATE_PUBLISHER_DISABLED',
            'ADMITTED_BRANCH_NOT_APPROVED',
            'BATCH_BINDING_MISMATCH',
            // An enforced separation rule nobody can satisfy is a control in
            // question, not merely a blocked step.
            'SOD_STAFFING_UNAVAILABLE',
        ],
    ],

    /*
    | GOVERNANCE — what a routine batch is NOT.
    |
    | Recorded in config rather than only in prose because it is the property
    | most likely to erode: a routine batch is operations work. It produces
    | operational evidence and an approval closure. It does NOT produce a GO
    | tag, because no software changed. GO tags remain reserved for software and
    | governance changes that went through CI.
    */
    'governance' => [
        'routine_batch_requires_go_tag' => false,
        'routine_batch_requires_fresh_approval' => true,
        'routine_batch_requires_evidence_record' => true,
        'evidence_template' => 'docs/evidence/legacy-rme/routine-batch-evidence-template.md',
        'runbook' => 'docs/runbooks/legacy-rme-steady-state-operations-runbook.md',
        'operator_checklist' => 'docs/runbooks/legacy-rme-routine-batch-operator-checklist.md',
    ],
];
