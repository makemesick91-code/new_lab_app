<?php

declare(strict_types=1);

namespace App\Modules\RmeInvoice\Support;

use App\Modules\Branch\Models\Branch;
use App\Modules\Branch\Services\BranchService;
use App\Modules\Branch\Support\BranchCodeAlias;

/**
 * FEATURE-RME-REFUND-SETTLEMENT-1 (rule 216; RF-3, RF-10) — the source-controlled
 * settlement pilot: which branches may settle at all, and which accounts are
 * designated as settlers for which of them.
 *
 * The designation list SHIPS EMPTY (the Owner has not appointed the settler role
 * or people), so it admits nobody. It is fail-closed in the strict sense: one
 * malformed entry, a duplicated user or an unknown/MAIN branch code voids the WHOLE
 * list rather than being skipped — a payout authority must never be inferred from
 * a half-readable configuration. A designation only NARROWS: the service still
 * requires a usable account holding `settle_rme_refund` that is not the request's
 * approver, re-checked under the locks on every action.
 */
final class RmeRefundSettlementPilot
{
    public const BRANCH_CODES_KEY = 'rme_refund_settlement.pilot.branch_codes';

    public const SETTLERS_KEY = 'rme_refund_settlement.pilot.settlers';

    public function __construct(
        private readonly BranchService $branches,
    ) {}

    /** @return list<string> */
    public function declaredCodes(): array
    {
        $raw = config(self::BRANCH_CODES_KEY, []);
        if (! is_array($raw)) {
            return [];
        }

        $codes = [];
        foreach ($raw as $value) {
            $canonical = is_string($value) ? BranchCodeAlias::canonicalize($value) : null;
            if ($canonical === null || $canonical === Branch::MAIN_CODE) {
                continue;
            }
            $codes[$canonical] = true;
        }

        return array_keys($codes);
    }

    /** @return array<string, int> canonical code => active RME-enabled branch id */
    private function rmeBranchIdsByCode(): array
    {
        $map = [];
        foreach ($this->branches->listRmeEnabled() as $branch) {
            $code = BranchCodeAlias::normalize((string) $branch->code);
            if ($code !== null && $code !== Branch::MAIN_CODE) {
                $map[$code] = (int) $branch->id;
            }
        }

        return $map;
    }

    /** @return list<int> */
    public function admittedBranchIds(): array
    {
        $map = $this->rmeBranchIdsByCode();
        $ids = [];
        foreach ($this->declaredCodes() as $code) {
            if (isset($map[$code])) {
                $ids[] = $map[$code];
            }
        }
        sort($ids);

        return $ids;
    }

    public function admits(int $branchId): bool
    {
        return in_array($branchId, $this->admittedBranchIds(), true);
    }

    /**
     * user id => canonical branch codes, or null when the list is unreadable.
     *
     * @return array<int, list<string>>|null
     */
    public function designations(): ?array
    {
        $raw = config(self::SETTLERS_KEY, []);
        if (! is_array($raw) || ! array_is_list($raw)) {
            return null;
        }

        $designations = [];
        foreach ($raw as $entry) {
            if (! is_array($entry) || array_diff(array_keys($entry), ['user_id', 'branch_codes']) !== []) {
                return null;
            }

            $userId = $entry['user_id'] ?? null;
            $codes = $entry['branch_codes'] ?? null;
            if (! is_int($userId) || $userId <= 0 || isset($designations[$userId]) || ! is_array($codes) || $codes === []) {
                return null; // malformed or duplicated: void the whole list
            }

            $canonical = [];
            foreach ($codes as $code) {
                $value = is_string($code) ? BranchCodeAlias::canonicalize($code) : null;
                if ($value === null || $value === Branch::MAIN_CODE) {
                    return null;
                }
                $canonical[$value] = true;
            }

            $designations[$userId] = array_keys($canonical);
        }

        return $designations;
    }

    /**
     * The branches this account may settle in: its designated codes ∩ the pilot
     * ∩ active RME-enabled branches. Empty for anyone not designated.
     *
     * @return list<int>
     */
    public function branchIdsFor(int $userId): array
    {
        $designations = $this->designations();
        if ($designations === null || $userId <= 0 || ! isset($designations[$userId])) {
            return [];
        }

        $map = $this->rmeBranchIdsByCode();
        $pilot = $this->declaredCodes();
        $ids = [];
        foreach ($designations[$userId] as $code) {
            if (in_array($code, $pilot, true) && isset($map[$code])) {
                $ids[] = $map[$code];
            }
        }
        $ids = array_values(array_unique($ids));
        sort($ids);

        return $ids;
    }

    public function isDesignated(int $userId): bool
    {
        $designations = $this->designations();

        return $designations !== null && isset($designations[$userId]);
    }
}
