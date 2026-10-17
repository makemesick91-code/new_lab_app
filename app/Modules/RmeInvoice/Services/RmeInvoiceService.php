<?php

namespace App\Modules\RmeInvoice\Services;

use App\Models\User;
use App\Modules\Branch\Services\BranchContext;
use App\Modules\Branch\Services\BranchService;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\LabOrder\Services\AuditLogService;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\RmeInvoice\Interfaces\RmeInvoiceRepositoryInterface;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use App\Modules\RmeInvoice\Models\RmeInvoiceItem;
use App\Modules\RmeInvoice\Support\RmeFinancialAuditEvent;
use App\Modules\RmeInvoice\Support\RmeInvoicePricingRefusal;
use App\Modules\RmeInvoice\Support\RmeInvoiceSnapshot;
use App\Modules\RmeOnlineContext\Services\RmeWorkingBranchScope;
use App\Support\Clinical\ClinicalClock;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class RmeInvoiceService
{
    public function __construct(
        private readonly RmeInvoiceRepositoryInterface $invoices,
        private readonly BranchContext $branchContext,
        private readonly RmeInvoiceNumberGeneratorService $numberGenerator,
        private readonly BranchService $branches,
        private readonly CashierHandoffStatusService $handoffStatus,
        private readonly RmeWorkingBranchScope $workingBranchScope,
        private readonly AuditLogService $auditLogs,
        private readonly RmeInvoicePricingService $pricing,
        private readonly ClinicalClock $clinicalClock,
    ) {}

    /**
     * FIX-CLINIC-OPS-BRANCH-CONTEXT-WA-1 (FIX-09) — the cashier financial
     * workspace reads only the branch the cashier is working in. Fails closed to
     * an empty scope without a valid context; governance roles keep the full set.
     *
     * @return array<int, int>
     */
    private function cashierScopeBranchIds(): array
    {
        return $this->workingBranchScope->branchIdsFor(Auth::user());
    }

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
            $this->cashierScopeBranchIds(),
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
            $this->cashierScopeBranchIds(),
            $filters,
            $perPage,
        );
    }

    public function findInvoiceForVisit(ClinicVisit $visit): ?RmeInvoice
    {
        return $this->invoices->findForVisit($visit->id);
    }

    /**
     * FEATURE-RME-BRANCH-TARIFF-AUTHORITY-1 — the cashier form's pricing context
     * for a visit: the branch mode and the tariff in force per treatment, read
     * for the VISIT's branch on the clinical today. Display only.
     *
     * @param  list<int>  $treatmentIds
     * @return array{mode: string, tariffs: array<int, int>, exceptions_enabled: bool}
     */
    public function pricingContextFor(ClinicVisit $visit, array $treatmentIds): array
    {
        return $this->pricing->contextFor((int) $visit->branch_id, $treatmentIds, $this->clinicalClock->todayString());
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
        try {
            return DB::transaction(function () use ($visit, $cashier, $data) {
                // FEATURE-RME-BILLING-REFUND-1 / Phase 0 (audit BCF-08) — serialize
                // invoice creation per VISIT. The one-active-invoice check below was
                // a check-then-insert with nothing locked, so two concurrent submits
                // for the same visit could both observe "no active invoice" and both
                // insert. Locking the visit row makes the second request wait until
                // the first commits; it then re-reads under the lock and is refused.
                //
                // Every gate below reads the LOCKED row, never the instance the
                // caller passed in — a status read from a stale model is exactly the
                // race the lock exists to close.
                //
                // Lock strength is FOR NO KEY UPDATE, deliberately not FOR UPDATE.
                // Two creates for the same visit still conflict (NO KEY UPDATE vs NO
                // KEY UPDATE), so the race is closed. But FOR UPDATE would also
                // conflict with the FOR KEY SHARE that every foreign-key INSERT takes
                // on its parent row — and the medical-record diagnosis writer locks
                // the record then inserts against this visit, while this method locks
                // the visit then inserts an invoice against that record: with FOR
                // UPDATE the two can deadlock (40P01). NO KEY UPDATE does not conflict
                // with FOR KEY SHARE, so no FK insert anywhere is blocked by it.
                // SQLite compiles any lock clause to nothing.
                $visit = ClinicVisit::query()->lock('for no key update')->findOrFail($visit->id);

                // The invoice branch always follows the visit's own "Cabang RME"
                // branch — never a BranchContext/MAIN fallback. The visit must belong
                // to an active RME-enabled branch (Sprint 23 Phase 23.10).
                $branchId = (int) $visit->branch_id;

                if (! in_array($branchId, $this->branches->rmeEnabledIds(), true)) {
                    throw ValidationException::withMessages([
                        'clinic_visit_id' => 'Kunjungan tidak berada di cabang RME aktif.',
                    ]);
                }

                // Sprint 62.1 — Doctor → Cashier completion gate. The cashier can only
                // bill a visit once the doctor has completed the examination (the RME
                // finalize moves the visit to cashier_pending).
                if ($visit->status !== ClinicVisit::STATUS_CASHIER_PENDING) {
                    throw ValidationException::withMessages([
                        'clinic_visit_id' => 'Pembayaran belum dapat diproses karena pemeriksaan dokter belum selesai.',
                    ]);
                }

                // Sprint 20's billing rule is "Cashier billing requires finalized
                // RME + cashier_pending visit", and this is where it is enforced.
                //
                // FIX-SUNU-GO-LIVE-BLOCKERS-1 / B5 — this read `if ($medicalRecord
                // && ...)`, which gated a record that EXISTS but skipped the gate
                // entirely when there was none. A visit carrying no clinical record
                // at all was therefore billable, and the invoice was written with a
                // null medical_record_id: a charge with nothing clinical behind it.
                // Reaching `cashier_pending` does not by itself write a record, so
                // this was reachable through the ordinary workflow, not only by
                // misuse — SPN4 visit 52 sat in exactly that state.
                //
                // ABSENT IS NOT THE SAME AS VALID. Fail closed on null: a missing
                // record is a stronger reason to refuse than an unfinalized one.
                $medicalRecord = $visit->medicalRecord;
                if ($medicalRecord === null || $medicalRecord->status !== MedicalRecord::STATUS_FINAL) {
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

                // FEATURE-RME-BRANCH-TARIFF-AUTHORITY-1 (owner D21) — every line is
                // priced on the SERVER: integer cents, discount never above the
                // line, overflow refused, and its provenance recorded against the
                // branch tariff in force on the clinical date. A strict-mode branch
                // additionally refuses any price the tariff does not authorize.
                // Submitted totals are never read. Priced BEFORE an invoice number
                // is drawn, so a refused line never consumes one.
                $pricing = $this->pricing->price(
                    $branchId,
                    array_values($items),
                    $cashier,
                    $this->clinicalClock->todayString(),
                );

                $invoiceNumber = $this->numberGenerator->generate();

                $invoice = $this->invoices->create([
                    'branch_id' => $branchId,
                    'clinic_visit_id' => $visit->id,
                    'patient_id' => $visit->patient_id,
                    // Non-null by the guard above: an invoice can no longer exist
                    // without the finalized record it bills for.
                    'medical_record_id' => $medicalRecord->id,
                    'cashier_id' => $cashier->id,
                    'invoice_number' => $invoiceNumber,
                    'status' => RmeInvoice::STATUS_UNPAID,
                    'subtotal' => 0,
                    'discount_total' => 0,
                    'grand_total' => 0,
                    'notes' => $data['notes'] ?? null,
                ]);

                foreach ($pricing['lines'] as $line) {
                    RmeInvoiceItem::create([
                        'rme_invoice_id' => $invoice->id,
                        'treatment_id' => $line['treatment_id'],
                        'description' => $line['description'],
                        'qty' => $line['qty'],
                        'unit_price' => RmeInvoiceSnapshot::centsToDecimal($line['unit_price_cents']),
                        'discount' => RmeInvoiceSnapshot::centsToDecimal($line['discount_cents']),
                        'subtotal' => RmeInvoiceSnapshot::centsToDecimal($line['subtotal_cents']),
                        'doctor_id' => $line['doctor_id'],
                        'pricing_mode' => $line['pricing_mode'],
                        'price_source' => $line['price_source'],
                        'tariff_id' => $line['tariff_id'],
                        'tariff_unit_price' => $line['tariff_unit_price_cents'] !== null
                            ? RmeInvoiceSnapshot::centsToDecimal($line['tariff_unit_price_cents'])
                            : null,
                        'price_exception_reason' => $line['price_exception_reason'],
                        'price_exception_by' => $line['price_exception_by'],
                    ]);
                }

                $this->invoices->update($invoice, [
                    'subtotal' => RmeInvoiceSnapshot::centsToDecimal($pricing['subtotal_cents']),
                    'discount_total' => RmeInvoiceSnapshot::centsToDecimal($pricing['discount_cents']),
                    'grand_total' => RmeInvoiceSnapshot::centsToDecimal($pricing['grand_total_cents']),
                ]);

                // Phase 0 (audit BCF-02) — the invoice and its audit row commit or
                // roll back together. The repository's update() refreshes this same
                // instance, so the totals logged are the persisted ones.
                $this->auditLogs->log(
                    RmeFinancialAuditEvent::ENTITY_INVOICE,
                    (int) $invoice->id,
                    RmeFinancialAuditEvent::ACTION_INVOICE_CREATED,
                    null,
                    RmeFinancialAuditEvent::invoiceCreatedPayload($invoice, count($items), $pricing),
                    $cashier,
                );

                return $invoice->load('items.treatment');
            });
        } catch (RmeInvoicePricingRefusal $refusal) {
            // The transaction rolled back; the refusal records an ATTEMPT, so it
            // is audited now (outside it) and then surfaced as a validation error.
            $this->recordPricingRefusal($refusal, $visit, $cashier);

            throw ValidationException::withMessages(['items' => $refusal->operatorMessage()]);
        }
    }

    /**
     * FEATURE-RME-BRANCH-TARIFF-AUTHORITY-1 — the single writer of
     * RME_INVOICE_PRICING_REFUSED. Never masks the refusal: an audit failure is
     * logged (codes and ids only) and the caller still refuses.
     */
    private function recordPricingRefusal(RmeInvoicePricingRefusal $refusal, ClinicVisit $visit, User $cashier): void
    {
        try {
            $this->auditLogs->log(
                RmeFinancialAuditEvent::ENTITY_VISIT,
                (int) $visit->id,
                RmeFinancialAuditEvent::ACTION_INVOICE_PRICING_REFUSED,
                null,
                RmeFinancialAuditEvent::invoicePricingRefusedPayload($visit, $refusal),
                $cashier,
            );
        } catch (\Throwable $e) {
            Log::error('RME invoice pricing refusal could not be audited.', [
                'clinic_visit_id' => (int) $visit->id,
                'code' => $refusal->refusalCode,
                'exception' => $e::class,
            ]);
        }
    }
}
