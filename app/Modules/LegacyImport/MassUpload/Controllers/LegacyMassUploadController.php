<?php

declare(strict_types=1);

namespace App\Modules\LegacyImport\MassUpload\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\LegacyImport\MassUpload\Adapters\LegacyMassUploadAdapter;
use App\Modules\LegacyImport\MassUpload\Exceptions\LegacyMassUploadPackageRejected;
use App\Modules\LegacyImport\MassUpload\Models\LegacyMassUploadBatch;
use App\Modules\LegacyImport\MassUpload\Models\LegacyMassUploadItem;
use App\Modules\LegacyImport\MassUpload\Requests\StoreLegacyMassUploadPackageRequest;
use App\Modules\LegacyImport\MassUpload\Services\LegacyMassUploadBatchService;
use App\Modules\LegacyImport\MassUpload\Services\LegacyMassUploadDispatchService;
use App\Modules\LegacyImport\MassUpload\Services\LegacyMassUploadManifestParser;
use App\Modules\LegacyImport\MassUpload\Services\LegacyMassUploadReportService;
use App\Modules\LegacyImport\MassUpload\Support\LegacyMassUploadItemStatus;
use App\Modules\LegacyRme\Support\LegacyRmeWorkspaceScope;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Shared HTTP surface for both mass-upload types.
 *
 * THIN BY CONTRACT. It resolves, authorizes, and hands off. No eligibility
 * decision, no date arithmetic, no branch logic and no storage handling lives
 * here — all of that belongs to the services, and the services delegate the
 * clinical parts to the canonical single-item domain (§6).
 *
 * Two subclasses rather than one controller with a `$type` request parameter.
 * A type that arrived in the request would be a thing an operator could change,
 * and "which clinical domain am I writing to" must never be that. Here it is
 * fixed by the route, so the RME routes can only ever reach the RME adapter.
 */
abstract class LegacyMassUploadController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        protected readonly LegacyMassUploadBatchService $batches,
        protected readonly LegacyMassUploadDispatchService $dispatcher,
        protected readonly LegacyMassUploadReportService $reports,
        protected readonly LegacyMassUploadManifestParser $manifests,
        protected readonly LegacyRmeWorkspaceScope $scope,
    ) {}

    /** LegacyImportType constant this surface writes to. */
    abstract protected function importType(): string;

    /** The adapter that owns this type's clinical delegation. */
    abstract protected function adapter(): LegacyMassUploadAdapter;

    /** Route-name prefix, e.g. `settings.rme.legacy-mass-imports`. */
    abstract protected function routePrefix(): string;

    /** Blade namespace, e.g. `settings.rme.legacy-mass-imports`. */
    abstract protected function viewPrefix(): string;

    abstract protected function heading(): string;

    public function index(Request $request): View
    {
        $this->authorizeType('viewAnyOfType');

        $status = trim((string) $request->query('status', ''));

        $batches = LegacyMassUploadBatch::query()
            ->where('import_type', $this->importType())
            ->when($status !== '', fn ($query) => $query->where('status', $status))
            // Branch isolation, plus the operator's own batches. A batch is a
            // work-queue row, so someone whose branch context changed mid-shift
            // must still be able to read the outcome of work they submitted.
            ->where(function ($query) use ($request): void {
                $query->where('created_by', $request->user()?->getKey());

                $branchIds = $this->scope->branchIdsFor($request->user());

                if ($branchIds !== []) {
                    $query->orWhereIn('origin_branch_id', $branchIds);
                }

                if ($this->scope->includesUnscopedRowsFor($request->user())) {
                    $query->orWhereNull('origin_branch_id');
                }
            })
            ->withCount('items')
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        return view($this->viewPrefix().'.index', [
            'batches' => $batches,
            'status' => $status,
            'heading' => $this->heading(),
            'routePrefix' => $this->routePrefix(),
            'importType' => $this->importType(),
        ]);
    }

    public function create(): View
    {
        $this->authorizeType('createOfType');

        return view($this->viewPrefix().'.create', [
            'heading' => $this->heading(),
            'routePrefix' => $this->routePrefix(),
            'importType' => $this->importType(),
            'template' => $this->manifests->template($this->importType()),
        ]);
    }

    /**
     * Downloadable manifest template so an operator starts from the right
     * columns rather than guessing them (§59).
     */
    public function manifestTemplate(): StreamedResponse
    {
        $this->authorizeType('createOfType');

        return $this->reports->streamManifestTemplate($this->importType(), $this->manifests);
    }

    public function store(StoreLegacyMassUploadPackageRequest $request): RedirectResponse
    {
        $this->authorizeType('createOfType');

        $this->grantIntakeExecutionBudget();

        try {
            $batch = $this->batches->store(
                $request->package(),
                $this->adapter(),
                $request->user(),
            );
        } catch (LegacyMassUploadPackageRejected $rejected) {
            // A package refusal belongs on the upload form, not on a 500 page.
            throw $rejected->toValidationException('package');
        }

        return redirect()
            ->route($this->routePrefix().'.show', $batch)
            ->with('status', 'Paket diterima dan diperiksa. Tinjau hasil pemeriksaan sebelum memulai proses.');
    }

    public function show(Request $request, LegacyMassUploadBatch $batch): View
    {
        $this->authorizeBatch($batch, 'view');

        $filter = trim((string) $request->query('item_status', ''));

        $items = LegacyMassUploadItem::query()
            ->where('mass_upload_batch_id', $batch->getKey())
            ->when(
                $filter !== '' && LegacyMassUploadItemStatus::isValid($filter),
                fn ($query) => $query->where('status', $filter),
            )
            ->orderBy('row_number')
            ->paginate(50)
            ->withQueryString();

        return view($this->viewPrefix().'.show', [
            'batch' => $batch,
            'items' => $items,
            'itemStatus' => $filter,
            'heading' => $this->heading(),
            'routePrefix' => $this->routePrefix(),
            'statusCounts' => [
                LegacyMassUploadItemStatus::ELIGIBLE => (int) $batch->eligible_items,
                LegacyMassUploadItemStatus::WARNING => (int) $batch->warning_items,
                LegacyMassUploadItemStatus::BLOCKED => (int) $batch->blocked_items,
                LegacyMassUploadItemStatus::DISPATCHED => (int) $batch->dispatched_items,
                LegacyMassUploadItemStatus::FAILED => (int) $batch->failed_items,
            ],
        ]);
    }

    /**
     * Confirm the reviewed batch and run the first bounded pass.
     *
     * Confirmation cannot widen anything: only rows the SERVER marked eligible
     * or warning are ever created, and a blocked row stays blocked no matter
     * what the operator submits (§19).
     */
    public function confirm(Request $request, LegacyMassUploadBatch $batch): RedirectResponse
    {
        $this->authorizeBatch($batch, 'confirm');

        $confirmed = $this->dispatcher->confirm($batch, $request->user());

        $confirmed = $this->dispatcher->dispatchPass($confirmed, $this->adapter(), $request->user());

        return redirect()
            ->route($this->routePrefix().'.show', $confirmed)
            ->with('status', $this->dispatchMessage($confirmed));
    }

    /**
     * Continue a batch that still has work left (§26).
     *
     * Bounded dispatch means a large archive legitimately needs several passes;
     * this is how an operator (or a resumed session) drives the next one.
     */
    public function dispatchItems(Request $request, LegacyMassUploadBatch $batch): RedirectResponse
    {
        $this->authorizeBatch($batch, 'dispatchItems');

        $batch = $this->dispatcher->dispatchPass($batch, $this->adapter(), $request->user());

        return redirect()
            ->route($this->routePrefix().'.show', $batch)
            ->with('status', $this->dispatchMessage($batch));
    }

    public function cancel(Request $request, LegacyMassUploadBatch $batch): RedirectResponse
    {
        $this->authorizeBatch($batch, 'cancel');

        $batch = $this->batches->cancel($batch, $request->user());

        return redirect()
            ->route($this->routePrefix().'.index')
            ->with('status', 'Batch dibatalkan. Tidak ada dokumen klinis yang dibuat dari batch ini.');
    }

    public function report(LegacyMassUploadBatch $batch): StreamedResponse
    {
        $this->authorizeBatch($batch, 'downloadReport');

        return $this->reports->stream($batch);
    }

    /**
     * Authorize a type-scoped ability.
     *
     * The policy methods take the import type explicitly because one policy
     * serves both surfaces; passing it from the controller (never the request)
     * is what keeps the type route-fixed.
     */
    protected function authorizeType(string $ability): void
    {
        $this->authorize($ability, [LegacyMassUploadBatch::class, $this->importType()]);
    }

    /**
     * Authorize an ability on a batch, and refuse a batch of the wrong type.
     *
     * The type check is an IDOR boundary, not a nicety: without it, an
     * odontogram batch uuid pasted into an RME route would be driven by the RME
     * adapter and could file a chart as a medical record.
     */
    protected function authorizeBatch(LegacyMassUploadBatch $batch, string $ability): void
    {
        abort_unless($batch->import_type === $this->importType(), 404);

        $this->authorize($ability, $batch);
    }

    /**
     * Raise THIS request's wall-clock budget for package intake only.
     *
     * BUGFIX-MASS-UPLOAD-REQUEST-ENTITY-TOO-LARGE-1. Receiving a package the
     * size of package.max_bytes and then walking its entries cannot finish
     * inside the pool's 30s default, but relaxing max_execution_time pool-wide
     * would remove that ceiling from every other request too. max_execution_time
     * is PHP_INI_ALL, so this route raises it for itself and nothing else.
     *
     * Bounded and never unlimited: a non-positive configured value is ignored
     * rather than treated as 0, because 0 means "no limit" to PHP and would let
     * a failed intake hold one of the five pool workers indefinitely.
     */
    private function grantIntakeExecutionBudget(): void
    {
        $seconds = (int) config('legacy_mass_upload.intake.max_execution_seconds', 900);

        if ($seconds <= 0) {
            return;
        }

        @set_time_limit($seconds);
    }

    private function dispatchMessage(LegacyMassUploadBatch $batch): string
    {
        if ($batch->isTerminal()) {
            return sprintf(
                'Batch selesai. %d dokumen dibuat, %d baris ditolak, %d baris gagal teknis.',
                (int) $batch->dispatched_items,
                (int) $batch->blocked_items,
                (int) $batch->failed_items,
            );
        }

        return sprintf(
            'Sebagian batch diproses (%d dokumen dibuat). Lanjutkan proses untuk baris yang tersisa.',
            (int) $batch->dispatched_items,
        );
    }
}
