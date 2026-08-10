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
    ];

    private function __construct() {}

    public static function isKnownAction(?string $action): bool
    {
        return $action !== null && in_array($action, self::ACTIONS, true);
    }
}
