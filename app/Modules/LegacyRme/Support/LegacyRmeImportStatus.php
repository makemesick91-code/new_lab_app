<?php

declare(strict_types=1);

namespace App\Modules\LegacyRme\Support;

/**
 * LEGACY-RME-PDF-1A — staging lifecycle of a legacy RME import.
 *
 * A typed, closed vocabulary plus the allowed transitions. Status strings live
 * ONLY here (and on the model constants that reference this class) so they are
 * never scattered as magic strings across services, requests or views.
 *
 * The repository has no native PHP enum anywhere in app/; the established
 * convention is public constants plus an explicit list, which is what this
 * class provides while still being validated and typed at the boundary.
 */
final class LegacyRmeImportStatus
{
    public const DRAFT = 'DRAFT';

    public const UPLOADED = 'UPLOADED';

    public const QUEUED = 'QUEUED';

    public const PROCESSING = 'PROCESSING';

    public const READY_FOR_REVIEW = 'READY_FOR_REVIEW';

    public const REVIEWED = 'REVIEWED';

    public const PUBLISHED = 'PUBLISHED';

    public const FAILED = 'FAILED';

    public const CANCELLED = 'CANCELLED';

    /** @var list<string> */
    public const ALL = [
        self::DRAFT,
        self::UPLOADED,
        self::QUEUED,
        self::PROCESSING,
        self::READY_FOR_REVIEW,
        self::REVIEWED,
        self::PUBLISHED,
        self::FAILED,
        self::CANCELLED,
    ];

    /**
     * Terminal states. A terminal import is never re-processed; a correction is
     * a fresh import (and, once published, a VOID on the produced record).
     *
     * @var list<string>
     */
    public const TERMINAL = [
        self::PUBLISHED,
        self::CANCELLED,
    ];

    /**
     * REVISION-LEGACY-SINGLE-ACTIVE-DOCUMENT-PER-PATIENT-1 — the staging states
     * that OCCUPY the patient's single legacy RME slot.
     *
     * Exactly the non-terminal states: every one of them can still advance
     * toward PUBLISHED, so a second parallel upload would create two competing
     * lifecycles for one patient. This is `ALL` minus `TERMINAL`, and a test
     * pins that identity so the two can never drift apart.
     *
     * FAILED IS IN THIS LIST, ON PURPOSE. Look at the transition map: FAILED
     * leads to QUEUED. A failed import is RETRYABLE, not abandoned, so it still
     * owns the slot — the operator retries it, or cancels it, and only then may
     * a new upload begin.
     *
     * PUBLISHED is absent because once a staging row publishes, occupancy is
     * decided by the RECORD it produced: PUBLISHED record occupies, VOID record
     * releases. A historical PUBLISHED staging row must never keep the slot
     * after its record has been voided, or void-then-reimport would be dead.
     *
     * CANCELLED is absent because it is terminal and produced no archive.
     *
     * @var list<string>
     */
    public const SLOT_OCCUPYING = [
        self::DRAFT,
        self::UPLOADED,
        self::QUEUED,
        self::PROCESSING,
        self::READY_FOR_REVIEW,
        self::REVIEWED,
        self::FAILED,
    ];

    /**
     * Allowed staging transitions. The publish runtime lands in a follow-up
     * sprint; the map is declared now so the lifecycle can never drift.
     *
     * @var array<string, list<string>>
     */
    public const TRANSITIONS = [
        self::DRAFT => [self::UPLOADED, self::CANCELLED, self::FAILED],
        self::UPLOADED => [self::QUEUED, self::CANCELLED, self::FAILED],
        self::QUEUED => [self::PROCESSING, self::CANCELLED, self::FAILED],
        self::PROCESSING => [self::READY_FOR_REVIEW, self::FAILED, self::CANCELLED],
        self::READY_FOR_REVIEW => [self::REVIEWED, self::CANCELLED, self::FAILED],
        self::REVIEWED => [self::PUBLISHED, self::CANCELLED, self::FAILED],
        // A FAILED import can be retried from the start of the pipeline.
        self::FAILED => [self::QUEUED, self::CANCELLED],
        self::PUBLISHED => [],
        self::CANCELLED => [],
    ];

    private function __construct() {}

    public static function isValid(?string $status): bool
    {
        return $status !== null && in_array($status, self::ALL, true);
    }

    public static function isTerminal(?string $status): bool
    {
        return $status !== null && in_array($status, self::TERMINAL, true);
    }

    public static function canTransition(?string $from, ?string $to): bool
    {
        if (! self::isValid($from) || ! self::isValid($to)) {
            return false;
        }

        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }

    /**
     * @return list<string>
     */
    public static function nextStatuses(?string $from): array
    {
        return self::isValid($from) ? (self::TRANSITIONS[$from] ?? []) : [];
    }
}
