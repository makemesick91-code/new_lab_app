<?php

declare(strict_types=1);

namespace App\Modules\LegacyRme\Services;

use App\Models\User;
use App\Modules\LabOrder\Services\AuditLogService;
use App\Modules\LegacyRme\Models\LegacyRmeImport;
use App\Modules\LegacyRme\Models\LegacyRmeRecord;
use App\Modules\LegacyRme\Support\LegacyRmeAuditEvent;

/**
 * LEGACY-RME-PDF-1A — audit facade for the legacy RME archive.
 *
 * Reuses the shared sys_audit_logs trail (AuditLogService) instead of adding a
 * parallel audit system, and enforces the domain's payload policy: the shared
 * service performs no masking of its own, so every payload is filtered here
 * against an explicit allow-list and reduced to scalars.
 *
 * Never persisted: patient name, KTP/NIK, clinical content, base64 file data,
 * a raw PDF, or an absolute filesystem path.
 */
class LegacyRmeAuditService
{
    private const MAX_STRING_LENGTH = 255;

    /**
     * LEGACY-RME-OPS-CLI-1 — the surface currently driving a lifecycle
     * operation, threaded into every audit row written while it is set.
     *
     * NULL BY DEFAULT, AND THAT IS DELIBERATE. Every pre-existing caller keeps
     * writing exactly the payload it wrote before: an upload, a queued render,
     * a duplicate rejection and a wave transition are unchanged byte for byte.
     * Only the lifecycle service sets it, and only for the span of one
     * operation.
     */
    private ?string $channel = null;

    public function __construct(
        private readonly AuditLogService $auditLogs,
    ) {}

    /**
     * Run a callback with every audit row inside it tagged with the surface
     * that asked.
     *
     * SCOPED, NOT ASSIGNED. The previous value is restored in a `finally`, so a
     * refusal, an exception or a nested call can never leave a stale channel
     * behind to mislabel the next operation in the same process — which matters
     * on a queue worker, where one long-lived process serves many jobs.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public function withChannel(?string $channel, callable $callback): mixed
    {
        $previous = $this->channel;
        $this->channel = $channel !== null && in_array($channel, LegacyRmeAuditEvent::CHANNELS, true)
            ? $channel
            : null;

        try {
            return $callback();
        } finally {
            $this->channel = $previous;
        }
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function logImportEvent(string $action, ?LegacyRmeImport $import, array $metadata = [], ?User $actor = null): void
    {
        $this->auditLogs->log(
            LegacyRmeAuditEvent::ENTITY_IMPORT,
            $import?->getKey() !== null ? (int) $import->getKey() : null,
            $action,
            null,
            $this->safePayload($metadata + $this->importContext($import) + $this->channelContext()),
            $actor,
        );
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function logRecordEvent(string $action, ?LegacyRmeRecord $record, array $metadata = [], ?User $actor = null): void
    {
        $this->auditLogs->log(
            LegacyRmeAuditEvent::ENTITY_RECORD,
            $record?->getKey() !== null ? (int) $record->getKey() : null,
            $action,
            null,
            $this->safePayload($metadata + $this->recordContext($record) + $this->channelContext()),
            $actor,
        );
    }

    /**
     * @return array<string, string>
     */
    private function channelContext(): array
    {
        return $this->channel !== null ? ['channel' => $this->channel] : [];
    }

    /**
     * Reduce an arbitrary payload to the allow-listed, scalar, length-bounded
     * subset that is safe to persist.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, scalar|null>
     */
    public function safePayload(array $payload): array
    {
        $safe = [];

        foreach ($payload as $key => $value) {
            if (! in_array($key, LegacyRmeAuditEvent::ALLOWED_METADATA_KEYS, true)) {
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
    private function importContext(?LegacyRmeImport $import): array
    {
        if ($import === null) {
            return [];
        }

        return [
            'import_id' => $import->getKey() !== null ? (int) $import->getKey() : null,
            'patient_id' => $import->patient_id,
            'origin_branch_id' => $import->origin_branch_id,
            'selected_rme_date' => $import->selected_rme_date?->toDateString(),
            'earliest_native_rme_date' => $import->earliest_native_rme_date_snapshot?->toDateString(),
            'status' => $import->status,
        ];
    }

    /**
     * @return array<string, scalar|null>
     */
    private function recordContext(?LegacyRmeRecord $record): array
    {
        if ($record === null) {
            return [];
        }

        return [
            'legacy_record_id' => $record->getKey() !== null ? (int) $record->getKey() : null,
            'patient_id' => $record->patient_id,
            'origin_branch_id' => $record->origin_branch_id,
            'rme_date' => $record->rme_date?->toDateString(),
            'page_count' => $record->page_count,
            'status' => $record->status,
        ];
    }
}
