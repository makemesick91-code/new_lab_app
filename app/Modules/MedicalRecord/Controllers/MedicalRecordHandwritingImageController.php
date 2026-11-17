<?php

namespace App\Modules\MedicalRecord\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\MedicalRecord\Models\MedicalRecordHandwriting;
use App\Modules\MedicalRecord\Models\MedicalRecordHandwritingPage;
use App\Modules\MedicalRecord\Services\HandwritingPayloadGuard;
use App\Modules\RME\Services\DoctorPatientScopeService;
use App\Support\Storage\ClinicalEvidenceStorage;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Log;
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
 *
 * Rule 240 §8 (memo M3.3 PR6) — an amendment page (a correction appended by an
 * approved amendment) is served only when its bytes match the recorded SHA-256;
 * a missing or tampered object answers 404, like an absent one; a storage read
 * fault answers 503.
 *
 * Rule 243 §2.8 — a page is asked as itself (MedicalRecordPolicy::
 * viewHandwritingPage, `view` plus, under the doctor encounter scope, the book
 * page's authoring encounter), so the image route serves exactly the pages the
 * workspace lists.
 */
class MedicalRecordHandwritingImageController extends Controller
{
    use AuthorizesRequests;

    public function legacy(MedicalRecordHandwriting $handwriting): StreamedResponse
    {
        $record = $handwriting->medicalRecord;

        abort_if($record === null, 404);

        $this->authorizePage($record, self::authorOf($handwriting));

        return $this->stream($handwriting->handwriting_path);
    }

    public function page(MedicalRecordHandwritingPage $handwritingPage): StreamedResponse
    {
        $record = $handwritingPage->medicalRecord;

        abort_if($record === null, 404);

        $this->authorizePage($record, self::authorOf($handwritingPage));

        if ($handwritingPage->getAttribute('rme_amendment_id') !== null) {
            $stored = HandwritingPayloadGuard::storedPng($handwritingPage->handwriting_path, $handwritingPage->handwriting_hash);

            if ($stored['bytes'] === null) {
                Log::warning('An RME amendment page was not served.', [
                    'handwriting_page_id' => (int) $handwritingPage->getKey(),
                    'rme_amendment_id' => (int) $handwritingPage->getAttribute('rme_amendment_id'),
                    'status' => $stored['status'],
                ]);
                // A storage read fault is retried (503), never reported as a
                // missing or tampered page (404) — review L2.
                abort($stored['status'] === HandwritingPayloadGuard::STORED_UNREADABLE ? 503 : 404);
            }

            return $this->streamBytes($stored['bytes']);
        }

        return $this->stream($handwritingPage->handwriting_path);
    }

    /**
     * `viewHandwritingPage` only while the doctor encounter scope applies to the
     * user; otherwise exactly the `view` of the record asked before (flag-OFF
     * parity: the same ability and arguments for every Gate hook).
     */
    private function authorizePage(MedicalRecord $record, ?int $authoredVisitId): void
    {
        if (app(DoctorPatientScopeService::class)->encounterScopeAppliesTo(auth()->user())) {
            $this->authorize('viewHandwritingPage', [$record, $authoredVisitId]);

            return;
        }

        $this->authorize('view', $record);
    }

    /** Rule 240 §4.4 — the encounter that wrote the row, null before rule 240. */
    private static function authorOf(MedicalRecordHandwriting|MedicalRecordHandwritingPage $row): ?int
    {
        $visitId = $row->getAttribute('authored_clinic_visit_id');

        return $visitId !== null ? (int) $visitId : null;
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

        return $this->streamBytes($binary);
    }

    private function streamBytes(string $binary): StreamedResponse
    {
        return new StreamedResponse(static function () use ($binary): void {
            echo $binary;
        }, 200, [
            'Content-Type' => 'image/png',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store, max-age=0',
        ]);
    }
}
