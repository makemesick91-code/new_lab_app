<?php

declare(strict_types=1);

namespace App\Modules\LegacyImport\Repositories;

use App\Modules\LegacyImport\Interfaces\LegacyImportDailyQuotaRepositoryInterface;
use App\Modules\LegacyImport\Models\LegacyImportDailyQuota;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * FEATURE-LEGACY-IMPORT-HUB-1 — the daily quota ledger, and the only place its
 * rows are locked.
 */
class LegacyImportDailyQuotaRepository implements LegacyImportDailyQuotaRepositoryInterface
{
    private const TABLE = 'ops_legacy_import_daily_quotas';

    public function ensureBucket(string $importType, int $branchId, string $quotaDate): void
    {
        DB::table(self::TABLE)->insertOrIgnore([
            'import_type' => $importType,
            'branch_id' => $branchId,
            'quota_date' => $quotaDate,
            'consumed' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function lockBuckets(string $importType, array $branchIds, string $quotaDate): Collection
    {
        if ($branchIds === []) {
            return collect();
        }

        return LegacyImportDailyQuota::query()
            ->where('import_type', $importType)
            ->whereIn('branch_id', $branchIds)
            ->whereDate('quota_date', $quotaDate)
            // Deterministic acquisition order. Two requests that need the same
            // pair of branches take them in the same sequence, so they queue
            // instead of deadlocking.
            ->orderBy('branch_id')
            ->lockForUpdate()
            ->get();
    }

    public function addConsumed(LegacyImportDailyQuota $bucket, int $units): void
    {
        // `increment` issues `consumed = consumed + ?` in SQL rather than
        // writing back a value this process read, so it cannot lose a
        // concurrent update even if a future caller reaches it without the
        // lock. The lock is still what makes the DECISION safe; this only
        // makes the WRITE safe.
        $bucket->increment('consumed', $units);
    }

    public function consumed(string $importType, int $branchId, string $quotaDate): int
    {
        return (int) DB::table(self::TABLE)
            ->where('import_type', $importType)
            ->where('branch_id', $branchId)
            ->whereDate('quota_date', $quotaDate)
            ->sum('consumed');
    }

    public function consumedMatrix(array $importTypes, array $branchIds, string $quotaDate): array
    {
        if ($importTypes === [] || $branchIds === []) {
            return [];
        }

        $rows = DB::table(self::TABLE)
            ->select(['import_type', 'branch_id', 'consumed'])
            ->whereIn('import_type', $importTypes)
            ->whereIn('branch_id', $branchIds)
            ->whereDate('quota_date', $quotaDate)
            ->get();

        $matrix = [];

        foreach ($rows as $row) {
            $matrix[$row->import_type.'|'.$row->branch_id] = (int) $row->consumed;
        }

        return $matrix;
    }
}
