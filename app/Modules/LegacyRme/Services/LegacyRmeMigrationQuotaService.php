<?php

declare(strict_types=1);

namespace App\Modules\LegacyRme\Services;

use App\Modules\LegacyRme\Models\LegacyRmeMigrationQuota;
use App\Modules\LegacyRme\Models\LegacyRmeMigrationWave;
use App\Modules\LegacyRme\Models\LegacyRmeWaveBranch;
use App\Modules\LegacyRme\Support\LegacyRmeOperationsDecision;
use App\Support\Clinical\ClinicalClock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * LEGACY-RME-PDF-ROLL-4 — the daily ceiling on newly accepted documents.
 *
 * TWO CHECKS, AND THE DIFFERENCE MATTERS.
 *
 *   preview()  — advisory, lock-free, runs BEFORE the 20 MiB upload is stored,
 *                so an operator who is already at the ceiling is told so
 *                immediately instead of after a long upload. It is a courtesy,
 *                never a gate: by the time the request finishes, another
 *                operator may have consumed the last slot.
 *
 *   reserve()  — AUTHORITATIVE. Runs inside the transaction that creates the
 *                staging row, takes `FOR UPDATE` on the day's buckets, and is
 *                the only thing that may be trusted. Because it shares the
 *                transaction with the row it counts, a rolled-back import
 *                releases its quota with no compensating write.
 *
 * WHY LOCKING IS NOT OPTIONAL. Counting rows cannot be made safe by adding a
 * transaction: two uploads racing for the last slot both read N-1 and both
 * insert, because the row that would block the second one is the one neither has
 * written yet. There has to be a row that exists BEFORE the decision. That is
 * what the bucket is, and it is why `insertOrIgnore` creates it first.
 *
 * DEADLOCK SAFETY. The wave-wide ceiling needs every branch's bucket for the
 * day, and the per-branch ceiling needs one of them. Both are taken by a single
 * `ORDER BY branch_id ... FOR UPDATE`, so concurrent requests on different
 * branches always acquire the same rows in the same order and cannot form a
 * cycle.
 *
 * NULL IS NOT ZERO. A NULL ceiling declines to limit; a ceiling of 0 admits
 * nothing. Collapsing them would turn "we did not set a quota" into "migration
 * is closed", which is a very different operational statement.
 */
class LegacyRmeMigrationQuotaService
{
    public function __construct(
        private readonly ClinicalClock $clock,
    ) {}

    /**
     * The clinical calendar day a document is charged to.
     *
     * LEGACY-RME-DATE-TZ-1 — delegated to ClinicalClock, the same canonical
     * clinical calendar the 1A date rules use, so a document can never be
     * charged to one day and judged against another. The previous inline
     * `config('app.timezone', 'UTC')` fallback resolved to UTC in production,
     * which rolled the quota over at 08:00 WITA — the middle of an Indonesian
     * working morning.
     */
    public function today(): CarbonImmutable
    {
        return $this->clock->today();
    }

    /**
     * The wave-wide daily ceiling, or null for "no ceiling declared".
     */
    public function waveDailyLimit(LegacyRmeMigrationWave $wave): ?int
    {
        if ($wave->daily_quota !== null) {
            return (int) $wave->daily_quota;
        }

        $configured = config('legacy_rme_operations.quota.default_wave_daily');

        return $configured === null ? null : (int) $configured;
    }

    /**
     * Consumption for one branch today, and for the whole wave today.
     *
     * @return array{branch: int, wave: int}
     */
    public function consumedToday(LegacyRmeMigrationWave $wave, int $branchId): array
    {
        $date = $this->today()->toDateString();

        $rows = LegacyRmeMigrationQuota::query()
            ->where('wave_id', $wave->getKey())
            ->whereDate('quota_date', $date)
            ->get(['branch_id', 'consumed']);

        return [
            'branch' => (int) $rows->firstWhere('branch_id', $branchId)?->consumed,
            'wave' => (int) $rows->sum('consumed'),
        ];
    }

    /**
     * ADVISORY pre-check. Never trusted as the gate — reserve() is.
     *
     * Returns a denial decision when the ceiling is already reached, so the
     * caller can refuse before storing bytes, or null when there is room.
     */
    public function preview(
        LegacyRmeMigrationWave $wave,
        LegacyRmeWaveBranch $branch,
        ?string $branchCode,
    ): ?LegacyRmeOperationsDecision {
        $consumed = $this->consumedToday($wave, (int) $branch->branch_id);

        $branchLimit = $branch->effectiveDailyQuota($wave);

        if ($branchLimit !== null && $consumed['branch'] >= $branchLimit) {
            return $this->branchExhausted($branchCode, $wave, $branchLimit);
        }

        $waveLimit = $this->waveDailyLimit($wave);

        if ($waveLimit !== null && $consumed['wave'] >= $waveLimit) {
            return $this->waveExhausted($branchCode, $wave, $waveLimit);
        }

        return null;
    }

    /**
     * AUTHORITATIVE reservation. MUST be called inside the transaction that
     * creates the staging row.
     *
     * @throws ValidationException when a ceiling has been reached
     */
    public function reserve(
        LegacyRmeMigrationWave $wave,
        LegacyRmeWaveBranch $branch,
        ?string $branchCode,
    ): LegacyRmeMigrationQuota {
        $date = $this->today()->toDateString();
        $branchId = (int) $branch->branch_id;

        // The bucket must exist before it can be locked. `insertOrIgnore` is
        // race-safe (ON CONFLICT DO NOTHING on PostgreSQL): two concurrent
        // requests both attempt it, one wins, neither errors, and both then lock
        // the same surviving row.
        DB::table('ops_rme_legacy_migration_quotas')->insertOrIgnore([
            'wave_id' => (int) $wave->getKey(),
            'branch_id' => $branchId,
            'quota_date' => $date,
            'consumed' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // One ordered locking read covers both ceilings. Ordering by branch_id
        // gives every concurrent request the same acquisition order, so they
        // queue instead of deadlocking.
        $buckets = LegacyRmeMigrationQuota::query()
            ->where('wave_id', $wave->getKey())
            ->whereDate('quota_date', $date)
            ->orderBy('branch_id')
            ->lockForUpdate()
            ->get();

        $bucket = $buckets->firstWhere('branch_id', $branchId);

        if ($bucket === null) {
            // Only reachable if the row vanished between the insert and the
            // lock. Fail closed rather than inventing an unlocked bucket.
            throw ValidationException::withMessages([
                'legacy_rme_quota' => 'Kuota migrasi harian tidak dapat dipastikan, sehingga dokumen tidak diterima.',
            ]);
        }

        $branchLimit = $branch->effectiveDailyQuota($wave);

        if ($branchLimit !== null && (int) $bucket->consumed >= $branchLimit) {
            throw ValidationException::withMessages([
                'legacy_rme_quota' => (string) $this->branchExhausted($branchCode, $wave, $branchLimit)->message,
            ]);
        }

        $waveLimit = $this->waveDailyLimit($wave);

        if ($waveLimit !== null && (int) $buckets->sum('consumed') >= $waveLimit) {
            throw ValidationException::withMessages([
                'legacy_rme_quota' => (string) $this->waveExhausted($branchCode, $wave, $waveLimit)->message,
            ]);
        }

        $bucket->consumed = (int) $bucket->consumed + 1;
        $bucket->save();

        return $bucket;
    }

    /**
     * Total consumption recorded for a wave, optionally for one branch.
     *
     * Used by reconciliation to compare the ledger against the documents
     * actually accepted.
     */
    public function totalConsumed(LegacyRmeMigrationWave $wave, ?int $branchId = null): int
    {
        return (int) LegacyRmeMigrationQuota::query()
            ->where('wave_id', $wave->getKey())
            ->when($branchId !== null, static fn ($query) => $query->where('branch_id', $branchId))
            ->sum('consumed');
    }

    private function branchExhausted(?string $branchCode, LegacyRmeMigrationWave $wave, int $limit): LegacyRmeOperationsDecision
    {
        return LegacyRmeOperationsDecision::deny(
            LegacyRmeOperationsDecision::CODE_QUOTA_BRANCH_EXHAUSTED,
            sprintf(
                'Kuota migrasi harian cabang %s sudah terpakai penuh (%d dokumen). Lanjutkan pada hari berikutnya.',
                $branchCode ?? '-',
                $limit,
            ),
            $branchCode,
            $wave->code,
            (int) $wave->getKey(),
        );
    }

    private function waveExhausted(?string $branchCode, LegacyRmeMigrationWave $wave, int $limit): LegacyRmeOperationsDecision
    {
        return LegacyRmeOperationsDecision::deny(
            LegacyRmeOperationsDecision::CODE_QUOTA_WAVE_EXHAUSTED,
            sprintf(
                'Kuota migrasi harian gelombang %s sudah terpakai penuh (%d dokumen). Lanjutkan pada hari berikutnya.',
                $wave->code,
                $limit,
            ),
            $branchCode,
            $wave->code,
            (int) $wave->getKey(),
        );
    }
}
