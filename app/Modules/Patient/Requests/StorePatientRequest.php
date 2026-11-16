<?php

namespace App\Modules\Patient\Requests;

use App\Modules\PatientMerge\Services\PatientRegistrationDuplicateCheck;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePatientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'clinic_id' => ['required', 'integer', 'exists:mst_clinics,id'],
            'doctor_id' => ['required', 'integer', 'exists:mst_doctors,id'],
            // DMS-RME-COMPREHENSIVE-CLOSURE-1 / memo M4.1 (rule 244 §2) — the
            // Nomor RM is automatic and read-only: the branch it is issued under
            // is required, and no number, manual sequence or registration date is
            // accepted (PatientService also drops them; the server stamps the
            // clinical day). The Sprint 23.8 manual fields and the free override
            // are retired.
            'branch_id' => [
                'required',
                'integer',
                Rule::exists('mst_branches', 'id')->where('is_active', true)->where('is_rme_enabled', true),
            ],
            'ktp_number' => ['nullable', 'string', 'max:16', Rule::unique('mst_patients', 'ktp_number')],
            'name' => ['required', 'string', 'max:150'],
            'gender' => ['nullable', 'string', 'in:Male,Female,Other'],
            'date_of_birth' => ['nullable', 'date'],
            'phone' => ['nullable', 'string', 'max:50'],
            'whatsapp_number' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:150'],
            'address' => ['nullable', 'string', 'max:1000'],
            'occupation' => ['nullable', 'string', 'max:150'],
            'is_active' => ['sometimes', 'boolean'],
            // Sprint 61.1 — opaque token for a KTP scan parked via the scanner
            // workflow. Attached to the patient after creation (controller).
            'ktp_scan_token' => ['nullable', 'string', 'max:64'],
            // REVISION-REGISTRATION-KTP-CAMERA-OCR-1 — when OCR suggestions were
            // applied to this form, the operator must confirm they checked them
            // against the physical KTP. Every value is still validated by the
            // rules above exactly as manual entry; this adds the human check.
            'ktp_ocr_applied' => ['nullable', 'in:0,1'],
            'ktp_ocr_verified' => ['exclude_unless:ktp_ocr_applied,1', 'accepted'],
            // FEATURE-PATIENT-DUPLICATE-RESOLUTION-MERGE-1 — required only when
            // a strong duplicate is found (see withValidator).
            'duplicate_override_reason' => ['nullable', 'string', 'max:1000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'ktp_number' => $this->normalizeKtpNumber($this->input('ktp_number')),
            'whatsapp_number' => $this->normalizeOptionalString($this->input('whatsapp_number')),
        ]);
    }

    public function messages(): array
    {
        return [
            'ktp_ocr_verified.accepted' => 'Konfirmasi bahwa data hasil baca KTP sudah dicocokkan dengan KTP asli sebelum menyimpan.',
            'ktp_ocr_verified.required' => 'Konfirmasi bahwa data hasil baca KTP sudah dicocokkan dengan KTP asli sebelum menyimpan.',
            'branch_id.required' => 'Cabang RME wajib dipilih. Nomor RM dibuat otomatis untuk cabang tersebut.',
            'ktp_number.unique' => 'Nomor KTP sudah terdaftar pada pasien lain.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->isEmpty()) {
                app(PatientRegistrationDuplicateCheck::class)->validate(
                    $validator,
                    $this->only(['name', 'date_of_birth', 'phone', 'whatsapp_number']),
                    $this->input('duplicate_override_reason'),
                    'duplicate_override_reason',
                );
            }
        });
    }

    private function normalizeKtpNumber(mixed $value): ?string
    {
        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }

    private function normalizeOptionalString(mixed $value): ?string
    {
        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }
}
