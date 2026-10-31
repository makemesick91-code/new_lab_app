<?php

declare(strict_types=1);

namespace App\Modules\RmeInvoice\Services;

use App\Models\User;
use App\Modules\Branch\Services\BranchService;
use App\Modules\LabOrder\Services\AuditLogService;
use App\Modules\RmeInvoice\Interfaces\RmeBillingDisputeRepositoryInterface;
use App\Modules\RmeInvoice\Interfaces\RmeInvoiceCorrectionRepositoryInterface;
use App\Modules\RmeInvoice\Interfaces\RmeInvoiceRepositoryInterface;
use App\Modules\RmeInvoice\Interfaces\RmeOverpaymentObligationRepositoryInterface;
use App\Modules\RmeInvoice\Interfaces\RmeRefundRequestRepositoryInterface;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use App\Modules\RmeInvoice\Models\RmeOverpaymentObligation;
use App\Modules\RmeInvoice\Models\RmeRefundRequest;
use App\Modules\RmeInvoice\Models\RmeRefundRequestEvent;
use App\Modules\RmeInvoice\Support\RmeFinancialAuditEvent;
use App\Modules\RmeInvoice\Support\RmeInvoiceCorrectionApprovalReference;
use App\Modules\RmeInvoice\Support\RmeInvoiceLedger;
use App\Modules\RmeInvoice\Support\RmeRefundRequestFeatureGuard;
use App\Modules\RmeInvoice\Support\RmeRefundRequestIntent;
use App\Modules\RmeInvoice\Support\RmeRefundRequestPilotIdentity;
use App\Modules\RmeInvoice\Support\RmeRefundRequestPilotScope;
use App\Modules\RmeInvoice\Support\RmeRefundRequestRefused;
use App\Modules\RmeInvoice\Support\RmeRefundRequestSnapshot;
use App\Modules\RmeInvoice\Support\RmeRefundRequestStatus;
use App\Modules\RmeOnlineContext\Services\RmeWorkingBranchScope;
use App\Support\AccessControl\ActiveAccountResolver;
use App\Support\Clinical\ClinicalClock;
use Closure;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * FEATURE-RME-BILLING-REFUND-REQUEST-APPROVAL-1 (Sprint 4/7, rule 215) — the refund
 * REQUEST and its decision (design `docs/architecture/rme-refund-request-approval-design.md`).
 *
 * A request decides WHETHER an OPEN overpayment obligation will be paid back. It
 * never moves money and never writes the obligation, the invoice, its items, its
 * payments or its receipts. Settlement is Sprint 5 and does not exist here.
 *
 * Authority comes from the owner-approved role and its server-resolved branch scope
 * (RF-1): the maker is a Front Office account at its assigned working branch; the
 * reviewer is Super Admin (every active RME branch), Supervisor RME (its authorized
 * scope) or Kepala Cabang (its own managed branch). A permission alone is never
 * enough — Super Admin holds every permission through Gate::before — so the
 * service checks the ROLE and the BRANCH itself. Maker ≠ checker per account,
 * Super Admin included.
 *
 * Every mutation is one transaction with the fixed lock order invoice → obligation
 * → refund request, followed by the actor re-read (the linearization point). The
 * service takes its actor explicitly and never reads the session, the request or
 * the BranchContext.
 */
class RmeRefundRequestService
{
    public const PERMISSION_REQUEST = 'request_rme_refund';

    public const PERMISSION_APPROVE = 'approve_rme_refund';

    public const ROLE_MAKER = 'Front Office';

    public const ROLE_SUPER_ADMIN = 'Super Admin';

    public const ROLE_SUPERVISOR_RME = 'Supervisor RME';

    public const ROLE_KEPALA_CABANG = 'Kepala Cabang';

    /** The only basis for a refund in Sprint 4 (design §7.1 step 8). */
    public const REASON_OVERPAYMENT_AFTER_CORRECTION = 'OVERPAYMENT_AFTER_CORRECTION';

    public const NOTE_MIN = 10;

    public const NOTE_MAX = 1000;

    public function __construct(
        private readonly RmeRefundRequestRepositoryInterface $refunds,
        private readonly RmeInvoiceRepositoryInterface $invoices,
        private readonly RmeOverpaymentObligationRepositoryInterface $obligations,
        private readonly RmeInvoiceCorrectionRepositoryInterface $corrections,
        private readonly RmeBillingDisputeRepositoryInterface $disputes,
        private readonly RmeOwnerApprovalReferenceRegistry $ownerReferences,
        private readonly RmeRefundRequestFeatureGuard $feature,
        private readonly RmeRefundRequestPilotScope $pilotScope,
        private readonly RmeRefundRequestPilotIdentity $pilotIdentity,
        private readonly RmeWorkingBranchScope $workingScope,
        private readonly BranchService $branches,
        private readonly ActiveAccountResolver $accounts,
        private readonly ClinicalClock $clock,
        private readonly AuditLogService $auditLogs,
    ) {}

    // ─── Read side ──────────────────────────────────────────────────────────

    public function capabilityEnabled(): bool
    {
        return $this->feature->enabled();
    }

    public function find(int $id): ?RmeRefundRequest
    {
        return $this->refunds->find($id);
    }

    /** @return Collection<int, RmeRefundRequestEvent> */
    public function eventsFor(RmeRefundRequest $request): Collection
    {
        return $this->refunds->events((int) $request->id);
    }

    /** May this account see refund requests of this branch at all? Out of scope ⇒ 404. */
    public function mayView(?User $user, int $branchId): bool
    {
        return $user !== null && in_array($branchId, $this->visibleBranchIds($user), true);
    }

    /** @param  array<string, mixed>  $filters */
    public function paginateFor(User $user, array $filters, int $perPage = 20): LengthAwarePaginator
    {
        return $this->refunds->paginateForBranches($this->visibleBranchIds($user), $filters, $perPage);
    }

    /** @return array<string, int> */
    public function statusCountsFor(User $user): array
    {
        return $this->refunds->statusCountsForBranches($this->visibleBranchIds($user));
    }

    /**
     * The cashier/invoice panel (design §12). A courtesy for the UI only; every
     * action is refused again by the service.
     *
     * @return array<string, mixed>
     */
    public function panelFor(RmeInvoice $invoice, ?User $user): array
    {
        // No query while the capability is off: the cashier page stays as it was.
        if (! $this->capabilityEnabled()) {
            return ['enabled' => false, 'obligation' => null, 'active_request' => null, 'requests' => collect(), 'can_request' => false];
        }

        $obligation = $this->obligations->openForInvoice((int) $invoice->id);
        $active = $obligation !== null ? $this->refunds->activeForObligation((int) $obligation->id) : null;

        return [
            'enabled' => $this->capabilityEnabled(),
            'obligation' => $obligation,
            'active_request' => $active,
            'requests' => $this->refunds->forInvoice((int) $invoice->id),
            'can_request' => $obligation !== null
                && $active === null
                && $this->capabilityEnabled()
                && $this->mayRequestOn($user, (int) $invoice->branch_id),
        ];
    }

    /**
     * Which actions this account may offer for a request (AIR-RMB-18: page controls
     * come from here, never from a role name in a view).
     *
     * @return array{approve: bool, reject: bool, withdraw: bool, revoke: bool}
     */
    public function actionsFor(RmeRefundRequest $request, ?User $user): array
    {
        $none = ['approve' => false, 'reject' => false, 'withdraw' => false, 'revoke' => false];
        if ($user === null || ! $this->capabilityEnabled()) {
            return $none;
        }

        $account = $this->accounts->resolveUsable($user);
        if ($account === null) {
            return $none;
        }

        $branchId = (int) $request->branch_id;
        $isMaker = (int) $request->requested_by === (int) $account->id;
        $reviews = ! $isMaker && $this->isReviewerFor($account, $branchId);

        return [
            'approve' => $reviews && $request->status === RmeRefundRequestStatus::REQUESTED,
            'reject' => $reviews && $request->status === RmeRefundRequestStatus::REQUESTED,
            'withdraw' => $isMaker && $request->status === RmeRefundRequestStatus::REQUESTED,
            'revoke' => $reviews && $request->status === RmeRefundRequestStatus::APPROVED,
        ];
    }

    public function mayRequestOn(?User $user, int $branchId): bool
    {
        if ($user === null) {
            return false;
        }

        $account = $this->accounts->resolveUsable($user);

        return $account !== null
            && $this->isMakerAccount($account)
            && in_array($branchId, $this->makerBranchIds($account), true)
            && $this->pilotScope->admits($branchId)
            && $this->pilotIdentity->designates(RmeRefundRequestPilotIdentity::ROLE_MAKER, (int) $account->id);
    }

    /**
     * APPROVED-but-unsettled totals by branch (RF-8): reported apart from revenue,
     * receivables, cash and bank, attributed to the source invoice's branch.
     *
     * @param  list<int>  $branchIds
     * @return array<int, array{count: int, amount_cents: int}>
     */
    public function approvedUnsettledByBranch(array $branchIds): array
    {
        return $this->refunds->approvedUnsettledByBranch($branchIds);
    }

    // ─── Submit (design §7.1) ───────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $data  refund_intent, obligation_id, obligation_amount,
     *                                      invoice_version, reason_note, patient_request_reference,
     *                                      owner_approval_reference. Any amount, branch or
     *                                      payment field is ignored.
     */
    public function submit(RmeInvoice $invoice, User $maker, array $data): RmeRefundRequest
    {
        return $this->audited('submit', $maker, null, (int) $invoice->id, function () use ($invoice, $maker, $data) {
            $this->feature->assertEnabled();
            $account = $this->assertUsable($maker, null, (int) $invoice->id);
            $this->assertMakerRole($account, (int) $invoice->id);

            $declaredObligationId = $this->positiveInt($data['obligation_id'] ?? null);
            if ($declaredObligationId === null) {
                throw RmeRefundRequestRefused::notFound((int) $invoice->id);
            }

            $declaredCents = $this->declaredCents($data['obligation_amount'] ?? null);
            if ($declaredCents === null) {
                throw $this->refused(RmeRefundRequestRefused::OBLIGATION_CHANGED, 'Nominal yang diajukan tidak sesuai dengan kewajiban pengembalian.', null, (int) $invoice->id, 'obligation_amount');
            }

            $declaredVersion = $this->nonNegativeInt($data['invoice_version'] ?? null);
            if ($declaredVersion === null) {
                throw $this->refused(RmeRefundRequestRefused::INVOICE_VERSION_CHANGED, 'Versi tagihan pada formulir tidak dikenali. Muat ulang halaman.', null, (int) $invoice->id, 'invoice_version');
            }

            $intentKey = RmeRefundRequestIntent::keyFrom($data[RmeRefundRequestIntent::FIELD] ?? null, $declaredObligationId);
            if ($intentKey === null) {
                throw $this->refused(RmeRefundRequestRefused::INTENT_INVALID, 'Formulir refund tidak valid atau sudah kedaluwarsa. Muat ulang halaman.', null, (int) $invoice->id, RmeRefundRequestIntent::FIELD);
            }

            $reasonNote = $this->note($data['reason_note'] ?? null, 'reason_note', (int) $invoice->id);
            $ownerReference = $this->ownerReference($data['owner_approval_reference'] ?? null, (int) $invoice->id);
            $patientReference = $this->patientReference($data['patient_request_reference'] ?? null, (int) $invoice->id);

            $fingerprint = RmeRefundRequestIntent::fingerprint($declaredObligationId, (int) $account->id, [
                'obligation_cents' => $declaredCents,
                'invoice_version' => $declaredVersion,
                'reason_note' => $reasonNote,
                'patient_reference' => $patientReference,
                'owner_reference' => $ownerReference,
            ]);

            try {
                return DB::transaction(function () use ($invoice, $account, $declaredObligationId, $declaredCents, $declaredVersion, $intentKey, $reasonNote, $ownerReference, $patientReference, $fingerprint) {
                    // §5 step 1 — the invoice.
                    $locked = $this->invoices->findForUpdate((int) $invoice->id);
                    if ($locked === null) {
                        throw RmeRefundRequestRefused::notFound((int) $invoice->id);
                    }

                    // §5 step 2 — the invoice's open obligation, which must be the declared one.
                    $obligation = $this->obligations->openForInvoice((int) $locked->id, lock: true);

                    // §5 step 4 — the actor, re-read under the locks (linearization point).
                    $actor = $this->assertUsable($account, null, (int) $locked->id);
                    $this->assertMakerRole($actor, (int) $locked->id);

                    $branchId = (int) $locked->branch_id;
                    if (! in_array($branchId, $this->makerBranchIds($actor), true)) {
                        throw RmeRefundRequestRefused::notFound((int) $locked->id);
                    }
                    $this->assertPilot($actor, $branchId, RmeRefundRequestPilotIdentity::ROLE_MAKER, null, (int) $locked->id);

                    // Replay (§7.1 step 5): an exact repeat returns the original, whatever its state.
                    $replay = $this->refunds->findByIntentKey($intentKey);
                    if ($replay !== null) {
                        return $this->replayed($replay, $actor, $fingerprint, (int) $locked->id);
                    }

                    if ($obligation === null) {
                        $declared = $this->obligations->find($declaredObligationId);
                        if ($declared !== null && (int) $declared->rme_invoice_id === (int) $locked->id) {
                            throw $this->refused(RmeRefundRequestRefused::OBLIGATION_NOT_OPEN, 'Kewajiban pengembalian ini tidak lagi terbuka.', null, (int) $locked->id);
                        }

                        throw RmeRefundRequestRefused::notFound((int) $locked->id);
                    }

                    if ((int) $obligation->id !== $declaredObligationId) {
                        $declared = $this->obligations->find($declaredObligationId);
                        if ($declared === null || (int) $declared->rme_invoice_id !== (int) $locked->id) {
                            throw RmeRefundRequestRefused::notFound((int) $locked->id);
                        }

                        throw $this->refused(RmeRefundRequestRefused::OBLIGATION_CHANGED, 'Kewajiban pengembalian tagihan ini telah berubah. Muat ulang halaman.', null, (int) $locked->id);
                    }

                    // §5 step 3 — the active request of the obligation, if any.
                    $active = $this->refunds->activeForObligation((int) $obligation->id, lock: true);

                    // §4 eligibility on the locked rows.
                    $this->assertEligible($locked, $obligation, (int) $obligation->id, null);
                    if ($active !== null) {
                        throw $this->refused(RmeRefundRequestRefused::ACTIVE_REQUEST_EXISTS, 'Kewajiban ini sudah memiliki permintaan refund yang aktif.', (int) $active->id, (int) $locked->id);
                    }

                    // §4 declarations vs the locked rows — never capped, rounded or substituted.
                    $obligationCents = RmeRefundRequestSnapshot::cents($obligation->amount);
                    if ($declaredCents !== $obligationCents) {
                        throw $this->refused(RmeRefundRequestRefused::OBLIGATION_CHANGED, 'Nominal yang diajukan tidak sesuai dengan kewajiban pengembalian. Refund hanya dapat diajukan sebesar seluruh kewajiban.', null, (int) $locked->id, 'obligation_amount');
                    }
                    if ($declaredVersion !== (int) ($locked->correction_version ?? 0)) {
                        throw $this->refused(RmeRefundRequestRefused::INVOICE_VERSION_CHANGED, 'Tagihan ini telah berubah sejak halaman dibuka. Muat ulang halaman.', null, (int) $locked->id, 'invoice_version');
                    }

                    // Owner reference (§7.1 step 8a): never recorded before, on ANY refund
                    // or ANY correction, compared case-folded. The registry below is the
                    // guarantee across scopes; this check gives the clear message.
                    if ($this->refunds->ownerReferenceUsedOnRefund($ownerReference)
                        || $this->refunds->ownerReferenceUsedOnCorrection($ownerReference)) {
                        throw $this->ownerReferenceReused((int) $locked->id);
                    }

                    $snapshot = RmeRefundRequestSnapshot::capture($locked, $obligation);

                    $request = $this->refunds->create([
                        'uuid' => (string) Str::uuid(),
                        'branch_id' => $branchId,
                        'rme_invoice_id' => (int) $locked->id,
                        'rme_overpayment_obligation_id' => (int) $obligation->id,
                        'clinic_visit_id' => (int) $obligation->clinic_visit_id,
                        'patient_id' => (int) $obligation->patient_id,
                        'amount' => RmeRefundRequestSnapshot::decimal($obligationCents),
                        'obligation_amount_snapshot' => RmeRefundRequestSnapshot::decimal($obligationCents),
                        'invoice_version' => (int) ($locked->correction_version ?? 0),
                        'request_snapshot' => $snapshot,
                        'request_snapshot_sha256' => RmeRefundRequestSnapshot::hash($snapshot),
                        'reason_code' => self::REASON_OVERPAYMENT_AFTER_CORRECTION,
                        'reason_note' => $reasonNote,
                        'patient_request_reference' => $patientReference,
                        'status' => RmeRefundRequestStatus::REQUESTED,
                        'intent_key' => $intentKey,
                        'request_fingerprint' => $fingerprint,
                        'requested_by' => (int) $actor->id,
                        'requested_at' => now(),
                        'owner_approval_reference' => $ownerReference,
                    ]);

                    $request = $this->refunds->assignNumber(
                        $request,
                        'RMEREF-'.$this->clock->now()->format('Ym').'-'.str_pad((string) $request->id, 6, '0', STR_PAD_LEFT),
                    );

                    $this->recordTransition($request, null, $actor);

                    // Registries last, in fixed order: Owner reference, then patient reference
                    // (migration plan §2a/§2b) — after every row lock, so no wait cycle forms.
                    if (! $this->ownerReferences->consume($ownerReference, RmeOwnerApprovalReferenceRegistry::SCOPE_REFUND_REQUEST, (int) $request->id, $actor)) {
                        throw $this->ownerReferenceReused((int) $locked->id);
                    }

                    $patientFolded = strtolower($patientReference);
                    $this->refunds->claimPatientReference($patientFolded, (int) $obligation->id, (int) $actor->id);
                    if ($this->refunds->patientReferenceObligation($patientFolded) !== (int) $obligation->id) {
                        throw $this->refused(RmeRefundRequestRefused::PATIENT_REFERENCE_REUSED, 'Referensi permintaan pasien ini sudah dipakai untuk kewajiban pengembalian lain.', null, (int) $locked->id, 'patient_request_reference');
                    }

                    return $request;
                });
            } catch (QueryException $e) {
                // PostgreSQL aborts the transaction on a unique violation, so it is
                // translated here, at the boundary, never caught and continued inside.
                if (! $this->isUniqueViolation($e)) {
                    throw $e;
                }

                $message = $e->getMessage();
                if (str_contains($message, 'trx_rme_refund_req_intent_uq') || str_contains($message, 'intent_key')) {
                    $replay = $this->refunds->findByIntentKey($intentKey);
                    if ($replay !== null) {
                        return $this->replayed($replay, $account, $fingerprint, (int) $invoice->id);
                    }
                }

                if (str_contains($message, 'trx_rme_refund_req_owner_ref_uq')) {
                    throw $this->ownerReferenceReused((int) $invoice->id);
                }

                throw $this->refused(RmeRefundRequestRefused::ACTIVE_REQUEST_EXISTS, 'Kewajiban ini sudah memiliki permintaan refund yang aktif.', null, (int) $invoice->id);
            }
        });
    }

    // ─── Decide (design §7.2–§7.4) ──────────────────────────────────────────

    /** @param  array<string, mixed>  $data  decision_note (optional). A reference here is ignored. */
    public function approve(RmeRefundRequest $request, User $reviewer, array $data = []): RmeRefundRequest
    {
        $note = $this->optionalNote($data['decision_note'] ?? null, (int) $request->id, (int) $request->rme_invoice_id);

        [$result, $stale] = $this->audited('approve', $reviewer, (int) $request->id, (int) $request->rme_invoice_id, function () use ($request, $reviewer, $note) {
            $this->feature->assertEnabled();
            $this->assertReviewerAuthority($this->assertUsable($reviewer, (int) $request->id, (int) $request->rme_invoice_id), $request);

            return DB::transaction(function () use ($request, $reviewer, $note) {
                [$invoice, $obligation, $locked, $actor] = $this->lockForDecision($request, $reviewer);
                $this->assertReviewer($actor, $locked);

                if ($locked->status === RmeRefundRequestStatus::APPROVED && (int) $locked->decided_by === (int) $actor->id) {
                    return [$locked, null]; // the same reviewer repeating the same decision
                }
                $this->assertDecidable($locked);
                $this->assertNotMaker($locked, $actor, RmeRefundRequestRefused::SELF_APPROVAL_FORBIDDEN);

                // §7.2 step 6 — the financial state still matches the request.
                $staleReason = $this->staleReason($invoice, $obligation, $locked);
                if ($staleReason !== null) {
                    return [$this->markStale($locked, $staleReason, $actor), $staleReason];
                }

                // §7.2 step 7 — the request's own Owner reference, bound to THIS request.
                if (! $this->ownerReferences->belongsTo((string) $locked->owner_approval_reference, RmeOwnerApprovalReferenceRegistry::SCOPE_REFUND_REQUEST, (int) $locked->id)) {
                    throw $this->refused(RmeRefundRequestRefused::OWNER_REFERENCE_INVALID, 'Referensi persetujuan Owner permintaan ini tidak tercatat untuk permintaan ini.', (int) $locked->id, (int) $invoice->id);
                }

                // §7.2 step 7a — an active billing dispute blocks the approval too (RF-7).
                $this->assertNotDisputed($invoice, (int) $locked->id);

                $from = (string) $locked->status;
                $locked = $this->refunds->transition($locked, [
                    'status' => RmeRefundRequestStatus::APPROVED,
                    'decided_by' => (int) $actor->id,
                    'decided_at' => now(),
                    'decision_note' => $note,
                ]);
                $this->recordTransition($locked, $from, $actor);

                return [$locked, null];
            });
        });

        if ($stale !== null) {
            $this->throwStale($result, $stale);
        }

        return $result;
    }

    /** @param  array<string, mixed>  $data  decision_note (required, 10–1000) */
    public function reject(RmeRefundRequest $request, User $reviewer, array $data): RmeRefundRequest
    {
        return $this->audited('reject', $reviewer, (int) $request->id, (int) $request->rme_invoice_id, function () use ($request, $reviewer, $data) {
            $this->feature->assertEnabled();
            $this->assertReviewerAuthority($this->assertUsable($reviewer, (int) $request->id, (int) $request->rme_invoice_id), $request);
            $note = $this->note($data['decision_note'] ?? null, 'decision_note', (int) $request->rme_invoice_id, (int) $request->id);

            return DB::transaction(function () use ($request, $reviewer, $note) {
                [, , $locked, $actor] = $this->lockForDecision($request, $reviewer);
                $this->assertReviewer($actor, $locked);

                if ($locked->status === RmeRefundRequestStatus::REJECTED && (int) $locked->decided_by === (int) $actor->id) {
                    return $locked;
                }
                $this->assertDecidable($locked);
                $this->assertNotMaker($locked, $actor, RmeRefundRequestRefused::SELF_DECISION_FORBIDDEN);

                $from = (string) $locked->status;
                $locked = $this->refunds->transition($locked, [
                    'status' => RmeRefundRequestStatus::REJECTED,
                    'decided_by' => (int) $actor->id,
                    'decided_at' => now(),
                    'decision_note' => $note,
                ]);
                $this->recordTransition($locked, $from, $actor);

                // The obligation stays OPEN and nothing is forgiven (RF-6).
                return $locked;
            });
        });
    }

    /**
     * REQUESTED → CANCELLED by its maker (withdraw); APPROVED → CANCELLED by an
     * authorised reviewer that is not the maker (revoke, before any settlement).
     *
     * @param  array<string, mixed>  $data  cancel_reason (required, 10–1000)
     */
    public function cancel(RmeRefundRequest $request, User $actor, array $data): RmeRefundRequest
    {
        return $this->audited('cancel', $actor, (int) $request->id, (int) $request->rme_invoice_id, function () use ($request, $actor, $data) {
            $this->feature->assertEnabled();
            $this->assertUsable($actor, (int) $request->id, (int) $request->rme_invoice_id);
            $reason = $this->note($data['cancel_reason'] ?? null, 'cancel_reason', (int) $request->rme_invoice_id, (int) $request->id);

            return DB::transaction(function () use ($request, $actor, $reason) {
                [, , $locked, $account] = $this->lockForDecision($request, $actor);
                $isMaker = (int) $locked->requested_by === (int) $account->id;

                if ($locked->status === RmeRefundRequestStatus::REQUESTED) {
                    if (! $isMaker || ! $this->isMakerAccount($account)) {
                        throw $this->refused(RmeRefundRequestRefused::MAKER_NOT_AUTHORIZED, 'Hanya pengaju dapat menarik permintaan refund yang belum diputuskan.', (int) $locked->id, (int) $locked->rme_invoice_id);
                    }
                } elseif ($locked->status === RmeRefundRequestStatus::APPROVED) {
                    if ($isMaker) {
                        throw $this->refused(RmeRefundRequestRefused::SELF_DECISION_FORBIDDEN, 'Pengaju tidak dapat mencabut persetujuan permintaannya sendiri.', (int) $locked->id, (int) $locked->rme_invoice_id);
                    }
                    $this->assertReviewer($account, $locked);
                } else {
                    throw $this->refused(RmeRefundRequestRefused::INVALID_TRANSITION, 'Permintaan refund ini sudah selesai dan tidak dapat dibatalkan.', (int) $locked->id, (int) $locked->rme_invoice_id);
                }

                $from = (string) $locked->status;
                $locked = $this->refunds->transition($locked, [
                    'status' => RmeRefundRequestStatus::CANCELLED,
                    'cancelled_by' => (int) $account->id,
                    'cancelled_at' => now(),
                    'cancel_reason' => $reason,
                ]);
                $this->recordTransition($locked, $from, $account);

                return $locked;
            });
        });
    }

    // ─── Authority ──────────────────────────────────────────────────────────

    /**
     * The maker's branch: the ASSIGNED working branch of a context-bound Front Office
     * account (RF-1). An account exempt from the working context (Owner, Super Admin,
     * Supervisor RME) has no working branch and is therefore never a maker.
     *
     * @return list<int>
     */
    public function makerBranchIds(User $account): array
    {
        if (! $this->isMakerAccount($account) || ! $this->workingScope->isContextBound($account)) {
            return [];
        }

        $branchId = $this->workingScope->activeBranchId($account);

        return $branchId !== null && in_array($branchId, $this->branches->rmeEnabledIds(), true) ? [$branchId] : [];
    }

    /**
     * The reviewer's branches, resolved from the persisted account per role (RF-1).
     *
     * @return list<int>
     */
    public function reviewerBranchIds(User $account): array
    {
        if (! $account->can(self::PERMISSION_APPROVE)) {
            return [];
        }

        $rme = $this->branches->rmeEnabledIds();
        $ids = [];

        if ($account->hasRole(self::ROLE_SUPER_ADMIN)) {
            $ids = $rme;
        }

        if ($account->hasRole(self::ROLE_SUPERVISOR_RME)) {
            $ids = array_merge($ids, array_values(array_intersect($this->workingScope->branchIdsFor($account), $rme)));
        }

        if ($account->hasRole(self::ROLE_KEPALA_CABANG)) {
            $own = $account->branch_id !== null ? (int) $account->branch_id : null;
            if ($own !== null && in_array($own, $rme, true)) {
                $ids[] = $own;
            }
        }

        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids);

        return $ids;
    }

    /** @return list<int> */
    private function visibleBranchIds(User $user): array
    {
        $account = $this->accounts->resolveUsable($user);
        if ($account === null) {
            return [];
        }

        $ids = array_values(array_unique(array_merge($this->makerBranchIds($account), $this->reviewerBranchIds($account))));
        sort($ids);

        return $ids;
    }

    private function isMakerAccount(User $account): bool
    {
        return $account->hasRole(self::ROLE_MAKER) && $account->can(self::PERMISSION_REQUEST);
    }

    private function isReviewerFor(User $account, int $branchId): bool
    {
        return in_array($branchId, $this->reviewerBranchIds($account), true)
            && $this->pilotScope->admits($branchId)
            && $this->pilotIdentity->designates(RmeRefundRequestPilotIdentity::ROLE_REVIEWER, (int) $account->id);
    }

    private function assertMakerRole(User $account, int $invoiceId): void
    {
        if (! $this->isMakerAccount($account)) {
            throw $this->refused(RmeRefundRequestRefused::MAKER_NOT_AUTHORIZED, 'Permintaan refund hanya dapat diajukan oleh Front Office di cabang kerjanya.', null, $invoiceId);
        }
    }

    /**
     * The actor holds a reviewer role, the approve permission and a non-empty
     * scope. It depends on the account only — never on the record — so refusing
     * here reveals nothing about another branch.
     */
    private function assertReviewerAuthority(User $account, RmeRefundRequest $request): void
    {
        if (! $this->holdsReviewerRole($account) || $this->reviewerBranchIds($account) === []) {
            throw $this->refused(RmeRefundRequestRefused::REVIEWER_NOT_AUTHORIZED, 'Akun Anda tidak berwenang memutuskan permintaan refund.', (int) $request->id, (int) $request->rme_invoice_id);
        }
    }

    private function assertReviewer(User $account, RmeRefundRequest $request): void
    {
        $branchId = (int) $request->branch_id;
        $reviewerBranches = $this->reviewerBranchIds($account);

        $this->assertReviewerAuthority($account, $request);

        if (! in_array($branchId, $reviewerBranches, true)) {
            if (! in_array($branchId, $this->visibleBranchIds($account), true)) {
                throw RmeRefundRequestRefused::notFound((int) $request->rme_invoice_id, (int) $request->id);
            }

            throw $this->refused(RmeRefundRequestRefused::REVIEWER_NOT_AUTHORIZED, 'Akun Anda tidak berwenang memutuskan permintaan refund cabang ini.', (int) $request->id, (int) $request->rme_invoice_id);
        }

        $this->assertPilot($account, $branchId, RmeRefundRequestPilotIdentity::ROLE_REVIEWER, (int) $request->id, (int) $request->rme_invoice_id);
    }

    private function holdsReviewerRole(User $account): bool
    {
        return $account->hasRole([self::ROLE_SUPER_ADMIN, self::ROLE_SUPERVISOR_RME, self::ROLE_KEPALA_CABANG]);
    }

    private function assertPilot(User $account, int $branchId, string $role, ?int $requestId, ?int $invoiceId): void
    {
        if (! $this->pilotScope->admits($branchId)) {
            throw $this->refused(RmeRefundRequestRefused::PILOT_SCOPE_DENIED, 'Permintaan refund belum diaktifkan untuk cabang ini.', $requestId, $invoiceId);
        }

        if (! $this->pilotIdentity->designates($role, (int) $account->id)) {
            throw $this->refused(RmeRefundRequestRefused::PILOT_IDENTITY_DENIED, 'Akun Anda tidak ditunjuk untuk pilot permintaan refund.', $requestId, $invoiceId);
        }
    }

    private function assertNotMaker(RmeRefundRequest $request, User $actor, string $code): void
    {
        if ((int) $request->requested_by === (int) $actor->id) {
            throw $this->refused($code, 'Pengaju tidak dapat memutuskan permintaan refundnya sendiri.', (int) $request->id, (int) $request->rme_invoice_id);
        }
    }

    private function assertUsable(User $actor, ?int $requestId, ?int $invoiceId): User
    {
        $account = $this->accounts->resolveUsable($actor);
        if ($account === null) {
            throw $this->refused(RmeRefundRequestRefused::ACTOR_UNUSABLE, 'Akun ini tidak aktif atau tidak dikenali.', $requestId, $invoiceId);
        }

        return $account;
    }

    // ─── Locks, eligibility, staleness ─────────────────────────────────────

    /**
     * §5 for a decision: invoice → the request's OWN obligation (asserted to be the
     * invoice's open obligation) → the request → the actor.
     *
     * @return array{0: RmeInvoice, 1: RmeOverpaymentObligation, 2: RmeRefundRequest, 3: User}
     */
    private function lockForDecision(RmeRefundRequest $request, User $actor): array
    {
        $invoice = $this->invoices->findForUpdate((int) $request->rme_invoice_id);
        $obligation = $this->obligations->findForUpdate((int) $request->rme_overpayment_obligation_id);
        $locked = $this->refunds->findForUpdate((int) $request->id);

        if ($invoice === null || $obligation === null || $locked === null) {
            throw RmeRefundRequestRefused::notFound((int) $request->rme_invoice_id, (int) $request->id);
        }

        $account = $this->assertUsable($actor, (int) $locked->id, (int) $invoice->id);

        if (! in_array((int) $locked->branch_id, $this->visibleBranchIds($account), true)) {
            throw RmeRefundRequestRefused::notFound((int) $invoice->id, (int) $locked->id);
        }

        $open = $this->obligations->openForInvoice((int) $invoice->id);
        if ((int) $obligation->rme_invoice_id !== (int) $invoice->id
            || ($locked->isActive() && ($open === null || (int) $open->id !== (int) $obligation->id))) {
            throw $this->refused(RmeRefundRequestRefused::OBLIGATION_CHANGED, 'Kewajiban pengembalian permintaan ini tidak lagi sesuai dengan tagihan.', (int) $locked->id, (int) $invoice->id);
        }

        return [$invoice, $obligation, $locked, $account];
    }

    private function assertDecidable(RmeRefundRequest $request): void
    {
        if ($request->status === RmeRefundRequestStatus::REQUESTED) {
            return;
        }

        if (in_array($request->status, [RmeRefundRequestStatus::APPROVED, RmeRefundRequestStatus::REJECTED], true)) {
            throw $this->refused(RmeRefundRequestRefused::ALREADY_DECIDED, 'Permintaan refund ini sudah diputuskan.', (int) $request->id, (int) $request->rme_invoice_id);
        }

        throw $this->refused(RmeRefundRequestRefused::INVALID_TRANSITION, 'Permintaan refund ini sudah selesai dan tidak dapat diputuskan.', (int) $request->id, (int) $request->rme_invoice_id);
    }

    /** §4 on the locked rows. */
    private function assertEligible(RmeInvoice $invoice, RmeOverpaymentObligation $obligation, int $obligationId, ?int $requestId): void
    {
        $invoiceId = (int) $invoice->id;

        if ($obligation->status !== RmeOverpaymentObligation::STATUS_OPEN) {
            throw $this->refused(RmeRefundRequestRefused::OBLIGATION_NOT_OPEN, 'Kewajiban pengembalian ini tidak lagi terbuka.', $requestId, $invoiceId);
        }

        if ((int) $obligation->id !== $obligationId
            || (int) $obligation->rme_invoice_id !== $invoiceId
            || (int) $obligation->branch_id !== (int) $invoice->branch_id) {
            throw $this->refused(RmeRefundRequestRefused::OBLIGATION_CHANGED, 'Kewajiban pengembalian tidak sesuai dengan tagihan ini.', $requestId, $invoiceId);
        }

        if ($invoice->status !== RmeInvoice::STATUS_PAID) {
            throw $this->refused(RmeRefundRequestRefused::INVOICE_NOT_PAID, 'Refund hanya dapat diajukan untuk tagihan yang lunas.', $requestId, $invoiceId);
        }

        if ((int) ($invoice->correction_version ?? 0) !== (int) $obligation->invoice_version) {
            throw $this->refused(RmeRefundRequestRefused::INVOICE_VERSION_CHANGED, 'Tagihan ini telah berubah sejak kewajiban pengembalian dicatat.', $requestId, $invoiceId);
        }

        $overpaid = RmeInvoiceLedger::overpaidCents(RmeInvoiceLedger::grandTotalCents($invoice), RmeInvoiceLedger::paidCents($invoice));
        if ($overpaid !== RmeRefundRequestSnapshot::cents($obligation->amount)) {
            throw $this->refused(RmeRefundRequestRefused::LEDGER_MISMATCH, 'Kelebihan bayar menurut catatan pembayaran tidak sama dengan kewajiban pengembalian.', $requestId, $invoiceId);
        }

        if ($this->corrections->activeForInvoice($invoiceId) !== null) {
            throw $this->refused(RmeRefundRequestRefused::CORRECTION_ACTIVE, 'Tagihan ini sedang dalam proses koreksi.', $requestId, $invoiceId);
        }

        $this->assertNotDisputed($invoice, $requestId);
    }

    private function assertNotDisputed(RmeInvoice $invoice, ?int $requestId): void
    {
        if ($this->disputes->activeForInvoice((int) $invoice->id) !== null) {
            throw $this->refused(RmeRefundRequestRefused::INVOICE_DISPUTED, 'Tagihan ini sedang dalam sengketa tagihan. Selesaikan sengketanya terlebih dahulu.', $requestId, (int) $invoice->id);
        }
    }

    private function staleReason(RmeInvoice $invoice, RmeOverpaymentObligation $obligation, RmeRefundRequest $request): ?string
    {
        try {
            $this->assertEligible($invoice, $obligation, (int) $request->rme_overpayment_obligation_id, (int) $request->id);
        } catch (RmeRefundRequestRefused $refused) {
            if ($refused->reasonCode === RmeRefundRequestRefused::INVOICE_DISPUTED) {
                return null; // a dispute refuses the approval; it is not a financial change
            }

            return $refused->reasonCode;
        }

        $snapshot = RmeRefundRequestSnapshot::capture($invoice, $obligation);

        return hash_equals((string) $request->request_snapshot_sha256, RmeRefundRequestSnapshot::hash($snapshot))
            ? null
            : 'SNAPSHOT_CHANGED';
    }

    private function markStale(RmeRefundRequest $request, string $reason, User $actor): RmeRefundRequest
    {
        $from = (string) $request->status;
        $request = $this->refunds->transition($request, [
            'status' => RmeRefundRequestStatus::STALE,
            'stale_reason_code' => mb_substr($reason, 0, 60),
            'staled_at' => now(),
        ]);
        $this->recordTransition($request, $from, $actor, ['stale_reason_code' => $request->stale_reason_code]);

        return $request;
    }

    private function throwStale(RmeRefundRequest $request, string $reason): never
    {
        throw $this->refused(
            RmeRefundRequestRefused::REQUEST_STALE,
            'Keadaan tagihan atau kewajiban pengembalian telah berubah sejak permintaan diajukan ('.$reason.'). Permintaan ini kedaluwarsa; ajukan permintaan baru bila masih diperlukan.',
            (int) $request->id,
            (int) $request->rme_invoice_id,
        );
    }

    private function replayed(RmeRefundRequest $existing, User $actor, string $fingerprint, int $invoiceId): RmeRefundRequest
    {
        if ((int) $existing->requested_by === (int) $actor->id && hash_equals((string) $existing->request_fingerprint, $fingerprint)) {
            return $existing;
        }

        throw $this->refused(RmeRefundRequestRefused::INTENT_REUSED, 'Formulir ini sudah dipakai untuk permintaan lain. Muat ulang halaman.', (int) $existing->id, $invoiceId, RmeRefundRequestIntent::FIELD);
    }

    // ─── Input ──────────────────────────────────────────────────────────────

    private function note(mixed $value, string $field, ?int $invoiceId, ?int $requestId = null): string
    {
        $text = is_string($value) ? trim($value) : '';
        $length = mb_strlen($text);

        if ($length < self::NOTE_MIN || $length > self::NOTE_MAX) {
            throw $this->refused(RmeRefundRequestRefused::REASON_REQUIRED, 'Alasan wajib diisi, '.self::NOTE_MIN.'–'.self::NOTE_MAX.' karakter.', $requestId, $invoiceId, $field);
        }

        return $text;
    }

    private function optionalNote(mixed $value, int $requestId, int $invoiceId): ?string
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }

        return $this->note($value, 'decision_note', $invoiceId, $requestId);
    }

    private function ownerReference(mixed $value, int $invoiceId): string
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            throw $this->refused(RmeRefundRequestRefused::OWNER_REFERENCE_REQUIRED, 'Setiap permintaan refund wajib mencantumkan referensi persetujuan Owner untuk kasus ini.', null, $invoiceId, 'owner_approval_reference');
        }

        $normalized = $this->normalizedReference($value);
        if ($normalized === null) {
            throw $this->refused(RmeRefundRequestRefused::OWNER_REFERENCE_INVALID, 'Format referensi persetujuan Owner tidak valid (6–120 karakter: huruf, angka, . _ : / -; tanpa rangkaian 13 digit atau lebih).', null, $invoiceId, 'owner_approval_reference');
        }

        return $normalized;
    }

    private function patientReference(mixed $value, int $invoiceId): string
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            throw $this->refused(RmeRefundRequestRefused::PATIENT_REFERENCE_REQUIRED, 'Referensi permintaan pasien wajib diisi.', null, $invoiceId, 'patient_request_reference');
        }

        // The same format rules as an Owner reference: no NIK-shaped value, also
        // when split by separators.
        $normalized = $this->normalizedReference($value);
        if ($normalized === null) {
            throw $this->refused(RmeRefundRequestRefused::PATIENT_REFERENCE_INVALID, 'Format referensi permintaan pasien tidak valid (6–120 karakter: huruf, angka, . _ : / -). Jangan gunakan NIK.', null, $invoiceId, 'patient_request_reference');
        }

        return $normalized;
    }

    /**
     * The canonical reference format (RmeInvoiceCorrectionApprovalReference): null
     * when the value is not a well-formed reference. The caller has already
     * refused an empty value, so null here always means "malformed".
     */
    private function normalizedReference(mixed $value): ?string
    {
        try {
            return RmeInvoiceCorrectionApprovalReference::normalize($value);
        } catch (ValidationException) {
            return null;
        }
    }

    private function ownerReferenceReused(int $invoiceId): RmeRefundRequestRefused
    {
        return $this->refused(RmeRefundRequestRefused::OWNER_REFERENCE_REUSED, 'Referensi persetujuan Owner ini sudah dipakai untuk keputusan lain. Setiap permintaan memerlukan referensi persetujuannya sendiri.', null, $invoiceId, 'owner_approval_reference');
    }

    private function declaredCents(mixed $value): ?int
    {
        if (! is_string($value) && ! is_int($value)) {
            return null;
        }

        $text = trim((string) $value);
        if (preg_match('/^\d{1,13}(\.\d{1,2})?$/D', $text) !== 1) {
            return null;
        }

        $cents = RmeRefundRequestSnapshot::cents($text);

        return $cents > 0 ? $cents : null;
    }

    private function positiveInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        return is_string($value) && ctype_digit($value) && (int) $value > 0 ? (int) $value : null;
    }

    private function nonNegativeInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value >= 0 ? $value : null;
        }

        return is_string($value) && ctype_digit($value) ? (int) $value : null;
    }

    // ─── Audit ──────────────────────────────────────────────────────────────

    private function recordTransition(RmeRefundRequest $request, ?string $from, User $actor, array $extra = []): void
    {
        $payload = RmeFinancialAuditEvent::refundRequestPayload($request, $from, $extra);

        $this->refunds->recordEvent([
            'rme_refund_request_id' => (int) $request->id,
            'from_status' => $from,
            'to_status' => (string) $request->status,
            'actor_id' => (int) $actor->id,
            'occurred_at' => now(),
            'metadata' => $payload,
        ]);

        $this->auditLogs->log(
            RmeFinancialAuditEvent::ENTITY_REFUND_REQUEST,
            (int) $request->id,
            RmeFinancialAuditEvent::REFUND_REQUEST_ACTIONS[(string) $request->status],
            $from !== null ? ['status' => $from] : null,
            $payload,
            $actor,
        );
    }

    /**
     * Runs a mutation; a refusal is recorded with its code after the transaction
     * rolled back (AIR-RMB-08), then rethrown.
     *
     * @template T
     *
     * @param  Closure(): T  $work
     * @return T
     */
    private function audited(string $action, User $actor, ?int $requestId, ?int $invoiceId, Closure $work): mixed
    {
        try {
            return $work();
        } catch (RmeRefundRequestRefused $refused) {
            $this->auditLogs->log(
                RmeFinancialAuditEvent::ENTITY_REFUND_REQUEST,
                $refused->refundRequestId ?? $requestId,
                RmeFinancialAuditEvent::ACTION_REFUND_REQUEST_REFUSED,
                null,
                [
                    'attempted_action' => $action,
                    'code' => $refused->reasonCode,
                    'refund_request_id' => $refused->refundRequestId ?? $requestId,
                    'rme_invoice_id' => $refused->invoiceId ?? $invoiceId,
                ],
                $actor,
            );

            throw $refused;
        }
    }

    private function refused(string $code, string $message, ?int $requestId, ?int $invoiceId, string $field = 'refund'): RmeRefundRequestRefused
    {
        return new RmeRefundRequestRefused($code, $message, false, $requestId, $invoiceId, $field);
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        return in_array((string) ($e->errorInfo[0] ?? $e->getCode()), ['23505', '23000'], true);
    }
}
