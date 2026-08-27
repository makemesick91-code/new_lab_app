<?php

declare(strict_types=1);

namespace App\Modules\LegacyImport\Interfaces;

use App\Modules\LegacyImport\Models\LegacyImportDailyQuota;
use Illuminate\Support\Collection;

/**
 * FEATURE-LEGACY-IMPORT-HUB-1 — persistence boundary for the daily quota ledger.
 *
 * The interface exists so the enterprise architecture baseline holds
 * (Service → RepositoryInterface → Repository → Model) and so the locking
 * primitives live in exactly one implementation. There is deliberately NO
 * `increment` that does not go through {@see lockBuckets()}: an unlocked
 * increment is the race this whole subsystem exists to prevent, and it must not
 * be reachable through the boundary.
 */
interface LegacyImportDailyQuotaRepositoryInterface
{
    /**
     * Create the bucket if it is absent, without failing when a concurrent
     * request created it first.
     *
     * Race-safe by construction (`ON CONFLICT DO NOTHING` on PostgreSQL): two
     * concurrent requests both attempt it, one wins, neither errors, and both
     * then lock the same surviving row.
     */
    public function ensureBucket(string $importType, int $branchId, string $quotaDate): void;

    /**
     * Read the day's buckets FOR UPDATE.
     *
     * Ordered by branch id so concurrent requests always acquire rows in the
     * same order and cannot form a deadlock cycle.
     *
     * @param  list<int>  $branchIds
     * @return Collection<int, LegacyImportDailyQuota>
     */
    public function lockBuckets(string $importType, array $branchIds, string $quotaDate): Collection;

    /**
     * Add units to a bucket that the caller has already locked.
     */
    public function addConsumed(LegacyImportDailyQuota $bucket, int $units): void;

    /**
     * Lock-free read of consumption for one branch on one day.
     *
     * ADVISORY ONLY. Used by the hub page and the pre-upload courtesy check;
     * never by the decision that admits a record.
     */
    public function consumed(string $importType, int $branchId, string $quotaDate): int;

    /**
     * Lock-free read of consumption for many branches and many types on one day.
     *
     * Returns a map keyed `"{importType}|{branchId}"`. One query, so the hub
     * page cannot become an N+1 as branches are added.
     *
     * @param  list<string>  $importTypes
     * @param  list<int>  $branchIds
     * @return array<string, int>
     */
    public function consumedMatrix(array $importTypes, array $branchIds, string $quotaDate): array;
}
