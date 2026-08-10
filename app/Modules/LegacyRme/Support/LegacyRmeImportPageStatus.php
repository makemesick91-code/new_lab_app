<?php

declare(strict_types=1);

namespace App\Modules\LegacyRme\Support;

/**
 * LEGACY-RME-PDF-1A — staging status of a single legacy RME page.
 *
 * Fail-closed by design: a page starts PENDING and only an explicitly rendered
 * and verified page reaches READY. Publishing requires READY, so an unprocessed
 * page can never slip into the archive.
 */
final class LegacyRmeImportPageStatus
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

    /** Only these statuses may be carried into a published record. */
    public const PUBLISHABLE = [
        self::READY,
    ];

    /**
     * LEGACY-RME-PDF-1C — statuses whose rendered image may still be streamed
     * from the STAGING screen.
     *
     * Deliberately wider than PUBLISHABLE: once an import is published its
     * pages become PUBLISHED, and the staging detail screen is the operator's
     * evidence of what was reviewed. Keeping that readable is an audit
     * property; it must not be conflated with "may be published again", which
     * stays READY-only.
     *
     * @var list<string>
     */
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
