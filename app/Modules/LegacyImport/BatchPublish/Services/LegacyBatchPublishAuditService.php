<?php

declare(strict_types=1);

namespace App\Modules\LegacyImport\BatchPublish\Services;

use App\Models\User;
use App\Modules\LabOrder\Services\AuditLogService;
use App\Modules\LegacyImport\BatchPublish\Models\LegacyBatchPublishItem;
use App\Modules\LegacyImport\BatchPublish\Models\LegacyBatchPublishRun;
use App\Support\DeveloperConsole\SensitiveValueMasker;

/**
 * Audit trail for batch publish — PR2 §14.
 *
 * Wraps the shared sys_audit_logs writer rather than adding another audit
 * table, mirroring LegacyRmeAuditService and the PR1 batch-review service.
 * What this class adds is a PAYLOAD POLICY, which the shared writer lacks.
 *
 * THESE EVENTS DO NOT REPLACE THE CANONICAL ONES — §14 is explicit
 * ----------------------------------------------------------------
 * `BATCH_PUBLISH_REQUESTED` and `BATCH_PUBLISH_COMPLETED` carry COUNTS AND
 * REFERENCES. They say "this publisher requested 50 publications in run <uuid>,
 * 47 created, 1 already filed, 2 refused". They do NOT restate 47 clinical
 * publications — those are already written by the canonical publish service as
 * LEGACY_RME_PUBLISHED / the odontogram equivalent, which remain the
 * authoritative clinical record and now carry the BATCH channel so an auditor
 * can tell which surface asked.
 *
 * The per-ITEM events here are orchestration bookkeeping: they record what the
 * batch decided about a selection row, not that a clinical publication occurred.
 * If a batch event and a canonical event ever disagree, the canonical one is
 * true.
 *
 * WHAT MAY NEVER BE RECORDED
 * --------------------------
 * Patient name, KTP/NIK, date of birth, any clinical content, any page bytes,
 * any absolute path, any disk name, and the raw text of an internal exception.
 * Enforcement is structural: an ALLOWLIST (not a denylist), every value reduced
 * to a bounded masked scalar, keys outside the list dropped. A caller cannot
 * leak by accident. Note that `reason_message` is deliberately ABSENT — it is
 * canonical operator-facing prose that lives on the item row behind
 * authorization, not in a permanent audit payload.
 */
class LegacyBatchPublishAuditService
{
    public const ENTITY_RUN = 'legacy_batch_publish_run';

    public const ENTITY_ITEM = 'legacy_batch_publish_item';

    public const RUN_OPENED = 'LEGACY_BATCH_PUBLISH_RUN_OPENED';

    public const PUBLISH_REQUESTED = 'LEGACY_BATCH_PUBLISH_REQUESTED';

    public const PUBLISH_COMPLETED = 'LEGACY_BATCH_PUBLISH_COMPLETED';

    public const RUN_ABANDONED = 'LEGACY_BATCH_PUBLISH_RUN_ABANDONED';

    public const ITEM_PUBLISHED = 'LEGACY_BATCH_PUBLISH_ITEM_PUBLISHED';

    public const ITEM_REFUSED = 'LEGACY_BATCH_PUBLISH_ITEM_REFUSED';

    /**
     * Keys permitted in an audit payload.
     *
     * An allowlist, not a denylist: a denylist protects against the leaks you
     * thought of, an allowlist against the ones you did not.
     *
     * @var list<string>
     */
    private const ALLOWED_KEYS = [
        'run_uuid',
        'import_type',
        'status',
        'previous_status',
        'import_id',
        'record_id',
        'patient_id',
        'branch_id',
        'origin_branch_id',
        'source_sha256',
        'reason_code',
        'created_record',
        'selected_count',
        'attempted',
        'attempted_count',
        'published_count',
        'already_published',
        'refused_count',
        'remaining',
        'channel',
        'refusal_counts',
    ];

    private const MAX_STRING_LENGTH = 200;

    public function __construct(
        private readonly AuditLogService $auditLogs,
        private readonly SensitiveValueMasker $masker,
    ) {}

    /** @param array<string, mixed> $metadata */
    public function logRunEvent(
        string $action,
        ?LegacyBatchPublishRun $run,
        array $metadata = [],
        ?User $actor = null,
    ): void {
        $this->auditLogs->log(
            self::ENTITY_RUN,
            $run?->getKey() !== null ? (int) $run->getKey() : null,
            $action,
            null,
            $this->safePayload($metadata + $this->runContext($run)),
            $actor,
        );
    }

    /** @param array<string, mixed> $metadata */
    public function logItemEvent(
        string $action,
        ?LegacyBatchPublishItem $item,
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

    /** @return array<string, mixed> */
    private function runContext(?LegacyBatchPublishRun $run): array
    {
        if ($run === null) {
            return [];
        }

        return [
            'run_uuid' => (string) $run->uuid,
            'import_type' => (string) $run->import_type,
            'status' => (string) $run->status,
            'origin_branch_id' => $run->origin_branch_id,
            'selected_count' => $run->selected_count,
        ];
    }

    /** @return array<string, mixed> */
    private function itemContext(?LegacyBatchPublishItem $item): array
    {
        if ($item === null) {
            return [];
        }

        return [
            'import_type' => (string) $item->import_type,
            'import_id' => $item->importId(),
            'record_id' => $item->recordId(),
            'patient_id' => $item->patient_id,
            'source_sha256' => $item->source_sha256,
            'status' => (string) $item->status,
            'reason_code' => $item->reason_code,
            'created_record' => (bool) $item->created_record,
        ];
    }

    /**
     * Reduce a payload to allowlisted, bounded, masked scalars.
     *
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
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
                // Masked FIRST, then bounded. Truncating before masking could
                // sever a pattern and let a fragment through.
                $safe[$key] = mb_substr($this->masker->mask($value), 0, self::MAX_STRING_LENGTH);

                continue;
            }

            if (is_array($value)) {
                // One level, scalar leaves only, and only for the counting
                // payloads that legitimately need it (refusal_counts).
                $flat = [];

                foreach ($value as $innerKey => $innerValue) {
                    if (! is_string($innerKey) && ! is_int($innerKey)) {
                        continue;
                    }

                    if (is_int($innerValue) || is_float($innerValue) || is_bool($innerValue) || $innerValue === null) {
                        $flat[(string) $innerKey] = $innerValue;

                        continue;
                    }

                    if (is_string($innerValue)) {
                        $flat[(string) $innerKey] = mb_substr(
                            $this->masker->mask($innerValue),
                            0,
                            self::MAX_STRING_LENGTH
                        );
                    }
                }

                $safe[$key] = $flat;
            }
        }

        return $safe;
    }
}
