<?php

namespace App\Modules\RmeInvoice\Repositories;

use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\RmeInvoice\Interfaces\RmeInvoiceRepositoryInterface;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class RmeInvoiceRepository implements RmeInvoiceRepositoryInterface
{
    public function paginateCashierPending(int $branchId, array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        return ClinicVisit::query()
            ->with(['patient', 'doctor', 'initialTreatment', 'rmeInvoice'])
            ->where('branch_id', $branchId)
            ->where('status', ClinicVisit::STATUS_CASHIER_PENDING)
            ->when($filters['search'] ?? null, function ($query, $search) {
                $term = '%'.mb_strtolower($search).'%';
                $query->where(function ($q) use ($term) {
                    $q->whereRaw('LOWER(visit_number) LIKE ?', [$term])
                        ->orWhereHas('patient', fn ($q) => $q->whereRaw('LOWER(name) LIKE ?', [$term]));
                });
            })
            ->orderByDesc('updated_at')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function findForVisit(int $clinicVisitId): ?RmeInvoice
    {
        return RmeInvoice::query()
            ->with(['items.treatment', 'patient', 'clinicVisit', 'cashier', 'medicalRecord'])
            ->where('clinic_visit_id', $clinicVisitId)
            ->whereIn('status', [RmeInvoice::STATUS_DRAFT, RmeInvoice::STATUS_UNPAID])
            ->first();
    }

    public function hasActiveInvoiceForVisit(int $clinicVisitId): bool
    {
        return RmeInvoice::query()
            ->where('clinic_visit_id', $clinicVisitId)
            ->whereIn('status', [RmeInvoice::STATUS_DRAFT, RmeInvoice::STATUS_UNPAID])
            ->exists();
    }

    public function latestInvoiceNumberForMonth(string $month): ?string
    {
        return RmeInvoice::query()
            ->where('invoice_number', 'like', 'RME-'.$month.'-%')
            ->max('invoice_number');
    }

    public function create(array $data): RmeInvoice
    {
        return RmeInvoice::create($data);
    }

    public function update(RmeInvoice $invoice, array $data): RmeInvoice
    {
        $invoice->update($data);

        return $invoice->refresh();
    }

    public function findInBranch(int $branchId, int $id): ?RmeInvoice
    {
        return RmeInvoice::query()
            ->with(['items.treatment', 'patient', 'clinicVisit.doctor', 'cashier', 'medicalRecord'])
            ->where('branch_id', $branchId)
            ->find($id);
    }
}
