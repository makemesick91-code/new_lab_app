<?php

declare(strict_types=1);

namespace App\Modules\LegacyOdontogram\Support;

/**
 * FIX-04b — the state of one rasterized staging page.
 *
 * Fail closed: the column defaults to PENDING and ONLY `READY` is publishable,
 * so a half-rendered batch can never be promoted into the immutable archive.
 */
final class LegacyOdontogramImportPageStatus
{
    public const PENDING = 'PENDING';

    public const PROCESSING = 'PROCESSING';

    public const READY = 'READY';

    public const FAILED = 'FAILED';

    public const PUBLISHED = 'PUBLISHED';

    public const CANCELLED = 'CANCELLED';

    /** @var list<string> */
    public const ALL = [
        self::PENDING,
        self::PROCESSING,
        self::READY,
        self::FAILED,
        self::PUBLISHED,
        self::CANCELLED,
    ];

    /** @var list<string> */
    public const PUBLISHABLE = [
        self::READY,
    ];

    /** @var list<string> */
    public const VIEWABLE = [
        self::READY,
        self::PUBLISHED,
    ];

    private function __construct() {}

    public static function isValid(?string $status): bool
    {
        return $status !== null && in_array($status, self::ALL, true);
    }

    public static function isPublishable(?string $status): bool
    {
        return $status !== null && in_array($status, self::PUBLISHABLE, true);
    }

    public static function isViewable(?string $status): bool
    {
        return $status !== null && in_array($status, self::VIEWABLE, true);
    }
}
