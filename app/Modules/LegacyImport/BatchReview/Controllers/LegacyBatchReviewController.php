<?php

declare(strict_types=1);

namespace App\Modules\LegacyImport\BatchReview\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\LegacyImport\BatchReview\Adapters\LegacyBatchReviewAdapter;
use App\Modules\LegacyImport\BatchReview\Models\LegacyBatchReviewSession;
use App\Modules\LegacyImport\BatchReview\Requests\ClearLegacyReviewTriageRequest;
use App\Modules\LegacyImport\BatchReview\Requests\RecordLegacyBatchReviewDecisionRequest;
use App\Modules\LegacyImport\BatchReview\Requests\SubmitLegacyBatchReviewRequest;
use App\Modules\LegacyImport\BatchReview\Services\LegacyBatchReviewSessionService;
use App\Modules\LegacyImport\BatchReview\Services\LegacyBatchReviewWorkspaceService;
use App\Modules\LegacyImport\BatchReview\Services\LegacyReviewTriageService;
use App\Modules\LegacyImport\BatchReview\Support\LegacyBatchReviewReason;
use App\Modules\LegacyImport\BatchReview\Support\LegacyBatchReviewSessionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Batch review workspace — PR1 §3, §12, §17.
 *
 * THIN. Every decision lives in a service; every clinical rule lives behind the
 * adapter in the canonical module. This class resolves the actor, hands work to
 * a service, and chooses a redirect.
 *
 * TWO SUBCLASSES RATHER THAN ONE CONTROLLER WITH A `$type` PARAMETER
 * ------------------------------------------------------------------
 * Same reasoning as the mass-upload controllers: fixing the clinical domain in
 * the subclass means nothing an operator can send is able to redirect a review
 * session into the other archive. A `?type=` parameter would make that a
 * validation problem instead of an impossibility.
 *
 * AUTHORIZATION IS NOT HERE
 * -------------------------
 * The route group carries `permission:review_legacy_*_imports`. Branch scope,
 * policy and separation of duties are resolved by the canonical layer through
 * the adapter, per item, at the moment of the write. This controller adds no
 * gate of its own and relaxes none.
 */
abstract class LegacyBatchReviewController extends Controller
{
    /** Upper bound on a request-supplied page size. */
    public const MAX_PER_PAGE = 100;

    public function __construct(
        protected readonly LegacyBatchReviewWorkspaceService $workspace,
        protected readonly LegacyBatchReviewSessionService $sessions,
        protected readonly LegacyReviewTriageService $triage,
    ) {}

    abstract protected function adapter(): LegacyBatchReviewAdapter;

    abstract protected function routePrefix(): string;

    abstract protected function heading(): string;

    /** The queue, plus this reviewer's open session if they have one. */
    public function index(Request $request): View
    {
        $adapter = $this->adapter();
        $this->assertMigrationEnabled();

        $actor = $request->user();
        $session = $this->currentSessionFor($request);

        $queue = $this->workspace->queue(
            $adapter,
            $actor,
            ['patient' => $request->query('patient')],
            $this->perPage($request),
            $session,
        );

        return view('settings.rme.legacy-batch-review.index', [
            'heading' => $this->heading(),
            'routePrefix' => $this->routePrefix(),
            'importType' => $adapter->importType(),
            'session' => $session,
            'paginator' => $queue['paginator'],
            'items' => $queue['items'],
            'counters' => $queue['counters'],
            'triageReasons' => LegacyBatchReviewReason::triageCodes(),
            'singleItemRoute' => $adapter->singleItemRouteName(),
            'pagePreviewRoute' => $adapter->pagePreviewRouteName(),
        ]);
    }

    /** Open a review session. */
    public function store(Request $request): RedirectResponse
    {
        $this->assertMigrationEnabled();

        $session = $this->sessions->open($this->adapter(), $request->user());

        return redirect()
            ->route($this->routePrefix().'.show', $session->uuid)
            ->with('status', 'Sesi tinjauan batch dibuka.');
    }

    /**
     * The review surface: one document at a time, with its rendered pages and
     * prev/next navigation.
     */
    public function show(Request $request, string $session): View
    {
        $adapter = $this->adapter();
        $this->assertMigrationEnabled();

        $actor = $request->user();
        $reviewSession = $this->resolveOwnSession($request, $session);

        $queue = $this->workspace->queue(
            $adapter,
            $actor,
            ['patient' => $request->query('patient')],
            $this->perPage($request),
            $reviewSession,
        );

        // The focused document. Defaults to the first queue row so the reviewer
        // lands on something to look at rather than an empty frame.
        $focusId = $request->integer('import');
        $focus = null;
        $focusSummary = null;

        if ($focusId > 0) {
            $focus = $adapter->findInScope($actor, $focusId);
        }

        if (! $focus instanceof Model && $queue['items'] !== []) {
            $focus = $adapter->findInScope($actor, (int) $queue['items'][0]['import_id']);
        }

        if ($focus instanceof Model) {
            $focusSummary = $adapter->summarize($focus, withPages: true)->toArray() + [
                'can_review' => $adapter->canReview($actor, $focus),
                'can_clear_triage' => $adapter->canClearTriage($actor, $focus),
            ];
        }

        [$prevImportId, $nextImportId] = $this->neighbours(
            $queue['items'],
            $focusSummary !== null ? (int) $focusSummary['import_id'] : null
        );

        return view('settings.rme.legacy-batch-review.show', [
            'heading' => $this->heading(),
            'routePrefix' => $this->routePrefix(),
            'importType' => $adapter->importType(),
            'session' => $reviewSession,
            'paginator' => $queue['paginator'],
            'items' => $queue['items'],
            'counters' => $queue['counters'],
            'focus' => $focusSummary,
            'prevImportId' => $prevImportId,
            'nextImportId' => $nextImportId,
            'triageReasons' => LegacyBatchReviewReason::triageCodes(),
            'singleItemRoute' => $adapter->singleItemRouteName(),
            'pagePreviewRoute' => $adapter->pagePreviewRouteName(),
        ]);
    }

    /** Record ONE attestation about ONE document. */
    public function decide(RecordLegacyBatchReviewDecisionRequest $request, string $session): RedirectResponse
    {
        $this->assertMigrationEnabled();

        $reviewSession = $this->resolveOwnSession($request, $session);

        $this->sessions->recordDecision(
            $reviewSession,
            $this->adapter(),
            $request->user(),
            $request->importId(),
            $request->decision(),
            $request->reasonCode(),
            $request->reasonNote(),
            $request->pagesViewed(),
        );

        // Keep the reviewer moving: land on the next queued document rather than
        // bouncing back to the index. §12 is explicit that reducing operator
        // time is the point.
        $next = $request->nextImportId();

        return redirect()
            ->route(
                $this->routePrefix().'.show',
                array_filter([
                    'session' => $reviewSession->uuid,
                    'import' => $next,
                ])
            )
            ->with('status', 'Keputusan tinjauan disimpan.');
    }

    /** Release sticky triage on ONE document. */
    public function clearTriage(ClearLegacyReviewTriageRequest $request, string $session): RedirectResponse
    {
        $adapter = $this->adapter();
        $this->assertMigrationEnabled();

        $reviewSession = $this->resolveOwnSession($request, $session);
        $import = $adapter->findInScope($request->user(), $request->importId());

        // Absence, not a permission error — an actor must not be able to probe
        // which archive ids exist in a branch they cannot see.
        abort_if(! $import instanceof Model, 404);

        $this->triage->clear($adapter, $import, $request->user());

        return redirect()
            ->route($this->routePrefix().'.show', [
                'session' => $reviewSession->uuid,
                'import' => $request->importId(),
            ])
            ->with('status', 'Status tahan dokumen dibebaskan.');
    }

    /** Carry every attested REVIEWED decision to the canonical review path. */
    public function submit(SubmitLegacyBatchReviewRequest $request, string $session): RedirectResponse
    {
        $this->assertMigrationEnabled();

        $reviewSession = $this->resolveOwnSession($request, $session);

        $summary = $this->sessions->submit(
            $reviewSession,
            $this->adapter(),
            $request->user(),
            $request->passLimit(),
        );

        return redirect()
            ->route($this->routePrefix().'.show', $reviewSession->uuid)
            ->with('status', $this->submitFlash($summary));
    }

    /** Close a session without submitting. */
    public function abandon(Request $request, string $session): RedirectResponse
    {
        $this->assertMigrationEnabled();

        $reviewSession = $this->resolveOwnSession($request, $session);

        $this->sessions->abandon($reviewSession, $this->adapter(), $request->user());

        return redirect()
            ->route($this->routePrefix().'.index')
            ->with('status', 'Sesi tinjauan ditinggalkan.');
    }

    /**
     * An honest flash. Partial success is reported as partial success — never
     * rounded up to "done" and never reported as a failure.
     *
     * @param  array{attempted:int, applied:int, refused:int, skipped:int, refusal_counts:array<string,int>}  $summary
     */
    protected function submitFlash(array $summary): string
    {
        if ($summary['attempted'] === 0) {
            return 'Tidak ada keputusan tinjauan yang perlu dikirim.';
        }

        if ($summary['refused'] === 0) {
            return sprintf('%d dokumen berhasil ditandai sudah ditinjau.', $summary['applied']);
        }

        return sprintf(
            '%d dokumen berhasil ditandai sudah ditinjau, %d ditolak sistem dan perlu diperiksa.',
            $summary['applied'],
            $summary['refused'],
        );
    }

    /**
     * Page size, CLAMPED.
     *
     * `per_page` is request-controlled and no view sends it, so without a bound
     * an authenticated reviewer could ask for the whole in-scope archive in one
     * request — hydrating every row with three eager-loaded relations and
     * building two whereIn overlays over N ids. That would also defeat the
     * workspace service's own flat-query-cost design.
     *
     * The ceiling is deliberately well above any usable screenful, so it bounds
     * abuse without constraining an operator working quickly.
     */
    protected function perPage(Request $request): int
    {
        return max(1, min((int) $request->integer('per_page', 25), self::MAX_PER_PAGE));
    }

    /**
     * The documents either side of the focused one, within the page the
     * reviewer is already looking at — PR1 §3, §12.
     *
     * Derived from the SAME already-authorized queue page the view renders, so
     * prev/next can never walk to a document outside this actor's branch scope.
     * Computing it from a separate query would be a second, unscoped source of
     * ids; taking it from the rendered page means navigation inherits the
     * canonical scope for free.
     *
     * Navigation deliberately does not wrap or cross pages: reaching the end of
     * a page and stopping is honest, whereas silently jumping to another page
     * would make "next" unpredictable mid-review.
     *
     * @param  list<array<string, mixed>>  $items
     * @return array{0: int|null, 1: int|null}
     */
    protected function neighbours(array $items, ?int $focusId): array
    {
        if ($focusId === null || $items === []) {
            return [null, null];
        }

        $ids = array_map(static fn (array $item): int => (int) $item['import_id'], $items);
        $position = array_search($focusId, $ids, true);

        if ($position === false) {
            return [null, null];
        }

        return [
            $ids[$position - 1] ?? null,
            $ids[$position + 1] ?? null,
        ];
    }

    /**
     * 404 when the migration capability is off, matching the canonical
     * controllers. A 403 would confirm the endpoint exists.
     */
    protected function assertMigrationEnabled(): void
    {
        abort_unless($this->adapter()->migrationEnabled(), 404);
    }

    /**
     * Resolve a session by uuid, scoped to the acting reviewer AND the document
     * type of this route group.
     *
     * Both constraints are enforced in SQL rather than checked afterwards, so a
     * uuid belonging to another reviewer or to the other archive is simply not
     * found. The service re-asserts ownership too — this is the HTTP-layer
     * boundary, not the only one.
     */
    protected function resolveOwnSession(Request $request, string $uuid): LegacyBatchReviewSession
    {
        $session = LegacyBatchReviewSession::query()
            ->where('uuid', $uuid)
            ->where('import_type', $this->adapter()->importType())
            ->where('opened_by', $request->user()?->getKey())
            ->first();

        abort_if($session === null, 404);

        return $session;
    }

    /** This reviewer's most recent still-usable session, if any. */
    protected function currentSessionFor(Request $request): ?LegacyBatchReviewSession
    {
        return LegacyBatchReviewSession::query()
            ->where('import_type', $this->adapter()->importType())
            ->where('opened_by', $request->user()?->getKey())
            ->whereIn('status', [
                LegacyBatchReviewSessionStatus::OPEN,
                LegacyBatchReviewSessionStatus::SUBMITTING,
                LegacyBatchReviewSessionStatus::SUBMITTED_WITH_REFUSALS,
            ])
            ->orderByDesc('id')
            ->first();
    }
}
