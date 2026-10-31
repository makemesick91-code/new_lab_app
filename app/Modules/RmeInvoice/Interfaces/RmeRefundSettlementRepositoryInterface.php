<?php

declare(strict_types=1);

namespace App\Modules\RmeInvoice\Interfaces;

use App\Modules\RmeInvoice\Models\RmeRefundSettlement;
use App\Modules\RmeInvoice\Models\RmeRefundSettlementEvent;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * FEATURE-RME-REFUND-SETTLEMENT-1 (rule 216) — persistence for refund settlements
 * and their append-only events.
 *
 * Every list a user reaches takes the AUTHORIZED branch ids as an argument; the
 * repository never decides scope, authority or eligibility, and never reads a
 * request. Lifecycle writes go through allow-listed columns only.
 */
interface RmeRefundSettlementRepositoryInterface
{
    public function find(int $id): ?RmeRefundSettlement;

    /** SELECT … FOR UPDATE by id. Lock order: invoice → obligation → request → settlement. */
    public function findForUpdate(int $id): ?RmeRefundSettlement;

    public function findByIntentKey(string $intentKey): ?RmeRefundSettlement;

    /** The settlement holding the request's slot (DISBURSING, OUTCOME_UNKNOWN or SETTLED), or null. */
    public function holdingForRequest(int $refundRequestId, bool $lock = false): ?RmeRefundSettlement;

    /** The settlement holding the obligation's slot, or null. */
    public function holdingForObligation(int $obligationId, bool $lock = false): ?RmeRefundSettlement;

    /** @return Collection<int, RmeRefundSettlement> */
    public function forRequest(int $refundRequestId): Collection;

    /** @param  array<string, mixed>  $data */
    public function create(array $data): RmeRefundSettlement;

    public function assignNumber(RmeRefundSettlement $settlement, string $number): RmeRefundSettlement;

    /** @param  array<string, mixed>  $data  only lifecycle columns are written */
    public function transition(RmeRefundSettlement $settlement, array $data): RmeRefundSettlement;

    /** @param  array<string, mixed>  $data */
    public function recordEvent(array $data): RmeRefundSettlementEvent;

    /** @return Collection<int, RmeRefundSettlementEvent> */
    public function events(int $settlementId): Collection;

    /** A SETTLED settlement already carries this reference for this method (case-folded). */
    public function referenceSettled(string $referenceKey): bool;

    /** Has this evidence file been attached to ANY settlement (settled or aborted)? */
    public function evidenceUsed(string $sha256): bool;

    /**
     * @param  list<int>  $branchIds
     * @param  array<string, mixed>  $filters  status, search
     */
    public function paginateForBranches(array $branchIds, array $filters, int $perPage = 20): LengthAwarePaginator;

    /**
     * @param  list<int>  $branchIds
     * @return array<string, int>
     */
    public function statusCountsForBranches(array $branchIds): array;

    /**
     * RFS-6 — SETTLED outflow per branch, by settled_at in [from, toExclusive).
     *
     * @param  list<int>  $branchIds
     * @return array<int, array{count: int, amount_cents: int}>
     */
    public function settledOutflowByBranch(array $branchIds, CarbonInterface $fromUtc, CarbonInterface $toExclusiveUtc): array;

    /**
     * RFS-6 — gross incoming payments per branch (invoice branch), by paid_at in
     * [from, toExclusive). Read-only, so net = gross − settled outflow per branch.
     *
     * @param  list<int>  $branchIds
     * @return array<int, int> branch id => cents
     */
    public function grossIncomingByBranch(array $branchIds, CarbonInterface $fromUtc, CarbonInterface $toExclusiveUtc): array;

    /**
     * In-flight settlements per branch (DISBURSING + OUTCOME_UNKNOWN): money that
     * may have left but is not verified.
     *
     * @param  list<int>  $branchIds
     * @return array<int, array{count: int, amount_cents: int}>
     */
    public function inFlightByBranch(array $branchIds): array;
}
