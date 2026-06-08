<?php

namespace App\Modules\Tariff\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Tariff\Models\Tariff;
use App\Modules\Tariff\Requests\StoreTariffRequest;
use App\Modules\Tariff\Requests\UpdateTariffRequest;
use App\Modules\Tariff\Services\TariffService;
use App\Modules\Treatment\Services\TreatmentService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class TariffController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly TariffService $tariffs,
        private readonly TreatmentService $treatments,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Tariff::class);

        $isActive = $request->input('is_active');
        $requiresLab = $request->input('requires_lab');

        $filters = [
            'search' => $request->string('search')->toString() ?: null,
            'treatment_id' => $request->integer('treatment_id') ?: null,
            'is_active' => ($isActive === null || $isActive === '') ? null : $request->boolean('is_active'),
            'requires_lab' => ($requiresLab === null || $requiresLab === '') ? null : $request->boolean('requires_lab'),
        ];

        return view('settings.tariffs.index', [
            'tariffs' => $this->tariffs->paginate($filters, 10),
            'filters' => $filters,
            'treatments' => $this->treatments->listActive(),
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', Tariff::class);

        return view('settings.tariffs.create', [
            'treatments' => $this->treatments->listActive(),
        ]);
    }

    public function store(StoreTariffRequest $request): RedirectResponse
    {
        $this->authorize('create', Tariff::class);

        $this->tariffs->create($request->validated());

        return redirect()->route('settings.tariffs.index')->with('status', 'Tarif berhasil ditambahkan.');
    }

    public function edit(Tariff $tariff): View
    {
        $this->authorize('update', $tariff);

        return view('settings.tariffs.edit', [
            'tariff' => $tariff,
            'treatments' => $this->treatments->listActive(),
        ]);
    }

    public function update(UpdateTariffRequest $request, Tariff $tariff): RedirectResponse
    {
        $this->authorize('update', $tariff);

        $this->tariffs->update($tariff, $request->validated());

        return redirect()->route('settings.tariffs.index')->with('status', 'Tarif berhasil diperbarui.');
    }

    public function destroy(Tariff $tariff): RedirectResponse
    {
        $this->authorize('delete', $tariff);

        $this->tariffs->delete($tariff);

        return redirect()->route('settings.tariffs.index')->with('status', 'Tarif berhasil dihapus.');
    }
}
