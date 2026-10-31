<?php

declare(strict_types=1);

namespace App\Modules\RmeInvoice\Services;

use App\Models\User;
use App\Modules\LabOrder\Services\AuditLogService;
use App\Modules\RmeInvoice\Interfaces\RmeBillingDisputeRepositoryInterface;
use App\Modules\RmeInvoice\Interfaces\RmeInvoiceCorrectionRepositoryInterface;
use App\Modules\RmeInvoice\Interfaces\RmeInvoiceRepositoryInterface;
use App\Modules\RmeInvoice\Interfaces\RmeOverpaymentObligationRepositoryInterface;
use App\Modules\RmeInvoice\Interfaces\RmeRefundRequestRepositoryInterface;
use App\Modules\RmeInvoice\Interfaces\RmeRefundSettlementRepositoryInterface;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use App\Modules\RmeInvoice\Models\RmeOverpaymentObligation;
use App\Modules\RmeInvoice\Models\RmeRefundRequest;
use App\Modules\RmeInvoice\Models\RmeRefundSettlement;
use App\Modules\RmeInvoice\Models\RmeRefundSettlementEvent;
use App\Modules\RmeInvoice\Support\RmeFinancialAuditEvent;
use App\Modules\RmeInvoice\Support\RmeInvoiceLedger;
use App\Modules\RmeInvoice\Support\RmeRefundRequestSnapshot;
use App\Modules\RmeInvoice\Support\RmeRefundRequestStatus;
use App\Modules\RmeInvoice\Support\RmeRefundSettlementEvidence;
use App\Modules\RmeInvoice\Support\RmeRefundSettlementFeatureGuard;
use App\Modules\RmeInvoice\Support\RmeRefundSettlementIntent;
use App\Modules\RmeInvoice\Support\RmeRefundSettlementMethod;
use App\Modules\RmeInvoice\Support\RmeRefundSettlementPilot;
use App\Modules\RmeInvoice\Support\RmeRefundSettlementRefused;
use App\Modules\RmeInvoice\Support\RmeRefundSettlementStatus;
use App\Support\AccessControl\ActiveAccountResolver;
use App\Support\Clinical\ClinicalClock;
use Closure;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use RuntimeException;
use Throwable;

/**
 * FEATURE-RME-REFUND-SETTLEMENT-1 (Sprint 5/7, rule 216) — recording that money owed
 * back on an APPROVED refund request actually left the clinic (owner RF-9 = C,
 * RF-10 = B, RF-11; financial contract RFS-1…RFS-7).
 *
 * The system never moves money: a designated settler pays outside it, by bank
 * transfer or cash, and records the fact here.
 *
 *   start     APPROVED request → a DISBURSING claim (one per request and obligation)
 *   confirm   DISBURSING/OUTCOME_UNKNOWN → SETTLED, with method-specific proof and
 *             the settler's attestation; moves the request and the obligation to
 *             SETTLED in the same transaction (RFS-4) at server time (RF-11)
 *   unknown   DISBURSING → OUTCOME_UNKNOWN: the result is uncertain; the claim stays
 *             held and nothing is retried
 *   abort     → ABORTED: the settler attests no money left (from an unknown outcome
 *             only with evidence); releases the request for a fresh attempt
 *
 * Authority is positive and narrow: a usable account holding `settle_rme_refund`,
 * explicitly designated for the branch in the source-controlled pilot (which ships
 * EMPTY), never a read-only oversight role, and never the request's approver
 * (RF-10) — Super Admin included, because Gate::before grants it every permission.
 * From DISBURSING only the starter confirms or aborts (a second settler aborting
 * someone else's in-flight payout could release it to be paid twice); anyone
 * authorised may escalate a claim to OUTCOME_UNKNOWN and reconcile it.
 *
 * Every mutation is one transaction with the fixed lock order invoice → obligation →
 * refund request → settlement, followed by the actor re-read (the linearization
 * point). The service takes its actor explicitly and never reads the session, the
 * request or the BranchContext. Incoming payments are never written (RFS-5).
 */
class RmeRefundSettlementService
{
    public const PERMISSION_SETTLE = 'settle_rme_refund';

    /** Read-only oversight roles: never payers, whatever a designation says (RF-8a). */
    public const NEVER_SETTLER_ROLES = ['Owner', 'Finance Klinik', 'Data Analyst'];

    public const NOTE_MIN = 10;

    public const NOTE_MAX = 1000;

    private const REFERENCE_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9 ._:\/#-]{5,59}$/D';

    private const BANK_NAME_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9 .&\/-]{1,59}$/D';

    public function __construct(
        private readonly RmeRefundSettlementRepositoryInterface $settlements,
        private readonly RmeRefundRequestRepositoryInterface $refunds,
        private readonly RmeInvoiceRepositoryInterface $invoices,
        private readonly RmeOverpaymentObligationRepositoryInterface $obligations,
        private readonly RmeInvoiceCorrectionRepositoryInterface $corrections,
        private readonly RmeBillingDisputeRepositoryInterface $disputes,
        private readonly RmeRefundRequestService $refundRequests,
        private readonly RmeRefundSettlementFeatureGuard $feature,
        private readonly RmeRefundSettlementPilot $pilot,
        private readonly ActiveAccountResolver $accounts,
        private readonly ClinicalClock $clock,
        private readonly AuditLogService $auditLogs,
    ) {}

    // ─── Read side ──────────────────────────────────────────────────────────

    public function capabilityEnabled(): bool
    {
        return $this->feature->enabled();
    }

    public function find(int $id): ?RmeRefundSettlement
    {
        return $this->settlements->find($id);
    }

    /** @return Collection<int, RmeRefundSettlementEvent> */
    public function eventsFor(RmeRefundSettlement $settlement): Collection
    {
        return $this->settlements->events((int) $settlement->id);
    }

    /** @return Collection<int, RmeRefundSettlement> */
    public function forRequest(RmeRefundRequest $request): Collection
    {
        return $this->settlements->forRequest((int) $request->id);
    }

    /**
     * May this account see settlements of this branch? Settlers of the branch, and
     * the refund reviewers of the branch (who decided the request). Out of scope ⇒ 404.
     */
    public function mayView(?User $user, int $branchId): bool
    {
        return $user !== null && in_array($branchId, $this->visibleBranchIds($user), true);
    }

    /** @param  array<string, mixed>  $filters */
    public function paginateFor(User $user, array $filters, int $perPage = 20): LengthAwarePaginator
    {
        return $this->settlements->paginateForBranches($this->visibleBranchIds($user), $filters, $perPage);
    }

    /** @return array<string, int> */
    public function statusCountsFor(User $user): array
    {
        return $this->settlements->statusCountsForBranches($this->visibleBranchIds($user));
    }

    /** APPROVED requests awaiting a payout, in this settler's branches only. */
    public function approvedQueueFor(User $user, int $perPage = 20): LengthAwarePaginator
    {
        $account = $this->accounts->resolveUsable($user);
        $branchIds = $account !== null ? $this->settlerBranchIds($account) : [];

        return $this->refunds->paginateForBranches($branchIds, ['status' => RmeRefundRequestStatus::APPROVED], $perPage);
    }

    /**
     * The settlement panel of a refund request page. A courtesy for the UI only;
     * every action is refused again by the service.
     *
     * @return array{enabled: bool, settlements: Collection<int, RmeRefundSettlement>, holding: ?RmeRefundSettlement, can_start: bool}
     */
    public function panelFor(RmeRefundRequest $request, ?User $user): array
    {
        if (! $this->capabilityEnabled()) {
            return ['enabled' => false, 'settlements' => collect(), 'holding' => null, 'can_start' => false];
        }

        return [
            'enabled' => true,
            'settlements' => $this->settlements->forRequest((int) $request->id),
            'holding' => $this->settlements->holdingForRequest((int) $request->id),
            'can_start' => $this->mayStart($request, $user),
        ];
    }

    public function mayStart(RmeRefundRequest $request, ?User $user): bool
    {
        if ($user === null || ! $this->capabilityEnabled() || $request->status !== RmeRefundRequestStatus::APPROVED) {
            return false;
        }

        $account = $this->accounts->resolveUsable($user);

        return $account !== null
            && $this->isSettlerAccount($account)
            && in_array((int) $request->branch_id, $this->settlerBranchIds($account), true)
            && (int) $request->decided_by !== (int) $account->id
            && $this->settlements->holdingForRequest((int) $request->id) === null;
    }

    /**
     * Which actions this account may offer for a settlement (page controls come
     * from here, never from a role name in a view).
     *
     * @return array{confirm: bool, report_unknown: bool, abort: bool, view_evidence: bool}
     */
    public function actionsFor(RmeRefundSettlement $settlement, ?User $user): array
    {
        $none = ['confirm' => false, 'report_unknown' => false, 'abort' => false, 'view_evidence' => false];
        if ($user === null || ! $this->capabilityEnabled()) {
            return $none;
        }

        $account = $this->accounts->resolveUsable($user);
        if ($account === null || ! $this->mayView($account, (int) $settlement->branch_id)) {
            return $none;
        }

        $settles = $this->isSettlerAccount($account)
            && in_array((int) $settlement->branch_id, $this->settlerBranchIds($account), true)
            && (int) $settlement->approved_by !== (int) $account->id;
        $isStarter = (int) $settlement->started_by === (int) $account->id;

        return [
            'confirm' => $settles && (($settlement->status === RmeRefundSettlementStatus::DISBURSING && $isStarter)
                || $settlement->status === RmeRefundSettlementStatus::OUTCOME_UNKNOWN),
            'report_unknown' => $settles && $settlement->status === RmeRefundSettlementStatus::DISBURSING,
            // Only the person who made (or tried to make) the payment may declare that no
            // money left: anyone else could release a payout that was in fact sent.
            'abort' => $settles && $isStarter && in_array($settlement->status, RmeRefundSettlementStatus::ACTIVE, true),
            'view_evidence' => $settlement->hasEvidence(),
        ];
    }

    /**
     * The private evidence file of a settlement, for an account allowed to see the
     * settlement. Opening it is audited.
     *
     * @return array{path: string, mime: string, download_name: string}
     */
    public function evidenceFor(RmeRefundSettlement $settlement, User $user): array
    {
        $this->feature->assertEnabled();
        $account = $this->assertUsable($user, (int) $settlement->id, (int) $settlement->rme_refund_request_id);
        if (! $this->mayView($account, (int) $settlement->branch_id) || ! $settlement->hasEvidence()) {
            throw RmeRefundSettlementRefused::notFound((int) $settlement->rme_refund_request_id, (int) $settlement->id);
        }

        $this->auditLogs->log(
            RmeFinancialAuditEvent::ENTITY_REFUND_SETTLEMENT,
            (int) $settlement->id,
            RmeFinancialAuditEvent::ACTION_REFUND_SETTLEMENT_EVIDENCE_VIEWED,
            null,
            RmeFinancialAuditEvent::refundSettlementPayload($settlement, null),
            $account,
        );

        $extension = RmeRefundSettlementEvidence::MIMES[(string) $settlement->evidence_mime] ?? 'bin';

        return [
            'path' => (string) $settlement->evidence_path,
            'mime' => (string) $settlement->evidence_mime,
            'download_name' => 'bukti-'.Str::lower((string) ($settlement->settlement_number ?? $settlement->uuid)).'.'.$extension,
        ];
    }

    // ─── Start (claim) ──────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $data  settlement_intent, method, amount. Any branch,
     *                                      actor, approver or reference field is ignored.
     */
    public function start(RmeRefundRequest $request, User $settler, array $data): RmeRefundSettlement
    {
        return $this->audited('start', $settler, null, (int) $request->id, function () use ($request, $settler, $data) {
            $this->feature->assertEnabled();
            $account = $this->assertUsable($settler, null, (int) $request->id);
            $this->assertSettlerAccount($account, null, (int) $request->id);
            // Scope before any input check, so a refusal never reveals that an
            // out-of-scope request exists.
            if (! in_array((int) $request->branch_id, $this->settlerBranchIds($account), true)) {
                throw RmeRefundSettlementRefused::notFound((int) $request->id);
            }

            $method = $this->method($data['method'] ?? null, (int) $request->id);
            $declaredCents = $this->declaredCents($data['amount'] ?? null);
            if ($declaredCents === null) {
                throw $this->refused(RmeRefundSettlementRefused::AMOUNT_MISMATCH, 'Nominal pembayaran refund tidak sesuai dengan permintaan yang disetujui. Refund hanya dibayarkan penuh.', null, (int) $request->id, 'amount');
            }

            $intentKey = RmeRefundSettlementIntent::keyFrom($data[RmeRefundSettlementIntent::FIELD] ?? null, (int) $request->id);
            if ($intentKey === null) {
                throw $this->refused(RmeRefundSettlementRefused::INTENT_INVALID, 'Formulir pembayaran refund tidak valid atau sudah kedaluwarsa. Muat ulang halaman.', null, (int) $request->id, RmeRefundSettlementIntent::FIELD);
            }

            $fingerprint = RmeRefundSettlementIntent::fingerprint((int) $request->id, (int) $account->id, $method, $declaredCents);

            try {
                return DB::transaction(function () use ($request, $account, $method, $declaredCents, $intentKey, $fingerprint) {
                    $invoice = $this->invoices->findForUpdate((int) $request->rme_invoice_id);
                    $obligation = $this->obligations->findForUpdate((int) $request->rme_overpayment_obligation_id);
                    $locked = $this->refunds->findForUpdate((int) $request->id);
                    if ($invoice === null || $obligation === null || $locked === null) {
                        throw RmeRefundSettlementRefused::notFound((int) $request->id);
                    }

                    // The actor, re-read under the locks (linearization point).
                    $actor = $this->assertUsable($account, null, (int) $locked->id);
                    $this->assertSettlerAccount($actor, null, (int) $locked->id);
                    if (! in_array((int) $locked->branch_id, $this->settlerBranchIds($actor), true)) {
                        throw RmeRefundSettlementRefused::notFound((int) $locked->id);
                    }

                    // An exact repeat returns the original, whatever its state.
                    $replay = $this->settlements->findByIntentKey($intentKey);
                    if ($replay !== null) {
                        return $this->replayedStart($replay, $actor, $fingerprint, (int) $locked->id);
                    }

                    if ((int) $locked->decided_by === (int) $actor->id) {
                        throw $this->refused(RmeRefundSettlementRefused::SETTLER_IS_APPROVER, 'Penyetuju permintaan refund tidak dapat membayarkan refund yang ia setujui sendiri.', null, (int) $locked->id);
                    }

                    if ($locked->status !== RmeRefundRequestStatus::APPROVED) {
                        throw $this->refused(RmeRefundSettlementRefused::REQUEST_NOT_APPROVED, 'Hanya permintaan refund berstatus Disetujui yang dapat dibayarkan.', null, (int) $locked->id);
                    }

                    $holding = $this->settlements->holdingForRequest((int) $locked->id, lock: true)
                        ?? $this->settlements->holdingForObligation((int) $obligation->id, lock: true);
                    if ($holding !== null) {
                        throw $this->refused(RmeRefundSettlementRefused::SETTLEMENT_EXISTS, 'Permintaan refund ini sudah memiliki pembayaran yang sedang diproses atau sudah dibayarkan.', (int) $holding->id, (int) $locked->id);
                    }

                    $this->assertEligible($invoice, $obligation, $locked, null);

                    $amountCents = RmeRefundRequestSnapshot::cents($locked->amount);
                    if ($declaredCents !== $amountCents) {
                        throw $this->refused(RmeRefundSettlementRefused::AMOUNT_MISMATCH, 'Nominal pembayaran refund tidak sesuai dengan permintaan yang disetujui. Refund hanya dibayarkan penuh.', null, (int) $locked->id, 'amount');
                    }

                    $settlement = $this->settlements->create([
                        'uuid' => (string) Str::uuid(),
                        'branch_id' => (int) $locked->branch_id,
                        'rme_invoice_id' => (int) $invoice->id,
                        'rme_overpayment_obligation_id' => (int) $obligation->id,
                        'rme_refund_request_id' => (int) $locked->id,
                        'amount' => RmeRefundRequestSnapshot::decimal($amountCents),
                        'method' => $method,
                        'status' => RmeRefundSettlementStatus::DISBURSING,
                        'intent_key' => $intentKey,
                        'request_fingerprint' => $fingerprint,
                        'approved_by' => (int) $locked->decided_by,
                        'started_by' => (int) $actor->id,
                        'started_at' => now(),
                    ]);

                    $settlement = $this->settlements->assignNumber(
                        $settlement,
                        'RMESTL-'.$this->clock->now()->format('Ym').'-'.str_pad((string) $settlement->id, 6, '0', STR_PAD_LEFT),
                    );

                    $this->recordSettlementTransition($settlement, null, $actor);

                    return $settlement;
                });
            } catch (QueryException $e) {
                if (! $this->isUniqueViolation($e)) {
                    throw $e;
                }

                $message = $e->getMessage();
                if (str_contains($message, 'trx_rme_refund_stl_intent_uq') || str_contains($message, 'intent_key')) {
                    $replay = $this->settlements->findByIntentKey($intentKey);
                    if ($replay !== null) {
                        return $this->replayedStart($replay, $account, $fingerprint, (int) $request->id);
                    }
                }

                if (str_contains($message, 'trx_rme_refund_stl_one_per_request_uq') || str_contains($message, 'trx_rme_refund_stl_one_per_obligation_uq')) {
                    throw $this->refused(RmeRefundSettlementRefused::SETTLEMENT_EXISTS, 'Permintaan refund ini sudah memiliki pembayaran yang sedang diproses atau sudah dibayarkan.', null, (int) $request->id);
                }

                throw $e;
            }
        });
    }

    // ─── Confirm (verified money out) ──────────────────────────────────────

    /**
     * @param  array<string, mixed>  $data  disbursement_reference, attest_disbursed, and per
     *                                      method destination_bank_name + destination_account_last4
     *                                      (bank) or recipient_relation (cash)
     */
    public function confirm(RmeRefundSettlement $settlement, User $settler, array $data, ?UploadedFile $evidence): RmeRefundSettlement
    {
        return $this->audited('confirm', $settler, (int) $settlement->id, (int) $settlement->rme_refund_request_id, function () use ($settlement, $settler, $data, $evidence) {
            $this->feature->assertEnabled();
            $account = $this->assertUsable($settler, (int) $settlement->id, (int) $settlement->rme_refund_request_id);
            $this->assertSettlerAccount($account, (int) $settlement->id, (int) $settlement->rme_refund_request_id);
            $this->assertVisibleToSettler($account, $settlement);

            $ids = [(int) $settlement->id, (int) $settlement->rme_refund_request_id];
            $reference = $this->reference($data['disbursement_reference'] ?? null, ...$ids);
            $destination = $this->destination((string) $settlement->method, $data, ...$ids);
            if (! $this->accepted($data['attest_disbursed'] ?? null)) {
                throw $this->refused(RmeRefundSettlementRefused::ATTESTATION_REQUIRED, 'Konfirmasikan bahwa Anda telah memverifikasi uang benar-benar keluar (mutasi bank / tanda terima bertanda tangan).', ...$ids, field: 'attest_disbursed');
            }
            $facts = $this->evidenceFacts($evidence, ...$ids);

            $stored = null;

            try {
                return DB::transaction(function () use ($settlement, $account, $reference, $destination, $facts, $evidence, &$stored) {
                    [$invoice, $obligation, $request, $locked, $actor] = $this->lockSettlement($settlement, $account);

                    // An exact repeat after commit is a no-op returning the settled row.
                    if ($locked->status === RmeRefundSettlementStatus::SETTLED
                        && (int) $locked->settled_by === (int) $actor->id
                        && hash_equals((string) $locked->evidence_sha256, $facts['sha256'])
                        && (string) $locked->disbursement_reference_key === self::referenceKey($reference)) {
                        return $locked;
                    }

                    $this->assertCanMove($locked, RmeRefundSettlementStatus::SETTLED, $actor, (int) $request->id);
                    $this->assertEligible($invoice, $obligation, $request, (int) $locked->id);

                    if ($this->settlements->referenceSettled(self::referenceKey($reference))) {
                        throw $this->referenceReused((int) $locked->id, (int) $request->id);
                    }
                    if ($this->settlements->evidenceUsed($facts['sha256'])) {
                        throw $this->evidenceReused((int) $locked->id, (int) $request->id);
                    }

                    // The file is written last, after every check; a failure below rolls
                    // the rows back and the catch removes the file.
                    $stored = RmeRefundSettlementEvidence::store((string) $locked->uuid, $evidence, $facts);
                    $at = now();

                    $from = (string) $locked->status;
                    $locked = $this->settlements->transition($locked, array_merge([
                        'status' => RmeRefundSettlementStatus::SETTLED,
                        'settled_by' => (int) $actor->id,
                        'settled_at' => $at,
                        'settler_attested_at' => $at,
                        'disbursement_reference' => $reference,
                        'disbursement_reference_key' => self::referenceKey($reference),
                        'evidence_path' => $stored,
                        'evidence_sha256' => $facts['sha256'],
                        'evidence_mime' => $facts['mime'],
                        'evidence_size_bytes' => $facts['size'],
                    ], $destination));
                    $this->recordSettlementTransition($locked, $from, $actor);

                    // RFS-4 — the obligation and the request move in the same transaction.
                    $obligation = $this->obligations->markSettled($obligation, (int) $locked->id, $at);
                    $this->recordObligationSettled($obligation, $locked, $actor);

                    $requestFrom = (string) $request->status;
                    $request = $this->refunds->transition($request, ['status' => RmeRefundRequestStatus::SETTLED]);
                    $this->recordRequestTransition($request, $requestFrom, $actor, (int) $locked->id);

                    $this->assertBalancedAfterSettlement($invoice, (int) $locked->id);

                    return $locked;
                });
            } catch (Throwable $e) {
                RmeRefundSettlementEvidence::discard($stored);

                if ($e instanceof QueryException && $this->isUniqueViolation($e)) {
                    if (str_contains($e->getMessage(), 'trx_rme_refund_stl_reference_uq')) {
                        throw $this->referenceReused((int) $settlement->id, (int) $settlement->rme_refund_request_id);
                    }
                    if (str_contains($e->getMessage(), 'trx_rme_refund_stl_evidence_uq')) {
                        throw $this->evidenceReused((int) $settlement->id, (int) $settlement->rme_refund_request_id);
                    }
                }

                throw $e;
            }
        });
    }

    // ─── Outcome unknown (reconciliation required) ─────────────────────────

    /** @param  array<string, mixed>  $data  outcome_note (required, 10–1000) */
    public function reportUnknown(RmeRefundSettlement $settlement, User $settler, array $data): RmeRefundSettlement
    {
        return $this->audited('report_unknown', $settler, (int) $settlement->id, (int) $settlement->rme_refund_request_id, function () use ($settlement, $settler, $data) {
            $this->feature->assertEnabled();
            $account = $this->assertUsable($settler, (int) $settlement->id, (int) $settlement->rme_refund_request_id);
            $this->assertSettlerAccount($account, (int) $settlement->id, (int) $settlement->rme_refund_request_id);
            $this->assertVisibleToSettler($account, $settlement);
            $note = $this->note($data['outcome_note'] ?? null, 'outcome_note', (int) $settlement->id, (int) $settlement->rme_refund_request_id);

            return DB::transaction(function () use ($settlement, $account, $note) {
                [, , $request, $locked, $actor] = $this->lockSettlement($settlement, $account);

                if ($locked->status === RmeRefundSettlementStatus::OUTCOME_UNKNOWN && (int) $locked->outcome_unknown_by === (int) $actor->id) {
                    return $locked;
                }

                $this->assertCanMove($locked, RmeRefundSettlementStatus::OUTCOME_UNKNOWN, $actor, (int) $request->id);

                $from = (string) $locked->status;
                $locked = $this->settlements->transition($locked, [
                    'status' => RmeRefundSettlementStatus::OUTCOME_UNKNOWN,
                    'outcome_unknown_by' => (int) $actor->id,
                    'outcome_unknown_at' => now(),
                    'outcome_note' => $note,
                ]);
                $this->recordSettlementTransition($locked, $from, $actor);

                return $locked;
            });
        });
    }

    // ─── Abort (no money left) ──────────────────────────────────────────────

    /** @param  array<string, mixed>  $data  abort_reason (10–1000), attest_not_disbursed */
    public function abort(RmeRefundSettlement $settlement, User $settler, array $data, ?UploadedFile $evidence = null): RmeRefundSettlement
    {
        return $this->audited('abort', $settler, (int) $settlement->id, (int) $settlement->rme_refund_request_id, function () use ($settlement, $settler, $data, $evidence) {
            $this->feature->assertEnabled();
            $account = $this->assertUsable($settler, (int) $settlement->id, (int) $settlement->rme_refund_request_id);
            $this->assertSettlerAccount($account, (int) $settlement->id, (int) $settlement->rme_refund_request_id);
            $this->assertVisibleToSettler($account, $settlement);

            $ids = [(int) $settlement->id, (int) $settlement->rme_refund_request_id];
            if (! $this->accepted($data['attest_not_disbursed'] ?? null)) {
                throw $this->refused(RmeRefundSettlementRefused::ATTESTATION_REQUIRED, 'Konfirmasikan bahwa tidak ada uang yang keluar untuk pembayaran ini.', ...$ids, field: 'attest_not_disbursed');
            }
            $reason = $this->note($data['abort_reason'] ?? null, 'abort_reason', ...$ids);
            $facts = $evidence !== null ? $this->evidenceFacts($evidence, ...$ids) : null;

            $stored = null;

            try {
                return DB::transaction(function () use ($settlement, $account, $reason, $facts, $evidence, &$stored) {
                    [, , $request, $locked, $actor] = $this->lockSettlement($settlement, $account);

                    if ($locked->status === RmeRefundSettlementStatus::ABORTED && (int) $locked->aborted_by === (int) $actor->id) {
                        return $locked;
                    }

                    $this->assertCanMove($locked, RmeRefundSettlementStatus::ABORTED, $actor, (int) $request->id);

                    // Releasing an UNKNOWN result as "no money left" is a reconciliation:
                    // it needs the bank's proof, never only a statement.
                    if ($locked->status === RmeRefundSettlementStatus::OUTCOME_UNKNOWN && $facts === null) {
                        throw $this->refused(RmeRefundSettlementRefused::EVIDENCE_REQUIRED, 'Hasil pembayaran yang tidak pasti hanya dapat dibatalkan dengan bukti bahwa dana tidak keluar (mis. mutasi bank / penolakan transfer).', (int) $locked->id, (int) $request->id, 'evidence');
                    }

                    $evidenceColumns = [];
                    if ($facts !== null) {
                        if ($this->settlements->evidenceUsed($facts['sha256'])) {
                            throw $this->evidenceReused((int) $locked->id, (int) $request->id);
                        }
                        $stored = RmeRefundSettlementEvidence::store((string) $locked->uuid, $evidence, $facts);
                        $evidenceColumns = [
                            'evidence_path' => $stored,
                            'evidence_sha256' => $facts['sha256'],
                            'evidence_mime' => $facts['mime'],
                            'evidence_size_bytes' => $facts['size'],
                        ];
                    }

                    $from = (string) $locked->status;
                    $locked = $this->settlements->transition($locked, array_merge([
                        'status' => RmeRefundSettlementStatus::ABORTED,
                        'aborted_by' => (int) $actor->id,
                        'aborted_at' => now(),
                        'abort_reason' => $reason,
                    ], $evidenceColumns));
                    $this->recordSettlementTransition($locked, $from, $actor);

                    // The request stays APPROVED and the obligation OPEN: nothing was paid.
                    return $locked;
                });
            } catch (Throwable $e) {
                RmeRefundSettlementEvidence::discard($stored);

                throw $e;
            }
        });
    }

    // ─── Authority ──────────────────────────────────────────────────────────

    /**
     * The one definition of an account that may settle anywhere: holds the
     * permission, is designated, and is not a read-only oversight role.
     */
    public function isSettlerAccount(User $account): bool
    {
        return $account->can(self::PERMISSION_SETTLE)
            && ! $account->hasRole(self::NEVER_SETTLER_ROLES)
            && $this->pilot->isDesignated((int) $account->id);
    }

    /** @return list<int> */
    public function settlerBranchIds(User $account): array
    {
        if (! $this->isSettlerAccount($account)) {
            return [];
        }

        return $this->pilot->branchIdsFor((int) $account->id);
    }

    /** @return list<int> */
    private function visibleBranchIds(User $user): array
    {
        $account = $this->accounts->resolveUsable($user);
        if ($account === null) {
            return [];
        }

        $ids = array_values(array_unique(array_merge(
            $this->settlerBranchIds($account),
            $this->refundRequests->reviewerBranchIds($account),
        )));
        sort($ids);

        return $ids;
    }

    private function assertSettlerAccount(User $account, ?int $settlementId, ?int $requestId): void
    {
        if (! $this->isSettlerAccount($account) || $this->settlerBranchIds($account) === []) {
            throw $this->refused(RmeRefundSettlementRefused::SETTLER_NOT_AUTHORIZED, 'Akun Anda tidak ditunjuk untuk membayarkan refund.', $settlementId, $requestId);
        }
    }

    /** A read-only, pre-lock scope check: out of the settler's branches is "not found". */
    private function assertVisibleToSettler(User $account, RmeRefundSettlement $settlement): void
    {
        if (! in_array((int) $settlement->branch_id, $this->settlerBranchIds($account), true)) {
            throw RmeRefundSettlementRefused::notFound((int) $settlement->rme_refund_request_id, (int) $settlement->id);
        }
    }

    private function assertUsable(User $actor, ?int $settlementId, ?int $requestId): User
    {
        $account = $this->accounts->resolveUsable($actor);
        if ($account === null) {
            throw $this->refused(RmeRefundSettlementRefused::ACTOR_UNUSABLE, 'Akun ini tidak aktif atau tidak dikenali.', $settlementId, $requestId);
        }

        return $account;
    }

    /**
     * Can this actor move this locked settlement to $to? The edge must exist; the
     * actor is never the approver (RF-10); a DISBURSING claim is confirmed or aborted
     * only by its starter.
     */
    private function assertCanMove(RmeRefundSettlement $locked, string $to, User $actor, int $requestId): void
    {
        if (! RmeRefundSettlementStatus::canTransition((string) $locked->status, $to)) {
            throw $this->refused(RmeRefundSettlementRefused::INVALID_TRANSITION, 'Status pembayaran refund ini tidak dapat diubah lagi ('.RmeRefundSettlementStatus::label($locked->status).').', (int) $locked->id, $requestId);
        }

        if ((int) $locked->approved_by === (int) $actor->id) {
            throw $this->refused(RmeRefundSettlementRefused::SETTLER_IS_APPROVER, 'Penyetuju permintaan refund tidak dapat membayarkan refund yang ia setujui sendiri.', (int) $locked->id, $requestId);
        }

        // Confirming from DISBURSING, and aborting from ANY state, belong to the settler
        // who started: they made (or tried to make) the transfer. Another settler can
        // only escalate to OUTCOME_UNKNOWN and, from there, CONFIRM with the bank's
        // proof — never declare "no money left", which would release the request for
        // a second payout of money that was in fact sent.
        $starterOnly = $to === RmeRefundSettlementStatus::ABORTED
            || ($to === RmeRefundSettlementStatus::SETTLED && $locked->status === RmeRefundSettlementStatus::DISBURSING);
        if ($starterOnly && (int) $locked->started_by !== (int) $actor->id) {
            throw $this->refused(RmeRefundSettlementRefused::NOT_STARTER, 'Hanya pembayar yang memulai pembayaran ini yang dapat mengonfirmasi atau membatalkannya. Laporkan sebagai "hasil tidak pasti" bila perlu ditindaklanjuti orang lain.', (int) $locked->id, $requestId);
        }
    }

    // ─── Locks, eligibility ────────────────────────────────────────────────

    /**
     * invoice → obligation → refund request → settlement, then the actor.
     *
     * @return array{0: RmeInvoice, 1: RmeOverpaymentObligation, 2: RmeRefundRequest, 3: RmeRefundSettlement, 4: User}
     */
    private function lockSettlement(RmeRefundSettlement $settlement, User $account): array
    {
        $invoice = $this->invoices->findForUpdate((int) $settlement->rme_invoice_id);
        $obligation = $this->obligations->findForUpdate((int) $settlement->rme_overpayment_obligation_id);
        $request = $this->refunds->findForUpdate((int) $settlement->rme_refund_request_id);
        $locked = $this->settlements->findForUpdate((int) $settlement->id);

        if ($invoice === null || $obligation === null || $request === null || $locked === null) {
            throw RmeRefundSettlementRefused::notFound((int) $settlement->rme_refund_request_id, (int) $settlement->id);
        }

        $actor = $this->assertUsable($account, (int) $locked->id, (int) $request->id);
        $this->assertSettlerAccount($actor, (int) $locked->id, (int) $request->id);
        if (! in_array((int) $locked->branch_id, $this->settlerBranchIds($actor), true)) {
            throw RmeRefundSettlementRefused::notFound((int) $request->id, (int) $locked->id);
        }

        if ((int) $locked->rme_invoice_id !== (int) $invoice->id
            || (int) $locked->rme_overpayment_obligation_id !== (int) $obligation->id
            || (int) $locked->rme_refund_request_id !== (int) $request->id
            || (int) $locked->branch_id !== (int) $invoice->branch_id) {
            throw new LogicException(sprintf('RME refund settlement #%d is bound inconsistently.', (int) $locked->id));
        }

        return [$invoice, $obligation, $request, $locked, $actor];
    }

    /** The approved request still describes the invoice's money, read on the locked rows. */
    private function assertEligible(RmeInvoice $invoice, RmeOverpaymentObligation $obligation, RmeRefundRequest $request, ?int $settlementId): void
    {
        $requestId = (int) $request->id;

        if ($obligation->status !== RmeOverpaymentObligation::STATUS_OPEN) {
            throw $this->refused(RmeRefundSettlementRefused::OBLIGATION_NOT_OPEN, 'Kewajiban pengembalian ini tidak lagi terbuka.', $settlementId, $requestId);
        }

        $stale = (int) $request->rme_invoice_id !== (int) $invoice->id
            || (int) $request->rme_overpayment_obligation_id !== (int) $obligation->id
            || (int) $obligation->rme_invoice_id !== (int) $invoice->id
            || (int) $request->branch_id !== (int) $invoice->branch_id
            || (int) $obligation->branch_id !== (int) $invoice->branch_id
            || $invoice->status !== RmeInvoice::STATUS_PAID
            || (int) ($invoice->correction_version ?? 0) !== (int) $obligation->invoice_version
            || (int) ($invoice->correction_version ?? 0) !== (int) $request->invoice_version
            || RmeRefundRequestSnapshot::cents($request->amount) !== RmeRefundRequestSnapshot::cents($obligation->amount)
            || RmeInvoiceLedger::overpaidCents(RmeInvoiceLedger::grandTotalCents($invoice), RmeInvoiceLedger::netReceivedCents($invoice))
                !== RmeRefundRequestSnapshot::cents($obligation->amount)
            || ! hash_equals((string) $request->request_snapshot_sha256, RmeRefundRequestSnapshot::hash(RmeRefundRequestSnapshot::capture($invoice, $obligation)));

        if ($stale) {
            throw $this->refused(RmeRefundSettlementRefused::REQUEST_STALE, 'Keadaan tagihan atau kewajiban pengembalian tidak lagi sesuai dengan permintaan yang disetujui. Refund tidak dibayarkan; laporkan ke penyetuju.', $settlementId, $requestId);
        }

        if ($this->corrections->activeForInvoice((int) $invoice->id) !== null) {
            throw $this->refused(RmeRefundSettlementRefused::CORRECTION_ACTIVE, 'Tagihan ini sedang dalam proses koreksi.', $settlementId, $requestId);
        }

        // RF-7 analogue: no settlement is recorded while the invoice is disputed. A
        // claim already DISBURSING stays held (no second payout, no revocation) until
        // the dispute is resolved.
        if ($this->disputes->activeForInvoice((int) $invoice->id) !== null) {
            throw $this->refused(RmeRefundSettlementRefused::INVOICE_DISPUTED, 'Tagihan ini sedang dalam sengketa tagihan. Selesaikan sengketanya terlebih dahulu; pembayaran yang sedang diproses tetap tertahan.', $settlementId, $requestId);
        }
    }

    /** RFS-2/RFS-4 post-condition: after a settlement the invoice balances and stays PAID. */
    private function assertBalancedAfterSettlement(RmeInvoice $invoice, int $settlementId): void
    {
        $grand = RmeInvoiceLedger::grandTotalCents($invoice);
        $net = RmeInvoiceLedger::netReceivedCents($invoice);

        if (RmeInvoiceLedger::overpaidCents($grand, $net) !== 0
            || RmeInvoiceLedger::outstandingCents($grand, $net) !== 0
            || RmeInvoiceLedger::statusAfterMoneyReceived($grand, $net) !== RmeInvoice::STATUS_PAID
            || $invoice->status !== RmeInvoice::STATUS_PAID) {
            throw new LogicException(sprintf('RME refund settlement #%d would leave its invoice unbalanced.', $settlementId));
        }
    }

    private function replayedStart(RmeRefundSettlement $existing, User $actor, string $fingerprint, int $requestId): RmeRefundSettlement
    {
        if ((int) $existing->started_by === (int) $actor->id && hash_equals((string) $existing->request_fingerprint, $fingerprint)) {
            return $existing;
        }

        throw $this->refused(RmeRefundSettlementRefused::INTENT_REUSED, 'Formulir ini sudah dipakai untuk pembayaran lain. Muat ulang halaman.', (int) $existing->id, $requestId, RmeRefundSettlementIntent::FIELD);
    }

    // ─── Input ──────────────────────────────────────────────────────────────

    private function method(mixed $value, int $requestId): string
    {
        if (! is_string($value) || ! in_array($value, RmeRefundSettlementMethod::ALL, true)) {
            throw $this->refused(RmeRefundSettlementRefused::METHOD_INVALID, 'Metode pembayaran refund hanya Transfer Bank atau Tunai.', null, $requestId, 'method');
        }

        return $value;
    }

    private function reference(mixed $value, int $settlementId, int $requestId): string
    {
        $text = is_string($value) ? preg_replace('/\s+/u', ' ', trim($value)) : null;
        if ($text === null || $text === '') {
            throw $this->refused(RmeRefundSettlementRefused::REFERENCE_REQUIRED, 'Nomor referensi transfer bank / nomor tanda terima wajib diisi.', $settlementId, $requestId, 'disbursement_reference');
        }

        // A value that is a 16-digit number once separators are removed is shaped
        // like a NIK: refused, so a patient identity can never land here.
        $digitsOnly = preg_replace('/[^A-Za-z0-9]/', '', $text) ?? '';
        if (preg_match(self::REFERENCE_PATTERN, $text) !== 1 || preg_match('/^\d{16}$/D', $digitsOnly) === 1) {
            throw $this->refused(RmeRefundSettlementRefused::REFERENCE_INVALID, 'Format nomor referensi tidak valid (6–60 karakter: huruf, angka, spasi, . _ : / # -). Jangan memasukkan NIK; bila nomor referensi bank tepat 16 digit, awali dengan kode bank (mis. BRI-...).', $settlementId, $requestId, 'disbursement_reference');
        }

        // One payout proves one refund, however the reference is spelled: the key is
        // the reference's letters and digits only, upper-cased, and is unique across
        // methods among SETTLED rows. It must keep at least six of them.
        if (strlen(self::referenceKey($text)) < 6) {
            throw $this->refused(RmeRefundSettlementRefused::REFERENCE_INVALID, 'Nomor referensi harus memuat sedikitnya 6 huruf atau angka.', $settlementId, $requestId, 'disbursement_reference');
        }

        return $text;
    }

    /** The comparison key of a disbursement reference: letters and digits only, upper case. */
    public static function referenceKey(string $reference): string
    {
        return strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $reference));
    }

    /** @return array<string, string|null> the method's destination columns */
    private function destination(string $method, array $data, int $settlementId, int $requestId): array
    {
        if ($method === RmeRefundSettlementMethod::BANK_TRANSFER) {
            $bank = is_string($data['destination_bank_name'] ?? null) ? trim((string) $data['destination_bank_name']) : '';
            $last4 = is_string($data['destination_account_last4'] ?? null) ? trim((string) $data['destination_account_last4']) : '';

            if (preg_match(self::BANK_NAME_PATTERN, $bank) !== 1 || preg_match('/^\d{4}$/D', $last4) !== 1) {
                throw $this->refused(RmeRefundSettlementRefused::DESTINATION_INVALID, 'Transfer bank memerlukan nama bank tujuan dan EMPAT digit terakhir rekening tujuan (bukan nomor rekening lengkap).', $settlementId, $requestId, 'destination_account_last4');
            }

            return ['destination_bank_name' => $bank, 'destination_account_last4' => $last4, 'recipient_relation' => null];
        }

        $relation = $data['recipient_relation'] ?? null;
        if (! is_string($relation) || ! in_array($relation, RmeRefundSettlementMethod::RECIPIENTS, true)) {
            throw $this->refused(RmeRefundSettlementRefused::RECIPIENT_INVALID, 'Pembayaran tunai memerlukan penerima: pasien sendiri atau wakil yang dikuasakan pasien.', $settlementId, $requestId, 'recipient_relation');
        }

        return ['destination_bank_name' => null, 'destination_account_last4' => null, 'recipient_relation' => $relation];
    }

    /** @return array{mime: string, extension: string, sha256: string, size: int} */
    private function evidenceFacts(?UploadedFile $evidence, int $settlementId, int $requestId): array
    {
        if ($evidence === null) {
            throw $this->refused(RmeRefundSettlementRefused::EVIDENCE_REQUIRED, 'Bukti pembayaran wajib diunggah (bukti transfer bank atau tanda terima tunai bertanda tangan).', $settlementId, $requestId, 'evidence');
        }

        $facts = RmeRefundSettlementEvidence::inspect($evidence);
        if ($facts === null) {
            throw $this->refused(RmeRefundSettlementRefused::EVIDENCE_INVALID, 'Bukti pembayaran harus berkas PDF, JPEG, atau PNG yang valid, tanpa konten aktif, maksimal '.intdiv(RmeRefundSettlementEvidence::maxBytes(), 1024).' KB.', $settlementId, $requestId, 'evidence');
        }

        return $facts;
    }

    private function note(mixed $value, string $field, int $settlementId, int $requestId): string
    {
        $text = is_string($value) ? trim($value) : '';
        $length = mb_strlen($text);

        if ($length < self::NOTE_MIN || $length > self::NOTE_MAX) {
            throw $this->refused(RmeRefundSettlementRefused::REASON_REQUIRED, 'Alasan / catatan wajib diisi, '.self::NOTE_MIN.'–'.self::NOTE_MAX.' karakter.', $settlementId, $requestId, $field);
        }

        return $text;
    }

    private function accepted(mixed $value): bool
    {
        return in_array($value, [true, 1, '1', 'on', 'yes', 'true'], true);
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

    private function referenceReused(int $settlementId, int $requestId): RmeRefundSettlementRefused
    {
        return $this->refused(RmeRefundSettlementRefused::REFERENCE_REUSED, 'Nomor referensi ini sudah dipakai untuk pembayaran refund lain. Satu transfer / satu tanda terima hanya membuktikan satu refund.', $settlementId, $requestId, 'disbursement_reference');
    }

    private function evidenceReused(int $settlementId, int $requestId): RmeRefundSettlementRefused
    {
        return $this->refused(RmeRefundSettlementRefused::EVIDENCE_REUSED, 'Berkas bukti ini sudah dipakai untuk pembayaran refund lain.', $settlementId, $requestId, 'evidence');
    }

    // ─── Audit ──────────────────────────────────────────────────────────────

    private function recordSettlementTransition(RmeRefundSettlement $settlement, ?string $from, User $actor): void
    {
        $payload = RmeFinancialAuditEvent::refundSettlementPayload($settlement, $from);

        $this->settlements->recordEvent([
            'rme_refund_settlement_id' => (int) $settlement->id,
            'from_status' => $from,
            'to_status' => (string) $settlement->status,
            'actor_id' => (int) $actor->id,
            'occurred_at' => now(),
            'metadata' => $payload,
        ]);

        $this->auditLogs->log(
            RmeFinancialAuditEvent::ENTITY_REFUND_SETTLEMENT,
            (int) $settlement->id,
            RmeFinancialAuditEvent::REFUND_SETTLEMENT_ACTIONS[(string) $settlement->status],
            $from !== null ? ['status' => $from] : null,
            $payload,
            $actor,
        );
    }

    private function recordRequestTransition(RmeRefundRequest $request, string $from, User $actor, int $settlementId): void
    {
        $payload = RmeFinancialAuditEvent::refundRequestPayload($request, $from, ['refund_settlement_id' => $settlementId]);

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
            ['status' => $from],
            $payload,
            $actor,
        );
    }

    private function recordObligationSettled(RmeOverpaymentObligation $obligation, RmeRefundSettlement $settlement, User $actor): void
    {
        $payload = array_merge(RmeFinancialAuditEvent::overpaymentObligationPayload($obligation), [
            'refund_settlement_id' => (int) $settlement->id,
            'refund_request_id' => (int) $settlement->rme_refund_request_id,
        ]);

        $this->obligations->recordEvent([
            'rme_overpayment_obligation_id' => (int) $obligation->id,
            'from_status' => RmeOverpaymentObligation::STATUS_OPEN,
            'to_status' => RmeOverpaymentObligation::STATUS_SETTLED,
            'actor_id' => (int) $actor->id,
            'occurred_at' => now(),
            'metadata' => $payload,
        ]);

        $this->auditLogs->log(
            RmeFinancialAuditEvent::ENTITY_OVERPAYMENT_OBLIGATION,
            (int) $obligation->id,
            RmeFinancialAuditEvent::ACTION_OVERPAYMENT_OBLIGATION_SETTLED,
            ['status' => RmeOverpaymentObligation::STATUS_OPEN],
            $payload,
            $actor,
        );
    }

    /**
     * Runs a mutation; a refusal is recorded with its code after the transaction
     * rolled back, then rethrown.
     *
     * @template T
     *
     * @param  Closure(): T  $work
     * @return T
     */
    private function audited(string $action, User $actor, ?int $settlementId, ?int $requestId, Closure $work): mixed
    {
        try {
            return $work();
        } catch (RmeRefundSettlementRefused $refused) {
            $this->auditLogs->log(
                RmeFinancialAuditEvent::ENTITY_REFUND_SETTLEMENT,
                $refused->settlementId ?? $settlementId,
                RmeFinancialAuditEvent::ACTION_REFUND_SETTLEMENT_REFUSED,
                null,
                [
                    'attempted_action' => $action,
                    'code' => $refused->reasonCode,
                    'refund_settlement_id' => $refused->settlementId ?? $settlementId,
                    'refund_request_id' => $refused->refundRequestId ?? $requestId,
                ],
                $actor,
            );

            throw $refused;
        } catch (QueryException $e) {
            // The bindings of a failed write hold the bank reference, bank name, the
            // last four digits and free text. Replace the exception (no "previous", so
            // the logger never prints the original) with SQLSTATE and ids only.
            throw new RuntimeException(sprintf(
                'Refund settlement %s failed in the database (SQLSTATE %s; settlement %s; request %s).',
                $action,
                (string) ($e->errorInfo[0] ?? 'unknown'),
                $settlementId === null ? '-' : (string) $settlementId,
                $requestId === null ? '-' : (string) $requestId,
            ));
        }
    }

    private function refused(string $code, string $message, ?int $settlementId, ?int $requestId, string $field = 'settlement'): RmeRefundSettlementRefused
    {
        return new RmeRefundSettlementRefused($code, $message, false, $settlementId, $requestId, $field);
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        return in_array((string) ($e->errorInfo[0] ?? $e->getCode()), ['23505', '23000'], true);
    }
}
