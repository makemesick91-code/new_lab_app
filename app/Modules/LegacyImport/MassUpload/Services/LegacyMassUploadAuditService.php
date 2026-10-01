<?php

declare(strict_types=1);

namespace App\Modules\LegacyImport\MassUpload\Services;

use App\Models\User;
use App\Modules\LabOrder\Services\AuditLogService;
use App\Modules\LegacyImport\MassUpload\Models\LegacyMassUploadBatch;
use App\Modules\LegacyImport\MassUpload\Models\LegacyMassUploadItem;
use App\Support\DeveloperConsole\SensitiveValueMasker;

/**
 * Audit trail for mass upload — FEATURE-LEGACY-RME-ODONTOGRAM-MASS-UPLOAD-1 §42.
 *
 * Wraps the shared sys_audit_logs writer rather than introducing another audit
 * table, mirroring LegacyRmeAuditService. What this class adds on top is a
 * PAYLOAD POLICY, which the shared writer does not have.
 *
 * WHAT MAY BE RECORDED
 * --------------------
 * Batch identity, the operator, counts, reason codes, digests, timestamps, and
 * the medical record number the operator themselves typed into the manifest.
 *
 * WHAT MAY NEVER BE RECORDED
 * --------------------------
 * Patient name, KTP/NIK, date of birth, any clinical content, any absolute
 * path, any disk name, base64, and the raw text of an internal exception. A
 * migration audit trail is read by people investigating an incident months
 * later, and anything in it is effectively permanent — so the safe default is
 * to record the decision, not the document.
 *
 * Enforcement is structural: every value is reduced to a bounded scalar and
 * passed through the masker, and keys outside the allowlist are dropped rather
 * than trusted. A caller cannot leak by accident.
 */
class LegacyMassUploadAuditService
{
    public const ENTITY_BATCH = 'legacy_mass_upload_batch';

    public const ENTITY_ITEM = 'legacy_mass_upload_item';

    /**
     * Keys permitted in an audit payload.
     *
     * An allowlist rather than a denylist on purpose. A denylist protects
     * against the leaks you thought of; an allowlist protects against the ones
     * you did not.
     *
     * @var list<string>
     */
    private const ALLOWED_KEYS = [
        'batch_uuid',
        'import_type',
        'status',
        'previous_status',
        'row_number',
        'medical_record_number',
        'patient_id',
        'branch_id',
        'origin_branch_id',
        'package_sha256',
        'package_bytes',
        'manifest_sha256',
        'document_sha256',
        'document_bytes',
        'total_items',
        'eligible_items',
        'warning_items',
        'blocked_items',
        'dispatched_items',
        'failed_items',
        'error_items',
        'reason_code',
        'failure_code',
        'created_import_id',
        'unreferenced_documents',
        'chunk_size',
        'items_in_pass',
        'quota_remaining',
        'reason_counts',
    ];

    private const MAX_STRING_LENGTH = 200;

    public function __construct(
        private readonly AuditLogService $auditLogs,
        private readonly SensitiveValueMasker $masker,
    ) {}

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function logBatchEvent(
        string $action,
        ?LegacyMassUploadBatch $batch,
        array $metadata = [],
        ?User $actor = null,
    ): void {
        $this->auditLogs->log(
            self::ENTITY_BATCH,
            $batch?->getKey() !== null ? (int) $batch->getKey() : null,
            $action,
            null,
            $this->safePayload($metadata + $this->batchContext($batch)),
            $actor,
        );
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function logItemEvent(
        string $action,
        ?LegacyMassUploadItem $item,
        array $metadata = [],
        ?User $actor = null,
    ): void {
        $this->auditLogs->log(
            self::ENTITY_ITEM,
            $item?->getKey() !== null ? (int) $item->getKey() : null,
            $action,
            null,
            $this->safePayload($metadata + $this->itemContext($item)),
            $actor,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function batchContext(?LegacyMassUploadBatch $batch): array
    {
        if ($batch === null) {
            return [];
        }

        return [
            'batch_uuid' => (string) $batch->uuid,
            'import_type' => (string) $batch->import_type,
            'status' => (string) $batch->status,
            'origin_branch_id' => $batch->origin_branch_id,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function itemContext(?LegacyMassUploadItem $item): array
    {
        if ($item === null) {
            return [];
        }

        return [
            'row_number' => $item->row_number,
            'medical_record_number' => (string) $item->manifest_medical_record_number,
            'patient_id' => $item->resolved_patient_id,
            'branch_id' => $item->resolved_branch_id,
            'status' => (string) $item->status,
            'reason_code' => $item->reason_code,
            'created_import_id' => $item->createdImportId(),
        ];
    }

    /**
     * Reduce a payload to allowlisted, bounded, masked scalars.
     *
     * @param  array<string, mixed>  $metadata
     * @return array<string, scalar|null>
     */
    private function safePayload(array $metadata): array
    {
        $safe = [];

        foreach ($metadata as $key => $value) {
            if (! is_string($key) || ! in_array($key, self::ALLOWED_KEYS, true)) {
                continue;
            }

            if ($value === null || is_bool($value) || is_int($value) || is_float($value)) {
                $safe[$key] = $value;

                continue;
            }

            if (is_string($value)) {
                // Masked first, then bounded. Masking a truncated string could
                // cut a pattern in half and let a fragment through.
                $safe[$key] = mb_substr($this->masker->mask($value), 0, self::MAX_STRING_LENGTH);

                continue;
            }

            if (is_array($value)) {
                // One level, scalars only, and only for the counting payloads
                // that legitimately need it (reason_counts). Anything deeper is
                // a sign a caller is dumping a model.
                $flat = [];

                foreach ($value as $innerKey => $innerValue) {
                    if (! is_scalar($innerValue) && $innerValue !== null) {
                        continue;
                    }

                    $flat[(string) $innerKey] = is_string($innerValue)
                        ? mb_substr($this->masker->mask($innerValue), 0, self::MAX_STRING_LENGTH)
                        : $innerValue;
                }

                $safe[$key] = json_encode($flat, JSON_UNESCAPED_UNICODE) ?: null;

                continue;
            }

            // Objects, resources and closures are dropped silently. There is no
            // safe rendering of them here and a partial one is worse than none.
        }

        return $safe;
    }
}
