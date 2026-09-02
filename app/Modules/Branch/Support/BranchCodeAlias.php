<?php

declare(strict_types=1);

namespace App\Modules\Branch\Support;

/**
 * The SINGLE canonical answer to "which branch code is this?".
 *
 * THE DECISIONS, in the order they were taken:
 *
 *   REVISION-TELKOMAS-BRANCH-CODE-TKM1-TO-TLK1-1
 *     Cabang Telkomas' canonical branch code is `TLK1`; `TKM1` is DEPRECATED.
 *
 *   REVISION-SUNU-BRANCH-CODE-SUN4-TO-SPN4-1
 *     Cabang Sunu's canonical branch code is `SPN4`; `SUN4` is DEPRECATED.
 *
 * In both cases the deprecated and canonical codes name the SAME branch
 * identity — the same row in `mst_branches`, the same patients, the same
 * history. Only the canonical token the application emits changed.
 *
 * A SECOND REVISION IS WHY THIS IS A REGISTRY AND NOT A PAIR OF CONSTANTS. Each
 * entry below is one clinic that was renamed; the behaviour is identical for
 * every one of them, so adding a branch is adding a row, never a code path.
 *
 * WHY A MAP AND NOT A CONDITIONAL. A branch code is read in several places that
 * must agree exactly: the branch-code segment of a Nomor RM, the legacy-archive
 * rollout allowlist, the master-data registry, and patient search. Scattering
 * `$code === 'TKM1' || $code === 'TLK1'` across those call sites is how the two
 * halves of a rename drift apart — one path accepts the historical code, another
 * does not, and a patient's history becomes unreachable from one screen while
 * being visible on the next. The mapping is declared once, here.
 *
 * DIRECTION IS DELIBERATE AND ONE-WAY:
 *
 *     canonicalize('TKM1')  →  'TLK1'   historical alias, accepted
 *     canonicalize('TLK1')  →  'TLK1'   canonical, unchanged
 *     canonicalize('LDK2')  →  'LDK2'   unrelated code, returned untouched
 *     canonicalize('XXXX')  →  'XXXX'   unknown, returned untouched → FAILS CLOSED
 *
 * An alias is ACCEPTED on input and NEVER re-emitted: nothing here turns a
 * canonical code back into a historical one, so no new record can be created
 * carrying `TKM1`.
 *
 * FAIL CLOSED, ALWAYS. An unknown code is normalized (trimmed, upper-cased) and
 * returned AS IS — it is never mapped onto Telkomas or any other branch. The
 * caller then looks it up in `mst_branches` and finds nothing, which is the
 * correct refusal. This class never decides that an unrecognised code is
 * "probably" a branch, and it never widens a match: `TKM` is not `TKM1`, and
 * `TKM1-EXTRA` is neither. Matching is exact-token, after normalization.
 *
 * WHAT THIS CLASS IS NOT. It does not read the database, the environment or the
 * container, and it grants no authorization. Resolving a code to a branch row —
 * and deciding whether that branch is active, RME-enabled, admitted or inside
 * the actor's scope — stays with the services that already own those questions.
 */
final class BranchCodeAlias
{
    /**
     * Cabang Telkomas' canonical branch code.
     */
    public const TELKOMAS_CANONICAL = 'TLK1';

    /**
     * Cabang Telkomas' deprecated historical branch code.
     */
    public const TELKOMAS_HISTORICAL = 'TKM1';

    /**
     * Cabang Sunu's canonical branch code.
     */
    public const SUNU_CANONICAL = 'SPN4';

    /**
     * Cabang Sunu's deprecated historical branch code.
     */
    public const SUNU_HISTORICAL = 'SUN4';

    /**
     * DEPRECATED HISTORICAL CODE => CANONICAL CODE.
     *
     * Keys are codes the application must still RECOGNISE because they are
     * printed on documents already issued to patients and stored in identifiers
     * already assigned. Values are the codes it EMITS.
     *
     * A code may appear as a key exactly once, and a key may never also be a
     * value — a canonical code must be a fixed point, otherwise canonicalize()
     * would not converge. Both invariants are pinned by tests.
     *
     * @var array<string, string>
     */
    private const HISTORICAL_ALIASES = [
        self::TELKOMAS_HISTORICAL => self::TELKOMAS_CANONICAL,
        self::SUNU_HISTORICAL => self::SUNU_CANONICAL,
    ];

    /**
     * Trim and upper-case a branch code without interpreting it.
     *
     * Returns null for a blank value so a caller can distinguish "nothing was
     * supplied" from "a code was supplied and is unknown" — those two need
     * different error messages and must not collapse into one.
     */
    public static function normalize(?string $code): ?string
    {
        $normalized = strtoupper(trim((string) $code));

        return $normalized === '' ? null : $normalized;
    }

    /**
     * The canonical code for a branch code, accepting a historical alias.
     *
     * This is the method call sites should use before looking a code up in
     * `mst_branches` or comparing it against an allowlist.
     */
    public static function canonicalize(?string $code): ?string
    {
        $normalized = self::normalize($code);

        if ($normalized === null) {
            return null;
        }

        return self::HISTORICAL_ALIASES[$normalized] ?? $normalized;
    }

    /**
     * Whether a code is a deprecated historical alias rather than a canonical
     * code. Used to LABEL a value, never to grant anything.
     */
    public static function isHistoricalAlias(?string $code): bool
    {
        $normalized = self::normalize($code);

        return $normalized !== null && array_key_exists($normalized, self::HISTORICAL_ALIASES);
    }

    /**
     * Every deprecated code that maps onto the given canonical code.
     *
     * @return list<string>
     */
    public static function historicalAliasesFor(?string $canonicalCode): array
    {
        $canonical = self::normalize($canonicalCode);

        if ($canonical === null) {
            return [];
        }

        return array_values(array_keys(
            array_filter(
                self::HISTORICAL_ALIASES,
                static fn (string $target): bool => $target === $canonical,
            )
        ));
    }

    /**
     * Every branch code that names the same branch as the supplied one — the
     * canonical code first, then its deprecated aliases.
     *
     * This exists for LOOKUP BY STORED VALUE, where the historical form may
     * still be sitting in a column (or on a patient's old card) and both spellings
     * must find the same row. It is never used to CREATE anything.
     *
     * @return list<string>
     */
    public static function equivalentCodes(?string $code): array
    {
        $canonical = self::canonicalize($code);

        if ($canonical === null) {
            return [];
        }

        return array_values(array_unique(array_merge(
            [$canonical],
            self::historicalAliasesFor($canonical),
        )));
    }

    /**
     * The full deprecated => canonical map, for governance reporting and tests.
     *
     * @return array<string, string>
     */
    public static function all(): array
    {
        return self::HISTORICAL_ALIASES;
    }
}
