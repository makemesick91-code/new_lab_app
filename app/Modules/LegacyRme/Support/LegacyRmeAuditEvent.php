<?php

declare(strict_types=1);

namespace App\Modules\LegacyRme\Support;

/**
 * LEGACY-RME-PDF-1A — audit vocabulary for the legacy RME archive.
 *
 * Written into the existing sys_audit_logs trail through the shared
 * AuditLogService (no new audit table, no parallel audit system).
 *
 * PAYLOAD POLICY: only the keys in ALLOWED_METADATA_KEYS are ever persisted.
 * Never a patient name, KTP/NIK, clinical content, base64 file data, a raw PDF
 * or an absolute filesystem path.
 */
final class LegacyRmeAuditEvent
{
    public const ENTITY_IMPORT = 'legacy_rme_import';

    public const ENTITY_RECORD = 'legacy_rme_record';

    public const IMPORT_CREATED = 'LEGACY_RME_IMPORT_CREATED';

    public const DATE_SELECTED = 'LEGACY_RME_DATE_SELECTED';

    public const PUBLISH_REJECTED = 'LEGACY_RME_PUBLISH_REJECTED';

    public const DUPLICATE_DETECTED = 'LEGACY_RME_DUPLICATE_DETECTED';

    public const PUBLISHED = 'LEGACY_RME_PUBLISHED';

    public const VOIDED = 'LEGACY_RME_VOIDED';

    // LEGACY-RME-PDF-1B — upload runtime, queue processing and private access.
    public const PDF_UPLOADED = 'LEGACY_RME_PDF_UPLOADED';

    public const PROCESSING_QUEUED = 'LEGACY_RME_PROCESSING_QUEUED';

    public const PROCESSING_STARTED = 'LEGACY_RME_PROCESSING_STARTED';

    public const PROCESSING_COMPLETED = 'LEGACY_RME_PROCESSING_COMPLETED';

    public const PROCESSING_FAILED = 'LEGACY_RME_PROCESSING_FAILED';

    public const PROCESSING_RETRIED = 'LEGACY_RME_PROCESSING_RETRIED';

    public const IMPORT_CANCELLED = 'LEGACY_RME_IMPORT_CANCELLED';

    public const SOURCE_VIEWED = 'LEGACY_RME_SOURCE_VIEWED';

    public const PAGE_VIEWED = 'LEGACY_RME_PAGE_VIEWED';

    // LEGACY-RME-PDF-1C — review, publish and the published-record viewer.
    // PUBLISHED / PUBLISH_REJECTED were already declared by 1A and are reused
    // verbatim rather than duplicated under a second name.
    public const IMPORT_REVIEWED = 'LEGACY_RME_IMPORT_REVIEWED';

    public const RECORD_VIEWED = 'LEGACY_RME_RECORD_VIEWED';

    public const RECORD_SOURCE_VIEWED = 'LEGACY_RME_RECORD_SOURCE_VIEWED';

    public const RECORD_PAGE_VIEWED = 'LEGACY_RME_RECORD_PAGE_VIEWED';

    // LEGACY-RME-PDF-1D — clinical read/print of a published archive.
    // VOIDED was already declared by 1A and is reused verbatim.
    public const RECORD_PRINTED = 'LEGACY_RME_RECORD_PRINTED';

    public const RECORD_EXPORTED = 'LEGACY_RME_RECORD_EXPORTED';

    /**
     * LEGACY-RME-PDF-FIX-ROLL2-1 — an intake refused before any staging row
     * existed, because the archive's branch could not be derived from the
     * patient's Nomor RM (or a request tried to override it).
     *
     * It is a distinct action rather than a reused IMPORT_CREATED: nothing was
     * created, and a trail that says otherwise is a lie.
     */
    public const IMPORT_BRANCH_REJECTED = 'LEGACY_RME_IMPORT_BRANCH_REJECTED';

    /**
     * LEGACY-RME-PDF-ROLL-3 — an intake refused because the RM-derived branch
     * is not admitted to the running migration wave (or the capability is off).
     *
     * Distinct from IMPORT_BRANCH_REJECTED: there the branch could not be
     * DERIVED, which is a patient master-data problem; here it derived cleanly
     * and the ROLLOUT declined it. Conflating them would hide which of the two
     * an operator has to fix.
     */
    public const IMPORT_ADMISSION_REJECTED = 'LEGACY_RME_IMPORT_ADMISSION_REJECTED';

    /**
     * LEGACY-RME-PDF-ROLL-3 — an intake refused because the rendering pipeline
     * was saturated (queue depth, a stalled queue, or low disk).
     *
     * Recorded so a wave that slows down leaves evidence of WHY, instead of
     * operators seeing sporadic upload failures with no explanation.
     */
    public const INGESTION_THROTTLED = 'LEGACY_RME_INGESTION_THROTTLED';

    /**
     * LEGACY-RME-PDF-ROLL-4 — an intake refused by the OPERATIONS layer, after
     * ROLL-3 had already admitted the branch.
     *
     * Distinct from IMPORT_ADMISSION_REJECTED on purpose: there, the rollout
     * declined the branch. Here the branch is admitted and something
     * operational refused — the wave is paused, the operator is not assigned,
     * the daily quota is spent. Those have completely different remedies, and a
     * shared action would leave an operator unable to tell which one they are
     * looking at.
     */
    public const IMPORT_OPERATIONS_REJECTED = 'LEGACY_RME_IMPORT_OPERATIONS_REJECTED';

    // LEGACY-RME-PDF-ROLL-4 — governance of a migration wave. Every state
    // change a wave, a branch enrollment or an operator assignment can undergo
    // leaves one of these behind.
    public const WAVE_REGISTERED = 'LEGACY_RME_WAVE_REGISTERED';

    public const WAVE_TRANSITIONED = 'LEGACY_RME_WAVE_TRANSITIONED';

    public const WAVE_BRANCH_TRANSITIONED = 'LEGACY_RME_WAVE_BRANCH_TRANSITIONED';

    public const WAVE_BRANCH_COMPLETED = 'LEGACY_RME_WAVE_BRANCH_COMPLETED';

    public const WAVE_OPERATOR_ASSIGNED = 'LEGACY_RME_WAVE_OPERATOR_ASSIGNED';

    public const WAVE_OPERATOR_REVOKED = 'LEGACY_RME_WAVE_OPERATOR_REVOKED';

    public const WAVE_QUOTA_CHANGED = 'LEGACY_RME_WAVE_QUOTA_CHANGED';

    /** @var list<string> */
    public const ACTIONS = [
        self::IMPORT_CREATED,
        self::DATE_SELECTED,
        self::PUBLISH_REJECTED,
        self::DUPLICATE_DETECTED,
        self::PUBLISHED,
        self::VOIDED,
        self::PDF_UPLOADED,
        self::PROCESSING_QUEUED,
        self::PROCESSING_STARTED,
        self::PROCESSING_COMPLETED,
        self::PROCESSING_FAILED,
        self::PROCESSING_RETRIED,
        self::IMPORT_CANCELLED,
        self::SOURCE_VIEWED,
        self::PAGE_VIEWED,
        self::IMPORT_REVIEWED,
        self::RECORD_PRINTED,
        self::RECORD_EXPORTED,
        self::RECORD_VIEWED,
        self::RECORD_SOURCE_VIEWED,
        self::RECORD_PAGE_VIEWED,
        self::IMPORT_BRANCH_REJECTED,
        self::IMPORT_ADMISSION_REJECTED,
        self::INGESTION_THROTTLED,
        self::IMPORT_OPERATIONS_REJECTED,
        self::WAVE_REGISTERED,
        self::WAVE_TRANSITIONED,
        self::WAVE_BRANCH_TRANSITIONED,
        self::WAVE_BRANCH_COMPLETED,
        self::WAVE_OPERATOR_ASSIGNED,
        self::WAVE_OPERATOR_REVOKED,
        self::WAVE_QUOTA_CHANGED,
    ];

    /**
     * The complete allow-list of audit metadata keys for this domain.
     *
     * @var list<string>
     */
    public const ALLOWED_METADATA_KEYS = [
        'patient_id',
        'origin_branch_id',
        'selected_rme_date',
        'rme_date',
        'earliest_native_rme_date',
        'import_id',
        'legacy_record_id',
        'page_count',
        'status',
        'rule_code',
        'source_pdf_sha256',
        'normalized_content_hash',
        'actor_id',
        'timestamp',
        // LEGACY-RME-PDF-1B. Still structure only: a failure code, a page
        // number, a byte count, a declared MIME type and the ids of colliding
        // rows. Never a filename, a path, a process command line, a stack
        // trace, or any clinical/patient content.
        'failure_code',
        'page_number',
        'size_bytes',
        'mime_type',
        'dpi',
        'malware_scanned',
        'duplicate_import_id',
        'duplicate_record_id',
        'duplicate_patient_id',
        'variant',
        // LEGACY-RME-PDF-1D. The void REASON itself is deliberately absent: it
        // is operator free text that may name a patient, and this allow-list is
        // structure-only. Its permanent home is the record's own void_reason
        // column, which is where a reader looks anyway. Only its length is
        // recorded, so the trail still shows a real explanation was given.
        'void_reason_length',
        'export_format',
        // LEGACY-RME-PDF-FIX-ROLL2-1. Still structure only. `latest_rme_date`
        // is the other end of the operator-declared clinical range;
        // `reference_mode` records whether the decision was bounded by a native
        // RME (BEFORE_NATIVE_RME) or made without one (NO_NATIVE_REFERENCE) —
        // the distinction the removed refusal used to destroy. `branch_code` is
        // an operational label such as `TKM1`, taken from the patient's own
        // Nomor RM; it is not patient data and never the full Nomor RM.
        'latest_rme_date',
        'reference_mode',
        'branch_code',
        // LEGACY-RME-PDF-ROLL-3. Still structure only. `wave` is a non-PHI
        // rollout label such as `WAVE-1`, so a refused or accepted intake can
        // be attributed to the stage that authorized it; `pending_jobs` is an
        // infrastructure depth, never anything about a patient or a document.
        'wave',
        'pending_jobs',
        // LEGACY-RME-PDF-ROLL-4. Still structure only. `operator_user_id` is the
        // id of the user an assignment covers — the trail already records the
        // ACTOR separately, and an assignment is meaningless without naming who
        // it is for. An id, never a name or an email. `daily_quota` is an
        // operational ceiling; `reason_length` records that a real explanation
        // was given without copying operator free text (which may name a
        // patient) into the payload, exactly as 1D does for `void_reason_length`.
        'operator_user_id',
        'daily_quota',
        'reason_length',
    ];

    private function __construct() {}

    public static function isKnownAction(?string $action): bool
    {
        return $action !== null && in_array($action, self::ACTIONS, true);
    }
}
