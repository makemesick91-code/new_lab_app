<?php

declare(strict_types=1);

namespace App\Modules\LegacyOdontogram\Services;

use App\Models\User;
use App\Modules\LabOrder\Services\AuditLogService;
use App\Modules\LegacyOdontogram\Models\LegacyOdontogramImport;
use App\Modules\LegacyOdontogram\Models\LegacyOdontogramRecord;
use App\Modules\LegacyOdontogram\Support\LegacyOdontogramAuditEvent;

/**
 * FIX-04b — the audit trail of the legacy odontogram archive.
 *
 * It reuses the shared `sys_audit_logs` writer (AuditLogService) rather than
 * inventing a table, and adds the one thing that writer does not have: a
 * PAYLOAD POLICY.
 *
 * Every payload is filtered against
 * LegacyOdontogramAuditEvent::ALLOWED_METADATA_KEYS and then reduced to
 * length-bounded scalars. Anything else is dropped silently and by default —
 * which is the point: a future caller that passes a patient name, a Nomor RM, a
 * KTP/NIK, a file path or a clinical note cannot leak it into the trail by
 * forgetting a rule it never read.
 */
class LegacyOdontogramAuditService
{
    private const MAX_STRING_LENGTH = 255;

    public function __construct(
        private readonly AuditLogService $auditLogs,
    ) {}

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function logImportEvent(string $action, ?LegacyOdontogramImport $import, array $metadata = [], ?User $actor = null): void
    {
        $this->auditLogs->log(
            LegacyOdontogramAuditEvent::ENTITY_IMPORT,
            $import?->getKey() !== null ? (int) $import->getKey() : null,
            $action,
            null,
            $this->safePayload($metadata + $this->importContext($import)),
            $actor,
        );
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function logRecordEvent(string $action, ?LegacyOdontogramRecord $record, array $metadata = [], ?User $actor = null): void
    {
        $this->auditLogs->log(
            LegacyOdontogramAuditEvent::ENTITY_RECORD,
            $record?->getKey() !== null ? (int) $record->getKey() : null,
            $action,
            null,
            $this->safePayload($metadata + $this->recordContext($record)),
            $actor,
        );
    }

    /**
     * Allow-list, then scalars only. Arrays and objects are dropped rather than
     * serialized: a nested structure is exactly where free text hides.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, scalar|null>
     */
    public function safePayload(array $payload): array
    {
        $safe = [];

        foreach ($payload as $key => $value) {
            if (! in_array($key, LegacyOdontogramAuditEvent::ALLOWED_METADATA_KEYS, true)) {
                continue;
            }

            if ($value === null || is_scalar($value)) {
                $safe[$key] = is_string($value)
                    ? mb_substr($value, 0, self::MAX_STRING_LENGTH)
                    : $value;
            }
        }

        return $safe;
    }

    /**
     * @return array<string, scalar|null>
     */
    private function importContext(?LegacyOdontogramImport $import): array
    {
        if ($import === null) {
            return [];
        }

        return [
            'import_id' => $import->getKey() !== null ? (int) $import->getKey() : null,
            'patient_id' => $import->patient_id,
            'origin_branch_id' => $import->origin_branch_id,
            'branch_code' => $import->source_branch_code,
            'selected_odontogram_date' => $import->selected_odontogram_date?->toDateString(),
            'earliest_native_odontogram_date' => $import->earliest_native_odontogram_date_snapshot?->toDateString(),
            'status' => $import->status,
        ];
    }

    /**
     * @return array<string, scalar|null>
     */
    private function recordContext(?LegacyOdontogramRecord $record): array
    {
        if ($record === null) {
            return [];
        }

        return [
            'legacy_record_id' => $record->getKey() !== null ? (int) $record->getKey() : null,
            'patient_id' => $record->patient_id,
            'origin_branch_id' => $record->branch_id,
            'branch_code' => $record->source_branch_code,
            'odontogram_date' => $record->odontogram_date?->toDateString(),
            'page_count' => $record->page_count,
            'status' => $record->status,
        ];
    }
}
