<?php

declare(strict_types=1);

namespace App\Modules\LegacyRme\Support;

/**
 * LEGACY-RME-PDF-ROLL-4 — lifecycle of a migration wave.
 *
 * A closed vocabulary plus an explicit transition map, matching the 1A
 * convention (public constants, no native enum anywhere in app/).
 *
 * The states are deliberately few. Every one answers a question an operator
 * actually asks, and none of them exists only to look thorough:
 *
 *   DRAFT     — being planned. Ingests nothing.
 *   APPROVED  — governance has signed it. Still ingests nothing; approval and
 *               activation are separate acts so a wave can be approved today
 *               and started on Monday.
 *   ACTIVE    — the only state that permits new ingestion.
 *   PAUSED    — temporarily stopped. New ingestion refused; everything already
 *               accepted keeps its lifecycle and may still be published.
 *   DRAINING  — winding down, and not coming back. Same runtime effect as
 *               PAUSED, different intent, and it cannot return to ACTIVE.
 *   COMPLETED — signed off. Terminal.
 *   CANCELLED — abandoned. Terminal.
 *
 * PAUSED AND DRAINING BEHAVE IDENTICALLY AT RUNTIME, AND THAT IS THE POINT.
 * Both refuse new intake and preserve accepted work. They differ in what they
 * permit NEXT: a pause is reversible, a drain is the path to completion. Merging
 * them would force an operator to express "we are stopping for good" by choosing
 * a state that invites someone to resume it.
 */
final class LegacyRmeWaveStatus
{
    public const DRAFT = 'DRAFT';

    public const APPROVED = 'APPROVED';

    public const ACTIVE = 'ACTIVE';

    public const PAUSED = 'PAUSED';

    public const DRAINING = 'DRAINING';

    public const COMPLETED = 'COMPLETED';

    public const CANCELLED = 'CANCELLED';

    /** @var list<string> */
    public const ALL = [
        self::DRAFT,
        self::APPROVED,
        self::ACTIVE,
        self::PAUSED,
        self::DRAINING,
        self::COMPLETED,
        self::CANCELLED,
    ];

    /** @var list<string> */
    public const TERMINAL = [
        self::COMPLETED,
        self::CANCELLED,
    ];

    /**
     * The ONLY state in which new documents may be accepted.
     *
     * Expressed as a single constant rather than repeated comparisons so a
     * future state cannot accidentally become ingestable by omission.
     */
    public const INGESTABLE = self::ACTIVE;

    /**
     * @var array<string, list<string>>
     */
    public const TRANSITIONS = [
        self::DRAFT => [self::APPROVED, self::CANCELLED],
        // Approval may be withdrawn back to DRAFT while nothing has started.
        self::APPROVED => [self::ACTIVE, self::DRAFT, self::CANCELLED],
        self::ACTIVE => [self::PAUSED, self::DRAINING, self::CANCELLED],
        self::PAUSED => [self::ACTIVE, self::DRAINING, self::CANCELLED],
        // A drain ends in completion or cancellation. It never reopens: a wave
        // that needs to run again is a new wave with its own approval, which is
        // the same rule ROLL-3 applies to admission.
        self::DRAINING => [self::COMPLETED, self::CANCELLED],
        self::COMPLETED => [],
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
