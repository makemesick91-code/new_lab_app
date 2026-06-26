<?php

namespace App\Modules\Patient\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Patient\Models\Patient;
use App\Modules\Patient\Models\PatientDocument;
use App\Modules\Patient\Requests\StoreKtpScanRequest;
use App\Modules\Patient\Services\KtpScanService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Sprint 61.1 — Direct KTP Scanner Capture & Compression.
 *
 * Endpoints for the private patient KTP document workflow. All actions are
 * gated by the patient management ability (manage patients) so doctors and
 * cashiers cannot upload, view or delete identity scans. Files are served only
 * through {@see show()} from the private disk — never via a public URL.
 */
class PatientDocumentController extends Controller
{
    use AuthorizesRequests;

    public function __construct(private readonly KtpScanService $ktpScans) {}

    /**
     * Accept a scanned KTP image (base64) and park it under a temp token so it
     * can be attached when the patient is saved.
     */
    public function uploadTemp(StoreKtpScanRequest $request): JsonResponse
    {
        $this->authorize('create', Patient::class);

        try {
            $result = $this->ktpScans->storeTempFromBase64(
                $request->string('image_base64')->toString(),
                $request->input('mime_type'),
                $request->input('filename'),
                (int) $request->user()->id,
            );
        } catch (RuntimeException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'ok' => true,
            'token' => $result['token'],
            'mime_type' => $result['mime_type'],
            'compressed_file_size' => $result['compressed_file_size'],
            'width' => $result['width'],
            'height' => $result['height'],
        ]);
    }

    /**
     * Stream a stored KTP document inline from the private disk.
     */
    public function show(Patient $patient, PatientDocument $document): StreamedResponse
    {
        $this->authorize('view', $patient);
        $this->ensureBelongsTo($patient, $document);

        $disk = Storage::disk($this->ktpScans->disk());
        abort_unless($disk->exists($document->file_path), 404);

        return $disk->response(
            $document->file_path,
            $document->original_filename ?: 'ktp-scan',
            ['Content-Type' => $document->mime_type],
        );
    }

    /**
     * Soft-delete a KTP document and remove the underlying private file.
     */
    public function destroy(Patient $patient, PatientDocument $document): RedirectResponse
    {
        $this->authorize('update', $patient);
        $this->ensureBelongsTo($patient, $document);

        $this->ktpScans->deleteDocument($document);

        return redirect()
            ->route('settings.patients.edit', $patient)
            ->with('status', 'Dokumen KTP berhasil dihapus.');
    }

    private function ensureBelongsTo(Patient $patient, PatientDocument $document): void
    {
        abort_unless((int) $document->patient_id === (int) $patient->id, 404);
    }
}
