<?php

declare(strict_types=1);

namespace App\Modules\RmeInvoice\Services;

use App\Models\User;
use App\Modules\Branch\Services\BranchService;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\LabOrder\Services\AuditLogService;
use App\Modules\RmeInvoice\Interfaces\RmeInvoiceCorrectionRepositoryInterface;
use App\Modules\RmeInvoice\Interfaces\RmeInvoiceRepositoryInterface;
use App\Modules\RmeInvoice\Interfaces\RmeOverpaymentObligationRepositoryInterface;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use App\Modules\RmeInvoice\Models\RmeInvoiceCorrection;
use App\Modules\RmeInvoice\Models\RmeInvoiceCorrectionLine;
use App\Modules\RmeInvoice\Models\RmeInvoiceDocumentVersion;
use App\Modules\RmeInvoice\Models\RmeInvoiceItem;
use App\Modules\RmeInvoice\Models\RmeOverpaymentObligation;
use App\Modules\RmeInvoice\Models\RmeReceiptSnapshot;
use App\Modules\RmeInvoice\Support\RmeFinancialAuditEvent;
use App\Modules\RmeInvoice\Support\RmeInvoiceCorrectionApprovalReference;
use App\Modules\RmeInvoice\Support\RmeInvoiceCorrectionDenied;
use App\Modules\RmeInvoice\Support\RmeInvoiceCorrectionFeatureGuard;
use App\Modules\RmeInvoice\Support\RmeInvoiceCorrectionPermission;
use App\Modules\RmeInvoice\Support\RmeInvoiceCorrectionPilotIdentity;
use App\Modules\RmeInvoice\Support\RmeInvoiceCorrectionPilotScope;
use App\Modules\RmeInvoice\Support\RmeInvoiceCorrectionStatus;
use App\Modules\RmeInvoice\Support\RmeInvoiceCorrectionType;
use App\Modules\RmeInvoice\Support\RmeInvoiceDocument;
use App\Modules\RmeInvoice\Support\RmeInvoiceLedger;
use App\Modules\RmeInvoice\Support\RmeInvoicePostPaymentCorrectionFeatureGuard;
use App\Modules\RmeInvoice\Support\RmeInvoicePriceSource;
use App\Modules\RmeInvoice\Support\RmeInvoiceSnapshot;
use App\Modules\RmeOnlineContext\Services\RmeWorkingBranchScope;
use App\Modules\Tariff\Support\TariffPricingMode;
use App\Modules\Treatment\Models\Treatment;
use App\Support\AccessControl\ActiveAccountResolver;
use Closure;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * FEATURE-RME-INVOICE-CORRECTION-RECEIPT-FOUNDATION-1 — the ONLY writer of RME
 * invoice corrections, and the only code path that may change an RME invoice's
 * items and totals after creation.
 *
 * INVARIANTS (each enforced here, not in a controller, so no caller can skip it):
 *  1. Feature flag `rme.invoice_correction` must be on for every mutation.
 *  2. The correction TYPE is derived by the server from the payment ledger under
 *     the invoice lock (any payment => POST_PAYMENT), never submitted, and is
 *     fixed at submission. A PRE_PAYMENT correction goes STALE if a payment
 *     lands. POST_PAYMENT (FEATURE-RME-POSTPAYMENT-INVOICE-CORRECTION-1, owner
 *     PP-1..PP-10) needs its own switch on top of the main flag, a case-specific
 *     owner approval reference, and re-derives the invoice status from its own
 *     ledger with the helper payments use; an overpaid result records an OPEN
 *     overpayment obligation. A payment row is never touched, by either type.
 *  3. Lock order is always INVOICE row (FOR UPDATE — the lock every payment path
 *     takes on the same row) then CORRECTION row. One order, no deadlock cycle;
 *     a payment and a correction on the same invoice serialize.
 *  4. Maker != checker, checked under the lock, for EVERY account including
 *     Super Admin (Gate::before lets it pass every policy, so a policy check
 *     alone would be no control at all).
 *  5. The checker approves a FINGERPRINT. Approval and application both
 *     re-verify it against the locked invoice; any difference makes the
 *     correction STALE (persisted, audited) and nothing is applied.
 *  6. Totals are computed server-side; a submitted figure is never stored.
 *  7. Application is atomic and idempotent: items + totals + version + frozen
 *     document versions + status + event + audit commit together, and a second
 *     apply of an APPLIED correction is a no-op.
 *  8. Every transition writes an append-only event and a PII-free audit row in
 *     the SAME transaction; a refused authorization attempt is audited after the
 *     rollback.
 *  9. ACTIVATION-RME-INVOICE-CORRECTION-PREPAYMENT-2 (rule 195) — the PILOT
 *     allowlist (RmeInvoiceCorrectionPilotScope): submit, approve and apply are
 *     refused for an invoice whose branch is not admitted, fail-closed, under
 *     the lock. Reject and cancel are not gated — they only de-escalate.
 * 10. Owner D1a — the CHECKER applies, automatically: approve() records the
 *     approval AND applies the approved fingerprint in ONE transaction under one
 *     lock pair. An APPROVED-but-unapplied correction never becomes visible to
 *     another transaction, so D10 can never strand an invoice behind an approval
 *     nobody applies. The account that requested a correction can neither
 *     approve nor apply it — on every path, Super Admin included.
 * 11. FIX-RME-INVOICE-CORRECTION-ZERO-ORIGINAL-GUARD-1 (rule 201) — a TEMPORARY
 *     FAIL-CLOSED boundary while the Rp0 policy (D21g) and the zombie-invoice
 *     question (D22) are PENDING: an invoice whose AUTHORITATIVE ORIGINAL total
 *     is Rp0 can never be corrected. Submit, approve (= apply) and the recovery
 *     apply refuse it under the invoice row lock, BEFORE any mutation; reject and
 *     cancel stay open because they only de-escalate. The original is persisted
 *     data — the live row at correction_version 0, the frozen v0 document above
 *     it — never a request field or the object a caller passes in.
 * 12. Rule 200 at this service — every mutation, cancel included, is decided on
 *     the PERSISTED, active, non-deleted account (ActiveAccountResolver), and the
 *     permission and branch scope are asked of THAT account, never of roles a
 *     stale in-memory object still carries. The account is re-read after the
 *     invoice lock is held: that read is the linearization point for a
 *     concurrent deactivation (rule 201 §29).
 */
class RmeInvoiceCorrectionService
{
    /**
     * One message for every reuse refusal, whichever layer refused: naming the
     * kind of decision that already holds the reference would tell a maker
     * something about another case.
     */
    private const OWNER_REFERENCE_REUSED_MESSAGE = '[CORRECTION_OWNER_APPROVAL_REFERENCE_REUSED] Referensi persetujuan Owner ini sudah dipakai untuk keputusan lain. Setiap kasus memerlukan referensi persetujuannya sendiri.';

    public const REASON_MIN = 10;

    public const REASON_MAX = 1000;

    private const IDEMPOTENCY_PATTERN = '/^[A-Za-z0-9-]{8,64}$/D';

    /** Rule 195 / D1a — the checker's approval applied the correction in the same transaction. */
    public const APPLICATION_AUTO_ON_APPROVAL = 'AUTO_ON_APPROVAL';

    /** Rule 195 — the legacy apply() path, for an APPROVED row that predates D1a. */
    public const APPLICATION_MANUAL_RECOVERY = 'MANUAL_RECOVERY';

    public function __construct(
        private readonly RmeInvoiceCorrectionRepositoryInterface $corrections,
        private readonly RmeInvoiceRepositoryInterface $invoices,
        private readonly RmeInvoiceCorrectionProposalBuilder $proposals,
        private readonly RmeInvoiceCorrectionFeatureGuard $guard,
        private readonly BranchService $branches,
        private readonly RmeWorkingBranchScope $workingBranchScope,
        private readonly AuditLogService $auditLogs,
        private readonly RmeInvoiceCorrectionPilotScope $pilot,
        private readonly RmeInvoiceCorrectionPilotIdentity $identity,
        private readonly ActiveAccountResolver $accounts,
        private readonly RmeInvoicePostPaymentCorrectionFeatureGuard $postPaymentGuard,
        private readonly RmeReceiptSnapshotService $receiptSnapshots,
        private readonly RmeOverpaymentObligationRepositoryInterface $obligations,
        private readonly RmeOwnerApprovalReferenceRegistry $ownerReferences,
    ) {}

    // ─── Reads ──────────────────────────────────────────────────────────────

    public function capabilityEnabled(): bool
    {
        return $this->guard->enabled();
    }

    /**
     * Rule 195 — the flag is ON *and* the branch is on the pilot allowlist. This
     * is what decides whether a correction can be STARTED for an invoice; it is
     * the same predicate the service asserts under the lock.
     */
    public function capabilityEnabledForBranch(int $branchId): bool
    {
        return $this->guard->enabled() && $this->pilot->admits($branchId);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function paginateFor(User $user, array $filters, int $perPage = 20): LengthAwarePaginator
    {
        return $this->corrections->paginateForBranches($this->workingBranchScope->branchIdsFor($user), $filters, $perPage);
    }

    /**
     * @return array<string, int>
     */
    public function statusCountsFor(User $user): array
    {
        return $this->corrections->countByStatusForBranches($this->workingBranchScope->branchIdsFor($user));
    }

    public function find(int $id): ?RmeInvoiceCorrection
    {
        return $this->corrections->find($id);
    }

    /** @return Collection<int, RmeInvoiceDocumentVersion> */
    public function documentVersionsFor(RmeInvoice $invoice): Collection
    {
        return $this->corrections->documentVersionsForInvoice((int) $invoice->id);
    }

    public function findDocumentVersion(int $id): ?RmeInvoiceDocumentVersion
    {
        return $this->corrections->findDocumentVersion($id);
    }

    /** @return Collection<int, Treatment> */
    public function treatmentOptions(): Collection
    {
        return $this->corrections->treatmentOptions();
    }

    /**
     * Active treatments PLUS every treatment the invoice's items already carry,
     * even if it has since been deactivated. Without the latter, the form's
     * select could not show an item's current value and would submit "no
     * treatment", silently turning an untouched line into a change.
     *
     * @return Collection<int, Treatment>
     */
    public function treatmentOptionsFor(RmeInvoice $invoice): Collection
    {
        $current = $invoice->items->pluck('treatment')->filter();

        return $this->corrections->treatmentOptions()
            ->concat($current)
            ->unique('id')
            ->sortBy('name')
            ->values();
    }

    /**
     * The proposal form starts as a copy of the current items (each carrying its
     * original_item_id), so an untouched row is a KEEP and the cashier edits only
     * what is wrong. Money is rendered as a plain decimal string.
     *
     * @return list<array<string, mixed>>
     */
    public function initialFormLines(RmeInvoice $invoice): array
    {
        return $invoice->items->sortBy('id')->map(fn (RmeInvoiceItem $item) => [
            'original_item_id' => (int) $item->id,
            'treatment_id' => $item->treatment_id !== null ? (int) $item->treatment_id : null,
            'doctor_id' => $item->doctor_id !== null ? (int) $item->doctor_id : null,
            'description' => (string) $item->description,
            'qty' => (int) $item->qty,
            'unit_price' => RmeInvoiceSnapshot::centsToDecimal(RmeInvoiceDocument::toCents($item->unit_price)),
            'discount' => RmeInvoiceSnapshot::centsToDecimal(RmeInvoiceDocument::toCents($item->discount)),
        ])->values()->all();
    }

    /**
     * The correction panel on the cashier invoice page. With the capability off
     * and the invoice never revised it runs NO query, so the page is exactly as
     * it was before this sprint.
     *
     * @return array{enabled: bool, pilot_admitted: bool, can_request: bool, eligible: bool, ineligible_reason: string|null, active: RmeInvoiceCorrection|null, history: Collection<int, RmeInvoiceCorrection>, versions: Collection<int, RmeInvoiceDocumentVersion>, correction_type: string|null, requires_owner_approval_reference: bool, obligations: Collection<int, RmeOverpaymentObligation>, receipts: Collection<int, RmeReceiptSnapshot>}
     */
    public function panelFor(RmeInvoice $invoice, ?User $user): array
    {
        $enabled = $this->guard->enabled();
        $empty = [
            'enabled' => $enabled,
            'pilot_admitted' => false,
            'can_request' => false,
            'eligible' => false,
            'ineligible_reason' => null,
            'active' => null,
            'history' => collect(),
            'versions' => collect(),
            'correction_type' => null,
            'requires_owner_approval_reference' => false,
            'obligations' => collect(),
            'receipts' => collect(),
        ];

        if (! $enabled && ! $invoice->isRevised()) {
            return $empty;
        }

        $history = $this->corrections->forInvoice((int) $invoice->id);
        $active = $history->first(fn (RmeInvoiceCorrection $c) => $c->isActive());

        $type = $this->typeFor($invoice);
        $ineligible = $this->ineligibility($invoice, $type, $active !== null);
        $pilotAdmitted = $enabled && $this->pilot->admits((int) $invoice->branch_id);

        return array_merge($empty, [
            'can_request' => $pilotAdmitted
                && $user !== null
                && $user->can(RmeInvoiceCorrectionPermission::REQUEST)
                && $this->workingBranchScope->allows($user, (int) $invoice->branch_id)
                // Rule 197 (owner D1e) — only the designated maker account.
                && $this->identity->allows(RmeInvoiceCorrectionPilotIdentity::ACTION_SUBMIT, $user),
            'eligible' => $pilotAdmitted && $ineligible === null,
            'pilot_admitted' => $pilotAdmitted,
            'ineligible_reason' => $ineligible,
            'active' => $active,
            'history' => $history,
            'versions' => $this->corrections->documentVersionsForInvoice((int) $invoice->id),
            'correction_type' => $type,
            'requires_owner_approval_reference' => $type === RmeInvoiceCorrectionType::POST_PAYMENT,
            'obligations' => $this->obligations->forInvoice((int) $invoice->id),
            // PP-5 — once the invoice is revised, every issued receipt stays
            // reachable exactly as issued.
            'receipts' => $invoice->isRevised() ? $invoice->receiptSnapshots()->get() : collect(),
        ]);
    }

    /**
     * Rule 197 — which decision controls the detail page may offer this account.
     * A courtesy for the screen only: every one is re-asserted by the service
     * under the lock. Gate::before answers `true` to any policy for a Super
     * Admin, so the identity allowlist is consulted here explicitly.
     *
     * @return array{approve: bool, reject: bool, apply: bool, cancel: bool, any: bool}
     */
    public function actionsFor(RmeInvoiceCorrection $correction, ?User $user): array
    {
        if ($user === null || ! $this->guard->enabled() || ! $correction->isActive()) {
            return ['approve' => false, 'reject' => false, 'apply' => false, 'cancel' => false, 'any' => false];
        }

        $pilotAdmitted = $this->pilot->admits((int) $correction->branch_id);
        $decides = ! $correction->isRequestedBy($user);

        $actions = [
            'approve' => $pilotAdmitted && $decides && $user->can('approve', $correction)
                && $this->identity->allows(RmeInvoiceCorrectionPilotIdentity::ACTION_APPROVE, $user),
            'reject' => $decides && $user->can('reject', $correction)
                && $this->identity->allows(RmeInvoiceCorrectionPilotIdentity::ACTION_REJECT, $user),
            'apply' => $pilotAdmitted && $decides && $correction->status === RmeInvoiceCorrectionStatus::APPROVED
                && $user->can('apply', $correction)
                && $this->identity->allows(RmeInvoiceCorrectionPilotIdentity::ACTION_APPLY, $user),
            // Cancel is the maker's own de-escalation (not identity-gated).
            'cancel' => $correction->isRequestedBy($user) && $user->can('cancel', $correction),
        ];

        return $actions + ['any' => in_array(true, $actions, true)];
    }

    /**
     * Rule 197 — may this account file a correction (the request form)? The
     * service asserts the same allowlist under the lock.
     */
    public function mayRequest(?User $user): bool
    {
        return $this->identity->allows(RmeInvoiceCorrectionPilotIdentity::ACTION_SUBMIT, $user);
    }

    // ─── Submit ─────────────────────────────────────────────────────────────

    /**
     * @param  array{reason?: mixed, lines?: mixed, idempotency_key?: mixed, owner_approval_reference?: mixed}  $data
     */
    public function submit(RmeInvoice $invoice, User $maker, array $data): RmeInvoiceCorrection
    {
        $this->guard->assertEnabled();

        $reason = $this->assertText($data['reason'] ?? null, 'reason', 'Alasan koreksi');
        // Format only; whether it is REQUIRED depends on the type, which is
        // decided under the lock below.
        $approvalReference = RmeInvoiceCorrectionApprovalReference::normalize($data['owner_approval_reference'] ?? null);
        $lines = $data['lines'] ?? null;
        if (! is_array($lines)) {
            throw ValidationException::withMessages(['lines' => '[CORRECTION_EMPTY] Daftar item koreksi wajib diisi.']);
        }

        $key = $data['idempotency_key'] ?? null;
        if ($key !== null && $key !== '') {
            if (! is_string($key) || preg_match(self::IDEMPOTENCY_PATTERN, $key) !== 1) {
                throw ValidationException::withMessages(['idempotency_key' => 'Kunci idempoten tidak valid.']);
            }

            $replay = $this->replayFor($key, $invoice, $maker);
            if ($replay !== null) {
                return $replay;
            }
        } else {
            $key = null;
        }

        return $this->audited('submit', $maker, null, (int) $invoice->id, function () use ($invoice, $maker, $reason, $lines, $key, $approvalReference) {
            try {
                return DB::transaction(function () use ($invoice, $maker, $reason, $lines, $key, $approvalReference) {
                    $locked = $this->invoices->findForUpdate((int) $invoice->id);
                    if ($locked === null) {
                        throw ValidationException::withMessages(['rme_invoice_id' => 'Tagihan tidak ditemukan.']);
                    }

                    $this->assertActor($maker, RmeInvoiceCorrectionPermission::REQUEST, (int) $locked->branch_id, null, (int) $locked->id);
                    $this->pilot->assertAdmitted((int) $locked->branch_id, null, (int) $locked->id);
                    // Rule 197 (owner D1e) — only the designated maker ACCOUNT,
                    // asserted here under the lock because Gate::before lets a
                    // Super Admin past every policy.
                    $this->identity->assertAllowed(RmeInvoiceCorrectionPilotIdentity::ACTION_SUBMIT, $maker, null, (int) $locked->id);
                    $this->assertRmeBranch((int) $locked->branch_id);

                    // Re-checked under the lock: a concurrent double-submit with
                    // the same key that committed while we waited is a replay,
                    // not a competing active correction.
                    if ($key !== null && ($replay = $this->replayFor($key, $locked, $maker)) !== null) {
                        return $replay;
                    }

                    // The TYPE is the ledger's, read under the lock — never the
                    // request's (rule 206 §B).
                    $type = $this->typeFor($locked);

                    $hasActive = $this->corrections->activeForInvoice((int) $locked->id) !== null;
                    $ineligible = $this->ineligibility($locked, $type, $hasActive);
                    if ($ineligible !== null) {
                        throw ValidationException::withMessages(['rme_invoice_id' => $ineligible]);
                    }

                    // PP-2 / PP-7 — a correction of money already received needs
                    // the owner's case-specific approval reference (rule 202).
                    if ($type === RmeInvoiceCorrectionType::POST_PAYMENT && $approvalReference === null) {
                        throw ValidationException::withMessages([
                            'owner_approval_reference' => '[CORRECTION_OWNER_APPROVAL_REFERENCE_REQUIRED] Koreksi tagihan yang sudah dibayar wajib mencantumkan referensi persetujuan Owner untuk kasus ini.',
                        ]);
                    }

                    // A reference is CASE-specific (rule 207 §10a): one recorded on a
                    // post-payment correction of another invoice cannot authorise this
                    // one, and on THIS invoice it may be repeated only by a
                    // resubmission after STALE/REJECT/CANCEL — never once a decision
                    // it authorised was approved and applied. Both checks run under
                    // the invoice lock taken above, so they cannot race on this
                    // invoice; the registry below backs the cross-invoice half.
                    if ($type === RmeInvoiceCorrectionType::POST_PAYMENT
                        && ($this->corrections->postPaymentReferenceUsedOnOtherInvoice($approvalReference, (int) $locked->id)
                            || $this->corrections->postPaymentReferenceSpentOnInvoice($approvalReference, (int) $locked->id))) {
                        throw ValidationException::withMessages([
                            'owner_approval_reference' => self::OWNER_REFERENCE_REUSED_MESSAGE,
                        ]);
                    }

                    // A pre-payment correction may carry an optional reference without
                    // consuming the registry (rule 214 D2). It still may not repeat one a
                    // registry-backed decision — a post-payment correction or a refund
                    // request (rule 215) — already spent.
                    if ($type === RmeInvoiceCorrectionType::PRE_PAYMENT
                        && $approvalReference !== null
                        && $this->ownerReferences->isClaimed($approvalReference)) {
                        throw ValidationException::withMessages([
                            'owner_approval_reference' => self::OWNER_REFERENCE_REUSED_MESSAGE,
                        ]);
                    }

                    $original = RmeInvoiceSnapshot::capture($locked);

                    // REVISION-RME-MANUAL-PRICING-AUTHORITY-1 (owner D21, revised):
                    // a corrected price is a MANUAL price. It does not have to
                    // equal — or even find — a branch tariff. The proposal
                    // builder is the price validation: integer cents, malformed
                    // money refused, discount within its line, overflow, an
                    // active treatment for a changed/added line, no doctor
                    // re-attribution, no new Rp0 line or total, totals
                    // recomputed on the server.
                    $proposal = $this->proposals->build($original, $lines);

                    $originalTotal = (int) $original['totals']['grand_total_cents'];
                    $proposedTotal = $proposal['grand_total_cents'];

                    $correction = $this->corrections->create([
                        'uuid' => (string) Str::uuid(),
                        'branch_id' => (int) $locked->branch_id,
                        'rme_invoice_id' => (int) $locked->id,
                        'clinic_visit_id' => (int) $locked->clinic_visit_id,
                        'patient_id' => (int) $locked->patient_id,
                        'correction_type' => $type,
                        'status' => RmeInvoiceCorrectionStatus::SUBMITTED,
                        'reason' => $reason,
                        'snapshot_schema_version' => RmeInvoiceSnapshot::SCHEMA_VERSION,
                        'invoice_version_at_request' => (int) ($locked->correction_version ?? 0),
                        'invoice_fingerprint' => RmeInvoiceSnapshot::fingerprint($original),
                        'original_snapshot' => $original,
                        'proposed_snapshot' => $this->proposedSnapshot($original, $proposal),
                        'original_grand_total' => RmeInvoiceSnapshot::centsToDecimal($originalTotal),
                        'proposed_grand_total' => RmeInvoiceSnapshot::centsToDecimal($proposedTotal),
                        'difference_amount' => RmeInvoiceSnapshot::centsToDecimal($proposedTotal - $originalTotal),
                        'paid_total_at_request' => RmeInvoiceSnapshot::centsToDecimal((int) $original['totals']['paid_cents']),
                        'requested_by' => (int) $maker->id,
                        'requested_at' => now(),
                        'idempotency_key' => $key,
                        'invoice_status_at_request' => (string) $locked->status,
                        'paid_payment_count_at_request' => count($original['payments']),
                        'owner_approval_reference' => $approvalReference,
                    ]);

                    $correction = $this->corrections->assignNumber(
                        $correction,
                        'RMECOR-'.now()->format('Ym').'-'.str_pad((string) $correction->id, 6, '0', STR_PAD_LEFT),
                    );

                    foreach ($proposal['lines'] as $line) {
                        $this->corrections->createLine(array_merge($line, ['rme_invoice_correction_id' => (int) $correction->id]));
                    }

                    $this->recordTransition($correction, null, $maker, [
                        'line_count' => count($proposal['lines']),
                    ]);

                    // HARDENING-RME-OWNER-REFERENCE-REGISTRY-1 (rule 214) — the
                    // database guarantee behind the check above: the registry
                    // key is shared across invoices and workflows, so exactly one
                    // decision scope ever owns the reference, also under
                    // concurrency. Last lock of the transaction; a refusal rolls
                    // the claim back with the correction.
                    if ($type === RmeInvoiceCorrectionType::POST_PAYMENT
                        && ! $this->ownerReferences->consume(
                            (string) $approvalReference,
                            RmeOwnerApprovalReferenceRegistry::SCOPE_POST_PAYMENT_CORRECTION_INVOICE,
                            (int) $locked->id,
                            $maker,
                        )) {
                        throw ValidationException::withMessages([
                            'owner_approval_reference' => self::OWNER_REFERENCE_REUSED_MESSAGE,
                        ]);
                    }

                    return $correction;
                });
            } catch (QueryException $e) {
                // The partial unique index is the database backstop for "one
                // active correction per invoice" (a racing submission that
                // slipped past the locked check is refused here, not stored).
                if ($this->isUniqueViolation($e) && $key !== null && str_contains($e->getMessage(), 'idempotency_key')) {
                    $replay = $this->replayFor($key, $invoice, $maker);
                    if ($replay !== null) {
                        return $replay;
                    }
                }

                if ($this->isUniqueViolation($e)) {
                    throw ValidationException::withMessages([
                        'rme_invoice_id' => '[CORRECTION_ALREADY_ACTIVE] Tagihan ini sudah memiliki pengajuan koreksi yang aktif.',
                    ]);
                }

                throw $e;
            }
        });
    }

    // ─── Decide ─────────────────────────────────────────────────────────────

    /**
     * Owner D1a (2026-10-21, rule 195): the CHECKER applies, automatically.
     *
     * Approval and application are ONE atomic act under the invoice → correction
     * lock pair: the SUBMITTED → APPROVED transition and the APPROVED → APPLIED
     * application (items, totals, correction_version, frozen v0/vN, events,
     * audit rows) commit together or not at all. If anything refuses the
     * application — staleness, a disabled correction type, a non-RME branch, a
     * total that would not reconcile — the approval is not recorded either and
     * the correction stays SUBMITTED (or goes STALE, which is persisted).
     *
     * The authority to apply here is the APPROVE permission the checker already
     * holds: under D1a approving IS applying. `apply_rme_invoice_correction`
     * gates only the legacy recovery path apply() below.
     */
    public function approve(RmeInvoiceCorrection $correction, User $checker, ?string $note = null): RmeInvoiceCorrection
    {
        $this->guard->assertEnabled();
        $note = $this->optionalText($note, 'decision_note', 'Catatan persetujuan');

        return $this->decide($correction, $checker, 'approve', function (RmeInvoiceCorrection $locked, ?RmeInvoice $invoice) use ($checker, $note) {
            // Rule 195 — the pilot allowlist, under the lock. Reject is NOT gated.
            $this->pilot->assertAdmitted((int) $locked->branch_id, (int) $locked->id, (int) $locked->rme_invoice_id);

            if (in_array($locked->status, [RmeInvoiceCorrectionStatus::APPROVED, RmeInvoiceCorrectionStatus::APPLIED], true)) {
                // Replay of an approval: idempotent no-op, never a second
                // application. (An APPROVED row can only predate D1a; it is
                // completed through the recovery path apply().)
                return [$locked, null];
            }

            $this->assertTransition($locked, RmeInvoiceCorrectionStatus::APPROVED);

            $this->assertTypeEnabled($locked);

            $this->assertRmeBranch((int) $locked->branch_id);

            $stale = $this->staleReason($invoice, $locked);
            if ($stale !== null) {
                return [$this->markStale($locked, $stale, $checker), $stale];
            }

            // Rule 201 — the invoice is still the document the checker reviewed
            // (staleReason() just proved it). If its original total is Rp0 — a
            // correction filed before this guard existed — it is refused BEFORE
            // anything is written: not APPROVED, not APPLIED. It stays SUBMITTED
            // so the checker can reject it or the maker cancel it (D10 release).
            $this->assertNotZeroOriginal($invoice);

            $from = $locked->status;
            $this->corrections->transition($locked, [
                'status' => RmeInvoiceCorrectionStatus::APPROVED,
                'decided_by' => (int) $checker->id,
                'decided_at' => now(),
                'decision_note' => $note,
            ]);
            $this->recordTransition($locked, $from, $checker, [
                'note_length' => mb_strlen((string) $note),
                'application_mode' => self::APPLICATION_AUTO_ON_APPROVAL,
            ]);

            /** @var RmeInvoice $invoice staleReason() returns a reason when the invoice is gone */
            return [$this->applyLocked($invoice, $locked, $checker, self::APPLICATION_AUTO_ON_APPROVAL), null];
        });
    }

    public function reject(RmeInvoiceCorrection $correction, User $checker, mixed $note): RmeInvoiceCorrection
    {
        $this->guard->assertEnabled();
        $note = $this->assertText($note, 'decision_note', 'Alasan penolakan');

        return $this->decide($correction, $checker, 'reject', function (RmeInvoiceCorrection $locked) use ($checker, $note) {
            if ($locked->status === RmeInvoiceCorrectionStatus::REJECTED) {
                return [$locked, null];
            }

            $this->assertTransition($locked, RmeInvoiceCorrectionStatus::REJECTED);

            $from = $locked->status;
            $this->corrections->transition($locked, [
                'status' => RmeInvoiceCorrectionStatus::REJECTED,
                'decided_by' => (int) $checker->id,
                'decided_at' => now(),
                'decision_note' => $note,
            ]);
            $this->recordTransition($locked, $from, $checker, ['note_length' => mb_strlen($note)]);

            return [$locked, null];
        });
    }

    /**
     * The maker withdraws their own SUBMITTED or APPROVED (not yet applied)
     * correction. Whether an approved correction may be withdrawn is owner
     * decision D16; the capability is inert until the flag is on.
     */
    public function cancel(RmeInvoiceCorrection $correction, User $maker, mixed $reason): RmeInvoiceCorrection
    {
        $this->guard->assertEnabled();
        $reason = $this->assertText($reason, 'cancel_reason', 'Alasan pembatalan');

        return $this->audited('cancel', $maker, (int) $correction->id, (int) $correction->rme_invoice_id, function () use ($correction, $maker, $reason) {
            return DB::transaction(function () use ($correction, $maker, $reason) {
                [$invoice, $locked] = $this->lockPair($correction);
                $this->assertActor($maker, RmeInvoiceCorrectionPermission::REQUEST, (int) $locked->branch_id, (int) $locked->id, (int) $locked->rme_invoice_id);

                if (! $locked->isRequestedBy($maker)) {
                    throw new RmeInvoiceCorrectionDenied(
                        RmeInvoiceCorrectionDenied::NOT_THE_MAKER,
                        'Hanya pengaju yang dapat membatalkan pengajuan koreksi ini.',
                        (int) $locked->id,
                        (int) $locked->rme_invoice_id,
                    );
                }

                if ($locked->status === RmeInvoiceCorrectionStatus::CANCELLED) {
                    return $locked;
                }

                $this->assertTransition($locked, RmeInvoiceCorrectionStatus::CANCELLED);

                $from = $locked->status;
                $this->corrections->transition($locked, [
                    'status' => RmeInvoiceCorrectionStatus::CANCELLED,
                    'cancelled_by' => (int) $maker->id,
                    'cancelled_at' => now(),
                    'cancel_reason' => $reason,
                ]);
                $this->recordTransition($locked, $from, $maker, ['note_length' => mb_strlen($reason)]);

                return $locked;
            });
        });
    }

    // ─── Apply ──────────────────────────────────────────────────────────────

    public function apply(RmeInvoiceCorrection $correction, User $actor): RmeInvoiceCorrection
    {
        $this->guard->assertEnabled();

        [$result, $stale] = $this->audited('apply', $actor, (int) $correction->id, (int) $correction->rme_invoice_id, function () use ($correction, $actor) {
            return DB::transaction(function () use ($correction, $actor) {
                [$invoice, $locked] = $this->lockPair($correction);
                $this->assertActor($actor, RmeInvoiceCorrectionPermission::APPLY, (int) $locked->branch_id, (int) $locked->id, (int) $locked->rme_invoice_id);
                $this->pilot->assertAdmitted((int) $locked->branch_id, (int) $locked->id, (int) $locked->rme_invoice_id);

                // Owner D1a — the CHECKER applies, never the maker. Under the
                // lock, per ACCOUNT, Super Admin included (Gate::before means a
                // policy could never be this guarantee).
                if ($locked->isRequestedBy($actor)) {
                    throw new RmeInvoiceCorrectionDenied(
                        RmeInvoiceCorrectionDenied::SELF_APPLICATION,
                        'Pengaju tidak boleh menerapkan koreksinya sendiri.',
                        (int) $locked->id,
                        (int) $locked->rme_invoice_id,
                    );
                }

                // Rule 197 — the recovery apply is the second half of an approval
                // (D1a), so it belongs to the designated checker account only.
                // This NARROWS the apply permission (held by every Super Admin)
                // to that one account; it grants nobody anything new.
                $this->identity->assertAllowed(RmeInvoiceCorrectionPilotIdentity::ACTION_APPLY, $actor, (int) $locked->id, (int) $locked->rme_invoice_id);

                if ($locked->status === RmeInvoiceCorrectionStatus::APPLIED) {
                    return [$locked, null]; // re-applying is a no-op, never a second application
                }

                if ($locked->status !== RmeInvoiceCorrectionStatus::APPROVED) {
                    throw ValidationException::withMessages([
                        'correction' => '[CORRECTION_NOT_APPROVED] Koreksi hanya dapat diterapkan setelah disetujui.',
                    ]);
                }

                // The recovery path exists only for a PRE_PAYMENT row approved
                // before D1a. A POST_PAYMENT correction is applied by its approval
                // in one transaction and can never be APPROVED-but-unapplied, so
                // reaching here with one is refused rather than completed.
                if ($locked->isPostPayment()) {
                    throw ValidationException::withMessages([
                        'correction' => '[CORRECTION_RECOVERY_NOT_SUPPORTED] Koreksi setelah pembayaran diterapkan bersama persetujuannya dan tidak melalui jalur pemulihan.',
                    ]);
                }

                if (! in_array($locked->correction_type, RmeInvoiceCorrectionType::ENABLED, true)) {
                    throw ValidationException::withMessages([
                        'correction' => '[CORRECTION_TYPE_NOT_ENABLED] Jenis koreksi ini belum diaktifkan.',
                    ]);
                }

                $this->assertRmeBranch((int) $locked->branch_id);

                $stale = $this->staleReason($invoice, $locked);
                if ($stale !== null) {
                    return [$this->markStale($locked, $stale, $actor), $stale];
                }

                // Rule 201 — the recovery apply of a pre-guard APPROVED row of a
                // Rp0 original (invoice unchanged since review) is refused before
                // mutation; it stays APPROVED and remains cancellable by its maker.
                $this->assertNotZeroOriginal($invoice);

                /** @var RmeInvoice $invoice */
                return [$this->applyLocked($invoice, $locked, $actor, self::APPLICATION_MANUAL_RECOVERY), null];
            });
        });

        if ($stale !== null) {
            $this->throwStale($stale);
        }

        return $result;
    }

    // ─── Internals ──────────────────────────────────────────────────────────

    private function applyLocked(RmeInvoice $invoice, RmeInvoiceCorrection $correction, User $actor, string $applicationMode): RmeInvoiceCorrection
    {
        $postPayment = $correction->isPostPayment();
        $before = RmeInvoiceSnapshot::capture($invoice);
        $statusBefore = (string) $invoice->status;
        $paidCentsBefore = (int) $before['totals']['paid_cents'];
        $paymentCountBefore = count($before['payments']);

        // PP-5 — every receipt already issued for this invoice is frozen BEFORE the
        // invoice changes. A payment recorded before receipt snapshots existed is
        // reconstructed now, from the verified pre-correction state (staleReason()
        // just proved it is the document the checker approved), and labelled so.
        $receiptsReconstructed = $postPayment ? $this->receiptSnapshots->reconstructMissing($invoice, $actor) : 0;

        $versionBefore = (int) ($invoice->correction_version ?? 0);
        $versionAfter = $versionBefore + 1;
        $latest = $this->corrections->latestDocumentVersion((int) $invoice->id);

        $originalVersion = null;
        if ($latest === null) {
            // First correction of this invoice: freeze the document exactly as
            // it stands — verified above to be the document the checker approved.
            $originalVersion = $this->freezeVersion($invoice, 0, RmeInvoiceDocumentVersion::KIND_ORIGINAL, $before, null, RmeInvoiceDocumentVersion::BASIS_PRE_FIRST_CORRECTION, $actor);
        }

        $latestMatched = $latest === null
            || RmeInvoiceSnapshot::fingerprint((array) $latest->snapshot) === RmeInvoiceSnapshot::fingerprint($before);

        /** @var array<int, RmeInvoiceItem> $items */
        $items = $invoice->items->keyBy('id')->all();
        $correction->loadMissing('lines');

        // REVISION-RME-MANUAL-PRICING-AUTHORITY-1 — a changed or added line
        // carries CORRECTION_MANUAL_ENTRY provenance; no tariff is read. A KEPT
        // line is untouched and keeps the provenance of its original entry.
        foreach ($correction->lines as $line) {
            match ($line->operation) {
                RmeInvoiceCorrectionLine::OP_KEEP => null,
                RmeInvoiceCorrectionLine::OP_REMOVE => $this->invoices->deleteItem($this->itemFor($items, $line)),
                RmeInvoiceCorrectionLine::OP_CHANGE => $this->invoices->updateItem($this->itemFor($items, $line), $this->changedItemValues($this->itemFor($items, $line), $line)),
                RmeInvoiceCorrectionLine::OP_ADD => $this->invoices->createItem(array_merge($this->itemValues($line), ['rme_invoice_id' => (int) $invoice->id])),
                default => throw new LogicException('Unknown correction line operation '.$line->operation),
            };
        }

        $invoice->load('items');
        $subtotal = 0;
        $discount = 0;
        foreach ($invoice->items as $item) {
            $subtotal += (int) $item->qty * RmeInvoiceDocument::toCents($item->unit_price);
            $discount += RmeInvoiceDocument::toCents($item->discount);
        }
        $grandTotal = $subtotal - $discount;

        // Belt and braces: what lands on the invoice must be exactly what the
        // checker approved. A mismatch rolls the whole application back.
        if ($grandTotal !== RmeInvoiceDocument::toCents($correction->proposed_grand_total)) {
            throw new LogicException(sprintf('Correction #%d applied total does not match the approved total.', (int) $correction->id));
        }

        $invoice = $this->invoices->applyCorrectionTotals($invoice, [
            'subtotal' => RmeInvoiceSnapshot::centsToDecimal($subtotal),
            'discount_total' => RmeInvoiceSnapshot::centsToDecimal($discount),
            'grand_total' => RmeInvoiceSnapshot::centsToDecimal($grandTotal),
            'correction_version' => $versionAfter,
        ]);

        // The ledger is read again, under the same lock: a correction never writes,
        // edits or deletes a payment, so the money received must be exactly what
        // it was before the items changed.
        $paidCents = RmeInvoiceLedger::paidCents($invoice);
        if ($paidCents !== $paidCentsBefore || $invoice->payments()->count() !== $paymentCountBefore) {
            throw new LogicException(sprintf('Correction #%d changed the payment ledger of its invoice.', (int) $correction->id));
        }

        $outstandingCents = RmeInvoiceLedger::outstandingCents($grandTotal, $paidCents);
        $overpaidCents = RmeInvoiceLedger::overpaidCents($grandTotal, $paidCents);
        $obligation = null;

        if (! $postPayment) {
            // Pre-payment: no payment exists (verified under this lock), so the
            // ledger-derived status is UNPAID and nothing about it changes.
            if ($invoice->status !== RmeInvoice::STATUS_UNPAID || $paidCents !== 0 || $paymentCountBefore !== 0) {
                throw new LogicException(sprintf('Correction #%d reached application on a paid invoice.', (int) $correction->id));
            }

            $statusAfter = RmeInvoice::STATUS_UNPAID;
        } else {
            if ($paidCents <= 0) {
                throw new LogicException(sprintf('Post-payment correction #%d reached application on an invoice with no money received.', (int) $correction->id));
            }

            // PP-3 / PP-4 — the status is re-derived from the invoice's own ledger
            // by the helper every payment uses: paid >= total => PAID, else PARTIAL.
            $statusAfter = RmeInvoiceLedger::statusAfterMoneyReceived($grandTotal, $paidCents);
            $invoice = $this->invoices->update($invoice, ['status' => $statusAfter]);

            if ($overpaidCents > 0) {
                // PP-3 option A — the difference is money owed back. It is
                // recorded, OPEN, in this transaction; no money moves.
                $obligation = $this->openObligation($invoice, $correction, $overpaidCents, $versionAfter, $actor);
            }
        }

        // Ledger invariants (FI-1..FI-4): never both outstanding and overpaid; a
        // balance owed back means the invoice is settled; a balance still due
        // means it is not; nothing is negative.
        if (($outstandingCents > 0 && $overpaidCents > 0)
            || ($overpaidCents > 0 && $statusAfter !== RmeInvoice::STATUS_PAID)
            || ($postPayment && $outstandingCents > 0 && $statusAfter !== RmeInvoice::STATUS_PARTIAL)
            || $outstandingCents < 0 || $overpaidCents < 0) {
            throw new LogicException(sprintf('Correction #%d would leave an inconsistent ledger.', (int) $correction->id));
        }

        $after = RmeInvoiceSnapshot::capture($invoice);
        $revision = $this->freezeVersion($invoice, $versionAfter, RmeInvoiceDocumentVersion::KIND_REVISION, $after, (int) $correction->id, RmeInvoiceDocumentVersion::BASIS_APPLIED_CORRECTION, $actor);

        $from = $correction->status;
        $this->corrections->transition($correction, [
            'status' => RmeInvoiceCorrectionStatus::APPLIED,
            'applied_by' => (int) $actor->id,
            'applied_at' => now(),
            'applied_invoice_version' => $versionAfter,
            'outstanding_after' => RmeInvoiceSnapshot::centsToDecimal($outstandingCents),
            'overpaid_after' => RmeInvoiceSnapshot::centsToDecimal($overpaidCents),
            'invoice_status_after' => $statusAfter,
        ]);

        $this->recordTransition($correction, $from, $actor, array_merge([
            'invoice_version_before' => $versionBefore,
            'invoice_version_after' => $versionAfter,
            'grand_total_before' => round((int) $before['totals']['grand_total_cents'] / 100, 2),
            'grand_total_after' => round($grandTotal / 100, 2),
            'document_version_original_id' => $originalVersion?->id !== null ? (int) $originalVersion->id : null,
            'document_version_revision_id' => (int) $revision->id,
            'latest_version_matched_live_state' => $latestMatched,
            'application_mode' => $applicationMode,
        ], $postPayment ? [
            'invoice_status_before' => $statusBefore,
            'invoice_status_after' => $statusAfter,
            'paid_total' => round($paidCents / 100, 2),
            'outstanding_after' => round($outstandingCents / 100, 2),
            'overpaid_after' => round($overpaidCents / 100, 2),
            'overpayment_obligation_id' => $obligation?->id !== null ? (int) $obligation->id : null,
            'receipt_snapshots_reconstructed' => $receiptsReconstructed,
            'owner_approval_reference' => $correction->owner_approval_reference,
        ] : []));

        return $correction;
    }

    /**
     * PP-3 option A — record, inside the application transaction, the money the
     * clinic now owes back. The amount is the server's (paid − new total); the
     * partial unique index is the database backstop for one OPEN obligation per
     * invoice, the service already refused a second at submission and approval.
     */
    private function openObligation(RmeInvoice $invoice, RmeInvoiceCorrection $correction, int $amountCents, int $invoiceVersion, User $actor): RmeOverpaymentObligation
    {
        if ($amountCents <= 0) {
            throw new LogicException('An overpayment obligation must be for a positive amount.');
        }

        $obligation = $this->obligations->create([
            'uuid' => (string) Str::uuid(),
            'branch_id' => (int) $invoice->branch_id,
            'rme_invoice_id' => (int) $invoice->id,
            'clinic_visit_id' => (int) $invoice->clinic_visit_id,
            'patient_id' => (int) $invoice->patient_id,
            'source_correction_id' => (int) $correction->id,
            'amount' => RmeInvoiceSnapshot::centsToDecimal($amountCents),
            'invoice_version' => $invoiceVersion,
            'status' => RmeOverpaymentObligation::STATUS_OPEN,
            'created_by' => (int) $actor->id,
        ]);

        $obligation = $this->obligations->assignNumber(
            $obligation,
            'RMEOVP-'.now()->format('Ym').'-'.str_pad((string) $obligation->id, 6, '0', STR_PAD_LEFT),
        );

        $this->obligations->recordEvent([
            'rme_overpayment_obligation_id' => (int) $obligation->id,
            'from_status' => null,
            'to_status' => RmeOverpaymentObligation::STATUS_OPEN,
            'actor_id' => (int) $actor->id,
            'occurred_at' => now(),
            'metadata' => [
                'source_correction_id' => (int) $correction->id,
                'amount' => round($amountCents / 100, 2),
                'invoice_version' => $invoiceVersion,
            ],
        ]);

        $this->auditLogs->log(
            RmeFinancialAuditEvent::ENTITY_OVERPAYMENT_OBLIGATION,
            (int) $obligation->id,
            RmeFinancialAuditEvent::ACTION_OVERPAYMENT_OBLIGATION_OPENED,
            null,
            RmeFinancialAuditEvent::overpaymentObligationPayload($obligation),
            $actor,
        );

        return $obligation;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private function freezeVersion(RmeInvoice $invoice, int $number, string $kind, array $snapshot, ?int $correctionId, string $basis, User $actor): RmeInvoiceDocumentVersion
    {
        $version = $this->corrections->createDocumentVersion([
            'uuid' => (string) Str::uuid(),
            'rme_invoice_id' => (int) $invoice->id,
            'branch_id' => (int) $invoice->branch_id,
            'version_number' => $number,
            'version_kind' => $kind,
            'document_reference' => $number === 0
                ? (string) $invoice->invoice_number
                : $invoice->invoice_number.'-REV'.$number,
            'source_correction_id' => $correctionId,
            'capture_basis' => $basis,
            'snapshot_schema_version' => RmeInvoiceSnapshot::SCHEMA_VERSION,
            'snapshot' => $snapshot,
            'snapshot_sha256' => RmeInvoiceSnapshot::documentHash($snapshot),
            'issued_by' => (int) $actor->id,
            'issued_at' => now(),
        ]);

        $this->auditLogs->log(
            RmeFinancialAuditEvent::ENTITY_DOCUMENT_VERSION,
            (int) $version->id,
            RmeFinancialAuditEvent::ACTION_DOCUMENT_VERSION_ISSUED,
            null,
            RmeFinancialAuditEvent::documentVersionPayload($version),
            $actor,
        );

        return $version;
    }

    /**
     * @param  Closure(RmeInvoiceCorrection, ?RmeInvoice): array{0: RmeInvoiceCorrection, 1: ?string}  $transition
     */
    private function decide(RmeInvoiceCorrection $correction, User $checker, string $action, Closure $transition): RmeInvoiceCorrection
    {
        // Resolved OUTSIDE the closures: a variable missing from a `use` clause
        // is silently null, which would select the wrong allowlist action.
        $identityAction = match ($action) {
            'approve' => RmeInvoiceCorrectionPilotIdentity::ACTION_APPROVE,
            'reject' => RmeInvoiceCorrectionPilotIdentity::ACTION_REJECT,
            // A future caller must choose its identity rule explicitly; it never
            // silently inherits the reject rule.
            default => throw new LogicException("Unknown correction decision action [{$action}]."),
        };

        [$result, $stale] = $this->audited($action, $checker, (int) $correction->id, (int) $correction->rme_invoice_id, function () use ($correction, $checker, $transition, $identityAction) {
            return DB::transaction(function () use ($correction, $checker, $transition, $identityAction) {
                [$invoice, $locked] = $this->lockPair($correction);
                $this->assertActor($checker, RmeInvoiceCorrectionPermission::APPROVE, (int) $locked->branch_id, (int) $locked->id, (int) $locked->rme_invoice_id);

                // Maker != checker — under the lock, for every account. The
                // policy repeats this as a courtesy; THIS is the control.
                if ($locked->isRequestedBy($checker)) {
                    throw new RmeInvoiceCorrectionDenied(
                        RmeInvoiceCorrectionDenied::SELF_APPROVAL,
                        'Pengaju tidak boleh memutuskan pengajuan koreksinya sendiri.',
                        (int) $locked->id,
                        (int) $locked->rme_invoice_id,
                    );
                }

                // Rule 197 (owner D1e) — approve (which applies, D1a) and reject
                // belong to the designated checker ACCOUNT only. Reject is not
                // branch-gated (rule 195 §17), but it IS identity-gated: the
                // maker's own cancel stays the ungated release path.
                $this->identity->assertAllowed(
                    $identityAction,
                    $checker,
                    (int) $locked->id,
                    (int) $locked->rme_invoice_id,
                );

                return $transition($locked, $invoice);
            });
        });

        if ($stale !== null) {
            $this->throwStale($stale);
        }

        return $result;
    }

    /**
     * Lock order: INVOICE first (the row every payment path locks), then the
     * correction. The invoice id is read from the immutable column of an
     * unlocked read; the correction row is then re-read under its own lock.
     *
     * @return array{0: RmeInvoice|null, 1: RmeInvoiceCorrection}
     */
    private function lockPair(RmeInvoiceCorrection $correction): array
    {
        $invoiceId = (int) $correction->rme_invoice_id;
        $invoice = $this->invoices->findForUpdate($invoiceId);
        $locked = $this->corrections->findForUpdate((int) $correction->id);

        if ($locked === null || (int) $locked->rme_invoice_id !== $invoiceId) {
            throw ValidationException::withMessages(['correction' => 'Pengajuan koreksi tidak ditemukan.']);
        }

        return [$invoice, $locked];
    }

    /**
     * Why the invoice is no longer the document the correction was reviewed
     * against, or null when it still is.
     */
    private function staleReason(?RmeInvoice $invoice, RmeInvoiceCorrection $correction): ?string
    {
        if ($invoice === null) {
            return 'INVOICE_REMOVED';
        }

        if ($correction->isPostPayment()) {
            return $this->postPaymentStaleReason($invoice, $correction);
        }

        if ($invoice->payments()->exists()) {
            return 'PAYMENT_RECORDED';
        }

        if ($invoice->status !== RmeInvoice::STATUS_UNPAID) {
            return 'INVOICE_STATUS_CHANGED';
        }

        if ((int) ($invoice->correction_version ?? 0) !== (int) $correction->invoice_version_at_request) {
            return 'INVOICE_VERSION_CHANGED';
        }

        if (! hash_equals((string) $correction->invoice_fingerprint, RmeInvoiceSnapshot::fingerprint(RmeInvoiceSnapshot::capture($invoice)))) {
            return 'INVOICE_CONTENT_CHANGED';
        }

        if ($this->corrections->invoiceHasLabCandidates((int) $invoice->id)) {
            return 'LAB_CANDIDATES_EXIST';
        }

        // REVISION-RME-MANUAL-PRICING-AUTHORITY-1 — the optional tariff master is
        // NOT a staleness reason: a correction approved at a manual price stays
        // valid when a tariff is edited, deactivated or deleted. STALE now means
        // only a genuine conflict with the INVOICE itself (the checks above).
        // Historical rows may still carry stale_reason_code TARIFF_AUTHORITY_*
        // from the tariff-authority period; nothing rewrites them.

        return null;
    }

    /**
     * POST_PAYMENT staleness. The fingerprint already covers status, version,
     * items, payments and totals; the named checks before it exist so the STALE
     * reason tells the cashier WHAT changed. An OPEN obligation (PP-3: no further
     * correction while one is open) is read FOR UPDATE under the invoice lock.
     */
    private function postPaymentStaleReason(RmeInvoice $invoice, RmeInvoiceCorrection $correction): ?string
    {
        if ($invoice->payments()->count() !== (int) $correction->paid_payment_count_at_request) {
            return 'PAYMENT_RECORDED';
        }

        if ((string) $invoice->status !== (string) $correction->invoice_status_at_request) {
            return 'INVOICE_STATUS_CHANGED';
        }

        if ((int) ($invoice->correction_version ?? 0) !== (int) $correction->invoice_version_at_request) {
            return 'INVOICE_VERSION_CHANGED';
        }

        if (! hash_equals((string) $correction->invoice_fingerprint, RmeInvoiceSnapshot::fingerprint(RmeInvoiceSnapshot::capture($invoice)))) {
            return 'INVOICE_CONTENT_CHANGED';
        }

        if ($this->corrections->invoiceHasLabCandidates((int) $invoice->id)) {
            return 'LAB_CANDIDATES_EXIST';
        }

        // The visit is not part of the invoice fingerprint: re-read it, so a visit
        // cancelled (or no longer completed) since submission is never corrected.
        if ($invoice->clinicVisit()->value('status') !== ClinicVisit::STATUS_COMPLETED) {
            return 'VISIT_STATUS_CHANGED';
        }

        if ($this->obligations->openForInvoice((int) $invoice->id, lock: true) !== null) {
            return 'OVERPAYMENT_OBLIGATION_OPEN';
        }

        return null;
    }

    private function markStale(RmeInvoiceCorrection $correction, string $reason, User $actor): RmeInvoiceCorrection
    {
        $from = $correction->status;
        $this->corrections->transition($correction, [
            'status' => RmeInvoiceCorrectionStatus::STALE,
            'stale_at' => now(),
            'stale_reason_code' => $reason,
        ]);
        $this->recordTransition($correction, $from, $actor, ['code' => $reason]);

        return $correction;
    }

    private function throwStale(string $reason): never
    {
        throw ValidationException::withMessages([
            'correction' => "[CORRECTION_STALE:{$reason}] Tagihan telah berubah sejak koreksi diajukan, sehingga koreksi ditandai kedaluwarsa dan tidak diterapkan. Ajukan koreksi baru terhadap tagihan terkini.",
        ]);
    }

    /**
     * Why an invoice cannot receive a PRE-PAYMENT correction now, or null.
     */
    /**
     * A replayed submission (double click, retried request) returns the
     * correction it already created — never a second one. A key already used
     * for another invoice or by another account is refused.
     */
    private function replayFor(string $key, RmeInvoice $invoice, User $maker): ?RmeInvoiceCorrection
    {
        $existing = $this->corrections->findByIdempotencyKey($key);
        if ($existing === null) {
            return null;
        }

        if ((int) $existing->rme_invoice_id === (int) $invoice->id && $existing->isRequestedBy($maker)) {
            return $existing;
        }

        throw ValidationException::withMessages(['idempotency_key' => 'Kunci idempoten sudah dipakai untuk pengajuan lain.']);
    }

    /**
     * The correction type the ledger implies, read from persisted payment rows:
     * any payment => POST_PAYMENT. Call it on the locked row when deciding.
     */
    private function typeFor(RmeInvoice $invoice): string
    {
        return $invoice->payments()->exists()
            ? RmeInvoiceCorrectionType::POST_PAYMENT
            : RmeInvoiceCorrectionType::PRE_PAYMENT;
    }

    private function ineligibility(RmeInvoice $invoice, string $type, bool $hasActiveCorrection): ?string
    {
        return $type === RmeInvoiceCorrectionType::POST_PAYMENT
            ? $this->postPaymentIneligibility($invoice, $hasActiveCorrection)
            : $this->prePaymentIneligibility($invoice, $hasActiveCorrection);
    }

    /**
     * Rule 206 — a correction type is accepted only when its own switch says so.
     * PRE_PAYMENT: RmeInvoiceCorrectionType::ENABLED (unchanged). POST_PAYMENT: the
     * separate flag `rme.invoice_postpayment_correction` (PP-1 / PP-10) — never by
     * adding the type to ENABLED. A POST_PAYMENT row also needs its approval
     * reference (defence behind the submission check and the database CHECK).
     */
    private function assertTypeEnabled(RmeInvoiceCorrection $correction): void
    {
        $enabled = $correction->isPostPayment()
            ? $this->postPaymentGuard->enabled()
            : in_array($correction->correction_type, RmeInvoiceCorrectionType::ENABLED, true);

        if (! $enabled) {
            throw ValidationException::withMessages([
                'correction' => '[CORRECTION_TYPE_NOT_ENABLED] Jenis koreksi ini belum diaktifkan.',
            ]);
        }

        if ($correction->isPostPayment() && trim((string) $correction->owner_approval_reference) === '') {
            throw ValidationException::withMessages([
                'correction' => '[CORRECTION_OWNER_APPROVAL_REFERENCE_REQUIRED] Koreksi tagihan yang sudah dibayar wajib mencantumkan referensi persetujuan Owner untuk kasus ini.',
            ]);
        }

        // HARDENING-RME-OWNER-REFERENCE-REGISTRY-1 — defence in depth: the
        // reference this approval relies on must be claimed by the registry for
        // THIS invoice. Read under the invoice + correction locks.
        if ($correction->isPostPayment() && ! $this->ownerReferences->belongsTo(
            trim((string) $correction->owner_approval_reference),
            RmeOwnerApprovalReferenceRegistry::SCOPE_POST_PAYMENT_CORRECTION_INVOICE,
            (int) $correction->rme_invoice_id,
        )) {
            throw ValidationException::withMessages([
                'correction' => '[CORRECTION_OWNER_APPROVAL_REFERENCE_INVALID] Referensi persetujuan Owner koreksi ini tidak tercatat untuk tagihan ini.',
            ]);
        }
    }

    /**
     * Why an invoice that has received money cannot receive a POST_PAYMENT
     * correction now, or null (owner decisions PP-1..PP-9).
     */
    private function postPaymentIneligibility(RmeInvoice $invoice, bool $hasActiveCorrection): ?string
    {
        if (! $this->postPaymentGuard->enabled()) {
            return '[CORRECTION_TYPE_NOT_ENABLED] Tagihan ini sudah memiliki pembayaran. Koreksi setelah pembayaran belum diaktifkan.';
        }

        if (! in_array($invoice->status, [RmeInvoice::STATUS_PARTIAL, RmeInvoice::STATUS_PAID], true)) {
            return '[CORRECTION_INVOICE_STATUS_NOT_SETTLED] Koreksi setelah pembayaran hanya untuk tagihan berstatus Dibayar Sebagian atau Lunas.';
        }

        // PP-3 — while money is owed back on this invoice, no further correction.
        if ($this->obligations->openForInvoice((int) $invoice->id) !== null) {
            return '[CORRECTION_OVERPAYMENT_OBLIGATION_OPEN] Tagihan ini memiliki kewajiban kelebihan bayar yang belum diselesaikan; koreksi berikutnya ditolak sampai kewajiban itu selesai.';
        }

        $grandCents = RmeInvoiceLedger::grandTotalCents($invoice);
        $paidCents = RmeInvoiceLedger::paidCents($invoice);

        // Fail closed on a ledger that does not explain its own status: a
        // correction is reviewed against figures, and figures that disagree with
        // the status cannot be reviewed.
        if ($paidCents <= 0
            || $grandCents < 0
            || $paidCents > $grandCents
            || RmeInvoiceLedger::statusAfterMoneyReceived($grandCents, $paidCents) !== $invoice->status) {
            return '[CORRECTION_LEDGER_INCONSISTENT] Status tagihan ini tidak sesuai dengan pembayaran yang tercatat, sehingga koreksi ditolak. Laporkan ke Owner.';
        }

        // D28 / rule 201 — the zero-original guard, read from persisted data.
        $zero = $this->zeroOriginalIneligibility($invoice);
        if ($zero !== null) {
            return $zero;
        }

        $visitStatus = $invoice->clinicVisit?->status;

        if ($visitStatus === ClinicVisit::STATUS_CANCELLED) {
            return '[CORRECTION_VISIT_CANCELLED] Kunjungan tagihan ini dibatalkan.';
        }

        // Only a COMPLETED visit. A Kontrol visit waits at cashier_pending while its
        // own invoice is PARTIAL, and only the payment path completes it: a
        // correction that settled that invoice would leave the visit pending with no
        // path out (a PAID invoice refuses payment). Refusing is the conservative
        // choice — completing a visit from a correction is not an approved decision.
        // A missing visit fails closed.
        if ($visitStatus !== ClinicVisit::STATUS_COMPLETED) {
            return '[CORRECTION_VISIT_NOT_COMPLETED] Kunjungan tagihan ini belum selesai di kasir; koreksi setelah pembayaran hanya untuk kunjungan yang sudah selesai.';
        }

        // PP-8 (a) — lab work already generated from this invoice: refused.
        if ($this->corrections->invoiceHasLabCandidates((int) $invoice->id)) {
            return '[CORRECTION_LAB_CANDIDATES_EXIST] Tagihan ini sudah menghasilkan kandidat lab; koreksi setelah pembayaran ditolak (keputusan owner PP-8).';
        }

        if ($hasActiveCorrection) {
            return '[CORRECTION_ALREADY_ACTIVE] Tagihan ini sudah memiliki pengajuan koreksi yang aktif.';
        }

        return null;
    }

    private function prePaymentIneligibility(RmeInvoice $invoice, bool $hasActiveCorrection): ?string
    {
        if ($invoice->payments()->exists()) {
            return '[CORRECTION_TYPE_NOT_ENABLED] Tagihan ini sudah memiliki pembayaran. Koreksi setelah pembayaran belum diaktifkan (menunggu keputusan owner).';
        }

        if ($invoice->status !== RmeInvoice::STATUS_UNPAID) {
            return '[CORRECTION_INVOICE_NOT_UNPAID] Koreksi sebelum pembayaran hanya untuk tagihan berstatus Belum Dibayar.';
        }

        // Invoice creation never enforced discount <= gross, so a negative total
        // can exist. Its correction is a policy question (D21/D22), not something
        // to push into a table whose CHECK refuses it with an opaque error.
        if (RmeInvoiceDocument::toCents($invoice->grand_total) < 0) {
            return '[CORRECTION_INVOICE_TOTAL_NEGATIVE] Total tagihan ini negatif; koreksinya menunggu keputusan owner.';
        }

        // Rule 201 — the zero-original guard, read from persisted data.
        $zero = $this->zeroOriginalIneligibility($invoice);
        if ($zero !== null) {
            return $zero;
        }

        if ($invoice->clinicVisit?->status === ClinicVisit::STATUS_CANCELLED) {
            return '[CORRECTION_VISIT_CANCELLED] Kunjungan tagihan ini dibatalkan.';
        }

        if ($this->corrections->invoiceHasLabCandidates((int) $invoice->id)) {
            return '[CORRECTION_LAB_CANDIDATES_EXIST] Tagihan ini sudah menghasilkan kandidat lab; koreksinya menunggu keputusan owner (D23).';
        }

        if ($hasActiveCorrection) {
            return '[CORRECTION_ALREADY_ACTIVE] Tagihan ini sudah memiliki pengajuan koreksi yang aktif.';
        }

        return null;
    }

    /**
     * FIX-RME-INVOICE-CORRECTION-ZERO-ORIGINAL-GUARD-1 (rule 201) — why an
     * invoice's ORIGINAL total forbids every correction, or null.
     *
     * TEMPORARY FAIL-CLOSED BOUNDARY, not a Rp0 policy: D21g (intrinsically free
     * treatments / Rp0 consultation and control) and D22 (zombie Rp0 invoices)
     * are PENDING owner decisions. Until one of them decides otherwise, a
     * correction may not create money out of a Rp0 document. Issuing a Rp0
     * invoice is untouched — this is about correction only.
     *
     * Refused when the authoritative original total is Rp0, when the CURRENT
     * total is Rp0 (the same outcome reached from a later version), and when the
     * original cannot be proven at all.
     */
    private function zeroOriginalIneligibility(RmeInvoice $invoice): ?string
    {
        $original = $this->originalTotalCents($invoice);

        if ($original === null) {
            return '[CORRECTION_ORIGINAL_TOTAL_UNVERIFIABLE] Total asli tagihan ini tidak dapat dibuktikan dari versi dokumen aslinya, sehingga koreksi ditolak.';
        }

        if ($original < 0) {
            return '[CORRECTION_INVOICE_TOTAL_NEGATIVE] Total asli tagihan ini negatif; koreksinya menunggu keputusan owner.';
        }

        if ($original === 0 || RmeInvoiceDocument::toCents($invoice->grand_total) === 0) {
            return '[CORRECTION_ORIGINAL_TOTAL_ZERO] Total asli tagihan ini Rp0. Koreksi tagihan Rp0 belum diizinkan — kebijakan Rp0 (D21g) dan tagihan Rp0 tertunda (D22) masih menunggu keputusan owner.';
        }

        return null;
    }

    /**
     * The ORIGINAL (v0) grand total in cents, from persisted data only, or null
     * when it cannot be proven:
     *  - correction_version 0: the invoice was never corrected, so the live row
     *    IS v0 (read by the caller under its FOR UPDATE lock);
     *  - correction_version > 0: v0 was frozen as the ORIGINAL document version
     *    by the first application; its snapshot is the authority;
     *  - correction_version > 0 with no frozen v0, a v0 whose snapshot no longer
     *    hashes to the value recorded when it was frozen (an out-of-band edit),
     *    or a snapshot without an integer total: unknowable, and the caller
     *    fails closed.
     */
    private function originalTotalCents(RmeInvoice $invoice): ?int
    {
        if ((int) ($invoice->correction_version ?? 0) === 0) {
            return RmeInvoiceDocument::toCents($invoice->grand_total);
        }

        $v0 = $this->corrections->originalDocumentVersion((int) $invoice->id);
        if ($v0 === null || ! $v0->integrityHolds()) {
            return null;
        }

        $cents = $v0->snapshot['totals']['grand_total_cents'] ?? null;

        return is_int($cents) ? $cents : null;
    }

    /**
     * Rule 201 at approve and at the recovery apply, inside the transaction that
     * holds the invoice → correction locks and before any write. A removed
     * invoice is left to staleReason() (INVOICE_REMOVED), unchanged.
     */
    private function assertNotZeroOriginal(?RmeInvoice $invoice): void
    {
        if ($invoice === null) {
            return;
        }

        $zero = $this->zeroOriginalIneligibility($invoice);
        if ($zero !== null) {
            throw ValidationException::withMessages(['correction' => $zero]);
        }
    }

    /**
     * Usability + permission + branch scope, asserted in the SERVICE so a
     * command, a job or a future caller cannot reach a transition without them.
     *
     * Rule 201 / rule 200 — all three are decided on the PERSISTED account,
     * re-read here (after the invoice lock is held): an inactive, deleted or
     * unsaved account is refused, and the permission is never answered by roles
     * a stale in-memory object still carries.
     */
    private function assertActor(User $actor, string $permission, int $branchId, ?int $correctionId, ?int $invoiceId): void
    {
        $account = $this->accounts->resolveUsable($actor);

        if ($account === null) {
            throw new RmeInvoiceCorrectionDenied(
                RmeInvoiceCorrectionDenied::ACTOR_UNUSABLE,
                'Akun ini tidak aktif atau tidak dikenali, sehingga tidak dapat menjalankan tindakan koreksi tagihan.',
                $correctionId,
                $invoiceId,
            );
        }

        if (! $account->can($permission)) {
            throw new RmeInvoiceCorrectionDenied(
                RmeInvoiceCorrectionDenied::PERMISSION_MISSING,
                'Anda tidak memiliki izin untuk tindakan koreksi ini.',
                $correctionId,
                $invoiceId,
            );
        }

        if (! $this->workingBranchScope->allows($account, $branchId)) {
            throw new RmeInvoiceCorrectionDenied(
                RmeInvoiceCorrectionDenied::BRANCH_OUT_OF_SCOPE,
                'Tagihan ini berada di luar cabang kerja Anda.',
                $correctionId,
                $invoiceId,
            );
        }
    }

    private function assertRmeBranch(int $branchId): void
    {
        if (! in_array($branchId, $this->branches->rmeEnabledIds(), true)) {
            throw ValidationException::withMessages(['rme_invoice_id' => '[CORRECTION_BRANCH_NOT_RME] Tagihan tidak berada di cabang RME aktif.']);
        }
    }

    private function assertTransition(RmeInvoiceCorrection $correction, string $to): void
    {
        if (! RmeInvoiceCorrectionStatus::canTransition((string) $correction->status, $to)) {
            throw ValidationException::withMessages([
                'correction' => sprintf(
                    '[CORRECTION_STATE_INVALID] Pengajuan berstatus %s tidak dapat diubah menjadi %s.',
                    RmeInvoiceCorrectionStatus::label((string) $correction->status),
                    RmeInvoiceCorrectionStatus::label($to),
                ),
            ]);
        }
    }

    /**
     * @param  array<string, int|float|string|bool|null>  $extra
     */
    private function recordTransition(RmeInvoiceCorrection $correction, ?string $from, User $actor, array $extra = []): void
    {
        $payload = RmeFinancialAuditEvent::correctionPayload($correction, $from, $extra);

        $this->corrections->recordEvent([
            'rme_invoice_correction_id' => (int) $correction->id,
            'from_status' => $from,
            'to_status' => (string) $correction->status,
            'actor_id' => (int) $actor->id,
            'occurred_at' => now(),
            'metadata' => $payload,
        ]);

        $this->auditLogs->log(
            RmeFinancialAuditEvent::ENTITY_CORRECTION,
            (int) $correction->id,
            RmeFinancialAuditEvent::CORRECTION_ACTIONS[(string) $correction->status],
            $from !== null ? ['status' => $from] : null,
            $payload,
            $actor,
        );
    }

    /**
     * Runs a mutation and, if it was refused for an AUTHORIZATION reason,
     * records the attempt after the transaction rolled back, then rethrows.
     *
     * @template T
     *
     * @param  Closure(): T  $work
     * @return T
     */
    private function audited(string $action, User $actor, ?int $correctionId, ?int $invoiceId, Closure $work): mixed
    {
        try {
            return $work();
        } catch (RmeInvoiceCorrectionDenied $denied) {
            $this->auditLogs->log(
                RmeFinancialAuditEvent::ENTITY_CORRECTION,
                $denied->correctionId ?? $correctionId,
                RmeFinancialAuditEvent::ACTION_CORRECTION_REFUSED,
                null,
                [
                    'attempted_action' => $action,
                    'code' => $denied->reasonCode,
                    'correction_id' => $denied->correctionId ?? $correctionId,
                    'rme_invoice_id' => $denied->invoiceId ?? $invoiceId,
                ],
                $actor,
            );

            throw $denied;
        }
    }

    private function assertText(mixed $value, string $field, string $label): string
    {
        $text = is_string($value) ? trim($value) : '';
        $length = mb_strlen($text);

        if ($length < self::REASON_MIN || $length > self::REASON_MAX) {
            throw ValidationException::withMessages([
                $field => "{$label} wajib diisi ".self::REASON_MIN.'–'.self::REASON_MAX.' karakter.',
            ]);
        }

        return $text;
    }

    private function optionalText(mixed $value, string $field, string $label): ?string
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }

        if (! is_string($value) || mb_strlen(trim($value)) > self::REASON_MAX) {
            throw ValidationException::withMessages([$field => "{$label} maksimal ".self::REASON_MAX.' karakter.']);
        }

        return trim($value);
    }

    /**
     * @param  array<string, mixed>  $original
     * @param  array<string, mixed>  $proposal
     * @return array<string, mixed>
     */
    private function proposedSnapshot(array $original, array $proposal): array
    {
        return [
            'schema_version' => RmeInvoiceSnapshot::SCHEMA_VERSION,
            'basis' => 'PROPOSAL',
            'invoice' => $original['invoice'],
            'items' => $proposal['items'],
            'totals' => [
                'subtotal_cents' => $proposal['subtotal_cents'],
                'discount_total_cents' => $proposal['discount_total_cents'],
                'grand_total_cents' => $proposal['grand_total_cents'],
                'paid_cents' => (int) $original['totals']['paid_cents'],
            ],
        ];
    }

    /**
     * @param  array<int, RmeInvoiceItem>  $items
     */
    private function itemFor(array $items, RmeInvoiceCorrectionLine $line): RmeInvoiceItem
    {
        $item = $items[(int) $line->original_item_id] ?? null;

        if ($item === null) {
            // Unreachable while the fingerprint matched; refuse rather than guess.
            throw new LogicException(sprintf('Correction line #%d names an item that no longer exists.', (int) $line->id));
        }

        return $item;
    }

    /**
     * @return array<string, mixed>
     */
    private function itemValues(RmeInvoiceCorrectionLine $line): array
    {
        return [
            'treatment_id' => $line->proposed_treatment_id,
            'description' => (string) $line->proposed_description,
            'qty' => (int) $line->proposed_qty,
            'unit_price' => (string) $line->proposed_unit_price,
            'discount' => (string) $line->proposed_discount,
            'subtotal' => (string) $line->proposed_subtotal,
            'doctor_id' => $line->proposed_doctor_id,
            // REVISION-RME-MANUAL-PRICING-AUTHORITY-1 — provenance of the applied
            // price: a manual price set by an approved correction, never a tariff.
            'pricing_mode' => TariffPricingMode::MANUAL,
            'price_source' => RmeInvoicePriceSource::CORRECTION_MANUAL_ENTRY,
            'tariff_id' => null,
            'tariff_unit_price' => null,
            'price_exception_reason' => null,
            'price_exception_by' => null,
        ];
    }

    /**
     * Rule 195 §27 — a CHANGED line only re-labels its price provenance when the
     * correction actually changed the PRICE or the TREATMENT. A line whose unit
     * price and treatment are identical (only qty, discount, description or
     * doctor changed) keeps the
     * provenance of the price it still carries: stamping CORRECTION_MANUAL_ENTRY
     * on a price nobody re-entered would misdescribe where that figure came from.
     * The original price itself survives in the frozen v0 document either way.
     *
     * @return array<string, mixed>
     */
    private function changedItemValues(RmeInvoiceItem $item, RmeInvoiceCorrectionLine $line): array
    {
        $values = $this->itemValues($line);

        // Provenance describes WHERE a price for THIS treatment came from, so it is
        // kept only when both the price and the treatment are unchanged (a changed
        // treatment at the same price is a new pricing decision — security review L2).
        if (RmeInvoiceDocument::toCents($item->unit_price) === RmeInvoiceDocument::toCents($line->proposed_unit_price)
            && (int) ($item->treatment_id ?? 0) === (int) ($line->proposed_treatment_id ?? 0)) {
            unset(
                $values['pricing_mode'],
                $values['price_source'],
                $values['tariff_id'],
                $values['tariff_unit_price'],
                $values['price_exception_reason'],
                $values['price_exception_by'],
            );
        }

        return $values;
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        $state = (string) $e->getCode();

        return $state === '23505'
            || $state === '23000'
            || str_contains(strtolower($e->getMessage()), 'unique constraint');
    }
}
