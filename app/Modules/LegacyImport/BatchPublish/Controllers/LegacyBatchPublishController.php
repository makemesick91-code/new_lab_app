<?php

declare(strict_types=1);

namespace App\Modules\LegacyImport\BatchPublish\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\LegacyImport\BatchPublish\Adapters\LegacyBatchPublishAdapter;
use App\Modules\LegacyImport\BatchPublish\Models\LegacyBatchPublishRun;
use App\Modules\LegacyImport\BatchPublish\Requests\ExecuteLegacyBatchPublishRequest;
use App\Modules\LegacyImport\BatchPublish\Requests\SelectLegacyBatchPublishItemsRequest;
use App\Modules\LegacyImport\BatchPublish\Services\LegacyBatchPublishRunService;
use App\Modules\LegacyImport\BatchPublish\Services\LegacyBatchPublishWorkspaceService;
use App\Modules\LegacyImport\BatchPublish\Support\LegacyBatchPublishRunStatus;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Batch publish workspace — PR2 §12, §13, §20.
 *
 * THIN. Every decision lives in a service; every clinical rule lives behind the
 * adapter in the canonical module. This class resolves the actor, hands work to
 * a service, and chooses a redirect.
 *
 * TWO SUBCLASSES RATHER THAN A `$type` PARAMETER
 * ----------------------------------------------
 * Fixing the archive in the subclass means nothing an operator can send is able
 * to redirect a publication run into the other archive. A `?type=` parameter
 * would demote that impossibility to a validation problem.
 *
 * AUTHORIZATION IS NOT HERE (§13)
 * -------------------------------
 * The route group carries `permission:publish_legacy_*_imports` — the canonical
 * publish permission, reused verbatim. There is no batch-publish
 * super-permission. Branch scope, policy and separation of duties are resolved
 * by the canonical layer through the adapter, per document, at the moment of the
 * write. This controller adds no gate and relaxes none.
 */
abstract class LegacyBatchPublishController extends Controller
{
    /** Upper bound on a request-supplied page size — §19 denial-of-service. */
    public const MAX_PER_PAGE = 100;

    public function __construct(
        protected readonly LegacyBatchPublishWorkspaceService $workspace,
        protected readonly LegacyBatchPublishRunService $runs,
    ) {}

    abstract protected function adapter(): LegacyBatchPublishAdapter;

    abstract protected function routePrefix(): string;

    abstract protected function heading(): string;

    /** The REVIEWED queue, plus this publisher's open run if they have one. */
    public function index(Request $request): View
    {
        $adapter = $this->adapter();
        $this->assertMigrationEnabled($adapter);

        $run = $this->currentRunFor($request, $adapter);

        $queue = $this->workspace->queue(
            $adapter,
            $request->user(),
            ['patient' => $request->query('patient')],
            $this->perPage($request),
            $run,
        );

        return view('settings.rme.legacy-batch-publish.index', [
            'heading' => $this->heading(),
            'routePrefix' => $this->routePrefix(),
            'importType' => $adapter->importType(),
            'run' => $run,
            'paginator' => $queue['paginator'],
            'items' => $queue['items'],
            'counters' => $queue['counters'],
            'singleItemRoute' => $adapter->singleItemRouteName(),
            'recordRoute' => $adapter->recordRouteName(),
        ]);
    }

    /** Open a publication run. */
    public function store(Request $request): RedirectResponse
    {
        $adapter = $this->adapter();
        $this->assertMigrationEnabled($adapter);

        $run = $this->runs->open($adapter, $request->user());

        return redirect()
            ->route($this->routePrefix().'.show', $run->uuid)
            ->with('status', 'Sesi publikasi batch dibuka.');
    }

    /**
     * The selection + confirmation surface.
     *
     * §12's honest summary lives here: Selected / Eligible now / Blocked after
     * revalidation, and a button that names the number it will actually publish.
     */
    public function show(Request $request, string $run): View
    {
        $adapter = $this->adapter();
        $this->assertMigrationEnabled($adapter);

        $publishRun = $this->resolveOwnRun($request, $adapter, $run);

        $queue = $this->workspace->queue(
            $adapter,
            $request->user(),
            ['patient' => $request->query('patient')],
            $this->perPage($request),
            $publishRun,
        );

        return view('settings.rme.legacy-batch-publish.show', [
            'heading' => $this->heading(),
            'routePrefix' => $this->routePrefix(),
            'importType' => $adapter->importType(),
            'run' => $publishRun,
            'paginator' => $queue['paginator'],
            'items' => $queue['items'],
            'counters' => $queue['counters'],
            'singleItemRoute' => $adapter->singleItemRouteName(),
            'recordRoute' => $adapter->recordRouteName(),
        ]);
    }

    /** Record the selection, re-evaluating every chosen document. */
    public function select(SelectLegacyBatchPublishItemsRequest $request, string $run): RedirectResponse
    {
        $adapter = $this->adapter();
        $this->assertMigrationEnabled($adapter);

        $publishRun = $this->resolveOwnRun($request, $adapter, $run);

        $summary = $this->runs->select(
            $publishRun,
            $adapter,
            $request->user(),
            $request->importIds(),
        );

        return redirect()
            ->route($this->routePrefix().'.show', $publishRun->uuid)
            ->with('status', $this->selectionFlash($summary));
    }

    /** Publish one bounded pass. */
    public function publish(ExecuteLegacyBatchPublishRequest $request, string $run): RedirectResponse
    {
        $adapter = $this->adapter();
        $this->assertMigrationEnabled($adapter);

        $publishRun = $this->resolveOwnRun($request, $adapter, $run);

        $summary = $this->runs->publish(
            $publishRun,
            $adapter,
            $request->user(),
            $request->archiveAttributes(),
            $request->passLimit(),
        );

        return redirect()
            ->route($this->routePrefix().'.show', $publishRun->uuid)
            ->with('status', $this->publishFlash($summary));
    }

    /** Close a run without finishing. Published documents stay published. */
    public function abandon(Request $request, string $run): RedirectResponse
    {
        $adapter = $this->adapter();
        $this->assertMigrationEnabled($adapter);

        $publishRun = $this->resolveOwnRun($request, $adapter, $run);

        $this->runs->abandon($publishRun, $adapter, $request->user());

        return redirect()
            ->route($this->routePrefix().'.index')
            ->with('status', 'Sesi publikasi ditinggalkan. Dokumen yang sudah terbit tetap terbit.');
    }

    /**
     * An honest selection flash — §12 forbids a misleading "Publish All".
     *
     * @param  array{selected:int, eligible:int, refused:int, unavailable:int}  $summary
     */
    protected function selectionFlash(array $summary): string
    {
        $unavailable = $summary['unavailable'] ?? 0;

        if ($summary['refused'] === 0 && $unavailable === 0) {
            return sprintf('%d dokumen siap dipublikasikan.', $summary['eligible']);
        }

        $parts = [sprintf('Dipilih %d dokumen', $summary['selected'])];
        $parts[] = sprintf('%d siap dipublikasikan', $summary['eligible']);

        if ($summary['refused'] > 0) {
            $parts[] = sprintf('%d ditolak setelah pemeriksaan ulang', $summary['refused']);
        }

        // Reported even though no attempt row exists for these — the operator
        // must know their selection did not cover what they thought it did.
        if ($unavailable > 0) {
            $parts[] = sprintf('%d tidak tersedia pada cakupan Anda', $unavailable);
        }

        return implode(', ', $parts).'.';
    }

    /**
     * An honest publish flash. Partial success is reported as partial success —
     * never rounded up to "done", never reported as a failure, and
     * already-filed documents are named separately so the count cannot overstate
     * what the run created.
     *
     * @param  array{attempted:int, published:int, already:int, refused:int, remaining:int, refusal_counts:array<string,int>}  $summary
     */
    protected function publishFlash(array $summary): string
    {
        if ($summary['attempted'] === 0) {
            return 'Tidak ada dokumen yang perlu dipublikasikan.';
        }

        $parts = [sprintf('%d dokumen dipublikasikan', $summary['published'])];

        if ($summary['already'] > 0) {
            $parts[] = sprintf('%d sudah terbit sebelumnya', $summary['already']);
        }

        if ($summary['refused'] > 0) {
            $parts[] = sprintf('%d ditolak dan perlu diperiksa', $summary['refused']);
        }

        if ($summary['remaining'] > 0) {
            $parts[] = sprintf('%d menunggu proses lanjutan', $summary['remaining']);
        }

        return implode(', ', $parts).'.';
    }

    protected function perPage(Request $request): int
    {
        return max(1, min((int) $request->integer('per_page', 25), self::MAX_PER_PAGE));
    }

    /**
     * 404 when the migration capability is off, matching the canonical
     * controllers. A 403 would confirm the endpoint exists.
     */
    protected function assertMigrationEnabled(LegacyBatchPublishAdapter $adapter): void
    {
        abort_unless($adapter->migrationEnabled(), 404);
    }

    /**
     * Resolve a run by uuid, scoped to the acting publisher AND this route
     * group's archive.
     *
     * Both constraints are enforced in SQL rather than checked afterwards, so a
     * uuid belonging to another publisher or to the other archive is simply not
     * found. The service re-asserts both too — this is the HTTP boundary, not
     * the only one.
     */
    protected function resolveOwnRun(
        Request $request,
        LegacyBatchPublishAdapter $adapter,
        string $uuid,
    ): LegacyBatchPublishRun {
        $run = LegacyBatchPublishRun::query()
            ->where('uuid', $uuid)
            ->where('import_type', $adapter->importType())
            ->where('started_by', $request->user()?->getKey())
            ->first();

        abort_if($run === null, 404);

        return $run;
    }

    /** This publisher's most recent still-usable run, if any. */
    protected function currentRunFor(
        Request $request,
        LegacyBatchPublishAdapter $adapter,
    ): ?LegacyBatchPublishRun {
        return LegacyBatchPublishRun::query()
            ->where('import_type', $adapter->importType())
            ->where('started_by', $request->user()?->getKey())
            ->whereIn('status', [
                LegacyBatchPublishRunStatus::OPEN,
                LegacyBatchPublishRunStatus::PUBLISHING,
            ])
            ->orderByDesc('id')
            ->first();
    }
}
