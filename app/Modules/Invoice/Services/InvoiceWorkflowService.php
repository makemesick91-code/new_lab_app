<?php

namespace App\Modules\Invoice\Services;

use App\Models\User;
use App\Modules\Invoice\Interfaces\InvoiceRepositoryInterface;
use App\Modules\Invoice\Models\Invoice;
use App\Modules\LabOrder\Models\AuditLog;
use App\Modules\LabOrder\Services\AuditLogService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InvoiceWorkflowService
{
    public function __construct(
        private readonly InvoiceRepositoryInterface $invoices,
        private readonly AuditLogService $auditLogs,
    ) {}

    public function issue(Invoice $invoice, ?string $notes = null, ?User $actor = null): Invoice
    {
        $actor = $actor ?? auth()->user();

        return DB::transaction(function () use ($invoice, $notes, $actor) {
            $invoice = Invoice::query()->withCount('items')->lockForUpdate()->findOrFail($invoice->id);

            if ($invoice->status !== Invoice::STATUS_DRAFT) {
                throw ValidationException::withMessages(['status' => 'Invoice hanya dapat diterbitkan dari status DRAFT.']);
            }

            if ($invoice->items_count < 1) {
                throw ValidationException::withMessages(['items' => 'Invoice wajib memiliki minimal satu item.']);
            }

            if ((float) $invoice->total_amount <= 0) {
                throw ValidationException::withMessages(['total_amount' => 'Total invoice harus lebih dari 0.']);
            }

            if (! $invoice->due_date) {
                throw ValidationException::withMessages(['due_date' => 'Due date wajib diisi sebelum invoice diterbitkan.']);
            }

            $updated = $this->invoices->update($invoice, [
                'status' => Invoice::STATUS_ISSUED,
                'issued_at' => now(),
                'notes' => $notes ?? $invoice->notes,
            ]);

            $this->auditLogs->log(
                Invoice::ENTITY_TYPE,
                $updated->id,
                AuditLog::ACTION_INVOICE_ISSUED,
                ['status' => Invoice::STATUS_DRAFT],
                ['status' => Invoice::STATUS_ISSUED, 'notes' => $notes],
                $actor,
            );

            return $updated;
        });
    }

    public function void(Invoice $invoice, string $notes, ?User $actor = null): Invoice
    {
        $actor = $actor ?? auth()->user();

        return DB::transaction(function () use ($invoice, $notes, $actor) {
            $invoice = Invoice::query()->withCount('payments')->lockForUpdate()->findOrFail($invoice->id);

            if (! in_array($invoice->status, [Invoice::STATUS_DRAFT, Invoice::STATUS_ISSUED], true)) {
                throw ValidationException::withMessages(['status' => 'Hanya invoice DRAFT atau ISSUED yang dapat divoid.']);
            }

            if ($invoice->payments_count > 0 || (float) $invoice->paid_amount > 0) {
                throw ValidationException::withMessages(['status' => 'Invoice yang sudah memiliki payment tidak dapat divoid.']);
            }

            $oldStatus = $invoice->status;
            $updated = $this->invoices->update($invoice, [
                'status' => Invoice::STATUS_VOID,
                'voided_at' => now(),
                'notes' => $notes,
            ]);

            $this->auditLogs->log(
                Invoice::ENTITY_TYPE,
                $updated->id,
                AuditLog::ACTION_INVOICE_VOIDED,
                ['status' => $oldStatus],
                ['status' => Invoice::STATUS_VOID, 'notes' => $notes],
                $actor,
            );

            return $updated;
        });
    }

    public function markOverdue(Invoice $invoice, ?User $actor = null): Invoice
    {
        $actor = $actor ?? auth()->user();

        if (! in_array($invoice->status, [Invoice::STATUS_ISSUED, Invoice::STATUS_PARTIALLY_PAID], true)) {
            return $invoice;
        }

        if (! $invoice->due_date || ! $invoice->due_date->isPast()) {
            return $invoice;
        }

        $oldStatus = $invoice->status;
        $updated = $this->invoices->update($invoice, ['status' => Invoice::STATUS_OVERDUE]);

        $this->auditLogs->log(
            Invoice::ENTITY_TYPE,
            $updated->id,
            AuditLog::ACTION_INVOICE_MARKED_OVERDUE,
            ['status' => $oldStatus],
            ['status' => Invoice::STATUS_OVERDUE],
            $actor,
        );

        return $updated;
    }
}
