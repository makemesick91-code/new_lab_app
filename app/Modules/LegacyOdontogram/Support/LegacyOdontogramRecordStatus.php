<?php

declare(strict_types=1);

namespace App\Modules\LegacyOdontogram\Support;

/**
 * FIX-04b — the state of a PUBLISHED legacy odontogram record.
 *
 * Two states and exactly one edge. There is no EDITED, no SUPERSEDED and no
 * DELETED: a published chart is immutable clinical evidence, and the only
 * supported correction is a reasoned VOID (which retracts without erasing)
 * followed by a fresh import.
 */
final class LegacyOdontogramRecordStatus
{
    public const PUBLISHED = 'PUBLISHED';

    public const VOID = 'VOID';

    /** @var list<string> */
    public const ALL = [
        self::PUBLISHED,
        self::VOID,
    ];

    /** @var array<string, list<string>> */
    public const TRANSITIONS = [
        self::PUBLISHED => [self::VOID],
        self::VOID => [],
    ];

    private function __construct() {}

    public static function isValid(?string $status): bool
    {
        return $status !== null && in_array($status, self::ALL, true);
    }

    public static function canTransition(?string $from, ?string $to): bool
    {
        if (! self::isValid($from) || ! self::isValid($to)) {
            return false;
        }

        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }
}
