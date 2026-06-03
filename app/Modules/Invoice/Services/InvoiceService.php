<?php

namespace App\Modules\Invoice\Services;

use App\Models\User;
use App\Modules\Invoice\Interfaces\InvoiceRepositoryInterface;
use App\Modules\Invoice\Models\Invoice;
use App\Modules\LabOrder\Models\AuditLog;
use App\Modules\LabOrder\Models\LabOrder;
use App\Modules\LabOrder\Services\AuditLogService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InvoiceService
{
    public function __construct(
        private readonly InvoiceRepositoryInterface $invoices,
        private readonly InvoiceNumberGeneratorService $numberGenerator,
        private readonly AuditLogService $auditLogs,
    ) {}

    public function paginate(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        return $this->invoices->paginate($filters, $perPage);
    }

    public function find(int $id): ?Invoice
    {
        return $this->invoices->find($id);
    }

    public function availableOrders(array $filters = []): Collection
    {
        return $this->invoices->completedOrdersAvailable($filters);
    }

    public function create(array $data, ?User $actor = null): Invoice
    {
        $actor = $actor ?? auth()->user();
        $labOrderIds = array_values(array_unique(array_map('intval', $data['lab_order_ids'] ?? [])));

        if ($labOrderIds === []) {
            throw ValidationException::withMessages(['lab_order_ids' => 'Minimal satu Lab Order wajib dipilih.']);
        }

        return DB::transaction(function () use ($data, $labOrderIds, $actor) {
            $orders = LabOrder::query()
                ->with(['clinic', 'items.labService'])
                ->whereIn('id', $labOrderIds)
                ->lockForUpdate()
                ->get();

            $this->validateOrders($orders, $labOrderIds, (int) ($data['clinic_id'] ?? 0));

            if ($this->invoices->hasActiveInvoiceForLabOrders($labOrderIds)) {
                throw ValidationException::withMessages(['lab_order_ids' => 'Salah satu Lab Order sudah masuk invoice aktif.']);
            }

            $itemRows = $orders->map(fn (LabOrder $order) => $this->buildItemRow($order))->values()->all();
            $totals = $this->calculateTotals(
                collect($itemRows)->sum('total_price'),
                (float) ($data['discount_amount'] ?? 0),
                (float) ($data['tax_amount'] ?? 0),
            );

            $invoice = $this->invoices->create([
                'invoice_number' => $this->numberGenerator->generate(),
                'clinic_id' => (int) $data['clinic_id'],
                'invoice_date' => $data['invoice_date'],
                'due_date' => $data['due_date'] ?? null,
                'status' => Invoice::STATUS_DRAFT,
                'subtotal' => $totals['subtotal'],
                'discount_amount' => $totals['discount_amount'],
                'tax_amount' => $totals['tax_amount'],
                'total_amount' => $totals['total_amount'],
                'paid_amount' => 0,
                'outstanding_amount' => $totals['total_amount'],
                'notes' => $data['notes'] ?? null,
                'created_by' => $actor?->id,
            ]);

            $this->invoices->createItems($invoice, $itemRows);

            $this->auditLogs->log(
                Invoice::ENTITY_TYPE,
                $invoice->id,
                AuditLog::ACTION_INVOICE_CREATED,
                null,
                [
                    'invoice_number' => $invoice->invoice_number,
                    'lab_order_ids' => $labOrderIds,
                    'total_amount' => $invoice->total_amount,
                ],
                $actor,
            );

            return $invoice->refresh();
        });
    }

    private function validateOrders(Collection $orders, array $labOrderIds, int $clinicId): void
    {
        if ($orders->count() !== count($labOrderIds)) {
            throw ValidationException::withMessages(['lab_order_ids' => 'Lab Order yang dipilih tidak valid.']);
        }

        if ($orders->contains(fn (LabOrder $order) => $order->status !== LabOrder::STATUS_COMPLETED)) {
            throw ValidationException::withMessages(['lab_order_ids' => 'Semua Lab Order harus COMPLETED sebelum dibuat invoice.']);
        }

        if ($orders->pluck('clinic_id')->unique()->count() !== 1 || (int) $orders->first()->clinic_id !== $clinicId) {
            throw ValidationException::withMessages(['clinic_id' => 'Semua Lab Order harus berasal dari Clinic yang sama.']);
        }
    }

    private function buildItemRow(LabOrder $order): array
    {
        $order->loadMissing('items.labService');

        $total = (float) $order->items->sum('subtotal');
        $description = $order->items->map(function ($item) {
            return $item->labService?->name ?? $item->material_text ?? 'Lab Order Item';
        })->filter()->unique()->implode(', ');

        return [
            'lab_order_id' => $order->id,
            'description' => $description ?: $order->order_number,
            'quantity' => 1,
            'unit_price' => $total,
            'discount_amount' => 0,
            'total_price' => $total,
        ];
    }

    private function calculateTotals(float $subtotal, float $discountAmount, float $taxAmount): array
    {
        $discountAmount = max(0, $discountAmount);
        $taxAmount = max(0, $taxAmount);
        $totalAmount = max(0, $subtotal - $discountAmount + $taxAmount);

        return [
            'subtotal' => round($subtotal, 2),
            'discount_amount' => round($discountAmount, 2),
            'tax_amount' => round($taxAmount, 2),
            'total_amount' => round($totalAmount, 2),
        ];
    }
}
