<?php

namespace App\Modules\Satusehat\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Satusehat\Interfaces\SatusehatIdentifierRepositoryInterface;
use App\Modules\Satusehat\Models\SatusehatEntityIdentifier;
use App\Modules\Satusehat\Requests\StoreSatusehatIdentifierRequest;
use App\Modules\Satusehat\Services\SatusehatIdentifierService;
use App\Modules\Satusehat\Services\SatusehatIdentifierVerificationService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * Entity identifier governance UI. No external lookup is ever performed;
 * identifiers are entered/verified administratively. Sandbox/production never mix.
 */
class SatusehatIdentifierController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly SatusehatIdentifierRepositoryInterface $repository,
        private readonly SatusehatIdentifierService $service,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', SatusehatEntityIdentifier::class);

        $filters = [
            'environment' => $request->string('environment')->toString() ?: null,
            'entity_type' => $request->string('entity_type')->toString() ?: null,
            'status' => $request->string('status')->toString() ?: null,
        ];

        return view('satusehat.identifiers.index', [
            'identifiers' => $this->repository->paginate($filters),
            'filters' => $filters,
            'entityTypes' => SatusehatEntityIdentifier::ENTITY_TYPES,
            'statuses' => SatusehatEntityIdentifier::STATUSES,
            'environments' => (array) config('satusehat.allowed_environments'),
        ]);
    }

    public function store(StoreSatusehatIdentifierRequest $request): RedirectResponse
    {
        $this->authorize('create', SatusehatEntityIdentifier::class);

        $this->service->upsert($request->validated(), Auth::user());

        return back()->with('success', 'Identifier SATUSEHAT disimpan.');
    }

    public function deactivate(SatusehatEntityIdentifier $identifier): RedirectResponse
    {
        $this->authorize('update', $identifier);

        $this->service->deactivate($identifier, Auth::user());

        return back()->with('success', 'Identifier dinonaktifkan.');
    }

    public function verify(SatusehatEntityIdentifier $identifier, SatusehatIdentifierVerificationService $verification): RedirectResponse
    {
        $this->authorize('update', $identifier);

        $result = $verification->verify($identifier, Auth::user());

        return back()->with($result['verified'] ? 'success' : 'error',
            $result['verified']
                ? 'Identifier terverifikasi di sandbox SATUSEHAT.'
                : 'Verifikasi identifier belum berhasil (status: '.$result['status'].').');
    }
}
