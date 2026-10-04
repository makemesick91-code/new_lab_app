<?php

declare(strict_types=1);

namespace App\Modules\LegacyImport\BatchPublish\Services;

use App\Models\User;
use App\Modules\Branch\Services\BranchContext;
use App\Modules\LegacyImport\BatchPublish\Adapters\LegacyBatchPublishAdapter;
use App\Modules\LegacyImport\BatchPublish\Models\LegacyBatchPublishItem;
use App\Modules\LegacyImport\BatchPublish\Models\LegacyBatchPublishRun;
use App\Modules\LegacyImport\BatchPublish\Support\LegacyBatchPublishEligibility;
use App\Modules\LegacyImport\BatchPublish\Support\LegacyBatchPublishItemStatus;
use App\Modules\LegacyImport\BatchPublish\Support\LegacyBatchPublishReason;
use App\Modules\LegacyImport\BatchPublish\Support\LegacyBatchPublishRunStatus;
use App\Modules\LegacyImport\BatchReview\Services\LegacyReviewTriageService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Batch publish orchestration — PR2 §4, §5, §6, §9, §15, §20.
 *
 * WHY THERE IS NO OUTER TRANSACTION AROUND A PUBLISH PASS (§5)
 * ------------------------------------------------------------
 * One document is one transaction, and the canonical layer is built that way:
 * the publish service opens its own transaction, takes its own row lock,
 * re-validates every clinical rule, writes the archive record and its pages,
 * and writes its audit row AFTER that transaction commits.
 *
 * Wrapping N of those in one outer transaction would do three unacceptable
 * things. A single refusal would roll back publications that had already
 * succeeded, which §4 forbids outright. The post-commit audit rows would lie,
 * describing archive records a later rollback destroyed. And it would hold one
 * transaction and its locks open across hundreds of documents, which §20
 * forbids.
 *
 * So a pass is a LOOP over the canonical per-document call. 100 selected, 97
 * eligible, 3 stale ⇒ 97 published and 3 refused with explicit reasons. Never
 * 0, and never 3 silently skipped.
 *
 * WHERE CONCURRENCY SAFETY ACTUALLY COMES FROM (§8) — attributed honestly
 * -----------------------------------------------------------------------
 * NOT from anything in this class. Exactly-one-publication is guaranteed by the
 * canonical layer, in three independent layers:
 *
 *   1. `UNIQUE(source_import_id)` on the archive records table — a database
 *      constraint, so even two simultaneous transactions cannot both insert.
 *   2. `lockForUpdate` on the import inside the canonical publish transaction,
 *      so the status is never read stale.
 *   3. An explicit `findBySourceImportId()` early-return that hands back the
 *      EXISTING record with `created: false` rather than refusing.
 *
 * This service's own row lock (in markPublishing) serializes only the RUN'S
 * STATUS TRANSITION. It is released on commit before the item set is read, so
 * it does NOT serialize a pass against a concurrent one — and claiming it did
 * would be the exact dishonesty §8 warns against. Two concurrent passes over
 * the same document are safe because the canonical layer makes them safe, and
 * the second one reports ALREADY_PUBLISHED.
 *
 * TRIAGE IS NEVER CLEARED HERE (§15)
 * ----------------------------------
 * A blocked document is refused, full stop. There is no code path in this class
 * — and no request shape in this feature — that clears a PR1 triage annotation.
 * Clearing is a review-authority act and lives in PR1's review flow, behind its
 * own authorization. Grep this file for `clear`: there is nothing to find.
 */
class LegacyBatchPublishRunService
{
    /**
     * Upper bound on one publish pass — §20.
     *
     * A pass must finish inside a request without holding anything open. A run
     * legitimately rests mid-pass and the operator resumes it, which is what
     * makes it survivable rather than an all-or-nothing request that dies at a
     * timeout and leaves ambiguity about what was written.
     */
    public const MAX_PUBLISH_PASS = 50;

    /** Upper bound on one selection, so a single request cannot be unbounded. */
    public const MAX_SELECTION = 500;

    public function __construct(
        private readonly LegacyReviewTriageService $triage,
        private readonly LegacyBatchPublishAuditService $audit,
        private readonly BranchContext $branchContext,
    ) {}

    /** Open a run for one archive. */
    public function open(LegacyBatchPublishAdapter $adapter, User $actor): LegacyBatchPublishRun
    {
        if (! $adapter->migrationEnabled()) {
            throw new AuthorizationException('Kapabilitas migrasi tidak aktif.');
        }

        $run = LegacyBatchPublishRun::query()->create([
            'uuid' => (string) Str::uuid(),
            'import_type' => $adapter->importType(),
            'status' => LegacyBatchPublishRunStatus::OPEN,
            'started_by' => $actor->getKey(),
            // Provenance only. Scope is re-resolved from the canonical workspace
            // scope for the acting user on every read and write.
            'origin_branch_id' => $this->branchContext->forUser($actor),
        ]);

        $this->audit->logRunEvent(
            LegacyBatchPublishAuditService::RUN_OPENED,
            $run,
            [],
            $actor,
        );

        return $run;
    }

    /**
     * Record the operator's selection — §4, §12, §15.
     *
     * Every id is resolved through the canonical scope, so a crafted or
     * out-of-scope id is simply absent. Each selected document is evaluated
     * immediately so §12's confirmation screen can state "Selected: N, Eligible
     * now: M, Blocked after revalidation: K" truthfully instead of promising to
     * publish everything.
     *
     * An ineligible document is still RECORDED, as a REFUSED item with its
     * reason — §4 forbids silently dropping it from the report.
     *
     * UNRESOLVABLE IDS ARE THE ONE EXCEPTION, and the database insisted on it.
     * An id that resolves to no document in the actor's scope has no clinical
     * row to attach an attempt to, and the item table's foreign keys correctly
     * refuse to record one (attempting it raises a FK violation). So those are
     * counted and reported back to the operator in the summary rather than
     * persisted — the integrity of the attempt table is worth more than a
     * bookkeeping row pointing at nothing, and the operator is still told.
     *
     * @param  list<int>  $importIds
     * @return array{selected:int, eligible:int, refused:int, unavailable:int}
     */
    public function select(
        LegacyBatchPublishRun $run,
        LegacyBatchPublishAdapter $adapter,
        User $actor,
        array $importIds,
    ): array {
        $this->assertRunMutable($run, $adapter, $actor);

        // Bounded, de-duplicated, and positive-only before anything is touched.
        $importIds = array_values(array_unique(array_filter(
            array_map('intval', $importIds),
            static fn (int $id): bool => $id > 0,
        )));

        if (count($importIds) > self::MAX_SELECTION) {
            throw ValidationException::withMessages([
                'import_ids' => sprintf(
                    'Maksimal %d dokumen per sesi publikasi.',
                    self::MAX_SELECTION
                ),
            ]);
        }

        $eligible = 0;
        $refused = 0;
        $unavailable = 0;

        foreach ($importIds as $importId) {
            $import = $adapter->findInScope($actor, $importId);

            // Absent, not an error: an id the actor cannot see is treated as if
            // it does not exist, so selection cannot be used to probe which ids
            // exist in another branch. Counted, not persisted — see the method
            // docblock for why the foreign key makes that the correct choice.
            if (! $import instanceof Model) {
                $unavailable++;

                continue;
            }

            $verdict = $this->evaluate($adapter, $actor, $import);

            if ($verdict->eligible) {
                $this->recordItem(
                    $run, $adapter, $importId,
                    $adapter->patientId($import), $adapter->sourceChecksum($import),
                    LegacyBatchPublishItemStatus::PENDING, null, null,
                );
                $eligible++;

                continue;
            }

            $this->recordItem(
                $run, $adapter, $importId,
                $adapter->patientId($import), $adapter->sourceChecksum($import),
                LegacyBatchPublishItemStatus::REFUSED,
                $verdict->reasonCode, $verdict->reasonMessage,
                $verdict->existingRecordId,
            );
            $refused++;
        }

        $this->refreshCounts($run);

        return [
            'selected' => count($importIds),
            'eligible' => $eligible,
            'refused' => $refused,
            'unavailable' => $unavailable,
        ];
    }

    /**
     * THE §6 REVALIDATION. Composes the archive-agnostic triage rule with the
     * archive-specific clinical rules.
     *
     * Order matters: triage is checked FIRST because it is the cheapest and
     * because §15 makes it absolute — a withheld document must never reach the
     * clinical checks, let alone a publish attempt.
     */
    public function evaluate(
        LegacyBatchPublishAdapter $adapter,
        ?User $actor,
        Model $import,
    ): LegacyBatchPublishEligibility {
        $importId = (int) $import->getKey();

        // PR1's contract, consumed exactly as shipped. Never cleared here.
        $blocking = $this->triage->blockingFor(
            $adapter->importType(),
            $adapter->importForeignKey(),
            $importId,
        );

        if ($blocking !== null) {
            return LegacyBatchPublishEligibility::refused(
                LegacyBatchPublishReason::TRIAGE_BLOCKED,
                sprintf(
                    'Dokumen ditahan peninjau (%s). Pembebasan dilakukan melalui alur tinjauan.',
                    $blocking->statusLabel()
                ),
            );
        }

        return $adapter->revalidate($actor, $import);
    }

    /**
     * Publish one bounded pass — §4, §5, §9, §20.
     *
     * @return array{attempted:int, published:int, already:int, refused:int, remaining:int, refusal_counts:array<string,int>}
     */
    public function publish(
        LegacyBatchPublishRun $run,
        LegacyBatchPublishAdapter $adapter,
        User $actor,
        array $attributes = [],
        int $limit = self::MAX_PUBLISH_PASS,
    ): array {
        $this->assertRunOwnership($run, $adapter, $actor);

        // Nothing to do. Returned BEFORE the status transition so a
        // double-clicked confirm, or a resumed pass on a finished run, is a
        // harmless no-op rather than a refusal — while the terminal statuses
        // stay genuinely terminal.
        $outstanding = $run->attemptableItems()->count();

        if ($outstanding === 0) {
            return [
                'attempted' => 0, 'published' => 0, 'already' => 0,
                'refused' => 0, 'remaining' => 0, 'refusal_counts' => [],
            ];
        }

        $run = $this->markPublishing($run, $actor);

        $this->audit->logRunEvent(
            LegacyBatchPublishAuditService::PUBLISH_REQUESTED,
            $run,
            ['attempted' => min($outstanding, $limit)],
            $actor,
        );

        $pending = $run->attemptableItems()
            ->orderBy('id')
            ->limit(max(1, min($limit, self::MAX_PUBLISH_PASS)))
            ->get();

        $published = 0;
        $already = 0;
        $refused = 0;
        $refusalCounts = [];

        foreach ($pending as $item) {
            $importId = $item->importId();

            if ($importId === null) {
                $this->markItemRefused(
                    $item,
                    LegacyBatchPublishReason::IMPORT_UNAVAILABLE,
                    'Dokumen tidak lagi tertaut pada item ini.',
                    $actor,
                );
                $refused++;
                $refusalCounts[LegacyBatchPublishReason::IMPORT_UNAVAILABLE] =
                    ($refusalCounts[LegacyBatchPublishReason::IMPORT_UNAVAILABLE] ?? 0) + 1;

                continue;
            }

            // §6 — RE-CHECKED NOW, not trusted from selection time. A colleague
            // may have published, a reviewer may have blocked, a branch may have
            // been de-admitted, the source may have drifted.
            $import = $adapter->findInScope($actor, $importId);

            if (! $import instanceof Model) {
                $this->markItemRefused(
                    $item,
                    LegacyBatchPublishReason::IMPORT_UNAVAILABLE,
                    'Dokumen tidak tersedia pada cakupan cabang Anda.',
                    $actor,
                );
                $refused++;
                $refusalCounts[LegacyBatchPublishReason::IMPORT_UNAVAILABLE] =
                    ($refusalCounts[LegacyBatchPublishReason::IMPORT_UNAVAILABLE] ?? 0) + 1;

                continue;
            }

            $verdict = $this->evaluate($adapter, $actor, $import);

            if (! $verdict->eligible) {
                // ALREADY_PUBLISHED is benign: the document IS in the archive,
                // so the item is marked PUBLISHED-but-not-created rather than
                // counted as a failure.
                if ($verdict->isBenign() && $verdict->existingRecordId !== null) {
                    $this->markItemPublished($item, $adapter, $verdict->existingRecordId, false, $actor);
                    $already++;

                    continue;
                }

                $this->markItemRefused(
                    $item,
                    $verdict->reasonCode ?? LegacyBatchPublishReason::PUBLISH_UNKNOWN,
                    $verdict->reasonMessage,
                    $actor,
                );
                $refused++;
                $code = $verdict->reasonCode ?? LegacyBatchPublishReason::PUBLISH_UNKNOWN;
                $refusalCounts[$code] = ($refusalCounts[$code] ?? 0) + 1;

                continue;
            }

            // THE CANONICAL CALL. Every gate — feature, scope, permission,
            // policy, separation of duties where the archive has it, the row
            // lock, the date rules, the bindings, the page integrity, the
            // archive insert — runs inside this, in its own transaction.
            $outcome = $adapter->applyPublish($actor, $importId, $attributes);

            if ($outcome->published && $outcome->recordId !== null) {
                $this->markItemPublished($item, $adapter, $outcome->recordId, $outcome->created, $actor);

                $outcome->created ? $published++ : $already++;

                continue;
            }

            $code = $outcome->reasonCode ?? LegacyBatchPublishReason::PUBLISH_UNKNOWN;
            $this->markItemRefused($item, $code, $outcome->reasonMessage, $actor);
            $refused++;
            $refusalCounts[$code] = ($refusalCounts[$code] ?? 0) + 1;
        }

        $remaining = $run->attemptableItems()->count();
        $run = $this->finalize($run, $remaining, $actor);

        $summary = [
            'attempted' => $pending->count(),
            'published' => $published,
            'already' => $already,
            'refused' => $refused,
            'remaining' => $remaining,
            'refusal_counts' => $refusalCounts,
        ];

        $this->audit->logRunEvent(
            LegacyBatchPublishAuditService::PUBLISH_COMPLETED,
            $run,
            [
                'attempted' => $summary['attempted'],
                'published_count' => $published,
                'already_published' => $already,
                'refused_count' => $refused,
                'remaining' => $remaining,
                'refusal_counts' => $refusalCounts,
                'channel' => 'BATCH',
            ],
            $actor,
        );

        return $summary;
    }

    /**
     * Close a run without finishing it.
     *
     * §9: already-published documents stay published. Abandoning is a
     * bookkeeping act — it cannot and must not un-publish anything.
     */
    public function abandon(
        LegacyBatchPublishRun $run,
        LegacyBatchPublishAdapter $adapter,
        User $actor,
    ): LegacyBatchPublishRun {
        $this->assertRunOwnership($run, $adapter, $actor);

        if (! $run->canTransitionTo(LegacyBatchPublishRunStatus::ABANDONED)) {
            throw ValidationException::withMessages([
                'status' => 'Sesi publikasi tidak dapat ditinggalkan pada status ini.',
            ]);
        }

        $run->items()
            ->whereIn('status', LegacyBatchPublishItemStatus::attemptable())
            ->update(['status' => LegacyBatchPublishItemStatus::SKIPPED]);

        $run->fill([
            'status' => LegacyBatchPublishRunStatus::ABANDONED,
            'abandoned_at' => now(),
        ])->save();

        $this->refreshCounts($run);

        $this->audit->logRunEvent(
            LegacyBatchPublishAuditService::RUN_ABANDONED,
            $run->refresh(),
            [],
            $actor,
        );

        return $run->refresh();
    }

    /**
     * Upsert one selection row.
     *
     * The unique index on (run, import) makes a re-selected document update its
     * row rather than appending, so a run cannot hold two attempts for one
     * document — the invariant a resumed pass depends on.
     */
    private function recordItem(
        LegacyBatchPublishRun $run,
        LegacyBatchPublishAdapter $adapter,
        int $importId,
        ?int $patientId,
        ?string $checksum,
        string $status,
        ?string $reasonCode,
        ?string $reasonMessage,
        ?int $recordId = null,
    ): LegacyBatchPublishItem {
        $foreignKey = $adapter->importForeignKey();

        return DB::transaction(function () use (
            $run, $adapter, $importId, $patientId, $checksum,
            $status, $reasonCode, $reasonMessage, $recordId, $foreignKey
        ): LegacyBatchPublishItem {
            $existing = LegacyBatchPublishItem::query()
                ->where('batch_publish_run_id', $run->getKey())
                ->where($foreignKey, $importId)
                ->lockForUpdate()
                ->first();

            $attributes = [
                'status' => $status,
                'reason_code' => $reasonCode,
                'reason_message' => $reasonMessage,
                'patient_id' => $patientId,
                'source_sha256' => $checksum,
            ];

            if ($recordId !== null) {
                $attributes[$adapter->recordForeignKey()] = $recordId;
            }

            // A PUBLISHED item is NEVER rewritten by a re-selection. Publication
            // is final, and letting a later selection move it back to PENDING
            // would invite a second attempt against an already-filed archive.
            if ($existing !== null) {
                if ($existing->isPublished()) {
                    return $existing;
                }

                $existing->fill($attributes)->save();

                return $existing->refresh();
            }

            return LegacyBatchPublishItem::query()->create($attributes + [
                'batch_publish_run_id' => $run->getKey(),
                'import_type' => $adapter->importType(),
                $foreignKey => $importId,
            ]);
        });
    }

    private function markItemPublished(
        LegacyBatchPublishItem $item,
        LegacyBatchPublishAdapter $adapter,
        int $recordId,
        bool $created,
        User $actor,
    ): void {
        $item->fill([
            'status' => LegacyBatchPublishItemStatus::PUBLISHED,
            $adapter->recordForeignKey() => $recordId,
            'created_record' => $created,
            'reason_code' => $created ? null : LegacyBatchPublishReason::ALREADY_PUBLISHED,
            'reason_message' => $created
                ? null
                : LegacyBatchPublishReason::label(LegacyBatchPublishReason::ALREADY_PUBLISHED),
            'attempted_at' => now(),
            'published_at' => now(),
        ])->save();

        $this->audit->logItemEvent(
            LegacyBatchPublishAuditService::ITEM_PUBLISHED,
            $item->refresh(),
            ['created_record' => $created],
            $actor,
        );
    }

    private function markItemRefused(
        LegacyBatchPublishItem $item,
        string $code,
        ?string $message,
        User $actor,
    ): void {
        $item->fill([
            'status' => LegacyBatchPublishItemStatus::REFUSED,
            'reason_code' => $code,
            'reason_message' => $message ?? LegacyBatchPublishReason::label($code),
            'attempted_at' => now(),
        ])->save();

        $this->audit->logItemEvent(
            LegacyBatchPublishAuditService::ITEM_REFUSED,
            $item->refresh(),
            [],
            $actor,
        );
    }

    private function markPublishing(
        LegacyBatchPublishRun $run,
        User $actor,
    ): LegacyBatchPublishRun {
        return DB::transaction(function () use ($run): LegacyBatchPublishRun {
            /** @var LegacyBatchPublishRun $locked */
            $locked = LegacyBatchPublishRun::query()
                ->whereKey($run->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            // This lock serializes the STATUS TRANSITION only — see the class
            // docblock. It is released on commit, so it does not serialize the
            // pass; the canonical per-document idempotency does that.
            if (! $locked->canTransitionTo(LegacyBatchPublishRunStatus::PUBLISHING)) {
                throw ValidationException::withMessages([
                    'status' => 'Sesi publikasi tidak dapat dijalankan pada status ini.',
                ]);
            }

            $locked->fill([
                'status' => LegacyBatchPublishRunStatus::PUBLISHING,
                'started_at' => $locked->started_at ?? now(),
            ])->save();

            return $locked->refresh();
        });
    }

    private function finalize(
        LegacyBatchPublishRun $run,
        int $remaining,
        User $actor,
    ): LegacyBatchPublishRun {
        $this->refreshCounts($run);
        $run = $run->refresh();

        // Still work left: stay PUBLISHING so the operator can resume. A bounded
        // pass ending early is normal, not a failure.
        $target = $remaining > 0
            ? LegacyBatchPublishRunStatus::PUBLISHING
            : ($run->refused_count > 0
                ? LegacyBatchPublishRunStatus::COMPLETED_WITH_REFUSALS
                : LegacyBatchPublishRunStatus::COMPLETED);

        $run->fill([
            'status' => $target,
            'completed_at' => $remaining > 0 ? $run->completed_at : now(),
        ])->save();

        return $run->refresh();
    }

    /** Recompute counters from the item rows. Never incremented blind. */
    private function refreshCounts(LegacyBatchPublishRun $run): void
    {
        $counts = $run->items()
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $published = (int) ($counts[LegacyBatchPublishItemStatus::PUBLISHED] ?? 0);
        $refusedCount = (int) ($counts[LegacyBatchPublishItemStatus::REFUSED] ?? 0);

        $run->fill([
            'selected_count' => (int) $counts->sum(),
            'attempted_count' => $published + $refusedCount,
            'published_count' => $published,
            'refused_count' => $refusedCount,
        ])->save();
    }

    private function assertRunMutable(
        LegacyBatchPublishRun $run,
        LegacyBatchPublishAdapter $adapter,
        User $actor,
    ): void {
        $this->assertRunOwnership($run, $adapter, $actor);

        if (! $run->isMutable()) {
            throw ValidationException::withMessages([
                'status' => 'Pilihan dokumen pada sesi ini sudah tidak dapat diubah.',
            ]);
        }
    }

    /**
     * A run belongs to the publisher who opened it, for the archive it was
     * opened for.
     *
     * The type check closes a real confusion risk: an RME run driven through the
     * odontogram adapter would write ids into the wrong foreign key and attempt
     * canonical calls against the wrong module.
     */
    private function assertRunOwnership(
        LegacyBatchPublishRun $run,
        LegacyBatchPublishAdapter $adapter,
        User $actor,
    ): void {
        if (! $adapter->migrationEnabled()) {
            throw new AuthorizationException('Kapabilitas migrasi tidak aktif.');
        }

        if ($run->import_type !== $adapter->importType()) {
            throw new AuthorizationException('Sesi publikasi bukan untuk jenis dokumen ini.');
        }

        if ((int) $run->started_by !== (int) $actor->getKey()) {
            throw new AuthorizationException('Sesi publikasi ini milik petugas lain.');
        }
    }
}
