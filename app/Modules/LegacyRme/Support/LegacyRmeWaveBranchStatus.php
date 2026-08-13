<?php

declare(strict_types=1);

namespace App\Modules\LegacyRme\Support;

/**
 * LEGACY-RME-PDF-ROLL-4 — lifecycle of ONE branch inside a migration wave.
 *
 * Mirrors the wave lifecycle minus the governance stages: a branch is not
 * separately approved (the wave's approval covers the whole branch set — that
 * is ROLL-3's scope-binding rule and it is not weakened here), so a branch goes
 * straight from PLANNED to ACTIVE when the wave starts.
 *
 * A branch is ingestable only when the WAVE is ACTIVE and the BRANCH is ACTIVE.
 * Pausing one branch of a five-branch wave stops that branch and leaves the
 * other four running — the per-branch control an operator reaches for when one
 * clinic's documents turn out to be a mess and the rest are fine.
 */
final class LegacyRmeWaveBranchStatus
{
    public const PLANNED = 'PLANNED';

    public const ACTIVE = 'ACTIVE';

    public const PAUSED = 'PAUSED';

    public const DRAINING = 'DRAINING';

    public const COMPLETED = 'COMPLETED';

    public const CANCELLED = 'CANCELLED';

    /** @var list<string> */
    public const ALL = [
        self::PLANNED,
        self::ACTIVE,
        self::PAUSED,
        self::DRAINING,
        self::COMPLETED,
        self::CANCELLED,
    ];

    /**
     * States that mean this branch is finished with, one way or another.
     *
     * Wave completion requires every enrolled branch to be in one of these —
     * "accounted for", which is not the same as "successful".
     *
     * @var list<string>
     */
    public const TERMINAL = [
        self::COMPLETED,
        self::CANCELLED,
    ];

    /** The ONLY branch state in which new documents may be accepted. */
    public const INGESTABLE = self::ACTIVE;

    /**
     * @var array<string, list<string>>
     */
    public const TRANSITIONS = [
        self::PLANNED => [self::ACTIVE, self::CANCELLED],
        self::ACTIVE => [self::PAUSED, self::DRAINING, self::CANCELLED],
        self::PAUSED => [self::ACTIVE, self::DRAINING, self::CANCELLED],
        // Completion is reachable ONLY from DRAINING. A branch cannot be signed
        // off while it is still accepting documents, because the reconciliation
        // that justifies the sign-off would be measuring a moving target.
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
