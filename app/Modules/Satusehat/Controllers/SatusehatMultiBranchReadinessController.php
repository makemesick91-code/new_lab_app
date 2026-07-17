<?php

namespace App\Modules\Satusehat\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Satusehat\Services\Pilot\SatusehatExecutiveReadinessService;
use App\Modules\Satusehat\Services\Pilot\SatusehatMultiBranchReadinessService;
use App\Modules\Satusehat\Support\SatusehatWorkspaceScope;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * SATUSEHAT-4D — read-only comparative multi-branch readiness matrix + executive
 * dashboard. Branch-scoped server-side (never trusts a request branch id),
 * aggregate/PII-free, and always shows external submission blocked.
 */
class SatusehatMultiBranchReadinessController extends Controller
{
    public function __construct(
        private readonly SatusehatMultiBranchReadinessService $matrix,
        private readonly SatusehatExecutiveReadinessService $executive,
        private readonly SatusehatWorkspaceScope $scope,
    ) {}

    public function index(Request $request): View
    {
        $branchIds = $this->scope->branchIdsFor($request->user());

        $filters = [
            'wave_id' => $request->integer('wave_id') ?: null,
            'stage' => $request->string('stage')->toString() ?: null,
            'promotion_eligible' => $request->query('promotion_eligible'),
            'search' => $request->string('search')->toString() ?: null,
        ];

        $rows = $this->matrix->matrix($branchIds, array_filter($filters, fn ($v) => $v !== null && $v !== ''));
        $summary = $this->matrix->summary($rows);

        $paginator = $this->paginate($rows, (int) config('satusehat_pilot.multi_branch.matrix.per_page', 25), $request);

        return view('satusehat.multi-branch.index', [
            'rows' => $paginator,
            'summary' => $summary,
            'filters' => $filters,
        ]);
    }

    public function executive(Request $request): View
    {
        $branchIds = $this->scope->branchIdsFor($request->user());

        return view('satusehat.multi-branch.executive', [
            'overview' => $this->executive->overview($branchIds),
            'windows' => $this->executive->governanceWindows($branchIds),
        ]);
    }

    /**
     * @param  list<array<string,mixed>>  $rows
     */
    private function paginate(array $rows, int $perPage, Request $request): LengthAwarePaginator
    {
        $page = max(1, (int) $request->integer('page', 1));
        $items = array_slice($rows, ($page - 1) * $perPage, $perPage);

        return new LengthAwarePaginator(
            new Collection($items),
            count($rows),
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()],
        );
    }
}
