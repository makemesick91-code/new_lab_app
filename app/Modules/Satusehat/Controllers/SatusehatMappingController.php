<?php

namespace App\Modules\Satusehat\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Satusehat\Interfaces\SatusehatMappingRepositoryInterface;
use App\Modules\Satusehat\Models\SatusehatAuditLog;
use App\Modules\Satusehat\Models\SatusehatCodeMapping;
use App\Modules\Satusehat\Requests\StoreSatusehatMappingRequest;
use App\Modules\Satusehat\Services\SatusehatMappingService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * Mapping governance UI. Thin controller; versioning + single-active enforcement
 * live in SatusehatMappingService. Active mappings are never edited in place.
 */
class SatusehatMappingController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly SatusehatMappingRepositoryInterface $repository,
        private readonly SatusehatMappingService $service,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', SatusehatCodeMapping::class);

        $filters = [
            'environment' => $request->string('environment')->toString() ?: null,
            'local_entity_type' => $request->string('local_entity_type')->toString() ?: null,
            'status' => $request->string('status')->toString() ?: null,
            'search' => $request->string('search')->trim()->toString() ?: null,
        ];

        return view('satusehat.mappings.index', [
            'mappings' => $this->repository->paginate($filters),
            'filters' => $filters,
            'statuses' => SatusehatCodeMapping::STATUSES,
            'environments' => (array) config('satusehat.allowed_environments'),
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', SatusehatCodeMapping::class);

        return view('satusehat.mappings.create', [
            'environments' => (array) config('satusehat.allowed_environments'),
        ]);
    }

    public function store(StoreSatusehatMappingRequest $request): RedirectResponse
    {
        $this->authorize('create', SatusehatCodeMapping::class);

        $mapping = $this->service->createDraft($request->validated(), Auth::user());

        return redirect()
            ->route('satusehat.mappings.show', $mapping)
            ->with('success', 'Draft mapping dibuat.');
    }

    public function show(SatusehatCodeMapping $mapping): View
    {
        $this->authorize('view', $mapping);

        $versions = SatusehatCodeMapping::query()
            ->where('environment', $mapping->environment)
            ->where('local_entity_type', $mapping->local_entity_type)
            ->where('target_resource_type', $mapping->target_resource_type)
            ->when($mapping->local_entity_id !== null,
                fn ($q) => $q->where('local_entity_id', $mapping->local_entity_id),
                fn ($q) => $q->whereNull('local_entity_id'))
            ->when($mapping->local_code !== null && $mapping->local_code !== '',
                fn ($q) => $q->where('local_code', $mapping->local_code),
                fn ($q) => $q->whereNull('local_code'))
            ->orderByDesc('version')
            ->get();

        $timeline = SatusehatAuditLog::query()
            ->where('entity_type', 'code_mapping')
            ->where('entity_id', $mapping->id)
            ->with('actor:id,name')
            ->orderByDesc('id')
            ->get();

        return view('satusehat.mappings.show', [
            'mapping' => $mapping,
            'versions' => $versions,
            'timeline' => $timeline,
        ]);
    }

    public function review(SatusehatCodeMapping $mapping): RedirectResponse
    {
        $this->authorize('review', $mapping);
        $this->service->review($mapping, Auth::user());

        return back()->with('success', 'Mapping direview.');
    }

    public function verify(Request $request, SatusehatCodeMapping $mapping): RedirectResponse
    {
        $this->authorize('review', $mapping);

        $data = $request->validate([
            'official_source' => ['required', 'string', 'max:500'],
            'official_source_version' => ['nullable', 'string', 'max:100'],
        ]);

        $this->service->verify($mapping, $data, Auth::user());

        return back()->with('success', 'Mapping diverifikasi terhadap sumber resmi.');
    }

    public function activate(SatusehatCodeMapping $mapping): RedirectResponse
    {
        $this->authorize('activate', $mapping);
        $this->service->activate($mapping, Auth::user());

        return back()->with('success', 'Mapping diaktifkan.');
    }

    public function deprecate(SatusehatCodeMapping $mapping): RedirectResponse
    {
        $this->authorize('deprecate', $mapping);
        $this->service->deprecate($mapping, Auth::user());

        return back()->with('success', 'Mapping diusangkan.');
    }
}
