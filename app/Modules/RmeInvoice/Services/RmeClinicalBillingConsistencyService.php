<?php

declare(strict_types=1);

namespace App\Modules\RmeInvoice\Services;

use App\Modules\Branch\Services\BranchService;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Odontogram\Interfaces\OdontogramRepositoryInterface;
use App\Modules\PerformedTreatment\Services\RmePerformedTreatmentService;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use App\Modules\RmeInvoice\Support\RmeClinicalBillingConsistency;
use App\Modules\RmeInvoice\Support\RmeClinicalBillingConsistencyResult;
use App\Modules\RmeInvoice\Support\RmeInvoiceDocument;

/**
 * HARDENING-RME-RP0-CLINICAL-BILLING-CONSISTENCY-1 (rule 218) — gathers the
 * structured clinical rows the cross-check reads and hands them to the pure
 * {@see RmeClinicalBillingConsistency}. Read-only: it never writes, never locks and
 * never refuses anything (owner decision CB-Q2 — detective only).
 *
 * The closure calls it on the LOCKED invoice and visit, after eligibility passed;
 * the cashier page calls it for display. The CLINICAL inputs are stable at
 * cashier_pending: the visit's own chart can no longer be authored (rule 109
 * CORRECTIVE-03), an earlier visit's chart is permanently read-only, the registered
 * treatment comes from the locked visit row, and invoice lines change only by a
 * correction, which the closure refuses. Master data (a treatment's category,
 * requires_lab, code) and a branch's RME status are read unlocked, at their
 * committed state — acceptable for a detective check, which records what it saw.
 *
 * It takes no actor on purpose: every authorized closer, Super Admin included,
 * gets the same answer. The patient's earlier chart is read across the active RME
 * branch set (an ENGINEERING choice, not an owner decision: patient history is
 * cross-branch in the doctor workspace, and only a count and ids leave here), so a
 * cashier pinned to one branch may see a count derived from another branch's chart.
 */
class RmeClinicalBillingConsistencyService
{
    public function __construct(
        private readonly OdontogramRepositoryInterface $odontograms,
        private readonly BranchService $branches,
        private readonly RmePerformedTreatmentService $performedTreatments,
    ) {}

    public function evaluate(RmeInvoice $invoice, ClinicVisit $visit): RmeClinicalBillingConsistencyResult
    {
        // A positive total is paid normally — never evaluated, never queried.
        if (RmeInvoiceDocument::toCents($invoice->grand_total) !== 0) {
            return RmeClinicalBillingConsistencyResult::notApplicable();
        }

        $invoice->loadMissing('items');

        // Read through the relation without setting it, so the caller's loaded
        // shape is untouched; soft-deleted master data included, as the closure
        // decides on it.
        $registered = $visit->initial_treatment_id === null ? null : $visit->initialTreatment()
            ->withTrashed()
            ->with(['category' => fn ($category) => $category->withTrashed()])
            ->first();

        $current = $this->odontograms->findByClinicVisit((int) $visit->id);
        if ($current !== null && ! $current->hasRecordedTeeth()) {
            $current = null;
        }

        $baseline = $current === null ? null
            : $this->odontograms->latestChartedBeforeVisit($this->branches->rmeEnabledIds(), $visit);

        // Rule 219 — the doctor's structured record of THIS visit; null while the
        // capability is OFF, which keeps the evaluation exactly as before.
        $doctor = $this->performedTreatments->evidenceFor($visit);

        return RmeClinicalBillingConsistency::evaluate($invoice, $visit, $registered, $current, $baseline, $doctor);
    }
}
