<?php

declare(strict_types=1);

namespace App\Modules\LegacyRme\Support;

/**
 * LEGACY-RME-PDF-1A — status of a published legacy RME record.
 *
 * A published record is immutable. The only supported correction is VOID (with
 * a reason) followed by a fresh import — never an in-place edit and never a
 * hard delete.
 */
final class LegacyRmeRecordStatus
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
