<?php

declare(strict_types=1);

namespace App\Modules\RmeInvoice\Repositories;

use App\Modules\RmeInvoice\Interfaces\RmeRefundRequestRepositoryInterface;
use App\Modules\RmeInvoice\Models\RmeInvoiceCorrection;
use App\Modules\RmeInvoice\Models\RmeRefundRequest;
use App\Modules\RmeInvoice\Models\RmeRefundRequestEvent;
use App\Modules\RmeInvoice\Support\RmeInvoiceDocument;
use App\Modules\RmeInvoice\Support\RmeRefundRequestStatus;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class RmeRefundRequestRepository implements RmeRefundRequestRepositoryInterface
{
    /** The only columns a transition may write. */
    private const LIFECYCLE_COLUMNS = [
        'status',
        'decided_by',
        'decided_at',
        'decision_note',
        'cancelled_by',
        'cancelled_at',
        'cancel_reason',
        'stale_reason_code',
        'staled_at',
    ];

    public function find(int $id): ?RmeRefundRequest
    {
        return RmeRefundRequest::query()->find($id);
    }

    public function findForUpdate(int $id): ?RmeRefundRequest
    {
        return RmeRefundRequest::query()->whereKey($id)->lockForUpdate()->first();
    }

    public function findByIntentKey(string $intentKey): ?RmeRefundRequest
    {
        return RmeRefundRequest::query()->where('intent_key', $intentKey)->first();
    }

    public function activeForObligation(int $obligationId, bool $lock = false): ?RmeRefundRequest
    {
        $query = RmeRefundRequest::query()
            ->where('rme_overpayment_obligation_id', $obligationId)
            ->whereIn('status', RmeRefundRequestStatus::ACTIVE)
            ->orderBy('id');

        return ($lock ? $query->lockForUpdate() : $query)->first();
    }

    public function forInvoice(int $invoiceId): Collection
    {
        return RmeRefundRequest::query()
            ->with(['requester', 'decider', 'canceller'])
            ->where('rme_invoice_id', $invoiceId)
            ->orderByDesc('id')
            ->get();
    }

    public function create(array $data): RmeRefundRequest
    {
        $request = new RmeRefundRequest;
        $request->forceFill($data)->save();

        return $request;
    }

    public function assignNumber(RmeRefundRequest $request, string $number): RmeRefundRequest
    {
        $request->forceFill(['request_number' => $number])->save();

        return $request;
    }

    public function transition(RmeRefundRequest $request, array $data): RmeRefundRequest
    {
        $request->forceFill(array_intersect_key($data, array_flip(self::LIFECYCLE_COLUMNS)))->save();

        return $request;
    }

    public function recordEvent(array $data): RmeRefundRequestEvent
    {
        return RmeRefundRequestEvent::query()->create($data);
    }

    public function events(int $requestId): Collection
    {
        return RmeRefundRequestEvent::query()
            ->with('actor')
            ->where('rme_refund_request_id', $requestId)
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get();
    }

    public function ownerReferenceUsedOnRefund(string $reference): bool
    {
        return RmeRefundRequest::query()
            ->whereRaw('lower(owner_approval_reference) = ?', [mb_strtolower($reference)])
            ->exists();
    }

    public function ownerReferenceUsedOnCorrection(string $reference): bool
    {
        return RmeInvoiceCorrection::query()
            ->whereNotNull('owner_approval_reference')
            ->whereRaw('lower(owner_approval_reference) = ?', [mb_strtolower($reference)])
            ->exists();
    }

    public function claimPatientReference(string $folded, int $obligationId, int $actorId): void
    {
        $now = now();

        DB::insert(
            'INSERT INTO trx_rme_refund_patient_references (reference_folded, rme_overpayment_obligation_id, first_consumed_by, first_consumed_at, created_at) VALUES (?, ?, ?, ?, ?) ON CONFLICT (reference_folded) DO NOTHING',
            [$folded, $obligationId, $actorId, $now, $now],
        );
    }

    public function patientReferenceObligation(string $folded): ?int
    {
        $value = DB::table('trx_rme_refund_patient_references')
            ->where('reference_folded', $folded)
            ->value('rme_overpayment_obligation_id');

        return $value === null ? null : (int) $value;
    }

    public function paginateForBranches(array $branchIds, array $filters, int $perPage = 20): LengthAwarePaginator
    {
        $query = RmeRefundRequest::query()
            ->with(['branch', 'requester', 'decider', 'invoice'])
            ->whereIn('branch_id', $branchIds === [] ? [0] : $branchIds);

        if (is_string($filters['status'] ?? null) && in_array($filters['status'], RmeRefundRequestStatus::ALL, true)) {
            $query->where('status', $filters['status']);
        }

        if (is_string($filters['search'] ?? null) && trim($filters['search']) !== '') {
            $search = '%'.addcslashes(trim($filters['search']), '%_\\').'%';
            $query->where('request_number', 'like', $search);
        }

        return $query->orderByDesc('id')->paginate($perPage)->withQueryString();
    }

    public function statusCountsForBranches(array $branchIds): array
    {
        $counts = array_fill_keys(RmeRefundRequestStatus::ALL, 0);

        RmeRefundRequest::query()
            ->whereIn('branch_id', $branchIds === [] ? [0] : $branchIds)
            ->selectRaw('status, count(*) AS total')
            ->groupBy('status')
            ->get()
            ->each(function ($row) use (&$counts): void {
                $counts[(string) $row->status] = (int) $row->total;
            });

        return $counts;
    }

    public function approvedUnsettledByBranch(array $branchIds): array
    {
        $summary = [];

        RmeRefundRequest::query()
            ->whereIn('branch_id', $branchIds === [] ? [0] : $branchIds)
            ->where('status', RmeRefundRequestStatus::APPROVED)
            ->get(['branch_id', 'amount'])
            ->each(function (RmeRefundRequest $row) use (&$summary): void {
                $branchId = (int) $row->branch_id;
                $summary[$branchId] ??= ['count' => 0, 'amount_cents' => 0];
                $summary[$branchId]['count']++;
                $summary[$branchId]['amount_cents'] += RmeInvoiceDocument::toCents($row->amount);
            });

        ksort($summary);

        return $summary;
    }
}
