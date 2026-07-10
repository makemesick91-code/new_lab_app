<?php

namespace App\Modules\LabOrder\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\LabOrder\Models\LabWorkflowEvidence;
use App\Modules\LabOrder\Services\LabWorkflowEvidenceService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * LAB-WORKFLOW-V2 — authorized streaming of private evidence files.
 *
 * Evidence binaries live on the private local disk (no public URL exists).
 * Access is policy-gated (LabWorkflowEvidencePolicy) and the filesystem path
 * is never exposed to the client. Mirrors PatientDocumentController@show.
 */
class LabWorkflowEvidenceController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly LabWorkflowEvidenceService $evidence,
    ) {}

    public function show(LabWorkflowEvidence $evidence): StreamedResponse
    {
        $this->authorize('view', $evidence);

        $disk = Storage::disk($this->evidence->disk());

        abort_unless($disk->exists($evidence->file_path), 404);

        return $disk->response($evidence->file_path, basename($evidence->file_path), [
            'Content-Type' => $evidence->mime_type ?? 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
