<?php

namespace App\Modules\MedicalRecord\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\MedicalRecord\Models\MedicalRecordHandwriting;
use App\Modules\MedicalRecord\Models\MedicalRecordHandwritingPage;
use App\Support\Storage\ClinicalEvidenceStorage;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * STORAGE-PUBLIC-CLINICAL-EVIDENCE-1 — the only read path for RME handwriting
 * binaries.
 *
 * Thin by design: every decision lives elsewhere. Authorisation is
 * MedicalRecordPolicy::view, which already enforces role, branch isolation and
 * doctor/patient scoping; storage lives behind ClinicalEvidenceStorage. The
 * filesystem key is never echoed to the client, and knowing an id grants
 * nothing without an authorised session.
 *
 * Mirrors LabWorkflowEvidenceController@show, the established pattern for
 * serving private clinical evidence in this codebase.
 */
class MedicalRecordHandwritingImageController extends Controller
{
    use AuthorizesRequests;

    public function legacy(MedicalRecordHandwriting $handwriting): StreamedResponse
    {
        $record = $handwriting->medicalRecord;

        abort_if($record === null, 404);

        $this->authorize('view', $record);

        return $this->stream($handwriting->handwriting_path);
    }

    public function page(MedicalRecordHandwritingPage $handwritingPage): StreamedResponse
    {
        $record = $handwritingPage->medicalRecord;

        abort_if($record === null, 404);

        $this->authorize('view', $record);

        return $this->stream($handwritingPage->handwriting_path);
    }

    private function stream(?string $path): StreamedResponse
    {
        abort_if($path === null || $path === '', 404);

        // A legacy row may hold the canvas inline rather than as an object key.
        // Those never touched the filesystem, so decode and serve directly.
        if (ClinicalEvidenceStorage::isInlineDataUri($path)) {
            return $this->streamInline($path);
        }

        $disk = ClinicalEvidenceStorage::disk();

        // The clinical disk is configured with throw => true, so an unreadable
        // backend raises rather than reporting the object absent. That keeps a
        // storage fault from being misreported to the operator as a clean 404.
        abort_unless($disk->exists($path), 404);

        return $disk->response($path, basename($path), [
            'Content-Type' => 'image/png',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store, max-age=0',
        ]);
    }

    private function streamInline(string $dataUri): StreamedResponse
    {
        $comma = strpos($dataUri, ',');
        $binary = $comma === false ? '' : (string) base64_decode(substr($dataUri, $comma + 1), true);

        abort_if($binary === '', 404);

        return new StreamedResponse(static function () use ($binary): void {
            echo $binary;
        }, 200, [
            'Content-Type' => 'image/png',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store, max-age=0',
        ]);
    }
}
