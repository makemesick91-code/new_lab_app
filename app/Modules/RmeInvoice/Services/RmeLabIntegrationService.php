<?php

namespace App\Modules\RmeInvoice\Services;

use App\Models\User;
use App\Modules\Branch\Services\BranchService;
use App\Modules\LabOrder\Models\LabCaseCandidate;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use App\Modules\RmeInvoice\Models\RmeInvoiceItem;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class RmeLabIntegrationService
{
    public function __construct(
        private readonly BranchService $branches,
    ) {}

    /**
     * Generate LabCaseCandidate records for all eligible items on a PAID invoice.
     *
     * Idempotent: safe to call multiple times with the same invoice.
     * Skips items whose treatment has requires_lab = false or treatment_id = null.
     *
     * @return Collection<int, LabCaseCandidate>
     */
    public function generateForPaidInvoice(RmeInvoice $invoice, ?User $actor = null): Collection
    {
        if ($invoice->status !== RmeInvoice::STATUS_PAID) {
            return collect();
        }

        // Branch isolation: the invoice must belong to the operational "Cabang RME"
        // set (active RME-enabled branches). Scoping by a single BranchContext/MAIN
        // fallback would skip candidate generation for every RME-branch payment in
        // the pilot (Sprint 23 Phase 23.10).
        if (! in_array((int) $invoice->branch_id, $this->branches->rmeEnabledIds(), true)) {
            throw ValidationException::withMessages([
                'rme_invoice_id' => 'Invoice tidak berada di cabang RME aktif.',
            ]);
        }

        $invoice->loadMissing(['items.treatment', 'clinicVisit']);

        return $invoice->items
            ->filter(fn (RmeInvoiceItem $item) => $item->treatment_id !== null
                && $item->treatment !== null
                && $item->treatment->requires_lab)
            ->map(fn (RmeInvoiceItem $item) => $this->generateForInvoiceItem($item, $invoice, $actor))
            ->values();
    }

    /**
     * Generate (or return existing) LabCaseCandidate for a single invoice item.
     *
     * Uses firstOrCreate keyed on rme_invoice_item_id — duplicate-safe.
     */
    public function generateForInvoiceItem(RmeInvoiceItem $item, RmeInvoice $invoice, ?User $actor = null): LabCaseCandidate
    {
        $doctorId = $item->doctor_id ?? $invoice->clinicVisit?->doctor_id;

        return LabCaseCandidate::firstOrCreate(
            ['rme_invoice_item_id' => $item->id],
            [
                'branch_id' => $invoice->branch_id,
                'clinic_visit_id' => $invoice->clinic_visit_id,
                'rme_invoice_id' => $invoice->id,
                'patient_id' => $invoice->patient_id,
                'doctor_id' => $doctorId,
                'treatment_id' => $item->treatment_id,
                'medical_record_id' => $invoice->medical_record_id,
                'source_description' => $item->description,
                'quantity' => $item->qty,
                'estimated_price' => $item->unit_price,
                'status' => LabCaseCandidate::STATUS_PENDING_REVIEW,
                'created_by' => $actor?->id,
            ]
        );
    }
}
