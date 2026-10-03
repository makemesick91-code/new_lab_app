<?php

declare(strict_types=1);

namespace App\Modules\LegacyImport\BatchReview\Support;

/**
 * Session lifecycle — PR1 §13, §16.
 *
 * Modelled as an explicit transition map, matching every other status
 * vocabulary in this codebase (LegacyRmeImportStatus, LegacyMassUploadBatchStatus,
 * LabWorkflowState). No native PHP enum anywhere in app/, so none here either.
 *
 * WHY SUBMITTED_WITH_REFUSALS IS ITS OWN TERMINAL STATE
 * -----------------------------------------------------
 * Partial success is the designed outcome, not an error (§7 of the parent spec,
 * and the same reasoning behind the mass-upload COMPLETED_WITH_BLOCKED_ITEMS).
 * 74 attested, 2 refused by the canonical path, 72 reviewed is a GOOD session.
 * Folding it into SUBMITTED would hide the two rows an operator must look at;
 * folding it into FAILED would claim 72 clinical reviews did not happen.
 *
 * SUBMITTING is deliberately not terminal and deliberately re-enterable from
 * itself: a browser that dies mid pass leaves the session there, and the
 * operator resumes it. The per-decision submit_status, not this column, is what
 * prevents a resumed pass from redoing applied work.
 */
final class LegacyBatchReviewSessionStatus
{
    private function __construct() {}

    /** Accepting per-item decisions. */
    public const OPEN = 'OPEN';

    /** A submit pass is walking the attested decisions. Resumable. */
    public const SUBMITTING = 'SUBMITTING';

    /** Every attested REVIEWED decision was applied. */
    public const SUBMITTED = 'SUBMITTED';

    /** Applied what it could; at least one canonical refusal to look at. */
    public const SUBMITTED_WITH_REFUSALS = 'SUBMITTED_WITH_REFUSALS';

    /** Closed without submitting. Decisions are kept as evidence. */
    public const ABANDONED = 'ABANDONED';

    public const TRANSITIONS = [
        self::OPEN => [self::SUBMITTING, self::ABANDONED],
        // Self-transition is intentional — a resumed pass re-enters SUBMITTING.
        self::SUBMITTING => [
            self::SUBMITTING,
            self::SUBMITTED,
            self::SUBMITTED_WITH_REFUSALS,
            self::ABANDONED,
        ],
        // A session with refusals may be re-submitted once the operator has
        // dealt with them (for example, after a second reviewer takes over a
        // document the first was barred from by separation of duties).
        self::SUBMITTED_WITH_REFUSALS => [self::SUBMITTING],
        self::SUBMITTED => [],
        self::ABANDONED => [],
    ];

    /** @return list<string> */
    public static function all(): array
    {
        return array_keys(self::TRANSITIONS);
    }

    public static function isValid(?string $status): bool
    {
        return $status !== null && array_key_exists($status, self::TRANSITIONS);
    }

    public static function canTransition(?string $from, ?string $to): bool
    {
        if ($from === null || $to === null) {
            return false;
        }

        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }

    /** Statuses in which an operator may still change decisions. */
    public static function isMutable(?string $status): bool
    {
        return in_array($status, [self::OPEN, self::SUBMITTED_WITH_REFUSALS], true);
    }

    public static function isTerminal(?string $status): bool
    {
        return in_array($status, [self::SUBMITTED, self::ABANDONED], true);
    }

    public static function label(?string $status): string
    {
        return match ($status) {
            self::OPEN => 'Sedang ditinjau',
            self::SUBMITTING => 'Sedang dikirim',
            self::SUBMITTED => 'Selesai dikirim',
            self::SUBMITTED_WITH_REFUSALS => 'Selesai dengan penolakan',
            self::ABANDONED => 'Ditinggalkan',
            default => (string) $status,
        };
    }
}
