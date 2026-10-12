<?php

namespace App\Modules\ClinicOversight\Services;

use App\Models\User;
use App\Modules\LabOrder\Services\AuditLogService;

/**
 * FEATURE-FINANCE-KLINIK-DATA-ANALYST-ROLES-1 — the audit trail of the
 * read-only oversight module, written to the shared `sys_audit_logs` through
 * the canonical AuditLogService (no audit table of its own).
 *
 * WHAT IS AUDITED: opening an identified clinical record (native or legacy),
 * streaming a clinical file, and every data EXTRACTION (print, PDF, CSV).
 * Aggregate dashboard reads and list pages are not audited — they carry no
 * single patient's record and would bury the meaningful events.
 *
 * Native handwritten RM images are audited PER IMAGE: the image routes can be
 * requested directly, bypassing the page that embeds them, so auditing only
 * the page would leave a bulk cross-branch extraction invisible. Legacy
 * archive THUMBNAILS are deliberately not audited (the existing legacy
 * convention, LEGACY-RME-PDF-1C): a thumbnail is a reduced preview, and the
 * full-resolution page, the source PDF, the print and the PDF are audited.
 *
 * PAYLOAD POLICY. Only allow-listed, scalar, length-bounded keys are stored:
 * ids, codes, counts, formats and filter shapes. Never a patient name, phone,
 * medical-record number, NIK/KTP, clinical text, a storage path or file bytes.
 */
class OversightAuditService
{
    public const ENTITY = 'clinic_oversight';

    public const NATIVE_RECORD_VIEWED = 'OVERSIGHT_NATIVE_RME_VIEWED';

    public const NATIVE_RECORD_PRINTED = 'OVERSIGHT_NATIVE_RME_PRINTED';

    public const NATIVE_RECORD_PDF = 'OVERSIGHT_NATIVE_RME_PDF_DOWNLOADED';

    public const ODONTOGRAM_PRINTED = 'OVERSIGHT_NATIVE_ODONTOGRAM_PRINTED';

    /**
     * One handwritten RM image streamed. Audited per image because these
     * routes can be requested directly (bypassing the page that embeds them),
     * which would otherwise make a bulk cross-branch extraction invisible.
     */
    public const NATIVE_FILE_STREAMED = 'OVERSIGHT_NATIVE_RME_FILE_STREAMED';

    public const PATIENT_HISTORY_VIEWED = 'OVERSIGHT_PATIENT_RME_HISTORY_VIEWED';

    public const LEGACY_RME_VIEWED = 'OVERSIGHT_LEGACY_RME_VIEWED';

    public const LEGACY_RME_FILE_STREAMED = 'OVERSIGHT_LEGACY_RME_FILE_STREAMED';

    public const LEGACY_RME_PRINTED = 'OVERSIGHT_LEGACY_RME_PRINTED';

    public const LEGACY_RME_PDF = 'OVERSIGHT_LEGACY_RME_PDF_DOWNLOADED';

    public const LEGACY_ODONTOGRAM_VIEWED = 'OVERSIGHT_LEGACY_ODONTOGRAM_VIEWED';

    public const LEGACY_ODONTOGRAM_FILE_STREAMED = 'OVERSIGHT_LEGACY_ODONTOGRAM_FILE_STREAMED';

    public const LEGACY_ODONTOGRAM_PRINTED = 'OVERSIGHT_LEGACY_ODONTOGRAM_PRINTED';

    public const DATA_EXPORTED = 'OVERSIGHT_DATA_EXPORTED';

    public const DATA_PRINTED = 'OVERSIGHT_DATA_PRINTED';

    public const ALLOWED_METADATA_KEYS = [
        'surface',
        'format',
        'record_type',
        'record_id',
        'clinic_visit_id',
        'patient_id',
        'branch_id',
        'branch_filter',
        'date',
        'date_from',
        'date_to',
        'row_count',
        'page_number',
        'page_count',
        'variant',
        'truncated',
    ];

    private const MAX_STRING_LENGTH = 64;

    public function __construct(private readonly AuditLogService $auditLogs) {}

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function log(string $action, ?int $entityId, array $metadata = [], ?User $actor = null): void
    {
        $this->auditLogs->log(self::ENTITY, $entityId, $action, null, $this->safePayload($metadata), $actor);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, scalar|null>
     */
    public function safePayload(array $payload): array
    {
        $safe = [];

        foreach ($payload as $key => $value) {
            if (! in_array($key, self::ALLOWED_METADATA_KEYS, true)) {
                continue;
            }

            if ($value === null || is_bool($value) || is_int($value) || is_float($value)) {
                $safe[$key] = $value;
            } elseif (is_string($value)) {
                $safe[$key] = mb_substr($value, 0, self::MAX_STRING_LENGTH);
            }
        }

        return $safe;
    }
}
