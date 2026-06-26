<?php

namespace App\Modules\RmeInvoice\Services;

use App\Models\User;
use App\Modules\Branch\Services\BranchContext;
use App\Modules\Branch\Services\BranchService;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\RmeInvoice\Interfaces\RmeInvoiceRepositoryInterface;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use App\Modules\RmeInvoice\Models\RmeInvoiceItem;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RmeInvoiceService
{
    public function __construct(
        private readonly RmeInvoiceRepositoryInterface $invoices,
        private readonly BranchContext $branchContext,
        private readonly RmeInvoiceNumberGeneratorService $numberGenerator,
        private readonly BranchService $branches,
        private readonly CashierHandoffStatusService $handoffStatus,
    ) {}

    /**
     * Hotfix Sprint 60.7 — Doctor → Cashier sync queue. Returns active visits across
     * the active "Cabang RME" set, each tagged with its derived handoff status and
     * grouped into the cashier-facing pipeline groups. Read-only/visibility only.
     *
     * @param  array<string, mixed>  $filters
     * @return array{
     *     groups: array<string, Collection<int, ClinicVisit>>,
     *     counts: array<string, int>,
     *     total: int
     * }
     */
    public function cashierHandoffQueue(array $filters = []): array
    {
        $visits = $this->invoices->cashierHandoffQueueForBranches(
            $this->branches->rmeEnabledIds(),
            $filters,
        );

        $groups = [];
        $counts = [];
        foreach (CashierHandoffStatusService::QUEUE_GROUPS as $group) {
            $groups[$group] = collect();
            $counts[$group] = 0;
        }

        $requestedGroup = $filters['group'] ?? null;

        $total = 0;
        foreach ($visits as $visit) {
            $key = $this->handoffStatus->determineKey($visit);

            if (! array_key_exists($key, $groups)) {
                continue; // terminal states never reach the queue, but stay defensive
            }

            $visit->setAttribute('handoff_status', $key);

            // Counts always reflect the full pipeline so the cashier sees how many
            // visits sit in every group; the group filter only narrows what is listed.
            $counts[$key]++;

            if ($requestedGroup !== null && $requestedGroup !== $key) {
                continue;
            }

            $groups[$key]->push($visit);
            $total++;
        }

        return [
            'groups' => $groups,
            'counts' => $counts,
            'total' => $total,
        ];
    }

    /**
     * Cashier pending queue is scoped to the operational "Cabang RME" set (active
     * RME-enabled branches), NOT a single BranchContext fallback. In the pilot the
     * fallback resolves to MAIN, which is not RME-enabled, so scoping by it left the
     * queue permanently empty. MAIN is excluded by definition (Sprint 23 Phase 23.10).
     *
     * @param  array<string, mixed>  $filters
     */
    public function paginatePendingVisits(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        return $this->invoices->paginateCashierPendingForBranches(
            $this->branches->rmeEnabledIds(),
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
            // The invoice branch always follows the visit's own "Cabang RME"
            // branch — never a BranchContext/MAIN fallback. The visit must belong
            // to an active RME-enabled branch (Sprint 23 Phase 23.10).
            $branchId = (int) $visit->branch_id;

            if (! in_array($branchId, $this->branches->rmeEnabledIds(), true)) {
                throw ValidationException::withMessages([
                    'clinic_visit_id' => 'Kunjungan tidak berada di cabang RME aktif.',
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
