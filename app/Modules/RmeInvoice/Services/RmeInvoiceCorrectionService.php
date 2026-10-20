<?php

declare(strict_types=1);

namespace App\Modules\RmeInvoice\Services;

use App\Models\User;
use App\Modules\Branch\Services\BranchService;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\LabOrder\Services\AuditLogService;
use App\Modules\RmeInvoice\Interfaces\RmeInvoiceCorrectionRepositoryInterface;
use App\Modules\RmeInvoice\Interfaces\RmeInvoiceRepositoryInterface;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use App\Modules\RmeInvoice\Models\RmeInvoiceCorrection;
use App\Modules\RmeInvoice\Models\RmeInvoiceCorrectionLine;
use App\Modules\RmeInvoice\Models\RmeInvoiceDocumentVersion;
use App\Modules\RmeInvoice\Models\RmeInvoiceItem;
use App\Modules\RmeInvoice\Support\RmeFinancialAuditEvent;
use App\Modules\RmeInvoice\Support\RmeInvoiceCorrectionDenied;
use App\Modules\RmeInvoice\Support\RmeInvoiceCorrectionFeatureGuard;
use App\Modules\RmeInvoice\Support\RmeInvoiceCorrectionPermission;
use App\Modules\RmeInvoice\Support\RmeInvoiceCorrectionStatus;
use App\Modules\RmeInvoice\Support\RmeInvoiceCorrectionType;
use App\Modules\RmeInvoice\Support\RmeInvoiceDocument;
use App\Modules\RmeInvoice\Support\RmeInvoicePriceSource;
use App\Modules\RmeInvoice\Support\RmeInvoiceSnapshot;
use App\Modules\RmeOnlineContext\Services\RmeWorkingBranchScope;
use App\Modules\Tariff\Support\TariffPricingMode;
use App\Modules\Treatment\Models\Treatment;
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
 *  2. PRE-PAYMENT ONLY: an invoice with any payment is refused at submission and
 *     makes a pending correction STALE. A payment row is never touched.
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
 */
class RmeInvoiceCorrectionService
{
    public const REASON_MIN = 10;

    public const REASON_MAX = 1000;

    private const IDEMPOTENCY_PATTERN = '/^[A-Za-z0-9-]{8,64}$/D';

    public function __construct(
        private readonly RmeInvoiceCorrectionRepositoryInterface $corrections,
        private readonly RmeInvoiceRepositoryInterface $invoices,
        private readonly RmeInvoiceCorrectionProposalBuilder $proposals,
        private readonly RmeInvoiceCorrectionFeatureGuard $guard,
        private readonly BranchService $branches,
        private readonly RmeWorkingBranchScope $workingBranchScope,
        private readonly AuditLogService $auditLogs,
    ) {}

    // ─── Reads ──────────────────────────────────────────────────────────────

    public function capabilityEnabled(): bool
    {
        return $this->guard->enabled();
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
     * @return array{enabled: bool, can_request: bool, eligible: bool, ineligible_reason: string|null, active: RmeInvoiceCorrection|null, history: Collection<int, RmeInvoiceCorrection>, versions: Collection<int, RmeInvoiceDocumentVersion>}
     */
    public function panelFor(RmeInvoice $invoice, ?User $user): array
    {
        $enabled = $this->guard->enabled();
        $empty = [
            'enabled' => $enabled,
            'can_request' => false,
            'eligible' => false,
            'ineligible_reason' => null,
            'active' => null,
            'history' => collect(),
            'versions' => collect(),
        ];

        if (! $enabled && ! $invoice->isRevised()) {
            return $empty;
        }

        $history = $this->corrections->forInvoice((int) $invoice->id);
        $active = $history->first(fn (RmeInvoiceCorrection $c) => $c->isActive());

        $ineligible = $this->prePaymentIneligibility($invoice, $active !== null);

        return array_merge($empty, [
            'can_request' => $enabled
                && $user !== null
                && $user->can(RmeInvoiceCorrectionPermission::REQUEST)
                && $this->workingBranchScope->allows($user, (int) $invoice->branch_id),
            'eligible' => $enabled && $ineligible === null,
            'ineligible_reason' => $ineligible,
            'active' => $active,
            'history' => $history,
            'versions' => $this->corrections->documentVersionsForInvoice((int) $invoice->id),
        ]);
    }

    // ─── Submit ─────────────────────────────────────────────────────────────

    /**
     * @param  array{reason?: mixed, lines?: mixed, idempotency_key?: mixed}  $data
     */
    public function submit(RmeInvoice $invoice, User $maker, array $data): RmeInvoiceCorrection
    {
        $this->guard->assertEnabled();

        $reason = $this->assertText($data['reason'] ?? null, 'reason', 'Alasan koreksi');
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

        return $this->audited('submit', $maker, null, (int) $invoice->id, function () use ($invoice, $maker, $reason, $lines, $key) {
            try {
                return DB::transaction(function () use ($invoice, $maker, $reason, $lines, $key) {
                    $locked = $this->invoices->findForUpdate((int) $invoice->id);
                    if ($locked === null) {
                        throw ValidationException::withMessages(['rme_invoice_id' => 'Tagihan tidak ditemukan.']);
                    }

                    $this->assertActor($maker, RmeInvoiceCorrectionPermission::REQUEST, (int) $locked->branch_id, null, (int) $locked->id);
                    $this->assertRmeBranch((int) $locked->branch_id);

                    // Re-checked under the lock: a concurrent double-submit with
                    // the same key that committed while we waited is a replay,
                    // not a competing active correction.
                    if ($key !== null && ($replay = $this->replayFor($key, $locked, $maker)) !== null) {
                        return $replay;
                    }

                    $hasActive = $this->corrections->activeForInvoice((int) $locked->id) !== null;
                    $ineligible = $this->prePaymentIneligibility($locked, $hasActive);
                    if ($ineligible !== null) {
                        throw ValidationException::withMessages(['rme_invoice_id' => $ineligible]);
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
                        'correction_type' => RmeInvoiceCorrectionType::PRE_PAYMENT,
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

    public function approve(RmeInvoiceCorrection $correction, User $checker, ?string $note = null): RmeInvoiceCorrection
    {
        $this->guard->assertEnabled();
        $note = $this->optionalText($note, 'decision_note', 'Catatan persetujuan');

        return $this->decide($correction, $checker, 'approve', function (RmeInvoiceCorrection $locked, ?RmeInvoice $invoice) use ($checker, $note) {
            if (in_array($locked->status, [RmeInvoiceCorrectionStatus::APPROVED, RmeInvoiceCorrectionStatus::APPLIED], true)) {
                return [$locked, null]; // replay of an approval: idempotent no-op
            }

            $this->assertTransition($locked, RmeInvoiceCorrectionStatus::APPROVED);

            $stale = $this->staleReason($invoice, $locked);
            if ($stale !== null) {
                return [$this->markStale($locked, $stale, $checker), $stale];
            }

            $from = $locked->status;
            $this->corrections->transition($locked, [
                'status' => RmeInvoiceCorrectionStatus::APPROVED,
                'decided_by' => (int) $checker->id,
                'decided_at' => now(),
                'decision_note' => $note,
            ]);
            $this->recordTransition($locked, $from, $checker, ['note_length' => mb_strlen((string) $note)]);

            return [$locked, null];
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

                if ($locked->status === RmeInvoiceCorrectionStatus::APPLIED) {
                    return [$locked, null]; // re-applying is a no-op, never a second application
                }

                if ($locked->status !== RmeInvoiceCorrectionStatus::APPROVED) {
                    throw ValidationException::withMessages([
                        'correction' => '[CORRECTION_NOT_APPROVED] Koreksi hanya dapat diterapkan setelah disetujui.',
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

                /** @var RmeInvoice $invoice */
                return [$this->applyLocked($invoice, $locked, $actor), null];
            });
        });

        if ($stale !== null) {
            $this->throwStale($stale);
        }

        return $result;
    }

    // ─── Internals ──────────────────────────────────────────────────────────

    private function applyLocked(RmeInvoice $invoice, RmeInvoiceCorrection $correction, User $actor): RmeInvoiceCorrection
    {
        $before = RmeInvoiceSnapshot::capture($invoice);
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
                RmeInvoiceCorrectionLine::OP_CHANGE => $this->invoices->updateItem($this->itemFor($items, $line), $this->itemValues($line)),
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

        // Pre-payment only: no payment exists (verified under this lock), so the
        // ledger-derived status is UNPAID and nothing about it changes.
        if ($invoice->status !== RmeInvoice::STATUS_UNPAID || $invoice->payments()->exists()) {
            throw new LogicException(sprintf('Correction #%d reached application on a paid invoice.', (int) $correction->id));
        }

        $after = RmeInvoiceSnapshot::capture($invoice);
        $revision = $this->freezeVersion($invoice, $versionAfter, RmeInvoiceDocumentVersion::KIND_REVISION, $after, (int) $correction->id, RmeInvoiceDocumentVersion::BASIS_APPLIED_CORRECTION, $actor);

        $from = $correction->status;
        $this->corrections->transition($correction, [
            'status' => RmeInvoiceCorrectionStatus::APPLIED,
            'applied_by' => (int) $actor->id,
            'applied_at' => now(),
            'applied_invoice_version' => $versionAfter,
        ]);

        $this->recordTransition($correction, $from, $actor, [
            'invoice_version_before' => $versionBefore,
            'invoice_version_after' => $versionAfter,
            'grand_total_before' => round((int) $before['totals']['grand_total_cents'] / 100, 2),
            'grand_total_after' => round($grandTotal / 100, 2),
            'document_version_original_id' => $originalVersion?->id !== null ? (int) $originalVersion->id : null,
            'document_version_revision_id' => (int) $revision->id,
            'latest_version_matched_live_state' => $latestMatched,
        ]);

        return $correction;
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
        [$result, $stale] = $this->audited($action, $checker, (int) $correction->id, (int) $correction->rme_invoice_id, function () use ($correction, $checker, $transition) {
            return DB::transaction(function () use ($correction, $checker, $transition) {
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
     * Permission + branch scope, asserted in the SERVICE so a command, a job or a
     * future caller cannot reach a transition without them.
     */
    private function assertActor(User $actor, string $permission, int $branchId, ?int $correctionId, ?int $invoiceId): void
    {
        if (! $actor->can($permission)) {
            throw new RmeInvoiceCorrectionDenied(
                RmeInvoiceCorrectionDenied::PERMISSION_MISSING,
                'Anda tidak memiliki izin untuk tindakan koreksi ini.',
                $correctionId,
                $invoiceId,
            );
        }

        if (! $this->workingBranchScope->allows($actor, $branchId)) {
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

    private function isUniqueViolation(QueryException $e): bool
    {
        $state = (string) $e->getCode();

        return $state === '23505'
            || $state === '23000'
            || str_contains(strtolower($e->getMessage()), 'unique constraint');
    }
}
