<?php

namespace App\Modules\Satusehat\Services;

use App\Modules\Satusehat\Models\SatusehatSubmissionBatch;
use App\Modules\Satusehat\Models\SatusehatSubmissionItem;

/**
 * Pure, exhaustively-testable transition matrix for submission items and
 * batches. It defines what is LEGAL — the processing service performs the
 * locked, audited, transactional transition itself. Free-form status writes
 * from a controller/Blade are forbidden; everything routes through here.
 */
final class SatusehatSubmissionStateMachine
{
    /**
     * @return array<string, list<string>>
     */
    public static function itemMatrix(): array
    {
        return [
            SatusehatSubmissionItem::STATUS_PENDING => [
                SatusehatSubmissionItem::STATUS_PREPARED,
                SatusehatSubmissionItem::STATUS_BLOCKED,
                SatusehatSubmissionItem::STATUS_CANCELLED,
            ],
            SatusehatSubmissionItem::STATUS_PREPARED => [
                SatusehatSubmissionItem::STATUS_QUEUED,
                SatusehatSubmissionItem::STATUS_BLOCKED,
                SatusehatSubmissionItem::STATUS_CANCELLED,
            ],
            SatusehatSubmissionItem::STATUS_QUEUED => [
                SatusehatSubmissionItem::STATUS_PROCESSING,
                SatusehatSubmissionItem::STATUS_CANCELLED,
            ],
            SatusehatSubmissionItem::STATUS_PROCESSING => [
                SatusehatSubmissionItem::STATUS_SUCCEEDED,
                SatusehatSubmissionItem::STATUS_RETRYABLE_FAILED,
                SatusehatSubmissionItem::STATUS_PERMANENT_FAILED,
                SatusehatSubmissionItem::STATUS_UNKNOWN_OUTCOME,
                SatusehatSubmissionItem::STATUS_RECONCILIATION_REQUIRED,
            ],
            SatusehatSubmissionItem::STATUS_RETRYABLE_FAILED => [
                SatusehatSubmissionItem::STATUS_QUEUED,
                SatusehatSubmissionItem::STATUS_PROCESSING,
                SatusehatSubmissionItem::STATUS_PERMANENT_FAILED,
                SatusehatSubmissionItem::STATUS_CANCELLED,
            ],
            SatusehatSubmissionItem::STATUS_UNKNOWN_OUTCOME => [
                SatusehatSubmissionItem::STATUS_RECONCILIATION_REQUIRED,
                SatusehatSubmissionItem::STATUS_RECONCILED,
                SatusehatSubmissionItem::STATUS_CANCELLED,
            ],
            SatusehatSubmissionItem::STATUS_RECONCILIATION_REQUIRED => [
                SatusehatSubmissionItem::STATUS_PROCESSING,
                SatusehatSubmissionItem::STATUS_RECONCILED,
                SatusehatSubmissionItem::STATUS_SUCCEEDED,
                SatusehatSubmissionItem::STATUS_PERMANENT_FAILED,
                SatusehatSubmissionItem::STATUS_CANCELLED,
            ],
            SatusehatSubmissionItem::STATUS_BLOCKED => [
                SatusehatSubmissionItem::STATUS_CANCELLED,
                SatusehatSubmissionItem::STATUS_PREPARED,
            ],
            // Terminal states — no outgoing transitions.
            SatusehatSubmissionItem::STATUS_SUCCEEDED => [],
            SatusehatSubmissionItem::STATUS_RECONCILED => [],
            SatusehatSubmissionItem::STATUS_PERMANENT_FAILED => [],
            SatusehatSubmissionItem::STATUS_CANCELLED => [],
        ];
    }

    /**
     * @return array<string, list<string>>
     */
    public static function batchMatrix(): array
    {
        return [
            SatusehatSubmissionBatch::STATUS_DRAFT => [
                SatusehatSubmissionBatch::STATUS_PREPARED,
                SatusehatSubmissionBatch::STATUS_CANCELLED,
            ],
            SatusehatSubmissionBatch::STATUS_PREPARED => [
                SatusehatSubmissionBatch::STATUS_QUEUED,
                SatusehatSubmissionBatch::STATUS_CANCELLED,
            ],
            SatusehatSubmissionBatch::STATUS_QUEUED => [
                SatusehatSubmissionBatch::STATUS_PROCESSING,
                SatusehatSubmissionBatch::STATUS_CANCELLED,
            ],
            SatusehatSubmissionBatch::STATUS_PROCESSING => [
                SatusehatSubmissionBatch::STATUS_PARTIAL,
                SatusehatSubmissionBatch::STATUS_SUCCEEDED,
                SatusehatSubmissionBatch::STATUS_FAILED,
                SatusehatSubmissionBatch::STATUS_RECONCILIATION_REQUIRED,
            ],
            SatusehatSubmissionBatch::STATUS_PARTIAL => [
                SatusehatSubmissionBatch::STATUS_PROCESSING,
                SatusehatSubmissionBatch::STATUS_RECONCILIATION_REQUIRED,
                SatusehatSubmissionBatch::STATUS_SUCCEEDED,
                SatusehatSubmissionBatch::STATUS_FAILED,
                SatusehatSubmissionBatch::STATUS_CANCELLED,
            ],
            SatusehatSubmissionBatch::STATUS_RECONCILIATION_REQUIRED => [
                SatusehatSubmissionBatch::STATUS_PROCESSING,
                SatusehatSubmissionBatch::STATUS_PARTIAL,
                SatusehatSubmissionBatch::STATUS_SUCCEEDED,
                SatusehatSubmissionBatch::STATUS_FAILED,
            ],
            SatusehatSubmissionBatch::STATUS_SUCCEEDED => [],
            SatusehatSubmissionBatch::STATUS_FAILED => [],
            SatusehatSubmissionBatch::STATUS_CANCELLED => [],
            SatusehatSubmissionBatch::STATUS_BLOCKED => [
                SatusehatSubmissionBatch::STATUS_CANCELLED,
            ],
        ];
    }

    public static function itemCanTransition(string $from, string $to): bool
    {
        if ($from === $to) {
            return true; // idempotent same-state no-op.
        }

        return in_array($to, self::itemMatrix()[$from] ?? [], true);
    }

    public static function batchCanTransition(string $from, string $to): bool
    {
        if ($from === $to) {
            return true;
        }

        return in_array($to, self::batchMatrix()[$from] ?? [], true);
    }
}
