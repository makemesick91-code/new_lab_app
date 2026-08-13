<?php

declare(strict_types=1);

namespace App\Modules\LegacyRme\Support;

use App\Modules\LegacyRme\Support\LegacyRmeImportStatus as Status;

/**
 * LEGACY-RME-PDF-ROLL-4 — the balance sheet of one branch (or one whole wave)
 * in a migration.
 *
 * THE QUESTION IT ANSWERS. "Is this branch finished?" cannot be answered by an
 * empty queue: a queue is empty both when everything succeeded and when a job
 * silently vanished. It cannot be answered by `failed_jobs = 0` either, which
 * ROLL-2 already learned the hard way — a worker that never consumed the queue
 * produced zero failures and zero progress simultaneously.
 *
 * So completion is a BALANCE. Every document this wave accepted has to be in
 * exactly one bucket:
 *
 *   accepted = published + cancelled + failed_unresolved + in_flight
 *
 * `unexplained` is the remainder. By construction it is zero, because the four
 * buckets partition every value in the 1A status vocabulary — and that is
 * exactly why it is computed and asserted rather than assumed. If someone adds
 * a tenth status and forgets this file, `unexplained` goes non-zero and blocks
 * the sign-off, instead of a document quietly falling out of the count.
 *
 * `quota_drift` is the second independent check: the counter ledger against the
 * documents actually accepted. It catches a hand-edited counter, or a future
 * code path that writes a staging row without passing through the quota
 * reservation.
 *
 * PII-FREE. Counts and a branch label. No patient, no document, no filename.
 */
final class LegacyRmeReconciliation
{
    /**
     * Statuses that mean the document is still moving. A branch with any of
     * these is not finished, whatever the queue says.
     *
     * @var list<string>
     */
    public const IN_FLIGHT_STATUSES = [
        Status::DRAFT,
        Status::UPLOADED,
        Status::QUEUED,
        Status::PROCESSING,
        Status::READY_FOR_REVIEW,
        Status::REVIEWED,
    ];

    private function __construct(
        public readonly ?string $branchCode,
        public readonly int $accepted,
        public readonly int $published,
        public readonly int $cancelled,
        public readonly int $failedUnresolved,
        public readonly int $inFlight,
        public readonly int $unexplained,
        public readonly int $quotaConsumed,
        public readonly int $quotaDrift,
        public readonly int $staleProcessing,
        public readonly int $publishedRecords,
        public readonly int $voidRecords,
    ) {}

    /**
     * @param  array<string, int>  $countsByStatus  staging counts keyed by 1A status
     */
    public static function fromCounts(
        ?string $branchCode,
        array $countsByStatus,
        int $quotaConsumed,
        int $staleProcessing,
        int $publishedRecords,
        int $voidRecords,
    ): self {
        $accepted = array_sum($countsByStatus);

        $published = (int) ($countsByStatus[Status::PUBLISHED] ?? 0);
        $cancelled = (int) ($countsByStatus[Status::CANCELLED] ?? 0);
        $failed = (int) ($countsByStatus[Status::FAILED] ?? 0);

        $inFlight = 0;
        foreach (self::IN_FLIGHT_STATUSES as $status) {
            $inFlight += (int) ($countsByStatus[$status] ?? 0);
        }

        return new self(
            branchCode: $branchCode,
            accepted: $accepted,
            published: $published,
            cancelled: $cancelled,
            failedUnresolved: $failed,
            inFlight: $inFlight,
            unexplained: $accepted - ($published + $cancelled + $failed + $inFlight),
            quotaConsumed: $quotaConsumed,
            // Signed on purpose: a ledger BELOW the accepted count (a staging
            // row written without reserving quota) is a different defect from a
            // ledger ABOVE it (a reservation whose row disappeared), and
            // collapsing both to an absolute value would hide which one it is.
            quotaDrift: $quotaConsumed - $accepted,
            staleProcessing: $staleProcessing,
            publishedRecords: $publishedRecords,
            voidRecords: $voidRecords,
        );
    }

    /** Everything accepted has reached a terminal state and the books agree. */
    public function balanced(): bool
    {
        return $this->unexplained === 0 && $this->quotaDrift === 0;
    }

    /**
     * Whether this branch may be signed off as COMPLETE.
     *
     * Each condition is independently required by
     * `legacy_rme_operations.completion_invariants`, and each one has a
     * different remedy — which is why the blockers are reported individually
     * rather than as one boolean.
     */
    public function completable(): bool
    {
        return $this->blockers() === [];
    }

    /**
     * Stable machine-readable reasons this branch cannot be completed yet.
     *
     * @return list<string>
     */
    public function blockers(): array
    {
        $invariants = (array) config('legacy_rme_operations.completion_invariants', []);
        $blockers = [];

        if (($invariants['requires_zero_in_flight'] ?? true) && $this->inFlight > 0) {
            $blockers[] = 'IN_FLIGHT_REMAINING';
        }

        if (($invariants['requires_zero_unresolved_failures'] ?? true) && $this->failedUnresolved > 0) {
            $blockers[] = 'UNRESOLVED_FAILURES';
        }

        if (($invariants['requires_zero_unexplained'] ?? true) && $this->unexplained !== 0) {
            $blockers[] = 'UNEXPLAINED_RECORDS';
        }

        if (($invariants['requires_zero_quota_drift'] ?? true) && $this->quotaDrift !== 0) {
            $blockers[] = 'QUOTA_LEDGER_DRIFT';
        }

        return $blockers;
    }

    /**
     * @return array<string, int|string|bool|null|list<string>>
     */
    public function toArray(): array
    {
        return [
            'branch_code' => $this->branchCode,
            'accepted' => $this->accepted,
            'published' => $this->published,
            'cancelled' => $this->cancelled,
            'failed_unresolved' => $this->failedUnresolved,
            'in_flight' => $this->inFlight,
            'unexplained' => $this->unexplained,
            'quota_consumed' => $this->quotaConsumed,
            'quota_drift' => $this->quotaDrift,
            'stale_processing' => $this->staleProcessing,
            'published_records' => $this->publishedRecords,
            'void_records' => $this->voidRecords,
            'balanced' => $this->balanced(),
            'completable' => $this->completable(),
            'blockers' => $this->blockers(),
        ];
    }
}
