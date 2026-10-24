<?php

declare(strict_types=1);

namespace App\Modules\RmeInvoice\Services;

use App\Models\User;
use App\Modules\Branch\Services\BranchService;
use App\Modules\LabOrder\Services\AuditLogService;
use App\Modules\RmeInvoice\Interfaces\RmeBillingDisputeRepositoryInterface;
use App\Modules\RmeInvoice\Interfaces\RmeInvoiceCorrectionRepositoryInterface;
use App\Modules\RmeInvoice\Interfaces\RmeInvoiceRepositoryInterface;
use App\Modules\RmeInvoice\Models\RmeBillingDispute;
use App\Modules\RmeInvoice\Models\RmeBillingDisputeEvent;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use App\Modules\RmeInvoice\Support\RmeBillingDisputeAuditEvent;
use App\Modules\RmeInvoice\Support\RmeBillingDisputeDenied;
use App\Modules\RmeInvoice\Support\RmeBillingDisputeOutcome;
use App\Modules\RmeInvoice\Support\RmeBillingDisputePermission;
use App\Modules\RmeInvoice\Support\RmeBillingDisputeReason;
use App\Modules\RmeInvoice\Support\RmeBillingDisputeStatus;
use App\Modules\RmeInvoice\Support\RmeBillingDisputeText;
use App\Modules\RmeOnlineContext\Services\RmeWorkingBranchScope;
use Closure;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * IMPLEMENTATION-RME-BILLING-DISPUTE-ESCALATION-OPS-OQ-1 — owner decision OPS-OQ-1
 * (rule 203), ALL branches:
 *
 *   1. An amount known to be wrong is not collected at that amount.
 *   2. Nobody changes the invoice unilaterally.
 *   3. The case is escalated to the responsible branch authority.
 *   4. The invoice stays financially unresolved until a legitimate, authorized
 *      resolution — never marked PAID, CANCELLED or Rp0 because of a dispute.
 *   5. The patient is not held to finish the correction pilot.
 *
 * WHAT THIS SERVICE NEVER DOES: write an invoice, item, payment, correction,
 * document version, receivable, visit or medical record. A dispute is a
 * separate record whose only financial effect is the payment HOLD that
 * RmePaymentService enforces while the dispute is ACTIVE.
 *
 * Every mutation is decided in the SERVICE, so a command, a job or a future
 * caller cannot skip it:
 *  - LOCK ORDER: the INVOICE row first (the row every payment path and every
 *    correction transition locks), then the dispute row. One invoice lock per
 *    operation, so no lock cycle is introduced against payment batches.
 *  - LINEARIZATION POINT: the actor is re-read from the database AFTER the
 *    invoice lock is held (rule 200/201). A deactivation committed before that
 *    read refuses the mutation; one still uncommitted at it orders after it.
 *  - The permission and the branch scope are asked of the PERSISTED account,
 *    never of the object passed in, and the branch is the invoice's own branch,
 *    never a request field. Super Admin's Gate::before does not reach any of it.
 */
class RmeBillingDisputeService
{
    public const TEXT_MIN = RmeBillingDisputeText::MIN;

    public const TEXT_MAX = RmeBillingDisputeText::MAX;

    public const REFERENCE_MIN = 6;

    public const REFERENCE_MAX = 120;

    /** Same shape as the Owner approval reference (rule 202 §21). */
    private const REFERENCE_PATTERN = '/^[A-Za-z0-9._:\/-]+$/';

    /** Invoice statuses a dispute may be reported against. */
    public const REPORTABLE_INVOICE_STATUSES = [
        RmeInvoice::STATUS_UNPAID,
        RmeInvoice::STATUS_PARTIAL,
        RmeInvoice::STATUS_PAID,
    ];

    public function __construct(
        private readonly RmeBillingDisputeRepositoryInterface $disputes,
        private readonly RmeInvoiceRepositoryInterface $invoices,
        private readonly RmeInvoiceCorrectionRepositoryInterface $corrections,
        private readonly RmeBillingActorGuard $actors,
        private readonly RmeWorkingBranchScope $workingBranchScope,
        private readonly BranchService $branches,
        private readonly AuditLogService $auditLogs,
        private readonly RmeBillingDisputeEscalationService $escalation,
        private readonly RmeBillingDisputeReviewerService $reviewers,
    ) {}

    // ─── Reads ───────────────────────────────────────────────────────────────

    /** The active dispute of an invoice (display only — the payment service decides under the lock). */
    public function activeFor(RmeInvoice $invoice): ?RmeBillingDispute
    {
        return $this->disputes->activeForInvoice((int) $invoice->id);
    }

    public function find(int $id): ?RmeBillingDispute
    {
        return $this->disputes->find($id);
    }

    /**
     * The dispute panel of the cashier invoice page. One query (the invoice's
     * disputes); whether the viewer may report is the policy's answer.
     *
     * @return array{active: ?RmeBillingDispute, history: Collection<int, RmeBillingDispute>, can_report: bool}
     */
    public function panelFor(RmeInvoice $invoice, ?User $user): array
    {
        $all = $this->disputes->forInvoice((int) $invoice->id);
        $active = $all->first(fn (RmeBillingDispute $d) => $d->isActive());

        return [
            'active' => $active,
            'history' => $all->reject(fn (RmeBillingDispute $d) => $d->isActive())->values(),
            'can_report' => $active === null
                && $user !== null
                && in_array((string) $invoice->status, self::REPORTABLE_INVOICE_STATUSES, true)
                && $user->can('create', [RmeBillingDispute::class, $invoice]),
        ];
    }

    /** @return Collection<int, RmeBillingDisputeEvent> */
    public function timeline(RmeBillingDispute $dispute): Collection
    {
        return $this->disputes->events((int) $dispute->id);
    }

    /**
     * @param  array{status?: string|null}  $filters
     */
    public function paginateFor(User $user, array $filters, int $perPage = 20): LengthAwarePaginator
    {
        return $this->disputes->paginateForBranches($this->readableBranchIds($user), $filters, $perPage);
    }

    /** @return array<string, int> */
    public function statusCountsFor(User $user): array
    {
        return $this->disputes->statusCountsForBranches($this->readableBranchIds($user));
    }

    /**
     * Branch ids whose disputes this user may read: their working-branch scope
     * when they hold one of the read permissions; otherwise (rule 204) only the
     * branches they are DESIGNATED to review, still inside their working scope.
     * Empty otherwise.
     *
     * @return list<int>
     */
    public function readableBranchIds(User $user): array
    {
        $scope = array_values(array_map('intval', $this->workingBranchScope->branchIdsFor($user)));

        foreach (RmeBillingDisputePermission::READ as $permission) {
            if ($user->can($permission)) {
                return $scope;
            }
        }

        return array_values(array_intersect($scope, $this->reviewers->designatedBranchIds($user)));
    }

    // ─── Report ──────────────────────────────────────────────────────────────

    /**
     * @param  array{reason_category?: mixed, description?: mixed}  $data
     */
    public function report(RmeInvoice $invoice, User $reporter, array $data): RmeBillingDispute
    {
        $reason = $data['reason_category'] ?? null;
        if (! is_string($reason) || ! in_array($reason, RmeBillingDisputeReason::all(), true)) {
            throw ValidationException::withMessages(['reason_category' => 'Pilih jenis ketidaksesuaian tagihan.']);
        }
        $description = $this->assertText($data['description'] ?? null, 'description', 'Keterangan ketidaksesuaian');

        $dispute = $this->audited('report', $reporter, null, (int) $invoice->id, function () use ($invoice, $reporter, $reason, $description) {
            try {
                return DB::transaction(function () use ($invoice, $reporter, $reason, $description) {
                    $locked = $this->invoices->findForUpdate((int) $invoice->id);
                    if ($locked === null) {
                        throw ValidationException::withMessages(['rme_invoice_id' => 'Tagihan tidak ditemukan.']);
                    }

                    $this->assertActor($reporter, [RmeBillingDisputePermission::REPORT], (int) $locked->branch_id, null, (int) $locked->id);
                    $this->assertRmeBranch((int) $locked->branch_id);

                    if (! in_array((string) $locked->status, self::REPORTABLE_INVOICE_STATUSES, true)) {
                        throw ValidationException::withMessages([
                            'rme_invoice_id' => '[BILLING_DISPUTE_INVOICE_STATUS_UNSUPPORTED] Laporan ketidaksesuaian hanya untuk tagihan berstatus Belum Dibayar, Sebagian atau Lunas.',
                        ]);
                    }

                    $existing = $this->disputes->activeForInvoice((int) $locked->id);
                    if ($existing !== null) {
                        throw $this->duplicate($existing);
                    }

                    $dispute = $this->disputes->create([
                        'rme_invoice_id' => (int) $locked->id,
                        'branch_id' => (int) $locked->branch_id,
                        'status' => RmeBillingDisputeStatus::OPEN,
                        'reason_category' => $reason,
                        'description' => $description,
                        'invoice_status_at_report' => (string) $locked->status,
                        'invoice_grand_total_at_report' => round((float) $locked->grand_total, 2),
                        'invoice_paid_total_at_report' => round((float) $locked->paidAmount(), 2),
                        'invoice_correction_version_at_report' => (int) ($locked->correction_version ?? 0),
                        'reported_by' => (int) $reporter->id,
                        'reported_at' => now(),
                    ]);

                    $dispute = $this->disputes->assignNumber(
                        $dispute,
                        'RMEDSP-'.now()->format('Ym').'-'.str_pad((string) $dispute->id, 6, '0', STR_PAD_LEFT),
                    );

                    $this->record($dispute, RmeBillingDisputeEvent::TYPE_REPORTED, null, $reporter, null, [
                        'description_length' => mb_strlen($description),
                    ]);

                    return $dispute;
                });
            } catch (QueryException $e) {
                // The partial unique index is the database backstop for "one
                // active dispute per invoice": a racing report that slipped past
                // the locked check is refused here, never stored twice.
                if ($this->isUniqueViolation($e)) {
                    $existing = $this->disputes->activeForInvoice((int) $invoice->id);

                    throw $existing !== null
                        ? $this->duplicate($existing)
                        : ValidationException::withMessages(['rme_invoice_id' => '[BILLING_DISPUTE_DUPLICATE] Tagihan ini sudah memiliki laporan ketidaksesuaian yang aktif.']);
                }

                throw $e;
            }
        });

        // After commit, never inside the transaction: the dispute and its payment
        // hold stand even if every notification fails.
        $this->escalation->reported($dispute, $reporter);

        return $dispute;
    }

    // ─── Branch authority actions ────────────────────────────────────────────

    public function startReview(RmeBillingDispute $dispute, User $reviewer, mixed $note = null): RmeBillingDispute
    {
        $note = $this->optionalText($note, 'note', 'Catatan tinjauan');

        return $this->mutate('review', $dispute, $reviewer, [RmeBillingDisputePermission::REVIEW], true, function (RmeBillingDispute $locked) use ($reviewer, $note) {
            $this->assertTransition($locked, RmeBillingDisputeStatus::UNDER_REVIEW);
            $from = (string) $locked->status;

            $this->disputes->transition($locked, [
                'status' => RmeBillingDisputeStatus::UNDER_REVIEW,
                'review_started_by' => (int) $reviewer->id,
                'review_started_at' => now(),
            ]);
            $this->record($locked, RmeBillingDisputeEvent::TYPE_REVIEW_STARTED, $from, $reviewer, $note);

            return $locked;
        });
    }

    /**
     * Escalate to the Owner — the case needs a case-specific decision the branch
     * authority cannot make (e.g. an invoice correction approval). Escalation is
     * coordination only: it grants nobody any financial power.
     */
    public function escalate(RmeBillingDispute $dispute, User $reviewer, mixed $note): RmeBillingDispute
    {
        $note = $this->assertText($note, 'note', 'Alasan eskalasi');

        $escalated = $this->mutate('escalate', $dispute, $reviewer, [RmeBillingDisputePermission::REVIEW], true, function (RmeBillingDispute $locked) use ($reviewer, $note) {
            $this->assertTransition($locked, RmeBillingDisputeStatus::ESCALATED);
            $from = (string) $locked->status;

            $this->disputes->transition($locked, [
                'status' => RmeBillingDisputeStatus::ESCALATED,
                'escalated_by' => (int) $reviewer->id,
                'escalated_at' => now(),
            ]);
            $this->record($locked, RmeBillingDisputeEvent::TYPE_ESCALATED, $from, $reviewer, $note);

            return $locked;
        });

        $this->escalation->escalated($escalated, $reviewer);

        return $escalated;
    }

    /** A follow-up note on an ACTIVE dispute (the reporter or the branch authority). No transition. */
    public function addNote(RmeBillingDispute $dispute, User $actor, mixed $note): RmeBillingDispute
    {
        $note = $this->assertText($note, 'note', 'Catatan tindak lanjut');

        return $this->mutate('note', $dispute, $actor, RmeBillingDisputePermission::NOTE, true, function (RmeBillingDispute $locked) use ($actor, $note) {
            if (! $locked->isActive()) {
                throw ValidationException::withMessages([
                    'dispute' => '[BILLING_DISPUTE_INVALID_TRANSITION] Laporan yang sudah selesai tidak dapat diberi catatan baru.',
                ]);
            }

            $this->record($locked, RmeBillingDisputeEvent::TYPE_NOTE_ADDED, (string) $locked->status, $actor, $note);

            return $locked;
        });
    }

    /**
     * Resolve — the ONLY way to release the payment hold. The outcome must be
     * one the service can verify (RmeBillingDisputeOutcome); a rejected or
     * cancelled correction is not one.
     *
     * @param  array{outcome?: mixed, resolution_reason?: mixed, resolution_reference?: mixed}  $data
     */
    public function resolve(RmeBillingDispute $dispute, User $resolver, array $data): RmeBillingDispute
    {
        $outcome = $data['outcome'] ?? null;
        if (! is_string($outcome) || ! in_array($outcome, RmeBillingDisputeOutcome::all(), true)) {
            throw ValidationException::withMessages(['outcome' => 'Pilih hasil penyelesaian yang sah.']);
        }
        $reason = $this->assertText($data['resolution_reason'] ?? null, 'resolution_reason', 'Alasan penyelesaian');
        $reference = $outcome === RmeBillingDisputeOutcome::ORIGINAL_CONFIRMED_CORRECT
            ? $this->assertReference($data['resolution_reference'] ?? null)
            : null;

        return $this->mutate('resolve', $dispute, $resolver, [RmeBillingDisputePermission::REVIEW], true, function (RmeBillingDispute $locked, ?RmeInvoice $invoice) use ($resolver, $outcome, $reason, $reference) {
            $this->assertTransition($locked, RmeBillingDisputeStatus::RESOLVED);

            if ($invoice === null) {
                throw ValidationException::withMessages(['dispute' => '[BILLING_DISPUTE_NOT_FOUND] Tagihan laporan ini tidak ditemukan.']);
            }

            // A correction still in flight means the invoice is not settled yet:
            // no outcome can be verified against a document that may still change.
            if ($this->corrections->activeForInvoice((int) $invoice->id) !== null) {
                throw ValidationException::withMessages([
                    'dispute' => '[BILLING_DISPUTE_CORRECTION_ACTIVE] Tagihan ini masih memiliki pengajuan koreksi yang aktif. Selesaikan koreksi terlebih dahulu.',
                ]);
            }

            $correctionId = null;

            if ($outcome === RmeBillingDisputeOutcome::CORRECTION_APPLIED) {
                // SYSTEM-VERIFIED: the invoice's CURRENT version was produced by an
                // APPLIED correction, and that version is newer than the disputed
                // one. The correction itself went through maker != checker.
                $applied = $this->corrections->latestAppliedForInvoice((int) $invoice->id);
                $current = (int) ($invoice->correction_version ?? 0);

                if ($applied === null
                    || (int) $applied->applied_invoice_version !== $current
                    || $current <= (int) $locked->invoice_correction_version_at_report) {
                    throw ValidationException::withMessages([
                        'outcome' => '[BILLING_DISPUTE_RESOLUTION_EVIDENCE_MISSING] Belum ada koreksi tagihan yang diterapkan setelah laporan ini dibuat.',
                    ]);
                }

                $correctionId = (int) $applied->id;
                $reference = (string) $applied->correction_number;
            } else {
                // HUMAN DETERMINATION: never self-certified by the reporter.
                if ($locked->isReportedBy($resolver)) {
                    throw new RmeBillingDisputeDenied(
                        RmeBillingDisputeDenied::SELF_RESOLUTION,
                        'Pelapor tidak dapat menyatakan sendiri bahwa tagihan yang dilaporkannya sudah benar.',
                        (int) $locked->id,
                        (int) $invoice->id,
                    );
                }

                // Rule 204 §15 — a DESIGNEE's confirmation must be independent of the
                // invoice's billing: never the account that issued it or collected on
                // it. Decided on the persisted account; an account that no longer
                // resolves takes the stricter (designee) branch — fail closed.
                $account = $this->actors->resolveUsable($resolver);
                if (! ($account?->can(RmeBillingDisputePermission::REVIEW) ?? false)
                    && $this->reviewers->hasBilledInvoice($resolver, $invoice)) {
                    throw new RmeBillingDisputeDenied(
                        RmeBillingDisputeDenied::RESOLVER_NOT_INDEPENDENT,
                        'Peninjau cabang yang menerbitkan atau menerima pembayaran tagihan ini tidak dapat menyatakan tagihannya benar. Minta peninjau lain.',
                        (int) $locked->id,
                        (int) $invoice->id,
                    );
                }

                // Only the invoice exactly as disputed can be "confirmed correct".
                if ((int) ($invoice->correction_version ?? 0) !== (int) $locked->invoice_correction_version_at_report) {
                    throw ValidationException::withMessages([
                        'outcome' => '[BILLING_DISPUTE_INVOICE_CHANGED] Tagihan sudah berubah sejak dilaporkan. Gunakan hasil penyelesaian koreksi yang diterapkan.',
                    ]);
                }
            }

            $from = (string) $locked->status;
            $this->disputes->transition($locked, [
                'status' => RmeBillingDisputeStatus::RESOLVED,
                'resolution_outcome' => $outcome,
                'resolution_reason' => $reason,
                'resolution_reference' => $reference,
                'resolution_correction_id' => $correctionId,
                'resolved_by' => (int) $resolver->id,
                'resolved_at' => now(),
            ]);
            $this->record($locked, RmeBillingDisputeEvent::TYPE_RESOLVED, $from, $resolver, null, [
                'resolution_reason_length' => mb_strlen($reason),
                'has_resolution_reference' => $reference !== null,
            ]);

            return $locked;
        });
    }

    // ─── Internals ───────────────────────────────────────────────────────────

    /**
     * @param  list<string>  $permissions  any one of them authorizes
     * @param  bool  $designationAuthorizes  rule 204: an ACTIVE branch reviewer designation for the dispute's branch also authorizes
     * @param  Closure(RmeBillingDispute, ?RmeInvoice): RmeBillingDispute  $work
     */
    private function mutate(string $action, RmeBillingDispute $dispute, User $actor, array $permissions, bool $designationAuthorizes, Closure $work): RmeBillingDispute
    {
        return $this->audited($action, $actor, (int) $dispute->id, (int) $dispute->rme_invoice_id, function () use ($dispute, $actor, $permissions, $designationAuthorizes, $work) {
            return DB::transaction(function () use ($dispute, $actor, $permissions, $designationAuthorizes, $work) {
                // Lock order: INVOICE first, then the dispute. The invoice id is
                // the immutable column of an unlocked read; the dispute is then
                // re-read under its own lock and must still belong to it.
                $invoiceId = (int) $dispute->rme_invoice_id;
                $invoice = $this->invoices->findForUpdate($invoiceId);
                $locked = $this->disputes->findForUpdate((int) $dispute->id);

                if ($locked === null || (int) $locked->rme_invoice_id !== $invoiceId) {
                    throw ValidationException::withMessages(['dispute' => '[BILLING_DISPUTE_NOT_FOUND] Laporan ketidaksesuaian tidak ditemukan.']);
                }

                $this->assertActor($actor, $permissions, (int) $locked->branch_id, (int) $locked->id, $invoiceId, $designationAuthorizes);

                return $work($locked, $invoice);
            });
        });
    }

    /**
     * Usability + permission (or, rule 204, an active designation for exactly this
     * branch) + branch scope of the PERSISTED account.
     *
     * The designation is read FOR SHARE under the invoice and dispute locks this
     * transaction already holds: a revocation (which locks the designation FOR
     * UPDATE) either committed first — and the designation is not found — or
     * waits until this action commits. Lock order: invoice → dispute →
     * designation; a revocation takes only the designation, so no cycle exists.
     *
     * @param  list<string>  $permissions
     */
    private function assertActor(User $actor, array $permissions, int $branchId, ?int $disputeId, ?int $invoiceId, bool $designationAuthorizes = false): void
    {
        $account = $this->actors->resolveUsable($actor);

        if ($account === null) {
            throw new RmeBillingDisputeDenied(
                RmeBillingDisputeDenied::ACTOR_UNUSABLE,
                'Akun ini tidak aktif atau tidak dikenali, sehingga tidak dapat menjalankan tindakan pada laporan tagihan.',
                $disputeId,
                $invoiceId,
            );
        }

        $holds = false;
        foreach ($permissions as $permission) {
            if ($account->can($permission)) {
                $holds = true;
                break;
            }
        }

        if (! $holds && $designationAuthorizes) {
            $holds = $this->reviewers->isDesignatedFor($account, $branchId, lock: true);
        }

        if (! $holds) {
            throw new RmeBillingDisputeDenied(
                RmeBillingDisputeDenied::PERMISSION_MISSING,
                'Anda tidak memiliki izin untuk tindakan ini.',
                $disputeId,
                $invoiceId,
            );
        }

        if (! $this->workingBranchScope->allows($account, $branchId)) {
            throw new RmeBillingDisputeDenied(
                RmeBillingDisputeDenied::BRANCH_OUT_OF_SCOPE,
                'Tagihan ini berada di luar cabang kerja Anda.',
                $disputeId,
                $invoiceId,
            );
        }
    }

    private function assertRmeBranch(int $branchId): void
    {
        if (! in_array($branchId, $this->branches->rmeEnabledIds(), true)) {
            throw ValidationException::withMessages(['rme_invoice_id' => '[BILLING_DISPUTE_BRANCH_NOT_RME] Tagihan tidak berada di cabang RME aktif.']);
        }
    }

    private function assertTransition(RmeBillingDispute $dispute, string $to): void
    {
        if (! RmeBillingDisputeStatus::canTransition((string) $dispute->status, $to)) {
            throw ValidationException::withMessages([
                'dispute' => sprintf(
                    '[BILLING_DISPUTE_INVALID_TRANSITION] Laporan berstatus %s tidak dapat diubah menjadi %s.',
                    RmeBillingDisputeStatus::label((string) $dispute->status),
                    RmeBillingDisputeStatus::label($to),
                ),
            ]);
        }
    }

    /**
     * @param  array<string, int|float|string|bool|null>  $extra
     */
    private function record(RmeBillingDispute $dispute, string $eventType, ?string $from, User $actor, ?string $note, array $extra = []): void
    {
        $payload = RmeBillingDisputeAuditEvent::payload($dispute, $from, array_merge($extra, [
            'event_type' => $eventType,
            'note_length' => $note !== null ? mb_strlen($note) : 0,
        ]));

        $this->disputes->recordEvent([
            'rme_billing_dispute_id' => (int) $dispute->id,
            'event_type' => $eventType,
            'from_status' => $from,
            'to_status' => (string) $dispute->status,
            'actor_id' => (int) $actor->id,
            'note' => $note,
            'occurred_at' => now(),
            'metadata' => $payload,
        ]);

        $this->auditLogs->log(
            RmeBillingDisputeAuditEvent::ENTITY_DISPUTE,
            (int) $dispute->id,
            RmeBillingDisputeAuditEvent::EVENT_ACTIONS[$eventType],
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
    private function audited(string $action, User $actor, ?int $disputeId, ?int $invoiceId, Closure $work): mixed
    {
        try {
            return $work();
        } catch (RmeBillingDisputeDenied $denied) {
            $this->auditLogs->log(
                RmeBillingDisputeAuditEvent::ENTITY_DISPUTE,
                $denied->disputeId ?? $disputeId,
                RmeBillingDisputeAuditEvent::ACTION_REFUSED,
                null,
                [
                    'attempted_action' => $action,
                    'code' => $denied->reasonCode,
                    'dispute_id' => $denied->disputeId ?? $disputeId,
                    'rme_invoice_id' => $denied->invoiceId ?? $invoiceId,
                ],
                $actor,
            );

            throw $denied;
        }
    }

    private function duplicate(RmeBillingDispute $existing): ValidationException
    {
        $number = $existing->dispute_number !== null ? ' ('.$existing->dispute_number.')' : '';

        return ValidationException::withMessages([
            'rme_invoice_id' => '[BILLING_DISPUTE_DUPLICATE] Tagihan ini sudah memiliki laporan ketidaksesuaian yang aktif'.$number.'.',
        ]);
    }

    private function assertText(mixed $value, string $field, string $label): string
    {
        return RmeBillingDisputeText::assert($value, $field, $label);
    }

    private function optionalText(mixed $value, string $field, string $label): ?string
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }

        return $this->assertText($value, $field, $label);
    }

    private function assertReference(mixed $value): string
    {
        $text = is_string($value) ? trim($value) : '';
        $length = mb_strlen($text);

        if ($length < self::REFERENCE_MIN
            || $length > self::REFERENCE_MAX
            || preg_match(self::REFERENCE_PATTERN, $text) !== 1
            || RmeBillingDisputeText::containsIdentityNumber($text)) {
            throw ValidationException::withMessages([
                'resolution_reference' => 'Referensi penyelesaian wajib diisi '.self::REFERENCE_MIN.'–'.self::REFERENCE_MAX
                    .' karakter (huruf, angka, titik, garis bawah, titik dua, garis miring, tanda hubung; tanpa NIK).',
            ]);
        }

        return $text;
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        $state = (string) $e->getCode();

        return $state === '23505'
            || $state === '23000'
            || str_contains(strtolower($e->getMessage()), 'unique constraint');
    }
}
