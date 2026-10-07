<?php

namespace App\Modules\PatientMerge\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Branch\Services\BranchService;
use App\Modules\PatientMerge\Models\PatientMergeCase;
use App\Modules\PatientMerge\Services\PatientDuplicateDetectionService;
use App\Modules\PatientMerge\Services\PatientMergeDashboardService;
use App\Modules\PatientMerge\Services\PatientMergeScope;
use App\Modules\PatientMerge\Services\PatientRmAliasService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Read-only pages: Dashboard, Deteksi Duplikat, RM Alias. */
class PatientMergeOverviewController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly PatientMergeDashboardService $dashboard,
        private readonly PatientDuplicateDetectionService $detection,
        private readonly PatientRmAliasService $aliases,
        private readonly PatientMergeScope $scope,
        private readonly BranchService $branches,
    ) {}

    public function dashboard(Request $request): View
    {
        $this->authorize('viewAny', PatientMergeCase::class);

        return view('patient-merge.dashboard', ['overview' => $this->dashboard->overview($request->user())]);
    }

    public function candidates(Request $request): View
    {
        $this->authorize('viewAny', PatientMergeCase::class);
        $filters = $request->only(['confidence', 'signal', 'branch_id']);
        $scopeIds = $this->scope->branchIdsFor($request->user());

        return view('patient-merge.candidates', [
            'pairs' => $this->detection->paginate($request->user(), $filters, $request->integer('page', 1)),
            'filters' => $filters,
            'branches' => $this->branches->listRmeEnabled()->whereIn('id', $scopeIds)->values(),
        ]);
    }

    public function aliases(Request $request): View
    {
        $this->authorize('viewAny', PatientMergeCase::class);
        $filters = $request->only(['rm', 'state']);

        return view('patient-merge.aliases', [
            'aliases' => $this->aliases->paginate($request->user(), $filters),
            'filters' => $filters,
        ]);
    }
}
