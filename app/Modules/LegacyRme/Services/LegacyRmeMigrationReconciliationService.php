<?php

declare(strict_types=1);

namespace App\Modules\LegacyRme\Services;

use App\Modules\LegacyRme\Models\LegacyRmeImport;
use App\Modules\LegacyRme\Models\LegacyRmeMigrationWave;
use App\Modules\LegacyRme\Models\LegacyRmeRecord;
use App\Modules\LegacyRme\Models\LegacyRmeWaveBranch;
use App\Modules\LegacyRme\Support\LegacyRmeImportStatus;
use App\Modules\LegacyRme\Support\LegacyRmeReconciliation;
use App\Modules\LegacyRme\Support\LegacyRmeRecordStatus;

/**
 * LEGACY-RME-PDF-ROLL-4 — does every document this wave accepted still add up?
 *
 * WHY COMPLETION CANNOT BE INFERRED FROM THE QUEUE. An empty render queue means
 * either "everything finished" or "nothing was ever consumed" — ROLL-2 shipped
 * with the second one and a green readiness report. `failed_jobs = 0` has the
 * same ambiguity. Neither is evidence, so neither is allowed to end a migration.
 *
 * The evidence is a balance instead:
 *
 *   accepted = published + cancelled + failed_unresolved + in_flight
 *
 * plus a second, independent check that the quota ledger agrees with the
 * documents actually accepted. Two counts derived from different tables, both
 * required to agree, is much harder to satisfy by accident than one count agreeing
 * with itself.
 *
 * ATTRIBUTION IS RECORDED, NOT INFERRED. Rows are counted by
 * `migration_wave_id`, written when the document was accepted. Guessing from a
 * branch plus a date window would be wrong exactly when it matters — a wave
 * spanning midnight, two waves touching one branch, a document uploaded on the
 * day a branch was drained.
 *
 * READ-ONLY. It counts and compares. It never repairs, never re-queues and never
 * rewrites a status: a migration that fixes its own books is not reconciled.
 *
 * PII-FREE. Counts and branch labels only.
 */
class LegacyRmeMigrationReconciliationService
{
    /**
     * Reconcile ONE branch of a wave.
     */
    public function forBranch(LegacyRmeMigrationWave $wave, LegacyRmeWaveBranch $branch): LegacyRmeReconciliation
    {
        return $this->build($wave, (int) $branch->branch_id, (string) $branch->branch_code);
    }

    /**
     * Reconcile the WHOLE wave, across every branch it accepted documents for.
     */
    public function forWave(LegacyRmeMigrationWave $wave): LegacyRmeReconciliation
    {
        return $this->build($wave, null, null);
    }

    /**
     * Per-branch reconciliation for every enrolled branch, keyed by branch code.
     *
     * @return array<string, LegacyRmeReconciliation>
     */
    public function perBranch(LegacyRmeMigrationWave $wave): array
    {
        $result = [];

        foreach ($wave->branches()->orderBy('branch_code')->get() as $branch) {
            $result[(string) $branch->branch_code] = $this->forBranch($wave, $branch);
        }

        return $result;
    }

    private function build(LegacyRmeMigrationWave $wave, ?int $branchId, ?string $branchCode): LegacyRmeReconciliation
    {
        $countsByStatus = LegacyRmeImport::query()
            ->where('migration_wave_id', $wave->getKey())
            ->when($branchId !== null, static fn ($query) => $query->where('origin_branch_id', $branchId))
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->map(static fn ($value): int => (int) $value)
            ->all();

        return LegacyRmeReconciliation::fromCounts(
            branchCode: $branchCode,
            countsByStatus: $countsByStatus,
            quotaConsumed: app(LegacyRmeMigrationQuotaService::class)->totalConsumed($wave, $branchId),
            staleProcessing: $this->staleProcessingCount($wave, $branchId),
            publishedRecords: $this->recordCount($wave, $branchId, LegacyRmeRecordStatus::PUBLISHED),
            voidRecords: $this->recordCount($wave, $branchId, LegacyRmeRecordStatus::VOID),
        );
    }

    /**
     * Staging rows stuck in PROCESSING past the configured threshold.
     *
     * SURFACED, NEVER MUTATED. A stalled worker and a slow 200-page render look
     * identical from here, and rewriting a clinical status from a clock is how
     * evidence quietly becomes wrong. The operator investigates and decides.
     */
    private function staleProcessingCount(LegacyRmeMigrationWave $wave, ?int $branchId): int
    {
        $seconds = (int) config('legacy_rme_operations.monitoring.stale_processing_seconds', 3600);

        if ($seconds <= 0) {
            return 0;
        }

        return LegacyRmeImport::query()
            ->where('migration_wave_id', $wave->getKey())
            ->when($branchId !== null, static fn ($query) => $query->where('origin_branch_id', $branchId))
            ->where('status', LegacyRmeImportStatus::PROCESSING)
            ->where('updated_at', '<', now()->subSeconds($seconds))
            ->count();
    }

    /**
     * Published/void RECORDS produced by this wave's imports.
     *
     * Counted through `source_import_id` rather than by trusting the staging
     * status: a staging row saying PUBLISHED and a published record that does
     * not exist is precisely the kind of divergence a reconciliation is for.
     */
    private function recordCount(LegacyRmeMigrationWave $wave, ?int $branchId, string $status): int
    {
        return LegacyRmeRecord::query()
            ->where('status', $status)
            ->whereIn('source_import_id', function ($query) use ($wave, $branchId): void {
                $query->select('id')
                    ->from('stg_rme_legacy_imports')
                    ->where('migration_wave_id', $wave->getKey());

                if ($branchId !== null) {
                    $query->where('origin_branch_id', $branchId);
                }
            })
            ->count();
    }
}
