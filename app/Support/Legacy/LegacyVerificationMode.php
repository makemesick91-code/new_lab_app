<?php

declare(strict_types=1);

namespace App\Support\Legacy;

/**
 * REVISION-LEGACY-VISIT-BOUND-PREVERIFIED-INGESTION-1 — how the clinical dates
 * on a legacy document came to be trusted.
 *
 * ONE VOCABULARY, TWO ARCHIVES. Legacy RME and Legacy Odontogram are separate
 * bounded contexts with deliberately separate everything else, but the meaning
 * of "a human verified these dates at a real visit" must be identical in both
 * or the governance statement is not a statement at all. So the mode strings
 * live here, once, in the same `App\Support\*` home the other genuinely
 * cross-module primitives use (ClinicalClock, SensitiveValueMasker).
 *
 * WHAT `VISIT_PREVERIFIED` MEANS — AND THE THREE THINGS IT DOES NOT.
 *
 *   MEANS:        the clinical DATES were read off the source document and
 *                 attested once, by a named account, at a named real visit,
 *                 bound to a named file hash.
 *
 *   DOES NOT MEAN: separation of duties is bypassed. LEGACY-RME-SOD-1 stays
 *                  armed and the uploader still cannot certify their own
 *                  document.
 *   DOES NOT MEAN: the document self-publishes. There is no auto-publish path
 *                  and no dormant flag that would create one.
 *   DOES NOT MEAN: the uploader gained publish or void authority. Those
 *                  permissions are unchanged.
 *
 * The single thing it removes is DUPLICATE DATE VERIFICATION: the downstream
 * checker reads the attested dates as read-only evidence and never re-enters,
 * re-selects or independently re-certifies them.
 *
 * ABSENCE IS MEANINGFUL, NOT MISSING. A NULL mode means the document did NOT
 * come through the visit-bound path — it came through the backlog/mass
 * migration path, which keeps its own canonical review flow untouched. NULL is
 * therefore a true statement about every row filed before this sprint, and
 * nothing is backfilled to pretend otherwise.
 *
 * THE MODE IS EXPLICIT, NEVER INFERRED. Nothing may conclude "this is
 * preverified" from the mere presence of a visit id, a non-null reviewer or a
 * populated date. A caller that wants the preverified path says so, and the
 * server decides whether it earned it.
 */
final class LegacyVerificationMode
{
    /**
     * The canonical backlog path: dates are verified in the review workflow,
     * exactly as they always have been. Represented as NULL on the row; this
     * constant names it for code that needs to say "the ordinary path".
     */
    public const STANDARD = 'STANDARD';

    /**
     * Dates verified once, at a real visit, by an authorized human, bound to a
     * source hash. Maker-checker still applies downstream.
     */
    public const VISIT_PREVERIFIED = 'VISIT_PREVERIFIED';

    /**
     * Modes that may be persisted in `verification_mode`.
     *
     * STANDARD is deliberately absent: the ordinary path stores NULL rather
     * than a sentinel string, so a row can never be half-migrated between two
     * spellings of "normal".
     *
     * @var list<string>
     */
    public const PERSISTED = [
        self::VISIT_PREVERIFIED,
    ];

    private function __construct() {}

    public static function isPersisted(?string $mode): bool
    {
        return $mode !== null && in_array($mode, self::PERSISTED, true);
    }

    /**
     * Whether a stored mode denotes the visit-bound preverified path.
     *
     * Deliberately strict equality against the one constant rather than a
     * "not null" test: a future third mode must not silently inherit the
     * read-only-dates behaviour that was reasoned about only for this one.
     */
    public static function isVisitPreverified(?string $mode): bool
    {
        return $mode === self::VISIT_PREVERIFIED;
    }
}
