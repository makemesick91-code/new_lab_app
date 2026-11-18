<?php

namespace App\Modules\ClinicalPhoto\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\ClinicalPhoto\Models\RmeClinicalPhoto;
use App\Modules\ClinicalPhoto\Requests\StoreRmeClinicalPhotoRequest;
use App\Modules\ClinicalPhoto\Requests\VoidRmeClinicalPhotoRequest;
use App\Modules\ClinicalPhoto\Services\RmeClinicalPhotoService;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * DMS memo M4.4 / M1.x, PTM PR4 (rule 242) — clinical photos, thin by design:
 * route permission → FormRequest (flag 404, policy) or flag 404 + policy →
 * RmeClinicalPhotoService, which decides everything again. The object key never
 * leaves the server (rule 121); the image is served private, no-store, nosniff.
 * The gallery UI is PR5.
 */
class RmeClinicalPhotoController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly RmeClinicalPhotoService $photos,
    ) {}

    public function store(StoreRmeClinicalPhotoRequest $request, ClinicVisit $clinicVisit): JsonResponse|RedirectResponse
    {
        $photo = $this->photos->upload($clinicVisit, $request->user(), $request->file('photo'));

        if ($request->expectsJson()) {
            return response()->json(['id' => $photo->getKey()], 201);
        }

        return back()->with('status', 'Foto klinis tersimpan.');
    }

    /** Rule 242 §9 — the supervised void; never a delete. */
    public function void(VoidRmeClinicalPhotoRequest $request, RmeClinicalPhoto $clinicalPhoto): JsonResponse|RedirectResponse
    {
        $photo = $this->photos->void($clinicalPhoto, $request->user(), (string) $request->validated('void_reason'));

        if ($request->expectsJson()) {
            return response()->json(['id' => $photo->getKey(), 'voided' => true]);
        }

        return back()->with('status', 'Foto klinis dibatalkan.');
    }

    public function image(Request $request, RmeClinicalPhoto $clinicalPhoto): Response
    {
        abort_unless($this->photos->enabled(), 404);

        $this->authorize('view', $clinicalPhoto);

        $bytes = $this->photos->imageFor($clinicalPhoto, $request->user());

        return response($bytes, 200, [
            'Content-Type' => 'image/jpeg',
            'Content-Length' => (string) strlen($bytes),
            'Content-Disposition' => 'inline; filename="foto-klinis-'.$clinicalPhoto->getKey().'.jpg"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store, max-age=0',
        ]);
    }
}
