<?php

namespace App\Modules\RmeInvoice\Services;

use App\Models\User;
use App\Modules\Branch\Services\BranchContext;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\RmeInvoice\Interfaces\RmeInvoiceRepositoryInterface;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use App\Modules\RmeInvoice\Models\RmeInvoiceItem;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RmeInvoiceService
{
    public function __construct(
        private readonly RmeInvoiceRepositoryInterface $invoices,
        private readonly BranchContext $branchContext,
        private readonly RmeInvoiceNumberGeneratorService $numberGenerator,
    ) {}

    /** @param array<string, mixed> $filters */
    public function paginatePendingVisits(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        return $this->invoices->paginateCashierPending(
            $this->branchContext->requireId(),
            $filters,
            $perPage,
        );
    }

    public function findInvoiceForVisit(ClinicVisit $visit): ?RmeInvoice
    {
        return $this->invoices->findForVisit($visit->id);
    }

    public function findInBranch(int $id): ?RmeInvoice
    {
        return $this->invoices->findInBranch($this->branchContext->requireId(), $id);
    }

    /**
     * @param  array{notes?: string|null, items: array<int, array{treatment_id?: int|null, description: string, qty: int, unit_price: numeric, discount?: numeric, doctor_id?: int|null}>}  $data
     */
    public function create(ClinicVisit $visit, User $cashier, array $data): RmeInvoice
    {
        return DB::transaction(function () use ($visit, $cashier, $data) {
            $branchId = $this->branchContext->requireId();

            if ($visit->branch_id !== $branchId) {
                throw ValidationException::withMessages([
                    'clinic_visit_id' => 'Kunjungan tidak ditemukan di cabang aktif.',
                ]);
            }

            if ($visit->status !== ClinicVisit::STATUS_CASHIER_PENDING) {
                throw ValidationException::withMessages([
                    'clinic_visit_id' => 'Kunjungan belum siap untuk ditagih. Status harus cashier_pending.',
                ]);
            }

            $medicalRecord = $visit->medicalRecord;
            if ($medicalRecord && $medicalRecord->status !== MedicalRecord::STATUS_FINAL) {
                throw ValidationException::withMessages([
                    'clinic_visit_id' => 'RME belum difinalisasi oleh dokter.',
                ]);
            }

            if ($this->invoices->hasActiveInvoiceForVisit($visit->id)) {
                throw ValidationException::withMessages([
                    'clinic_visit_id' => 'Tagihan aktif sudah ada untuk kunjungan ini.',
                ]);
            }

            $items = $data['items'] ?? [];
            if (empty($items)) {
                throw ValidationException::withMessages([
                    'items' => 'Minimal satu item tagihan wajib diisi.',
                ]);
            }

            $invoiceNumber = $this->numberGenerator->generate();

            $invoice = $this->invoices->create([
                'branch_id' => $branchId,
                'clinic_visit_id' => $visit->id,
                'patient_id' => $visit->patient_id,
                'medical_record_id' => $medicalRecord?->id,
                'cashier_id' => $cashier->id,
                'invoice_number' => $invoiceNumber,
                'status' => RmeInvoice::STATUS_UNPAID,
                'subtotal' => 0,
                'discount_total' => 0,
                'grand_total' => 0,
                'notes' => $data['notes'] ?? null,
            ]);

            $subtotal = 0;
            $discountTotal = 0;

            foreach ($items as $itemData) {
                $qty = (int) ($itemData['qty'] ?? 0);
                $unitPrice = (float) ($itemData['unit_price'] ?? 0);
                $discount = (float) ($itemData['discount'] ?? 0);

                if ($qty < 1) {
                    throw ValidationException::withMessages([
                        'items' => 'Qty item harus minimal 1.',
                    ]);
                }
                if ($unitPrice < 0) {
                    throw ValidationException::withMessages([
                        'items' => 'Harga satuan tidak boleh negatif.',
                    ]);
                }
                if ($discount < 0) {
                    throw ValidationException::withMessages([
                        'items' => 'Diskon tidak boleh negatif.',
                    ]);
                }

                $itemSubtotal = ($qty * $unitPrice) - $discount;

                RmeInvoiceItem::create([
                    'rme_invoice_id' => $invoice->id,
                    'treatment_id' => $itemData['treatment_id'] ?? null,
                    'description' => $itemData['description'],
                    'qty' => $qty,
                    'unit_price' => $unitPrice,
                    'discount' => $discount,
                    'subtotal' => $itemSubtotal,
                    'doctor_id' => $itemData['doctor_id'] ?? null,
                ]);

                $subtotal += $qty * $unitPrice;
                $discountTotal += $discount;
            }

            $grandTotal = $subtotal - $discountTotal;

            $this->invoices->update($invoice, [
                'subtotal' => $subtotal,
                'discount_total' => $discountTotal,
                'grand_total' => $grandTotal,
            ]);

            return $invoice->load('items.treatment');
        });
    }
}
