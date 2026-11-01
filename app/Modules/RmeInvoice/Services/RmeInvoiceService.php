<?php

namespace App\Modules\RmeInvoice\Services;

use App\Models\User;
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
use App\Modules\RmeInvoice\Support\RmeZeroValueClosureFeatureGuard;
use App\Modules\RmeInvoice\Support\RmeZeroValueEligibility;
use App\Modules\RmeOnlineContext\Services\RmeWorkingBranchScope;
use App\Modules\Treatment\Models\Treatment;
use App\Support\Clinical\ClinicalClock;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class RmeInvoiceService
{
    public function __construct(
        private readonly RmeInvoiceRepositoryInterface $invoices,
        private readonly RmeInvoiceNumberGeneratorService $numberGenerator,
        private readonly BranchService $branches,
        private readonly CashierHandoffStatusService $handoffStatus,
        private readonly RmeWorkingBranchScope $workingBranchScope,
        private readonly AuditLogService $auditLogs,
        private readonly RmeInvoicePricingService $pricing,
        private readonly ClinicalClock $clinicalClock,
        private readonly RmeBillingActorGuard $actors,
        private readonly RmeZeroValueClosureFeatureGuard $zeroValueFlag,
    ) {}

    /**
     * FIX-CLINIC-OPS-BRANCH-CONTEXT-WA-1 (FIX-09) — the cashier financial
     * workspace reads only the branch the cashier is working in. Fails closed to
     * an empty scope without a valid context; governance roles keep the full set.
     *
     * FIX-RME-INVOICE-BRANCH-SCOPE-EXPLICIT-ACTOR-1 (rule 198) — judged on the
     * EXPLICIT actor the caller passes, never the ambient session. This read
     * Auth::user(): it judged whoever was logged in rather than the cashier the
     * caller meant, and with NO session RmeWorkingBranchScope::branchIdsFor(null)
     * answers EVERY active RME branch, so both cashier lists failed OPEN
     * (reproduced on the unmodified base). The actor must also be a usable
     * account holding the billing permission, decided on the persisted row; any
     * other actor gets an EMPTY scope — never the estate, never a fallback.
     *
     * @return array<int, int>
     */
    private function cashierScopeBranchIds(User $actor): array
    {
        $account = $this->actors->resolveBillingActor($actor);

        if ($account === null) {
            return [];
        }

        return $this->workingBranchScope->branchIdsFor($account);
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
    public function cashierHandoffQueue(User $actor, array $filters = []): array
    {
        $visits = $this->invoices->cashierHandoffQueueForBranches(
            $this->cashierScopeBranchIds($actor),
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
    public function paginatePendingVisits(User $actor, array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        return $this->invoices->paginateCashierPendingForBranches(
            $this->cashierScopeBranchIds($actor),
            $filters,
            $perPage,
        );
    }

    /**
     * The visit's existing invoice, disclosed only to an actor allowed in that
     * invoice's branch (rule 198). Judged on the invoice ROW, the record being
     * disclosed; an unusable or unauthorized actor gets null, exactly like a
     * visit with no invoice. The controller authorizes the visit first; this
     * keeps the service safe for any other caller.
     */
    public function findInvoiceForVisit(ClinicVisit $visit, User $actor): ?RmeInvoice
    {
        $invoice = $this->invoices->findForVisit($visit->id);

        if ($invoice === null) {
            return null;
        }

        return in_array((int) $invoice->branch_id, $this->cashierScopeBranchIds($actor), true)
            ? $invoice
            : null;
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

                // FIX-RME-INVOICE-BRANCH-SCOPE-EXPLICIT-ACTOR-1 (rule 198) — the
                // cashier the invoice is RECORDED under (`cashier_id`) must be a
                // real, usable account holding the billing permission, decided on
                // the persisted row inside the transaction. Before this an inactive
                // or deleted account, an unsaved object carrying a governance id, or
                // an active account without manage_rme_billing could all bill a
                // visit through any non-HTTP caller: the policy that refuses them
                // runs only in the controller. Every check below is then asked of
                // this persisted account, never of the object passed in.
                $account = $this->actors->resolveBillingActor($cashier);
                if ($account === null) {
                    throw new AuthorizationException('Akun kasir tidak aktif, tidak dikenali, atau tidak memiliki izin penagihan.');
                }
                $cashier = $account;

                // The invoice branch always follows the visit's own "Cabang RME"
                // branch — never a BranchContext/MAIN fallback. The visit must belong
                // to an active RME-enabled branch (Sprint 23 Phase 23.10).
                $branchId = (int) $visit->branch_id;

                if (! in_array($branchId, $this->branches->rmeEnabledIds(), true)) {
                    throw ValidationException::withMessages([
                        'clinic_visit_id' => 'Kunjungan tidak berada di cabang RME aktif.',
                    ]);
                }

                // REVISION-RME-MANUAL-PRICING-AUTHORITY-1 (security review F1) — the
                // cashier issues invoices only for the branch they work in, decided on
                // the LOCKED visit so no caller (HTTP, command, job) can bill another
                // branch's visit at a price it typed. Same canonical scope as view /
                // pay / receipt; a context-bound role with no working branch fails
                // closed, a whole-estate role is unaffected.
                if (! $this->workingBranchScope->allows($cashier, $branchId)) {
                    throw new AuthorizationException('Kunjungan berada di luar cabang kerja Anda.');
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

                // REVISION-RME-MANUAL-PRICING-AUTHORITY-1 (owner D21, revised) —
                // the cashier's typed price is the price, and every line is still
                // priced on the SERVER: integer cents, malformed money refused,
                // discount never above the line, overflow refused, a named
                // treatment active, MANUAL provenance recorded. No tariff is read.
                // Submitted totals are never read. Priced BEFORE an invoice number
                // is drawn, so a refused line never consumes one. Once created, the
                // amounts change only through the invoice correction workflow.
                $pricing = $this->pricing->price(
                    $branchId,
                    array_values($items),
                    $cashier,
                    $this->clinicalClock->todayString(),
                );

                $this->assertKontrolLinesIssuable($visit, $pricing['lines']);

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

    /**
     * HARDENING-RME-RP0-CLOSURE-OWNER-DECISIONS-AND-PAID-TREATMENT-1 (owner S6-Q3,
     * revised; S6-Q3b) — on a server-derived Kontrol a normally paid treatment is
     * billed at a positive price: a Rp0 line must be an approved free service and
     * never need lab work. Decided on the server-priced lines (never a submitted
     * total), BEFORE an invoice number is drawn, so a refused invoice consumes none.
     *
     * Gated on `rme.zero_value_closure`: with the flag OFF issuance is exactly what
     * production does today (D21 manual pricing unchanged). Scoped to Kontrol only —
     * Rp0 issuance on other visits stays D21g, PENDING.
     *
     * @param  list<array<string, mixed>>  $lines
     */
    private function assertKontrolLinesIssuable(ClinicVisit $visit, array $lines): void
    {
        if (! $this->zeroValueFlag->enabled() || ! RmeZeroValueEligibility::isControlVisit($visit)) {
            return;
        }

        $ids = collect($lines)
            ->filter(fn (array $line) => (int) $line['subtotal_cents'] === 0 && $line['treatment_id'] !== null)
            ->map(fn (array $line) => (int) $line['treatment_id'])
            ->unique()
            ->values()
            ->all();

        $treatments = $ids === []
            ? []
            : Treatment::withTrashed()
                ->with(['category' => fn ($category) => $category->withTrashed()])
                ->whereIn('id', $ids)
                ->get()
                ->keyBy('id')
                ->all();

        $refusal = RmeZeroValueEligibility::issuanceRefusal(
            $visit,
            array_map(fn (array $line) => [
                'treatment_id' => $line['treatment_id'] !== null ? (int) $line['treatment_id'] : null,
                'unit_price_cents' => (int) $line['unit_price_cents'],
                'discount_cents' => (int) $line['discount_cents'],
                'subtotal_cents' => (int) $line['subtotal_cents'],
            ], array_values($lines)),
            $treatments,
        );

        if ($refusal !== null) {
            throw ValidationException::withMessages(['items' => $refusal]);
        }
    }
}
