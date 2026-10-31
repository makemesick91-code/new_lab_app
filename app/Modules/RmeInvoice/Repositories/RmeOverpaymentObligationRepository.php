<?php

declare(strict_types=1);

namespace App\Modules\RmeInvoice\Repositories;

use App\Modules\RmeInvoice\Interfaces\RmeOverpaymentObligationRepositoryInterface;
use App\Modules\RmeInvoice\Models\RmeOverpaymentObligation;
use App\Modules\RmeInvoice\Models\RmeOverpaymentObligationEvent;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

final class RmeOverpaymentObligationRepository implements RmeOverpaymentObligationRepositoryInterface
{
    public function openForInvoice(int $invoiceId, bool $lock = false): ?RmeOverpaymentObligation
    {
        $query = RmeOverpaymentObligation::query()
            ->where('rme_invoice_id', $invoiceId)
            ->where('status', RmeOverpaymentObligation::STATUS_OPEN)
            ->orderBy('id');

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    public function findForUpdate(int $id): ?RmeOverpaymentObligation
    {
        return RmeOverpaymentObligation::query()->whereKey($id)->lockForUpdate()->first();
    }

    public function find(int $id): ?RmeOverpaymentObligation
    {
        return RmeOverpaymentObligation::query()
            ->with(['branch', 'invoice', 'sourceCorrection', 'creator', 'events.actor'])
            ->find($id);
    }

    public function forCorrection(int $correctionId): ?RmeOverpaymentObligation
    {
        return RmeOverpaymentObligation::query()->where('source_correction_id', $correctionId)->first();
    }

    public function forInvoice(int $invoiceId): Collection
    {
        return RmeOverpaymentObligation::query()
            ->where('rme_invoice_id', $invoiceId)
            ->orderBy('id')
            ->get();
    }

    public function create(array $data): RmeOverpaymentObligation
    {
        return RmeOverpaymentObligation::create($data);
    }

    public function assignNumber(RmeOverpaymentObligation $obligation, string $number): RmeOverpaymentObligation
    {
        $obligation->forceFill(['obligation_number' => $number])->save();

        return $obligation;
    }

    public function markSettled(RmeOverpaymentObligation $obligation, int $settlementId, \DateTimeInterface $settledAt): RmeOverpaymentObligation
    {
        $obligation->forceFill([
            'status' => RmeOverpaymentObligation::STATUS_SETTLED,
            'settled_at' => $settledAt,
            'rme_refund_settlement_id' => $settlementId,
        ])->save();

        return $obligation;
    }

    public function recordEvent(array $data): RmeOverpaymentObligationEvent
    {
        return RmeOverpaymentObligationEvent::create($data);
    }

    public function paginateForBranches(array $branchIds, array $filters, int $perPage = 20): LengthAwarePaginator
    {
        return RmeOverpaymentObligation::query()
            ->with(['branch', 'invoice', 'sourceCorrection'])
            ->whereIn('branch_id', $branchIds === [] ? [0] : $branchIds)
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->whereIn('status', (array) $status))
            ->when($filters['search'] ?? null, function ($query, $search) {
                $term = '%'.mb_strtolower((string) $search).'%';
                $query->where(function ($q) use ($term) {
                    $q->whereRaw('LOWER(obligation_number) LIKE ?', [$term])
                        ->orWhereHas('invoice', fn ($i) => $i->whereRaw('LOWER(invoice_number) LIKE ?', [$term]));
                });
            })
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function openSummaryByBranch(): array
    {
        return RmeOverpaymentObligation::query()
            ->where('status', RmeOverpaymentObligation::STATUS_OPEN)
            ->selectRaw('branch_id, COUNT(*) AS aggregate_count, SUM(amount) AS aggregate_amount')
            ->groupBy('branch_id')
            ->get()
            ->mapWithKeys(fn ($row) => [(int) $row->branch_id => [
                'count' => (int) $row->aggregate_count,
                'amount' => number_format((float) $row->aggregate_amount, 2, '.', ''),
            ]])
            ->all();
    }
}
