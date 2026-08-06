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

    private function __construct() {}

    public static function isValid(?string $status): bool
    {
        return $status !== null && in_array($status, self::ALL, true);
    }

    public static function isPublishable(?string $status): bool
    {
        return $status !== null && in_array($status, self::PUBLISHABLE, true);
    }
}
