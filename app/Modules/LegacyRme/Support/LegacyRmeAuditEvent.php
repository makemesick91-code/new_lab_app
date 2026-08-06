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

    /** @var list<string> */
    public const ACTIONS = [
        self::IMPORT_CREATED,
        self::DATE_SELECTED,
        self::PUBLISH_REJECTED,
        self::DUPLICATE_DETECTED,
        self::PUBLISHED,
        self::VOIDED,
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
    ];

    private function __construct() {}

    public static function isKnownAction(?string $action): bool
    {
        return $action !== null && in_array($action, self::ACTIONS, true);
    }
}
