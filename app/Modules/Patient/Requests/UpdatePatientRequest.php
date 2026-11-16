<?php

namespace App\Modules\Patient\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePatientRequest extends FormRequest
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
        $patientId = $this->route('patient')?->id;

        return [
            'clinic_id' => ['required', 'integer', 'exists:mst_clinics,id'],
            'doctor_id' => ['required', 'integer', 'exists:mst_doctors,id'],
            // DMS-RME-COMPREHENSIVE-CLOSURE-1 / memo M4.1 (rule 244 §2.3, owner
            // default 5) — the Nomor RM, the branch it was issued under and the
            // registration date are frozen on edit: not accepted here, dropped by
            // PatientService::update(), shown read-only on the form.
            'ktp_number' => ['nullable', 'string', 'max:16', Rule::unique('mst_patients', 'ktp_number')->ignore($patientId)],
            'name' => ['required', 'string', 'max:150'],
            'gender' => ['nullable', 'string', 'in:Male,Female,Other'],
            'date_of_birth' => ['nullable', 'date'],
            'phone' => ['nullable', 'string', 'max:50'],
            'whatsapp_number' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:150'],
            'address' => ['nullable', 'string', 'max:1000'],
            'occupation' => ['nullable', 'string', 'max:150'],
            'is_active' => ['sometimes', 'boolean'],
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
            'ktp_number.unique' => 'Nomor KTP sudah terdaftar pada pasien lain.',
        ];
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
