<?php

namespace App\Modules\TreatmentCategory\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\TreatmentCategory\Models\TreatmentCategory;
use App\Modules\TreatmentCategory\Requests\StoreTreatmentCategoryRequest;
use App\Modules\TreatmentCategory\Requests\UpdateTreatmentCategoryRequest;
use App\Modules\TreatmentCategory\Services\TreatmentCategoryService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class TreatmentCategoryController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly TreatmentCategoryService $categories,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', TreatmentCategory::class);

        $isActive = $request->input('is_active');

        $filters = [
            'search' => $request->string('search')->toString() ?: null,
            'is_active' => ($isActive === null || $isActive === '') ? null : $request->boolean('is_active'),
        ];

        return view('settings.treatment-categories.index', [
            'categories' => $this->categories->paginate($filters, 10),
            'filters' => $filters,
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', TreatmentCategory::class);

        return view('settings.treatment-categories.create');
    }

    public function store(StoreTreatmentCategoryRequest $request): RedirectResponse
    {
        $this->authorize('create', TreatmentCategory::class);

        $this->categories->create($request->validated());

        return redirect()->route('settings.treatment-categories.index')->with('status', 'Kategori perawatan berhasil ditambahkan.');
    }

    public function edit(TreatmentCategory $treatmentCategory): View
    {
        $this->authorize('update', $treatmentCategory);

        return view('settings.treatment-categories.edit', [
            'category' => $treatmentCategory,
        ]);
    }

    public function update(UpdateTreatmentCategoryRequest $request, TreatmentCategory $treatmentCategory): RedirectResponse
    {
        $this->authorize('update', $treatmentCategory);

        $this->categories->update($treatmentCategory, $request->validated());

        return redirect()->route('settings.treatment-categories.index')->with('status', 'Kategori perawatan berhasil diperbarui.');
    }

    public function destroy(TreatmentCategory $treatmentCategory): RedirectResponse
    {
        $this->authorize('delete', $treatmentCategory);

        $this->categories->delete($treatmentCategory);

        return redirect()->route('settings.treatment-categories.index')->with('status', 'Kategori perawatan berhasil dihapus.');
    }
}
