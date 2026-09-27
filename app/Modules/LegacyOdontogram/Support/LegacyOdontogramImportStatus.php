<?php

declare(strict_types=1);

namespace App\Modules\LegacyOdontogram\Support;

/**
 * FIX-04b — the staging lifecycle of a legacy odontogram import.
 *
 * A final Support class with an explicit transition map rather than a PHP
 * enum, matching how every other status vocabulary in this codebase is
 * modelled (LegacyRmeImportStatus, LabWorkflowState, …). The map is the single
 * definition of what may follow what; no service, policy or controller decides
 * that for itself.
 */
final class LegacyOdontogramImportStatus
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

    /** @var list<string> */
    public const TERMINAL = [
        self::PUBLISHED,
        self::CANCELLED,
    ];

    /**
     * REVISION-LEGACY-SINGLE-ACTIVE-DOCUMENT-PER-PATIENT-1 — the staging states
     * that OCCUPY the patient's single legacy odontogram slot.
     *
     * Derived from THIS module's own transition map, not copied from the RME
     * one. The two vocabularies happen to agree today; the odontogram slot is
     * defined by odontogram semantics, and a future divergence in either state
     * machine must not silently change the other's occupancy rule.
     *
     * Exactly `ALL` minus `TERMINAL`, pinned by a test. FAILED is included
     * because FAILED leads to QUEUED here as well: a failed chart import is
     * retryable, so it still owns the slot until it is retried or cancelled.
     *
     * PUBLISHED is absent — occupancy then belongs to the produced RECORD, whose
     * VOID is what releases the slot for a correction import. CANCELLED is
     * absent because it is terminal and produced no archive.
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
     * PUBLISHED is reachable ONLY from REVIEWED: a human review is a hard
     * precondition of turning a staged chart into immutable clinical evidence,
     * and PUBLISHED itself leads nowhere — a published record is corrected by
     * VOIDing the RECORD and importing again, never by moving the import back.
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
