<?php

declare(strict_types=1);

namespace App\Modules\LegacyImport\BatchPublish\Support;

/**
 * Run lifecycle — PR2 §9, §10.
 *
 * ORCHESTRATION ONLY. §10 is explicit that a publish-session model must not
 * become a second clinical state machine, so nothing in this vocabulary is ever
 * consulted to decide whether a document may publish. The canonical import
 * status and the archive record's `UNIQUE(source_import_id)` remain clinical
 * truth; these values describe an operator's work-queue.
 *
 * PUBLISHING is deliberately re-enterable from itself: a bounded pass ends,
 * the operator resumes, and the per-ITEM status (not this column) is what stops
 * finished work being redone.
 *
 * COMPLETED_WITH_REFUSALS is its own terminal state because partial success is
 * the designed outcome (§4). A run of 100 that publishes 97 and refuses 3 is a
 * GOOD run: folding it into COMPLETED would hide the three rows an operator
 * must act on, and folding it into a failure would claim 97 publications did
 * not happen.
 */
final class LegacyBatchPublishRunStatus
{
    private function __construct() {}

    /** Selection in progress. Nothing attempted yet. */
    public const OPEN = 'OPEN';

    /** A publish pass is walking the selected items. Resumable. */
    public const PUBLISHING = 'PUBLISHING';

    /** Every selected item was attempted and none was refused. */
    public const COMPLETED = 'COMPLETED';

    /** Attempted everything it could; at least one refusal to look at. */
    public const COMPLETED_WITH_REFUSALS = 'COMPLETED_WITH_REFUSALS';

    /** Closed without finishing. Already-published items stay published. */
    public const ABANDONED = 'ABANDONED';

    public const TRANSITIONS = [
        self::OPEN => [self::PUBLISHING, self::ABANDONED],
        // Self-transition is intentional — a resumed pass re-enters PUBLISHING.
        self::PUBLISHING => [
            self::PUBLISHING,
            self::COMPLETED,
            self::COMPLETED_WITH_REFUSALS,
            self::ABANDONED,
        ],
        // A run with refusals is TERMINAL. Retrying a refusal means selecting
        // the document into a NEW run, so the refusal stays visible in this
        // one's report rather than being quietly overwritten.
        self::COMPLETED_WITH_REFUSALS => [],
        self::COMPLETED => [],
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

    /** May the operator still change the selection? */
    public static function isMutable(?string $status): bool
    {
        return $status === self::OPEN;
    }

    public static function isTerminal(?string $status): bool
    {
        return in_array(
            $status,
            [self::COMPLETED, self::COMPLETED_WITH_REFUSALS, self::ABANDONED],
            true
        );
    }

    public static function label(?string $status): string
    {
        return match ($status) {
            self::OPEN => 'Pemilihan dokumen',
            self::PUBLISHING => 'Sedang dipublikasikan',
            self::COMPLETED => 'Selesai',
            self::COMPLETED_WITH_REFUSALS => 'Selesai dengan penolakan',
            self::ABANDONED => 'Ditinggalkan',
            default => (string) $status,
        };
    }
}
