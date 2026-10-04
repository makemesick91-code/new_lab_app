<?php

declare(strict_types=1);

namespace App\Modules\LegacyImport\Completeness\Support;

use App\Modules\LegacyImport\Services\LegacySingleActiveDocumentService;
use App\Modules\LegacyRme\Support\LegacyRmeImportStatus;
use App\Modules\LegacyRme\Support\LegacyRmeRecordStatus;

/**
 * FEATURE-LEGACY-PATIENT-DOCUMENT-COMPLETENESS-1 — the EFFECTIVE state of one
 * legacy document type for one patient.
 *
 * NOT A NEW LIFECYCLE. The canonical state machines are
 * {@see LegacyRmeImportStatus} (9 staging states),
 * {@see LegacyRmeRecordStatus} (2 record states)
 * and their odontogram twins. This class invents none of them: it is a
 * read-only PROJECTION that answers the single question the completeness page
 * asks — "does this patient currently have a usable archive of this type, and
 * if not, why not?"
 *
 * THE DERIVATION ORDER IS THE SAME AS OCCUPANCY, AND THAT IS LOAD-BEARING.
 * {@see LegacySingleActiveDocumentService::occupancyFor()}
 * consults the ARCHIVE first and the staging table second, because a staging
 * row keeps its historical PUBLISHED status forever and would otherwise look
 * like an occupant after its record had been voided. This projection follows
 * the identical order:
 *
 *     published record        -> PUBLISHED
 *     else slot-occupying row -> IN_PROGRESS
 *     else void record        -> VOID
 *     else                    -> MISSING
 *
 * Matching it is not a tidiness preference. If this page reported MISSING for a
 * slot the server considers OCCUPIED, it would invite an operator to start a
 * second upload that the single-active-document guard then refuses — the exact
 * "no duplicate-upload encouragement" failure the owner called out. A test pins
 * the agreement against the real service rather than against a copy of its
 * rules.
 */
final class LegacyArchiveDocumentState
{
    /**
     * No lifecycle at all, or only terminal ones that produced no archive (a
     * CANCELLED import, or a staging row whose record has been voided and not
     * replaced is VOID rather than this).
     *
     * The slot is free: a new upload is the next step.
     */
    public const MISSING = 'MISSING';

    /**
     * A staging row is alive and owns the patient's slot — DRAFT, UPLOADED,
     * QUEUED, PROCESSING, READY_FOR_REVIEW, REVIEWED or FAILED.
     *
     * FAILED IS IN PROGRESS, NOT MISSING, because FAILED transitions to QUEUED:
     * the import is retryable and still holds the slot. Reporting it as MISSING
     * would send the operator to upload a replacement the server will refuse.
     */
    public const IN_PROGRESS = 'IN_PROGRESS';

    /** A published, non-void archive record exists. This is the only complete state. */
    public const PUBLISHED = 'PUBLISHED';

    /**
     * A record exists but has been VOIDed and nothing has replaced it.
     *
     * The slot is RELEASED (that is the documented correction path: VOID with a
     * reason, then a fresh import), so operationally this needs an upload just
     * like MISSING — but the operator must be told it is a correction, not a
     * first filing, which is why it is its own state and not folded into MISSING.
     */
    public const VOID = 'VOID';

    /** @var list<string> */
    public const ALL = [
        self::MISSING,
        self::IN_PROGRESS,
        self::PUBLISHED,
        self::VOID,
    ];

    private function __construct() {}

    /**
     * Derive the effective state from the four facts the query reports.
     *
     * The caller supplies booleans rather than rows on purpose: the page is a
     * set-based report over thousands of patients, so the facts arrive as
     * aggregate flags from SQL and never as hydrated models.
     */
    public static function derive(
        bool $hasPublishedRecord,
        bool $hasSlotOccupyingImport,
        bool $hasVoidRecord,
    ): string {
        if ($hasPublishedRecord) {
            return self::PUBLISHED;
        }

        if ($hasSlotOccupyingImport) {
            return self::IN_PROGRESS;
        }

        if ($hasVoidRecord) {
            return self::VOID;
        }

        return self::MISSING;
    }

    public static function isValid(?string $state): bool
    {
        return $state !== null && in_array($state, self::ALL, true);
    }

    /** The one state that counts toward a complete archive. */
    public static function isComplete(string $state): bool
    {
        return $state === self::PUBLISHED;
    }

    /**
     * True when a NEW upload is the operator's next step.
     *
     * MISSING and VOID both leave the slot free. IN_PROGRESS does not, and
     * PUBLISHED does not — which is what keeps the "Belum Ada" filter from
     * listing a patient whose upload would be refused.
     */
    public static function needsUpload(string $state): bool
    {
        return $state === self::MISSING || $state === self::VOID;
    }
}
