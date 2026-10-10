<?php

namespace App\Modules\Patient\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Patient\Models\Patient;
use App\Modules\Patient\Models\PatientDocument;
use App\Modules\Patient\Requests\ParseKtpOcrRequest;
use App\Modules\Patient\Requests\StoreKtpScanRequest;
use App\Modules\Patient\Services\KtpOcrSuggestionService;
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

    public function __construct(
        private readonly KtpScanService $ktpScans,
        private readonly KtpOcrSuggestionService $ocrSuggestions,
    ) {}

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
                $request->input('replaces_token'),
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
     * REVISION-REGISTRATION-KTP-CAMERA-OCR-1 — turn OCR text lines (read in the
     * operator's browser) into validated, flagged registration SUGGESTIONS.
     *
     * Writes nothing and logs nothing: the lines can contain a full NIK, so the
     * response is marked no-store and no part of the payload is audited.
     * Duplicate detection is deliberately NOT done here — the registration
     * submit already runs the canonical, branch-scoped duplicate check.
     */
    public function parseOcr(ParseKtpOcrRequest $request): JsonResponse
    {
        $this->authorize('create', Patient::class);

        // `fields` (REVISION-PATIENT-KTP-OCR-FIELD-BASED-ROI-1) is the optional
        // per-field read; without it the result is the original single read.
        $fields = $request->validated('fields');
        $result = $this->ocrSuggestions->suggest(
            array_values((array) $request->validated('lines', [])),
            is_array($fields) ? $fields : null,
            (float) config('scanner.ocr.confidence_threshold', 75.0),
        );

        return response()
            ->json(['ok' => true] + $result)
            ->header('Cache-Control', 'no-store, private');
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
