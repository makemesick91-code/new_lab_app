<?php

namespace App\Modules\LabOrder\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\LabOrder\Models\ExternalLab;
use App\Modules\LabOrder\Requests\StoreExternalLabRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * LAB-WORKFLOW-V2 (Phase 3) — external lab (vendor) master data.
 * Minimal list + create; gated by manage_lab_orders at the route layer.
 */
class ExternalLabController extends Controller
{
    public function index(): View
    {
        return view('lab-workflow.external-labs.index', [
            'externalLabs' => ExternalLab::query()
                ->withCount('dispatches')
                ->orderBy('name')
                ->paginate(20),
        ]);
    }

    public function store(StoreExternalLabRequest $request): RedirectResponse
    {
        ExternalLab::create($request->validated() + ['is_active' => true]);

        return back()->with('success', 'Lab eksternal ditambahkan.');
    }
}
