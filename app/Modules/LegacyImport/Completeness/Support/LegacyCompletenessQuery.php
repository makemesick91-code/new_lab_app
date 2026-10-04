<?php

declare(strict_types=1);

namespace App\Modules\LegacyImport\Completeness\Support;

/**
 * FEATURE-LEGACY-PATIENT-DOCUMENT-COMPLETENESS-1 — the fully resolved,
 * server-decided shape of one completeness request.
 *
 * WHY THIS EXISTS RATHER THAN PASSING THE REQUEST DOWN. Every field here has
 * already been decided by the service from the server's own authorities. The
 * repository therefore cannot be handed an unvalidated filter, an unbounded page
 * size or — the one that matters — a branch the actor may not read. Carrying
 * the resolved scope in the value object is what makes "the repository never
 * widens a scope" a structural property instead of a convention.
 *
 * `branchIds` IS ALWAYS AN INTERSECTION, NEVER A REQUEST VALUE. The service
 * resolves the actor's authorized branches first, then narrows by any requested
 * branch. A branch the actor cannot read is dropped on the way in, so a crafted
 * `?branch_id=` can only ever make this list smaller.
 *
 * AN EMPTY `branchIds` MEANS NO ROWS. It never means "no filter". That is the
 * fail-closed contract the whole legacy archive already follows, and the
 * repository enforces it with an impossible predicate rather than by omitting a
 * WHERE clause.
 */
final class LegacyCompletenessQuery
{
    public const PER_PAGE = 25;

    /**
     * @param  list<int>  $branchIds  already-authorized branch ids; empty = deny all
     * @param  string  $filter  one of {@see LegacyCompletenessFilter::ALL}
     * @param  bool  $includeUnscopedBranch  governance tier only: also show legacy
     *                                       patients that carry no branch at all
     */
    public function __construct(
        public readonly array $branchIds,
        public readonly string $filter,
        public readonly ?string $search,
        public readonly bool $includeUnscopedBranch,
        public readonly int $perPage = self::PER_PAGE,
    ) {}

    /**
     * True when the actor's scope cannot produce a single row.
     *
     * Note the asymmetry: an empty branch list is still readable for the
     * governance tier when unscoped rows are included, because a branchless
     * legacy patient is a real row that only that tier can see.
     */
    public function deniesEverything(): bool
    {
        return $this->branchIds === [] && ! $this->includeUnscopedBranch;
    }
}
