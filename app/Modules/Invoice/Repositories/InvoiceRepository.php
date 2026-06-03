<?php

namespace App\Modules\Invoice\Repositories;

use App\Modules\Invoice\Interfaces\InvoiceRepositoryInterface;
use App\Modules\Invoice\Models\Invoice;
use App\Modules\LabOrder\Models\LabOrder;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class InvoiceRepository implements InvoiceRepositoryInterface
{
    public function paginate(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $search = $filters['search'] ?? null;

        return Invoice::query()
            ->with(['clinic', 'creator'])
            ->withCount(['items', 'payments'])
            ->when($search, function ($query, $search) {
                $term = '%'.mb_strtolower($search).'%';
                $query->where(function ($q) use ($term) {
                    $q->whereRaw('LOWER(invoice_number) LIKE ?', [$term])
                        ->orWhereHas('clinic', fn ($clinic) => $clinic->whereRaw('LOWER(name) LIKE ?', [$term]));
                });
            })
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['clinic_id'] ?? null, fn ($q, $clinicId) => $q->where('clinic_id', $clinicId))
            ->when($filters['invoice_date'] ?? null, fn ($q, $date) => $q->whereDate('invoice_date', $date))
            ->orderByDesc('invoice_date')
            ->orderByDesc('id')
            ->paginate(min($perPage, 100))
            ->withQueryString();
    }

    public function find(int $id): ?Invoice
    {
        return Invoice::query()
            ->with([
                'clinic',
                'creator',
                'items.labOrder.clinic',
                'items.labOrder.doctor',
                'items.labOrder.patient',
                'payments.receiver',
                'payments.creator',
                'auditLogs.performer',
            ])
            ->find($id);
    }

    public function create(array $data): Invoice
    {
        return Invoice::create($data);
    }

    public function createItems(Invoice $invoice, array $items): void
    {
        $invoice->items()->createMany($items);
    }

    public function update(Invoice $invoice, array $data): Invoice
    {
        $invoice->update($data);

        return $invoice->refresh();
    }

    public function latestInvoiceNumberForMonth(string $month): ?string
    {
        return Invoice::query()
            ->where('invoice_number', 'like', "INV-{$month}-%")
            ->orderByDesc('invoice_number')
            ->value('invoice_number');
    }

    public function hasActiveInvoiceForLabOrders(array $labOrderIds): bool
    {
        return Invoice::query()
            ->where('status', '!=', Invoice::STATUS_VOID)
            ->whereHas('items', fn ($query) => $query->whereIn('lab_order_id', $labOrderIds))
            ->exists();
    }

    public function completedOrdersAvailable(array $filters = [], array $excludeOrderIds = [], int $limit = 100): Collection
    {
        $search = $filters['search'] ?? null;

        return LabOrder::query()
            ->with(['clinic', 'doctor', 'patient', 'items.labService'])
            ->where('status', LabOrder::STATUS_COMPLETED)
            ->when($search, function ($query, $search) {
                $term = '%'.mb_strtolower($search).'%';
                $query->where(function ($q) use ($term) {
                    $q->whereRaw('LOWER(order_number) LIKE ?', [$term])
                        ->orWhereHas('clinic', fn ($clinic) => $clinic->whereRaw('LOWER(name) LIKE ?', [$term]))
                        ->orWhereHas('doctor', fn ($doctor) => $doctor->whereRaw('LOWER(name) LIKE ?', [$term]))
                        ->orWhereHas('patient', fn ($patient) => $patient->whereRaw('LOWER(name) LIKE ?', [$term]));
                });
            })
            ->when($filters['clinic_id'] ?? null, fn ($q, $clinicId) => $q->where('clinic_id', $clinicId))
            ->when($excludeOrderIds !== [], fn ($q) => $q->whereNotIn('id', $excludeOrderIds))
            ->whereDoesntHave('invoiceItems.invoice', fn ($invoice) => $invoice->where('status', '!=', Invoice::STATUS_VOID))
            ->orderBy('clinic_id')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }
}
