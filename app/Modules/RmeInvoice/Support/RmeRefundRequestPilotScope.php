<?php

declare(strict_types=1);

namespace App\Modules\RmeInvoice\Support;

use App\Modules\Branch\Models\Branch;
use App\Modules\Branch\Services\BranchService;
use App\Modules\Branch\Support\BranchCodeAlias;

/**
 * FEATURE-RME-BILLING-REFUND-REQUEST-APPROVAL-1 (rule 215; owner decision RF-3 —
 * pilot SPN4) — the source-controlled branch allowlist. Fail-closed: a missing,
 * malformed or empty list admits no branch; MAIN is never admitted.
 */
final class RmeRefundRequestPilotScope
{
    public const CONFIG_KEY = 'rme_refund.pilot.branch_codes';

    public function __construct(
        private readonly BranchService $branches,
    ) {}

    /** @return list<string> */
    public function declaredCodes(): array
    {
        $raw = config(self::CONFIG_KEY, []);
        if (! is_array($raw)) {
            return [];
        }

        $codes = [];
        foreach ($raw as $value) {
            if (! is_string($value)) {
                continue;
            }
            $canonical = BranchCodeAlias::canonicalize($value);
            if ($canonical === null || $canonical === Branch::MAIN_CODE) {
                continue;
            }
            $codes[$canonical] = true;
        }

        return array_keys($codes);
    }

    /** @return list<int> */
    public function admittedBranchIds(): array
    {
        $declared = $this->declaredCodes();
        if ($declared === []) {
            return [];
        }

        $ids = [];
        foreach ($this->branches->listRmeEnabled() as $branch) {
            $code = BranchCodeAlias::normalize((string) $branch->code);
            if ($code !== null && $code !== Branch::MAIN_CODE && in_array($code, $declared, true)) {
                $ids[] = (int) $branch->id;
            }
        }
        sort($ids);

        return $ids;
    }

    public function admits(int $branchId): bool
    {
        return in_array($branchId, $this->admittedBranchIds(), true);
    }
}
