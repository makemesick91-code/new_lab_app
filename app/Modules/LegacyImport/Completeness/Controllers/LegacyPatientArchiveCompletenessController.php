<?php

declare(strict_types=1);

namespace App\Modules\LegacyImport\Completeness\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\LegacyImport\Completeness\Requests\LegacyPatientArchiveCompletenessFilterRequest;
use App\Modules\LegacyImport\Completeness\Services\LegacyPatientArchiveCompletenessService;
use App\Modules\LegacyImport\Completeness\Support\LegacyCompletenessFilter;
use Illuminate\Contracts\View\View;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * FEATURE-LEGACY-PATIENT-DOCUMENT-COMPLETENESS-1 — "Kelengkapan Arsip Pasien
 * Legacy".
 *
 * THIN BY CONSTRUCTION, following the sibling hub controller. Resolve the actor,
 * ask the service, render. There is no query, no branch arithmetic and no
 * completeness rule here: every state, count and scope on the page was decided
 * in the service from the server's own authorities.
 *
 * DEFENCE IN DEPTH, NOT DEFENCE IN THE ROUTE FILE. The route already carries
 * `permission:` middleware, and this controller re-checks reachability anyway —
 * a route file is edited far more often than a controller, and a middleware list
 * that silently loses an entry must not silently open a page over patient data.
 *
 * ONE VERB, ONE ACTION. This controller has a single `index` and the module
 * registers a single GET route. There is no store, update, destroy, publish,
 * review, void or cancel here, and there must never be one: the page is a
 * monitor over lifecycles it has no authority over.
 */
class LegacyPatientArchiveCompletenessController extends Controller
{
    public function __construct(
        private readonly LegacyPatientArchiveCompletenessService $completeness,
    ) {}

    public function index(LegacyPatientArchiveCompletenessFilterRequest $request): View
    {
        if (! $this->completeness->enabled()) {
            // "This page does not exist" rather than 403: the actor's authority
            // is not in question when the whole surface is switched off, and a
            // 403 would wrongly imply it was.
            throw new NotFoundHttpException;
        }

        $user = $request->user();

        abort_if($user === null, 403);
        abort_unless($this->completeness->isReachableBy($user), 403);

        $query = $this->completeness->resolveQuery(
            user: $user,
            filter: $request->statusFilter(),
            search: $request->searchTerm(),
            requestedBranchId: $request->requestedBranchId(),
        );

        $rows = $this->completeness->rows($query);

        return view('settings.legacy-patient-completeness.index', [
            'rows' => $rows->appends($request->query()),
            'summary' => $this->completeness->summary($query),
            'branchOptions' => $this->completeness->branchOptions($query),
            'filterOptions' => LegacyCompletenessFilter::options(),
            'activeFilter' => $query->filter,
            'activeSearch' => $query->search,
            // The EFFECTIVE branch filter, read back from the resolved query
            // rather than echoed from the request: a branch the actor may not
            // read was dropped on the way in, and the form must show what is
            // actually in force rather than what was asked for.
            'activeBranchId' => count($query->branchIds) === 1 ? $query->branchIds[0] : null,
            'governsEveryBranch' => $this->completeness->governsEveryBranch($user),
            'scopeIsEmpty' => $query->deniesEverything(),
        ]);
    }
}
