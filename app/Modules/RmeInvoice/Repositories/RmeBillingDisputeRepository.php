<?php

declare(strict_types=1);

namespace App\Modules\RmeInvoice\Repositories;

use App\Modules\RmeInvoice\Interfaces\RmeBillingDisputeRepositoryInterface;
use App\Modules\RmeInvoice\Models\RmeBillingDispute;
use App\Modules\RmeInvoice\Models\RmeBillingDisputeEvent;
use App\Modules\RmeInvoice\Support\RmeBillingDisputeStatus;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class RmeBillingDisputeRepository implements RmeBillingDisputeRepositoryInterface
{
    /** Lifecycle columns a transition may write — nothing from the immutable report. */
    private const LIFECYCLE_COLUMNS = [
        'status',
        'review_started_by',
        'review_started_at',
        'escalated_by',
        'escalated_at',
        'resolution_outcome',
        'resolution_reason',
        'resolution_reference',
        'resolution_correction_id',
        'resolved_by',
        'resolved_at',
    ];

    public function activeForInvoice(int $invoiceId): ?RmeBillingDispute
    {
        return RmeBillingDispute::query()
            ->where('rme_invoice_id', $invoiceId)
            ->whereIn('status', RmeBillingDisputeStatus::ACTIVE)
            ->first();
    }

    public function activeInvoiceIds(array $invoiceIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $invoiceIds), fn (int $id) => $id > 0)));
        if ($ids === []) {
            return [];
        }

        return RmeBillingDispute::query()
            ->whereIn('rme_invoice_id', $ids)
            ->whereIn('status', RmeBillingDisputeStatus::ACTIVE)
            ->pluck('rme_invoice_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    public function forInvoice(int $invoiceId): Collection
    {
        return RmeBillingDispute::query()
            ->with(['reporter:id,name', 'resolver:id,name'])
            ->where('rme_invoice_id', $invoiceId)
            ->orderByDesc('reported_at')
            ->orderByDesc('id')
            ->get();
    }

    public function find(int $id): ?RmeBillingDispute
    {
        return RmeBillingDispute::query()->find($id);
    }

    public function findForUpdate(int $id): ?RmeBillingDispute
    {
        return RmeBillingDispute::query()->lockForUpdate()->find($id);
    }

    public function paginateForBranches(array $branchIds, array $filters, int $perPage = 20): LengthAwarePaginator
    {
        $query = RmeBillingDispute::query()
            ->with(['branch:id,code,name', 'invoice:id,invoice_number,status,grand_total,clinic_visit_id', 'reporter:id,name'])
            ->whereIn('branch_id', $branchIds === [] ? [0] : $branchIds);

        $status = $filters['status'] ?? null;
        if ($status === 'ACTIVE' || $status === null || $status === '') {
            $query->whereIn('status', RmeBillingDisputeStatus::ACTIVE);
        } elseif (in_array($status, RmeBillingDisputeStatus::ALL, true)) {
            $query->where('status', $status);
        }
        // 'ALL' (or any other value) lists every status within the scope.

        return $query->orderByDesc('reported_at')->orderByDesc('id')->paginate($perPage)->withQueryString();
    }

    public function statusCountsForBranches(array $branchIds): array
    {
        $rows = RmeBillingDispute::query()
            ->whereIn('branch_id', $branchIds === [] ? [0] : $branchIds)
            ->select('status', DB::raw('COUNT(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status');

        $counts = [];
        foreach (RmeBillingDisputeStatus::ALL as $status) {
            $counts[$status] = (int) ($rows[$status] ?? 0);
        }

        return $counts;
    }

    public function create(array $data): RmeBillingDispute
    {
        return RmeBillingDispute::create($data);
    }

    public function assignNumber(RmeBillingDispute $dispute, string $number): RmeBillingDispute
    {
        $dispute->forceFill(['dispute_number' => $number])->save();

        return $dispute;
    }

    public function transition(RmeBillingDispute $dispute, array $data): RmeBillingDispute
    {
        $dispute->forceFill(array_intersect_key($data, array_flip(self::LIFECYCLE_COLUMNS)))->save();

        return $dispute;
    }

    public function recordEvent(array $data): RmeBillingDisputeEvent
    {
        return RmeBillingDisputeEvent::create($data);
    }

    public function events(int $disputeId): Collection
    {
        return RmeBillingDisputeEvent::query()
            ->with('actor:id,name')
            ->where('rme_billing_dispute_id', $disputeId)
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get();
    }

    public function summaryByBranchAndStatus(): Collection
    {
        return DB::table('trx_rme_billing_disputes')
            ->select('branch_id', 'status', DB::raw('COUNT(*) as total'), DB::raw('MIN(reported_at) as oldest_reported_at'))
            ->groupBy('branch_id', 'status')
            ->orderBy('branch_id')
            ->orderBy('status')
            ->get();
    }

    public function auditActionCountsSince(array $actions, \DateTimeInterface $since): array
    {
        $counts = DB::table('sys_audit_logs')
            ->whereIn('action', $actions)
            ->where('created_at', '>=', $since)
            ->select('action', DB::raw('COUNT(*) as total'))
            ->groupBy('action')
            ->pluck('total', 'action');

        $result = [];
        foreach ($actions as $action) {
            $result[$action] = (int) ($counts[$action] ?? 0);
        }

        return $result;
    }
}
