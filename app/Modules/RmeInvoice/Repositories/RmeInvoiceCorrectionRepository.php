<?php

declare(strict_types=1);

namespace App\Modules\RmeInvoice\Repositories;

use App\Modules\Doctor\Models\Doctor;
use App\Modules\LabOrder\Models\LabCaseCandidate;
use App\Modules\RmeInvoice\Interfaces\RmeInvoiceCorrectionRepositoryInterface;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use App\Modules\RmeInvoice\Models\RmeInvoiceCorrection;
use App\Modules\RmeInvoice\Models\RmeInvoiceCorrectionEvent;
use App\Modules\RmeInvoice\Models\RmeInvoiceCorrectionLine;
use App\Modules\RmeInvoice\Models\RmeInvoiceDocumentVersion;
use App\Modules\RmeInvoice\Support\RmeInvoiceCorrectionStatus;
use App\Modules\RmeInvoice\Support\RmeInvoiceCorrectionType;
use App\Modules\Treatment\Models\Treatment;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class RmeInvoiceCorrectionRepository implements RmeInvoiceCorrectionRepositoryInterface
{
    /** The only columns {@see transition()} may write. */
    private const LIFECYCLE_COLUMNS = [
        'status',
        'decided_by',
        'decided_at',
        'decision_note',
        'applied_by',
        'applied_at',
        'applied_invoice_version',
        'cancelled_by',
        'cancelled_at',
        'cancel_reason',
        'stale_at',
        'stale_reason_code',
        // FEATURE-RME-POSTPAYMENT-INVOICE-CORRECTION-1 — the ledger outcome,
        // written once by the application.
        'outstanding_after',
        'overpaid_after',
        'invoice_status_after',
    ];

    public function find(int $id): ?RmeInvoiceCorrection
    {
        return RmeInvoiceCorrection::query()
            ->with([
                'branch',
                'patient',
                'invoice',
                'clinicVisit',
                'requester',
                'decider',
                'applier',
                'canceller',
                'lines.proposedTreatment',
                'lines.originalTreatment',
                'lines.proposedDoctor',
                'lines.originalDoctor',
                'events.actor',
                'revisionVersion',
            ])
            ->find($id);
    }

    public function findForUpdate(int $id): ?RmeInvoiceCorrection
    {
        return RmeInvoiceCorrection::query()->lockForUpdate()->find($id);
    }

    public function findByIdempotencyKey(string $key): ?RmeInvoiceCorrection
    {
        return RmeInvoiceCorrection::query()->where('idempotency_key', $key)->first();
    }

    public function activeForInvoice(int $invoiceId): ?RmeInvoiceCorrection
    {
        return RmeInvoiceCorrection::query()
            ->where('rme_invoice_id', $invoiceId)
            ->whereIn('status', RmeInvoiceCorrectionStatus::ACTIVE)
            ->first();
    }

    public function latestAppliedForInvoice(int $invoiceId): ?RmeInvoiceCorrection
    {
        return RmeInvoiceCorrection::query()
            ->where('rme_invoice_id', $invoiceId)
            ->where('status', RmeInvoiceCorrectionStatus::APPLIED)
            ->orderByDesc('applied_invoice_version')
            ->orderByDesc('id')
            ->first();
    }

    public function forInvoice(int $invoiceId): Collection
    {
        return RmeInvoiceCorrection::query()
            ->with(['requester', 'decider', 'applier'])
            ->where('rme_invoice_id', $invoiceId)
            ->orderByDesc('requested_at')
            ->orderByDesc('id')
            ->get();
    }

    public function create(array $data): RmeInvoiceCorrection
    {
        return RmeInvoiceCorrection::create($data);
    }

    public function assignNumber(RmeInvoiceCorrection $correction, string $number): RmeInvoiceCorrection
    {
        $correction->forceFill(['correction_number' => $number])->save();

        return $correction;
    }

    public function createLine(array $data): RmeInvoiceCorrectionLine
    {
        return RmeInvoiceCorrectionLine::create($data);
    }

    public function transition(RmeInvoiceCorrection $correction, array $data): RmeInvoiceCorrection
    {
        $correction->forceFill(array_intersect_key($data, array_flip(self::LIFECYCLE_COLUMNS)))->save();

        return $correction;
    }

    public function recordEvent(array $data): RmeInvoiceCorrectionEvent
    {
        return RmeInvoiceCorrectionEvent::create($data);
    }

    public function paginateForBranches(array $branchIds, array $filters, int $perPage = 20): LengthAwarePaginator
    {
        return RmeInvoiceCorrection::query()
            ->with(['branch', 'patient', 'invoice', 'requester', 'decider'])
            ->whereIn('branch_id', $branchIds === [] ? [0] : $branchIds)
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->whereIn('status', (array) $status))
            ->when($filters['mine'] ?? null, fn ($query, $userId) => $query->where('requested_by', (int) $userId))
            ->when($filters['search'] ?? null, function ($query, $search) {
                $term = '%'.mb_strtolower((string) $search).'%';
                $query->where(function ($q) use ($term) {
                    $q->whereRaw('LOWER(correction_number) LIKE ?', [$term])
                        ->orWhereHas('invoice', fn ($i) => $i->whereRaw('LOWER(invoice_number) LIKE ?', [$term]))
                        ->orWhereHas('patient', function ($p) use ($term) {
                            $p->whereRaw('LOWER(name) LIKE ?', [$term])
                                ->orWhereRaw('LOWER(medical_record_number) LIKE ?', [$term]);
                        });
                });
            })
            ->orderByDesc('requested_at')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function countByStatusForBranches(array $branchIds): array
    {
        $counts = RmeInvoiceCorrection::query()
            ->whereIn('branch_id', $branchIds === [] ? [0] : $branchIds)
            ->selectRaw('status, COUNT(*) AS aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->map(fn ($count) => (int) $count)
            ->all();

        $result = [];
        foreach (RmeInvoiceCorrectionStatus::ALL as $status) {
            $result[$status] = $counts[$status] ?? 0;
        }

        return $result;
    }

    public function activeTreatments(array $ids): Collection
    {
        if ($ids === []) {
            return collect();
        }

        return Treatment::query()
            ->whereIn('id', $ids)
            ->where('is_active', true)
            ->get()
            ->keyBy('id');
    }

    public function treatmentOptions(): Collection
    {
        return Treatment::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'code', 'name']);
    }

    public function existingDoctorIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return Doctor::query()
            ->whereIn('id', $ids)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    public function invoiceHasLabCandidates(int $invoiceId): bool
    {
        return LabCaseCandidate::query()->where('rme_invoice_id', $invoiceId)->exists();
    }

    public function postPaymentReferenceUsedOnOtherInvoice(string $reference, int $invoiceId): bool
    {
        return RmeInvoiceCorrection::query()
            ->where('correction_type', RmeInvoiceCorrectionType::POST_PAYMENT)
            ->where('rme_invoice_id', '!=', $invoiceId)
            ->whereRaw('lower(owner_approval_reference) = ?', [mb_strtolower($reference)])
            ->exists();
    }

    public function postPaymentReferenceSpentOnInvoice(string $reference, int $invoiceId): bool
    {
        return RmeInvoiceCorrection::query()
            ->where('correction_type', RmeInvoiceCorrectionType::POST_PAYMENT)
            ->where('rme_invoice_id', $invoiceId)
            ->whereIn('status', [RmeInvoiceCorrectionStatus::APPROVED, RmeInvoiceCorrectionStatus::APPLIED])
            ->whereRaw('lower(owner_approval_reference) = ?', [mb_strtolower($reference)])
            ->exists();
    }

    public function documentVersionsForInvoice(int $invoiceId): Collection
    {
        return RmeInvoiceDocumentVersion::query()
            ->with(['sourceCorrection', 'issuer'])
            ->where('rme_invoice_id', $invoiceId)
            ->orderBy('version_number')
            ->get();
    }

    public function latestDocumentVersion(int $invoiceId): ?RmeInvoiceDocumentVersion
    {
        return RmeInvoiceDocumentVersion::query()
            ->where('rme_invoice_id', $invoiceId)
            ->orderByDesc('version_number')
            ->first();
    }

    public function originalDocumentVersion(int $invoiceId): ?RmeInvoiceDocumentVersion
    {
        return RmeInvoiceDocumentVersion::query()
            ->where('rme_invoice_id', $invoiceId)
            ->where('version_number', 0)
            ->where('version_kind', RmeInvoiceDocumentVersion::KIND_ORIGINAL)
            ->first();
    }

    public function createDocumentVersion(array $data): RmeInvoiceDocumentVersion
    {
        return RmeInvoiceDocumentVersion::create($data);
    }

    public function findDocumentVersion(int $id): ?RmeInvoiceDocumentVersion
    {
        return RmeInvoiceDocumentVersion::query()
            ->with(['invoice.patient', 'invoice.clinicVisit', 'sourceCorrection', 'issuer', 'branch'])
            ->find($id);
    }

    public function activeCountByBranch(): array
    {
        return RmeInvoiceCorrection::query()
            ->whereIn('status', RmeInvoiceCorrectionStatus::ACTIVE)
            ->selectRaw('branch_id, count(*) as aggregate')
            ->groupBy('branch_id')
            ->pluck('aggregate', 'branch_id')
            ->map(fn ($count) => (int) $count)
            ->all();
    }

    public function positiveUnpaidUnsettledInvoiceCount(array $branchIds): int
    {
        if ($branchIds === []) {
            return 0;
        }

        return RmeInvoice::query()
            ->whereIn('branch_id', $branchIds)
            ->where('status', RmeInvoice::STATUS_UNPAID)
            ->where('grand_total', '>', 0)
            ->whereDoesntHave('payments')
            ->count();
    }
}
