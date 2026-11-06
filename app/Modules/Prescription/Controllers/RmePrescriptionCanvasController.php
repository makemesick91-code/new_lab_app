<?php

namespace App\Modules\Prescription\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Prescription\Models\RmePrescription;
use App\Support\Storage\ClinicalEvidenceStorage;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * STORAGE-PUBLIC-CLINICAL-EVIDENCE-1 — the only read path for prescription and
 * doctor-signature canvases.
 *
 * Authorisation is RmePrescriptionPolicy::view (role, branch isolation and
 * visit scoping). The `kind` segment is validated against a fixed allow-list so
 * a request can never select an arbitrary column or path.
 *
 * Mirrors RmeVisitConsentController@signature.
 */
class RmePrescriptionCanvasController extends Controller
{
    use AuthorizesRequests;

    private const KINDS = ['prescription', 'signature'];

    /**
     * DMS-RME-REVISION-PROGRAM-6 / P6-RX-03 — the argument MUST be named after
     * the route segment `{rmePrescription}`. Implicit binding matches by name;
     * under the old name `$prescription` it never ran, the container injected an
     * EMPTY model, the policy refused it and the stored canvases never loaded on
     * the prescription page (print was unaffected: it embeds them inline).
     */
    public function show(RmePrescription $rmePrescription, string $kind): StreamedResponse
    {
        $this->authorize('view', $rmePrescription);

        abort_unless(in_array($kind, self::KINDS, true), 404);

        $path = $kind === 'signature'
            ? $rmePrescription->doctor_signature_canvas_path
            : $rmePrescription->prescription_canvas_path;

        abort_if($path === null || $path === '', 404);

        if (ClinicalEvidenceStorage::isInlineDataUri($path)) {
            $comma = strpos($path, ',');
            $binary = $comma === false ? '' : (string) base64_decode(substr($path, $comma + 1), true);

            abort_if($binary === '', 404);

            return new StreamedResponse(static function () use ($binary): void {
                echo $binary;
            }, 200, [
                'Content-Type' => 'image/png',
                'X-Content-Type-Options' => 'nosniff',
                'Cache-Control' => 'private, no-store, max-age=0',
            ]);
        }

        $disk = ClinicalEvidenceStorage::disk();

        abort_unless($disk->exists($path), 404);

        return $disk->response($path, basename($path), [
            'Content-Type' => 'image/png',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store, max-age=0',
        ]);
    }
}
