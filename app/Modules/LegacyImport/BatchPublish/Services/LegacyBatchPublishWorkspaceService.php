<?php

declare(strict_types=1);

namespace App\Modules\LegacyImport\BatchPublish\Services;

use App\Models\User;
use App\Modules\LegacyImport\BatchPublish\Adapters\LegacyBatchPublishAdapter;
use App\Modules\LegacyImport\BatchPublish\Models\LegacyBatchPublishItem;
use App\Modules\LegacyImport\BatchPublish\Models\LegacyBatchPublishRun;
use App\Modules\LegacyImport\BatchPublish\Support\LegacyBatchPublishItemStatus;
use App\Modules\LegacyImport\BatchReview\Services\LegacyReviewTriageService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;

/**
 * The READ side of the batch publish workspace — PR2 §12.
 *
 * Read-only by construction: nothing here writes, locks or decides. It composes
 * three already-authorized sources into what one screen needs — the canonical
 * REVIEWED queue (branch-scoped by the canonical workspace scope), PR1's sticky
 * triage overlay, and this run's own item rows.
 *
 * WHY THE OVERLAYS ARE BULK LOOKUPS, AND WHY THERE IS NO PER-ROW REVALIDATION
 * ---------------------------------------------------------------------------
 * A 100-row queue page must not issue 100 triage queries, 100 record lookups
 * and 100 full revalidations. Both overlays are fetched once per page and keyed
 * by import id, so the page cost stays flat regardless of page size.
 *
 * Critically, the LIST does NOT run the §6 revalidation per row. That is a
 * deliberate cost decision AND an honesty decision: a full revalidation per row
 * would re-evaluate date rules, branch bindings and source bindings for every
 * visible document, and its answer would still be stale by the time the
 * operator pressed confirm. So the list shows what is cheap and stable
 * (triage, already-published), and the authoritative "eligible now" count is
 * produced by the SELECTION step — which evaluates exactly the documents the
 * operator chose, immediately before the confirmation screen quotes the number.
 *
 * WHAT IS DELIBERATELY NOT EXPOSED
 * --------------------------------
 * No KTP/NIK, no date of birth, no clinical content, no page bytes, no
 * filesystem path. The summary is reused from PR1's PII-safe value object, and
 * the canonical listing it is built from already restricts patient search to
 * name and medical record number.
 */
class LegacyBatchPublishWorkspaceService
{
    public function __construct(
        private readonly LegacyReviewTriageService $triage,
    ) {}

    /**
     * One page of the REVIEWED queue, with the triage and already-published
     * overlays resolved for display.
     *
     * @param  array<string, mixed>  $filters
     * @return array{
     *     paginator: LengthAwarePaginator,
     *     items: list<array<string, mixed>>,
     *     counters: array<string, int>
     * }
     */
    public function queue(
        LegacyBatchPublishAdapter $adapter,
        ?User $actor,
        array $filters = [],
        int $perPage = 25,
        ?LegacyBatchPublishRun $run = null,
    ): array {
        $paginator = $adapter->paginatePublishQueue($actor, $this->safeFilters($filters), $perPage);

        /** @var list<Model> $models */
        $models = $paginator->items();
        $importIds = array_map(static fn (Model $m): int => (int) $m->getKey(), $models);

        $triageMap = $this->triage->blockingMap(
            $adapter->importType(),
            $adapter->importForeignKey(),
            $importIds
        );

        $itemMap = $this->itemMap($adapter, $run, $importIds);

        $items = [];
        $selectable = 0;
        $blocked = 0;

        foreach ($models as $model) {
            $importId = (int) $model->getKey();
            $summary = $adapter->summarize($model);
            $triage = $triageMap[$importId] ?? null;
            $item = $itemMap[$importId] ?? null;

            // §15: a withheld document is NEVER selectable, and the UI says so
            // rather than offering a checkbox the server would refuse.
            $isBlocked = $triage !== null;

            $isBlocked ? $blocked++ : $selectable++;

            $items[] = $summary->toArray() + [
                'triage_status' => $triage?->triage_status,
                'triage_status_label' => $triage !== null ? $triage->statusLabel() : null,
                'triage_reason_label' => $triage !== null ? $triage->reasonLabel() : null,
                'triage_blocking' => $isBlocked,
                'selectable' => ! $isBlocked,
                'item_status' => $item?->status,
                'item_status_label' => $item !== null ? $item->statusLabel() : null,
                'item_reason_label' => $item !== null && $item->reason_code !== null
                    ? $item->reasonLabel()
                    : null,
                'item_reason_message' => $item?->reason_message,
                'record_id' => $item?->recordId(),
            ];
        }

        return [
            'paginator' => $paginator,
            'items' => $items,
            'counters' => $this->counters($run, $paginator->total(), $selectable, $blocked),
        ];
    }

    /**
     * The counters §12 asks for.
     *
     * REVIEWED is the canonical queue total for this actor's scope. SELECTABLE
     * and BLOCKED describe the visible page. The rest come from this run's item
     * rows, because "published" in a workspace means "this run published it" —
     * a global count would mix in other operators' runs and read as this one's
     * progress.
     *
     * @return array<string, int>
     */
    public function counters(
        ?LegacyBatchPublishRun $run,
        int $reviewed,
        int $selectableOnPage = 0,
        int $blockedOnPage = 0,
    ): array {
        $base = [
            'reviewed' => $reviewed,
            'selectable_on_page' => $selectableOnPage,
            'blocked_on_page' => $blockedOnPage,
            'selected' => 0,
            'pending' => 0,
            'published' => 0,
            'already_published' => 0,
            'refused' => 0,
        ];

        if ($run === null) {
            return $base;
        }

        $byStatus = $run->items()
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        // "Already published" is split out of the published count so a run of
        // 50 that finds 3 already filed does not read as 53 fresh publications.
        $alreadyPublished = $run->items()
            ->where('status', LegacyBatchPublishItemStatus::PUBLISHED)
            ->where('created_record', false)
            ->count();

        $published = (int) ($byStatus[LegacyBatchPublishItemStatus::PUBLISHED] ?? 0);

        return [
            'reviewed' => $reviewed,
            'selectable_on_page' => $selectableOnPage,
            'blocked_on_page' => $blockedOnPage,
            'selected' => (int) $byStatus->sum(),
            'pending' => (int) ($byStatus[LegacyBatchPublishItemStatus::PENDING] ?? 0),
            'published' => $published - $alreadyPublished,
            'already_published' => $alreadyPublished,
            'refused' => (int) ($byStatus[LegacyBatchPublishItemStatus::REFUSED] ?? 0),
        ];
    }

    /**
     * This run's item rows, keyed by import id.
     *
     * @param  list<int>  $importIds
     * @return array<int, LegacyBatchPublishItem>
     */
    private function itemMap(
        LegacyBatchPublishAdapter $adapter,
        ?LegacyBatchPublishRun $run,
        array $importIds,
    ): array {
        if ($run === null || $importIds === []) {
            return [];
        }

        $foreignKey = $adapter->importForeignKey();

        return $run->items()
            ->whereIn($foreignKey, $importIds)
            ->get()
            ->keyBy(fn (LegacyBatchPublishItem $i): int => (int) $i->getAttribute($foreignKey))
            ->all();
    }

    /**
     * Keep only the filters the queue understands.
     *
     * `status` is NOT passed through — the adapter forces REVIEWED, because this
     * is the publish queue and accepting a status from the request would let a
     * non-REVIEWED document be surfaced as selectable.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function safeFilters(array $filters): array
    {
        $safe = [];
        $patient = $filters['patient'] ?? null;

        if (is_string($patient) && trim($patient) !== '') {
            $safe['patient'] = mb_substr(trim($patient), 0, 120);
        }

        return $safe;
    }
}
