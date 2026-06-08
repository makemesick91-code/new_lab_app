<?php

namespace App\Modules\Treatment\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Treatment\Models\Treatment;
use App\Modules\Treatment\Requests\StoreTreatmentRequest;
use App\Modules\Treatment\Requests\UpdateTreatmentRequest;
use App\Modules\Treatment\Services\TreatmentService;
use App\Modules\TreatmentCategory\Services\TreatmentCategoryService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class TreatmentController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly TreatmentService $treatments,
        private readonly TreatmentCategoryService $categories,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Treatment::class);

        $isActive = $request->input('is_active');
        $requiresLab = $request->input('requires_lab');

        $filters = [
            'search' => $request->string('search')->toString() ?: null,
            'treatment_category_id' => $request->integer('treatment_category_id') ?: null,
            'is_active' => ($isActive === null || $isActive === '') ? null : $request->boolean('is_active'),
            'requires_lab' => ($requiresLab === null || $requiresLab === '') ? null : $request->boolean('requires_lab'),
        ];

        return view('settings.treatments.index', [
            'treatments' => $this->treatments->paginate($filters, 10),
            'filters' => $filters,
            'categories' => $this->categories->listActive(),
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', Treatment::class);

        return view('settings.treatments.create', [
            'categories' => $this->categories->listActive(),
        ]);
    }

    public function store(StoreTreatmentRequest $request): RedirectResponse
    {
        $this->authorize('create', Treatment::class);

        $this->treatments->create($request->validated());

        return redirect()->route('settings.treatments.index')->with('status', 'Perawatan berhasil ditambahkan.');
    }

    public function edit(Treatment $treatment): View
    {
        $this->authorize('update', $treatment);

        return view('settings.treatments.edit', [
            'treatment' => $treatment,
            'categories' => $this->categories->listActive(),
        ]);
    }

    public function update(UpdateTreatmentRequest $request, Treatment $treatment): RedirectResponse
    {
        $this->authorize('update', $treatment);

        $this->treatments->update($treatment, $request->validated());

        return redirect()->route('settings.treatments.index')->with('status', 'Perawatan berhasil diperbarui.');
    }

    public function destroy(Treatment $treatment): RedirectResponse
    {
        $this->authorize('delete', $treatment);

        $this->treatments->delete($treatment);

        return redirect()->route('settings.treatments.index')->with('status', 'Perawatan berhasil dihapus.');
    }
}
