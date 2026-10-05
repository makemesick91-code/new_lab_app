<?php

declare(strict_types=1);

namespace App\Modules\LegacyImport\Completeness\Support;

use App\Modules\LegacyImport\Services\LegacySingleActiveDocumentService;

/**
 * FEATURE-LEGACY-PATIENT-DOCUMENT-COMPLETENESS-1 — the overall verdict for one
 * legacy patient, across BOTH document types.
 *
 * THE PRODUCT RULE, VERBATIM. A legacy patient is COMPLETE only when the
 * effective legacy RME is PUBLISHED and the effective legacy odontogram is
 * PUBLISHED. Everything else is incomplete.
 *
 * WHY IN_PROGRESS IS A SEPARATE VERDICT AND NOT A SHADE OF INCOMPLETE. Both are
 * "not complete", and both appear under the default "Semua Belum Lengkap"
 * filter — the verdict changes nothing about whether the archive is finished.
 * What it changes is the operator's NEXT ACTION. An INCOMPLETE patient needs an
 * upload; an IN_PROGRESS patient already has a lifecycle running and a second
 * upload would be refused by the single-active-document guard. Collapsing the
 * two would leave the page telling an operator to do something impossible,
 * which is precisely what the owner forbade.
 *
 * THE SLOTS ARE INDEPENDENT. A published RME never substitutes for a missing
 * odontogram or the reverse; there is deliberately no "has any legacy document"
 * shortcut here, for the same reason
 * {@see LegacySingleActiveDocumentService}
 * refuses to have one — that shortcut is how the two slots get fused.
 */
final class LegacyArchiveCompleteness
{
    /** Both document types hold a published, non-void archive. */
    public const COMPLETE = 'COMPLETE';

    /**
     * Not complete, and at least one type has a live lifecycle (including a
     * retryable FAILED import). Nothing to upload; wait, retry, or finish the
     * review.
     */
    public const IN_PROGRESS = 'IN_PROGRESS';

    /**
     * Not complete, and no type has a live lifecycle. At least one document is
     * missing outright or sits on a voided record. An upload is the next step.
     */
    public const INCOMPLETE = 'INCOMPLETE';

    /** @var list<string> */
    public const ALL = [
        self::COMPLETE,
        self::IN_PROGRESS,
        self::INCOMPLETE,
    ];

    private function __construct() {}

    public static function derive(string $rmeState, string $odontogramState): string
    {
        if (LegacyArchiveDocumentState::isComplete($rmeState)
            && LegacyArchiveDocumentState::isComplete($odontogramState)) {
            return self::COMPLETE;
        }

        if ($rmeState === LegacyArchiveDocumentState::IN_PROGRESS
            || $odontogramState === LegacyArchiveDocumentState::IN_PROGRESS) {
            return self::IN_PROGRESS;
        }

        return self::INCOMPLETE;
    }

    public static function isValid(?string $verdict): bool
    {
        return $verdict !== null && in_array($verdict, self::ALL, true);
    }

    /**
     * The single definition of "shows up under the default filter".
     *
     * Anything that is not COMPLETE is incomplete work, IN_PROGRESS included.
     */
    public static function countsAsIncomplete(string $verdict): bool
    {
        return $verdict !== self::COMPLETE;
    }
}
