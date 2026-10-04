<?php

declare(strict_types=1);

namespace App\Modules\LegacyImport\BatchPublish\Support;

/**
 * What the SERVER did with one selected document — PR2 §4, §9.
 *
 * PENDING is the load-bearing one. §9 requires that a run which stops after 37
 * of 80 items leaves the 37 final and the 43 resumable, and this is how: only
 * PENDING rows are walked by a resumed pass, so a completed attempt can never
 * be retried into a second publication.
 *
 * REFUSED vs SKIPPED mirrors the distinction the mass-upload and batch-review
 * vocabularies already draw. REFUSED means the domain said no and the operator
 * must look at it — §4 forbids silently skipping those. SKIPPED means nothing
 * was decided, because a bounded pass ended or the run was abandoned first.
 */
final class LegacyBatchPublishItemStatus
{
    private function __construct() {}

    /** Selected, not yet attempted. Resumable. */
    public const PENDING = 'PENDING';

    /** The canonical publish ran and an archive record exists. */
    public const PUBLISHED = 'PUBLISHED';

    /** The canonical path refused. Stable code recorded. Must be reported. */
    public const REFUSED = 'REFUSED';

    /** Not attempted — a bounded pass ended, or the run was abandoned. */
    public const SKIPPED = 'SKIPPED';

    /** @return list<string> */
    public static function all(): array
    {
        return [self::PENDING, self::PUBLISHED, self::REFUSED, self::SKIPPED];
    }

    public static function isValid(?string $status): bool
    {
        return $status !== null && in_array($status, self::all(), true);
    }

    /**
     * Statuses a resumed pass may attempt.
     *
     * PUBLISHED is absent, and that is the anti-duplication rule: the canonical
     * publish is itself idempotent, but re-walking a finished row would still
     * inflate the run's counters and write a second attempt record for one
     * publication.
     *
     * REFUSED is absent too. A refusal is a decision the operator must see; a
     * resumed pass silently retrying it would hide the thing §4 insists on
     * reporting. Re-selecting the document in a NEW run is the deliberate way
     * to try again.
     *
     * @return list<string>
     */
    public static function attemptable(): array
    {
        return [self::PENDING];
    }

    public static function isAttemptable(?string $status): bool
    {
        return $status !== null && in_array($status, self::attemptable(), true);
    }

    public static function isTerminal(?string $status): bool
    {
        return in_array($status, [self::PUBLISHED, self::REFUSED], true);
    }

    public static function label(?string $status): string
    {
        return match ($status) {
            self::PENDING => 'Menunggu diproses',
            self::PUBLISHED => 'Dipublikasikan',
            self::REFUSED => 'Ditolak',
            self::SKIPPED => 'Tidak diproses',
            default => (string) $status,
        };
    }
}
