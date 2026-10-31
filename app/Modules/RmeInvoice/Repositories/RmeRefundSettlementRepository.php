<?php

declare(strict_types=1);

namespace App\Modules\RmeInvoice\Repositories;

use App\Modules\RmeInvoice\Interfaces\RmeRefundSettlementRepositoryInterface;
use App\Modules\RmeInvoice\Models\RmePayment;
use App\Modules\RmeInvoice\Models\RmeRefundSettlement;
use App\Modules\RmeInvoice\Models\RmeRefundSettlementEvent;
use App\Modules\RmeInvoice\Support\RmeInvoiceDocument;
use App\Modules\RmeInvoice\Support\RmeRefundSettlementStatus;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final class RmeRefundSettlementRepository implements RmeRefundSettlementRepositoryInterface
{
    /** The only columns a transition may write. */
    private const LIFECYCLE_COLUMNS = [
        'status',
        'outcome_unknown_by',
        'outcome_unknown_at',
        'outcome_note',
        'settled_by',
        'settled_at',
        'settler_attested_at',
        'disbursement_reference',
        'disbursement_reference_key',
        'destination_bank_name',
        'destination_account_last4',
        'recipient_relation',
        'aborted_by',
        'aborted_at',
        'abort_reason',
        'evidence_path',
        'evidence_sha256',
        'evidence_mime',
        'evidence_size_bytes',
    ];

    /** The only columns written at start. */
    private const START_COLUMNS = [
        'uuid',
        'branch_id',
        'rme_invoice_id',
        'rme_overpayment_obligation_id',
        'rme_refund_request_id',
        'amount',
        'method',
        'status',
        'intent_key',
        'request_fingerprint',
        'approved_by',
        'started_by',
        'started_at',
    ];

    public function find(int $id): ?RmeRefundSettlement
    {
        return RmeRefundSettlement::query()->find($id);
    }

    public function findForUpdate(int $id): ?RmeRefundSettlement
    {
        return RmeRefundSettlement::query()->whereKey($id)->lockForUpdate()->first();
    }

    public function findByIntentKey(string $intentKey): ?RmeRefundSettlement
    {
        return RmeRefundSettlement::query()->where('intent_key', $intentKey)->first();
    }

    public function holdingForRequest(int $refundRequestId, bool $lock = false): ?RmeRefundSettlement
    {
        $query = RmeRefundSettlement::query()
            ->where('rme_refund_request_id', $refundRequestId)
            ->whereIn('status', RmeRefundSettlementStatus::HOLDING)
            ->orderBy('id');

        return ($lock ? $query->lockForUpdate() : $query)->first();
    }

    public function holdingForObligation(int $obligationId, bool $lock = false): ?RmeRefundSettlement
    {
        $query = RmeRefundSettlement::query()
            ->where('rme_overpayment_obligation_id', $obligationId)
            ->whereIn('status', RmeRefundSettlementStatus::HOLDING)
            ->orderBy('id');

        return ($lock ? $query->lockForUpdate() : $query)->first();
    }

    public function forRequest(int $refundRequestId): Collection
    {
        return RmeRefundSettlement::query()
            ->with(['starter', 'settler', 'aborter', 'unknownReporter'])
            ->where('rme_refund_request_id', $refundRequestId)
            ->orderByDesc('id')
            ->get();
    }

    public function create(array $data): RmeRefundSettlement
    {
        $settlement = new RmeRefundSettlement;
        $settlement->forceFill(array_intersect_key($data, array_flip(self::START_COLUMNS)))->save();

        return $settlement;
    }

    public function assignNumber(RmeRefundSettlement $settlement, string $number): RmeRefundSettlement
    {
        $settlement->forceFill(['settlement_number' => $number])->save();

        return $settlement;
    }

    public function transition(RmeRefundSettlement $settlement, array $data): RmeRefundSettlement
    {
        $settlement->forceFill(array_intersect_key($data, array_flip(self::LIFECYCLE_COLUMNS)))->save();

        return $settlement;
    }

    public function recordEvent(array $data): RmeRefundSettlementEvent
    {
        return RmeRefundSettlementEvent::query()->create($data);
    }

    public function events(int $settlementId): Collection
    {
        return RmeRefundSettlementEvent::query()
            ->with('actor')
            ->where('rme_refund_settlement_id', $settlementId)
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get();
    }

    public function referenceSettled(string $referenceKey): bool
    {
        return RmeRefundSettlement::query()
            ->where('status', RmeRefundSettlementStatus::SETTLED)
            ->where('disbursement_reference_key', $referenceKey)
            ->exists();
    }

    public function evidenceUsed(string $sha256): bool
    {
        return RmeRefundSettlement::query()->where('evidence_sha256', $sha256)->exists();
    }

    public function paginateForBranches(array $branchIds, array $filters, int $perPage = 20): LengthAwarePaginator
    {
        return RmeRefundSettlement::query()
            ->with(['branch', 'invoice', 'refundRequest', 'starter', 'settler'])
            ->whereIn('branch_id', $branchIds === [] ? [0] : $branchIds)
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->whereIn('status', (array) $status))
            ->when($filters['search'] ?? null, function ($query, $search) {
                $term = '%'.mb_strtolower((string) $search).'%';
                $query->where(function ($q) use ($term) {
                    $q->whereRaw('LOWER(settlement_number) LIKE ?', [$term])
                        ->orWhereHas('invoice', fn ($i) => $i->whereRaw('LOWER(invoice_number) LIKE ?', [$term]))
                        ->orWhereHas('refundRequest', fn ($r) => $r->whereRaw('LOWER(request_number) LIKE ?', [$term]));
                });
            })
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function statusCountsForBranches(array $branchIds): array
    {
        $counts = array_fill_keys(RmeRefundSettlementStatus::ALL, 0);

        RmeRefundSettlement::query()
            ->whereIn('branch_id', $branchIds === [] ? [0] : $branchIds)
            ->selectRaw('status, COUNT(*) AS aggregate_count')
            ->groupBy('status')
            ->get()
            ->each(function ($row) use (&$counts) {
                $counts[(string) $row->status] = (int) $row->aggregate_count;
            });

        return $counts;
    }

    public function settledOutflowByBranch(array $branchIds, CarbonInterface $fromUtc, CarbonInterface $toExclusiveUtc): array
    {
        return $this->aggregate(
            RmeRefundSettlement::query()
                ->where('status', RmeRefundSettlementStatus::SETTLED)
                ->where('settled_at', '>=', $fromUtc)
                ->where('settled_at', '<', $toExclusiveUtc),
            $branchIds,
        );
    }

    public function grossIncomingByBranch(array $branchIds, CarbonInterface $fromUtc, CarbonInterface $toExclusiveUtc): array
    {
        $result = [];

        // Invoice-branch attribution (AIR-RMB-37), Σ amount by paid_at.
        RmePayment::query()
            ->join('trx_rme_invoices', 'trx_rme_invoices.id', '=', 'trx_rme_payments.rme_invoice_id')
            ->whereIn('trx_rme_invoices.branch_id', $branchIds === [] ? [0] : $branchIds)
            ->where('trx_rme_payments.paid_at', '>=', $fromUtc)
            ->where('trx_rme_payments.paid_at', '<', $toExclusiveUtc)
            ->selectRaw('trx_rme_invoices.branch_id AS branch_id, SUM(trx_rme_payments.amount) AS aggregate_amount')
            ->groupBy('trx_rme_invoices.branch_id')
            ->get()
            ->each(function ($row) use (&$result) {
                $result[(int) $row->branch_id] = RmeInvoiceDocument::toCents(round((float) $row->aggregate_amount, 2));
            });

        return $result;
    }

    public function inFlightByBranch(array $branchIds): array
    {
        return $this->aggregate(
            RmeRefundSettlement::query()->whereIn('status', RmeRefundSettlementStatus::ACTIVE),
            $branchIds,
        );
    }

    /**
     * @param  Builder<RmeRefundSettlement>  $query
     * @param  list<int>  $branchIds
     * @return array<int, array{count: int, amount_cents: int}>
     */
    private function aggregate($query, array $branchIds): array
    {
        $result = [];

        $query->whereIn('branch_id', $branchIds === [] ? [0] : $branchIds)
            ->selectRaw('branch_id, COUNT(*) AS aggregate_count, SUM(amount) AS aggregate_amount')
            ->groupBy('branch_id')
            ->get()
            ->each(function ($row) use (&$result) {
                $result[(int) $row->branch_id] = [
                    'count' => (int) $row->aggregate_count,
                    'amount_cents' => RmeInvoiceDocument::toCents(round((float) $row->aggregate_amount, 2)),
                ];
            });

        return $result;
    }
}
