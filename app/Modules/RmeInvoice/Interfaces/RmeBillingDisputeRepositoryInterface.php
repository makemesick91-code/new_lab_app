<?php

declare(strict_types=1);

namespace App\Modules\RmeInvoice\Interfaces;

use App\Modules\RmeInvoice\Models\RmeBillingDispute;
use App\Modules\RmeInvoice\Models\RmeBillingDisputeEvent;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * Rule 203 — persistence of billing disputes. Every list/detail read takes the
 * caller's ALREADY-RESOLVED branch ids; the repository never derives scope and
 * never reads a request branch.
 */
interface RmeBillingDisputeRepositoryInterface
{
    /** The ACTIVE dispute of an invoice (at most one — partial unique index), or null. Unlocked. */
    public function activeForInvoice(int $invoiceId): ?RmeBillingDispute;

    /**
     * Invoice ids (from $invoiceIds) that have an ACTIVE dispute — one query, for list badges.
     *
     * @param  list<int>  $invoiceIds
     * @return list<int>
     */
    public function activeInvoiceIds(array $invoiceIds): array;

    /** @return Collection<int, RmeBillingDispute> every dispute of an invoice, newest first */
    public function forInvoice(int $invoiceId): Collection;

    public function find(int $id): ?RmeBillingDispute;

    public function findForUpdate(int $id): ?RmeBillingDispute;

    /**
     * @param  list<int>  $branchIds
     * @param  array{status?: string|null}  $filters
     */
    public function paginateForBranches(array $branchIds, array $filters, int $perPage = 20): LengthAwarePaginator;

    /**
     * @param  list<int>  $branchIds
     * @return array<string, int> status => count
     */
    public function statusCountsForBranches(array $branchIds): array;

    /** @param array<string, mixed> $data */
    public function create(array $data): RmeBillingDispute;

    public function assignNumber(RmeBillingDispute $dispute, string $number): RmeBillingDispute;

    /** @param array<string, mixed> $data lifecycle columns only */
    public function transition(RmeBillingDispute $dispute, array $data): RmeBillingDispute;

    /** @param array<string, mixed> $data */
    public function recordEvent(array $data): RmeBillingDisputeEvent;

    /** @return Collection<int, RmeBillingDisputeEvent> oldest first */
    public function events(int $disputeId): Collection;

    /**
     * Read-only observability rows: per branch and status, count and oldest report time.
     *
     * @return Collection<int, object{branch_id: int, status: string, total: int, oldest_reported_at: string|null}>
     */
    public function summaryByBranchAndStatus(): Collection;

    /**
     * Rule 203 — how many audit rows of each action were written since $since
     * (operator status report). Counts only; never a payload.
     *
     * @param  list<string>  $actions
     * @return array<string, int>
     */
    public function auditActionCountsSince(array $actions, \DateTimeInterface $since): array;
}
