<?php

declare(strict_types=1);

namespace App\Modules\LegacyImport\BatchReview\Support;

/**
 * The SECOND, independent axis — FEATURE-LEGACY-BATCH-REVIEW-PUBLISH-1 PR1 §5.
 *
 * The owner's rule, verbatim in intent:
 *
 *     canonical import status = READY_FOR_REVIEW   (unchanged, clinical)
 *     review_triage_status    = BLOCKED            (this vocabulary)
 *
 * and explicitly NOT `canonical import status = CANCELLED`. No new clinical
 * lifecycle state is invented here; the canonical nine-state machines in
 * LegacyRmeImportStatus / LegacyOdontogramImportStatus are untouched and remain
 * the only clinical truth. Triage is an operational overlay that can be set,
 * changed and cleared without ever moving a canonical import.
 *
 * WHY CLEARED IS A VALUE RATHER THAN A DELETED ROW
 * ------------------------------------------------
 * "This was blocked and someone with review authority cleared it" is a question
 * an audit asks. Deleting the row would answer it with silence. So the row
 * survives, carrying both the original reviewer's attestation and the clearing
 * actor's — which also means the authorization boundary on clearing stays
 * visible in the data rather than only in the log.
 */
final class LegacyReviewTriageStatus
{
    private function __construct() {}

    /** Withheld from publishing by reviewer judgement. */
    public const BLOCKED = 'BLOCKED';

    /** Withheld from publishing pending more information. */
    public const NEEDS_ATTENTION = 'NEEDS_ATTENTION';

    /** Previously triaged, since released by an authorized reviewer. */
    public const CLEARED = 'CLEARED';

    /** @return list<string> */
    public static function all(): array
    {
        return [self::BLOCKED, self::NEEDS_ATTENTION, self::CLEARED];
    }

    public static function isValid(?string $status): bool
    {
        return $status !== null && in_array($status, self::all(), true);
    }

    /**
     * THE PUBLISH EXCLUSION SET. PR2 must refuse to select any item carrying one
     * of these, and this method is the single place that set is expressed.
     *
     * @return list<string>
     */
    public static function blocking(): array
    {
        return [self::BLOCKED, self::NEEDS_ATTENTION];
    }

    public static function isBlocking(?string $status): bool
    {
        return $status !== null && in_array($status, self::blocking(), true);
    }

    /** Map a reviewer attestation onto the triage it raises, or null. */
    public static function forDecision(?string $decision): ?string
    {
        return match ($decision) {
            LegacyBatchReviewDecision::BLOCKED => self::BLOCKED,
            LegacyBatchReviewDecision::NEEDS_ATTENTION => self::NEEDS_ATTENTION,
            default => null,
        };
    }

    public static function label(?string $status): string
    {
        return match ($status) {
            self::BLOCKED => 'Ditahan',
            self::NEEDS_ATTENTION => 'Perlu perhatian',
            self::CLEARED => 'Sudah dibebaskan',
            default => (string) $status,
        };
    }
}
