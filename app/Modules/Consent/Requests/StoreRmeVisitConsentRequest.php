<?php

namespace App\Modules\Consent\Requests;

use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Consent\Models\RmeVisitConsent;
use App\Modules\Consent\Services\RmeVisitConsentService;
use App\Modules\Prescription\Services\PrescriptionCanvasDecoder;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * FIX-RME-CONSENT-WORKFLOW-PRINT-UX-2 / FIX-01.
 *
 * Note what is NOT accepted here: there is no "signed" flag, no doctor_id, no
 * patient_id, no branch_id and no consent status. Whether the consent counts is
 * decided by the presence of a real signature; the doctor, patient and branch
 * come from the visit; the timing gate is re-asserted in the service. A crafted
 * request can add fields but cannot add authority.
 */
class StoreRmeVisitConsentRequest extends FormRequest
{
    /**
     * DMS-RME-COMPREHENSIVE-CLOSURE-1 (R8A-2 / 8D finding 3, owner decision
     * 2026-11-16) — the policy decides BEFORE the payload is validated, so a
     * refused account gets its 403/404 whatever it sends, never a field error
     * that depends on the payload. The controller asks the same policy again.
     */
    public function authorize(): Response
    {
        $visit = $this->route('clinicVisit');

        if (! $visit instanceof ClinicVisit) {
            return Response::denyAsNotFound();
        }

        return Gate::forUser($this->user())->inspect('create', [RmeVisitConsent::class, $visit]);
    }

    public function rules(): array
    {
        $relationships = array_keys((array) config('rme_consent.relationships', []));
        $templates = array_keys((array) config('rme_consent.templates', []));

        return [
            'template_code' => ['required', 'string', Rule::in($templates)],
            // Rule 241 §6 — the version the capture page showed. Optional; when
            // given, the service refuses it unless it is the version in force.
            'template_version' => ['nullable', 'string', 'max:20'],

            'consenter_relationship' => ['required', 'string', Rule::in($relationships)],
            // Identity of the person signing. Required only when somebody other
            // than the patient signs; for "Saya sendiri" the service copies the
            // patient's own canonical data rather than trusting typed input.
            'consenter_name' => ['nullable', 'required_unless:consenter_relationship,self', 'string', 'max:255'],
            'consenter_age' => ['nullable', 'string', 'max:20'],
            'consenter_gender' => ['nullable', 'string', 'max:20'],
            'consenter_address' => ['nullable', 'string', 'max:1000'],
            // Deliberately NOT required: mst_patients.ktp_number is nullable, so
            // demanding an identity number here would invent a stricter rule
            // than the patient record itself carries.
            'consenter_identity_number' => ['nullable', 'string', 'max:50'],

            'medical_action' => ['required', 'string', 'max:2000'],
            'treatment_summary' => ['nullable', 'string', 'max:2000'],

            // Clause 8 — explicit YA/TIDAK. `present` + `boolean` rather than
            // `required`, because `required` rejects the legitimate answer "0".
            'documentation_consent' => ['present', 'boolean'],
            // Rule 241 §6 — clause 9 (publication) on v2026.2 only. Its presence
            // depends on the version the SERVICE selects, so the service requires
            // it (or refuses it on a version without clause 9); here it is bound.
            'publication_consent' => ['nullable', 'boolean'],

            // DMS-RME-REVISION-PROGRAM-8D (rule 228 §10.3) — the signature
            // payload bound at the edge, in characters of the data URI; the
            // service passes the same limit to the decoder for callers that
            // skip this request.
            'consenter_signature' => ['required', 'string', 'max:'.PrescriptionCanvasDecoder::MAX_SIGNATURE_PAYLOAD_LENGTH],
            // DMS memo M1.1–M1.3 PR2 (rule 241 §4) — the capture takes no doctor
            // drawing; the treating doctor countersigns from Ruang Perawatan.
            'doctor_signature' => ['prohibited'],
        ];
    }

    public function messages(): array
    {
        return [
            'template_code.required' => 'Pilih form persetujuan terlebih dahulu.',
            'template_code.in' => 'Form persetujuan tidak dikenal.',
            'consenter_relationship.required' => 'Hubungan pemberi persetujuan dengan pasien wajib dipilih.',
            'consenter_relationship.in' => 'Hubungan pemberi persetujuan tidak dikenal.',
            'consenter_name.required_unless' => 'Nama pemberi persetujuan wajib diisi.',
            'medical_action.required' => 'Tindakan medis yang disetujui wajib diisi.',
            'documentation_consent.present' => 'Persetujuan dokumentasi/publikasi harus dipilih YA atau TIDAK.',
            'documentation_consent.boolean' => 'Persetujuan dokumentasi/publikasi harus dipilih YA atau TIDAK.',
            'publication_consent.boolean' => 'Persetujuan publikasi/promosi harus dipilih YA atau TIDAK.',
            'consenter_signature.required' => 'Tanda tangan pemberi persetujuan wajib diisi.',
            'consenter_signature.max' => 'Ukuran gambar tanda tangan melebihi batas yang diizinkan.',
            'doctor_signature.prohibited' => RmeVisitConsentService::MESSAGE_DOCTOR_SIGNATURE_ON_CAPTURE,
        ];
    }
}
