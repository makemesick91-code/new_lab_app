<?php

namespace App\Modules\MedicalRecord\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Consent\Services\RmeVisitConsentService;
use App\Modules\MedicalRecord\Interfaces\MedicalRecordHandwritingRepositoryInterface;
use App\Modules\MedicalRecord\Interfaces\MedicalRecordRepositoryInterface;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\MedicalRecord\Services\MedicalRecordHandwritingService;
use App\Modules\MedicalRecord\Services\MedicalRecordService;
use App\Modules\MedicalRecord\Services\PatientRmWorkspaceResolver;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class MedicalRecordHandwritingController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly MedicalRecordHandwritingRepositoryInterface $handwritings,
        private readonly MedicalRecordRepositoryInterface $medicalRecords,
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
        $canonicalVisit = $workspace->resolveCanonicalWorkspaceVisit($clinicVisit->patient_id) ?? $clinicVisit;
        $canonicalRecord = $workspace->canonicalMedicalRecord($clinicVisit->patient_id);

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

        // Sprint 59 — handwriting RM is revisable after finalization. The
        // previous immutability lock is removed so doctors can correct or
        // append handwriting on any visit, including older finalized ones.

        // Sprint 60 — 1 canvas = 1 RM page. `page_number` selects which RM page
        // is being saved. Page 1 is the legacy `trx_medical_record_handwritings`
        // row (read-through, backward compatible); Page 2+ persist to the
        // additive pages table. Saving a higher page never touches Page 1.
        $request->validate([
            'handwriting_data' => ['required', 'string'],
            'page_number' => ['nullable', 'integer', 'min:1'],
        ]);

        // The PNG is decoded, validated and blank-guarded identically for every
        // page (Sprint 59.1 guard applies per page), before any storage/DB write.
        $decoded = $this->decodeHandwriting($request->input('handwriting_data'));

        $saved = $handwritingService->save(
            function () use ($needsCanonicalDraft, $medicalRecord, $clinicVisit, $canonicalVisit, $medicalRecordService, $consents): array {
                if (! $needsCanonicalDraft) {
                    return [$medicalRecord, $clinicVisit];
                }

                $draft = $medicalRecordService->getOrCreateCanonicalMedicalRecord(
                    $clinicVisit,
                    Auth::id(),
                    $clinicVisit->id,
                );

                // A refusal here rolls the draft back with the rest of the save.
                $this->assertWritableTarget($draft, $canonicalVisit, $consents);

                return [$draft, $canonicalVisit];
            },
            $pageNumber,
            $decoded,
            Auth::id(),
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
    private function assertWritableTarget(MedicalRecord $medicalRecord, ClinicVisit $storageVisit, RmeVisitConsentService $consents): void
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
        $consents->assertRmeAuthoringAllowedForPatient($medicalRecord->patient_id);
    }

    /**
     * Decode, validate and blank-guard the submitted canvas PNG. Returns the raw
     * PNG bytes or throws a ValidationException (Sprint 59 / 59.1 semantics).
     */
    private function decodeHandwriting(?string $raw): string
    {
        // Strip the data URI prefix if present (data:image/png;base64,...)
        $base64 = preg_replace('/^data:image\/\w+;base64,/', '', (string) $raw);

        $decoded = base64_decode($base64, strict: true);

        // Must decode successfully and have valid PNG magic bytes
        if ($decoded === false || strlen($decoded) < 8 || substr($decoded, 0, 4) !== "\x89PNG") {
            throw ValidationException::withMessages([
                'handwriting_data' => 'Data tulisan tangan tidak valid atau kosong.',
            ]);
        }

        // Sprint 59.1 — never overwrite saved handwriting with an accidental
        // blank canvas. A fully transparent / uniform PNG is rejected before
        // any storage or DB write, so previously saved strokes are preserved.
        if ($this->isBlankHandwriting($decoded)) {
            throw ValidationException::withMessages([
                'handwriting_data' => 'Tulisan tangan kosong tidak dapat disimpan dan tidak akan menghapus tulisan tangan yang sudah ada.',
            ]);
        }

        return $decoded;
    }

    /**
     * Detect a blank handwriting submission (a fully transparent canvas or a
     * single uniform colour) so an accidental empty save never overwrites
     * previously stored handwriting. Pure-PHP PNG inspection — GD/Imagick are
     * not required, only zlib (always present). Only the 8-bit RGBA output of
     * canvas.toDataURL() is inspected; anything else is treated as non-blank to
     * avoid false rejections of legitimate content.
     */
    private function isBlankHandwriting(string $png): bool
    {
        if (strlen($png) < 8 || substr($png, 0, 8) !== "\x89PNG\r\n\x1a\n") {
            return false;
        }

        $offset = 8;
        $width = $height = $bitDepth = $colorType = null;
        $idat = '';

        while ($offset + 12 <= strlen($png)) {
            $length = unpack('N', substr($png, $offset, 4))[1];
            $type = substr($png, $offset + 4, 4);
            $data = substr($png, $offset + 8, $length);

            if ($type === 'IHDR') {
                $width = unpack('N', substr($data, 0, 4))[1];
                $height = unpack('N', substr($data, 4, 4))[1];
                $bitDepth = ord($data[8]);
                $colorType = ord($data[9]);
            } elseif ($type === 'IDAT') {
                $idat .= $data;
            } elseif ($type === 'IEND') {
                break;
            }

            $offset += 12 + $length;
        }

        if ($colorType !== 6 || $bitDepth !== 8 || $width === null || $width <= 0 || $height <= 0) {
            return false;
        }

        $raw = @zlib_decode($idat);
        if ($raw === false || $raw === '') {
            return false;
        }

        $bytesPerPixel = 4;
        $stride = $width * $bytesPerPixel;
        $previous = array_fill(0, $stride, 0);
        $position = 0;
        $allTransparent = true;
        $uniform = true;
        $firstPixel = null;

        for ($y = 0; $y < $height; $y++) {
            if ($position >= strlen($raw)) {
                return false;
            }

            $filter = ord($raw[$position++]);
            $line = array_values(unpack('C*', substr($raw, $position, $stride)));

            if (count($line) < $stride) {
                return false;
            }

            $position += $stride;

            // Reverse the PNG scanline filter so we read true pixel values.
            for ($x = 0; $x < $stride; $x++) {
                $a = $x >= $bytesPerPixel ? $line[$x - $bytesPerPixel] : 0;
                $b = $previous[$x];
                $c = $x >= $bytesPerPixel ? $previous[$x - $bytesPerPixel] : 0;

                switch ($filter) {
                    case 0:
                        break;
                    case 1:
                        $line[$x] = ($line[$x] + $a) & 0xFF;
                        break;
                    case 2:
                        $line[$x] = ($line[$x] + $b) & 0xFF;
                        break;
                    case 3:
                        $line[$x] = ($line[$x] + intdiv($a + $b, 2)) & 0xFF;
                        break;
                    case 4:
                        $p = $a + $b - $c;
                        $pa = abs($p - $a);
                        $pb = abs($p - $b);
                        $pc = abs($p - $c);
                        $predictor = ($pa <= $pb && $pa <= $pc) ? $a : (($pb <= $pc) ? $b : $c);
                        $line[$x] = ($line[$x] + $predictor) & 0xFF;
                        break;
                    default:
                        return false;
                }
            }

            for ($x = 0; $x < $stride; $x += $bytesPerPixel) {
                $pixel = [$line[$x], $line[$x + 1], $line[$x + 2], $line[$x + 3]];

                if ($pixel[3] !== 0) {
                    $allTransparent = false;
                }

                if ($firstPixel === null) {
                    $firstPixel = $pixel;
                } elseif ($pixel !== $firstPixel) {
                    $uniform = false;
                }

                // Any non-transparent, non-uniform pixel means real content.
                if (! $allTransparent && ! $uniform) {
                    return false;
                }
            }

            $previous = $line;
        }

        return $allTransparent || $uniform;
    }
}
