<?php

declare(strict_types=1);

namespace App\Modules\LegacyImport\Requests;

use App\Modules\LegacyRme\Models\LegacyRmeImport;
use Illuminate\Foundation\Http\FormRequest;

/**
 * REVISION-LEGACY-VISIT-BOUND-PREVERIFIED-INGESTION-1 — input boundary for a
 * legacy RME document uploaded FROM a real visit.
 *
 * DELIBERATELY SMALLER THAN THE BACKLOG FORM. There is no `patient_id`, no
 * `origin_branch_id` and no visit date here, because none of them are the
 * client's to assert: the patient comes from the visit, the branch stays
 * RM-derived, and the ceiling is read off the visit row. A field that is not
 * accepted cannot be forged.
 *
 * THESE RULES ARE THE CHEAP FIRST PASS ONLY. The visit binding, the date rules
 * (including the visit ceiling and the native-RME bound), the branch
 * derivation, the structural PDF validation, the checksum and the duplicate
 * precheck are all re-evaluated server-side in LegacyVisitBindingService and
 * LegacyRmeImportService — a form request can only see what the client chose
 * to send, so passing this validation proves nothing.
 *
 * THE ATTESTATION IS REQUIRED HERE *AND* IN THE SERVICE. `accepted` here gives
 * the operator a clear field error; LegacyVisitBindingService refuses again
 * regardless, so a direct service call or a future CLI cannot be a weaker door.
 */
class StoreVisitBoundLegacyRmeRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        if ($user === null) {
            return false;
        }

        // BOTH are required, and they are different things. `create` is the
        // existing right to file a legacy document at all; the new permission
        // is the right to ATTEST its dates so the checker need not re-enter
        // them. Holding one without the other must not open this path.
        return $user->can('create', LegacyRmeImport::class)
            && $user->can('verify_legacy_dates_at_ingestion');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $maxKilobytes = (int) ceil(((int) config('legacy_rme.upload.max_bytes', 20971520)) / 1024);

        return [
            // The Nomor RM PRINTED ON THE DOCUMENT, as the operator read it.
            // Shape only — LegacyRmeSourcePatientBindingService decides whether
            // it names this visit's patient, and identity is never something a
            // client may assert.
            'source_rm_raw' => [
                'required',
                'string',
                'max:'.max(1, (int) config('legacy_rme.source_rm.max_length', 64)),
            ],

            // The EARLIEST clinical date the document shows (representative).
            'selected_rme_date' => ['required', 'date'],

            // The LATEST clinical date the document shows. Optional: a
            // single-date document omits it and the server collapses the range.
            // Ordering, the visit ceiling and the native bound are all enforced
            // in the date-rule service, never here.
            'latest_rme_date' => ['nullable', 'date'],

            'document' => [
                'required',
                'file',
                // Validated from the file's own bytes, not the client-declared
                // Content-Type or the extension.
                'mimetypes:application/pdf',
                'max:'.max(1, $maxKilobytes),
            ],

            // THE date attestation. This is the one that carries governance
            // weight in this sprint: it is what lets the checker skip date
            // re-entry, so it is re-asserted server-side too.
            'date_attestation' => ['accepted'],

            // The operator states the Nomor RM they typed is the one printed
            // on the document.
            'source_rm_confirmation' => ['accepted'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'date_attestation.accepted' => 'Anda harus menyatakan bahwa tanggal yang dimasukkan sesuai dengan dokumen asli.',
            'source_rm_confirmation.accepted' => 'Anda harus menyatakan bahwa Nomor RM yang dimasukkan tertera pada dokumen.',
        ];
    }
}
