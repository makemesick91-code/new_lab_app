<?php

declare(strict_types=1);

namespace App\Modules\Branch\Services;

use App\Modules\Branch\Models\Branch;
use App\Modules\Branch\Support\BranchCodeAlias;

/**
 * ACTIVATION-RME-DOCTOR-PERFORMED-TREATMENTS-1 — the shared, STRICT reading of a
 * source-controlled branch-code allowlist (a capability's "where may an ON flag
 * reach").
 *
 * It answers two questions and decides nothing else:
 *   1. inspect(): is the declared list well formed, and which CANONICAL codes does
 *      it name? A list that is not a list of non-empty strings is MALFORMED as a
 *      whole (never "the good entries"), and MAIN is reported, never admitted.
 *   2. liveBranchIds(): which ACTIVE, RME-enabled branches carry those canonical
 *      codes EXACTLY (an alias-coded branch, a prefix or an inactive branch never
 *      matches)?
 *
 * Policy — how many branches a pilot may name, whether MAIN listed invalidates
 * the list — belongs to the capability's own scope class. The earlier
 * `RmeInvoiceCorrectionPilotScope` and `RmeRefundRequestPilotScope` carry a
 * lenient copy of step 2 (they skip a non-string entry); they are deliberately
 * left untouched here (financial pilots, separate review) and are a recorded
 * consolidation candidate.
 */
final class BranchAllowlistResolver
{
    public function __construct(
        private readonly BranchService $branches,
    ) {}

    /**
     * @return array{malformed: bool, main_listed: bool, codes: list<string>}
     */
    public function inspect(mixed $raw): array
    {
        if (! is_array($raw) || ! array_is_list($raw)) {
            return ['malformed' => true, 'main_listed' => false, 'codes' => []];
        }

        $codes = [];
        $mainListed = false;

        foreach ($raw as $value) {
            if (! is_string($value)) {
                return ['malformed' => true, 'main_listed' => false, 'codes' => []];
            }

            $canonical = BranchCodeAlias::canonicalize($value);

            if ($canonical === null) {
                return ['malformed' => true, 'main_listed' => false, 'codes' => []];
            }

            if ($canonical === Branch::MAIN_CODE) {
                $mainListed = true;

                continue;
            }

            $codes[$canonical] = true;
        }

        return ['malformed' => false, 'main_listed' => $mainListed, 'codes' => array_keys($codes)];
    }

    /**
     * Canonical code => id of every active RME-enabled branch whose stored code
     * IS one of the given canonical codes (one query).
     *
     * @param  list<string>  $canonicalCodes
     * @return array<string, int>
     */
    public function liveBranchIds(array $canonicalCodes): array
    {
        if ($canonicalCodes === []) {
            return [];
        }

        $ids = [];

        foreach ($this->branches->listRmeEnabled() as $branch) {
            // Only the DECLARED side is canonicalized: the live branch must carry
            // the canonical code itself, so an alias-coded branch never matches.
            $code = BranchCodeAlias::normalize((string) $branch->code);

            if ($code !== null && $code !== Branch::MAIN_CODE && in_array($code, $canonicalCodes, true)) {
                $ids[$code] = (int) $branch->id;
            }
        }

        ksort($ids);

        return $ids;
    }
}
