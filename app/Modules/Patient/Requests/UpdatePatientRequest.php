<?php

namespace App\Modules\Patient\Requests;

use App\Modules\Patient\Support\PatientCoreFields;
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
        $patient = $this->route('patient');
        $patientId = $patient?->id;

        // Memo M4.2 (rule 244 §5.4; owner 2026-11-17 "Existing patients are never
        // blocked") — an edit keeps every field optional, and a stored value is
        // never re-judged: a legacy date of birth or NIK saves unchanged. Only a
        // value the operator CHANGES is held to the new-patient format (date of
        // birth 1900..the clinical day, NIK 16 digits).
        $dateOfBirthChanged = $this->filled('date_of_birth')
            && (string) $this->input('date_of_birth') !== (string) $patient?->date_of_birth?->format('Y-m-d');
        $ktpChanged = $this->filled('ktp_number')
            && (string) $this->input('ktp_number') !== (string) $patient?->ktp_number;

        return [
            'clinic_id' => ['required', 'integer', 'exists:mst_clinics,id'],
            'doctor_id' => ['required', 'integer', 'exists:mst_doctors,id'],
            // DMS-RME-COMPREHENSIVE-CLOSURE-1 / memo M4.1 (rule 244 §2.3, owner
            // default 5) — the Nomor RM, the branch it was issued under and the
            // registration date are frozen on edit: not accepted here, dropped by
            // PatientService::update(), shown read-only on the form.
            'ktp_number' => ['nullable', 'string', 'max:16', ...($ktpChanged ? PatientCoreFields::NIK_RULES : []), Rule::unique('mst_patients', 'ktp_number')->ignore($patientId)],
            'name' => ['required', 'string', 'max:150'],
            'gender' => ['nullable', 'string', 'in:Male,Female,Other'],
            'place_of_birth' => PatientCoreFields::placeOfBirthRules(required: false),
            'date_of_birth' => ['nullable', 'date', ...($dateOfBirthChanged ? PatientCoreFields::birthDateBounds() : [])],
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
            ...PatientCoreFields::messages(),
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
