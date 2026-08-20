<?php

namespace App\Modules\Consent\Requests;

use Illuminate\Foundation\Http\FormRequest;
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
    public function authorize(): bool
    {
        // Authorisation is performed in the controller against the visit, where
        // the bound model is available to the policy.
        return true;
    }

    public function rules(): array
    {
        $relationships = array_keys((array) config('rme_consent.relationships', []));
        $templates = array_keys((array) config('rme_consent.templates', []));

        return [
            'template_code' => ['required', 'string', Rule::in($templates)],

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

            'consenter_signature' => ['required', 'string'],
            'doctor_signature' => ['nullable', 'string'],
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
            'consenter_signature.required' => 'Tanda tangan pemberi persetujuan wajib diisi.',
        ];
    }
}
