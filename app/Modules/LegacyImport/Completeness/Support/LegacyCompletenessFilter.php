<?php

declare(strict_types=1);

namespace App\Modules\LegacyImport\Completeness\Support;

/**
 * FEATURE-LEGACY-PATIENT-DOCUMENT-COMPLETENESS-1 — the closed set of status
 * filters the page offers, and the default.
 *
 * A FILTER NARROWS A REPORT; IT NEVER WIDENS AUTHORITY. Every one of these is
 * applied INSIDE the actor's already-resolved branch scope, so no value here
 * can surface a patient the viewer may not see. The branch scope is decided
 * before any of this is read.
 *
 * WHY "BELUM ADA RME" INCLUDES A VOIDED RECORD. The filter answers an
 * operational question — "whose archive do I still need to upload?" — and a
 * voided record leaves the slot free exactly as a never-filed one does. The
 * STATUS COLUMN is what distinguishes the two, so the operator still sees that
 * one is a correction and the other a first filing. Filtering on availability
 * and displaying the reason keeps both facts legible instead of merging them.
 */
final class LegacyCompletenessFilter
{
    /** Anything that is not COMPLETE, in-progress work included. The default. */
    public const INCOMPLETE = 'incomplete';

    /** Effective legacy RME needs an upload (MISSING or VOID). */
    public const MISSING_RME = 'missing_rme';

    /** Effective legacy odontogram needs an upload (MISSING or VOID). */
    public const MISSING_ODONTOGRAM = 'missing_odontogram';

    /** Both types need an upload. */
    public const MISSING_BOTH = 'missing_both';

    /** At least one type has a live lifecycle. Nothing to upload. */
    public const IN_PROGRESS = 'in_progress';

    /**
     * Both types published. Offered for audit, never the default — the page
     * exists to surface what is unfinished.
     */
    public const COMPLETE = 'complete';

    /** @var list<string> */
    public const ALL = [
        self::INCOMPLETE,
        self::MISSING_RME,
        self::MISSING_ODONTOGRAM,
        self::MISSING_BOTH,
        self::IN_PROGRESS,
        self::COMPLETE,
    ];

    public const DEFAULT = self::INCOMPLETE;

    private function __construct() {}

    public static function isValid(?string $filter): bool
    {
        return $filter !== null && in_array($filter, self::ALL, true);
    }

    /**
     * Fail safe rather than fail closed, deliberately: an unrecognised filter
     * falls back to the DEFAULT, which is the narrowest useful view and shows
     * strictly less than "everything". A read-only report has nothing to gain
     * from refusing the request outright, and the branch scope — the control
     * that actually matters — is applied regardless of what this returns.
     */
    public static function normalize(?string $filter): string
    {
        return self::isValid($filter) ? (string) $filter : self::DEFAULT;
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return [
            self::INCOMPLETE => 'Semua Belum Lengkap',
            self::MISSING_RME => 'Belum Ada RME',
            self::MISSING_ODONTOGRAM => 'Belum Ada Odontogram',
            self::MISSING_BOTH => 'Belum Ada Keduanya',
            self::IN_PROGRESS => 'Dalam Proses',
            self::COMPLETE => 'Lengkap',
        ];
    }

    public static function label(string $filter): string
    {
        return self::options()[$filter] ?? $filter;
    }
}
