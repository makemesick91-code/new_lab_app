<?php

namespace App\Modules\MedicalRecord\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Consent\Services\RmeVisitConsentService;
use App\Modules\MedicalRecord\Interfaces\MedicalRecordHandwritingRepositoryInterface;
use App\Modules\MedicalRecord\Interfaces\MedicalRecordRepositoryInterface;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\MedicalRecord\Services\HandwritingPayloadGuard;
use App\Modules\MedicalRecord\Services\MedicalRecordHandwritingService;
use App\Modules\MedicalRecord\Services\MedicalRecordService;
use App\Modules\MedicalRecord\Services\PatientRmWorkspaceResolver;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class MedicalRecordHandwritingController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly MedicalRecordHandwritingRepositoryInterface $handwritings,
        private readonly MedicalRecordRepositoryInterface $medicalRecords,
        private readonly HandwritingPayloadGuard $payloadGuard,
    ) {}

    public function store(
        Request $request,
        ClinicVisit $clinicVisit,
        MedicalRecord $medicalRecord,
        PatientRmWorkspaceResolver $workspace,
        MedicalRecordService $medicalRecordService,
        RmeVisitConsentService $consents,
        MedicalRecordHandwritingService $handwritingService,
    ): RedirectResponse {
        $pageNumber = (int) ($request->input('page_number') ?? 1);

        // FIX-RME-EXAM-CONSENT-ODONTOGRAM-HISTORY-3 / FIX-02 — remember which
        // visit the doctor is actually examining BEFORE the Sprint 64.0.2
        // canonical substitution below rewrites $clinicVisit to the patient's
        // first visit. The consent that matters is the one for the live
        // encounter, not for whichever historical visit stores the page.
        $encounterVisit = $clinicVisit;

        // Sprint 64.0.2 — new handwriting pages always persist on the canonical
        // medical record. Existing legacy pages on later visits may still be
        // edited in place when the doctor re-saves that virtual page.
        // DMS-RME-COMPREHENSIVE-CLOSURE-1 (R7-1) — the book is written on its own
        // visit, which is no longer the canonical one once that visit was
        // cancelled or a merge put an earlier visit in front of it.
        $canonicalRecord = $workspace->canonicalMedicalRecord($clinicVisit->patient_id);
        $canonicalVisit = $canonicalRecord?->clinicVisit
            ?? $workspace->resolveCanonicalWorkspaceVisit($clinicVisit->patient_id)
            ?? $clinicVisit;

        $isNewCanonicalPage = $canonicalRecord === null
            || $medicalRecord->id === $canonicalRecord->id
            || $pageNumber >= $this->handwritings->nextPageNumber($medicalRecord->id);

        /*
         * DMS-RME-REVISION-PROGRAM-6 / P6-HW-01 (closes R5-3) — nothing is written
         * until the canvas is validated. The canonical draft used to be created
         * here, before validation, so a blank or invalid canvas left an empty
         * book behind on the patient's earliest visit. When the patient has no
         * book yet it is now created inside the save transaction below, after
         * validation; the authoring gate is asserted first, exactly as
         * getOrCreateCanonicalMedicalRecord() asserted it first before.
         */
        $needsCanonicalDraft = $isNewCanonicalPage && $canonicalRecord === null;

        if ($needsCanonicalDraft) {
            $consents->assertRmeAuthoringAllowedForPatient($clinicVisit->patient_id);
        } else {
            if ($isNewCanonicalPage) {
                $medicalRecord = $canonicalRecord;
                $clinicVisit = $canonicalVisit;
            }

            $this->assertWritableTarget($medicalRecord, $clinicVisit, $consents);
        }

        // Rule 240 §4 (memo M3.3, supersedes the Sprint 59 "handwriting RM is
        // revisable after finalization") — a page sealed when a sheet was
        // finalized is never overwritten; MedicalRecordHandwritingService::save()
        // refuses it under the record lock. Appending a new page in the live,
        // consented encounter stays allowed, onto the one book (R7-1).

        // Sprint 60 — 1 canvas = 1 RM page. `page_number` selects which RM page
        // is being saved. Page 1 is the legacy `trx_medical_record_handwritings`
        // row (read-through, backward compatible); Page 2+ persist to the
        // additive pages table. Saving a higher page never touches Page 1.
        $request->validate([
            // DMS-RME-COMPREHENSIVE-CLOSURE-1 (rule 237 §5) — bounded at the edge.
            'handwriting_data' => ['required', 'string', 'max:'.HandwritingPayloadGuard::MAX_HANDWRITING_PAYLOAD_LENGTH],
            'page_number' => ['nullable', 'integer', 'min:1'],
            // DMS-RME-COMPREHENSIVE-CLOSURE-1 (OD-6-1) — the revision this device loaded.
            'page_revision' => ['nullable', 'string', 'max:64'],
        ]);

        // The PNG is decoded, validated and blank-guarded identically for every
        // page (Sprint 59.1 guard applies per page), before any storage/DB write.
        // Rule 240 §8 — the one canvas decoder, shared with the amendment page.
        $decoded = $this->payloadGuard->decodeCanvas($request->input('handwriting_data'));

        $saved = $handwritingService->save(
            function () use ($needsCanonicalDraft, $medicalRecord, $clinicVisit, $canonicalVisit, $medicalRecordService, $consents): array {
                if (! $needsCanonicalDraft) {
                    // DMS-RME-COMPREHENSIVE-CLOSURE-1 (rule 223 §6, rule 219 §12a) —
                    // re-decided inside the save transaction, locking the encounter's
                    // visit row BEFORE the record row (lockForHandwriting), so a
                    // consent void or a finish committed meanwhile is seen. The
                    // canonical-draft path below asserts the same inside
                    // getOrCreateCanonicalMedicalRecord().
                    $encounter = $consents->assertRmeAuthoringAllowedForPatient($medicalRecord->patient_id);

                    // Rule 240 §4 — the authorized encounter is the page's author.
                    return [$medicalRecord, $clinicVisit, $encounter];
                }

                $draft = $medicalRecordService->getOrCreateCanonicalMedicalRecord(
                    $clinicVisit,
                    Auth::id(),
                    $clinicVisit->id,
                );

                // R7-1 — the service decides the book under the visit lock; it may
                // be an existing one, so the page goes onto the book's own visit.
                $storageVisit = $draft->clinicVisit ?? $canonicalVisit;

                // A refusal here rolls the draft back with the rest of the save.
                $encounter = $this->assertWritableTarget($draft, $storageVisit, $consents);

                return [$draft, $storageVisit, $encounter];
            },
            $pageNumber,
            $decoded,
            Auth::id(),
            $request->filled('page_revision') ? (string) $request->input('page_revision') : null,
        );

        $medicalRecord = $saved['medical_record'];
        $clinicVisit = $saved['storage_visit'];
        $focusPage = $saved['page_number'];

        $handwritingBook = $workspace->orderedHandwritingBookForPatient($medicalRecord->patient_id);
        $virtualFocus = $handwritingBook->first(
            fn (array $page) => $page['medical_record_id'] === $medicalRecord->id
                && (int) $page['storage_page_number'] === $focusPage
        );
        $focusVirtualPage = $virtualFocus['virtual_page_number'] ?? $focusPage;

        $redirectVisit = $workspace->resolveCanonicalWorkspaceVisit($medicalRecord->patient_id) ?? $clinicVisit;
        $sourceVisitId = $request->integer('source_visit_id') ?: null;

        // Sprint 60.1 — focus the page that was just saved/added on the next
        // load. Flashed (not added to the redirect URL) so the show page lands
        // on this page while existing redirect-target assertions stay valid.
        return redirect()
            ->route('rme.visits.medical-record.show', array_filter([
                $redirectVisit,
                'source_visit_id' => $sourceVisitId,
            ], fn ($v) => $v !== null && $v !== ''))
            ->with('status', 'Tulisan tangan RME berhasil disimpan.')
            ->with('focus_rm_page', $focusVirtualPage);
    }

    /**
     * The record must belong to the visit that stores it, the actor must be
     * allowed to update it, and the patient must have an authorized, consented
     * examination in progress.
     */
    private function assertWritableTarget(MedicalRecord $medicalRecord, ClinicVisit $storageVisit, RmeVisitConsentService $consents): ClinicVisit
    {
        abort_if($medicalRecord->clinic_visit_id !== $storageVisit->id, 404);

        $this->authorize('update', $medicalRecord);

        /*
         * FIX-02 — handwriting is RME content, so it is gated exactly like the
         * rest of the record. The write goes through MedicalRecordHandwritingService,
         * not MedicalRecordService, so the gate is asserted here too; without it
         * the "edit an existing page in place" branch would reach a write without
         * ever passing a service-layer check.
         *
         * Every visit involved is checked: the live encounter, the visit the doctor
         * navigated from, and the visit that owns the record being written.
         */
        /*
         * FIX-02 / CORRECTIVE-02 — POSITIVE authority, resolved from server state.
         *
         * Neither the {clinicVisit} route parameter, nor source_visit_id, nor the
         * record's own clinic_visit_id can establish the right to write: the first
         * two are request input and the third points at wherever Sprint 64.0.2
         * chose to store the bytes, which for a returning patient is an old
         * terminal visit. The only sound question is whether this patient has an
         * authorized, consented examination happening now.
         */
        return $consents->assertRmeAuthoringAllowedForPatient($medicalRecord->patient_id);
    }
}
