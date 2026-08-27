<?php

declare(strict_types=1);

namespace App\Modules\LegacyImport\Services;

use App\Modules\LegacyImport\Interfaces\LegacyImportDailyQuotaRepositoryInterface;
use App\Modules\LegacyImport\Support\LegacyImportType;
use App\Support\Clinical\ClinicalClock;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * FEATURE-LEGACY-IMPORT-HUB-1 — the canonical daily ceiling for every legacy
 * importer.
 *
 * TWO CHECKS, AND THE DIFFERENCE MATTERS. The shape is deliberately the same as
 * the ROLL-4 LegacyRmeMigrationQuotaService, because that shape was arrived at by
 * solving this exact problem and an operator who has read one should recognise
 * the other.
 *
 *   preview()  — advisory, lock-free. Runs BEFORE a 20 MiB upload is stored, so
 *                an operator already at the ceiling is told immediately instead
 *                of after a long upload. It is a courtesy, never a gate: by the
 *                time the request finishes, another operator may have taken the
 *                last slot.
 *
 *   reserve()  — AUTHORITATIVE. MUST run inside the transaction that writes the
 *                record it counts. Takes `FOR UPDATE` on the day's bucket, and
 *                is the only thing that may be trusted. Because it shares the
 *                transaction with the row it counts, a rolled-back import
 *                releases its slot with no compensating write.
 *
 * WHY LOCKING IS NOT OPTIONAL. Counting rows cannot be made safe by adding a
 * transaction: two uploads racing for the last slot both read N-1 and both
 * insert, because the row that would block the second one is the one neither has
 * written yet. There has to be a row that exists BEFORE the decision. That is
 * what the bucket is, and it is why `ensureBucket` creates it first.
 *
 * DEADLOCK SAFETY, AND THE ORDER THAT GUARANTEES IT. Two lock orders exist in
 * this codebase and they must never interleave in opposite directions:
 *
 *   1. this service's bucket, ordered by branch id;
 *   2. the ROLL-4 wave buckets, ordered by branch id.
 *
 * Legacy RME takes both. It takes THIS ONE FIRST, always, at its single call
 * site. Nothing else takes them in the other order, so no cycle can form. A
 * future caller that needs both must keep that order.
 *
 * NULL IS NOT ZERO. A NULL ceiling declines to limit; a ceiling of 0 admits
 * nothing. Collapsing them would turn "we did not set a quota" into "this
 * capability is closed", which is a very different operational statement.
 *
 * MULTI-UNIT RESERVATION IS ALL-OR-NOTHING. Legacy Patient commits a whole
 * batch, so it reserves N slots at once. Partially admitting a batch would
 * either split it across two clinical days or silently drop rows the operator
 * believed were imported; both are worse than refusing with a message that
 * names the remaining capacity.
 */
class LegacyImportDailyQuotaService
{
    public function __construct(
        private readonly LegacyImportDailyQuotaRepositoryInterface $buckets,
        private readonly ClinicalClock $clock,
    ) {}

    /**
     * The clinical calendar day a record is charged to.
     *
     * Delegated to ClinicalClock, the same clinical calendar the legacy date
     * rules and the ROLL-4 quota use, so a record can never be charged to one
     * day and judged against another.
     */
    public function today(): CarbonImmutable
    {
        return $this->clock->today();
    }

    public function timezone(): string
    {
        return $this->clock->timezone();
    }

    /**
     * The ceiling for one import type, or null for "no ceiling declared".
     *
     * A configured value above `max_declarable_daily` is CLAMPED rather than
     * honoured: a ceiling anybody may set to a million is decoration. The clamp
     * is visible through {@see limitIsClamped()} so the hub can say so instead
     * of quietly disagreeing with the environment.
     */
    public function limitFor(string $type): ?int
    {
        $this->assertKnownType($type);

        $configured = config('legacy_import_hub.daily_limit.'.$type);

        if ($configured === null) {
            return null;
        }

        return min((int) $configured, $this->maxDeclarable());
    }

    public function limitIsClamped(string $type): bool
    {
        $this->assertKnownType($type);

        $configured = config('legacy_import_hub.daily_limit.'.$type);

        return $configured !== null && (int) $configured > $this->maxDeclarable();
    }

    public function maxDeclarable(): int
    {
        return max(0, (int) config('legacy_import_hub.max_declarable_daily', 500));
    }

    /**
     * ADVISORY read of what one branch has consumed today for one type.
     */
    public function consumedToday(string $type, int $branchId): int
    {
        $this->assertKnownType($type);

        return $this->buckets->consumed($type, $branchId, $this->today()->toDateString());
    }

    /**
     * ADVISORY remaining capacity, or null when no ceiling is declared.
     */
    public function remainingToday(string $type, int $branchId): ?int
    {
        $limit = $this->limitFor($type);

        if ($limit === null) {
            return null;
        }

        return max(0, $limit - $this->consumedToday($type, $branchId));
    }

    /**
     * ADVISORY consumption for many types and branches in ONE query.
     *
     * @param  list<string>  $types
     * @param  list<int>  $branchIds
     * @return array<string, int> keyed "{type}|{branchId}"
     */
    public function consumedMatrixToday(array $types, array $branchIds): array
    {
        foreach ($types as $type) {
            $this->assertKnownType($type);
        }

        return $this->buckets->consumedMatrix($types, array_values(array_unique($branchIds)), $this->today()->toDateString());
    }

    /**
     * ADVISORY pre-check. Never trusted as the gate — reserve() is.
     *
     * @return string|null a denial message, or null when there is room
     */
    public function preview(string $type, int $branchId, int $units = 1): ?string
    {
        $this->assertKnownType($type);
        $this->assertPositiveUnits($units);

        $limit = $this->limitFor($type);

        if ($limit === null) {
            return null;
        }

        $consumed = $this->consumedToday($type, $branchId);

        if ($consumed + $units > $limit) {
            return $this->exhaustedMessage($type, $limit, $consumed, $units);
        }

        return null;
    }

    /**
     * AUTHORITATIVE reservation.
     *
     * MUST be called inside the transaction that writes the record(s) being
     * counted, and — for Legacy RME — BEFORE the ROLL-4 wave reservation, so the
     * two lock orders never invert.
     *
     * @throws ValidationException when the ceiling would be exceeded
     */
    public function reserve(string $type, int $branchId, int $units = 1): void
    {
        $this->assertKnownType($type);
        $this->assertPositiveUnits($units);

        $limit = $this->limitFor($type);

        if ($limit === null) {
            // No ceiling declared. Nothing is counted, because a counter nobody
            // reads is a drift source: it would have to be reconciled against
            // reality forever to stay meaningful.
            return;
        }

        $date = $this->today()->toDateString();

        // The bucket must exist before it can be locked.
        $this->buckets->ensureBucket($type, $branchId, $date);

        $locked = $this->buckets->lockBuckets($type, [$branchId], $date);
        $bucket = $locked->firstWhere('branch_id', $branchId);

        if ($bucket === null) {
            // Only reachable if the row vanished between the insert and the
            // lock. Fail closed rather than inventing an unlocked bucket.
            throw ValidationException::withMessages([
                'legacy_import_quota' => 'Kuota impor harian tidak dapat dipastikan, sehingga data tidak diterima.',
            ]);
        }

        $consumed = (int) $bucket->consumed;

        if ($consumed + $units > $limit) {
            throw ValidationException::withMessages([
                'legacy_import_quota' => $this->exhaustedMessage($type, $limit, $consumed, $units),
            ]);
        }

        $this->buckets->addConsumed($bucket, $units);
    }

    /**
     * AUTHORITATIVE reservation for several branches at once.
     *
     * Legacy Patient commits a CSV batch whose rows may resolve to different
     * branches. Every branch's ceiling must hold or the whole commit is refused;
     * admitting the branches that fit would leave the operator with a partially
     * imported file and no way to tell which rows landed.
     *
     * @param  array<int, int>  $unitsByBranchId  branch id => units
     *
     * @throws ValidationException
     */
    public function reserveMany(string $type, array $unitsByBranchId): void
    {
        $this->assertKnownType($type);

        $unitsByBranchId = array_filter($unitsByBranchId, static fn (int $units): bool => $units > 0);

        if ($unitsByBranchId === []) {
            return;
        }

        $limit = $this->limitFor($type);

        if ($limit === null) {
            return;
        }

        $date = $this->today()->toDateString();

        // Create every bucket first, then take them all in ONE ordered locking
        // read. Interleaving create-then-lock per branch would let two requests
        // acquire the same two branches in opposite orders.
        $branchIds = array_map('intval', array_keys($unitsByBranchId));
        sort($branchIds);

        foreach ($branchIds as $branchId) {
            $this->buckets->ensureBucket($type, $branchId, $date);
        }

        $locked = $this->buckets->lockBuckets($type, $branchIds, $date);

        foreach ($branchIds as $branchId) {
            $bucket = $locked->firstWhere('branch_id', $branchId);
            $units = (int) $unitsByBranchId[$branchId];

            if ($bucket === null) {
                throw ValidationException::withMessages([
                    'legacy_import_quota' => 'Kuota impor harian tidak dapat dipastikan, sehingga data tidak diterima.',
                ]);
            }

            $consumed = (int) $bucket->consumed;

            if ($consumed + $units > $limit) {
                throw ValidationException::withMessages([
                    'legacy_import_quota' => $this->exhaustedMessage($type, $limit, $consumed, $units),
                ]);
            }
        }

        // Every ceiling holds; only now is anything written. Splitting the
        // checks from the writes is what makes the batch all-or-nothing even
        // before the surrounding transaction rolls back.
        foreach ($branchIds as $branchId) {
            $bucket = $locked->firstWhere('branch_id', $branchId);

            if ($bucket !== null) {
                $this->buckets->addConsumed($bucket, (int) $unitsByBranchId[$branchId]);
            }
        }
    }

    private function exhaustedMessage(string $type, int $limit, int $consumed, int $units): string
    {
        $remaining = max(0, $limit - $consumed);

        return sprintf(
            'Kuota impor %s untuk cabang ini hari ini sudah tercapai (%d dari %d terpakai, sisa %d, diminta %d). Lanjutkan pada hari berikutnya.',
            LegacyImportType::label($type),
            $consumed,
            $limit,
            $remaining,
            $units,
        );
    }

    private function assertKnownType(string $type): void
    {
        if (! LegacyImportType::isValid($type)) {
            // A programming error, not operator input: the type is always a
            // constant at the call site. Failing loudly stops an unknown string
            // from opening an uncounted bucket.
            throw new InvalidArgumentException('Unknown legacy import type: '.$type);
        }
    }

    private function assertPositiveUnits(int $units): void
    {
        if ($units < 1) {
            throw new InvalidArgumentException('Legacy import quota units must be at least 1.');
        }
    }
}
