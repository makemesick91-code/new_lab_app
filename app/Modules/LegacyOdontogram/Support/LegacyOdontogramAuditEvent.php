<?php

declare(strict_types=1);

namespace App\Modules\LegacyOdontogram\Support;

/**
 * FIX-04b — the audit vocabulary of the legacy odontogram archive.
 *
 * Its own entity names and its own allow-list, deliberately NOT shared with the
 * legacy RME archive: an auditor must be able to answer "what happened to the
 * odontogram archive?" without disentangling it from a different capability's
 * trail, and a key admitted for one archive must not silently become admissible
 * in the other.
 *
 * ALLOWED_METADATA_KEYS is the whole PII policy. Anything not named here is
 * dropped before the payload is written — so a patient NAME, a Nomor RM, a
 * KTP/NIK, a file path, a filename or any clinical detail cannot reach the
 * trail even if a future caller passes one by mistake.
 */
final class LegacyOdontogramAuditEvent
{
    public const ENTITY_IMPORT = 'legacy_odontogram_import';

    public const ENTITY_RECORD = 'legacy_odontogram_record';

    public const IMPORT_CREATED = 'LEGACY_ODONTOGRAM_IMPORT_CREATED';

    public const IMPORT_BRANCH_REJECTED = 'LEGACY_ODONTOGRAM_IMPORT_BRANCH_REJECTED';

    public const PDF_UPLOADED = 'LEGACY_ODONTOGRAM_PDF_UPLOADED';

    public const PROCESSING_QUEUED = 'LEGACY_ODONTOGRAM_PROCESSING_QUEUED';

    public const PROCESSING_COMPLETED = 'LEGACY_ODONTOGRAM_PROCESSING_COMPLETED';

    public const PROCESSING_FAILED = 'LEGACY_ODONTOGRAM_PROCESSING_FAILED';

    public const PROCESSING_RETRIED = 'LEGACY_ODONTOGRAM_PROCESSING_RETRIED';

    /** A staging page image was streamed — full-resolution clinical document bytes. */
    public const IMPORT_PAGE_VIEWED = 'LEGACY_ODONTOGRAM_IMPORT_PAGE_VIEWED';

    public const IMPORT_REVIEWED = 'LEGACY_ODONTOGRAM_IMPORT_REVIEWED';

    public const IMPORT_CANCELLED = 'LEGACY_ODONTOGRAM_IMPORT_CANCELLED';

    public const PUBLISH_REJECTED = 'LEGACY_ODONTOGRAM_PUBLISH_REJECTED';

    public const PUBLISHED = 'LEGACY_ODONTOGRAM_PUBLISHED';

    public const VOIDED = 'LEGACY_ODONTOGRAM_VOIDED';

    public const RECORD_VIEWED = 'LEGACY_ODONTOGRAM_RECORD_VIEWED';

    public const RECORD_SOURCE_VIEWED = 'LEGACY_ODONTOGRAM_RECORD_SOURCE_VIEWED';

    public const RECORD_PAGE_VIEWED = 'LEGACY_ODONTOGRAM_RECORD_PAGE_VIEWED';

    /** @var list<string> */
    public const ACTIONS = [
        self::IMPORT_CREATED,
        self::IMPORT_BRANCH_REJECTED,
        self::PDF_UPLOADED,
        self::PROCESSING_QUEUED,
        self::PROCESSING_COMPLETED,
        self::PROCESSING_FAILED,
        self::PROCESSING_RETRIED,
        self::IMPORT_PAGE_VIEWED,
        self::IMPORT_REVIEWED,
        self::IMPORT_CANCELLED,
        self::PUBLISH_REJECTED,
        self::PUBLISHED,
        self::VOIDED,
        self::RECORD_VIEWED,
        self::RECORD_SOURCE_VIEWED,
        self::RECORD_PAGE_VIEWED,
    ];

    /**
     * Surrogate ids, dates, counts, hashes and stable codes only.
     *
     * @var list<string>
     */
    public const ALLOWED_METADATA_KEYS = [
        'import_id',
        'legacy_record_id',
        'patient_id',
        'origin_branch_id',
        'branch_code',
        'selected_odontogram_date',
        'odontogram_date',
        'earliest_native_odontogram_date',
        'page_count',
        'page_number',
        'variant',
        'status',
        'rule_code',
        'failure_code',
        'source_pdf_sha256',
        'size_bytes',
        'mime_type',
        'dpi',
        'void_reason_length',
    ];

    private function __construct() {}

    public static function isKnownAction(?string $action): bool
    {
        return $action !== null && in_array($action, self::ACTIONS, true);
    }
}
