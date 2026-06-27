<?php

namespace App\Modules\RmeInvoice\Services;

use App\Modules\Branch\Services\BranchService;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class RmeControlReceivableService
{
    public function __construct(
        private readonly BranchService $branches,
    ) {}

    /**
     * @return Collection<int, RmeInvoice>
     */
    public function getCarryOverInvoicesForControlVisit(ClinicVisit $visit): Collection
    {
        if (! $this->isControlVisitWithParent($visit)) {
            return collect();
        }

        $ancestorVisitIds = $this->collectAncestorVisitIds($visit);

        if ($ancestorVisitIds === []) {
            return collect();
        }

        $rmeBranchIds = $this->branches->rmeEnabledIds();

        return RmeInvoice::query()
            ->whereIn('clinic_visit_id', $ancestorVisitIds)
            ->where('patient_id', $visit->patient_id)
            ->whereIn('branch_id', $rmeBranchIds)
            ->whereIn('status', [RmeInvoice::STATUS_UNPAID, RmeInvoice::STATUS_PARTIAL])
            ->with(['clinicVisit', 'payments', 'items'])
            ->get()
            ->filter(fn (RmeInvoice $invoice) => $this->getCurrentInvoiceRemaining($invoice) > 0)
            ->sortBy(fn (RmeInvoice $invoice) => array_search($invoice->clinic_visit_id, $ancestorVisitIds, true))
            ->values();
    }

    public function getCarryOverBalanceForControlVisit(ClinicVisit $visit): float
    {
        return round(
            $this->getCarryOverInvoicesForControlVisit($visit)
                ->sum(fn (RmeInvoice $invoice) => $this->getCurrentInvoiceRemaining($invoice)),
            2,
        );
    }

    public function getCurrentInvoiceRemaining(RmeInvoice $invoice): float
    {
        return $invoice->remainingAmount();
    }

    /**
     * @return array{
     *     carry_over_invoices: Collection<int, RmeInvoice>,
     *     carry_over_remaining: float,
     *     current_invoice: RmeInvoice,
     *     current_remaining: float,
     *     total_payable: float,
     *     has_carry_over: bool,
     * }
     */
    public function getControlPayableSummary(RmeInvoice $controlInvoice): array
    {
        $controlInvoice->loadMissing('clinicVisit');
        $visit = $controlInvoice->clinicVisit;

        if (! $visit) {
            throw ValidationException::withMessages([
                'rme_invoice_id' => 'Kunjungan klinik tidak ditemukan untuk invoice ini.',
            ]);
        }

        $this->assertControlInvoiceChain($controlInvoice, $visit);

        $carryOverInvoices = $this->getCarryOverInvoicesForControlVisit($visit);
        $carryOverRemaining = round(
            $carryOverInvoices->sum(fn (RmeInvoice $invoice) => $this->getCurrentInvoiceRemaining($invoice)),
            2,
        );
        $currentRemaining = $this->getCurrentInvoiceRemaining($controlInvoice);

        return [
            'carry_over_invoices' => $carryOverInvoices,
            'carry_over_remaining' => $carryOverRemaining,
            'current_invoice' => $controlInvoice,
            'current_remaining' => $currentRemaining,
            'total_payable' => round($carryOverRemaining + $currentRemaining, 2),
            'has_carry_over' => $carryOverRemaining > 0,
        ];
    }

    public function hasCarryOver(RmeInvoice $controlInvoice): bool
    {
        $controlInvoice->loadMissing('clinicVisit');

        if (! $controlInvoice->clinicVisit) {
            return false;
        }

        return $this->getCarryOverBalanceForControlVisit($controlInvoice->clinicVisit) > 0;
    }

    /**
     * Sprint 62.2 — generalized patient-level outstanding receivable discovery.
     *
     * Returns the patient's OTHER outstanding RME invoices (any visit, not just
     * follow_up_of_visit_id chains) so the cashier can optionally collect them on
     * any new visit. Branch isolation, patient scoping and payable status are
     * enforced here; the current invoice is never listed as its own receivable.
     *
     * @return Collection<int, RmeInvoice>
     */
    public function getOutstandingReceivablesForPatientVisit(ClinicVisit $visit, RmeInvoice $currentInvoice): Collection
    {
        $rmeBranchIds = $this->branches->rmeEnabledIds();

        return RmeInvoice::query()
            ->where('patient_id', $visit->patient_id)
            ->whereIn('branch_id', $rmeBranchIds)
            ->whereIn('status', [RmeInvoice::STATUS_UNPAID, RmeInvoice::STATUS_PARTIAL])
            ->where('id', '!=', $currentInvoice->id)
            ->where('grand_total', '>', 0)
            ->with(['clinicVisit', 'payments', 'items'])
            ->get()
            ->filter(fn (RmeInvoice $invoice) => $this->getCurrentInvoiceRemaining($invoice) > 0)
            // Deterministic FIFO: oldest visit_date first, then invoice id. A
            // zero-padded composite key keeps the lexicographic sort numeric.
            ->sortBy(fn (RmeInvoice $invoice) => sprintf(
                '%011d-%011d',
                $invoice->clinicVisit?->visit_date?->timestamp ?? 0,
                $invoice->id,
            ))
            ->values();
    }

    /**
     * Sprint 62.2 — payable summary for ANY visit, with cashier-driven selection.
     *
     * `selected_receivables` is always the server-side intersection of the
     * eligible discovery set and the submitted ids (the IDOR boundary): ids for
     * other patients, other branches, paid/zero-remaining invoices or the current
     * invoice can never appear and therefore can never be paid.
     *
     * @param  list<int|string>  $selectedReceivableInvoiceIds
     * @return array{
     *     outstanding_receivables: Collection<int, RmeInvoice>,
     *     selected_receivables: Collection<int, RmeInvoice>,
     *     selected_remaining: float,
     *     current_invoice: RmeInvoice,
     *     current_remaining: float,
     *     total_payable: float,
     *     has_outstanding: bool,
     * }
     */
    public function getVisitPayableSummary(RmeInvoice $currentInvoice, array $selectedReceivableInvoiceIds = []): array
    {
        $currentInvoice->loadMissing('clinicVisit');
        $visit = $currentInvoice->clinicVisit;

        if (! $visit) {
            throw ValidationException::withMessages([
                'rme_invoice_id' => 'Kunjungan klinik tidak ditemukan untuk invoice ini.',
            ]);
        }

        $outstanding = $this->getOutstandingReceivablesForPatientVisit($visit, $currentInvoice);

        $selectedIds = array_map('intval', $selectedReceivableInvoiceIds);
        $selected = $outstanding
            ->filter(fn (RmeInvoice $invoice) => in_array((int) $invoice->id, $selectedIds, true))
            ->values();

        $selectedRemaining = round(
            $selected->sum(fn (RmeInvoice $invoice) => $this->getCurrentInvoiceRemaining($invoice)),
            2,
        );
        $currentRemaining = $this->getCurrentInvoiceRemaining($currentInvoice);

        return [
            'outstanding_receivables' => $outstanding,
            'selected_receivables' => $selected,
            'selected_remaining' => $selectedRemaining,
            'current_invoice' => $currentInvoice,
            'current_remaining' => $currentRemaining,
            'total_payable' => round($selectedRemaining + $currentRemaining, 2),
            'has_outstanding' => $outstanding->isNotEmpty(),
        ];
    }

    /**
     * @return list<int>
     */
    private function collectAncestorVisitIds(ClinicVisit $visit): array
    {
        $ancestorIds = [];
        $seen = [$visit->id];
        $currentId = $visit->follow_up_of_visit_id;

        while ($currentId !== null) {
            if (in_array($currentId, $seen, true)) {
                break;
            }

            $current = ClinicVisit::query()->find($currentId);

            if (! $current) {
                break;
            }

            if ($current->patient_id !== $visit->patient_id) {
                break;
            }

            if (! in_array((int) $current->branch_id, $this->branches->rmeEnabledIds(), true)) {
                break;
            }

            $ancestorIds[] = $current->id;
            $seen[] = $current->id;
            $currentId = $current->follow_up_of_visit_id;
        }

        return array_reverse($ancestorIds);
    }

    private function isControlVisitWithParent(ClinicVisit $visit): bool
    {
        return $visit->isControlVisit() && $visit->follow_up_of_visit_id !== null;
    }

    private function assertControlInvoiceChain(RmeInvoice $controlInvoice, ClinicVisit $visit): void
    {
        if (! $visit->isControlVisit()) {
            return;
        }

        if (! in_array((int) $controlInvoice->branch_id, $this->branches->rmeEnabledIds(), true)) {
            throw ValidationException::withMessages([
                'rme_invoice_id' => 'Invoice tidak berada di cabang RME aktif.',
            ]);
        }

        if ($controlInvoice->patient_id !== $visit->patient_id) {
            throw ValidationException::withMessages([
                'rme_invoice_id' => 'Invoice kontrol tidak sesuai dengan pasien kunjungan.',
            ]);
        }

        foreach ($this->getCarryOverInvoicesForControlVisit($visit) as $parentInvoice) {
            if ($parentInvoice->patient_id !== $visit->patient_id) {
                throw ValidationException::withMessages([
                    'rme_invoice_id' => 'Piutang kunjungan sebelumnya tidak valid untuk pasien ini.',
                ]);
            }

            if (! in_array((int) $parentInvoice->branch_id, $this->branches->rmeEnabledIds(), true)) {
                throw ValidationException::withMessages([
                    'rme_invoice_id' => 'Piutang kunjungan sebelumnya tidak berada di cabang RME aktif.',
                ]);
            }
        }
    }
}
