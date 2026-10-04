<?php

declare(strict_types=1);

namespace App\Modules\LegacyImport\BatchReview\Services;

use App\Models\User;
use App\Modules\LegacyImport\BatchReview\Adapters\LegacyBatchReviewAdapter;
use App\Modules\LegacyImport\BatchReview\Models\LegacyBatchReviewItemDecision;
use App\Modules\LegacyImport\BatchReview\Models\LegacyBatchReviewSession;
use App\Modules\LegacyImport\BatchReview\Support\LegacyBatchReviewDecision;
use App\Modules\LegacyImport\BatchReview\Support\LegacyBatchReviewSubmitStatus;
use App\Modules\LegacyImport\BatchReview\Support\LegacyReviewTriageStatus;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;

/**
 * The READ side of the batch review workspace — PR1 §11, §12.
 *
 * Read-only by construction: nothing here writes, locks or decides. It composes
 * three already-authorized sources into what one screen needs — the canonical
 * review queue (branch-scoped by the canonical workspace scope), the sticky
 * triage overlay, and this reviewer's own decisions in this session.
 *
 * WHY THE OVERLAY IS A BULK LOOKUP
 * --------------------------------
 * A 200-row queue page must not issue 200 triage queries and 200 decision
 * queries. Both overlays are fetched once per page and keyed by import id, so
 * the page cost stays flat regardless of page size.
 *
 * AND WHY THERE IS NO PER-ROW AUTHORIZATION HERE
 * ----------------------------------------------
 * The list deliberately carries no `can_review` / `can_clear_triage` flag.
 * Resolving either per row means re-entering the canonical workspace scope (and
 * on odontogram, the policy) once per row, and `BranchService::rmeEnabledIds()`
 * is not memoized — a 25-row page would issue roughly fifty extra branch
 * queries. The list renders no action buttons, so those values were computed
 * and then used nowhere.
 *
 * Authorization for the ACTIONS is resolved once, for the focused document
 * only, by the controller — and is advisory even there, because the canonical
 * path re-decides under its own row lock when the write actually happens.
 *
 * WHAT IS DELIBERATELY NOT EXPOSED
 * --------------------------------
 * No KTP/NIK, no date of birth, no clinical content, no page bytes, no
 * filesystem path. The item summary the adapter returns is the whole contract,
 * and the canonical listing it is built from already restricts patient search to
 * name and medical record number.
 */
class LegacyBatchReviewWorkspaceService
{
    public function __construct(
        private readonly LegacyReviewTriageService $triage,
    ) {}

    /**
     * One page of the review queue, with the triage and decision overlays
     * resolved for display.
     *
     * @param  array<string, mixed>  $filters
     * @return array{
     *     paginator: LengthAwarePaginator,
     *     items: list<array<string, mixed>>,
     *     counters: array<string, int>
     * }
     */
    public function queue(
        LegacyBatchReviewAdapter $adapter,
        ?User $actor,
        array $filters = [],
        int $perPage = 25,
        ?LegacyBatchReviewSession $session = null,
    ): array {
        $paginator = $adapter->paginateReviewQueue($actor, $this->safeFilters($filters), $perPage);

        /** @var list<Model> $models */
        $models = $paginator->items();
        $importIds = array_map(static fn (Model $m): int => (int) $m->getKey(), $models);

        $triageMap = $this->triage->blockingMap(
            $adapter->importType(),
            $adapter->importForeignKey(),
            $importIds
        );

        $decisionMap = $this->decisionMap($adapter, $session, $importIds);

        $items = [];

        foreach ($models as $model) {
            $importId = (int) $model->getKey();
            // No pages: the list renders no previews (see summarize()).
            $summary = $adapter->summarize($model);
            $triage = $triageMap[$importId] ?? null;
            $decision = $decisionMap[$importId] ?? null;

            $items[] = $summary->toArray() + [
                'triage_status' => $triage?->triage_status,
                'triage_status_label' => $triage !== null ? $triage->statusLabel() : null,
                'triage_reason_label' => $triage !== null ? $triage->reasonLabel() : null,
                'triage_blocking' => $triage !== null,
                'decision' => $decision?->decision,
                'decision_label' => $decision !== null ? $decision->decisionLabel() : null,
                'submit_status' => $decision?->submit_status,
                'submit_status_label' => $decision !== null ? $decision->submitStatusLabel() : null,
                'refusal_message' => $decision?->refusal_message,
            ];
        }

        return [
            'paginator' => $paginator,
            'items' => $items,
            'counters' => $this->counters($adapter, $session, $paginator->total()),
        ];
    }

    /**
     * The counters §11 asks for.
     *
     * READY FOR REVIEW comes from the canonical queue total for this actor's
     * scope. The rest come from this session's decision rows, because "reviewed"
     * in a workspace means "this reviewer attested it here" — a global count
     * would mix in other reviewers' sessions and read as this one's progress.
     *
     * @return array<string, int>
     */
    public function counters(
        LegacyBatchReviewAdapter $adapter,
        ?LegacyBatchReviewSession $session,
        int $readyForReview,
    ): array {
        $base = [
            'ready_for_review' => $readyForReview,
            'marked_reviewed' => 0,
            'marked_blocked' => 0,
            'marked_attention' => 0,
            'pending_submit' => 0,
            'applied' => 0,
            'refused' => 0,
        ];

        if ($session === null) {
            return $base;
        }

        $byDecision = $session->decisions()
            ->selectRaw('decision, count(*) as aggregate')
            ->groupBy('decision')
            ->pluck('aggregate', 'decision');

        $bySubmit = $session->decisions()
            ->selectRaw('submit_status, count(*) as aggregate')
            ->groupBy('submit_status')
            ->pluck('aggregate', 'submit_status');

        return [
            'ready_for_review' => $readyForReview,
            'marked_reviewed' => (int) ($byDecision[LegacyBatchReviewDecision::REVIEWED] ?? 0),
            'marked_blocked' => (int) ($byDecision[LegacyBatchReviewDecision::BLOCKED] ?? 0),
            'marked_attention' => (int) ($byDecision[LegacyBatchReviewDecision::NEEDS_ATTENTION] ?? 0),
            'pending_submit' => (int) ($bySubmit[LegacyBatchReviewSubmitStatus::PENDING] ?? 0),
            'applied' => (int) ($bySubmit[LegacyBatchReviewSubmitStatus::APPLIED] ?? 0),
            'refused' => (int) ($bySubmit[LegacyBatchReviewSubmitStatus::REFUSED] ?? 0),
        ];
    }

    /**
     * This reviewer's decisions in this session, keyed by import id.
     *
     * @param  list<int>  $importIds
     * @return array<int, LegacyBatchReviewItemDecision>
     */
    private function decisionMap(
        LegacyBatchReviewAdapter $adapter,
        ?LegacyBatchReviewSession $session,
        array $importIds,
    ): array {
        if ($session === null || $importIds === []) {
            return [];
        }

        $foreignKey = $adapter->importForeignKey();

        return $session->decisions()
            ->whereIn($foreignKey, $importIds)
            ->get()
            ->keyBy(fn (LegacyBatchReviewItemDecision $d): int => (int) $d->getAttribute($foreignKey))
            ->all();
    }

    /**
     * Keep only the filters the queue understands.
     *
     * `status` is NOT passed through — the adapter forces READY_FOR_REVIEW,
     * because this is the review queue and accepting a status from the request
     * would turn it into an arbitrary workspace listing. `triage` is handled by
     * the overlay rather than the canonical query, since triage lives in this
     * module's own table.
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

    /** @return list<string> */
    public function triageFilterOptions(): array
    {
        return LegacyReviewTriageStatus::blocking();
    }
}
