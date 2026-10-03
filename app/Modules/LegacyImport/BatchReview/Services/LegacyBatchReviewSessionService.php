<?php

declare(strict_types=1);

namespace App\Modules\LegacyImport\BatchReview\Services;

use App\Models\User;
use App\Modules\Branch\Services\BranchContext;
use App\Modules\LegacyImport\BatchReview\Adapters\LegacyBatchReviewAdapter;
use App\Modules\LegacyImport\BatchReview\Models\LegacyBatchReviewItemDecision;
use App\Modules\LegacyImport\BatchReview\Models\LegacyBatchReviewSession;
use App\Modules\LegacyImport\BatchReview\Support\LegacyBatchReviewDecision;
use App\Modules\LegacyImport\BatchReview\Support\LegacyBatchReviewReason;
use App\Modules\LegacyImport\BatchReview\Support\LegacyBatchReviewSessionStatus;
use App\Modules\LegacyImport\BatchReview\Support\LegacyBatchReviewSubmitStatus;
use App\Modules\LegacyImport\BatchReview\Support\LegacyReviewTriageStatus;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Batch review sessions — PR1 §1, §3, §4, §7, §13, §16.
 *
 * WHY THERE IS NO OUTER TRANSACTION AROUND submit()
 * -------------------------------------------------
 * One import is one transaction, and the canonical layer is built that way: the
 * review service opens its own transaction, takes its own row lock, and writes
 * its audit row AFTER that transaction commits. Wrapping N of those in one
 * outer transaction would do two bad things. A single refusal would roll back
 * reviews that had already succeeded, which §7 explicitly forbids. And the
 * post-commit audit rows would lie, because they would describe work that a
 * later rollback undid.
 *
 * So submit() is a LOOP over the canonical per-import call. Each iteration
 * succeeds or is recorded as refused, and the loop keeps going. 74 attested, 2
 * refused ⇒ 72 reviewed and 2 refused. Never 0.
 *
 * WHY THERE IS NO "REVIEW ALL"
 * ----------------------------
 * submit() can only act on decision rows that already exist, and a decision row
 * is only ever created by recordDecision() for ONE named import. There is no
 * method here, and no request shape anywhere in this feature, that can mark a
 * queue reviewed without an attestation per item. An item with no decision row
 * is untouched by submit() — that is the structural guarantee behind §1 and
 * §24, not a UI convention.
 *
 * WHY BROWSER-OPEN IS NOT PROOF OF REVIEW
 * ---------------------------------------
 * recordDecision() accepts view telemetry and stores it, but no gate in this
 * class reads it. §13 is explicit that opening a document does not prove
 * clinical review and that the actor's attestation is the authoritative signal.
 * Treating telemetry as a precondition would also be self-defeating: the system
 * can satisfy it on the operator's behalf, so it would prove nothing.
 */
class LegacyBatchReviewSessionService
{
    /**
     * Upper bound on one submit pass.
     *
     * Bounded for the same reason mass-upload dispatch is: a pass must finish
     * inside a request. A session legitimately rests mid-submit and the operator
     * resumes it, which is what makes it survivable rather than an
     * all-or-nothing request that dies at a timeout.
     */
    public const MAX_SUBMIT_PASS = 200;

    public function __construct(
        private readonly LegacyReviewTriageService $triage,
        private readonly LegacyBatchReviewAuditService $audit,
        private readonly BranchContext $branchContext,
    ) {}

    /**
     * Open a session for one document type.
     *
     * The actor must hold review authority for the type; the route middleware
     * already enforces the permission and this is the service-level backstop.
     */
    public function open(LegacyBatchReviewAdapter $adapter, User $actor): LegacyBatchReviewSession
    {
        if (! $adapter->migrationEnabled()) {
            throw new AuthorizationException('Kapabilitas migrasi tidak aktif.');
        }

        $session = LegacyBatchReviewSession::query()->create([
            'uuid' => (string) Str::uuid(),
            'import_type' => $adapter->importType(),
            'status' => LegacyBatchReviewSessionStatus::OPEN,
            'opened_by' => $actor->getKey(),
            // Provenance only. Every read and write re-resolves scope from the
            // canonical workspace scope for the acting user at that moment.
            'origin_branch_id' => $this->branchContext->forUser($actor),
        ]);

        $this->audit->logSessionEvent(
            LegacyBatchReviewAuditService::SESSION_OPENED,
            $session,
            [],
            $actor,
        );

        return $session;
    }

    /**
     * Record ONE reviewer attestation about ONE document.
     *
     * Re-marking the same item in the same session UPDATES its row rather than
     * appending, so "what did this reviewer decide about this item" has exactly
     * one answer. The unique index on (session, import) is what guarantees it
     * under concurrency.
     *
     * A triaging decision also raises sticky triage through the triage service,
     * which authorizes and reasons it separately. A REVIEWED decision CLEARS any
     * triage the reviewer had previously raised in this session — otherwise an
     * operator who blocked an item, looked again and changed their mind would
     * leave it permanently withheld.
     *
     * @throws AuthorizationException|ValidationException
     */
    public function recordDecision(
        LegacyBatchReviewSession $session,
        LegacyBatchReviewAdapter $adapter,
        User $actor,
        int $importId,
        string $decision,
        ?string $reasonCode = null,
        ?string $note = null,
        ?int $pagesViewed = null,
    ): LegacyBatchReviewItemDecision {
        $this->assertSessionUsable($session, $adapter, $actor);

        if (! LegacyBatchReviewDecision::isValid($decision)) {
            throw ValidationException::withMessages([
                'decision' => 'Keputusan tinjauan tidak dikenal.',
            ]);
        }

        // Scope is resolved server-side from the canonical workspace scope. An
        // import the actor cannot see is ABSENT, never a permission error, so a
        // crafted id cannot be used to probe which ids exist elsewhere.
        $import = $adapter->findInScope($actor, $importId);

        if (! $import instanceof Model) {
            throw ValidationException::withMessages([
                'import_id' => 'Dokumen tidak tersedia pada cakupan cabang Anda.',
            ]);
        }

        if (! $adapter->isReviewable($import)) {
            throw ValidationException::withMessages([
                'import_id' => 'Dokumen tidak lagi berada pada tahap tinjauan.',
            ]);
        }

        $triaging = LegacyBatchReviewDecision::isTriaging($decision);

        // Raised BEFORE the decision row is written: if triage is refused
        // (unauthorized, or an unacceptable reason) the attestation must not
        // land either, or the two records would disagree.
        if ($triaging) {
            $this->triage->raise(
                $adapter,
                $import,
                $actor,
                (string) LegacyReviewTriageStatus::forDecision($decision),
                $reasonCode,
                $note,
                $session,
            );
        }

        $foreignKey = $adapter->importForeignKey();

        $row = DB::transaction(function () use (
            $session, $adapter, $actor, $import, $importId, $decision, $reasonCode, $note, $pagesViewed, $foreignKey, $triaging
        ): LegacyBatchReviewItemDecision {
            $existing = LegacyBatchReviewItemDecision::query()
                ->where('batch_review_session_id', $session->getKey())
                ->where($foreignKey, $importId)
                ->lockForUpdate()
                ->first();

            $attributes = [
                'decision' => $decision,
                'reason_code' => $triaging ? $reasonCode : null,
                'reason_note' => $triaging ? $this->normalizeNote($note) : null,
                'decided_by' => $actor->getKey(),
                'decided_at' => now(),
                'source_sha256' => $adapter->sourceChecksum($import),
                'patient_id' => $adapter->patientId($import),
                // A triaging decision performs no canonical write by design, so
                // it is SKIPPED rather than PENDING — a submit pass has nothing
                // to carry for it.
                'submit_status' => $triaging
                    ? LegacyBatchReviewSubmitStatus::SKIPPED
                    : LegacyBatchReviewSubmitStatus::PENDING,
                'refusal_code' => null,
                'refusal_message' => null,
                'applied_at' => null,
            ];

            if ($pagesViewed !== null) {
                $attributes['pages_viewed'] = max(0, min($pagesViewed, 65535));
            }

            if ($existing !== null) {
                // first_viewed_at is set once and never moved — it records when
                // this reviewer first opened the document, not the latest time.
                if ($existing->first_viewed_at === null) {
                    $attributes['first_viewed_at'] = now();
                }

                $existing->fill($attributes)->save();

                return $existing->refresh();
            }

            return LegacyBatchReviewItemDecision::query()->create($attributes + [
                'batch_review_session_id' => $session->getKey(),
                'import_type' => $adapter->importType(),
                $foreignKey => $importId,
                'first_viewed_at' => now(),
            ]);
        });

        // A reviewer who changes their mind releases THEIR OWN earlier block
        // from THIS session, and nothing else.
        //
        // Two bugs are being avoided here. Clearing unconditionally would
        // demand triage authority just to attest a document reviewed, so an
        // actor barred by separation of duties would be refused at ATTESTATION
        // time instead of at submit — losing the refusal classification the
        // batch report depends on. And clearing another reviewer's block would
        // let any reviewer override a colleague's judgement simply by marking
        // the item reviewed, which is exactly the governance hole sticky triage
        // exists to close. A block raised elsewhere therefore stays, and
        // submit() refuses the item until an authorized reviewer clears it
        // deliberately.
        if (! $triaging) {
            $existingTriage = $this->triage->currentFor(
                $adapter->importType(),
                $foreignKey,
                $importId
            );

            if ($existingTriage !== null
                && $existingTriage->isBlocking()
                && (int) $existingTriage->raised_in_session_id === (int) $session->getKey()
            ) {
                $this->triage->clear($adapter, $import, $actor);
            }
        }

        $this->refreshMarkedCounts($session);

        $this->audit->logDecisionEvent(
            LegacyBatchReviewAuditService::ITEM_DECIDED,
            $row,
            ['session_uuid' => $session->uuid],
            $actor,
        );

        return $row;
    }

    /**
     * Carry every attested REVIEWED decision to the canonical review path.
     *
     * Partial success by construction. Resumable by construction: only
     * retryable rows are walked, so a pass that died halfway re-attempts
     * exactly what it never applied and cannot double-review what it did.
     *
     * @return array{attempted:int, applied:int, refused:int, skipped:int, refusal_counts:array<string,int>}
     */
    public function submit(
        LegacyBatchReviewSession $session,
        LegacyBatchReviewAdapter $adapter,
        User $actor,
        int $limit = self::MAX_SUBMIT_PASS,
    ): array {
        $this->assertSessionSubmittable($session, $adapter, $actor);

        // Nothing left to carry. Returned BEFORE the status transition so a
        // double-clicked Submit, or a resumed pass on an already-finished
        // session, is a harmless no-op rather than a refusal — while SUBMITTED
        // stays genuinely terminal in the state machine.
        $outstanding = $session->decisions()
            ->where('decision', LegacyBatchReviewDecision::REVIEWED)
            ->whereIn('submit_status', LegacyBatchReviewSubmitStatus::retryable())
            ->count();

        if ($outstanding === 0) {
            return [
                'attempted' => 0,
                'applied' => 0,
                'refused' => 0,
                'skipped' => 0,
                'refusal_counts' => [],
            ];
        }

        $session = $this->markSubmitting($session, $actor);

        $pending = $session->decisions()
            ->where('decision', LegacyBatchReviewDecision::REVIEWED)
            ->whereIn('submit_status', LegacyBatchReviewSubmitStatus::retryable())
            ->orderBy('id')
            ->limit(max(1, min($limit, self::MAX_SUBMIT_PASS)))
            ->get();

        $applied = 0;
        $refused = 0;
        $skipped = 0;
        $refusalCounts = [];

        foreach ($pending as $decision) {
            $importId = $decision->importId();

            if ($importId === null) {
                $this->recordRefusal(
                    $decision,
                    LegacyBatchReviewReason::REFUSAL_IMPORT_UNAVAILABLE,
                    'Dokumen tidak lagi tertaut pada keputusan ini.',
                    $actor
                );
                $refused++;
                $refusalCounts[LegacyBatchReviewReason::REFUSAL_IMPORT_UNAVAILABLE] =
                    ($refusalCounts[LegacyBatchReviewReason::REFUSAL_IMPORT_UNAVAILABLE] ?? 0) + 1;

                continue;
            }

            // Re-checked at submit time, not trusted from attestation time. A
            // reviewer may have blocked this item in another session since.
            $blocking = $this->triage->blockingFor(
                $adapter->importType(),
                $adapter->importForeignKey(),
                $importId
            );

            if ($blocking !== null) {
                $this->recordRefusal(
                    $decision,
                    LegacyBatchReviewReason::REFUSAL_TRIAGE_BLOCKING,
                    'Dokumen masih ditahan oleh peninjau.',
                    $actor
                );
                $refused++;
                $refusalCounts[LegacyBatchReviewReason::REFUSAL_TRIAGE_BLOCKING] =
                    ($refusalCounts[LegacyBatchReviewReason::REFUSAL_TRIAGE_BLOCKING] ?? 0) + 1;

                continue;
            }

            // THE CANONICAL CALL. Every gate — feature, scope, permission,
            // policy, separation of duties where the type has it, the row lock,
            // the page re-validation — runs inside this, not here.
            $outcome = $adapter->applyReview($actor, $importId);

            if ($outcome->applied) {
                $decision->fill([
                    'submit_status' => LegacyBatchReviewSubmitStatus::APPLIED,
                    'refusal_code' => null,
                    'refusal_message' => null,
                    'applied_at' => now(),
                ])->save();

                $applied++;

                continue;
            }

            $code = $outcome->refusalCode ?? LegacyBatchReviewReason::REFUSAL_UNKNOWN;
            $this->recordRefusal($decision, $code, $outcome->refusalMessage, $actor);
            $refused++;
            $refusalCounts[$code] = ($refusalCounts[$code] ?? 0) + 1;
        }

        $remaining = $session->decisions()
            ->where('decision', LegacyBatchReviewDecision::REVIEWED)
            ->where('submit_status', LegacyBatchReviewSubmitStatus::PENDING)
            ->count();

        $session = $this->finalize($session, $remaining, $actor);

        $summary = [
            'attempted' => $pending->count(),
            'applied' => $applied,
            'refused' => $refused,
            'skipped' => $skipped,
            'refusal_counts' => $refusalCounts,
        ];

        $this->audit->logSessionEvent(
            LegacyBatchReviewAuditService::SUBMITTED,
            $session,
            [
                'attempted' => $summary['attempted'],
                'applied_reviewed' => $applied,
                'refused_reviewed' => $refused,
                'refusal_counts' => $refusalCounts,
                'channel' => 'BATCH',
            ],
            $actor,
        );

        return $summary;
    }

    /** Close a session without submitting. Decisions are kept as evidence. */
    public function abandon(LegacyBatchReviewSession $session, User $actor): LegacyBatchReviewSession
    {
        if (! $session->canTransitionTo(LegacyBatchReviewSessionStatus::ABANDONED)) {
            throw ValidationException::withMessages([
                'status' => 'Sesi tinjauan tidak dapat ditinggalkan pada status ini.',
            ]);
        }

        $session->fill([
            'status' => LegacyBatchReviewSessionStatus::ABANDONED,
            'abandoned_at' => now(),
        ])->save();

        $this->audit->logSessionEvent(
            LegacyBatchReviewAuditService::SESSION_ABANDONED,
            $session,
            [],
            $actor,
        );

        return $session->refresh();
    }

    private function recordRefusal(
        LegacyBatchReviewItemDecision $decision,
        string $code,
        ?string $message,
        User $actor,
    ): void {
        $decision->fill([
            'submit_status' => LegacyBatchReviewSubmitStatus::REFUSED,
            'refusal_code' => $code,
            'refusal_message' => $message,
        ])->save();

        // Fills the review-time refusal gap: the canonical single-item review
        // writes no refusal event, so without this a batch refusal would leave
        // no trail at all.
        $this->audit->logDecisionEvent(
            LegacyBatchReviewAuditService::ITEM_REFUSED,
            $decision->refresh(),
            [],
            $actor,
        );
    }

    private function markSubmitting(
        LegacyBatchReviewSession $session,
        User $actor,
    ): LegacyBatchReviewSession {
        return DB::transaction(function () use ($session, $actor): LegacyBatchReviewSession {
            /** @var LegacyBatchReviewSession $locked */
            $locked = LegacyBatchReviewSession::query()
                ->whereKey($session->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            // The row lock plus the transition guard is what makes a
            // double-clicked Submit harmless: the second request finds the
            // session already SUBMITTING and the per-decision submit_status
            // stops it redoing applied work.
            if (! $locked->canTransitionTo(LegacyBatchReviewSessionStatus::SUBMITTING)) {
                throw ValidationException::withMessages([
                    'status' => 'Sesi tinjauan tidak dapat dikirim pada status ini.',
                ]);
            }

            $locked->fill([
                'status' => LegacyBatchReviewSessionStatus::SUBMITTING,
                'submitted_by' => $actor->getKey(),
            ])->save();

            return $locked->refresh();
        });
    }

    private function finalize(
        LegacyBatchReviewSession $session,
        int $remaining,
        User $actor,
    ): LegacyBatchReviewSession {
        $refused = $session->decisions()
            ->where('submit_status', LegacyBatchReviewSubmitStatus::REFUSED)
            ->count();

        $applied = $session->decisions()
            ->where('submit_status', LegacyBatchReviewSubmitStatus::APPLIED)
            ->count();

        // Still work left in this session: stay SUBMITTING so the operator can
        // resume. A bounded pass ending early is normal, not a failure.
        $target = $remaining > 0
            ? LegacyBatchReviewSessionStatus::SUBMITTING
            : ($refused > 0
                ? LegacyBatchReviewSessionStatus::SUBMITTED_WITH_REFUSALS
                : LegacyBatchReviewSessionStatus::SUBMITTED);

        $session->fill([
            'status' => $target,
            'applied_reviewed' => $applied,
            'refused_reviewed' => $refused,
            'submitted_at' => $remaining > 0 ? $session->submitted_at : now(),
        ])->save();

        return $session->refresh();
    }

    /** Recompute marked counts from the decision rows. Never incremented blind. */
    private function refreshMarkedCounts(LegacyBatchReviewSession $session): void
    {
        $counts = $session->decisions()
            ->selectRaw('decision, count(*) as aggregate')
            ->groupBy('decision')
            ->pluck('aggregate', 'decision');

        $session->fill([
            'marked_reviewed' => (int) ($counts[LegacyBatchReviewDecision::REVIEWED] ?? 0),
            'marked_blocked' => (int) ($counts[LegacyBatchReviewDecision::BLOCKED] ?? 0),
            'marked_attention' => (int) ($counts[LegacyBatchReviewDecision::NEEDS_ATTENTION] ?? 0),
        ])->save();
    }

    private function assertSessionUsable(
        LegacyBatchReviewSession $session,
        LegacyBatchReviewAdapter $adapter,
        User $actor,
    ): void {
        $this->assertSessionOwnership($session, $adapter, $actor);

        if (! $session->isMutable()) {
            throw ValidationException::withMessages([
                'status' => 'Sesi tinjauan ini sudah tidak dapat diubah.',
            ]);
        }
    }

    private function assertSessionSubmittable(
        LegacyBatchReviewSession $session,
        LegacyBatchReviewAdapter $adapter,
        User $actor,
    ): void {
        $this->assertSessionOwnership($session, $adapter, $actor);
    }

    /**
     * A session belongs to the reviewer who opened it, for the type it was
     * opened for.
     *
     * The type check closes a real confusion risk: an RME session driven through
     * an odontogram adapter would write ids into the wrong foreign key and
     * attempt canonical calls against the wrong module.
     */
    private function assertSessionOwnership(
        LegacyBatchReviewSession $session,
        LegacyBatchReviewAdapter $adapter,
        User $actor,
    ): void {
        if (! $adapter->migrationEnabled()) {
            throw new AuthorizationException('Kapabilitas migrasi tidak aktif.');
        }

        if ($session->import_type !== $adapter->importType()) {
            throw new AuthorizationException('Sesi tinjauan bukan untuk jenis dokumen ini.');
        }

        if ((int) $session->opened_by !== (int) $actor->getKey()) {
            throw new AuthorizationException('Sesi tinjauan ini milik peninjau lain.');
        }
    }

    private function normalizeNote(?string $note): ?string
    {
        if ($note === null) {
            return null;
        }

        $trimmed = trim($note);

        return $trimmed === '' ? null : mb_substr($trimmed, 0, 2000);
    }
}
