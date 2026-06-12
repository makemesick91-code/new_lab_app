<?php

namespace App\Modules\Branch\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Branch\Models\Branch;
use App\Modules\Branch\Requests\StoreBranchRequest;
use App\Modules\Branch\Requests\UpdateBranchRequest;
use App\Modules\Branch\Services\BranchService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Master Data Cabang CRUD (Sprint 23 Phase 23.7).
 *
 * Standard Controller → Request → Service → Repository wiring for branch
 * administration. Branches drive the multi-branch modules (RME + Inventory)
 * only; Laboratory is single / global and is not represented here.
 *
 * Destroy is a soft delete (reversible) and the default head-office branch
 * (code MAIN) is protected from deletion so legacy/unscoped rows keep an anchor.
 */
class BranchController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly BranchService $branchService,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Branch::class);

        return view('settings.branches.index', [
            'branches' => $this->branchService->list(
                ['search' => $request->string('search')->toString() ?: null],
                10
            ),
            'search' => $request->string('search')->toString(),
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', Branch::class);

        return view('settings.branches.create');
    }

    public function store(StoreBranchRequest $request): RedirectResponse
    {
        $this->authorize('create', Branch::class);

        $this->branchService->create($request->validated());

        return redirect()->route('settings.branches.index')->with('status', 'Cabang berhasil dibuat.');
    }

    public function edit(Branch $branch): View
    {
        $this->authorize('update', $branch);

        return view('settings.branches.edit', ['branch' => $branch]);
    }

    public function update(UpdateBranchRequest $request, Branch $branch): RedirectResponse
    {
        $this->authorize('update', $branch);

        $this->branchService->update($branch, $request->validated());

        return redirect()->route('settings.branches.index')->with('status', 'Cabang berhasil diperbarui.');
    }

    public function destroy(Branch $branch): RedirectResponse
    {
        $this->authorize('delete', $branch);

        // The default head-office branch anchors legacy/unscoped rows — never delete it.
        if ($branch->code === Branch::MAIN_CODE) {
            return redirect()->route('settings.branches.index')
                ->with('error', 'Cabang utama tidak dapat dihapus.');
        }

        $this->branchService->delete($branch);

        return redirect()->route('settings.branches.index')->with('status', 'Cabang berhasil dihapus.');
    }
}
